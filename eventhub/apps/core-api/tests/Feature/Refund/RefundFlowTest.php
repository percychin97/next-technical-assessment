<?php

namespace Tests\Feature\Refund;

use App\Enums\EventStatus;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundRequestStatus;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformCommissionRate;
use App\Models\PlatformPayoutSetting;
use App\Models\Refund;
use App\Models\RefundRequest;
use App\Models\Ticket;
use App\Models\TicketInventoryPool;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Refund\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the refund flow (Step 11).
 *
 * Tests covered:
 *   ✅ 100% refund: > 48 hours before event
 *   ✅ 50% refund: 24–48 hours before event
 *   ✅ 0% refund: < 24 hours before event
 *   ✅ Attendee cannot refund another user's order (403)
 *   ✅ Duplicate refund request rejected (409 conflict)
 *   ✅ Admin approve: RefundRequest → processing, Refund created
 *   ✅ Admin deny: RefundRequest → denied
 *   ✅ Refund webhook success → order refunded, tickets voided
 *   ✅ Refund webhook failure → refund failed
 *   ✅ Duplicate webhook ignored (idempotency)
 */
class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $attendee;
    private User $admin;
    private Event $event;
    private Order $paidOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin    = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->attendee = User::factory()->create(['role' => UserRole::Attendee->value]);

        // Platform config (required for payout calculation)
        PlatformCommissionRate::create([
            'rate_basis_points'  => 1000, // 10%
            'effective_from'     => now()->subYear(),
            'created_by_user_id' => $this->admin->id,
            'created_at'         => now()->subYear(),
        ]);

        PlatformPayoutSetting::create([
            'minimum_payout_minor' => 100,
            'currency'             => 'MYR',
            'effective_from'       => now()->subYear(),
            'created_by_user_id'   => $this->admin->id,
            'created_at'           => now()->subYear(),
        ]);

        $vendorUser = User::factory()->create(['role' => UserRole::Vendor->value]);
        $vendor     = Vendor::factory()->create([
            'user_id'    => $vendorUser->id,
            'kyc_status' => KycStatus::Verified->value,
        ]);

        // Event 72 hours in the future (well within 100% refund window)
        $this->event = Event::factory()->create([
            'vendor_id'    => $vendor->id,
            'status'       => EventStatus::Published->value,
            'start_at_utc' => now()->addHours(72),
            'end_at_utc'   => now()->addHours(78),
        ]);

        $pool = TicketInventoryPool::factory()->create([
            'event_id'       => $this->event->id,
            'capacity_units' => 10,
            'sold_units'     => 2,
        ]);

        $ticketType = TicketType::factory()->create([
            'event_id'         => $this->event->id,
            'inventory_pool_id' => $pool->id,
            'price_minor'      => 10000,
            'currency'         => 'MYR',
        ]);

        $this->paidOrder = $this->createPaidOrder($this->attendee, $ticketType);
    }

    // ─── 1. Policy calculation ────────────────────────────────────────────────

    /** @test */
    public function refund_policy_returns_100_percent_more_than_48_hours_before_event(): void
    {
        // Event is 72 hours away (setUp default)
        $service = app(RefundService::class);
        $policy  = $service->calculatePolicy($this->paidOrder);

        $this->assertEquals(100, $policy['percentage']);
        $this->assertEquals($this->paidOrder->total_amount_minor, $policy['eligible_amount_minor']);
    }

    /** @test */
    public function refund_policy_returns_50_percent_between_24_and_48_hours_before_event(): void
    {
        // Move event to 36 hours from now
        $this->event->update(['start_at_utc' => now()->addHours(36)]);

        $service = app(RefundService::class);
        $policy  = $service->calculatePolicy($this->paidOrder);

        $this->assertEquals(50, $policy['percentage']);
        $this->assertEquals((int) floor($this->paidOrder->total_amount_minor * 0.5), $policy['eligible_amount_minor']);
    }

    /** @test */
    public function refund_policy_returns_0_percent_less_than_24_hours_before_event(): void
    {
        // Move event to 12 hours from now
        $this->event->update(['start_at_utc' => now()->addHours(12)]);

        $service = app(RefundService::class);
        $policy  = $service->calculatePolicy($this->paidOrder);

        $this->assertEquals(0, $policy['percentage']);
        $this->assertEquals(0, $policy['eligible_amount_minor']);
    }

    // ─── 2. API: attendee requests refund ─────────────────────────────────────

    /** @test */
    public function attendee_can_request_refund_for_paid_order(): void
    {
        $response = $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/v1/orders/{$this->paidOrder->id}/refund-request", [
                'reason' => 'Change of plans',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.policy_percentage_snapshot', 100);

        $this->assertDatabaseHas('refund_requests', [
            'order_id'                   => $this->paidOrder->id,
            'requested_by_user_id'       => $this->attendee->id,
            'status'                     => 'requested',
            'policy_percentage_snapshot' => 100,
            'original_amount_minor'      => $this->paidOrder->total_amount_minor,
        ]);
    }

    /** @test */
    public function attendee_cannot_refund_another_users_order(): void
    {
        $otherUser = User::factory()->create(['role' => UserRole::Attendee->value]);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/v1/orders/{$this->paidOrder->id}/refund-request");

        $response->assertForbidden();
    }

    /** @test */
    public function duplicate_refund_request_is_rejected_with_409(): void
    {
        // Create first request
        $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/v1/orders/{$this->paidOrder->id}/refund-request")
            ->assertCreated();

        // Second request — should conflict
        $response = $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/v1/orders/{$this->paidOrder->id}/refund-request");

        $response->assertConflict();
    }

    // ─── 3. Admin: approve refund ─────────────────────────────────────────────

    /** @test */
    public function admin_can_approve_refund_request(): void
    {
        // Attendee submits request
        $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/v1/orders/{$this->paidOrder->id}/refund-request")
            ->assertCreated();

        $refundRequest = RefundRequest::where('order_id', $this->paidOrder->id)->first();

        // Admin approves
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/refund-requests/{$refundRequest->id}/approve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'processing');

        // Refund record created in processing state
        $this->assertDatabaseHas('refunds', [
            'refund_request_id' => $refundRequest->id,
            'status'            => 'pending',
        ]);
    }

    /** @test */
    public function admin_can_deny_refund_request(): void
    {
        $this->actingAs($this->attendee, 'sanctum')
            ->postJson("/api/v1/orders/{$this->paidOrder->id}/refund-request")
            ->assertCreated();

        $refundRequest = RefundRequest::where('order_id', $this->paidOrder->id)->first();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/refund-requests/{$refundRequest->id}/deny", [
                'reason' => 'Outside policy window',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'denied');

        $this->assertDatabaseHas('refund_requests', [
            'id'     => $refundRequest->id,
            'status' => 'denied',
        ]);
    }

    // ─── 4. Webhook: refund success ───────────────────────────────────────────

    /** @test */
    public function refund_webhook_success_marks_order_refunded_and_voids_tickets(): void
    {
        // Create pending Refund record directly
        $refundRequest = RefundRequest::create([
            'order_id'                   => $this->paidOrder->id,
            'requested_by_user_id'       => $this->attendee->id,
            'idempotency_key'            => 'refund-req-001',
            'status'                     => RefundRequestStatus::Processing->value,
            'policy_percentage_snapshot' => 100,
            'original_amount_minor'      => $this->paidOrder->total_amount_minor,
            'requested_amount_minor'     => $this->paidOrder->total_amount_minor,
            'approved_amount_minor'      => $this->paidOrder->total_amount_minor,
            'currency'                   => 'MYR',
            'calculated_at'              => now(),
        ]);

        $payment = $this->paidOrder->payments()->first();

        $refund = Refund::create([
            'refund_request_id' => $refundRequest->id,
            'payment_id'        => $payment->id,
            'status'            => RefundStatus::Pending->value,
            'idempotency_key'   => "refund-{$refundRequest->id}",
            'amount_minor'      => $this->paidOrder->total_amount_minor,
            'currency'          => 'MYR',
        ]);

        $webhookPayload = [
            'refund_id'          => $refund->id,
            'payment_id'         => $payment->id,
            'status'             => 'succeeded',
            'amount_minor'       => $this->paidOrder->total_amount_minor,
            'currency'           => 'MYR',
            'provider_reference' => 'stripe_re_test',
        ];

        $response = $this->postJson(
            '/api/v1/webhooks/refunds',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-refund-001')
        );

        $response->assertOk();

        // Order should now be refunded
        $this->paidOrder->refresh();
        $this->assertEquals(OrderStatus::Refunded->value, $this->paidOrder->status->value);

        // Refund record should be completed
        $refund->refresh();
        $this->assertEquals(RefundStatus::Completed->value, $refund->status->value);

        // Tickets should be voided
        Ticket::where('order_id', $this->paidOrder->id)->each(function ($ticket) {
            $this->assertEquals(TicketStatus::Voided->value, $ticket->status->value);
        });
    }

    /** @test */
    public function refund_webhook_failure_marks_refund_failed(): void
    {
        $refundRequest = RefundRequest::create([
            'order_id'                   => $this->paidOrder->id,
            'requested_by_user_id'       => $this->attendee->id,
            'idempotency_key'            => 'refund-req-fail-001',
            'status'                     => RefundRequestStatus::Processing->value,
            'policy_percentage_snapshot' => 100,
            'original_amount_minor'      => $this->paidOrder->total_amount_minor,
            'requested_amount_minor'     => $this->paidOrder->total_amount_minor,
            'approved_amount_minor'      => $this->paidOrder->total_amount_minor,
            'currency'                   => 'MYR',
            'calculated_at'              => now(),
        ]);

        $payment = $this->paidOrder->payments()->first();

        $refund = Refund::create([
            'refund_request_id' => $refundRequest->id,
            'payment_id'        => $payment->id,
            'status'            => RefundStatus::Pending->value,
            'idempotency_key'   => "refund-{$refundRequest->id}",
            'amount_minor'      => $this->paidOrder->total_amount_minor,
            'currency'          => 'MYR',
        ]);

        $webhookPayload = [
            'refund_id'    => $refund->id,
            'payment_id'   => $payment->id,
            'status'       => 'failed',
            'amount_minor' => $this->paidOrder->total_amount_minor,
            'currency'     => 'MYR',
        ];

        $this->postJson(
            '/api/v1/webhooks/refunds',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-refund-fail-001')
        )->assertOk();

        $refund->refresh();
        $this->assertEquals(RefundStatus::Failed->value, $refund->status->value);

        $refundRequest->refresh();
        $this->assertEquals(RefundRequestStatus::Failed->value, $refundRequest->status->value);
    }

    /** @test */
    public function duplicate_refund_webhook_is_a_no_op(): void
    {
        $refundRequest = RefundRequest::create([
            'order_id'                   => $this->paidOrder->id,
            'requested_by_user_id'       => $this->attendee->id,
            'idempotency_key'            => 'refund-req-dup-001',
            'status'                     => RefundRequestStatus::Completed->value,
            'policy_percentage_snapshot' => 100,
            'original_amount_minor'      => $this->paidOrder->total_amount_minor,
            'requested_amount_minor'     => $this->paidOrder->total_amount_minor,
            'approved_amount_minor'      => $this->paidOrder->total_amount_minor,
            'currency'                   => 'MYR',
            'calculated_at'              => now(),
        ]);

        $payment = $this->paidOrder->payments()->first();

        $refund = Refund::create([
            'refund_request_id' => $refundRequest->id,
            'payment_id'        => $payment->id,
            'status'            => RefundStatus::Completed->value, // already done
            'idempotency_key'   => "refund-{$refundRequest->id}",
            'amount_minor'      => $this->paidOrder->total_amount_minor,
            'currency'          => 'MYR',
            'completed_at'      => now(),
        ]);

        $webhookPayload = [
            'refund_id'    => $refund->id,
            'payment_id'   => $payment->id,
            'status'       => 'succeeded',
            'amount_minor' => $this->paidOrder->total_amount_minor,
            'currency'     => 'MYR',
        ];

        // Send same webhook twice
        $this->postJson('/api/v1/webhooks/refunds', $webhookPayload, $this->webhookHeaders($webhookPayload, 'wh-dup-001'))->assertOk();
        $this->postJson('/api/v1/webhooks/refunds', $webhookPayload, $this->webhookHeaders($webhookPayload, 'wh-dup-002'))->assertOk();

        // Refund is still completed — not changed
        $refund->refresh();
        $this->assertEquals(RefundStatus::Completed->value, $refund->status->value);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createPaidOrder(User $user, TicketType $ticketType): Order
    {
        $order = Order::create([
            'user_id'                  => $user->id,
            'event_id'                 => $ticketType->event_id,
            'order_number'             => 'EH-' . now()->format('Ymd') . '-TEST01',
            'creation_idempotency_key' => 'paid-order-refund-test-' . uniqid(),
            'status'                   => OrderStatus::Paid->value,
            'subtotal_minor'           => 20000,
            'total_amount_minor'       => 20000,
            'currency'                 => 'MYR',
            'hold_expires_at'          => now()->addMinutes(15),
            'paid_at'                  => now(),
        ]);

        Payment::create([
            'order_id'        => $order->id,
            'provider'        => 'stripe_simulator',
            'status'          => PaymentStatus::Succeeded->value,
            'idempotency_key' => 'pmt-' . $order->id,
            'amount_minor'    => 20000,
            'currency'        => 'MYR',
            'succeeded_at'    => now(),
        ]);

        $orderItem = \App\Models\OrderItem::create([
            'order_id'                             => $order->id,
            'ticket_type_id'                       => $ticketType->id,
            'ticket_type_name_snapshot'            => 'General Admission',
            'purchase_quantity'                    => 1,
            'admission_units_per_purchase_snapshot' => 1,
            'admission_quantity'                   => 1,
            'unit_price_minor_snapshot'            => 20000,
            'subtotal_minor'                       => 20000,
            'currency'                             => 'MYR',
        ]);

        Ticket::create([
            'order_id'        => $order->id,
            'order_item_id'   => $orderItem->id,
            'ticket_type_id'  => $ticketType->id,
            'ticket_number'   => 'TKT-2024-0000001',
            'qr_token_hash'   => hash('sha256', 'test-token'),
            'status'          => TicketStatus::Valid->value,
            'issued_at'       => now(),
        ]);

        return $order;
    }

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
