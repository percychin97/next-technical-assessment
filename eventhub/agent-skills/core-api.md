# Core API Agent Skill

> Service: `apps/core-api/` — Laravel 11, PHP 8.2+, PostgreSQL 16

---

## What this service owns

- Users and roles (admin, vendor, attendee)
- Vendor KYC onboarding and approval
- Events (full lifecycle: draft → published → ongoing → completed / cancelled)
- Ticket types, inventory pools, and admission unit tracking
- Orders and ticket reservations (15-minute hold, atomic inventory locking)
- Payment business records and state transitions (initiated, paid, failed)
- Refund policy calculation, request management, and approval
- Payout calculation, approval, and completion
- Audit logs for every financial state transition
- Outbox events for reliable notification publishing
- Scheduled jobs (reservation cleanup, lifecycle updates, outbox publish, reminders, payout batch)
- Admin endpoints and platform analytics

## What this service does NOT own

- Payment provider execution → belongs to `payment-service`
- Notification delivery → belongs to `notification-service`
- RabbitMQ consumer logic
- Any direct publish to RabbitMQ inside a DB transaction

---

## Directory Structure

```
app/
├── Console/Commands/         ← Artisan commands run by the scheduler
│   ├── CleanExpiredReservations.php
│   ├── UpdateEventLifecycle.php
│   ├── PublishOutboxEvents.php      ← Outbox → RabbitMQ bridge (Step 12)
│   ├── SendEventReminders.php
│   ├── ProcessPayoutBatch.php
│   └── GenerateDailySalesReport.php
├── Enums/
│   ├── OrderEnums.php        ← OrderStatus, PayoutStatus, OutboxEventStatus …
│   ├── EventStatus.php
│   └── UserRole.php
├── Exceptions/
│   └── DomainExceptions.php  ← DomainException (422), ConflictException (409) …
├── Http/
│   ├── Controllers/Api/V1/   ← Thin controllers: validate → service → respond
│   ├── Middleware/
│   │   └── RoleMiddleware.php
│   ├── Requests/             ← One FormRequest per action
│   └── Responses/
│       └── ApiResponse.php   ← Consistent envelope {success, data, message}
├── Models/                   ← Eloquent models (HasUuids on all)
├── Policies/                 ← Resource ownership authorization
├── Repositories/
│   ├── Contracts/            ← Interfaces
│   └── Eloquent/             ← Implementations
└── Services/                 ← Application services — ALL business logic lives here
    ├── Auth/AuthService.php
    ├── Event/EventService.php
    ├── Notification/OutboxPublisherService.php
    ├── Order/OrderService.php
    ├── Order/ReservationCleanupService.php
    ├── Payout/PayoutService.php
    └── Refund/RefundService.php

bootstrap/app.php             ← Scheduler, middleware, exception renderers
routes/api.php                ← All routes (auth | public | sanctum | role:vendor | role:admin)
database/
├── migrations/               ← Named by domain, ordered by dependency
└── seeders/DatabaseSeeder.php
tests/Feature/                ← One directory per domain
```

---

## Common Patterns

### Consistent API Response
```php
return ApiResponse::success($data, 'Optional message');
return ApiResponse::created($data, 'Resource created');
return ApiResponse::error('Validation message', 422);
return ApiResponse::notFound('Resource not found');
return ApiResponse::forbidden('Insufficient role');
```

### Inventory Locking (CRITICAL — always do this)
```php
DB::transaction(function () use ($poolIds, $required) {
    // Lock pools in ID order to prevent cross-request deadlocks
    $pools = TicketInventoryPool::whereIn('id', $poolIds)
        ->orderBy('id')         // ← MUST be deterministic
        ->lockForUpdate()
        ->get();

    foreach ($pools as $pool) {
        if ($pool->availableUnits() < $required[$pool->id]) {
            throw new InsufficientInventoryException();
        }
    }
    // ... write reservation and increment reserved_units
});
```

### Audit Logging (every financial state change)
```php
AuditLog::create([
    'entity_type'     => 'order',           // or 'refund', 'payout' …
    'entity_id'       => $order->id,
    'action'          => 'status_changed',
    'previous_status' => $previous->value,
    'new_status'      => $newStatus->value,
    'actor_user_id'   => $actorId,          // null for system actions
    'correlation_id'  => (string) Str::uuid(),
    'metadata'        => ['reason' => $reason],
    'created_at'      => now(),
]);
```

### Outbox Event Publishing (write in same DB transaction, NEVER call RabbitMQ here)
```php
OutboxEvent::create([
    'event_type'       => 'order.paid',
    'aggregate_type'   => 'order',
    'aggregate_id'     => $order->id,
    'payload'          => [
        'user_id'    => $order->user_id,
        'user_email' => $order->user->email,
        // ... all data the notification service needs, denormalized
    ],
    'status'           => OutboxEventStatus::Pending->value,
    'publish_attempts' => 0,
    'available_at'     => now(),
]);
```

### Exception Hierarchy
```php
throw new \App\Exceptions\DomainException('Cannot refund a non-paid order');    // → 422
throw new \App\Exceptions\ConflictException('Order already paid');              // → 409
throw new \App\Exceptions\NotFoundException('Event not found');                 // → 404
// Rendered automatically by bootstrap/app.php
```

---

## Tests

```bash
# Run all feature tests
docker compose exec core-api php artisan test

# Run a specific domain
docker compose exec core-api php artisan test tests/Feature/Order
docker compose exec core-api php artisan test tests/Feature/Refund
docker compose exec core-api php artisan test tests/Feature/Payout
docker compose exec core-api php artisan test tests/Feature/Notification

# Run a single test class
docker compose exec core-api php artisan test --filter=ReservationFlowTest

# Regenerate test database
docker compose exec core-api php artisan migrate:fresh --env=testing
```

Key test patterns:
- Use `RefreshDatabase` trait on every Feature test
- Seed the minimum data inline — don't rely on `DatabaseSeeder` in tests
- Use `$this->actingAs($user)` for authenticated requests
- Assert `OutboxEvent::count()` to verify notification side-effects

---

## Commands

```bash
# Migrations
php artisan migrate
php artisan migrate:fresh --seed
php artisan migrate:rollback

# Scheduler (runs all scheduled commands)
php artisan schedule:run

# Individual scheduled jobs
php artisan reservations:cleanup
php artisan outbox:publish [--limit=50]
php artisan events:reminders
php artisan payouts:batch [--date=2024-01-31]
php artisan reports:daily-sales [--date=2024-01-31]

# Code quality
./vendor/bin/pint   # PHP-CS-Fixer (PSR-12)
```

---

## Integration Boundaries

- **Calls payment-service** via HTTP: `POST {PAYMENT_SERVICE_URL}/internal/v1/payments|refunds|payouts`
  - Header: `Authorization: Bearer {PAYMENT_SERVICE_TOKEN}`
- **Receives webhook callbacks** at:
  - `POST /api/v1/webhooks/payments`
  - `POST /api/v1/webhooks/refunds`
  - `POST /api/v1/webhooks/payouts`
  - Validates: `HMAC-SHA256(X-Webhook-Timestamp + "." + raw_body)` using `PAYMENT_SERVICE_WEBHOOK_SECRET`
- **Writes to RabbitMQ** via `OutboxPublisherService` (runs in the scheduler, not in-request)
  - Queue: `eventhub.notifications`
  - Library: `php-amqplib/php-amqplib ^3.7`
- **Own database:** `eventhub_core` (PostgreSQL 16)
