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
  process.env.PAYOUT_FAIL_RATE = '0.0'; // always succeed unless _fail key
  mockDb._clear();
});

afterEach(() => vi.clearAllMocks());

const AUTH_HEADER = { Authorization: 'Bearer test-token' };
const BANK = { accountHolder: 'Test Vendor', bankName: 'Maybank', maskedAccountNumber: '****1234' };

describe('POST /internal/v1/payouts', () => {
  it('returns 202 pending on valid payout request', async () => {
    const app = await buildApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/payouts',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'payout-001',
        vendorId:       'vendor-uuid-001',
        provider:       'stripe_simulator',
        amountMinor:    50000,
        currency:       'MYR',
        bankAccount:    BANK,
      },
    });

    expect(response.statusCode).toBe(202);
    const body = JSON.parse(response.body);
    expect(body.status).toBe('pending');
    expect(body.providerReference).toMatch(/^stripe_po_/);

    await app.close();
  });

  it('is idempotent — same key returns the same record', async () => {
    const app = await buildApp();
    const payload = {
      idempotencyKey: 'payout-idem-001',
      vendorId:       'vendor-uuid-002',
      provider:       'stripe_simulator',
      amountMinor:    100000,
      currency:       'MYR',
      bankAccount:    BANK,
    };
    const headers = { ...AUTH_HEADER, 'Content-Type': 'application/json' };

    const r1 = await app.inject({ method: 'POST', url: '/internal/v1/payouts', headers, payload });
    const r2 = await app.inject({ method: 'POST', url: '/internal/v1/payouts', headers, payload });

    expect(r1.statusCode).toBe(202);
    expect(r2.statusCode).toBe(200);
    expect(JSON.parse(r1.body).id).toBe(JSON.parse(r2.body).id);

    await app.close();
  });

  it('schedules callback with vendorId as aggregateReference', async () => {
    const { callbackWorker } = await import('../src/services/callbackWorker');
    const app = await buildApp();

    await app.inject({
      method: 'POST',
      url: '/internal/v1/payouts',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'payout-callback-001',
        vendorId:       'vendor-uuid-003',
        provider:       'paypal_simulator',
        amountMinor:    75000,
        currency:       'MYR',
        bankAccount:    BANK,
      },
    });

    const args = vi.mocked(callbackWorker.schedulePayoutCallback).mock.calls[0][0];
    expect(args.aggregateReference).toBe('vendor-uuid-003');
    expect(args.operationType).toBe('payout');
    expect(args.willSucceed).toBe(true);
    expect(args.providerReference).toMatch(/^paypal_payout_/);

    await app.close();
  });

  it('returns 400 when bankAccount is missing', async () => {
    const app = await buildApp();

    const response = await app.inject({
      method: 'POST',
      url: '/internal/v1/payouts',
      headers: { ...AUTH_HEADER, 'Content-Type': 'application/json' },
      payload: {
        idempotencyKey: 'payout-bad-001',
        vendorId:       'vendor-uuid-004',
        provider:       'stripe_simulator',
        amountMinor:    50000,
        currency:       'MYR',
        // Missing bankAccount
      },
    });

    expect(response.statusCode).toBe(400);
    await app.close();
  });
});
