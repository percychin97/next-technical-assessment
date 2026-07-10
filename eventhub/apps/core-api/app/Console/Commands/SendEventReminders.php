<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Enums\OutboxEventStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * events:reminders — queue event reminder notifications for events starting
 * within 24 hours that have not yet been reminded.
 *
 * Scheduled: hourly by bootstrap/app.php
 */
class SendEventReminders extends Command
{
    protected $signature   = 'events:reminders';
    protected $description = 'Queue event reminder notifications for events starting within 24 hours.';

    public function handle(): int
    {
        $this->info('[EventReminders] Checking for upcoming events...');

        $events = Event::where('status', EventStatus::Published->value)
            ->whereBetween('start_at_utc', [now(), now()->addHours(24)])
            ->where('reminder_sent_at', null) // Only send once
            ->get();

        $count = 0;

        foreach ($events as $event) {
            try {
                DB::transaction(function () use ($event) {
                    // Get all paid orders for this event
                    $orders = Order::where('event_id', $event->id)
                        ->where('status', 'paid')
                        ->with('user:id,email,name')
                        ->get();

                    foreach ($orders as $order) {
                        OutboxEvent::create([
                            'event_type'       => 'event.reminder',
                            'aggregate_type'   => 'event',
                            'aggregate_id'     => $event->id,
                            'payload'          => [
                                'user_id'    => $order->user_id,
                                'user_email' => $order->user?->email ?? '',
                                'order_id'   => $order->id,
                                'event_id'   => $event->id,
                                'eventTitle' => $event->title,
                                'startAt'    => $event->start_at_utc->toISOString(),
                            ],
                            'status'           => OutboxEventStatus::Pending->value,
                            'publish_attempts' => 0,
                            'available_at'     => now(),
                        ]);
                    }

                    // Mark reminder sent to prevent duplicate notifications
                    $event->update(['reminder_sent_at' => now()]);
                });

                $count++;
            } catch (\Throwable $e) {
                Log::error('[EventReminders] Failed to queue reminder', [
                    'event_id' => $event->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->info("[EventReminders] Queued reminders for {$count} event(s).");

        return Command::SUCCESS;
    }
}
