# Architecture Decision Log — EventHub

> Decisions made during the technical assessment build (Steps 1–15). Organized from highest to lowest architectural impact.

---

## 1. Monorepo vs Separate Repositories

**Decision:** Single monorepo with `apps/` subdirectories.

**Rationale:**
- The assessment requires a single `docker compose up` to spin up the entire system. A monorepo is the only practical way to achieve that without external tooling.
- Services share no runtime code — the monorepo is structural convenience, not code coupling. Each service has its own `package.json` / `composer.json` and its own database.
- In a production system with multiple teams, each service would likely be extracted into its own repository with individual CI pipelines. The current structure is explicitly an assessment trade-off.

**Trade-off accepted:** A real multi-team setup would use separate repositories with versioned API contracts (OpenAPI) and independent deploy pipelines.

---

## 2. Synchronous HTTP vs Queue Communication (Core API → Payment Service)

**Decision:** Synchronous HTTP from Core API to Payment Service for initiating payment, with an **asynchronous webhook callback** for the result.

**Rationale:**
- Payment initiation is synchronous so the Core API can immediately know whether the request was accepted.
- The payment result (success/failure) is inherently asynchronous — real gateways like Stripe also work this way. Modelling it as a webhook means the architecture is realistic and the Core API is resilient to payment-service slowness.
- A pure synchronous model would couple order latency to payment-provider latency. A pure queue model would make the checkout UX awkward (no immediate feedback).

**Trade-off accepted:** The frontend must poll for order status after initiating payment. This adds client complexity but keeps the backend correctly decoupled.

---

## 3. Database Row Lock vs Redis Lock for Inventory

**Decision:** PostgreSQL `SELECT … FOR UPDATE` (row-level lock) inside a DB transaction. No Redis.

**Rationale:**
- Inventory availability is a DB concern — the source of truth is the `ticket_inventory_pools.reserved_units` column. Locking at the DB layer means the lock and the data update are **atomic** within the same transaction. If the transaction rolls back, the lock is released and the reserved_units are never incremented.
- Redis locks are non-transactional. If the app crashes after acquiring a Redis lock but before writing to the DB, the inventory could be silently held. Releasing that lock correctly requires a separate watchdog/TTL mechanism.
- PostgreSQL row locks scale well for the assessment's expected concurrency range.

**Additional invariant enforced:** Pools are always locked in `ORDER BY id` to prevent deadlocks when a single order spans multiple pools.

---

## 4. Separate Payment Microservice

**Decision:** Payment execution logic lives in a dedicated `payment-service` (Node.js Fastify), separate from the Core API (Laravel).

**Rationale:**
- **Provider isolation:** Adding Stripe, PayPal, or any other gateway requires zero changes to Core API. The gateway interface in `payment-service/src/gateways/` is the only seam.
- **Realistic assessment architecture:** The spec explicitly calls for a payment service. A real platform would have different teams, languages, and PCI DSS scoping for the payment component.
- **Failure isolation:** A payment-service crash does not take down event browsing or vendor management.

**Trade-off accepted:** Inter-service HTTP adds latency and a network failure mode. Mitigation: webhook-based result model means the order is never left in an inconsistent state on network failure.

## 5. Financial Audit Strategy

**Decision:** Every financial state change (order status, refund status, payout status) MUST insert a corresponding record into the `audit_logs` table synchronously, within the same database transaction.

**Rationale:**
- In a financial system, knowing *what* the current state is is not enough; we must know *who* changed it, *when*, and *why*.
- Writing audit logs asynchronously (e.g., via events) risks losing the audit trail if the async process fails while the main transaction succeeds.
- By enforcing this at the application service layer within the `DB::transaction()`, we guarantee that a financial change cannot exist without its accompanying audit record.

---

## 6. Outbox Pattern for Notification Publishing

**Decision:** Core API writes `outbox_events` to its own DB in the same transaction as the financial commit. A scheduler (`outbox:publish`, every 1 minute) polls and publishes to RabbitMQ.

**Rationale:**
- Publishing to RabbitMQ inside a DB transaction is unsafe — if the broker is down, the transaction would fail and roll back the financial operation.
- The outbox pattern guarantees **at-least-once delivery**: if the scheduler crashes mid-publish, the event remains `pending` and will be retried on the next run.
- `publish_attempts` is tracked; events are marked `failed` after 5 retries.

**Alternative considered:** Saga / choreography with compensating transactions. Rejected as over-engineered for the notification domain where missing an email is recoverable.

---

## 7. UTC Storage + Display Timezone

**Decision:** All timestamps stored in UTC. Events carry `display_timezone` (e.g. `Asia/Kuala_Lumpur`) used only for presentation.

**Rationale:**
- DST adjustments, timezone offsets, and regional rules are entirely a presentation concern. Storing local times in the DB creates subtle bugs when clocks change.
- The scheduler operates on UTC, which is correct regardless of server or user location.

---

## 8. Financial Amounts in Minor Units (Integer Sen)

**Decision:** All monetary values stored as `INTEGER` (minor units — sen for MYR). `MYR 150.00 = 15000`.

**Rationale:**
- Floating-point arithmetic is non-deterministic for financial calculations (`0.1 + 0.2 !== 0.3` in IEEE 754).
- Integer arithmetic in SQL and PHP is exact. All payout calculations use integer arithmetic.
- Division by 100 happens only at the presentation layer (never stored).

---

## 9. Payout Commission Snapshotted at Creation Time

**Decision:** Commission rate is copied into `commission_rate_basis_points_snapshot` at payout creation, not at order time.

**Rationale:**
- Platform rates may change. A vendor who sold tickets under a 5% commission rate must be paid at 5%, even if the rate later changes to 6%.
- Snapshotting at payout time simplifies the query: join the latest effective rate once per payout batch, copy it, and that value is immutable forever.

---

## 10. Deliberately Incomplete Features

The following features were **scoped out** as the spec explicitly allowed:

| Feature | Decision | Future Path |
|---|---|---|
| Real payment gateways (Stripe/PayPal) | Simulator only | Add `StripeGateway` implementing `PaymentGateway` interface |
| KYC document upload | Status flag only | Integrate document verification service (e.g. Jumio) |
| QR code ticket scanning | Ticket record stored | Add `POST /tickets/{id}/check-in` with TOTP/QR validation |
| Dispute management | Not built | Add `disputes` table and admin arbitration flow |
| Waitlist auto-notification | Outbox events fire for `inventory.released` | Wire `inventory.released` in `OutboxPublisherService` |
| Real email delivery | Console-simulated | Replace `console.log` in `dispatchers/index.ts` with Mailgun/SES |
| Multi-currency support | MYR only | Add `fx_rate_snapshot` to `order_items` and `payouts` |

---

## 11. RefundPolicy Sliding Scale

**Decision:**

| Time before event | Refund % |
|---|---|
| ≥ 7 days | 100% |
| 2–7 days | 50% |
| 24h–2 days | 25% |
| < 24h | 0% |

**Rationale:** Mirrors common industry practice and is simple enough for the assessment. In production, each event would have its own vendor-configured refund policy.
