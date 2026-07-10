import { v4 as uuidv4 } from 'uuid';
import {
  PaymentGateway,
  CreatePaymentRequest,
  CreateRefundRequest,
  CreatePayoutRequest,
  GatewayResult,
} from '../types';

/**
 * PayPalSimulator — simulates PayPal payment processing.
 *
 * Configurable via environment variables:
 *   PAYPAL_SUCCESS_RATE      — float 0–1, default 0.85
 *   PAYPAL_CALLBACK_DELAY_MS — milliseconds before webhook fires, default 5000
 */
export class PayPalSimulator implements PaymentGateway {
  private successRate: number;
  private callbackDelayMs: number;

  constructor() {
    this.successRate = parseFloat(process.env.PAYPAL_SUCCESS_RATE ?? '0.85');
    this.callbackDelayMs = parseInt(process.env.PAYPAL_CALLBACK_DELAY_MS ?? '5000', 10);
  }

  async createPayment(operation: CreatePaymentRequest): Promise<GatewayResult> {
    const willSucceed = this.simulateOutcome(operation.idempotencyKey);
    return {
      providerReference: `paypal_order_${uuidv4().replace(/-/g, '').toUpperCase()}`,
      status: 'pending',
      callbackDelayMs: willSucceed ? this.callbackDelayMs : this.callbackDelayMs * 0.4,
    };
  }

  async createRefund(operation: CreateRefundRequest): Promise<GatewayResult> {
    const willSucceed = this.simulateOutcome(operation.idempotencyKey);
    return {
      providerReference: `paypal_refund_${uuidv4().replace(/-/g, '').toUpperCase()}`,
      status: 'pending',
      callbackDelayMs: willSucceed ? this.callbackDelayMs : this.callbackDelayMs * 0.4,
    };
  }

  async createPayout(operation: CreatePayoutRequest): Promise<GatewayResult> {
    const willSucceed = this.simulateOutcome(operation.idempotencyKey);
    return {
      providerReference: `paypal_payout_${uuidv4().replace(/-/g, '').toUpperCase()}`,
      status: 'pending',
      callbackDelayMs: willSucceed ? this.callbackDelayMs * 3 : this.callbackDelayMs,
    };
  }

  async getOperation(providerReference: string): Promise<GatewayResult | null> {
    return null;
  }

  private simulateOutcome(idempotencyKey: string): boolean {
    if (process.env.NODE_ENV === 'test') {
      if (idempotencyKey.endsWith('_fail')) return false;
      if (idempotencyKey.endsWith('_success')) return true;
    }
    return Math.random() < this.successRate;
  }
}
