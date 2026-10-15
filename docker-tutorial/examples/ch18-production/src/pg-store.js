import pg from 'pg';
import { logger } from './logger.js';

// Stores links in PostgreSQL. DATABASE_URL looks like
// postgres://user:password@host:5432/dbname; inside Compose the host is the
// service name of the database ("db").
export async function createPgStore(connectionString) {
  const pool = new pg.Pool({ connectionString, max: 10 });
  // An idle connection that the server closes (a database restart, a failover) emits
  // 'error' on the pool; without a listener, Node.js treats it as a crash.
  pool.on('error', (err) => logger.error({ err }, 'database connection error'));
  await waitForDatabase(pool);
  // The schema is created by the migrate job (src/migrate.js), not here.

  return {
    async create({ code, url }) {
      const { rows } = await pool.query(
        'INSERT INTO links (code, url) VALUES ($1, $2) RETURNING *', [code, url]);
      return toLink(rows[0]);
    },
    async get(code) {
      const { rows } = await pool.query('SELECT * FROM links WHERE code = $1', [code]);
      return rows[0] ? toLink(rows[0]) : null;
    },
    async resolve(code) {
      const { rows } = await pool.query('SELECT url FROM links WHERE code = $1', [code]);
      return rows[0]?.url ?? null;
    },
    async recordClick(code) {
      await pool.query('UPDATE links SET clicks = clicks + 1 WHERE code = $1', [code]);
    },
    close: () => pool.end(),
  };
}

// The database container may still be starting when the app starts.
// Retry for a while instead of crashing on the first ECONNREFUSED.
async function waitForDatabase(pool, attempts = 30) {
  for (let i = 1; ; i++) {
    try {
      await pool.query('SELECT 1');
      return;
    } catch (err) {
      if (i >= attempts) throw err;
      logger.warn({ reason: err.code ?? err.message, attempt: i, of: attempts }, 'database not ready');
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
  }
}

function toLink(row) {
  return {
    code: row.code,
    url: row.url,
    clicks: Number(row.clicks),
    createdAt: row.created_at.toISOString(),
  };
}
