<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Models\AuditLog;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves events through their time-driven lifecycle:
 *   published  → ongoing   (when start_at_utc <= now < end_at_utc)
 *   ongoing    → completed (when end_at_utc <= now)
 *
 * Safe to run repeatedly — uses conditional updates that only transition
 * from the expected previous state.
 */
class UpdateEventLifecycle extends Command
{
    protected $signature   = 'events:update-lifecycle';
    protected $description = 'Transition published events to ongoing and ongoing events to completed.';

    public function handle(): int
    {
        $now = now();

        // published → ongoing
        $toOngoing = Event::where('status', EventStatus::Published->value)
            ->where('start_at_utc', '<=', $now)
            ->where('end_at_utc', '>', $now)
            ->get();

        foreach ($toOngoing as $event) {
            DB::transaction(function () use ($event) {
                $event->update(['status' => EventStatus::Ongoing->value]);
                AuditLog::create([
                    'entity_type'     => 'event',
                    'entity_id'       => $event->id,
                    'action'          => 'status_changed',
                    'previous_status' => EventStatus::Published->value,
                    'new_status'      => EventStatus::Ongoing->value,
                    'correlation_id'  => (string) Str::uuid(),
                    'created_at'      => now(),
                ]);
            });
        }

        // ongoing → completed
        $toCompleted = Event::where('status', EventStatus::Ongoing->value)
            ->where('end_at_utc', '<=', $now)
            ->get();

        foreach ($toCompleted as $event) {
            DB::transaction(function () use ($event) {
                $event->update(['status' => EventStatus::Completed->value]);
                AuditLog::create([
                    'entity_type'     => 'event',
                    'entity_id'       => $event->id,
                    'action'          => 'status_changed',
                    'previous_status' => EventStatus::Ongoing->value,
                    'new_status'      => EventStatus::Completed->value,
                    'correlation_id'  => (string) Str::uuid(),
                    'created_at'      => now(),
                ]);
            });
        }

        $this->info(sprintf(
            '[EventLifecycle] %d → ongoing, %d → completed',
            count($toOngoing),
            count($toCompleted)
        ));

        return Command::SUCCESS;
    }
}
