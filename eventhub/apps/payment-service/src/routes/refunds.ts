import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { v4 as uuidv4 } from 'uuid';
import { db } from '../db';
import { getGateway } from '../gateways';
import { callbackWorker } from '../services/callbackWorker';

const createRefundSchema = {
  body: {
    type: 'object',
    required: ['idempotencyKey', 'paymentId', 'amountMinor', 'currency', 'provider'],
    properties: {
      idempotencyKey: { type: 'string', minLength: 1, maxLength: 255 },
      paymentId:      { type: 'string', minLength: 1 },
      orderId:        { type: 'string' },
      provider:       { type: 'string', enum: ['stripe_simulator', 'paypal_simulator'] },
      amountMinor:    { type: 'integer', minimum: 1 },
      currency:       { type: 'string', minLength: 3, maxLength: 3 },
      reason:         { type: 'string' },
    },
    additionalProperties: false,
  },
};

export async function refundRoutes(app: FastifyInstance): Promise<void> {
  /**
   * POST /internal/v1/refunds
   * Idempotent: same key returns the same result.
   * Refunds succeed by default in simulation (configurable via REFUND_FAIL_RATE).
   */
  app.post(
    '/',
    { schema: createRefundSchema },
    async (request: FastifyRequest, reply: FastifyReply) => {
      const body = request.body as {
        idempotencyKey: string;
        paymentId: string;
        orderId?: string;
        provider: 'stripe_simulator' | 'paypal_simulator';
        amountMinor: number;
        currency: string;
        reason?: string;
      };

      // Idempotency
      const existing = await db.queryOne<Record<string, unknown>>(
        `SELECT * FROM payment_operations WHERE idempotency_key = $1`,
        [body.idempotencyKey]
      );

      if (existing) {
        return reply.code(200).send({
          id:    existing['id'],
          status: existing['status'],
          message: 'Idempotent response',
        });
      }

      const gateway = getGateway(body.provider);
      const gatewayResult = await gateway.createRefund({
        idempotencyKey: body.idempotencyKey,
        paymentId:      body.paymentId,
        amountMinor:    body.amountMinor,
        currency:       body.currency,
        reason:         body.reason,
      });

      const id = uuidv4();
      await db.query(
        `INSERT INTO payment_operations
           (id, operation_type, idempotency_key, aggregate_reference, provider,
            amount_minor, currency, status, provider_reference, request_payload)
         VALUES ($1, 'refund', $2, $3, $4, $5, $6, 'pending', $7, $8)`,
        [
          id,
          body.idempotencyKey,
          body.paymentId,
          body.provider,
          body.amountMinor,
          body.currency,
          gatewayResult.providerReference,
          JSON.stringify(body),
        ]
      );

      // Refund failure rate (default 5% — simulating occasional provider failures)
      const failRate = parseFloat(process.env.REFUND_FAIL_RATE ?? '0.05');
      const willSucceed = body.idempotencyKey.endsWith('_fail')
        ? false
        : body.idempotencyKey.endsWith('_success')
          ? true
          : Math.random() >= failRate;

      callbackWorker.scheduleRefundCallback({
        operationId:        id,
        operationType:      'refund',
        providerReference:  gatewayResult.providerReference,
        idempotencyKey:     body.idempotencyKey,
        aggregateReference: body.paymentId,   // ← payment_id for Core API
        amountMinor:        body.amountMinor,
        currency:           body.currency,
        willSucceed,
        delayMs:            gatewayResult.callbackDelayMs,
      });

      return reply.code(202).send({
        id,
        status:           'pending',
        providerReference: gatewayResult.providerReference,
      });
    }
  );

  /**
   * GET /internal/v1/refunds/:id
   */
  app.get('/:id', async (request: FastifyRequest, reply: FastifyReply) => {
    const { id } = request.params as { id: string };

    const operation = await db.queryOne<Record<string, unknown>>(
      `SELECT * FROM payment_operations WHERE id = $1 AND operation_type = 'refund'`,
      [id]
    );

    if (!operation) {
      return reply.code(404).send({ error: 'Refund not found' });
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
