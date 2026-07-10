import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { buildApp } from '../src/index';
import { createMockDb } from './helpers/mockDb';

const mockDb = createMockDb();
vi.mock('../src/db', () => ({ db: mockDb }));

vi.mock('../src/services/callbackWorker', () => ({
  callbackWorker: {
    schedulePaymentCallback: vi.fn(),
    scheduleRefundCallback:  vi.fn(),
    schedulePayoutCallback:  vi.fn(),
  },
}));

beforeEach(() => {
  process.env.PAYMENT_SERVICE_TOKEN = 'test-token';
  process.env.REFUND_FAIL_RATE = '0.0'; // always succeed in tests unless key ends in _fail
  mockDb._clear();
});

afterEach(() => vi.clearAllMocks());

const AUTH_HEADER = { Authorization: 'Bearer test-token' };

describe('POST /internal/v1/refunds', () => {
  it('returns 202 pending on valid refund request', async () => {
    const app = await buildApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/refunds',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'refund-001',
        paymentId:      'payment-uuid-001',
        provider:       'stripe_simulator',
        amountMinor:    10000,
        currency:       'MYR',
        reason:         'Customer request',
      },
    });

    expect(response.statusCode).toBe(202);
    const body = JSON.parse(response.body);
    expect(body.status).toBe('pending');
    expect(body.providerReference).toMatch(/^stripe_re_/);

    await app.close();
  });

  it('is idempotent — same key returns the same record', async () => {
    const app = await buildApp();
    const payload = {
      idempotencyKey: 'refund-idem-001',
      paymentId:      'payment-uuid-002',
      provider:       'stripe_simulator',
      amountMinor:    5000,
      currency:       'MYR',
    };
    const headers = { ...AUTH_HEADER, 'Content-Type': 'application/json' };

    const r1 = await app.inject({ method: 'POST', url: '/internal/v1/refunds', headers, payload });
    const r2 = await app.inject({ method: 'POST', url: '/internal/v1/refunds', headers, payload });

    expect(r1.statusCode).toBe(202);
    expect(r2.statusCode).toBe(200);
    expect(JSON.parse(r1.body).id).toBe(JSON.parse(r2.body).id);

    await app.close();
  });

  it('schedules callback with paymentId as aggregateReference', async () => {
    const { callbackWorker } = await import('../src/services/callbackWorker');
    const app = await buildApp();

    await app.inject({
      method: 'POST',
      url: '/internal/v1/refunds',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'refund-callback-001',
        paymentId:      'payment-uuid-003',
        provider:       'stripe_simulator',
        amountMinor:    7500,
        currency:       'MYR',
      },
    });

    const args = vi.mocked(callbackWorker.scheduleRefundCallback).mock.calls[0][0];
    expect(args.aggregateReference).toBe('payment-uuid-003');
    expect(args.operationType).toBe('refund');
    expect(args.willSucceed).toBe(true); // REFUND_FAIL_RATE=0.0

    await app.close();
  });

  it('deterministically fails refund for _fail key suffix', async () => {
    const { callbackWorker } = await import('../src/services/callbackWorker');
    const app = await buildApp();

    await app.inject({
      method: 'POST',
      url: '/internal/v1/refunds',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'refund_fail',
        paymentId:      'payment-uuid-004',
        provider:       'stripe_simulator',
        amountMinor:    1000,
        currency:       'MYR',
      },
    });

    const args = vi.mocked(callbackWorker.scheduleRefundCallback).mock.calls[0][0];
    expect(args.willSucceed).toBe(false);

    await app.close();
  });

  it('returns 400 when required fields are missing', async () => {
    const app = await buildApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/refunds',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'refund-bad-001',
        // Missing paymentId, provider, amountMinor, currency
      },
    });

    expect(response.statusCode).toBe(400);
    await app.close();
  });
});
