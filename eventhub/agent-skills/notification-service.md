# Notification Service Agent Skill

> Service: `apps/notification-service/` — Node.js 20, TypeScript, RabbitMQ consumer, PostgreSQL

---

## What this service owns

- RabbitMQ queue consumption from `eventhub.notifications`
- Simulated email delivery (logged to console as structured output)
- Vendor webhook delivery with HMAC-SHA256 signing
- Exponential backoff retry (1s → 4s → 16s → 64s → 256s)
- Dead-letter queue after 5 failed attempts
- Delivery status tracking in `notification_deliveries` table (own database)

## What this service does NOT own

- Who gets notified — Core API decides and publishes outbox events
- Business event eligibility (whether an attendee has a valid ticket)
- The routing decision of which recipient receives which message (done in `OutboxPublisherService`)
- Any calls back to Core API's database

---

## Directory Structure

```
src/
├── index.ts                       ← Startup: connect to RabbitMQ (with retry), start consumer
├── consumers/
│   └── notificationConsumer.ts    ← Main queue consumer, retry/DLQ logic
├── dispatchers/
│   └── index.ts                   ← Routes by channel: email | vendor_webhook
│       ├── getEmailTemplate()     ← Template per NotificationType
│       └── dispatchVendorWebhook() ← HTTP POST with HMAC-SHA256 signature
├── db/
│   └── index.ts                   ← PostgreSQL client + createDelivery/updateDelivery
└── types/
    └── index.ts                   ← NotificationType, NotificationJob, DeliveryRecord

tests/
└── notificationDispatcher.test.ts ← 14 unit tests (email templates + vendor webhook)

vitest.config.ts
Dockerfile                         ← Multi-stage build (builder + production)
```

---

## NotificationJob Shape

Messages consumed from RabbitMQ follow this exact shape:

```typescript
interface NotificationJob {
  integrationEventId:   string;   // Unique per delivery; used for idempotency
  notificationType:     NotificationType;
  channel:              'email' | 'vendor_webhook';
  recipientReference:   string;   // user_id or vendor_id
  destination:          string;   // email address or webhook URL
  payload:              Record<string, unknown>; // Template-specific data
  vendorWebhookSecret?: string;   // Required if channel = vendor_webhook
}
```

---

## Retry Strategy

```
Attempt 1: immediate  (BACKOFF_BASE_MS = 1000)
Attempt 2: 1 second   (1000 × 4^0)
Attempt 3: 4 seconds  (1000 × 4^1)
Attempt 4: 16 seconds (1000 × 4^2)
Attempt 5: 64 seconds (1000 × 4^3)
→ Dead letter after 5 failures (status: dead_letter)
```

The retry delay is injected into the consumer via `setTimeout` — the RabbitMQ message is nacked (requeued) only on transient failures. On dead-letter, it is moved to `eventhub.notifications.dead_letter`.

---

## Supported Notification Types

| `notificationType` | Channel | Triggered by outbox event |
|---|---|---|
| `order_confirmation` | email | `order.paid` |
| `refund_processed` | email | `refund.completed` |
| `payout_completed` | email + vendor_webhook | `payout.completed` |
| `vendor_approved` | email | `vendor.approved` |
| `vendor_rejected` | email | `vendor.rejected` |
| `event_reminder` | email | `event.reminder` (hourly scheduler) |
| `event_cancelled` | email | `event.cancelled` |
| `report.daily_sales` | (admin notification) | `reports:daily-sales` command |

---

## Adding a New Notification Type

1. **`src/types/index.ts`** — add to `NotificationType` union:
   ```typescript
   export type NotificationType =
     | 'order_confirmation'
     | 'refund_processed'
     | 'your_new_type'    // ← add here
     | …;
   ```

2. **`dispatchers/index.ts`** — add template case in `getEmailTemplate()`:
   ```typescript
   case 'your_new_type':
     return {
       subject: 'Your Subject',
       body: `Dear attendee, ${payload.relevantField}…`,
     };
   ```

3. **Core API `OutboxPublisherService.php`** — add match arm in `mapEventToNotificationJobs()`:
   ```php
   'your.outbox_event_type' => [[
       'integrationEventId' => $event->id,
       'notificationType'   => 'your_new_type',
       'channel'            => 'email',
       'destination'        => $p['user_email'] ?? '',
       'payload'            => ['relevantField' => $p['relevant_field']],
   ]],
   ```

4. **Test** — add a test case in `tests/notificationDispatcher.test.ts`.

---

## Vendor Webhook Signature

```
POST {vendor_webhook_url}

Headers:
  Content-Type:          application/json
  X-EventHub-Event-Id:   notif_{uuid}
  X-EventHub-Timestamp:  Unix timestamp (seconds)
  X-EventHub-Signature:  HMAC-SHA256(timestamp + "." + rawBody, vendorWebhookSecret)
```

The vendor verifies: `expected = HMAC-SHA256(timestamp + "." + body, their_secret)`

---

## Commands

```bash
npm run dev        # start consumer with tsx watch + hot reload
npm run build      # compile TypeScript → dist/
npm run start      # production: node dist/index.js
npm test           # vitest unit tests
npm run typecheck  # tsc --noEmit
```

---

## Integration Boundaries

| Direction | What |
|---|---|
| Inbound | RabbitMQ queue: `eventhub.notifications` (published by Core API's `outbox:publish`) |
| Outbound | Console-simulated email + HTTP POST to vendor webhook URLs |
| Dead letter | `eventhub.notifications.dead_letter` queue |
| Database | Own PostgreSQL: `eventhub_notification` (`notification_deliveries` table) |
| No access to | Core API DB, payment-service DB, Core API HTTP endpoints |

---

## Common Failure Modes

| Failure | Behaviour |
|---|---|
| Vendor webhook returns 4xx/5xx | Retried with exponential backoff; dead-lettered after 5 attempts |
| RabbitMQ is down at startup | `index.ts` retries connection every 5 seconds up to 10 times |
| Missing `vendorWebhookSecret` | Throws immediately; job goes to DLQ (no partial delivery) |
| Duplicate `integrationEventId` | `notification_deliveries` has unique index; second delivery is skipped gracefully |
