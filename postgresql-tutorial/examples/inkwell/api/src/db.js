// The connection pool and the transaction helpers (chapter 22).
import pg from 'pg';
import { config } from './config.js';

// bigint (int8) arrives as a string by default, because it can exceed JavaScript's safe integer range.
// Inkwell's ids and counts never get near 2^53, so parse them as numbers, but fail loudly if one ever does.
pg.types.setTypeParser(pg.types.builtins.INT8, (value) => {
  const number = Number(value);
  if (!Number.isSafeInteger(number)) throw new RangeError(`bigint ${value} doesn't fit in a JavaScript number`);
  return number;
});
// numeric stays a string ("48.00"): money must not become a binary floating-point number.

export const pool = new pg.Pool({
  connectionString: config.databaseUrl,
  application_name: 'inkwell-api',
  max: config.poolSize,
  idleTimeoutMillis: 30_000,
  connectionTimeoutMillis: 5_000,
});

// A broken idle connection is removed by the pool; without a listener its 'error' event would crash the process.
pool.on('error', (err) => console.error('idle database client error:', err.message));

const RETRYABLE = new Set(['40001', '40P01']); // serialization_failure, deadlock_detected

// Runs fn(client) in a transaction on one connection; retries serialization failures and deadlocks.
export async function withTransaction(fn, { isolation = 'READ COMMITTED', retries = 3 } = {}) {
  if (!['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'].includes(isolation)) {
    throw new Error(`unknown isolation level: ${isolation}`);
  }
  for (let attempt = 1; ; attempt++) {
    const client = await pool.connect();
    let broken = false;
    try {
      await client.query(`BEGIN ISOLATION LEVEL ${isolation}`);
      const result = await fn(client);
      await client.query('COMMIT');
      return result;
    } catch (err) {
      await client.query('ROLLBACK').catch(() => { broken = true; });
      if (RETRYABLE.has(err.code) && attempt <= retries) {
        await new Promise((resolve) => setTimeout(resolve, Math.random() * 20 * 2 ** attempt));
        continue;
      }
      throw err;
    } finally {
      client.release(broken);
    }
  }
}

// Runs fn(client) in a transaction as the signed-in customer: the portal role plus app.customer_id, which
// row-level security and the SECURITY DEFINER functions use (chapter 21). Both end with the transaction,
// so the pooled connection never carries one customer's identity into the next request.
export function asCustomer(customerId, fn, options) {
  return withTransaction(async (client) => {
    await client.query('SET LOCAL ROLE inkwell_portal');
    await client.query("SELECT set_config('app.customer_id', $1, true)", [String(customerId)]);
    return fn(client);
  }, options);
}
