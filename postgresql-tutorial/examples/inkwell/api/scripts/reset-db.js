// Rebuilds a development or test database from scratch: roles (db/roles.sql), a new empty database, the
// migrations, and the sample data (db/seed.sql). The database name comes from DATABASE_URL.
// Runs automatically before npm test (as "pretest", with .env.test); run it by hand with npm run db:reset.
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import pg from 'pg';
import { requireEnv } from '../src/config.js';
import { migrate } from './migrate.js';

const dbDir = fileURLToPath(new URL('../../db/', import.meta.url));
const databaseUrl = requireEnv('DATABASE_URL');
const migrationUrl = requireEnv('MIGRATION_DATABASE_URL');
const adminUrl = requireEnv('ADMIN_DATABASE_URL');

const name = decodeURIComponent(new URL(databaseUrl).pathname.slice(1));
// This script drops the database. Refuse anything that doesn't look like a development or test database.
if (!/^inkwell_(dev|test)\w*$/.test(name)) {
  throw new Error(`refusing to reset "${name}": only inkwell_dev* and inkwell_test* databases are reset`);
}

// 1. Roles and the database, as a superuser. Same statements as db/create-database.sql.
const admin = new pg.Client({ connectionString: adminUrl });
await admin.connect();
try {
  await admin.query(await readFile(`${dbDir}/roles.sql`, 'utf8'));
  const db = admin.escapeIdentifier(name);
  await admin.query(`DROP DATABASE IF EXISTS ${db} WITH (FORCE)`);
  await admin.query(`CREATE DATABASE ${db} OWNER inkwell_owner TEMPLATE template0
                     ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'en'`);
  await admin.query(`ALTER DATABASE ${db} SET timezone TO 'UTC'`);
  await admin.query(`REVOKE ALL ON DATABASE ${db} FROM PUBLIC`);
  await admin.query(`GRANT CONNECT ON DATABASE ${db} TO inkwell_migrate`);
} finally {
  await admin.end();
}

// 2. Migrations and sample data, as the migration role.
await migrate(migrationUrl, { log: () => {} });
const client = new pg.Client({ connectionString: migrationUrl });
await client.connect();
try {
  await client.query('BEGIN');
  await client.query(await readFile(`${dbDir}/seed.sql`, 'utf8'));
  await client.query('COMMIT');
} finally {
  await client.end();
}
console.log(`database ${name} is ready`);
