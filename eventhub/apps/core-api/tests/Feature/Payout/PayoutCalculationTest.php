<?php

namespace Tests\Feature\Payout;

use App\Enums\EventStatus;
use App\Enums\KycStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PlatformCommissionRate;
use App\Models\PlatformPayoutSetting;
use App\Models\RefundRequest;
use App\Models\TicketInventoryPool;
use App\Models\TicketType;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Payout\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for payout calculation (Step 11).
 *
 * Tests covered:
 *   ✅ Normal commission calculation (gross - refund - commission = net)
 *   ✅ Zero sales → net = 0 (does not create payout — below threshold)
 *   ✅ Partial refund reduces gross correctly
 *   ✅ Below minimum threshold → 422
 *   ✅ Same period submitted twice → idempotent (second call returns same payout)
 *   ✅ Payout period conflict → 409
 *   ✅ Admin approve → payout → processing, PayoutAttempt created
 *   ✅ Webhook success → payout completed
 *   ✅ Webhook failure → payout failed
 */
class PayoutCalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Vendor $vendor;
    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);

        // 10% commission rate
        PlatformCommissionRate::create([
            'rate_basis_points'  => 1000,
            'effective_from'     => now()->subYear(),
            'created_by_user_id' => $this->admin->id,
            'created_at'         => now()->subYear(),
        ]);

        // Min payout 100 sen (MYR 1.00)
        PlatformPayoutSetting::create([
            'minimum_payout_minor' => 100,
            'currency'             => 'MYR',
            'effective_from'       => now()->subYear(),
            'created_by_user_id'   => $this->admin->id,
            'created_at'           => now()->subYear(),
        ]);

        $vendorUser = User::factory()->create(['role' => UserRole::Vendor->value]);
        $this->vendor = Vendor::factory()->create([
            'user_id'    => $vendorUser->id,
            'kyc_status' => KycStatus::Verified->value,
        ]);

        $event = Event::factory()->create([
            'vendor_id'    => $this->vendor->id,
            'status'       => EventStatus::Published->value,
            'start_at_utc' => now()->addDays(30),
            'end_at_utc'   => now()->addDays(30)->addHours(6),
        ]);

        $pool = TicketInventoryPool::factory()->create([
            'event_id'       => $event->id,
            'capacity_units' => 100,
            'sold_units'     => 0,
        ]);

        $this->ticketType = TicketType::factory()->create([
            'event_id'          => $event->id,
            'inventory_pool_id' => $pool->id,
            'price_minor'       => 10000,
            'currency'          => 'MYR',
        ]);
    }

    // ─── 1. Normal commission calculation ─────────────────────────────────────

    /** @test */
    public function payout_preview_correctly_calculates_gross_commission_and_net(): void
    {
        // Create 3 paid orders: 3 × MYR 100.00 = MYR 300.00 gross
        $this->createPaidOrderWithItem(30000);
        $this->createPaidOrderWithItem(30000);
        $this->createPaidOrderWithItem(30000);

        $service = app(PayoutService::class);
        $preview = $service->calculatePreview(
            $this->vendor->id,
            now()->subDays(1)->toDateString(),
            now()->toDateString()
        );

        // Gross: 90000 sen (MYR 900.00)
        $this->assertEquals(90000, $preview['gross_amount_minor']);

        // Commission: 10% = 9000 sen
        $this->assertEquals(9000, $preview['commission_amount_minor']);
        $this->assertEquals(1000, $preview['commission_rate_basis_points']);

        // Refunded: 0
        $this->assertEquals(0, $preview['refunded_amount_minor']);

        // Net: 90000 - 0 - 9000 = 81000
        $this->assertEquals(81000, $preview['net_amount_minor']);

        $this->assertFalse($preview['below_threshold']);
    }

    // ─── 2. Zero sales ───────────────────────────────────────────────────────

    /** @test */
    public function zero_sales_results_in_net_amount_zero_and_fails_minimum_threshold(): void
    {
        // No paid orders
        $service = app(PayoutService::class);
        $preview = $service->calculatePreview(
            $this->vendor->id,
            now()->subDays(7)->toDateString(),
            now()->toDateString()
        );

        $this->assertEquals(0, $preview['gross_amount_minor']);
        $this->assertEquals(0, $preview['net_amount_minor']);
        $this->assertTrue($preview['below_threshold']);
    }

    /** @test */
    public function creating_payout_below_threshold_returns_422(): void
    {
        // No sales → net = 0 → below threshold
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payouts', [
                'vendor_id'    => $this->vendor->id,
                'period_start' => now()->subDays(7)->toDateString(),
                'period_end'   => now()->toDateString(),
            ]);

        $response->assertUnprocessable(); // 422
    }

    // ─── 3. Partial refund reduces gross ─────────────────────────────────────

    /** @test */
    public function partial_refund_reduces_net_payout_amount(): void
    {
        $order = $this->createPaidOrderWithItem(50000); // MYR 500.00

        // Simulate a completed refund of 25000 (50%) on this order
        RefundRequest::create([
            'order_id'                   => $order->id,
            'requested_by_user_id'       => $order->user_id,
            'idempotency_key'            => 'refund-partial-' . $order->id,
            'status'                     => 'completed',
            'policy_percentage_snapshot' => 50,
            'original_amount_minor'      => 50000,
            'requested_amount_minor'     => 25000,
            'approved_amount_minor'      => 25000,
            'currency'                   => 'MYR',
            'calculated_at'              => now(),
        ]);

        $service = app(PayoutService::class);
        $preview = $service->calculatePreview(
            $this->vendor->id,
            now()->subDays(1)->toDateString(),
            now()->toDateString()
        );

        $this->assertEquals(50000, $preview['gross_amount_minor']);
        $this->assertEquals(25000, $preview['refunded_amount_minor']);

        // Commission on gross (not net-of-refund): 50000 * 10% = 5000
        $this->assertEquals(5000, $preview['commission_amount_minor']);

        // Net: 50000 - 25000 - 5000 = 20000
        $this->assertEquals(20000, $preview['net_amount_minor']);
    }

    // ─── 4. Idempotency (same period submitted twice) ─────────────────────────

    /** @test */
    public function same_idempotency_key_returns_the_original_payout(): void
    {
        $this->createPaidOrderWithItem(50000);

        $idemKey = 'payout-idem-test-001';

        $response1 = $this->actingAs($this->admin, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson('/api/v1/admin/payouts', [
                'vendor_id'    => $this->vendor->id,
                'period_start' => now()->subDays(1)->toDateString(),
                'period_end'   => now()->toDateString(),
            ]);

        $response1->assertCreated();
        $payoutId1 = $response1->json('data.id');

        $response2 = $this->actingAs($this->admin, 'sanctum')
            ->withHeaders(['Idempotency-Key' => $idemKey])
            ->postJson('/api/v1/admin/payouts', [
                'vendor_id'    => $this->vendor->id,
                'period_start' => now()->subDays(1)->toDateString(),
                'period_end'   => now()->toDateString(),
            ]);

        // Second call returns existing payout (idempotent)
        $payoutId2 = $response2->json('data.id');
        $this->assertEquals($payoutId1, $payoutId2);
    }

    /** @test */
    public function duplicate_period_without_idempotency_key_returns_409(): void
    {
        $this->createPaidOrderWithItem(50000);

        // First payout for the period
        $this->actingAs($this->admin, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'unique-key-001'])
            ->postJson('/api/v1/admin/payouts', [
                'vendor_id'    => $this->vendor->id,
                'period_start' => now()->subDays(1)->toDateString(),
                'period_end'   => now()->toDateString(),
            ])
            ->assertCreated();

        // Second payout for the same period with a different key → conflict
        $response = $this->actingAs($this->admin, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'unique-key-002'])
            ->postJson('/api/v1/admin/payouts', [
                'vendor_id'    => $this->vendor->id,
                'period_start' => now()->subDays(1)->toDateString(),
                'period_end'   => now()->toDateString(),
            ]);

        $response->assertConflict(); // 409
    }

    // ─── 5. Admin approve ─────────────────────────────────────────────────────

    /** @test */
    public function admin_can_approve_pending_payout(): void
    {
        $this->createPaidOrderWithItem(50000);

        $createResponse = $this->actingAs($this->admin, 'sanctum')
            ->withHeaders(['Idempotency-Key' => 'payout-approve-test'])
            ->postJson('/api/v1/admin/payouts', [
                'vendor_id'    => $this->vendor->id,
                'period_start' => now()->subDays(1)->toDateString(),
                'period_end'   => now()->toDateString(),
            ]);

        $createResponse->assertCreated();
        $payoutId = $createResponse->json('data.id');

        $approveResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/payouts/{$payoutId}/approve");

        file_put_contents('/var/www/html/storage/logs/payout_error.txt', $approveResponse->getContent());
        $approveResponse->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseHas('payouts', [
            'id'     => $payoutId,
            'status' => 'processing',
        ]);

        // PayoutAttempt created
        $this->assertDatabaseHas('payout_attempts', [
            'payout_id' => $payoutId,
            'status'    => 'processing',
        ]);
    }

    /** @test */
    public function admin_can_retry_a_failed_payout(): void
    {
        $this->createPaidOrderWithItem(50000);

        $payout = Payout::create([
            'vendor_id'                            => $this->vendor->id,
            'commission_rate_id'                   => PlatformCommissionRate::first()->id,
            'payout_setting_id'                    => PlatformPayoutSetting::first()->id,
            'payout_number'                        => 'PO-RETRY-001',
            'period_start'                         => now()->subDays(7)->toDateString(),
            'period_end'                           => now()->toDateString(),
            'gross_amount_minor'                   => 50000,
            'refunded_amount_minor'                => 0,
            'commission_rate_basis_points_snapshot' => 1000,
            'commission_amount_minor'              => 5000,
            'net_amount_minor'                     => 45000,
            'minimum_threshold_minor_snapshot'     => 100,
            'currency'                             => 'MYR',
            'status'                               => PayoutStatus::Failed->value,
            'idempotency_key'                      => 'payout-retry-001',
        ]);

        // First attempt that failed
        \App\Models\PayoutAttempt::create([
            'payout_id'         => $payout->id,
            'provider_event_id' => 'old-event-id',
            'status'            => 'failed',
            'attempt_number'    => 1,
            'response_payload'  => [],
            'attempted_at'      => now()->subHour(),
        ]);

        $approveResponse = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/payouts/{$payout->id}/approve");

        $approveResponse->dump();
        $approveResponse->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseHas('payouts', [
            'id'     => $payout->id,
            'status' => 'processing',
        ]);

        // A second PayoutAttempt is created
        $this->assertDatabaseHas('payout_attempts', [
            'payout_id'      => $payout->id,
            'status'         => 'processing',
            'attempt_number' => 2,
        ]);
    }

    // ─── 6. Webhook ──────────────────────────────────────────────────────────

    /** @test */
    public function payout_webhook_success_marks_payout_completed(): void
    {
        $this->createPaidOrderWithItem(50000);

        $payout = Payout::create([
            'vendor_id'                            => $this->vendor->id,
            'commission_rate_id'                   => PlatformCommissionRate::first()->id,
            'payout_setting_id'                    => PlatformPayoutSetting::first()->id,
            'payout_number'                        => 'PO-TEST-001',
            'period_start'                         => now()->subDays(7)->toDateString(),
            'period_end'                           => now()->toDateString(),
            'gross_amount_minor'                   => 50000,
            'refunded_amount_minor'                => 0,
            'commission_rate_basis_points_snapshot' => 1000,
            'commission_amount_minor'              => 5000,
            'net_amount_minor'                     => 45000,
            'minimum_threshold_minor_snapshot'     => 100,
            'currency'                             => 'MYR',
            'status'                               => PayoutStatus::Processing->value,
            'idempotency_key'                      => 'payout-wh-success-001',
        ]);

        $webhookPayload = [
            'payout_id'          => $payout->id,
            'vendor_id'          => $this->vendor->id,
            'status'             => 'succeeded',
            'amount_minor'       => 45000,
            'currency'           => 'MYR',
            'provider_reference' => 'stripe_po_test',
        ];

        $response = $this->postJson(
            '/api/v1/webhooks/payouts',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-payout-001')
        );

        $response->assertOk();

        $payout->refresh();
        $this->assertEquals(PayoutStatus::Completed->value, $payout->status->value);
        $this->assertNotNull($payout->completed_at);
    }

    /** @test */
    public function payout_webhook_failure_marks_payout_failed(): void
    {
        $payout = Payout::create([
            'vendor_id'                            => $this->vendor->id,
            'commission_rate_id'                   => PlatformCommissionRate::first()->id,
            'payout_setting_id'                    => PlatformPayoutSetting::first()->id,
            'payout_number'                        => 'PO-TEST-002',
            'period_start'                         => now()->subDays(7)->toDateString(),
            'period_end'                           => now()->toDateString(),
            'gross_amount_minor'                   => 50000,
            'refunded_amount_minor'                => 0,
            'commission_rate_basis_points_snapshot' => 1000,
            'commission_amount_minor'              => 5000,
            'net_amount_minor'                     => 45000,
            'minimum_threshold_minor_snapshot'     => 100,
            'currency'                             => 'MYR',
            'status'                               => PayoutStatus::Processing->value,
            'idempotency_key'                      => 'payout-wh-fail-001',
        ]);

        $webhookPayload = [
            'payout_id'    => $payout->id,
            'vendor_id'    => $this->vendor->id,
            'status'       => 'failed',
            'amount_minor' => 45000,
            'currency'     => 'MYR',
        ];

        $this->postJson(
            '/api/v1/webhooks/payouts',
            $webhookPayload,
            $this->webhookHeaders($webhookPayload, 'wh-payout-fail-001')
        )->assertOk();

        $payout->refresh();
        $this->assertEquals(PayoutStatus::Failed->value, $payout->status->value);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createPaidOrderWithItem(int $subtotalMinor): Order
    {
        $attendee = User::factory()->create(['role' => UserRole::Attendee->value]);

        $order = Order::create([
            'user_id'                  => $attendee->id,
            'event_id'                 => $this->ticketType->event_id,
            'order_number'             => 'EH-' . now()->format('Ymd') . '-' . uniqid(),
            'creation_idempotency_key' => 'payout-test-' . uniqid(),
            'status'                   => OrderStatus::Paid->value,
            'subtotal_minor'           => $subtotalMinor,
            'total_amount_minor'       => $subtotalMinor,
            'currency'                 => 'MYR',
            'hold_expires_at'          => now()->addMinutes(15),
            'paid_at'                  => now()->subHours(1),
        ]);

        OrderItem::create([
            'order_id'                             => $order->id,
            'ticket_type_id'                       => $this->ticketType->id,
            'ticket_type_name_snapshot'            => 'General Admission',
            'purchase_quantity'                    => 1,
            'admission_units_per_purchase_snapshot' => 1,
            'admission_quantity'                   => 1,
            'unit_price_minor_snapshot'            => $subtotalMinor,
            'subtotal_minor'                       => $subtotalMinor,
            'currency'                             => 'MYR',
            'created_at'                           => now(),
        ]);

        Payment::create([
            'order_id'        => $order->id,
            'provider'        => 'stripe_simulator',
            'status'          => PaymentStatus::Succeeded->value,
            'idempotency_key' => 'pmt-' . $order->id,
            'amount_minor'    => $subtotalMinor,
            'currency'        => 'MYR',
            'succeeded_at'    => now(),
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
