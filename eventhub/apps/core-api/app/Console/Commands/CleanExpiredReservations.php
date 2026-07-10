<?php

namespace App\Console\Commands;

use App\Services\Order\ReservationCleanupService;
use Illuminate\Console\Command;

class CleanExpiredReservations extends Command
{
    protected $signature   = 'reservations:cleanup';
    protected $description = 'Expire held ticket reservations past their hold time and release inventory.';

    public function handle(ReservationCleanupService $service): int
    {
        $this->info('[ReservationCleanup] Starting...');

        $count = $service->cleanExpired();

        $this->info("[ReservationCleanup] Done — {$count} order(s) expired.");

        return Command::SUCCESS;
    }
}
