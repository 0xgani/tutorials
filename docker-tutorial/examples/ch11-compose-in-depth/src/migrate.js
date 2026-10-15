import pg from 'pg';
import { readdir, readFile } from 'node:fs/promises';

// Applies every migrations/*.sql file that hasn't been applied yet, in name order,
// each in its own transaction. Runs as a one-off container before the API starts.
const dir = new URL('../migrations/', import.meta.url);
const client = new pg.Client({ connectionString: process.env.DATABASE_URL });
await client.connect();

try {
  // Two migrate containers started at once must not both apply the same file.
  await client.query('SELECT pg_advisory_lock(20261015)');
  await client.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
    name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())`);
  const { rows } = await client.query('SELECT name FROM schema_migrations');
  const applied = new Set(rows.map((row) => row.name));
  const files = (await readdir(dir)).filter((name) => name.endsWith('.sql')).sort();

  for (const name of files.filter((file) => !applied.has(file))) {
    const sql = await readFile(new URL(name, dir), 'utf8');
    await client.query('BEGIN');
    try {
      await client.query(sql);
      await client.query('INSERT INTO schema_migrations (name) VALUES ($1)', [name]);
      await client.query('COMMIT');
      console.log(`applied ${name}`);
    } catch (err) {
      await client.query('ROLLBACK');
      throw new Error(`migration ${name} failed: ${err.message}`);
    }
  }
  console.log(`database is up to date (${files.length} migrations)`);
} finally {
  await client.end();
}
