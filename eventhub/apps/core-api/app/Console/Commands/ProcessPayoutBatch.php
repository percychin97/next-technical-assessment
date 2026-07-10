<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Vendor;
use App\Services\Payout\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * payouts:batch — scheduled job to automatically calculate and create
 * pending payouts for vendors for the previous day's sales.
 *
 * Scheduled: daily at 02:00 UTC by bootstrap/app.php
 */
class ProcessPayoutBatch extends Command
{
    protected $signature   = 'payouts:batch {--date= : Date to process (Y-m-d), defaults to yesterday}';
    protected $description = 'Automatically create pending payouts for all vendors with sales in the specified period.';

    public function handle(PayoutService $payoutService): int
    {
        $date = $this->option('date') ?? now()->subDay()->toDateString();
        
        $this->info("[PayoutBatch] Starting batch for period: {$date}");

        // Find all vendors who had paid orders on this date
        $vendorIds = Order::where('status', 'paid')
            ->whereBetween('paid_at', [$date . ' 00:00:00', $date . ' 23:59:59'])
            ->join('ticket_types', 'orders.event_id', '=', 'ticket_types.event_id') // Join events to get vendor
            ->join('events', 'ticket_types.event_id', '=', 'events.id')
            ->pluck('events.vendor_id')
            ->unique()
            ->values()
            ->all();

        if (empty($vendorIds)) {
            $this->info("[PayoutBatch] No sales found for {$date}. Exiting.");
            return Command::SUCCESS;
        }

        $created = 0;
        $failed  = 0;

        foreach ($vendorIds as $vendorId) {
            $idempotencyKey = "batch-payout-{$vendorId}-{$date}";

            try {
                // The payout service calculates the preview and throws if it's below the minimum threshold.
                $payout = $payoutService->createPayout(
                    $vendorId,
                    $date,
                    $date,
                    $idempotencyKey
                );

                $created++;
                Log::info("[PayoutBatch] Created pending payout", ['payout_id' => $payout->id, 'vendor_id' => $vendorId]);
            } catch (\App\Exceptions\DomainException $e) {
                // E.g., below minimum threshold — completely normal, just skip
                Log::info("[PayoutBatch] Skipped payout creation", ['vendor_id' => $vendorId, 'reason' => $e->getMessage()]);
            } catch (\Throwable $e) {
                // Unexpected error
                Log::error("[PayoutBatch] Failed to create payout", ['vendor_id' => $vendorId, 'error' => $e->getMessage()]);
                $failed++;
            }
        }

        $this->info("[PayoutBatch] Done — Created: {$created}, Failed: {$failed}, Skipped (below threshold): " . (count($vendorIds) - $created - $failed));

        return Command::SUCCESS;
    }
}
