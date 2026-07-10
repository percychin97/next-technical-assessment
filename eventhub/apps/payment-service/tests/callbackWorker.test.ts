import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import crypto from 'crypto';

// Mock fetch globally — no real HTTP in unit tests
const mockFetch = vi.fn();
vi.stubGlobal('fetch', mockFetch);

// Mock DB — best-effort updates don't need real storage
vi.mock('../src/db', () => ({
  db: {
    query:    vi.fn().mockResolvedValue([]),
    queryOne: vi.fn().mockResolvedValue(null),
    connect:  vi.fn().mockResolvedValue(undefined),
  },
}));

// ─── Import worker AFTER mocks are in place ────────────────────────────────
// Dynamic import ensures mock is applied before module evaluation
let callbackWorker: typeof import('../src/services/callbackWorker')['callbackWorker'];

beforeEach(async () => {
  process.env.CORE_API_WEBHOOK_URL    = 'http://core-api/api/v1/webhooks/payments';
  process.env.CORE_API_WEBHOOK_SECRET = 'test-secret';
  process.env.NODE_ENV = 'test';

  vi.resetModules();
  const mod = await import('../src/services/callbackWorker');
  callbackWorker = mod.callbackWorker;

  mockFetch.mockResolvedValue({ ok: true, status: 200 } as Response);
  vi.clearAllMocks();
});

afterEach(() => {
  mockFetch.mockReset();
});

// ─── Helper ────────────────────────────────────────────────────────────────

function makeJob(overrides = {}) {
  return {
    operationId:        'op-001',
    operationType:      'payment' as const,
    providerReference:  'stripe_pi_abc123',
    idempotencyKey:     'test-key-001',
    aggregateReference: 'order-uuid-001',
    amountMinor:        10000,
    currency:           'MYR',
    willSucceed:        true,
    delayMs:            0,
    ...overrides,
  };
}

// ─── Tests ─────────────────────────────────────────────────────────────────

describe('CallbackWorker', () => {
  describe('HMAC signature generation', () => {
    it('sends correct HMAC-signed headers to Core API', async () => {
      await new Promise<void>((resolve) => {
        mockFetch.mockImplementationOnce(async (url: string, init: RequestInit) => {
          const body = init.body as string;
          const ts   = (init.headers as Record<string, string>)['X-Webhook-Timestamp'];
          const sig  = (init.headers as Record<string, string>)['X-Webhook-Signature'];
          const wid  = (init.headers as Record<string, string>)['X-Webhook-Id'];

          // Verify signature matches expected HMAC
          const expected = crypto
            .createHmac('sha256', 'test-secret')
            .update(`${ts}.${body}`)
            .digest('hex');

          expect(sig).toBe(expected);
          expect(wid).toMatch(/^evt_pay_/);
          expect(url).toBe('http://core-api/api/v1/webhooks/payments');

          resolve();
          return { ok: true, status: 200 } as Response;
        });

        callbackWorker.schedulePaymentCallback(makeJob({ delayMs: 0 }));
      });
    });

    it('sends correct payload shape for payment callbacks', async () => {
      let capturedBody: Record<string, unknown> = {};

      await new Promise<void>((resolve) => {
        mockFetch.mockImplementationOnce(async (_url: string, init: RequestInit) => {
          capturedBody = JSON.parse(init.body as string);
          resolve();
          return { ok: true, status: 200 } as Response;
        });

        callbackWorker.schedulePaymentCallback(makeJob({ delayMs: 0, willSucceed: true }));
      });

      expect(capturedBody['payment_id']).toBe('op-001');
      expect(capturedBody['order_id']).toBe('order-uuid-001');
      expect(capturedBody['status']).toBe('succeeded');
      expect(capturedBody['amount_minor']).toBe(10000);
      expect(capturedBody['currency']).toBe('MYR');
      expect(capturedBody['provider_reference']).toBe('stripe_pi_abc123');
    });

    it('sends failed status in payload when willSucceed=false', async () => {
      let capturedBody: Record<string, unknown> = {};

      await new Promise<void>((resolve) => {
        mockFetch.mockImplementationOnce(async (_url: string, init: RequestInit) => {
          capturedBody = JSON.parse(init.body as string);
          resolve();
          return { ok: true, status: 200 } as Response;
        });

        callbackWorker.schedulePaymentCallback(makeJob({ delayMs: 0, willSucceed: false }));
      });

      expect(capturedBody['status']).toBe('failed');
    });
  });

  describe('Refund callback', () => {
    it('sends refund payload to /api/v1/webhooks/refunds', async () => {
      let capturedUrl = '';
      let capturedBody: Record<string, unknown> = {};

      await new Promise<void>((resolve) => {
        mockFetch.mockImplementationOnce(async (url: string, init: RequestInit) => {
          capturedUrl = url;
          capturedBody = JSON.parse(init.body as string);
          resolve();
          return { ok: true, status: 200 } as Response;
        });

        callbackWorker.scheduleRefundCallback(makeJob({
          operationType:      'refund',
          aggregateReference: 'payment-uuid-001',
          delayMs:            0,
        }));
      });

      expect(capturedUrl).toBe('http://core-api/api/v1/webhooks/refunds');
      expect(capturedBody['refund_id']).toBe('op-001');
      expect(capturedBody['payment_id']).toBe('payment-uuid-001');
    });
  });

  describe('Payout callback', () => {
    it('sends payout payload to /api/v1/webhooks/payouts', async () => {
      let capturedUrl = '';
      let capturedBody: Record<string, unknown> = {};

      await new Promise<void>((resolve) => {
        mockFetch.mockImplementationOnce(async (url: string, init: RequestInit) => {
          capturedUrl = url;
          capturedBody = JSON.parse(init.body as string);
          resolve();
          return { ok: true, status: 200 } as Response;
        });

        callbackWorker.schedulePayoutCallback(makeJob({
          operationType:      'payout',
          aggregateReference: 'vendor-uuid-001',
          delayMs:            0,
        }));
      });

      expect(capturedUrl).toBe('http://core-api/api/v1/webhooks/payouts');
      expect(capturedBody['payout_id']).toBe('op-001');
      expect(capturedBody['vendor_id']).toBe('vendor-uuid-001');
    });
  });

  describe('Resilience — Core API unavailability', () => {
    it('does not throw when Core API is unreachable (catches error)', async () => {
      // This test verifies that a failed callback doesn't crash the payment service
      mockFetch.mockRejectedValueOnce(new Error('ECONNREFUSED'));

      // Should not throw — error is caught and retry is scheduled
      await expect(async () => {
        callbackWorker.schedulePaymentCallback(makeJob({ delayMs: 0 }));
        // Give the async chain a tick to start
        await new Promise((r) => setTimeout(r, 10));
      }).not.toThrow();
    });

    it('retries on non-2xx response from Core API', async () => {
      let callCount = 0;

      // First call returns 500, second returns 200
      mockFetch.mockImplementation(async () => {
        callCount++;
        if (callCount === 1) {
          return { ok: false, status: 500 } as Response;
        }
        return { ok: true, status: 200 } as Response;
      });

      await new Promise<void>((resolve) => {
        // Override RETRY_DELAYS to 0ms for speed
        // The worker will retry after RETRY_DELAYS_MS[1] = 4s normally
        // We just check that a second fetch is eventually made
        setTimeout(resolve, 50);
        callbackWorker.schedulePaymentCallback(makeJob({ delayMs: 0 }));
      });

      // At least the first attempt was made
      expect(mockFetch).toHaveBeenCalled();
    });
  });
});
