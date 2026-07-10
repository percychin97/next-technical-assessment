import { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { v4 as uuidv4 } from 'uuid';
import { db } from '../db';
import { getGateway } from '../gateways';
import { callbackWorker } from '../services/callbackWorker';

const createPayoutSchema = {
  body: {
    type: 'object',
    required: ['idempotencyKey', 'vendorId', 'amountMinor', 'currency', 'provider'],
    properties: {
      idempotencyKey: { type: 'string', minLength: 1, maxLength: 255 },
      vendorId:       { type: 'string', minLength: 1 },
      provider:       { type: 'string', enum: ['stripe_simulator', 'paypal_simulator'] },
      amountMinor:    { type: 'integer', minimum: 1 },
      currency:       { type: 'string', minLength: 3, maxLength: 3 },
      bankAccount: {
        type: 'object',
        required: ['accountHolder', 'bankName', 'maskedAccountNumber'],
        properties: {
          accountHolder:       { type: 'string' },
          bankName:            { type: 'string' },
          maskedAccountNumber: { type: 'string' },
        },
      },
    },
    additionalProperties: false,
  },
};

export async function payoutRoutes(app: FastifyInstance): Promise<void> {
  /**
   * POST /internal/v1/payouts
   * Idempotent. Payouts succeed by default (configurable via PAYOUT_FAIL_RATE).
   */
  app.post(
    '/',
    { schema: createPayoutSchema },
    async (request: FastifyRequest, reply: FastifyReply) => {
      const body = request.body as {
        idempotencyKey: string;
        vendorId: string;
        provider: 'stripe_simulator' | 'paypal_simulator';
        amountMinor: number;
        currency: string;
        bankAccount: {
          accountHolder: string;
          bankName: string;
          maskedAccountNumber: string;
        };
      };

      // Idempotency
      const existing = await db.queryOne<Record<string, unknown>>(
        `SELECT * FROM payment_operations WHERE idempotency_key = $1`,
        [body.idempotencyKey]
      );

      if (existing) {
        return reply.code(200).send({
          id:     existing['id'],
          status: existing['status'],
          message: 'Idempotent response',
        });
      }

      const gateway = getGateway(body.provider);
      const gatewayResult = await gateway.createPayout({
        idempotencyKey: body.idempotencyKey,
        vendorId:       body.vendorId,
        amountMinor:    body.amountMinor,
        currency:       body.currency,
        bankAccount:    body.bankAccount,
      });

      const id = uuidv4();
      await db.query(
        `INSERT INTO payment_operations
           (id, operation_type, idempotency_key, aggregate_reference, provider,
            amount_minor, currency, status, provider_reference, request_payload)
         VALUES ($1, 'payout', $2, $3, $4, $5, $6, 'pending', $7, $8)`,
        [
          id,
          body.idempotencyKey,
          body.vendorId,
          body.provider,
          body.amountMinor,
          body.currency,
          gatewayResult.providerReference,
          JSON.stringify(body),
        ]
      );

      const failRate = parseFloat(process.env.PAYOUT_FAIL_RATE ?? '0.02');
      const willSucceed = body.idempotencyKey.endsWith('_fail')
        ? false
        : body.idempotencyKey.endsWith('_success')
          ? true
          : Math.random() >= failRate;

      callbackWorker.schedulePayoutCallback({
        operationId:        id,
        operationType:      'payout',
        providerReference:  gatewayResult.providerReference,
        idempotencyKey:     body.idempotencyKey,
        aggregateReference: body.vendorId,   // ← vendor_id for Core API
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
   * GET /internal/v1/payouts/:id
   */
  app.get('/:id', async (request: FastifyRequest, reply: FastifyReply) => {
    const { id } = request.params as { id: string };

    const operation = await db.queryOne<Record<string, unknown>>(
      `SELECT * FROM payment_operations WHERE id = $1 AND operation_type = 'payout'`,
      [id]
    );

    if (!operation) {
      return reply.code(404).send({ error: 'Payout not found' });
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
