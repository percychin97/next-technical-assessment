<?php

namespace App\Services\Refund;

use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\RefundRequestStatus;
use App\Enums\RefundStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Refund;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Payment\PaymentGatewayClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RefundService — manages the full refund lifecycle.
 *
 * Policy (hours before event start):
 *   > 48h  → 100 % refund
 *   24–48h → 50 % refund
 *   < 24h  →  0 % refund (no refund)
 *
 * All money is in integer minor units (sen). Never floating-point.
 */
class RefundService
{
    public function __construct(private readonly PaymentGatewayClient $paymentClient) {}

    // ─── Policy calculation (pure, stateless) ────────────────────────────────

    /**
     * Calculate the refund policy for an order.
     *
     * @return array{percentage: int, eligible_amount_minor: int, hours_until_event: float}
     */
    public function calculatePolicy(Order $order): array
    {
        $event            = $order->event;
        $hoursUntilEvent  = now()->diffInHours($event->start_at_utc, false); // negative if past

        $percentage = match (true) {
            $hoursUntilEvent > 48  => 100,
            $hoursUntilEvent > 24  => 50,
            default                => 0,
        };

        // Integer truncation — never round up in favour of the customer
        $eligibleAmount = (int) floor($order->total_amount_minor * $percentage / 100);

        return [
            'percentage'            => $percentage,
            'eligible_amount_minor' => $eligibleAmount,
            'hours_until_event'     => $hoursUntilEvent,
        ];
    }

    // ─── Attendee: request a refund ─────────────────────────────────────────

    /**
     * Create a refund request for a paid order.
     *
     * @throws ConflictException  if a pending/approved request already exists
     * @throws DomainException    if the order is not refundable
     */
    public function requestRefund(User $user, Order $order, array $data): RefundRequest
    {
        // Guard: order must belong to the requesting user
        if ($order->user_id !== $user->id) {
            throw new DomainException('You do not own this order.', 403);
        }

        // Guard: order must be in a paid state
        if (!in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded])) {
            throw new DomainException('Only paid orders are eligible for a refund request.');
        }

        // Guard: no duplicate active request
        $existingRequest = RefundRequest::where('order_id', $order->id)
            ->whereIn('status', [
                RefundRequestStatus::Requested->value,
                RefundRequestStatus::Approved->value,
                RefundRequestStatus::Processing->value,
            ])
            ->first();

        if ($existingRequest) {
            throw new ConflictException('A refund request is already pending for this order.');
        }

        $policy     = $this->calculatePolicy($order);
        $idempotency = $data['idempotency_key'] ?? (string) Str::uuid();

        return DB::transaction(function () use ($user, $order, $data, $policy, $idempotency) {
            $refundRequest = RefundRequest::create([
                'order_id'                   => $order->id,
                'requested_by_user_id'       => $user->id,
                'idempotency_key'            => $idempotency,
                'status'                     => RefundRequestStatus::Requested->value,
                'reason'                     => $data['reason'] ?? null,
                'policy_percentage_snapshot' => $policy['percentage'],
                'original_amount_minor'      => $order->total_amount_minor,
                'requested_amount_minor'     => $policy['eligible_amount_minor'],
                'approved_amount_minor'      => null,
                'currency'                   => $order->currency,
                'calculated_at'              => now(),
            ]);

            AuditLog::create([
                'entity_type'   => 'refund_request',
                'entity_id'     => $refundRequest->id,
                'action'        => 'requested',
                'previous_status' => null,
                'new_status'    => RefundRequestStatus::Requested->value,
                'after_state'   => $refundRequest->toArray(),
                'actor_user_id' => $user->id,
                'correlation_id' => $idempotency,
            ]);

            OutboxEvent::create([
                'event_type'       => 'refund_request.created',
                'aggregate_type'   => 'refund_request',
                'aggregate_id'     => $refundRequest->id,
                'payload'          => [
                    'order_id'           => $order->id,
                    'refund_request_id'  => $refundRequest->id,
                    'percentage'         => $policy['percentage'],
                    'eligible_amount'    => $policy['eligible_amount_minor'],
                    'currency'           => $order->currency,
                    'correlation_id'     => $idempotency,
                ],
                'status'           => OutboxEventStatus::Pending->value,
                'publish_attempts' => 0,
                'available_at'     => now(),
            ]);

            return $refundRequest;
        });
    }

    // ─── Admin: approve a refund ─────────────────────────────────────────────

    /**
     * Approve a refund request and dispatch to the payment service.
     *
     * @param  int|null  $overrideAmount  Override approved amount (must be ≤ requested_amount_minor)
     * @throws DomainException if request is not in 'requested' status
     */
    public function approveRefund(User $admin, RefundRequest $refundRequest, ?int $overrideAmount = null): RefundRequest
    {
        if ($refundRequest->status !== RefundRequestStatus::Requested) {
            throw new DomainException("Cannot approve a refund request in status '{$refundRequest->status->value}'.");
        }

        $approvedAmount = $overrideAmount ?? $refundRequest->requested_amount_minor;

        if ($approvedAmount > $refundRequest->requested_amount_minor) {
            throw new DomainException('Approved amount cannot exceed the requested amount.');
        }

        if ($approvedAmount <= 0) {
            throw new DomainException('Approved amount must be greater than zero.');
        }

        return DB::transaction(function () use ($admin, $refundRequest, $approvedAmount) {
            $refundRequest->update([
                'status'               => RefundRequestStatus::Processing->value,
                'approved_amount_minor' => $approvedAmount,
                'reviewed_by_user_id'  => $admin->id,
                'reviewed_at'          => now(),
            ]);

            // Load the payment for this order
            $payment = $refundRequest->order->payments()
                ->where('status', 'succeeded')
                ->latest()
                ->firstOrFail();

            $idempotency = "refund-{$refundRequest->id}";

            // Call payment service to execute refund
            $this->paymentClient->createRefund([
                'idempotencyKey' => $idempotency,
                'paymentId'      => $payment->id,
                'orderId'        => $refundRequest->order_id,
                'provider'       => $payment->provider === 'stripe' ? 'stripe_simulator' : $payment->provider,
                'amountMinor'    => $approvedAmount,
                'currency'       => $refundRequest->currency,
                'reason'         => 'admin_approved',
            ]);

            // Create local Refund record (tracks provider execution)
            Refund::create([
                'refund_request_id' => $refundRequest->id,
                'payment_id'        => $payment->id,
                'status'            => RefundStatus::Pending->value,
                'idempotency_key'   => $idempotency,
                'amount_minor'      => $approvedAmount,
                'currency'          => $refundRequest->currency,
            ]);

            AuditLog::create([
                'entity_type'     => 'refund_request',
                'entity_id'       => $refundRequest->id,
                'action'          => 'approved',
                'previous_status' => RefundRequestStatus::Requested->value,
                'new_status'      => RefundRequestStatus::Processing->value,
                'after_state'     => $refundRequest->fresh()->toArray(),
                'actor_user_id'   => $admin->id,
                'correlation_id'  => (string) Str::uuid(),
            ]);

            return $refundRequest->fresh();
        });
    }

    // ─── Admin: deny a refund ────────────────────────────────────────────────

    /**
     * Deny a refund request.
     */
    public function denyRefund(User $admin, RefundRequest $refundRequest, string $reason): RefundRequest
    {
        if ($refundRequest->status !== RefundRequestStatus::Requested) {
            throw new DomainException("Cannot deny a refund request in status '{$refundRequest->status->value}'.");
        }

        return DB::transaction(function () use ($admin, $refundRequest, $reason) {
            $previous = $refundRequest->status->value;

            $refundRequest->update([
                'status'              => RefundRequestStatus::Denied->value,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at'         => now(),
                'reason'              => $refundRequest->reason
                    ? $refundRequest->reason . "\n[Denial reason: {$reason}]"
                    : "[Denial reason: {$reason}]",
            ]);

            AuditLog::create([
                'entity_type'     => 'refund_request',
                'entity_id'       => $refundRequest->id,
                'action'          => 'denied',
                'previous_status' => $previous,
                'new_status'      => RefundRequestStatus::Denied->value,
                'after_state'     => $refundRequest->fresh()->toArray(),
                'actor_user_id'   => $admin->id,
                'correlation_id'  => (string) Str::uuid(),
            ]);

            return $refundRequest->fresh();
        });
    }

    // ─── Webhook: process payment service callback ───────────────────────────

    /**
     * Process a refund webhook from the payment service.
     *
     * Expected payload:
     *   { refund_id, payment_id, status, amount_minor, currency, provider_reference, event_id }
     *
     * @throws DomainException if the refund record is not found
     */
    public function processWebhook(array $payload, string $eventId): array
    {
        $paymentId = $payload['payment_id'] ?? '';
        $refund = Refund::where('payment_id', $paymentId)
            ->where('status', RefundStatus::Pending->value)
            ->first();

        if (!$refund) {
            // Fallback for idempotency if it was already processed
            $refund = Refund::where('payment_id', $paymentId)->latest()->first();
        }

        if (!$refund) {
            Log::warning('[RefundWebhook] Refund record not found', $payload);
            throw new DomainException('Refund record not found.');
        }

        // Idempotency guard — if already completed or failed, ignore
        if ($refund->status !== RefundStatus::Pending) {
            Log::info('[RefundWebhook] Duplicate webhook ignored', ['refund_id' => $refund->id, 'event_id' => $eventId]);
            return ['status' => $refund->status->value, 'refund_id' => $refund->id];
        }

        $succeeded = ($payload['status'] ?? '') === 'succeeded';

        return DB::transaction(function () use ($refund, $payload, $eventId, $succeeded) {
            $refundRequest = $refund->refundRequest;
            $order         = $refundRequest->order;

            // Update the Refund record
            $refund->update([
                'status'             => $succeeded ? RefundStatus::Completed->value : RefundStatus::Failed->value,
                'provider_reference' => $payload['provider_reference'] ?? null,
                'completed_at'       => $succeeded ? now() : null,
            ]);

            if ($succeeded) {
                // Update RefundRequest → completed
                $refundRequest->update(['status' => RefundRequestStatus::Completed->value]);

                // Update Order status
                $newOrderStatus = $refundRequest->approved_amount_minor >= $order->total_amount_minor
                    ? OrderStatus::Refunded
                    : OrderStatus::PartiallyRefunded;

                $order->update([
                    'status'  => $newOrderStatus->value,
                    'paid_at' => $order->paid_at, // preserve
                ]);

                // Void all tickets for this order
                $order->tickets()->update(['status' => TicketStatus::Voided->value]);

                $this->publishOutbox('refund.completed', 'refund', $refund->id, [
                    'order_id'         => $order->id,
                    'refund_id'        => $refund->id,
                    'amount_minor'     => $refund->amount_minor,
                    'currency'         => $refund->currency,
                    'new_order_status' => $newOrderStatus->value,
                    'event_id_webhook' => $eventId,
                ]);
            } else {
                // Refund failed — mark request as failed
                $refundRequest->update(['status' => RefundRequestStatus::Failed->value]);

                $this->publishOutbox('refund.failed', 'refund', $refund->id, [
                    'order_id'         => $order->id,
                    'refund_id'        => $refund->id,
                    'event_id_webhook' => $eventId,
                ]);
            }

            return [
                'status'       => $refund->fresh()->status->value,
                'refund_id'    => $refund->id,
                'order_status' => $order->fresh()->status->value,
            ];
        });
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

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
