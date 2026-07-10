import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { buildApp } from '../src/index';
import { createMockDb } from './helpers/mockDb';

// ─── Mock the DB module ───────────────────────────────────────────────────────
const mockDb = createMockDb();
vi.mock('../src/db', () => ({ db: mockDb }));

// ─── Mock callbackWorker — no real HTTP calls in unit tests ──────────────────
vi.mock('../src/services/callbackWorker', () => ({
  callbackWorker: {
    schedulePaymentCallback: vi.fn(),
    scheduleRefundCallback:  vi.fn(),
    schedulePayoutCallback:  vi.fn(),
  },
}));

// ─── Set required env ─────────────────────────────────────────────────────────
beforeEach(() => {
  process.env.PAYMENT_SERVICE_TOKEN = 'test-token';
  process.env.STRIPE_SUCCESS_RATE   = '0.9';
  process.env.PAYPAL_SUCCESS_RATE   = '0.85';
  mockDb._clear();
});

afterEach(() => {
  vi.clearAllMocks();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

async function buildTestApp() {
  const app = await buildApp();
  return app;
}

const AUTH_HEADER = { Authorization: 'Bearer test-token' };

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('POST /internal/v1/payments', () => {
  it('returns 202 with pending status on valid request', async () => {
    const app = await buildTestApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/payments',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'test-payment-001',
        orderId:        'order-uuid-001',
        provider:       'stripe_simulator',
        amountMinor:    10000,
        currency:       'MYR',
      },
    });

    expect(response.statusCode).toBe(202);
    const body = JSON.parse(response.body);
    expect(body.status).toBe('pending');
    expect(body.id).toBeDefined();
    expect(body.providerReference).toMatch(/^stripe_pi_/);
    expect(body.idempotencyKey).toBe('test-payment-001');

    await app.close();
  });

  it('returns 200 with same record on duplicate idempotency key', async () => {
    const app = await buildTestApp();
    const payload = {
      idempotencyKey: 'idem-duplicate-001',
      orderId:        'order-uuid-002',
      provider:       'stripe_simulator',
      amountMinor:    20000,
      currency:       'MYR',
    };
    const headers = { ...AUTH_HEADER, 'Content-Type': 'application/json' };

    // First request
    const r1 = await app.inject({ method: 'POST', url: '/internal/v1/payments', headers, payload });
    expect(r1.statusCode).toBe(202);
    const body1 = JSON.parse(r1.body);

    // Second request — same idempotency key
    const r2 = await app.inject({ method: 'POST', url: '/internal/v1/payments', headers, payload });
    expect(r2.statusCode).toBe(200);
    const body2 = JSON.parse(r2.body);

    expect(body1.id).toBe(body2.id);
    expect(body2.message).toBe('Idempotent response');

    await app.close();
  });

  it('returns 401 when authorization header is missing', async () => {
    const app = await buildTestApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/payments',
      headers: { 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'no-auth-001',
        orderId:        'order-uuid-003',
        provider:       'stripe_simulator',
        amountMinor:    10000,
        currency:       'MYR',
      },
    });

    expect(response.statusCode).toBe(401);
    await app.close();
  });

  it('returns 400 when required fields are missing', async () => {
    const app = await buildTestApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/payments',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        // Missing idempotencyKey and orderId
        provider:    'stripe_simulator',
        amountMinor: 10000,
        currency:    'MYR',
      },
    });

    expect(response.statusCode).toBe(400);
    await app.close();
  });

  it('returns 400 for unknown payment provider', async () => {
    const app = await buildTestApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/payments',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'bad-provider-001',
        orderId:        'order-uuid-004',
        provider:       'unknown_bank',   // invalid
        amountMinor:    10000,
        currency:       'MYR',
      },
    });

    expect(response.statusCode).toBe(400);
    await app.close();
  });

  it('schedules a callback after successful creation', async () => {
    const { callbackWorker } = await import('../src/services/callbackWorker');
    const app = await buildTestApp();

    await app.inject({
      method: 'POST',
      url: '/internal/v1/payments',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'callback-test-001',
        orderId:        'order-uuid-005',
        provider:       'stripe_simulator',
        amountMinor:    5000,
        currency:       'MYR',
      },
    });

    expect(callbackWorker.schedulePaymentCallback).toHaveBeenCalledOnce();
    const callArgs = vi.mocked(callbackWorker.schedulePaymentCallback).mock.calls[0][0];
    expect(callArgs.aggregateReference).toBe('order-uuid-005');
    expect(callArgs.operationType).toBe('payment');
    expect(callArgs.amountMinor).toBe(5000);
    expect(callArgs.currency).toBe('MYR');

    await app.close();
  });

  it('deterministically fails payment for idempotency key ending in _fail', async () => {
    const { callbackWorker } = await import('../src/services/callbackWorker');
    const app = await buildTestApp();

    await app.inject({
      method: 'POST',
      url: '/internal/v1/payments',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'test-payment_fail',
        orderId:        'order-uuid-006',
        provider:       'stripe_simulator',
        amountMinor:    10000,
        currency:       'MYR',
      },
    });

    const callArgs = vi.mocked(callbackWorker.schedulePaymentCallback).mock.calls[0][0];
    expect(callArgs.willSucceed).toBe(false);

    await app.close();
  });

  it('deterministically succeeds payment for idempotency key ending in _success', async () => {
    const { callbackWorker } = await import('../src/services/callbackWorker');
    const app = await buildTestApp();

    await app.inject({
      method: 'POST',
      url: '/internal/v1/payments',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'test-payment_success',
        orderId:        'order-uuid-007',
        provider:       'paypal_simulator',
        amountMinor:    25000,
        currency:       'MYR',
      },
    });

    const callArgs = vi.mocked(callbackWorker.schedulePaymentCallback).mock.calls[0][0];
    expect(callArgs.willSucceed).toBe(true);
    expect(callArgs.providerReference).toMatch(/^paypal_order_/);

    await app.close();
  });
});

describe('GET /internal/v1/payments/:id', () => {
  it('returns 404 for unknown payment ID', async () => {
    const app = await buildTestApp();

    const response = await app.inject({
      method: 'GET',
      url: '/internal/v1/payments/non-existent-id',
      headers: AUTH_HEADER,
    });

    expect(response.statusCode).toBe(404);
    await app.close();
  });
});

describe('GET /health', () => {
  it('returns 200 without authentication', async () => {
    const app = await buildTestApp();

    const response = await app.inject({
      method: 'GET',
      url: '/health',
    });

    expect(response.statusCode).toBe(200);
    const body = JSON.parse(response.body);
    expect(body.status).toBe('ok');

    await app.close();
  });
});
