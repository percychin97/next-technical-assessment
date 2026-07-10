import { db } from './db';
import { startConsumer } from './consumers/notificationConsumer';

const MAX_CONNECT_RETRIES = 10;
const CONNECT_RETRY_DELAY_MS = 3000;

async function connectWithRetry(retries: number): Promise<void> {
  for (let i = 1; i <= retries; i++) {
    try {
      await startConsumer();
      return;
    } catch (err) {
      if (i === retries) throw err;
      console.log(`[notification-service] RabbitMQ not ready (attempt ${i}/${retries}), retrying in ${CONNECT_RETRY_DELAY_MS}ms...`);
      await new Promise((r) => setTimeout(r, CONNECT_RETRY_DELAY_MS));
    }
  }
}

async function main() {
  console.log('[notification-service] Starting...');

  // Initialize database
  await db.connect();
  await db.runMigrations();
  console.log('[notification-service] Database ready');

  // Start consuming with retry
  await connectWithRetry(MAX_CONNECT_RETRIES);
  console.log('[notification-service] Consumer started successfully');
}

main().catch((err) => {
  console.error('[notification-service] Fatal error:', err);
  process.exit(1);
});
