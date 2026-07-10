<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Enums\OutboxEventStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * reports:daily-sales — generates a daily sales report outbox event
 * for admin notification purposes.
 *
 * Scheduled: daily at 01:00 UTC by bootstrap/app.php
 *
 * This is intentionally lightweight — we publish an outbox event with
 * the report data rather than emailing directly, so the notification-service
 * handles delivery with retry semantics.
 */
class GenerateDailySalesReport extends Command
{
    protected $signature   = 'reports:daily-sales {--date= : Date to report on (Y-m-d), defaults to yesterday}';
    protected $description = 'Generate and queue a daily sales summary report.';

    public function handle(): int
    {
        $date = $this->option('date') ?? now()->subDay()->toDateString();

        $this->info("[DailySalesReport] Generating report for {$date}...");

        $stats = DB::selectOne(
            "SELECT
                COUNT(*)                     AS total_orders,
                COALESCE(SUM(total_amount_minor), 0) AS gross_revenue_minor,
                COUNT(CASE WHEN status = ? THEN 1 END) AS paid_orders,
                COUNT(CASE WHEN status = ? THEN 1 END) AS refunded_orders,
                COUNT(CASE WHEN status = ? THEN 1 END) AS failed_orders,
                COUNT(CASE WHEN status = ? THEN 1 END) AS expired_orders
            FROM orders
            WHERE DATE(created_at AT TIME ZONE 'UTC') = ?",
            [
                OrderStatus::Paid->value,
                OrderStatus::Refunded->value,
                OrderStatus::Failed->value,
                OrderStatus::Expired->value,
                $date,
            ]
        );

        $reportPayload = [
            'report_date'          => $date,
            'total_orders'         => (int) ($stats->total_orders ?? 0),
            'paid_orders'          => (int) ($stats->paid_orders ?? 0),
            'refunded_orders'      => (int) ($stats->refunded_orders ?? 0),
            'failed_orders'        => (int) ($stats->failed_orders ?? 0),
            'expired_orders'       => (int) ($stats->expired_orders ?? 0),
            'gross_revenue_minor'  => (int) ($stats->gross_revenue_minor ?? 0),
            'currency'             => 'MYR',
            'generated_at'         => now()->toISOString(),
        ];

        OutboxEvent::create([
            'event_type'       => 'report.daily_sales',
            'aggregate_type'   => 'report',
            'aggregate_id'     => $date,
            'payload'          => $reportPayload,
            'status'           => OutboxEventStatus::Pending->value,
            'publish_attempts' => 0,
            'available_at'     => now(),
        ]);

        $this->info("[DailySalesReport] Report queued — {$reportPayload['paid_orders']} paid orders, gross: {$reportPayload['gross_revenue_minor']} sen.");

        return Command::SUCCESS;
    }
}
