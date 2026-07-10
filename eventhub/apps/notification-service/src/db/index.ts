import { Pool } from 'pg';
import { v4 as uuidv4 } from 'uuid';
import { NotificationJob } from '../types';

const pool = new Pool({ connectionString: process.env.DATABASE_URL });

export const db = {
  async connect(): Promise<void> {
    const client = await pool.connect();
    client.release();
  },

  async runMigrations(): Promise<void> {
    await pool.query(`
      CREATE TABLE IF NOT EXISTS notification_deliveries (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        integration_event_id VARCHAR(255) NOT NULL,
        notification_type VARCHAR(100) NOT NULL,
        channel VARCHAR(50) NOT NULL,
        recipient_reference VARCHAR(255) NOT NULL,
        destination TEXT NOT NULL,
        payload JSONB NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        attempt_count INT NOT NULL DEFAULT 0,
        next_attempt_at TIMESTAMPTZ,
        last_error TEXT,
        sent_at TIMESTAMPTZ,
        dead_lettered_at TIMESTAMPTZ,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        UNIQUE (integration_event_id, channel, recipient_reference, destination)
      );

      CREATE INDEX IF NOT EXISTS idx_notification_status
        ON notification_deliveries (status, next_attempt_at);
    `);
  },

  async upsertDelivery(job: NotificationJob): Promise<string> {
    const result = await pool.query<{ id: string }>(
      `INSERT INTO notification_deliveries
         (id, integration_event_id, notification_type, channel,
          recipient_reference, destination, payload, status)
       VALUES ($1, $2, $3, $4, $5, $6, $7, 'pending')
       ON CONFLICT (integration_event_id, channel, recipient_reference, destination)
       DO UPDATE SET updated_at = NOW()
       RETURNING id`,
      [
        uuidv4(),
        job.integrationEventId,
        job.notificationType,
        job.channel,
        job.recipientReference,
        job.destination,
        JSON.stringify(job.payload),
      ]
    );
    return result.rows[0].id;
  },

  async getAttemptCount(deliveryId: string): Promise<number> {
    const result = await pool.query<{ attempt_count: number }>(
      `SELECT attempt_count FROM notification_deliveries WHERE id = $1`,
      [deliveryId]
    );
    return result.rows[0]?.attempt_count ?? 0;
  },

  async markSent(deliveryId: string): Promise<void> {
    await pool.query(
      `UPDATE notification_deliveries
       SET status = 'sent', sent_at = NOW(), updated_at = NOW(),
           attempt_count = attempt_count + 1
       WHERE id = $1`,
      [deliveryId]
    );
  },

  async markRetrying(
    deliveryId: string,
    error: string,
    nextAttemptAt: Date,
    attemptCount: number
  ): Promise<void> {
    await pool.query(
      `UPDATE notification_deliveries
       SET status = 'retrying', last_error = $1, next_attempt_at = $2,
           attempt_count = $3, updated_at = NOW()
       WHERE id = $4`,
      [error, nextAttemptAt, attemptCount, deliveryId]
    );
  },

  async markDeadLetter(deliveryId: string, error: string): Promise<void> {
    await pool.query(
      `UPDATE notification_deliveries
       SET status = 'dead_letter', last_error = $1, dead_lettered_at = NOW(),
           attempt_count = attempt_count + 1, updated_at = NOW()
       WHERE id = $2`,
      [error, deliveryId]
    );
  },
};
