import { FastifyError, FastifyRequest, FastifyReply } from 'fastify';

export function errorHandler(
  error: FastifyError,
  request: FastifyRequest,
  reply: FastifyReply
): void {
  request.log.error({ err: error, url: request.url }, 'Request error');

  if (error.statusCode) {
    reply.code(error.statusCode).send({
      error: error.message,
      code: error.code,
    });
    return;
  }

  reply.code(500).send({
    error: 'Internal server error',
    code: 'INTERNAL_ERROR',
  });
}
