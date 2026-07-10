<?php

use App\Console\Commands\CleanExpiredReservations;
use App\Console\Commands\GenerateDailySalesReport;
use App\Console\Commands\ProcessPayoutBatch;
use App\Console\Commands\PublishOutboxEvents;
use App\Console\Commands\SendEventReminders;
use App\Console\Commands\UpdateEventLifecycle;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\AppServiceProvider::class,
    ])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\DomainException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return \App\Http\Responses\ApiResponse::error($e->getMessage(), 422);
            }
        });

        $exceptions->render(function (\App\Exceptions\ConflictException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return \App\Http\Responses\ApiResponse::error($e->getMessage(), 409);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return \App\Http\Responses\ApiResponse::forbidden($e->getMessage());
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return \App\Http\Responses\ApiResponse::notFound('Resource not found');
            }
        });

        $exceptions->render(function (\RuntimeException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') && $e->getCode() === 503) {
                return \App\Http\Responses\ApiResponse::error($e->getMessage(), 503);
            }
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        // Expire held ticket reservations every 5 minutes
        $schedule->command(CleanExpiredReservations::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/reservation-cleanup.log'));

        // Transition event lifecycle states every 5 minutes
        $schedule->command(UpdateEventLifecycle::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Outbox publisher — polls pending outbox events and publishes to RabbitMQ
        $schedule->command(PublishOutboxEvents::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/outbox-publisher.log'));

        // Event reminders — queue notifications for events starting within 24 hours
        $schedule->command(SendEventReminders::class)
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // Payout batch — daily at 02:00 UTC (calculate previous day sales)
        $schedule->command(ProcessPayoutBatch::class)
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/payout-batch.log'));

        // Daily sales report — aggregate stats and queue admin notification
        $schedule->command(GenerateDailySalesReport::class)
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/daily-sales-report.log'));
    })
    ->create();
