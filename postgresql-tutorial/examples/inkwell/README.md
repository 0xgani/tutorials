# Inkwell: the capstone project

The complete Inkwell shop from [chapter 24](../../24-capstone-project.md) of the PostgreSQL tutorial: the database
(roles, migrations, functions, triggers, row-level security, reports), a JSON API in Node.js, its tests, and a
reporting script in PHP. The chapter explains every part; this file is only the quick start.

```text
db/
  roles.sql              cluster-wide roles (run once per cluster, as a superuser)
  create-database.sql    creates a database owned by inkwell_owner
  migrations/            001_schema.sql ... 005_reporting.sql
  seed.sql               sample data
  docker-init.sh         used by compose.yaml
api/                     Node.js 22+: node:http and pg, no framework
  src/                   config.js, db.js, errors.js, app.js, server.js
  scripts/               migrate.js, reset-db.js
  test/                  node:test tests (API and database)
report/
  monthly-report.php     PHP 8.3+ with pdo_pgsql
compose.yaml             optional: PostgreSQL 18 and the API in containers
```

## Quick start

Needs PostgreSQL 18 (17 works too), Node.js 22.9 or newer, and a superuser login (here `postgres`).

```bash
cd api
npm install
cp .env.example .env              # adjust host, port and the superuser in ADMIN_DATABASE_URL
npm run db:reset                  # roles, database inkwell_dev, migrations, sample data
npm start                         # http://localhost:3000

cp .env.test.example .env.test
npm test                          # rebuilds inkwell_test, then runs the tests

cd ../report
php monthly-report.php            # uses PGHOST, PGPORT and PGPASSWORD like psql
```

The development passwords in `db/roles.sql` and the example files are for a local machine only.
