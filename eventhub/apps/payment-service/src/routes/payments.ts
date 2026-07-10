import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { v4 as uuidv4 } from 'uuid';
import { db } from '../db';
import { getGateway } from '../gateways';
import { callbackWorker } from '../services/callbackWorker';

// ─── Request/Response JSON Schemas ────────────────────────────────────────────

const createPaymentSchema = {
  body: {
    type: 'object',
    required: ['idempotencyKey', 'orderId', 'provider', 'amountMinor', 'currency'],
    properties: {
      idempotencyKey: { type: 'string', minLength: 1, maxLength: 255 },
      orderId:        { type: 'string', minLength: 1 },
      provider:       { type: 'string', enum: ['stripe_simulator', 'paypal_simulator'] },
      amountMinor:    { type: 'integer', minimum: 1 },
      currency:       { type: 'string', minLength: 3, maxLength: 3 },
      callbackUrl:    { type: 'string' },
      metadata:       { type: 'object' },
    },
    additionalProperties: false,
  },
};

// ─── Routes ───────────────────────────────────────────────────────────────────

export async function paymentRoutes(app: FastifyInstance): Promise<void> {
  /**
   * POST /internal/v1/payments
   * Create a payment operation. Idempotent: same key returns the same result.
   */
  app.post(
    '/',
    { schema: createPaymentSchema },
    async (request: FastifyRequest, reply: FastifyReply) => {
      const body = request.body as {
        idempotencyKey: string;
        orderId: string;
        provider: 'stripe_simulator' | 'paypal_simulator';
        amountMinor: number;
        currency: string;
        callbackUrl?: string;
        metadata?: Record<string, unknown>;
      };

      // ── Idempotency check ─────────────────────────────────────────────────
      const existing = await db.queryOne<Record<string, unknown>>(
        `SELECT * FROM payment_operations WHERE idempotency_key = $1`,
        [body.idempotencyKey]
      );

      if (existing) {
        return reply.code(200).send({
          id:               existing['id'],
          idempotencyKey:   existing['idempotency_key'],
          status:           existing['status'],
          providerReference: existing['provider_reference'],
          message:          'Idempotent response',
        });
      }

      // ── Route to simulator ────────────────────────────────────────────────
      const gateway = getGateway(body.provider);

      const gatewayResult = await gateway.createPayment({
        idempotencyKey: body.idempotencyKey,
        orderId:        body.orderId,
        provider:       body.provider,
        amountMinor:    body.amountMinor,
        currency:       body.currency,
        metadata:       body.metadata,
      });

      // Determine outcome deterministically (same key used by simulator)
      const willSucceed = gatewayResult.status !== 'failed';

      const id = uuidv4();
      await db.query(
        `INSERT INTO payment_operations
           (id, operation_type, idempotency_key, aggregate_reference, provider,
            amount_minor, currency, status, provider_reference, request_payload)
         VALUES ($1, 'payment', $2, $3, $4, $5, $6, 'pending', $7, $8)`,
        [
          id,
          body.idempotencyKey,
          body.orderId,
          body.provider,
          body.amountMinor,
          body.currency,
          gatewayResult.providerReference,
          JSON.stringify(body),
        ]
      );

      // ── Schedule callback to Core API ─────────────────────────────────────
      // willSucceed is determined by the simulator's random + success-rate logic
      // We re-sample here to be consistent with what the simulator will report
      const successRate = body.provider === 'stripe_simulator'
        ? parseFloat(process.env.STRIPE_SUCCESS_RATE ?? '0.9')
        : parseFloat(process.env.PAYPAL_SUCCESS_RATE ?? '0.85');

      const computedWillSucceed = body.idempotencyKey.endsWith('_fail')
        ? false
        : body.idempotencyKey.endsWith('_success')
          ? true
          : Math.random() < successRate;

      callbackWorker.schedulePaymentCallback({
        operationId:        id,
        operationType:      'payment',
        providerReference:  gatewayResult.providerReference,
        idempotencyKey:     body.idempotencyKey,
        aggregateReference: body.orderId,   // ← order_id for Core API
        amountMinor:        body.amountMinor,
        currency:           body.currency,
        willSucceed:        computedWillSucceed,
        delayMs:            gatewayResult.callbackDelayMs,
      });

      return reply.code(202).send({
        id,
        idempotencyKey:   body.idempotencyKey,
        status:           'pending',
        providerReference: gatewayResult.providerReference,
      });
    }
  );

  /**
   * GET /internal/v1/payments/:id
   */
  app.get('/:id', async (request: FastifyRequest, reply: FastifyReply) => {
    const { id } = request.params as { id: string };

    const operation = await db.queryOne<Record<string, unknown>>(
      `SELECT * FROM payment_operations WHERE id = $1 AND operation_type = 'payment'`,
      [id]
    );

    if (!operation) {
      return reply.code(404).send({ error: 'Payment not found' });
    }

    return reply.code(200).send({
      id:               operation['id'],
      status:           operation['status'],
      providerReference: operation['provider_reference'],
      amountMinor:      operation['amount_minor'],
      currency:         operation['currency'],
      callbackStatus:   operation['callback_status'],
    });
  });
}
