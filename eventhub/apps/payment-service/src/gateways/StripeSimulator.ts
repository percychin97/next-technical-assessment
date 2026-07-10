import { v4 as uuidv4 } from 'uuid';
import {
  PaymentGateway,
  CreatePaymentRequest,
  CreateRefundRequest,
  CreatePayoutRequest,
  GatewayResult,
} from '../types';

/**
 * StripeSimulator — simulates Stripe payment processing.
 *
 * Configurable via environment variables:
 *   STRIPE_SUCCESS_RATE      — float 0–1, default 0.9
 *   STRIPE_CALLBACK_DELAY_MS — milliseconds before webhook fires, default 3000
 */
export class StripeSimulator implements PaymentGateway {
  private successRate: number;
  private callbackDelayMs: number;

  constructor() {
    this.successRate = parseFloat(process.env.STRIPE_SUCCESS_RATE ?? '0.9');
    this.callbackDelayMs = parseInt(process.env.STRIPE_CALLBACK_DELAY_MS ?? '3000', 10);
  }

  async createPayment(operation: CreatePaymentRequest): Promise<GatewayResult> {
    const willSucceed = this.simulateOutcome(operation.idempotencyKey);
    return {
      providerReference: `stripe_pi_${uuidv4().replace(/-/g, '')}`,
      status: 'pending',
      callbackDelayMs: willSucceed ? this.callbackDelayMs : this.callbackDelayMs * 0.5,
    };
  }

  async createRefund(operation: CreateRefundRequest): Promise<GatewayResult> {
    const willSucceed = this.simulateOutcome(operation.idempotencyKey);
    return {
      providerReference: `stripe_re_${uuidv4().replace(/-/g, '')}`,
      status: 'pending',
      callbackDelayMs: willSucceed ? this.callbackDelayMs : this.callbackDelayMs * 0.5,
    };
  }

  async createPayout(operation: CreatePayoutRequest): Promise<GatewayResult> {
    const willSucceed = this.simulateOutcome(operation.idempotencyKey);
    return {
      providerReference: `stripe_po_${uuidv4().replace(/-/g, '')}`,
      status: 'pending',
      callbackDelayMs: willSucceed ? this.callbackDelayMs * 2 : this.callbackDelayMs,
    };
  }

  async getOperation(providerReference: string): Promise<GatewayResult | null> {
    // In a real implementation this would call the Stripe API
    return null;
  }

  /**
   * Deterministic outcome seeded from idempotency key for test predictability.
   * In non-test mode uses random with configured success rate.
   */
  private simulateOutcome(idempotencyKey: string): boolean {
    if (process.env.NODE_ENV === 'test') {
      // Deterministic: keys ending in '_fail' always fail
      if (idempotencyKey.endsWith('_fail')) return false;
      if (idempotencyKey.endsWith('_success')) return true;
    }
    return Math.random() < this.successRate;
  }
}
