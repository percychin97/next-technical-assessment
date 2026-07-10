import { FastifyRequest, FastifyReply } from 'fastify';

/**
 * Service-to-service authentication middleware.
 * Validates Bearer token against PAYMENT_SERVICE_TOKEN env variable.
 */
export async function authMiddleware(
  request: FastifyRequest,
  reply: FastifyReply
): Promise<void> {
  const serviceToken = process.env.PAYMENT_SERVICE_TOKEN;
  if (!serviceToken) {
    reply.code(500).send({ error: 'Service misconfigured: missing token' });
    return;
  }

  const authHeader = request.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    reply.code(401).send({ error: 'Unauthorized: missing or malformed token' });
    return;
  }

  const token = authHeader.slice(7);
  if (token !== serviceToken) {
    reply.code(401).send({ error: 'Unauthorized: invalid service token' });
    return;
  }
}
