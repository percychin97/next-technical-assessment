# EventHub

> **Multi-vendor event ticketing and payout platform** — built with Laravel 11, Next.js 14, Node.js (Fastify), PostgreSQL 16, and RabbitMQ.

---

## Quick Start (2 Commands)

```bash
# 1. Build and start all services
docker compose up --build

# 2. In a second terminal: migrate and seed the demo data
docker compose exec core-api php artisan key:generate
docker compose exec core-api php artisan migrate --seed
```

Then open **http://localhost:3000** and log in using the demo accounts below.

---

## Demo Accounts

All accounts use the password **`password`**.

| Role       | Email                      | Pre-loaded State |
|------------|----------------------------|------------------|
| **Admin**  | admin@eventhub.dev         | Full platform access |
| **Vendor** | vendor@eventhub.dev        | Verified KYC, 3 events (2 published, 1 draft), 1 completed payout |
| **Vendor2**| vendor2@eventhub.dev       | Pending KYC — use Admin panel to approve |
| **Attendee**| attendee@eventhub.dev     | 2 paid orders, 3 tickets, 1 pending refund request |

---

## Demo Script

The following sequence demonstrates the complete platform in under 15 minutes.

### Act 1 — Vendor Creates an Event (3 min)

1. Log in as **vendor@eventhub.dev**
2. Click **Create Event**
3. Fill in a title, description, and start date (any future date)
4. Add an inventory pool (e.g. 200 seats)
5. Set a ticket price (e.g. MYR 80)
6. Go to **My Events** → click **Publish Now**

The event is immediately visible on the homepage.

### Act 2 — Attendee Buys a Ticket (5 min)

1. Log out, then log in as **attendee@eventhub.dev**
2. Browse the homepage → open any event
3. Select ticket quantity → **Checkout**
4. Click **Pay with Demo Card**
5. The page polls the API until the payment webhook fires — order transitions to `paid`
6. Go to **My Orders** — see the confirmed ticket

> **What happens under the hood:** `POST /orders` locks inventory atomically, `POST /orders/{id}/payments` calls the payment-service (Node.js Fastify), which fires back a webhook to Core API, which transitions the order to `paid` and writes an `order.paid` outbox event for the notification service.

### Act 3 — Attendee Requests a Refund (2 min)

1. On **My Orders** → click **Request Refund** on any paid order
2. Log in as **admin@eventhub.dev**
3. Navigate to **Finance** → see the pending refund request
4. Click **Approve** — the refund is processed

### Act 4 — Admin Approves a Vendor (2 min)

1. As Admin, navigate to **Vendors**
2. Find **Creative Gatherings Co.** (pending KYC)
3. Click **Approve**

### Act 5 — Payout (2 min)

1. As Admin, navigate to **Finance** → note the pre-seeded completed payout (MYR 285.00)
2. To trigger a new one: call `POST /api/v1/admin/payouts` with vendor ID and period (see Postman collection)
3. Then `POST /api/v1/admin/payouts/{id}/approve`
4. As **vendor@eventhub.dev** → **Dashboard** shows the updated lifetime earnings

---

## Service URLs

| Service | URL |
|---------|-----|
| **Web** (Next.js 14) | http://localhost:3000 |
| **Core API** (Laravel 11) | http://localhost:8000/api/v1 |
| **Payment Service** (Fastify) | http://localhost:3001 |
| **RabbitMQ Management** | http://localhost:15672 — `eventhub` / `eventhub_password` |

---

## API Documentation

Import [`docs/EventHub.postman_collection.json`](docs/EventHub.postman_collection.json) into Postman.

The collection includes:
- Pre-request scripts that auto-store the Bearer token after login
- Test scripts that capture `orderId`, `eventId`, `refundId`, and `payoutId` from responses
- All endpoints across Auth, Events, Orders, Vendor, and Admin flows

---

## Running Tests

```bash
# Core API — PHPUnit integration tests
docker compose exec core-api php artisan test

# Core API — specific test suite
docker compose exec core-api php artisan test tests/Feature/Order
docker compose exec core-api php artisan test tests/Feature/Refund
docker compose exec core-api php artisan test tests/Feature/Payout
docker compose exec core-api php artisan test tests/Feature/Notification

# Payment Service — Vitest unit tests
docker compose exec payment-service npm test

# Notification Service — Vitest unit tests
docker compose exec notification-service npm test
```

---

## Reset and Re-seed

```bash
# Full reset (drops + recreates all tables, then seeds)
docker compose exec core-api php artisan migrate:fresh --seed

# Seed only (on an already-migrated database)
docker compose exec core-api php artisan db:seed
```

---

## Architecture

```
Browser (Next.js 14 — :3000)
        |
        v  REST (Bearer token / Sanctum)
Laravel Core API (:8000)
        |
        +──> PostgreSQL 16 (core_db)
        |      orders, events, tickets, payouts, outbox_events …
        |
        +──> Node.js Payment Service (:3001)
        |      Fastify simulator — processes payments, fires webhook
        |
        +──> RabbitMQ (:5672)
               outbox:publish polls outbox_events → publishes to queue
                      |
                      v
               Node.js Notification Service
                      Consumes jobs → email (console) / vendor webhook
                      Tracks delivery in notification_db.notification_deliveries
```

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Row-level `SELECT … FOR UPDATE`** | Prevents overselling without application-level locks |
| **Outbox pattern** | Guarantees notification delivery even if RabbitMQ is momentarily down at payment time |
| **Idempotency keys on all writes** | Payments, refunds, and payouts are safe to retry |
| **Minor units (integer sen)** | Eliminates floating-point rounding errors in financial calculations |
| **Commission rate snapshotted at payout time** | Historical rate changes don't retroactively alter past payouts |
| **UTC storage + display_timezone** | All times stored in UTC; the UI localises based on `display_timezone` |
| **Separate payment microservice** | Isolates provider coupling; swap payment gateway without touching core domain |

See [`docs/architecture.md`](docs/architecture.md) for the full system design and ERD.

---

## Project Structure

```
eventhub/
├── apps/
│   ├── core-api/          Laravel 11 — domain logic, REST API, scheduler
│   ├── web/               Next.js 14 — Attendee, Vendor, Admin UI
│   ├── payment-service/   Node.js Fastify — payment simulation + webhooks
│   └── notification-service/ Node.js — RabbitMQ consumer, email/webhook delivery
├── docs/
│   ├── architecture.md    Full ERD and system design
│   └── EventHub.postman_collection.json
├── docker-compose.yml
└── README.md
```

---

## Video Walkthrough
https://drive.google.com/file/d/1jS8fxvuVYgGe_SHk4WhNNIzs0PDYMD4G/view?usp=drive_link
