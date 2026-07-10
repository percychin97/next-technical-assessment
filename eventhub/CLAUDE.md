# CLAUDE.md — EventHub AI Developer Guide

> **Read this first.** Any developer — human or AI — should be productive in 30 minutes of reading this file.
> Updated after Step 15 to reflect the complete system.

---

## Repository Map

```
eventhub/
├── apps/
│   ├── core-api/              ← Laravel 11, PHP 8.2+ (Port 8000)
│   ├── web/                   ← Next.js 14, TypeScript, Vanilla CSS (Port 3000)
│   ├── payment-service/       ← Node.js 20, TypeScript, Fastify (Port 3001)
│   └── notification-service/  ← Node.js 20, TypeScript, RabbitMQ consumer
├── docs/
│   ├── architecture.md        ← Full ERD and system design
│   ├── decision-log.md        ← Architecture trade-off decisions
│   └── EventHub.postman_collection.json
├── agent-skills/              ← Per-service AI guidance (see below)
├── scripts/                   ← Docker init scripts
└── docker-compose.yml         ← Full local stack definition
```

---

## Service Ownership

| Service | Owns | Must NOT own |
|---|---|---|
| `core-api` | Users, KYC, events, inventory, orders, refunds, payouts, outbox events, scheduled jobs, audit logs | Payment execution, notification delivery |
| `payment-service` | Gateway abstraction, idempotency, simulator behaviour, webhook callbacks | Business authorization, inventory, order status |
| `notification-service` | Queue consumption, delivery tracking, retry/DLQ, email/webhook dispatch | Business decisions, recipient eligibility |
| `web` | UI, API integration, client-side auth state | Authorization logic, business calculations |

---

## Architecture Rules

These are **invariants** — violating them breaks correctness or safety.

1. **Controllers are thin.** `validate → service → respond`. No DB queries or business logic in controllers.
2. **Layering is strict:** `Controller → Service → Repository → Model`
3. **All API responses** use the `ApiResponse` envelope: `{success, data, message, meta?}`
4. **Amounts are always minor units** (integer sen/cents). Never floats. Never divide by 100 in the database layer.
5. **All timestamps stored in UTC.** Events carry `display_timezone` for client-side formatting only.
6. **Inventory locking:** Always `SELECT … FOR UPDATE` inside a transaction. Lock pools in `ORDER BY id` to prevent deadlocks.
7. **Idempotency keys** are required for payment initiation, refund creation, and payout creation.
8. **Audit every financial transition** via `AuditLog::create()` inside the same DB transaction as the state change.
9. **Outbox pattern** for notifications: write to `outbox_events` in the same transaction as the financial commit. Never call RabbitMQ inside a transaction.
10. **Confirmed financial amounts are immutable.** Never `UPDATE` a paid order's `total_amount_minor`, a completed refund's `approved_amount_minor`, or a completed payout's `net_amount_minor`.
11. **Webhook authenticity** is validated via HMAC-SHA256 before processing any provider callback.
12. **No direct cross-service DB access.** Services communicate only via HTTP or RabbitMQ.

---

## Setup Commands

```bash
# Full startup (first time or after changes)
docker compose up --build

# Migrate and seed demo data
docker compose exec core-api php artisan key:generate
docker compose exec core-api php artisan migrate --seed

# Reset everything and re-seed
docker compose exec core-api php artisan migrate:fresh --seed

# Open the UI
open http://localhost:3000
```

---

## Demo Accounts (password: `password`)

| Role | Email | State |
|---|---|---|
| Admin | admin@eventhub.dev | Full access |
| Vendor | vendor@eventhub.dev | Verified, 3 events, 1 payout |
| Vendor2 | vendor2@eventhub.dev | Pending KYC (approve via Admin) |
| Attendee | attendee@eventhub.dev | 2 paid orders, 1 pending refund |

---

## Test Commands

```bash
# Core API — all tests
docker compose exec core-api php artisan test

# Core API — by domain
docker compose exec core-api php artisan test tests/Feature/Order
docker compose exec core-api php artisan test tests/Feature/Refund
docker compose exec core-api php artisan test tests/Feature/Payout
docker compose exec core-api php artisan test tests/Feature/Notification

# Payment Service
docker compose exec payment-service npm test

# Notification Service
docker compose exec notification-service npm test

# Web — TypeScript build check
cd apps/web && npm run build
```

---

## Coding Conventions

### PHP (core-api)
- **PSR-12** formatting — run `./vendor/bin/pint`
- **Strict types** on every file: `declare(strict_types=1);`
- **PHP 8.2 features**: named arguments, enum, readonly properties, first-class callables
- **No raw SQL** except in Repository implementations and complex aggregates
- **Exception hierarchy**: throw `DomainException` (422), `ConflictException` (409), `NotFoundException` (404) — they auto-render via `bootstrap/app.php`

### TypeScript (payment-service, notification-service, web)
- `"strict": true` in all tsconfigs
- Explicit return types on all exported functions
- No `any` except in test utilities
- Prefer `interface` over `type` for object shapes

### Frontend (web)
- **No TailwindCSS** — use Vanilla CSS with CSS Modules
- Global design tokens live in `src/app/globals.css`
- Component styles in `ComponentName.module.css` co-located with the component
- All data fetching via `src/lib/api.ts` — never `fetch()` directly in components
- Use `'use client'` only in leaf components that need state/effects

---

## How to Add an Endpoint (Core API)

```
1. Create FormRequest:  app/Http/Requests/<Domain>/<ActionNameRequest>.php
2. Add Service method:  app/Services/<Domain>/<DomainService>.php
3. Create/update Controller: app/Http/Controllers/Api/V1/<DomainController>.php
4. Register route: routes/api.php  (inside the correct auth/role middleware group)
5. Add Policy if ownership is involved: app/Policies/<Domain>Policy.php
6. Write tests: tests/Feature/<Domain>/<FeatureTest>.php
```

Example controller method:
```php
public function store(CreateEventRequest $request): JsonResponse
{
    $vendor = $request->user()->vendor;
    $event  = $this->eventService->create($vendor, $request->validated());
    return ApiResponse::created($event, 'Event created');
}
```

---

## How to Add a Migration

```bash
# Generate
docker compose exec core-api php artisan make:migration add_column_to_table

# Run
docker compose exec core-api php artisan migrate

# Always write a down() method for rollback
```

**Naming conventions:**
- `create_<table>_table` for new tables
- `add_<column>_to_<table>` for new columns
- `create_idx_<table>_<column>` for index-only migrations
- File prefix format: `YYYY_MM_DD_HHMMSS_`

---

## How to Add a Payment Provider

Payment providers are isolated inside the `payment-service`. Core API has zero knowledge of provider specifics.

```
1. Create: apps/payment-service/src/gateways/<ProviderName>Simulator.ts
   - Implement the PaymentGateway interface (process, refund, payout methods)
   - Accept PROVIDER_SUCCESS_RATE env variable for simulator behaviour

2. Register: apps/payment-service/src/gateways/index.ts
   - Add case to getGateway(provider) factory

3. Add env variables to docker-compose.yml:
   PROVIDER_API_KEY, PROVIDER_SUCCESS_RATE

4. Write tests with deterministic key suffixes (_success / _fail)
```

---

## How to Add a Notification Type

```
1. Core API (outbox side):
   - Add the new event_type string (e.g. 'event.cancelled')
   - In OutboxPublisherService::mapEventToNotificationJobs() add a new match arm
   - Map to a notificationType (e.g. 'event_cancelled') with recipient data

2. Notification Service (delivery side):
   - Add to NotificationType union in src/types/index.ts
   - Add a case to getEmailTemplate() in dispatchers/index.ts
   - If vendor webhook: the channel='vendor_webhook' path handles it generically

3. Write a test case in notification-service/tests/notificationDispatcher.test.ts
```

---

## Domain Invariants — Never Break These

| Invariant | Where enforced |
|---|---|
| `reserved_units + sold_units <= capacity_units` | DB CHECK constraint + `SELECT FOR UPDATE` in OrderService |
| Payment idempotency key → at most one payment aggregate | Unique index on `payments.idempotency_key` |
| Provider webhook event ID applied at most once | Unique index on `payment_attempts.provider_event_id` |
| Payout period → at most one payout per vendor | Unique index on `payouts.idempotency_key` |
| Completed financial amounts are immutable | Application rule — never UPDATE after status = completed/paid |
| Notification failure must not roll back a financial operation | Outbox events written in same TX; notification delivery is async |
| Commission rates snapshotted at payout time | `commission_rate_basis_points_snapshot` copied at payout creation |
| All inventory locks acquired in ID order | `orderBy('id')` before `lockForUpdate()` in all reservation paths |

---

## Scheduled Jobs (bootstrap/app.php)

| Command | Schedule | Purpose |
|---|---|---|
| `reservations:cleanup` | Every 5 min | Expire held reservations past their 15-min hold, release inventory |
| `event-lifecycle:update` | Every 5 min | Draft → Published → Ongoing → Completed transitions |
| `outbox:publish` | Every 1 min | Poll `outbox_events` → publish to RabbitMQ |
| `events:reminders` | Hourly | Queue 24h reminder notifications for upcoming events |
| `payouts:batch` | Daily 02:00 UTC | Auto-calculate and create pending payouts for vendors |
| `reports:daily-sales` | Daily 01:00 UTC | Aggregate daily stats → admin notification |

---

## Decision Log

See [`docs/decision-log.md`](docs/decision-log.md) for the full rationale behind key architectural choices:
- Monorepo vs separate repositories
- Synchronous HTTP vs queue communication
- Database row lock vs Redis lock for inventory
- Separate payment microservice (why not in core-api)
- Outbox pattern for notifications
- UTC storage and event timezone handling
- Why some features were deliberately left incomplete
