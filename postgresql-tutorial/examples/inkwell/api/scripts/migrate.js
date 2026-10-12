// Applies db/migrations/*.sql in name order, each in its own transaction, and records them with a checksum
// in schema_migrations (chapter 22 and its exercise 5). Connects as inkwell_migrate (MIGRATION_DATABASE_URL).
//   npm run migrate
import { createHash } from 'node:crypto';
import { readdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import pg from 'pg';
import { requireEnv } from '../src/config.js';

const MIGRATIONS_DIR = fileURLToPath(new URL('../../db/migrations/', import.meta.url));

export async function migrate(connectionString, { log = console.log } = {}) {
  const client = new pg.Client({ connectionString, application_name: 'inkwell-migrate' });
  await client.connect();
  try {
    // One runner at a time, even when two deployments start together (advisory lock, chapter 17).
    await client.query("SELECT pg_advisory_lock(hashtext('inkwell-migrations'))");
    await client.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
                          version     text PRIMARY KEY,
                          checksum    text NOT NULL,
                          applied_at  timestamptz NOT NULL DEFAULT now())`);
    const { rows } = await client.query('SELECT version, checksum FROM schema_migrations');
    const applied = new Map(rows.map((row) => [row.version, row.checksum]));

    const files = (await readdir(MIGRATIONS_DIR)).filter((name) => name.endsWith('.sql')).sort();
    let count = 0;
    for (const file of files) {
      const sql = await readFile(join(MIGRATIONS_DIR, file), 'utf8');
      const checksum = createHash('sha256').update(sql).digest('hex');
      if (applied.has(file)) {
        // Applied migrations are history: change the schema with a new file, never by editing an old one.
        if (applied.get(file) !== checksum) throw new Error(`migration ${file} was changed after it was applied`);
        continue;
      }
      try {
        await client.query('BEGIN');
        await client.query(sql);
        await client.query('INSERT INTO schema_migrations (version, checksum) VALUES ($1, $2)', [file, checksum]);
        await client.query('COMMIT');
      } catch (err) {
        await client.query('ROLLBACK');
        throw new Error(`migration ${file} failed: ${err.message} (SQLSTATE ${err.code})`, { cause: err });
      }
      log(`applied ${file}`);
      count++;
    }
    return count;
  } finally {
    await client.end(); // ending the session also releases the advisory lock
  }
}

// Run from the command line (not when imported by reset-db.js).
if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    const count = await migrate(requireEnv('MIGRATION_DATABASE_URL'));
    console.log(count ? `${count} migration(s) applied` : 'database is up to date');
  } catch (err) {
    console.error(err.message);
    process.exitCode = 1;
  }
}
