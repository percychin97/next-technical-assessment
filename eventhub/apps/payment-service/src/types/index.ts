export interface PaymentOperation {
  id: string;
  operationType: 'payment' | 'refund' | 'payout';
  idempotencyKey: string;
  aggregateReference: string;
  provider: 'stripe_simulator' | 'paypal_simulator';
  amountMinor: bigint;
  currency: string;
  status: 'pending' | 'succeeded' | 'failed';
  providerReference?: string;
  callbackEventId?: string;
  callbackStatus?: string;
  requestPayload?: Record<string, unknown>;
  responsePayload?: Record<string, unknown>;
  createdAt: Date;
  updatedAt: Date;
}

export interface CreatePaymentRequest {
  idempotencyKey: string;
  orderId: string;
  provider: 'stripe_simulator' | 'paypal_simulator';
  amountMinor: number;
  currency: string;
  metadata?: Record<string, unknown>;
}

export interface CreateRefundRequest {
  idempotencyKey: string;
  paymentId: string;
  amountMinor: number;
  currency: string;
  reason?: string;
}

export interface CreatePayoutRequest {
  idempotencyKey: string;
  vendorId: string;
  amountMinor: number;
  currency: string;
  bankAccount: {
    accountHolder: string;
    bankName: string;
    maskedAccountNumber: string;
  };
}

export interface PaymentGateway {
  createPayment(operation: CreatePaymentRequest): Promise<GatewayResult>;
  createRefund(operation: CreateRefundRequest): Promise<GatewayResult>;
  createPayout(operation: CreatePayoutRequest): Promise<GatewayResult>;
  getOperation(providerReference: string): Promise<GatewayResult | null>;
}

export interface GatewayResult {
  providerReference: string;
  status: 'pending' | 'succeeded' | 'failed';
  callbackDelayMs: number;
}
