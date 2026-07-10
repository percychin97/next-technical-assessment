<?php

namespace App\Services\Notification;

use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OutboxPublisherService — polls pending outbox_events and publishes them to
 * RabbitMQ so the notification-service consumer can process them.
 *
 * Each outbox event is mapped to one or more NotificationJob envelopes,
 * then published to the RabbitMQ notification queue.
 *
 * On success → status = 'published', published_at = now()
 * On failure → status stays 'pending', publish_attempts incremented
 *              After 5 failed attempts → status = 'failed'
 */
class OutboxPublisherService
{
    private const MAX_PUBLISH_ATTEMPTS = 5;

    /**
     * Process up to $limit pending outbox events.
     *
     * @return array{published: int, failed: int}
     */
    public function publish(int $limit = 50): array
    {
        $events = OutboxEvent::where('status', OutboxEventStatus::Pending->value)
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->limit($limit)
            ->get();

        $published = 0;
        $failed    = 0;

        foreach ($events as $event) {
            try {
                $jobs = $this->mapEventToNotificationJobs($event);

                if (empty($jobs)) {
                    // No notification needed — mark as published and continue
                    $this->markPublished($event);
                    $published++;
                    continue;
                }

                foreach ($jobs as $job) {
                    $this->publishToRabbitMq($job);
                }

                $this->markPublished($event);
                $published++;
            } catch (\Throwable $e) {
                Log::error('[OutboxPublisher] Failed to publish event', [
                    'outbox_id'  => $event->id,
                    'event_type' => $event->event_type,
                    'error'      => $e->getMessage(),
                    'attempts'   => $event->publish_attempts + 1,
                ]);

                $this->markFailed($event, $e->getMessage());
                $failed++;
            }
        }

        return compact('published', 'failed');
    }

    // ─── Event → NotificationJob mapping ────────────────────────────────────

    /**
     * Map an outbox event to zero or more NotificationJob payloads.
     *
     * The mapping only covers events that should trigger end-user notifications.
     * Infrastructure events (inventory.released, event.sold_out) produce no jobs.
     *
     * @return array<array<string, mixed>>
     */
    private function mapEventToNotificationJobs(OutboxEvent $event): array
    {
        $p = $event->payload;

        return match ($event->event_type) {
            // ── Order confirmed — email the attendee ──────────────────────────
            'order.paid' => [[
                'integrationEventId' => $event->id,
                'notificationType'   => 'order_confirmation',
                'channel'            => 'email',
                'recipientReference' => $p['user_id'] ?? '',
                'destination'        => $p['user_email'] ?? '',
                'payload'            => [
                    'orderNumber' => $p['order_number'] ?? '',
                    'ticketCount' => $p['ticket_count'] ?? 0,
                    'eventTitle'  => $p['event_title'] ?? '',
                    'totalAmount' => $p['total_amount_minor'] ?? 0,
                    'currency'    => $p['currency'] ?? 'MYR',
                ],
            ]],

            // ── Refund completed — email the attendee ─────────────────────────
            'refund.completed' => [[
                'integrationEventId' => $event->id,
                'notificationType'   => 'refund_processed',
                'channel'            => 'email',
                'recipientReference' => $p['user_id'] ?? '',
                'destination'        => $p['user_email'] ?? '',
                'payload'            => [
                    'orderNumber' => $p['order_number'] ?? '',
                    'amount'      => $p['amount_minor'] ?? 0,
                    'currency'    => $p['currency'] ?? 'MYR',
                ],
            ]],

            // ── Payout completed — email + vendor webhook ─────────────────────
            'payout.completed' => $this->buildPayoutJobs($event->id, $p),

            // ── Vendor approved ───────────────────────────────────────────────
            'vendor.approved' => [[
                'integrationEventId' => $event->id,
                'notificationType'   => 'vendor_approved',
                'channel'            => 'email',
                'recipientReference' => $p['vendor_id'] ?? '',
                'destination'        => $p['vendor_email'] ?? '',
                'payload'            => [],
            ]],

            // ── Vendor rejected ───────────────────────────────────────────────
            'vendor.rejected' => [[
                'integrationEventId' => $event->id,
                'notificationType'   => 'vendor_rejected',
                'channel'            => 'email',
                'recipientReference' => $p['vendor_id'] ?? '',
                'destination'        => $p['vendor_email'] ?? '',
                'payload'            => [
                    'reason' => $p['reason'] ?? '',
                ],
            ]],

            // ── Infrastructure / other events → no notification ───────────────
            default => [],
        };
    }

    /**
     * Build notification jobs for a completed payout.
     * Emits: (1) email to vendor + (2) vendor webhook if registered.
     */
    private function buildPayoutJobs(string $integrationEventId, array $p): array
    {
        $jobs = [];

        // Always send email
        if (!empty($p['vendor_email'])) {
            $jobs[] = [
                'integrationEventId' => $integrationEventId,
                'notificationType'   => 'payout_completed',
                'channel'            => 'email',
                'recipientReference' => $p['vendor_id'] ?? '',
                'destination'        => $p['vendor_email'] ?? '',
                'payload'            => [
                    'netAmount'   => $p['net_amount_minor'] ?? 0,
                    'currency'    => $p['currency'] ?? 'MYR',
                    'periodStart' => $p['period_start'] ?? '',
                    'periodEnd'   => $p['period_end'] ?? '',
                ],
            ];
        }

        // Optionally deliver vendor webhook
        if (!empty($p['vendor_webhook_url'])) {
            $jobs[] = [
                'integrationEventId' => $integrationEventId . '-wh',
                'notificationType'   => 'payout_completed',
                'channel'            => 'vendor_webhook',
                'recipientReference' => $p['vendor_id'] ?? '',
                'destination'        => $p['vendor_webhook_url'],
                'vendorWebhookSecret' => $p['vendor_webhook_secret'] ?? '',
                'payload'            => [
                    'vendor_id'        => $p['vendor_id'] ?? '',
                    'net_amount_minor' => $p['net_amount_minor'] ?? 0,
                    'currency'         => $p['currency'] ?? 'MYR',
                    'period_start'     => $p['period_start'] ?? '',
                    'period_end'       => $p['period_end'] ?? '',
                    'payout_id'        => $p['payout_id'] ?? '',
                ],
            ];
        }

        return $jobs;
    }

    // ─── RabbitMQ publishing ─────────────────────────────────────────────────

    /**
     * Publish a single NotificationJob to RabbitMQ.
     *
     * Uses PHP's stream-socket approach via php-amqplib *only if available*.
     * Falls back to a logged stub when RABBITMQ_HOST is not configured (e.g. tests).
     */
    private function publishToRabbitMq(array $job): void
    {
        $host = config('rabbitmq.host', env('RABBITMQ_HOST', ''));
        $port = (int) config('rabbitmq.port', env('RABBITMQ_PORT', 5672));
        $user = config('rabbitmq.user', env('RABBITMQ_USER', 'eventhub'));
        $pass = config('rabbitmq.password', env('RABBITMQ_PASSWORD', 'eventhub_password'));
        $queue = env('NOTIFICATION_QUEUE', 'eventhub.notifications');

        if (empty($host) || app()->environment('testing') || env('APP_ENV') === 'testing' || config('app.env') === 'testing' || app()->runningUnitTests()) {
            // Test / development stub: log the job
            Log::info('[OutboxPublisher] (stub) Would publish to RabbitMQ', [
                'queue'               => $queue,
                'integrationEventId'  => $job['integrationEventId'],
                'notificationType'    => $job['notificationType'],
                'channel'             => $job['channel'],
                'destination'         => $job['destination'],
            ]);
            return;
        }

        // Build the connection using raw socket (php-amqplib)
        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection($host, $port, $user, $pass);
        $channel    = $connection->channel();

        $args = new \PhpAmqpLib\Wire\AMQPTable([
            'x-dead-letter-exchange' => 'eventhub.dlx',
            'x-dead-letter-routing-key' => 'dead_letter'
        ]);
        $channel->queue_declare($queue, false, true, false, false, false, $args);

        $message = new \PhpAmqpLib\Message\AMQPMessage(
            json_encode($job),
            ['delivery_mode' => \PhpAmqpLib\Message\AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        $channel->basic_publish($message, '', $queue);
        $channel->close();
        $connection->close();

        Log::debug('[OutboxPublisher] Published to RabbitMQ', [
            'queue'              => $queue,
            'integrationEventId' => $job['integrationEventId'],
            'notificationType'   => $job['notificationType'],
        ]);
    }

    // ─── Status helpers ──────────────────────────────────────────────────────

    private function markPublished(OutboxEvent $event): void
    {
        $event->update([
            'status'       => OutboxEventStatus::Published->value,
            'published_at' => now(),
        ]);
    }

    private function markFailed(OutboxEvent $event, string $error): void
    {
        $attempts   = $event->publish_attempts + 1;
        $newStatus  = $attempts >= self::MAX_PUBLISH_ATTEMPTS
            ? OutboxEventStatus::Failed->value
            : OutboxEventStatus::Pending->value;

        $event->update([
            'publish_attempts' => $attempts,
            'status'           => $newStatus,
        ]);
    }
}
