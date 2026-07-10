<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\TicketInventoryPool;
use App\Models\TicketReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles the "expired reservation cleanup" scheduled job (every 5 minutes).
 *
 * Implements the algorithm from architecture section 8.2:
 *   - For each expired held reservation, lock order + reservations + pools
 *   - Decrement reserved_units, mark reservation expired, mark order expired
 *   - Write audit and inventory.released outbox event
 *   - Each order is processed in an independent transaction (one failure
 *     doesn't stop the rest)
 */
class ReservationCleanupService
{
    /**
     * Process all expired held reservations.
     *
     * @return int Number of orders expired
     */
    public function cleanExpired(): int
    {
        $count = 0;

        // Find orders that have expired reservations still held
        $expiredOrderIds = TicketReservation::where('status', ReservationStatus::Held->value)
            ->where('expires_at', '<=', now())
            ->pluck('order_id')
            ->unique()
            ->all();

        foreach ($expiredOrderIds as $orderId) {
            try {
                $released = $this->processExpiredOrder($orderId);
                if ($released) {
                    $count++;
                }
            } catch (\Throwable $e) {
                // One failed order must not stop the rest
                Log::error('[ReservationCleanup] Failed to expire order', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        if ($count > 0) {
            Log::info("[ReservationCleanup] Expired {$count} orders");
        }

        return $count;
    }

    private function processExpiredOrder(string $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            // Lock the order first
            $order = Order::where('id', $orderId)->lockForUpdate()->first();

            if (!$order) {
                return false;
            }

            // Re-check status after lock — another process may have already handled it
            if ($order->status !== OrderStatus::AwaitingPayment) {
                return false;
            }

            // Lock the held reservations
            $reservations = TicketReservation::where('order_id', $orderId)
                ->where('status', ReservationStatus::Held->value)
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                return false;
            }

            $correlationId = (string) Str::uuid();

            // Lock inventory pools in sorted order (deadlock prevention)
            $poolIds = $reservations->pluck('inventory_pool_id')->unique()->sort()->values()->all();
            $pools   = TicketInventoryPool::whereIn('id', $poolIds)
                ->orderByRaw('id::text')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Release each reservation and decrement the pool counter
            foreach ($reservations as $reservation) {
                $pool = $pools->get($reservation->inventory_pool_id);
                if ($pool) {
                    $pool->decrement('reserved_units', $reservation->reserved_units);
                }

                $reservation->update([
                    'status'      => ReservationStatus::Expired->value,
                    'released_at' => now(),
                ]);
            }

            // Mark the order expired
            $order->update(['status' => OrderStatus::Expired->value]);

            // Audit record
            AuditLog::create([
                'entity_type'     => 'order',
                'entity_id'       => $order->id,
                'action'          => 'status_changed',
                'previous_status' => OrderStatus::AwaitingPayment->value,
                'new_status'      => OrderStatus::Expired->value,
                'correlation_id'  => $correlationId,
                'created_at'      => now(),
            ]);

            // Outbox — inventory.released triggers waitlist processing (Step 12)
            OutboxEvent::create([
                'event_type'       => 'inventory.released',
                'aggregate_type'   => 'order',
                'aggregate_id'     => $order->id,
                'payload'          => [
                    'order_id'       => $order->id,
                    'event_id'       => $order->event_id,
                    'pool_ids'       => $poolIds,
                    'correlation_id' => $correlationId,
                    'reason'         => 'reservation_expired',
                ],
                'status'           => OutboxEventStatus::Pending->value,
                'publish_attempts' => 0,
                'available_at'     => now(),
            ]);

            Log::info('[ReservationCleanup] Order expired', [
                'order_id'        => $order->id,
                'released_pools'  => $poolIds,
            ]);

            return true;
        });
    }
}
