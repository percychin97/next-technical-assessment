<?php

namespace App\Console\Commands;

use App\Services\Notification\OutboxPublisherService;
use Illuminate\Console\Command;

/**
 * outbox:publish — polls pending outbox events and publishes them to RabbitMQ.
 *
 * Scheduled every minute by bootstrap/app.php.
 *
 * Usage:
 *   php artisan outbox:publish           # Process up to 50 events
 *   php artisan outbox:publish --limit=100
 */
class PublishOutboxEvents extends Command
{
    protected $signature   = 'outbox:publish {--limit=50 : Maximum events to process per run}';
    protected $description = 'Publish pending outbox events to the RabbitMQ notification queue.';

    public function handle(OutboxPublisherService $service): int
    {
        $limit = (int) $this->option('limit');

        $this->info("[OutboxPublisher] Processing up to {$limit} pending events...");

        $result = $service->publish($limit);

        $this->info("[OutboxPublisher] Done — published: {$result['published']}, failed: {$result['failed']}");

        return Command::SUCCESS;
    }
}
