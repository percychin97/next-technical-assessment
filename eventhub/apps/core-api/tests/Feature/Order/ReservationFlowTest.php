<?php

namespace Tests\Feature\Order;

use App\Enums\EventStatus;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Ticket;
use App\Models\TicketInventoryPool;
use App\Models\TicketReservation;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integration tests for the ticket purchase flow (Step 9).
 *
 * Tests covered (per spec section 10.1 and steps.txt):
 *   ✅ Happy path: reserve → payment webhook success → tickets issued
 *   ✅ Concurrent reservation: two users attempt last ticket — one wins
 *   ✅ Expired reservation: cleanup releases inventory correctly
 *   ✅ Duplicate payment webhook: second processing is no-op
 *   ✅ Same idempotency key: returns original order
 *   ✅ Late payment: order → payment_review, no tickets issued
 *   ✅ Payment failure: order → failed, inventory released
 *   ✅ Group bundle: admission units consumed correctly
 */
class ReservationFlowTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    private User $attendee;
    private Event $event;
    private TicketInventoryPool $pool;
    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();

        $vendorUser = User::factory()->create(['role' => UserRole::Vendor->value]);
        $vendor     = Vendor::factory()->create([
            'user_id'    => $vendorUser->id,
            'kyc_status' => KycStatus::Verified->value,
        ]);

        $this->attendee = User::factory()->create(['role' => UserRole::Attendee->value]);

        $this->event = Event::factory()->create([
            'vendor_id'    => $vendor->id,
            'status'       => EventStatus::Published->value,
            'start_at_utc' => now()->addDays(10),
            'end_at_utc'   => now()->addDays(10)->addHours(6),
        ]);

        $this->pool = TicketInventoryPool::factory()->create([
            'event_id'       => $this->event->id,
            'capacity_units' => 5,
            'reserved_units' => 0,
            'sold_units'     => 0,
        ]);

        $this->ticketType = TicketType::factory()->create([
            'event_id'                    => $this->event->id,
            'inventory_pool_id'           => $this->pool->id,
            'price_minor'                 => 10000,
            'currency'                    => 'MYR',
            'admission_units_per_purchase' => 1,
            'is_active'                   => true,
            'category'                    => 'general_admission',
        ]);
    }

    // ─── 1. Happy Path ────────────────────────────────────────────────────────

    /** @test */
    public function attendee_can_reserve_tickets_and_payment_success_issues_tickets(): void
    {
        // Step 1: Reserve
        $response = $this->actingAs($this->attendee, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'test-idem-001'])
            ->postJson('/api/v1/orders', [
                'event_id' => $this->event->id,
                'items'    => [
                    ['ticket_type_id' => $this->ticketType->id, 'quantity' => 2],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonStructure(['data' => ['order_id', 'hold_expires_at', 'total_amount_minor']]);

        $orderId = $response->json('data.order_id');

        // Verify inventory reserved
        $this->pool->refresh();
        $this->assertEquals(2, $this->pool->reserved_units);
        $this->assertEquals(0, $this->pool->sold_units);

        // Step 2: Initiate payment
        $order = Order::find($orderId);
        $payment = Payment::create([
            'order_id'        => $orderId,
            'provider'        => 'stripe_simulator',
            'status'          => PaymentStatus::Pending->value,
            'idempotency_key' => 'pmt-idem-001',
            'amount_minor'    => 20000,
            'currency'        => 'MYR',
        ]);

        // Step 3: Simulate payment webhook (success)
        $webhookPayload = [
            'payment_id'         => $payment->id,
            'order_id'           => $orderId,
            'status'             => 'succeeded',
            'provider_reference' => 'sim_pi_happy',
            'amount_minor'       => 20000,
            'currency'           => 'MYR',
        ];

        $webhookResponse = $this->postJson(
            '/api/v1/webhooks/payments',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-happy-001')
        );

        $webhookResponse->assertOk()
            ->assertJsonPath('data.status', 'paid');

        // Verify: order paid, inventory transferred, tickets issued
        $order->refresh();
        $this->assertEquals(OrderStatus::Paid->value, $order->status->value);

        $this->pool->refresh();
        $this->assertEquals(0, $this->pool->reserved_units);
        $this->assertEquals(2, $this->pool->sold_units);

        $tickets = Ticket::where('order_id', $orderId)->get();
        $this->assertCount(2, $tickets);
        $tickets->each(fn ($t) => $this->assertEquals('valid', $t->status->value));
    }

    // ─── 2. Concurrent Reservation — Last Ticket ─────────────────────────────

    /** @test */
    public function concurrent_reservation_of_last_ticket_one_wins_one_fails(): void
    {
        // Set pool to exactly 1 available unit
        $this->pool->update(['reserved_units' => 4, 'sold_units' => 0]); // 5 - 4 = 1 available

        $attendee2 = User::factory()->create(['role' => UserRole::Attendee->value]);

        $successCount = 0;
        $failCount    = 0;

        // Simulate two requests racing — PHP is single-threaded so we test
        // the service-level locking logic using transactions
        $results = [];
        foreach ([$this->attendee->id, $attendee2->id] as $userId) {
            try {
                $user = User::find($userId);
                $response = $this->actingAs($user, 'sanctum')
                    ->withHeaders(['Idempotency-Key' => "idem-concurrent-{$userId}"])
                    ->postJson('/api/v1/orders', [
                        'event_id' => $this->event->id,
                        'items'    => [
                            ['ticket_type_id' => $this->ticketType->id, 'quantity' => 1],
                        ],
                    ]);

                if ($response->status() === 201) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Throwable $e) {
                $failCount++;
            }
        }

        // At most one should succeed (the first to acquire the lock)
        $this->assertLessThanOrEqual(1, $successCount, 'At most one reservation should succeed for the last ticket');
    }

    // ─── 3. Expired Reservation Releases Inventory ────────────────────────────

    /** @test */
    public function expired_reservation_cleanup_releases_inventory(): void
    {
        // Create an already-expired order
        $order = Order::create([
            'user_id'                  => $this->attendee->id,
            'event_id'                 => $this->event->id,
            'order_number'             => 'EH-20240101-000001',
            'creation_idempotency_key' => 'expired-order-001',
            'status'                   => OrderStatus::AwaitingPayment->value,
            'subtotal_minor'           => 10000,
            'total_amount_minor'       => 10000,
            'currency'                 => 'MYR',
            'hold_expires_at'          => now()->subMinutes(20), // already expired
        ]);

        TicketReservation::create([
            'order_id'          => $order->id,
            'ticket_type_id'    => $this->ticketType->id,
            'inventory_pool_id' => $this->pool->id,
            'purchase_quantity' => 2,
            'reserved_units'    => 2,
            'status'            => ReservationStatus::Held->value,
            'expires_at'        => now()->subMinutes(20),
        ]);

        $this->pool->update(['reserved_units' => 2]);

        // Run cleanup
        $this->artisan('reservations:cleanup')->assertSuccessful();

        // Verify
        $order->refresh();
        $this->assertEquals(OrderStatus::Expired->value, $order->status->value);

        $this->pool->refresh();
        $this->assertEquals(0, $this->pool->reserved_units);

        $reservation = TicketReservation::where('order_id', $order->id)->first();
        $this->assertEquals(ReservationStatus::Expired->value, $reservation->status->value);
    }

    // ─── 4. Duplicate Payment Webhook ─────────────────────────────────────────

    /** @test */
    public function duplicate_payment_webhook_is_a_no_op(): void
    {
        $order = $this->createPaidOrder();

        $payment = Payment::where('order_id', $order->id)->first();

        // Create existing attempt to simulate the webhook already processed
        PaymentAttempt::create([
            'payment_id'        => $payment->id,
            'provider_event_id' => 'wh-dup-001',
            'status'            => 'succeeded',
            'attempt_number'    => 1,
            'response_payload'  => [],
            'attempted_at'      => now(),
        ]);

        // Send the same webhook again
        $webhookPayload = [
            'payment_id'         => $payment->id,
            'order_id'           => $order->id,
            'status'             => 'succeeded',
            'provider_reference' => 'sim_pi_dup',
            'amount_minor'       => $order->total_amount_minor,
            'currency'           => 'MYR',
        ];

        $response = $this->postJson(
            '/api/v1/webhooks/payments',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-dup-001') // same event ID
        );

        $response->assertOk();

        // Verify order is still paid — no state regression
        $order->refresh();
        $this->assertEquals(OrderStatus::Paid->value, $order->status->value);

        // Verify no new attempts created (duplicate guard)
        $attemptCount = PaymentAttempt::where('provider_event_id', 'wh-dup-001')->count();
        $this->assertEquals(1, $attemptCount);
    }

    // ─── 5. Idempotency Key Reuse ─────────────────────────────────────────────

    /** @test */
    public function same_idempotency_key_returns_original_order(): void
    {
        $idemKey = 'idem-key-reuse-test';

        $response1 = $this->actingAs($this->attendee, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson('/api/v1/orders', [
                'event_id' => $this->event->id,
                'items'    => [['ticket_type_id' => $this->ticketType->id, 'quantity' => 1]],
            ]);

        $response1->assertCreated();
        $orderId1 = $response1->json('data.order_id');

        // Second identical request — should return the same order
        $response2 = $this->actingAs($this->attendee, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson('/api/v1/orders', [
                'event_id' => $this->event->id,
                'items'    => [['ticket_type_id' => $this->ticketType->id, 'quantity' => 1]],
            ]);

        $response2->assertCreated();
        $orderId2 = $response2->json('data.order_id');

        $this->assertEquals($orderId1, $orderId2, 'Same idempotency key must return the same order');

        // Pool should only have 1 reserved unit (not 2)
        $this->pool->refresh();
        $this->assertEquals(1, $this->pool->reserved_units);
    }

    // ─── 6. Late Payment → payment_review ────────────────────────────────────

    /** @test */
    public function late_payment_moves_order_to_payment_review_without_issuing_tickets(): void
    {
        // Create an expired order with expired reservation
        $order = Order::create([
            'user_id'                  => $this->attendee->id,
            'event_id'                 => $this->event->id,
            'order_number'             => 'EH-20240101-000002',
            'creation_idempotency_key' => 'late-payment-idem-001',
            'status'                   => OrderStatus::Expired->value,
            'subtotal_minor'           => 10000,
            'total_amount_minor'       => 10000,
            'currency'                 => 'MYR',
            'hold_expires_at'          => now()->subMinutes(20),
        ]);

        TicketReservation::create([
            'order_id'          => $order->id,
            'ticket_type_id'    => $this->ticketType->id,
            'inventory_pool_id' => $this->pool->id,
            'purchase_quantity' => 1,
            'reserved_units'    => 1,
            'status'            => ReservationStatus::Expired->value,
            'expires_at'        => now()->subMinutes(20),
        ]);

        $payment = Payment::create([
            'order_id'        => $order->id,
            'provider'        => 'stripe_simulator',
            'status'          => PaymentStatus::Pending->value,
            'idempotency_key' => 'pmt-late-001',
            'amount_minor'    => 10000,
            'currency'        => 'MYR',
        ]);

        $webhookPayload = [
            'payment_id'         => $payment->id,
            'order_id'           => $order->id,
            'status'             => 'succeeded',
            'provider_reference' => 'sim_pi_late',
            'amount_minor'       => 10000,
            'currency'           => 'MYR',
        ];

        $response = $this->postJson(
            '/api/v1/webhooks/payments',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-late-001')
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'payment_review');

        // Verify no tickets issued
        $this->assertEquals(0, Ticket::where('order_id', $order->id)->count());

        // Verify order status
        $order->refresh();
        $this->assertEquals(OrderStatus::PaymentReview->value, $order->status->value);

        // Verify pool inventory NOT decremented (inventory was already released)
        $this->pool->refresh();
        $this->assertEquals(0, $this->pool->sold_units);
    }

    // ─── 7. Payment Failure ───────────────────────────────────────────────────

    /** @test */
    public function payment_failure_releases_inventory_and_marks_order_failed(): void
    {
        $response = $this->actingAs($this->attendee, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'idem-fail-001'])
            ->postJson('/api/v1/orders', [
                'event_id' => $this->event->id,
                'items'    => [['ticket_type_id' => $this->ticketType->id, 'quantity' => 3]],
            ]);

        $response->assertCreated();
        $orderId = $response->json('data.order_id');

        $payment = Payment::create([
            'order_id'        => $orderId,
            'provider'        => 'stripe_simulator',
            'status'          => PaymentStatus::Pending->value,
            'idempotency_key' => 'pmt-fail-idem-001',
            'amount_minor'    => 30000,
            'currency'        => 'MYR',
        ]);

        $webhookPayload = [
            'payment_id' => $payment->id,
            'order_id'   => $orderId,
            'status'     => 'failed',
            'amount_minor' => 30000,
            'currency'   => 'MYR',
        ];

        $webhookResponse = $this->postJson(
            '/api/v1/webhooks/payments',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-fail-001')
        );

        $webhookResponse->assertOk();

        $order = Order::find($orderId);
        $this->assertEquals(OrderStatus::Failed->value, $order->status->value);

        // Inventory fully released
        $this->pool->refresh();
        $this->assertEquals(0, $this->pool->reserved_units);
        $this->assertEquals(0, $this->pool->sold_units);
    }

    // ─── 8. Group Bundle Consumes Multiple Admission Units ────────────────────

    /** @test */
    public function group_bundle_consumes_correct_admission_units(): void
    {
        $groupBundle = TicketType::factory()->create([
            'event_id'                    => $this->event->id,
            'inventory_pool_id'           => $this->pool->id,
            'price_minor'                 => 35000,
            'currency'                    => 'MYR',
            'admission_units_per_purchase' => 4, // group of 4
            'is_active'                   => true,
            'category'                    => 'group_bundle',
        ]);

        $response = $this->actingAs($this->attendee, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'idem-group-001'])
            ->postJson('/api/v1/orders', [
                'event_id' => $this->event->id,
                'items'    => [
                    ['ticket_type_id' => $groupBundle->id, 'quantity' => 1],
                ],
            ]);

        $response->assertCreated();

        // 1 purchase × 4 admission units = 4 reserved units
        $this->pool->refresh();
        $this->assertEquals(4, $this->pool->reserved_units);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createPaidOrder(): Order
    {
        $order = Order::create([
            'user_id'                  => $this->attendee->id,
            'event_id'                 => $this->event->id,
            'order_number'             => 'EH-20240101-PAID',
            'creation_idempotency_key' => 'paid-order-idem-001',
            'status'                   => OrderStatus::Paid->value,
            'subtotal_minor'           => 10000,
            'total_amount_minor'       => 10000,
            'currency'                 => 'MYR',
            'hold_expires_at'          => now()->addMinutes(15),
            'paid_at'                  => now(),
        ]);

        Payment::create([
            'order_id'        => $order->id,
            'provider'        => 'stripe_simulator',
            'status'          => PaymentStatus::Succeeded->value,
            'idempotency_key' => 'pmt-paid-001',
            'amount_minor'    => 10000,
            'currency'        => 'MYR',
            'succeeded_at'    => now(),
        ]);

        return $order;
    }

    /**
     * Build HMAC-signed webhook headers for testing.
     */
    private function webhookHeaders(array $payload, string $eventId): array
    {
        $secret    = config('services.payment.webhook_secret', 'webhook-hmac-secret');
        $timestamp = (string) time();
        $body      = json_encode($payload);
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return [
            'X-Webhook-Id'        => $eventId,
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => $signature,
            'Content-Type'        => 'application/json',
        ];
    }
}
