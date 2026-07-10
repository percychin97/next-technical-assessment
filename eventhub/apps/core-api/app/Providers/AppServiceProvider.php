<?php

namespace App\Providers;

use App\Models\Event;
use App\Models\Order;
use App\Policies\EventPolicy;
use App\Policies\OrderPolicy;
use App\Repositories\Contracts\EventRepositoryInterface;
use App\Repositories\Eloquent\EloquentEventRepository;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    protected $policies = [
        Event::class => EventPolicy::class,
        Order::class => OrderPolicy::class,
    ];

    public function register(): void
    {
        // Repository bindings
        $this->app->bind(EventRepositoryInterface::class, EloquentEventRepository::class);

        // Services are resolved automatically by the container via constructor injection
        // but we can add explicit singletons here if needed for performance
    }

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
