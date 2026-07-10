<?php

namespace App\Services\Payout;

use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\PayoutStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Models\OrderItem;
use App\Models\OutboxEvent;
use App\Models\Payout;
use App\Models\PayoutAttempt;
use App\Models\PayoutItem;
use App\Models\PlatformCommissionRate;
use App\Models\PlatformPayoutSetting;
use App\Models\Vendor;
use App\Services\Payment\PaymentGatewayClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PayoutService — manages vendor payout calculation and execution.
 *
 * Calculation:
 *   gross_amount   = sum of paid order_items (subtotal_minor) for the vendor in the period
 *   refunded       = sum of approved refund amounts charged to those orders
 *   commission     = gross * rate_basis_points / 10000   (integer floor)
 *   net_amount     = gross - refunded - commission
 *
 * All money is in integer minor units (sen). Never floating-point.
 */
class PayoutService
{
    public function __construct(private readonly PaymentGatewayClient $paymentClient) {}

    // ─── Preview / Calculation ───────────────────────────────────────────────

    /**
     * Calculate a payout preview without persisting anything.
     *
     * @return array{
     *   vendor_id: string,
     *   period_start: string,
     *   period_end: string,
     *   gross_amount_minor: int,
     *   refunded_amount_minor: int,
     *   commission_rate_basis_points: int,
     *   commission_amount_minor: int,
     *   net_amount_minor: int,
     *   minimum_threshold_minor: int,
     *   currency: string,
     *   below_threshold: bool,
     *   order_item_ids: string[],
     * }
     *
     * @throws DomainException if no commission rate or payout setting is configured
     */
    public function calculatePreview(string $vendorId, string $periodStart, string $periodEnd): array
    {
        $commissionRate   = PlatformCommissionRate::currentRate();
        $payoutSetting    = PlatformPayoutSetting::currentSetting();

        // Fetch all paid order items belonging to this vendor in the period
        $orderItems = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('ticket_types', 'order_items.ticket_type_id', '=', 'ticket_types.id')
            ->join('events', 'ticket_types.event_id', '=', 'events.id')
            ->where('events.vendor_id', $vendorId)
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereBetween('orders.paid_at', [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59'])
            ->select('order_items.*')
            ->get();

        $grossAmount = $orderItems->sum('subtotal_minor');

        // Refunded amounts: sum approved refund amounts for the qualifying orders
        $orderIds       = $orderItems->pluck('order_id')->unique();
        $refundedAmount = \App\Models\RefundRequest::whereIn('order_id', $orderIds)
            ->whereIn('status', ['completed'])
            ->sum('approved_amount_minor');

        // Commission — integer floor to avoid rounding in vendor's favour
        $commissionAmount = (int) floor($grossAmount * $commissionRate->rate_basis_points / 10000);

        // Net amount
        $netAmount = $grossAmount - (int) $refundedAmount - $commissionAmount;
        if ($netAmount < 0) {
            $netAmount = 0;
        }

        return [
            'vendor_id'                    => $vendorId,
            'period_start'                 => $periodStart,
            'period_end'                   => $periodEnd,
            'gross_amount_minor'           => $grossAmount,
            'refunded_amount_minor'        => (int) $refundedAmount,
            'commission_rate_basis_points' => $commissionRate->rate_basis_points,
            'commission_amount_minor'      => $commissionAmount,
            'net_amount_minor'             => $netAmount,
            'minimum_threshold_minor'      => $payoutSetting->minimum_payout_minor,
            'currency'                     => $payoutSetting->currency,
            'below_threshold'              => $netAmount < $payoutSetting->minimum_payout_minor,
            'order_item_ids'               => $orderItems->pluck('id')->all(),
            'commission_rate_id'           => $commissionRate->id,
            'payout_setting_id'            => $payoutSetting->id,
        ];
    }

    // ─── Create payout (admin initiates) ─────────────────────────────────────

    /**
     * Create a pending payout for a vendor after the admin reviews the preview.
     *
     * @throws ConflictException  if a payout for the same period already exists
     * @throws DomainException    if net amount is below the minimum threshold
     */
    public function createPayout(string $vendorId, string $periodStart, string $periodEnd, string $idempotencyKey): Payout
    {
        // Idempotency guard
        $existing = Payout::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        // Period uniqueness guard
        $periodConflict = Payout::where('vendor_id', $vendorId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if ($periodConflict) {
            throw new ConflictException("A payout for vendor {$vendorId} covering {$periodStart}–{$periodEnd} already exists.");
        }

        $preview = $this->calculatePreview($vendorId, $periodStart, $periodEnd);

        if ($preview['below_threshold']) {
            throw new DomainException(
                "Net amount {$preview['net_amount_minor']} is below the minimum payout threshold of {$preview['minimum_threshold_minor']}.",
                422
            );
        }

        return DB::transaction(function () use ($vendorId, $periodStart, $periodEnd, $idempotencyKey, $preview) {
            $payoutNumber = $this->generatePayoutNumber();

            $payout = Payout::create([
                'vendor_id'                            => $vendorId,
                'commission_rate_id'                   => $preview['commission_rate_id'],
                'payout_setting_id'                    => $preview['payout_setting_id'],
                'payout_number'                        => $payoutNumber,
                'period_start'                         => $periodStart,
                'period_end'                           => $periodEnd,
                'gross_amount_minor'                   => $preview['gross_amount_minor'],
                'refunded_amount_minor'                => $preview['refunded_amount_minor'],
                'commission_rate_basis_points_snapshot' => $preview['commission_rate_basis_points'],
                'commission_amount_minor'              => $preview['commission_amount_minor'],
                'net_amount_minor'                     => $preview['net_amount_minor'],
                'minimum_threshold_minor_snapshot'     => $preview['minimum_threshold_minor'],
                'currency'                             => $preview['currency'],
                'status'                               => PayoutStatus::Pending->value,
                'idempotency_key'                      => $idempotencyKey,
            ]);

            // Create payout items referencing each contributing order item
            foreach ($preview['order_item_ids'] as $orderItemId) {
                $orderItem = OrderItem::find($orderItemId);
                PayoutItem::create([
                    'payout_id'             => $payout->id,
                    'order_item_id'         => $orderItemId,
                    'gross_amount_minor'    => $orderItem->subtotal_minor,
                    'refunded_amount_minor' => 0, // detailed at the item level if needed
                    'eligible_amount_minor' => $orderItem->subtotal_minor,
                    'created_at'            => now(),
                ]);
            }

            $this->publishOutbox('payout.created', 'payout', $payout->id, [
                'payout_id'        => $payout->id,
                'vendor_id'        => $vendorId,
                'net_amount_minor' => $preview['net_amount_minor'],
                'currency'         => $preview['currency'],
            ]);

            return $payout;
        });
    }

    // ─── Admin: approve and dispatch to payment service ──────────────────────

    /**
     * Approve a payout and send to the payment service for execution.
     *
     * @throws DomainException if payout is not in 'pending' status
     */
    public function approvePayout(User $admin, Payout $payout): Payout
    {
        if (!in_array($payout->status, [PayoutStatus::Pending, PayoutStatus::Failed])) {
            throw new DomainException("Cannot approve a payout in status '{$payout->status->value}'.");
        }

        $vendor = $payout->vendor;

        return DB::transaction(function () use ($admin, $payout, $vendor) {
            $payout->update([
                'status'      => PayoutStatus::Processing->value,
                'approved_at' => now(),
            ]);

            $idempotency = (string) \Illuminate\Support\Str::uuid();

            // Fetch vendor bank account for the payout payload
            $bankAccount = $vendor->bankAccount ?? [
                'accountHolder'       => $vendor->business_name ?? 'Unknown',
                'bankName'            => 'Unknown',
                'maskedAccountNumber' => '****',
            ];

            $this->paymentClient->createPayout([
                'idempotencyKey'  => $idempotency,
                'idempotency_key' => $idempotency, // for the client header
                'vendorId'        => $vendor->id,
                'provider'        => 'stripe_simulator',
                'amountMinor'     => $payout->net_amount_minor,
                'currency'        => $payout->currency,
                'bankAccount'     => is_array($bankAccount) ? $bankAccount : [
                    'accountHolder'       => $bankAccount->account_holder_name,
                    'bankName'            => $bankAccount->bank_name,
                    'maskedAccountNumber' => $bankAccount->masked_account_number,
                ],
            ]);

            // Track the dispatch attempt
            PayoutAttempt::create([
                'payout_id'         => $payout->id,
                'provider_event_id' => $idempotency,
                'status'            => 'processing',
                'attempt_number'    => $payout->attempts()->count() + 1,
                'response_payload'  => [],
                'attempted_at'      => now(),
            ]);

            AuditLog::create([
                'entity_type'     => 'payout',
                'entity_id'       => $payout->id,
                'action'          => 'approved',
                'previous_status' => PayoutStatus::Pending->value,
                'new_status'      => PayoutStatus::Processing->value,
                'after_state'     => $payout->fresh()->toArray(),
                'actor_user_id'   => $admin->id,
                'correlation_id'  => $idempotency,
            ]);

            return $payout->fresh();
        });
    }

    // ─── Webhook: process payment service callback ───────────────────────────

    /**
     * Process a payout webhook from the payment service.
     *
     * Expected payload:
     *   { payout_id, vendor_id, status, amount_minor, currency, provider_reference, event_id }
     */
    public function processWebhook(array $payload, string $eventId): array
    {
        // Locate by the local payout id embedded in the provider_event_id
        $payout = Payout::find($payload['payout_id'] ?? '');

        if (!$payout) {
            // Also try looking up by vendor_id + provider_reference
            Log::warning('[PayoutWebhook] Payout not found', $payload);
            throw new DomainException('Payout record not found.');
        }

        // Idempotency: if already terminal, return
        if (in_array($payout->status, [PayoutStatus::Completed, PayoutStatus::Failed])) {
            Log::info('[PayoutWebhook] Duplicate webhook ignored', ['payout_id' => $payout->id, 'event_id' => $eventId]);
            return ['status' => $payout->status->value, 'payout_id' => $payout->id];
        }

        $succeeded = ($payload['status'] ?? '') === 'succeeded';

        return DB::transaction(function () use ($payout, $payload, $eventId, $succeeded) {
            $newStatus = $succeeded ? PayoutStatus::Completed : PayoutStatus::Failed;

            $payout->update([
                'status'       => $newStatus->value,
                'completed_at' => $succeeded ? now() : null,
            ]);

            // Update the PayoutAttempt
            $attempt = $payout->attempts()->latest('attempted_at')->first();
            if ($attempt) {
                $attempt->update([
                    'status'           => $succeeded ? 'succeeded' : 'failed',
                    'provider_event_id' => $eventId,
                    'response_payload' => $payload,
                ]);
            }

            $this->publishOutbox(
                $succeeded ? 'payout.completed' : 'payout.failed',
                'payout',
                $payout->id,
                [
                    'payout_id'        => $payout->id,
                    'vendor_id'        => $payout->vendor_id,
                    'amount_minor'     => $payout->net_amount_minor,
                    'currency'         => $payout->currency,
                    'event_id_webhook' => $eventId,
                ]
            );

            return [
                'status'    => $newStatus->value,
                'payout_id' => $payout->id,
            ];
        });
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function generatePayoutNumber(): string
    {
        $date = now()->format('Ymd');
        $seq  = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        return "PO-{$date}-{$seq}";
    }

    private function publishOutbox(string $eventType, string $aggregateType, string $aggregateId, array $payload): void
    {
        OutboxEvent::create([
            'event_type'       => $eventType,
            'aggregate_type'   => $aggregateType,
            'aggregate_id'     => $aggregateId,
            'payload'          => $payload,
            'status'           => OutboxEventStatus::Pending->value,
            'publish_attempts' => 0,
            'available_at'     => now(),
        ]);
    }
}
