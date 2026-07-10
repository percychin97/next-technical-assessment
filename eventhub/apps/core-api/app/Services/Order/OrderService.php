<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\DomainException;
use App\Exceptions\InsufficientInventoryException;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Ticket;
use App\Models\TicketInventoryPool;
use App\Models\TicketReservation;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Payment\PaymentGatewayClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(private readonly PaymentGatewayClient $paymentClient) {}

    // ─── 1. Reserve Tickets ──────────────────────────────────────────────────────

    /**
     * Reserve tickets for an order.
     *
     * Implements the 13-step reservation algorithm from section 5.1 of the spec:
     * sorts pool IDs, acquires FOR UPDATE locks, re-reads availability,
     * creates order/items/reservations, increments counters, writes audit+outbox.
     *
     * @param  User   $user    Authenticated attendee
     * @param  array  $data    Validated request data
     *   - event_id: string
     *   - items: array<{ticket_type_id: string, quantity: int}>
     *   - idempotency_key: string
     * @return Order
     *
     * @throws ConflictException if idempotency key already used for a different user
     * @throws InsufficientInventoryException if any pool is sold out
     */
    public function reserve(User $user, array $data): Order
    {
        $idempotencyKey = $data['idempotency_key'];

        // Idempotency check — return existing order if same key, same user
        $existing = Order::where('creation_idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->user_id !== $user->id) {
                throw new ConflictException('Idempotency key belongs to a different user.');
            }
            return $existing->load(['items', 'reservations']);
        }

        $event = \App\Models\Event::findOrFail($data['event_id']);

        if (!$event->isPublished()) {
            throw new DomainException('Event is not accepting reservations.');
        }

        $requestedItems = collect($data['items']); // [{ticket_type_id, quantity}]

        // Load and validate ticket types
        $ticketTypeIds = $requestedItems->pluck('ticket_type_id')->all();
        $ticketTypes   = TicketType::whereIn('id', $ticketTypeIds)
            ->where('event_id', $event->id)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($ticketTypeIds as $ttId) {
            if (!$ticketTypes->has($ttId)) {
                throw new DomainException("Ticket type {$ttId} is not available for this event.");
            }
        }

        // Check sale windows
        $now = now();
        foreach ($ticketTypes as $tt) {
            if ($tt->sale_start_at_utc && $now->lt($tt->sale_start_at_utc)) {
                throw new DomainException("Ticket type {$tt->code} sales have not started yet.");
            }
            if ($tt->sale_end_at_utc && $now->gt($tt->sale_end_at_utc)) {
                throw new DomainException("Ticket type {$tt->code} sales have ended.");
            }
        }

        // Map: pool_id → total admission units required
        /** @var array<string, int> $poolRequirements */
        $poolRequirements = [];
        /** @var array<string, array{ticket_type: TicketType, quantity: int, admission_units: int}> $lineItems */
        $lineItems = [];

        foreach ($requestedItems as $item) {
            $tt       = $ticketTypes[$item['ticket_type_id']];
            $poolId   = $tt->inventory_pool_id;
            $quantity = (int) $item['quantity'];

            if ($quantity < 1) {
                throw new DomainException('Quantity must be at least 1.');
            }

            $admissionUnits = $quantity * $tt->admission_units_per_purchase;

            $poolRequirements[$poolId] = ($poolRequirements[$poolId] ?? 0) + $admissionUnits;
            $lineItems[] = compact('tt', 'quantity', 'admissionUnits', 'poolId');
        }

        // Sort pool IDs deterministically to prevent deadlocks
        ksort($poolRequirements);
        $poolIds = array_keys($poolRequirements);

        return DB::transaction(function () use (
            $user, $event, $lineItems, $poolIds, $poolRequirements, $idempotencyKey, $ticketTypes
        ) {
            // Lock inventory pools in sorted order (deadlock prevention)
            /** @var Collection<TicketInventoryPool> $pools */
            $pools = TicketInventoryPool::whereIn('id', $poolIds)
                ->orderByRaw('id::text')  // deterministic sort on UUID text
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Re-read availability after lock and validate
            foreach ($poolRequirements as $poolId => $required) {
                $pool = $pools->get($poolId);
                if (!$pool) {
                    throw new DomainException("Inventory pool {$poolId} not found.");
                }
                $available = $pool->availableUnits();
                if ($available < $required) {
                    throw new InsufficientInventoryException(
                        "Insufficient inventory for pool {$pool->name}: {$available} units available, {$required} required."
                    );
                }
            }

            // Calculate order totals
            $subtotal = 0;
            foreach ($lineItems as $line) {
                $subtotal += $line['tt']->price_minor * $line['quantity'];
            }

            $holdExpiresAt = now()->addMinutes(15);
            $correlationId = (string) Str::uuid();
            $currency      = $lineItems[0]['tt']->currency ?? 'MYR';

            // Create order
            $order = Order::create([
                'user_id'                   => $user->id,
                'event_id'                  => $event->id,
                'order_number'              => $this->generateOrderNumber(),
                'creation_idempotency_key'  => $idempotencyKey,
                'status'                    => OrderStatus::AwaitingPayment->value,
                'subtotal_minor'            => $subtotal,
                'total_amount_minor'        => $subtotal,
                'currency'                  => $currency,
                'hold_expires_at'           => $holdExpiresAt,
            ]);

            // Create order items and reservations
            foreach ($lineItems as $line) {
                $tt = $line['tt'];

                OrderItem::create([
                    'order_id'                             => $order->id,
                    'ticket_type_id'                       => $tt->id,
                    'ticket_type_name_snapshot'            => $tt->name,
                    'purchase_quantity'                    => $line['quantity'],
                    'admission_units_per_purchase_snapshot' => $tt->admission_units_per_purchase,
                    'admission_quantity'                   => $line['admissionUnits'],
                    'unit_price_minor_snapshot'            => $tt->price_minor,
                    'subtotal_minor'                       => $tt->price_minor * $line['quantity'],
                    'currency'                             => $tt->currency,
                ]);

                TicketReservation::create([
                    'order_id'          => $order->id,
                    'ticket_type_id'    => $tt->id,
                    'inventory_pool_id' => $line['poolId'],
                    'purchase_quantity' => $line['quantity'],
                    'reserved_units'    => $line['admissionUnits'],
                    'status'            => ReservationStatus::Held->value,
                    'expires_at'        => $holdExpiresAt,
                ]);

                // Increment pool counter
                $pools[$line['poolId']]->increment('reserved_units', $line['admissionUnits']);
            }

            // Audit record
            AuditLog::create([
                'entity_type'   => 'order',
                'entity_id'     => $order->id,
                'action'        => 'created',
                'new_status'    => OrderStatus::AwaitingPayment->value,
                'actor_user_id' => $user->id,
                'correlation_id' => $correlationId,
                'created_at'    => now(),
            ]);

            // Outbox event for notifications (consumed by Step 12)
            OutboxEvent::create([
                'event_type'     => 'order.awaiting_payment',
                'aggregate_type' => 'order',
                'aggregate_id'   => $order->id,
                'payload'        => [
                    'order_id'       => $order->id,
                    'user_id'        => $user->id,
                    'event_id'       => $event->id,
                    'total_minor'    => $subtotal,
                    'currency'       => $currency,
                    'hold_expires_at' => $holdExpiresAt->toISOString(),
                    'correlation_id' => $correlationId,
                ],
                'status'         => OutboxEventStatus::Pending->value,
                'publish_attempts' => 0,
                'available_at'   => now(),
            ]);

            Log::info('[OrderService] Order reserved', [
                'order_id'   => $order->id,
                'user_id'    => $user->id,
                'event_id'   => $event->id,
                'total'      => $subtotal,
                'expires_at' => $holdExpiresAt->toISOString(),
            ]);

            return $order->load(['items', 'reservations']);
        });
    }

    // ─── 2. Initiate Payment ─────────────────────────────────────────────────────

    /**
     * Create a local Payment record and call the payment service.
     *
     * The payment service returns immediately with `pending`; the final result
     * arrives via a signed webhook callback.
     */
    public function initiatePayment(
        Order  $order,
        User   $user,
        string $provider,
        string $idempotencyKey
    ): Payment {
        if ($order->user_id !== $user->id) {
            throw new DomainException('You do not own this order.');
        }

        if ($order->status !== OrderStatus::AwaitingPayment) {
            throw new ConflictException(
                "Order cannot accept payment in status: {$order->status->value}"
            );
        }

        if (!$order->isReservationHeld()) {
            throw new ConflictException('The ticket reservation has expired.');
        }

        // Idempotency — return existing payment for same key
        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        // Create local payment record
        $payment = Payment::create([
            'order_id'        => $order->id,
            'provider'        => $provider,
            'status'          => PaymentStatus::Pending->value,
            'idempotency_key' => $idempotencyKey,
            'amount_minor'    => $order->total_amount_minor,
            'currency'        => $order->currency,
        ]);

        // Call the payment service (fire-and-forget; result comes via webhook)
        $this->paymentClient->createPayment([
            'idempotencyKey' => $idempotencyKey,
            'orderId'        => $order->id,
            'amountMinor'    => $order->total_amount_minor,
            'currency'       => $order->currency,
            'provider'       => $provider === 'stripe' ? 'stripe_simulator' : $provider,
            'callbackUrl'    => config('services.payment.callback_url_payments'),
        ]);

        Log::info('[OrderService] Payment initiated', [
            'payment_id' => $payment->id,
            'order_id'   => $order->id,
            'provider'   => $provider,
        ]);

        return $payment;
    }

    // ─── 3. Handle Payment Webhook ───────────────────────────────────────────────

    /**
     * Process an inbound payment webhook from the payment service.
     *
     * Routes to success, failure, or late-payment handlers based on payload.
     */
    public function processPaymentWebhook(array $payload, string $eventId): array
    {
        $paymentId = $payload['payment_id'] ?? null;
        $status    = $payload['status']     ?? null;

        if (!$paymentId || !$status) {
            throw new DomainException('Invalid payment webhook payload.');
        }

        return DB::transaction(function () use ($payload, $eventId, $paymentId, $status) {
            $orderId = $payload['order_id'] ?? null;

            // Idempotency — check if this webhook event was already processed
            if (PaymentAttempt::where('provider_event_id', $eventId)->exists()) {
                Log::info('[Webhook] Duplicate payment event, skipping', ['event_id' => $eventId]);
                $order   = Order::findOrFail($orderId);
                return ['order_id' => $order->id, 'status' => $order->status->value, 'duplicate' => true];
            }

            // Lock the order, then find its latest pending payment
            $order   = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            $payment = Payment::where('order_id', $orderId)->lockForUpdate()->firstOrFail();

            // Record the attempt (before business logic so we always capture it)
            PaymentAttempt::create([
                'payment_id'        => $payment->id,
                'provider_event_id' => $eventId,
                'status'            => $status,
                'attempt_number'    => 1,
                'response_payload'  => $payload,
                'attempted_at'      => now(),
            ]);

            if ($status === 'succeeded') {
                return $this->applyPaymentSuccess($payment, $order, $payload, $eventId);
            }

            if ($status === 'failed') {
                return $this->applyPaymentFailure($payment, $order, $payload, $eventId);
            }

            throw new DomainException("Unknown payment webhook status: {$status}");
        });
    }

    // ─── Internal: Payment Success ───────────────────────────────────────────────

    private function applyPaymentSuccess(Payment $payment, Order $order, array $payload, string $eventId): array
    {
        $correlationId = (string) Str::uuid();

        // Amount/currency integrity check
        if ($payment->amount_minor !== (int) $payload['amount_minor']) {
            Log::error('[Webhook] Payment amount mismatch', [
                'expected' => $payment->amount_minor,
                'received' => $payload['amount_minor'],
                'event_id' => $eventId,
            ]);
            throw new DomainException('Payment amount mismatch. Manual review required.');
        }

        // ── Late payment path ──────────────────────────────────────────────────
        $reservations = TicketReservation::where('order_id', $order->id)
            ->lockForUpdate()
            ->get();

        $hasHeldReservation = $reservations->contains(
            fn ($r) => $r->status === ReservationStatus::Held->value || $r->status === ReservationStatus::Held
        );

        if (!$hasHeldReservation || $order->status !== OrderStatus::AwaitingPayment) {
            return $this->applyLatePayment($payment, $order, $payload, $correlationId);
        }

        // ── Normal success path ────────────────────────────────────────────────
        // Lock pools in sorted order
        $poolIds = $reservations->pluck('inventory_pool_id')->unique()->sort()->values()->all();
        $pools   = TicketInventoryPool::whereIn('id', $poolIds)
            ->orderByRaw('id::text')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // Update payment
        $payment->update([
            'status'             => PaymentStatus::Succeeded->value,
            'provider_reference' => $payload['provider_reference'] ?? null,
            'succeeded_at'       => now(),
        ]);

        // Update order
        $order->update([
            'status'  => OrderStatus::Paid->value,
            'paid_at' => now(),
        ]);

        // Confirm reservations and update inventory counters
        foreach ($reservations as $reservation) {
            if ($reservation->status === ReservationStatus::Held->value || $reservation->status === ReservationStatus::Held) {
                $pool = $pools->get($reservation->inventory_pool_id);
                $pool->decrement('reserved_units', $reservation->reserved_units);
                $pool->increment('sold_units', $reservation->reserved_units);

                $reservation->update([
                    'status'       => ReservationStatus::Confirmed->value,
                    'confirmed_at' => now(),
                ]);
            }
        }

        // Issue tickets — one per admission unit
        $orderItems = $order->items()->get();
        foreach ($orderItems as $item) {
            $admissionQuantity = $item->admission_quantity;
            for ($i = 0; $i < $admissionQuantity; $i++) {
                Ticket::create([
                    'order_id'      => $order->id,
                    'order_item_id' => $item->id,
                    'ticket_type_id' => $item->ticket_type_id,
                    'ticket_number' => $this->generateTicketNumber(),
                    'qr_token_hash' => hash('sha256', Str::random(64)),
                    'status'        => TicketStatus::Valid->value,
                ]);
            }
        }

        // Audit
        AuditLog::create([
            'entity_type'     => 'order',
            'entity_id'       => $order->id,
            'action'          => 'status_changed',
            'previous_status' => OrderStatus::AwaitingPayment->value,
            'new_status'      => OrderStatus::Paid->value,
            'correlation_id'  => $correlationId,
            'created_at'      => now(),
        ]);

        // Outbox — order.paid triggers attendee confirmation + vendor webhook
        OutboxEvent::create([
            'event_type'       => 'order.paid',
            'aggregate_type'   => 'order',
            'aggregate_id'     => $order->id,
            'payload'          => [
                'order_id'      => $order->id,
                'user_id'       => $order->user_id,
                'event_id'      => $order->event_id,
                'total_minor'   => $payment->amount_minor,
                'currency'      => $payment->currency,
                'correlation_id' => $correlationId,
            ],
            'status'           => OutboxEventStatus::Pending->value,
            'publish_attempts' => 0,
            'available_at'     => now(),
        ]);

        Log::info('[OrderService] Payment succeeded, order paid', [
            'order_id'   => $order->id,
            'payment_id' => $payment->id,
            'event_id_wh' => $eventId,
        ]);

        // Check sold-out and write outbox if needed
        $this->checkAndPublishSoldOut($pools, $order->event_id, $correlationId);

        return ['order_id' => $order->id, 'status' => OrderStatus::Paid->value];
    }

    // ─── Internal: Payment Failure ───────────────────────────────────────────────

    private function applyPaymentFailure(Payment $payment, Order $order, array $payload, string $eventId): array
    {
        $correlationId = (string) Str::uuid();

        $payment->update([
            'status'    => PaymentStatus::Failed->value,
            'failed_at' => now(),
        ]);

        // Release reservations if still held
        if ($order->status === OrderStatus::AwaitingPayment) {
            $this->releaseReservations($order, $correlationId);
            $order->update(['status' => OrderStatus::Failed->value]);

            AuditLog::create([
                'entity_type'     => 'order',
                'entity_id'       => $order->id,
                'action'          => 'status_changed',
                'previous_status' => OrderStatus::AwaitingPayment->value,
                'new_status'      => OrderStatus::Failed->value,
                'correlation_id'  => $correlationId,
                'created_at'      => now(),
            ]);
        }

        Log::info('[OrderService] Payment failed', [
            'order_id'    => $order->id,
            'payment_id'  => $payment->id,
            'event_id_wh' => $eventId,
        ]);

        return ['order_id' => $order->id, 'status' => $order->status->value];
    }

    // ─── Internal: Late Payment ──────────────────────────────────────────────────

    /**
     * A payment succeeded after the reservation expired.
     * Spec section 5.4: do NOT issue tickets, move order to payment_review,
     * create a compensating refund request, queue refund execution.
     */
    private function applyLatePayment(Payment $payment, Order $order, array $payload, string $correlationId): array
    {
        $payment->update([
            'status'             => PaymentStatus::Succeeded->value,
            'provider_reference' => $payload['provider_reference'] ?? null,
            'succeeded_at'       => now(),
        ]);

        $order->update([
            'status'                => OrderStatus::PaymentReview->value,
            'payment_review_reason' => 'Payment received after reservation expiry.',
        ]);

        AuditLog::create([
            'entity_type'     => 'order',
            'entity_id'       => $order->id,
            'action'          => 'late_payment_received',
            'previous_status' => $order->getOriginal('status'),
            'new_status'      => OrderStatus::PaymentReview->value,
            'correlation_id'  => $correlationId,
            'created_at'      => now(),
        ]);

        // Outbox — will trigger compensating refund workflow in Step 11
        OutboxEvent::create([
            'event_type'       => 'order.late_payment',
            'aggregate_type'   => 'order',
            'aggregate_id'     => $order->id,
            'payload'          => [
                'order_id'       => $order->id,
                'payment_id'     => $payment->id,
                'user_id'        => $order->user_id,
                'amount_minor'   => $payment->amount_minor,
                'currency'       => $payment->currency,
                'correlation_id' => $correlationId,
            ],
            'status'           => OutboxEventStatus::Pending->value,
            'publish_attempts' => 0,
            'available_at'     => now(),
        ]);

        Log::warning('[OrderService] Late payment received', [
            'order_id'   => $order->id,
            'payment_id' => $payment->id,
        ]);

        return [
            'order_id'      => $order->id,
            'status'        => OrderStatus::PaymentReview->value,
            'refund_status' => 'processing',
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

    private function releaseReservations(Order $order, string $correlationId): void
    {
        $reservations = TicketReservation::where('order_id', $order->id)
            ->where('status', ReservationStatus::Held->value)
            ->lockForUpdate()
            ->get();

        if ($reservations->isEmpty()) {
            return;
        }

        $poolIds = $reservations->pluck('inventory_pool_id')->unique()->sort()->values()->all();
        $pools   = TicketInventoryPool::whereIn('id', $poolIds)
            ->orderByRaw('id::text')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($reservations as $reservation) {
            $pool = $pools->get($reservation->inventory_pool_id);
            $pool->decrement('reserved_units', $reservation->reserved_units);
            $reservation->update(['status' => ReservationStatus::Released->value, 'released_at' => now()]);
        }

        OutboxEvent::create([
            'event_type'       => 'inventory.released',
            'aggregate_type'   => 'order',
            'aggregate_id'     => $order->id,
            'payload'          => [
                'order_id'       => $order->id,
                'event_id'       => $order->event_id,
                'pool_ids'       => $poolIds,
                'correlation_id' => $correlationId,
            ],
            'status'           => OutboxEventStatus::Pending->value,
            'publish_attempts' => 0,
            'available_at'     => now(),
        ]);
    }

    private function checkAndPublishSoldOut(Collection $pools, string $eventId, string $correlationId): void
    {
        $soldOutPools = $pools->filter(fn ($p) => $p->availableUnits() <= 0);
        if ($soldOutPools->isNotEmpty()) {
            OutboxEvent::create([
                'event_type'       => 'event.sold_out',
                'aggregate_type'   => 'event',
                'aggregate_id'     => $eventId,
                'payload'          => [
                    'event_id'       => $eventId,
                    'pool_ids'       => $soldOutPools->keys()->all(),
                    'correlation_id' => $correlationId,
                ],
                'status'           => OutboxEventStatus::Pending->value,
                'publish_attempts' => 0,
                'available_at'     => now(),
            ]);
        }
    }

    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $seq  = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        return "EH-{$date}-{$seq}";
    }

    private function generateTicketNumber(): string
    {
        $year = now()->format('Y');
        $seq  = str_pad((string) random_int(1, 9999999), 7, '0', STR_PAD_LEFT);
        return "TKT-{$year}-{$seq}";
    }
}
