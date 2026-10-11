// The connection pool and transaction helpers shared by the examples.
// Connection settings come from DATABASE_URL if it's set, otherwise from the standard
// PG* environment variables (PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD).
import pg from 'pg';

export const pool = new pg.Pool({
  connectionString: process.env.DATABASE_URL,
  application_name: 'inkwell-examples',
  max: 10,                        // at most 10 connections from this process
  idleTimeoutMillis: 30_000,      // close connections idle for 30 s
  connectionTimeoutMillis: 5_000, // fail if no connection is free within 5 s
});

// A connection sitting idle in the pool can fail (server restart, network trouble).
// The pool removes it; without a listener, the 'error' event would crash the process.
pool.on('error', (err) => {
  console.error('idle client error:', err.message);
});

const ISOLATION_LEVELS = new Set(['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE']);
const RETRYABLE = new Set(['40001', '40P01']); // serialization_failure, deadlock_detected

// Runs fn(client) inside a transaction on one pooled connection, and retries the whole
// transaction when PostgreSQL reports a serialization failure or a deadlock.
export async function withTransaction(fn, { isolation = 'READ COMMITTED', retries = 3, onRetry } = {}) {
  if (!ISOLATION_LEVELS.has(isolation)) throw new Error(`unknown isolation level: ${isolation}`);

  for (let attempt = 1; ; attempt++) {
    const client = await pool.connect();
    let broken = false;
    try {
      await client.query(`BEGIN ISOLATION LEVEL ${isolation}`);
      const result = await fn(client);
      await client.query('COMMIT');
      return result;
    } catch (err) {
      // If even ROLLBACK fails, the connection is unusable: don't return it to the pool.
      await client.query('ROLLBACK').catch(() => { broken = true; });
      if (RETRYABLE.has(err.code) && attempt <= retries) {
        onRetry?.(err, attempt);
        const delay = Math.random() * 20 * 2 ** attempt; // exponential backoff with jitter
        await new Promise((resolve) => setTimeout(resolve, delay));
        continue;
      }
      throw err;
    } finally {
      client.release(broken); // release(true) destroys the connection instead of reusing it
    }
  }
}

// Runs fn(client) in a transaction as the customer portal: row-level security (chapter 21)
// then limits every query to the given customer's rows. Both settings end with the transaction.
export function asCustomer(customerId, fn) {
  return withTransaction(async (client) => {
    await client.query('SET LOCAL ROLE inkwell_portal');
    await client.query("SELECT set_config('app.customer_id', $1, true)", [String(customerId)]);
    return fn(client);
  });
}
