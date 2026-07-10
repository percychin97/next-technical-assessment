import { Pool, PoolClient } from 'pg';

const pool = new Pool({
  connectionString: process.env.DATABASE_URL,
});

export const db = {
  async connect(): Promise<void> {
    const client = await pool.connect();
    client.release();
  },

  async query<T = unknown>(text: string, values?: unknown[]): Promise<T[]> {
    const result = await pool.query(text, values);
    return result.rows as T[];
  },

  async queryOne<T = unknown>(text: string, values?: unknown[]): Promise<T | null> {
    const result = await pool.query(text, values);
    return (result.rows[0] as T) ?? null;
  },

  async transaction<T>(fn: (client: PoolClient) => Promise<T>): Promise<T> {
    const client = await pool.connect();
    try {
      await client.query('BEGIN');
      const result = await fn(client);
      await client.query('COMMIT');
      return result;
    } catch (err) {
      await client.query('ROLLBACK');
      throw err;
    } finally {
      client.release();
    }
  },

  async runMigrations(): Promise<void> {
    await pool.query(`
      CREATE TABLE IF NOT EXISTS payment_operations (
        id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
        operation_type VARCHAR(20) NOT NULL CHECK (operation_type IN ('payment', 'refund', 'payout')),
        idempotency_key VARCHAR(255) UNIQUE NOT NULL,
        aggregate_reference VARCHAR(255) NOT NULL,
        provider VARCHAR(50) NOT NULL,
        amount_minor BIGINT NOT NULL,
        currency VARCHAR(3) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        provider_reference VARCHAR(255),
        callback_event_id VARCHAR(255),
        callback_status VARCHAR(50),
        request_payload JSONB,
        response_payload JSONB,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
      );

      CREATE INDEX IF NOT EXISTS idx_payment_operations_idempotency
        ON payment_operations (idempotency_key);

      CREATE INDEX IF NOT EXISTS idx_payment_operations_status
        ON payment_operations (status, created_at DESC);
    `);
  },
};
