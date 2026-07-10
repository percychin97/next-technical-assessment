import Fastify from 'fastify';
import helmet from '@fastify/helmet';
import cors from '@fastify/cors';
import { paymentRoutes } from './routes/payments';
import { refundRoutes } from './routes/refunds';
import { payoutRoutes } from './routes/payouts';
import { healthRoutes } from './routes/health';
import { authMiddleware } from './middleware/auth';
import { errorHandler } from './middleware/errorHandler';
import { db } from './db';

const PORT = parseInt(process.env.PAYMENT_SERVICE_PORT ?? '3001', 10);
const HOST = process.env.HOST ?? '0.0.0.0';

export async function buildApp() {
  const app = Fastify({
    logger: {
      level: process.env.LOG_LEVEL ?? 'info',
    },
  });

  // Security
  await app.register(helmet);
  await app.register(cors, { origin: false });

  // Error handler
  app.setErrorHandler(errorHandler);

  // Health (unauthenticated)
  await app.register(healthRoutes, { prefix: '/health' });

  // Internal API routes (authenticated)
  await app.register(async (instance) => {
    instance.addHook('onRequest', authMiddleware);
    await instance.register(paymentRoutes, { prefix: '/internal/v1/payments' });
    await instance.register(refundRoutes, { prefix: '/internal/v1/refunds' });
    await instance.register(payoutRoutes, { prefix: '/internal/v1/payouts' });
  });

  return app;
}

async function main() {
  const app = await buildApp();

  try {
    // Initialize database
    await db.connect();
    app.log.info('Database connected');

    // Run migrations
    await db.runMigrations();
    app.log.info('Migrations complete');

    await app.listen({ port: PORT, host: HOST });
    app.log.info(`Payment service running on http://${HOST}:${PORT}`);
  } catch (err) {
    app.log.error(err);
    process.exit(1);
  }
}

main();
