// A minimal migration runner: applies the .sql files in ./migrations in name order,
// each in its own transaction, and records them in schema_migrations.
// Run it as the migration role (chapter 21), e.g.:
//   PGUSER=inkwell_migrate PGPASSWORD=migrate-dev-password node migrate.js
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import pg from 'pg';

const dir = fileURLToPath(new URL('./migrations/', import.meta.url));
const client = new pg.Client({ connectionString: process.env.DATABASE_URL, application_name: 'inkwell-migrate' });
await client.connect();

try {
  // Only one runner at a time, even if two deployments start together (advisory lock, chapter 17).
  await client.query("SELECT pg_advisory_lock(hashtext('inkwell-migrations'))");
  await client.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
                        version     text PRIMARY KEY,
                        applied_at  timestamptz NOT NULL DEFAULT now())`);

  const { rows } = await client.query('SELECT version FROM schema_migrations');
  const applied = new Set(rows.map((row) => row.version));
  const files = (await readdir(dir)).filter((name) => name.endsWith('.sql')).sort();

  let count = 0;
  for (const file of files) {
    if (applied.has(file)) continue;
    const sql = await readFile(join(dir, file), 'utf8');
    try {
      await client.query('BEGIN');
      await client.query(sql); // no parameters: the file may contain several statements
      await client.query('INSERT INTO schema_migrations (version) VALUES ($1)', [file]);
      await client.query('COMMIT');
    } catch (err) {
      await client.query('ROLLBACK');
      console.error(`migration ${file} failed: ${err.message} (SQLSTATE ${err.code})`);
      process.exitCode = 1; // stop here: later migrations may depend on this one
      break;
    }
    console.log(`applied ${file}`);
    count++;
  }
  if (!process.exitCode) console.log(count ? `${count} migration(s) applied` : 'database is up to date');
} finally {
  await client.end(); // closing the session also releases the advisory lock
}
