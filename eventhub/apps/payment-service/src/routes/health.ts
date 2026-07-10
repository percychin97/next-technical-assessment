import { FastifyInstance, FastifyReply, FastifyRequest } from 'fastify';
import { db } from '../db';

export async function healthRoutes(app: FastifyInstance): Promise<void> {
  app.get('/', async (_request: FastifyRequest, reply: FastifyReply) => {
    try {
      await db.query('SELECT 1');
      return reply.code(200).send({ status: 'ok', service: 'payment-service', db: 'ok' });
    } catch {
      return reply.code(503).send({ status: 'degraded', db: 'unreachable' });
    }
  });
}
