import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { dispatch } from '../src/dispatchers';
import type { NotificationJob } from '../src/types';
import crypto from 'crypto';

// Mock global fetch for vendor webhook tests
const mockFetch = vi.fn();
vi.stubGlobal('fetch', mockFetch);

// Capture console.log output to assert email dispatch
const consoleLogs: string[] = [];
const originalConsoleLog = console.log;

beforeEach(() => {
  consoleLogs.length = 0;
  console.log = (...args: unknown[]) => {
    consoleLogs.push(args.map(String).join(' '));
  };
  mockFetch.mockResolvedValue({ ok: true, status: 200 });
});

afterEach(() => {
  console.log = originalConsoleLog;
  vi.clearAllMocks();
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeEmailJob(type: string, payload: Record<string, unknown> = {}): NotificationJob {
  return {
    integrationEventId:  `evt-${type}-001`,
    notificationType:    type as NotificationJob['notificationType'],
    channel:             'email',
    recipientReference:  'user-uuid-001',
    destination:         'test@example.com',
    payload,
  };
}

function makeWebhookJob(payload: Record<string, unknown> = {}): NotificationJob {
  return {
    integrationEventId:  'evt-webhook-001',
    notificationType:    'payout_completed',
    channel:             'vendor_webhook',
    recipientReference:  'vendor-uuid-001',
    destination:         'https://vendor.example.com/webhook',
    payload,
    vendorWebhookSecret: 'wh-secret-test',
  };
}

// ─── Email dispatch tests ─────────────────────────────────────────────────────

describe('dispatchEmail — template rendering', () => {
  it('renders order_confirmation with orderNumber and ticketCount', async () => {
    const job = makeEmailJob('order_confirmation', {
      orderNumber: 'EH-20240101-000001',
      ticketCount: 2,
    });

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('Order Confirmed'))).toBe(true);
    expect(consoleLogs.some(l => l.includes('EH-20240101-000001'))).toBe(true);
    expect(consoleLogs.some(l => l.includes('test@example.com'))).toBe(true);
  });

  it('renders event_reminder with eventTitle and startAt', async () => {
    const job = makeEmailJob('event_reminder', {
      eventTitle: 'Tech Expo 2024',
      startAt:    '2024-08-15T09:00:00Z',
    });

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('Tech Expo 2024'))).toBe(true);
    expect(consoleLogs.some(l => l.includes('Reminder'))).toBe(true);
  });

  it('renders payout_completed with netAmount, currency, period', async () => {
    const job = makeEmailJob('payout_completed', {
      netAmount:   45000,
      currency:    'MYR',
      periodStart: '2024-07-01',
      periodEnd:   '2024-07-31',
    });

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('Payout Completed'))).toBe(true);
    expect(consoleLogs.some(l => l.includes('MYR'))).toBe(true);
  });

  it('renders vendor_approved notification', async () => {
    const job = makeEmailJob('vendor_approved');

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('approved'))).toBe(true);
  });

  it('renders vendor_rejected with reason', async () => {
    const job = makeEmailJob('vendor_rejected', { reason: 'Incomplete documents' });

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('Incomplete documents'))).toBe(true);
  });

  it('renders refund_processed with orderNumber and amount', async () => {
    const job = makeEmailJob('refund_processed', {
      orderNumber: 'EH-20240101-000002',
      amount:      10000,
      currency:    'MYR',
    });

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('Refund Processed'))).toBe(true);
    expect(consoleLogs.some(l => l.includes('EH-20240101-000002'))).toBe(true);
  });

  it('renders event_cancelled with eventTitle', async () => {
    const job = makeEmailJob('event_cancelled', { eventTitle: 'Cancelled Expo' });

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('Cancelled Expo'))).toBe(true);
  });

  it('falls back to JSON payload for unknown notification type', async () => {
    const job = makeEmailJob('unknown_type', { some: 'data' });

    await dispatch(job);

    expect(consoleLogs.some(l => l.includes('EventHub Notification'))).toBe(true);
  });
});

// ─── Vendor webhook tests ─────────────────────────────────────────────────────

describe('dispatchVendorWebhook', () => {
  it('sends POST request to vendor webhook URL', async () => {
    const job = makeWebhookJob({ payout_id: 'payout-123', amount: 45000 });

    await dispatch(job);

    expect(mockFetch).toHaveBeenCalledOnce();
    const [url] = mockFetch.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('https://vendor.example.com/webhook');
  });

  it('sends HMAC-signed headers on vendor webhook', async () => {
    const job = makeWebhookJob({ payout_id: 'payout-456' });

    await dispatch(job);

    const [, init] = mockFetch.mock.calls[0] as [string, RequestInit];
    const headers = init.headers as Record<string, string>;

    expect(headers['Content-Type']).toBe('application/json');
    expect(headers['X-EventHub-Event-Id']).toMatch(/^notif_/);
    expect(headers['X-EventHub-Timestamp']).toBeTruthy();
    expect(headers['X-EventHub-Signature']).toBeTruthy();

    // Verify the HMAC is correct
    const timestamp = headers['X-EventHub-Timestamp'];
    const body      = init.body as string;
    const expected  = crypto
      .createHmac('sha256', 'wh-secret-test')
      .update(`${timestamp}.${body}`)
      .digest('hex');

    expect(headers['X-EventHub-Signature']).toBe(expected);
  });

  it('includes the job payload in the request body', async () => {
    const job = makeWebhookJob({ payout_id: 'payout-789', custom_field: 'test-value' });

    await dispatch(job);

    const [, init] = mockFetch.mock.calls[0] as [string, RequestInit];
    const body     = JSON.parse(init.body as string);
    expect(body.payout_id).toBe('payout-789');
    expect(body.custom_field).toBe('test-value');
  });

  it('throws when vendor webhook URL returns non-2xx', async () => {
    mockFetch.mockResolvedValueOnce({ ok: false, status: 503 });

    const job = makeWebhookJob({ payout_id: 'payout-fail' });

    await expect(dispatch(job)).rejects.toThrow('503');
  });

  it('throws when vendorWebhookSecret is missing', async () => {
    const job: NotificationJob = {
      ...makeWebhookJob(),
      vendorWebhookSecret: undefined,
    };

    await expect(dispatch(job)).rejects.toThrow('Vendor webhook secret is required');
  });
});

// ─── Channel routing ──────────────────────────────────────────────────────────

describe('dispatch channel routing', () => {
  it('throws for unknown channel', async () => {
    const job = {
      ...makeEmailJob('order_confirmation'),
      channel: 'sms' as NotificationJob['channel'],
    };

    await expect(dispatch(job)).rejects.toThrow('Unknown notification channel');
  });
});
