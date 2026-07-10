/**
 * Test helpers: in-memory DB stub so tests don't require a live PostgreSQL instance.
 *
 * We mock the `db` module before any route imports happen. Every test gets a
 * fresh store so there is no cross-test state leakage.
 */
import { vi } from 'vitest';

interface StoredRow {
  id: string;
  operation_type: string;
  idempotency_key: string;
  aggregate_reference: string;
  provider: string;
  amount_minor: number;
  currency: string;
  status: string;
  provider_reference: string;
  callback_status?: string;
  callback_event_id?: string;
  request_payload?: string;
  response_payload?: string;
  created_at: Date;
  updated_at: Date;
}

export function createMockDb() {
  const store = new Map<string, StoredRow>();

  const mockDb = {
    connect: vi.fn().mockResolvedValue(undefined),
    runMigrations: vi.fn().mockResolvedValue(undefined),

    query: vi.fn(async (sql: string, values?: unknown[]) => {
      if (sql.includes('INSERT INTO payment_operations')) {
        const row: StoredRow = {
          id:                  values![0] as string,
          operation_type:      values![1] as string,
          idempotency_key:     values![2] as string,
          aggregate_reference: values![3] as string,
          provider:            values![4] as string,
          amount_minor:        values![5] as number,
          currency:            values![6] as string,
          status:              'pending',
          provider_reference:  values![7] as string,
          request_payload:     values![8] as string,
          created_at:          new Date(),
          updated_at:          new Date(),
        };
        store.set(row.id, row);
        store.set(`idem:${row.idempotency_key}`, row);
        return [];
      }

      if (sql.includes('UPDATE payment_operations')) {
        // Best-effort update
        for (const row of store.values()) {
          if (row.id === values?.[values.length - 1]) {
            Object.assign(row, { updated_at: new Date() });
          }
        }
        return [];
      }

      return [];
    }),

    queryOne: vi.fn(async (sql: string, values?: unknown[]) => {
      if (sql.includes('idempotency_key')) {
        const key = values![0] as string;
        return store.get(`idem:${key}`) ?? null;
      }
      if (sql.includes('WHERE id')) {
        const id = values![0] as string;
        return store.get(id) ?? null;
      }
      return null;
    }),

    transaction: vi.fn(async (fn: (client: unknown) => Promise<unknown>) => {
      return fn({});
    }),

    // Expose store for test assertions
    _store: store,
    _clear: () => store.clear(),
  };

  return mockDb;
}
