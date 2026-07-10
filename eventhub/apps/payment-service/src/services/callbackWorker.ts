import crypto from 'crypto';
import { db } from '../db';

// ─── Types ────────────────────────────────────────────────────────────────────

interface CallbackJob {
  operationId: string;
  operationType: 'payment' | 'refund' | 'payout';
  providerReference: string;
  idempotencyKey: string;
  /** The aggregate ID that the Core API needs (order_id / payment_id / payout_id) */
  aggregateReference: string;
  amountMinor: number;
  currency: string;
  willSucceed: boolean;
  delayMs: number;
}

// Exponential backoff delays in ms: 1s, 4s, 16s, 64s
const RETRY_DELAYS_MS = [1_000, 4_000, 16_000, 64_000];
const MAX_RETRIES = RETRY_DELAYS_MS.length;

/**
 * CallbackWorker — simulates delayed payment provider webhooks back to Core API.
 *
 * Signing scheme (identical to Core API's WebhookController):
 *   X-Webhook-Id:        Unique event ID (prevents replay in Core API)
 *   X-Webhook-Timestamp: Unix timestamp (seconds)
 *   X-Webhook-Signature: HMAC-SHA256(`timestamp + "." + raw_body`)
 *
 * Payload shapes (match Core API WebhookController contract):
 *   Payment:  { payment_id, order_id, status, amount_minor, currency, provider_reference }
 *   Refund:   { refund_id, payment_id, status, amount_minor, currency, provider_reference }
 *   Payout:   { payout_id, vendor_id, status, amount_minor, currency, provider_reference }
 */
class CallbackWorker {
  private coreApiBaseUrl: string;
  private webhookSecret: string;

  constructor() {
    // Strip any trailing path — we build the full path per operation type
    const rawUrl = process.env.CORE_API_WEBHOOK_URL ?? 'http://core-api/api/v1/webhooks/payments';
    this.coreApiBaseUrl = rawUrl.replace(/\/api\/v1\/webhooks\/.*$/, '');
    this.webhookSecret = process.env.CORE_API_WEBHOOK_SECRET ?? 'webhook-hmac-secret';
  }

  // ─── Public scheduling API ─────────────────────────────────────────────────

  schedulePaymentCallback(job: CallbackJob): void {
    setTimeout(() => this.executeWithRetry(job, 0), job.delayMs);
  }

  scheduleRefundCallback(job: CallbackJob): void {
    setTimeout(() => this.executeWithRetry(job, 0), job.delayMs);
  }

  schedulePayoutCallback(job: CallbackJob): void {
    setTimeout(() => this.executeWithRetry(job, 0), job.delayMs);
  }

  // ─── Core execution + retry ────────────────────────────────────────────────

  private async executeWithRetry(job: CallbackJob, attempt: number): Promise<void> {
    try {
      await this.sendCallback(job);
    } catch (err) {
      const nextAttempt = attempt + 1;
      if (nextAttempt >= MAX_RETRIES) {
        console.error(
          `[CallbackWorker] All ${MAX_RETRIES} retry attempts exhausted for operation ${job.operationId}`,
          err
        );
        // Mark the operation as callback-failed so it can be retried manually
        await db.query(
          `UPDATE payment_operations SET callback_status = 'callback_failed', updated_at = NOW() WHERE id = $1`,
          [job.operationId]
        ).catch(() => {/* best-effort */});
        return;
      }

      const retryDelayMs = RETRY_DELAYS_MS[nextAttempt];
      console.warn(
        `[CallbackWorker] Attempt ${attempt + 1} failed for ${job.operationId}. Retrying in ${retryDelayMs}ms`,
        (err as Error).message
      );
      setTimeout(() => this.executeWithRetry(job, nextAttempt), retryDelayMs);
    }
  }

  // ─── Callback dispatch ────────────────────────────────────────────────────

  private async sendCallback(job: CallbackJob): Promise<void> {
    const status = job.willSucceed ? 'succeeded' : 'failed';
    const eventId = `evt_${job.operationType.slice(0, 3)}_${crypto.randomBytes(12).toString('hex')}`;

    // Build the payload matching Core API's WebhookController contract
    const payload = this.buildPayload(job, status, eventId);
    const path = this.getWebhookPath(job.operationType);
    const url = `${this.coreApiBaseUrl}${path}`;

    const body = JSON.stringify(payload);
    const timestamp = Math.floor(Date.now() / 1000).toString();
    const signature = crypto
      .createHmac('sha256', this.webhookSecret)
      .update(`${timestamp}.${body}`)
      .digest('hex');

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Id': eventId,
        'X-Webhook-Timestamp': timestamp,
        'X-Webhook-Signature': signature,
      },
      body,
      signal: AbortSignal.timeout(10_000), // 10 second timeout
    });

    if (!response.ok) {
      throw new Error(`Core API returned HTTP ${response.status} for ${path}`);
    }

    // Persist the callback outcome to payment_operations
    await db.query(
      `UPDATE payment_operations
       SET status = $1, callback_event_id = $2, callback_status = 'sent', updated_at = NOW()
       WHERE id = $3`,
      [status, eventId, job.operationId]
    );

    console.info(
      `[CallbackWorker] ${job.operationType} callback sent`,
      { operationId: job.operationId, status, eventId }
    );
  }

  // ─── Payload builders ─────────────────────────────────────────────────────

  private buildPayload(
    job: CallbackJob,
    status: string,
    eventId: string
  ): Record<string, unknown> {
    const base = {
      status,
      amount_minor: job.amountMinor,
      currency: job.currency,
      provider_reference: job.providerReference,
      event_id: eventId,
    };

    if (job.operationType === 'payment') {
      return {
        ...base,
        payment_id: job.operationId,
        order_id: job.aggregateReference,
      };
    }

    if (job.operationType === 'refund') {
      return {
        ...base,
        refund_id: job.operationId,
        payment_id: job.aggregateReference,
      };
    }

    // payout
    return {
      ...base,
      payout_id: job.operationId,
      vendor_id: job.aggregateReference,
    };
  }

  private getWebhookPath(operationType: 'payment' | 'refund' | 'payout'): string {
    const paths: Record<string, string> = {
      payment: '/api/v1/webhooks/payments',
      refund:  '/api/v1/webhooks/refunds',
      payout:  '/api/v1/webhooks/payouts',
    };
    return paths[operationType];
  }
}

export const callbackWorker = new CallbackWorker();
