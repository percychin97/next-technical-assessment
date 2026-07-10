# Payment Service Agent Skill

> Service: `apps/payment-service/` — Node.js 20, TypeScript, Fastify, PostgreSQL

---

## What this service owns

- Simulated payment gateway abstraction (`StripeSimulator`, `PayPalSimulator`)
- Payment creation with full idempotency (duplicate key → same response, no double-charge)
- Refund execution against a previous payment
- Payout execution for vendor net settlement
- Delayed, HMAC-signed webhook callbacks to Core API for all outcomes
- `payment_operations` table in its own isolated database

## What this service does NOT own

- Business authorization (who can pay, refund eligibility, payout approval)
- Ticket inventory — it knows nothing about events or tickets
- Order status — it only reports payment outcomes back via webhook
- Any connection to the notification system

---

## Directory Structure

```
src/
├── index.ts                    ← Fastify app bootstrap, plugin registration
├── db.ts                       ← PostgreSQL pool + auto-migration on startup
├── gateways/
│   ├── StripeSimulator.ts      ← Configurable success rate, seeded for test determinism
│   ├── PayPalSimulator.ts      ← Alternative simulator
│   └── index.ts                ← getGateway(provider) factory
├── routes/
│   ├── payments.ts             ← POST /internal/v1/payments
│   ├── refunds.ts              ← POST /internal/v1/refunds
│   ├── payouts.ts              ← POST /internal/v1/payouts
│   └── health.ts               ← GET /health
├── middleware/
│   ├── auth.ts                 ← Bearer token validation (PAYMENT_SERVICE_TOKEN)
│   └── errorHandler.ts         ← Consistent error envelope
├── services/
│   └── callbackWorker.ts       ← Fires delayed HMAC-signed webhook to Core API
└── types/index.ts              ← PaymentRequest, RefundRequest, etc.
```

---

## Idempotency Pattern

Every write operation checks for an existing record by `idempotency_key` before proceeding. If found, returns the existing result with `HTTP 200` — **never processes twice**.

```typescript
const existing = await db.queryOne(
  `SELECT * FROM payment_operations WHERE idempotency_key = $1`,
  [body.idempotencyKey]
);
if (existing) {
  return reply.code(200).send({ ...existing, message: 'Idempotent response' });
}
// ... proceed with new operation
```

---

## Test Determinism

In `NODE_ENV=test`, simulators accept deterministic key suffixes to control outcomes:

| Key Suffix | Result |
|---|---|
| `_success` | Always succeeds |
| `_fail` | Always fails |
| _(other)_ | Uses configured `PAYMENT_SUCCESS_RATE` (default 0.85) |

Example test:
```typescript
const body = {
  idempotencyKey: 'order-uuid-001_success',
  amount: 15000,
  currency: 'MYR',
  provider: 'stripe_simulator',
};
// Will always succeed — no race conditions in tests
```

---

## Webhook Callback Signature

All callbacks to Core API are signed to prove authenticity:

```
POST {CORE_API_CALLBACK_URL}/api/v1/webhooks/payments

Headers:
  X-Webhook-Id:        unique UUID per webhook delivery
  X-Webhook-Timestamp: Unix timestamp (seconds)
  X-Webhook-Signature: HMAC-SHA256(timestamp + "." + raw_body, WEBHOOK_SECRET)
```

Core API validates: `expected = HMAC-SHA256(timestamp + "." + body, PAYMENT_SERVICE_WEBHOOK_SECRET)`

---

## Adding a New Payment Provider

1. Create `src/gateways/<Provider>Simulator.ts` implementing:
   ```typescript
   interface PaymentGateway {
     process(req: PaymentRequest): Promise<GatewayResult>;
     refund(req: RefundRequest): Promise<GatewayResult>;
     payout(req: PayoutRequest): Promise<GatewayResult>;
   }
   ```
2. Register in `src/gateways/index.ts`:
   ```typescript
   case 'new_provider': return new NewProviderSimulator();
   ```
3. Add env variables to `docker-compose.yml`
4. Add `NODE_ENV=test` deterministic suffix handling

---

## Commands

```bash
npm run dev      # development with tsx watch + hot reload
npm run build    # compile TypeScript → dist/
npm run start    # production: node dist/index.js
npm test         # vitest unit tests
npm run typecheck # tsc --noEmit
```

---

## Integration Boundaries

| Direction | What |
|---|---|
| Inbound | HTTP from Core API only (Bearer token validated) |
| Outbound | HMAC-signed webhook callback to Core API |
| Database | Own PostgreSQL database: `eventhub_payment` |
| No access to | Core API DB, RabbitMQ, notification-service |

---

## Common Failure Modes to Know

| Failure | Behaviour |
|---|---|
| Payment times out | Core API's webhook never fires; order stays `awaiting_payment` until the 15-min reservation expires |
| Core API webhook endpoint is down | `callbackWorker.ts` retries up to 3 times with backoff |
| Duplicate `idempotencyKey` | Returns existing result; no second operation is created |
| Invalid Bearer token | 401 immediately, no operation attempted |
