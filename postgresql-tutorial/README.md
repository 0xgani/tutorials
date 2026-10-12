# The PostgreSQL Tutorial — from first query to production

A long-form, text-only tutorial that takes you from "what is a relational database?" to designing, querying, tuning,
securing and operating real PostgreSQL databases. It covers SQL in depth (queries, joins, aggregation, window
functions, CTEs, JSON, full-text search), how PostgreSQL works inside (MVCC, the planner, indexes, WAL, VACUUM), and the
work around the database (roles and row-level security, application access from Node.js and PHP, backups, replication
and monitoring). It closes with a capstone project that puts everything together.

The tutorial targets **PostgreSQL 18**, the current stable release (first released September 2025; every example was
run against 18.6). Features that arrived in recent releases are labelled with the version that introduced them, for
example "PostgreSQL 17+" or "PostgreSQL 18+", so you know what works on an older server. Anything without a label
works on every supported version (14 and newer).

## Who it's for

- You can program, and you have used a database at least through an ORM or a few hand-written queries. You do not need
  to know SQL well: chapter 1 starts from zero.
- You want to become *skilled* with PostgreSQL, not just able to get rows out of it: able to design a schema that
  protects your data, write the query that answers the real question, read a query plan, find out why the database is
  slow, and run it safely in production.

The main text is pure PostgreSQL: SQL and the `psql` command-line client. Wherever the tutorial shows how an
application talks to the database, it does so in **Node.js** (with the `pg` package) and **PHP** (with PDO).
Chapter 22 is entirely about application access; the capstone has a small Node.js API and a PHP command-line report.

## Prerequisites

| You need | Why | Check |
|---|---|---|
| PostgreSQL 18 (server and client tools) | Running every example | `psql --version`, `postgres --version` |
| A terminal | Everything runs from the command line with `psql` | |
| A text editor | Writing `.sql` files | |
| Node.js 22 or newer (chapters 22 and 24) | Application examples with the `pg` driver | `node --version` |
| PHP 8.3 or newer with `pdo_pgsql` (chapters 22 and 24) | Application examples with PDO | `php -m \| grep pdo_pgsql` |
| Optional: Docker | An alternative way to run the server (chapter 1) and the capstone's compose file | `docker --version` |

Chapter 1 shows how to install PostgreSQL on macOS, Linux and Windows, or run it in a container.

## How to use this tutorial

- **Read in order.** Each chapter builds on the previous ones, and the running example grows as you go.
- **Type the examples and run them in `psql`.** Where output matters, it is shown in a `text` block right after the
  query, exactly as `psql` prints it.
- **Reset the database whenever you like.** From chapter 6 on, every chapter starts from the same known data. The
  scripts in [`sql/`](sql/) rebuild it in a second (chapter 5 explains how), so you can experiment freely.
- **Do the exercises.** Every chapter ends with 3–6 exercises from easy to hard. Solutions are hidden in collapsible
  "Solution" sections. Try first, then compare.
- **Keep the manual open.** The official documentation at <https://www.postgresql.org/docs/current/> is excellent and
  complete. This tutorial links to it where it matters.

### The running example: Inkwell

Throughout the tutorial you build the database behind **Inkwell**, a small online shop that sells pens, ink, paper and
notebooks. It starts as a single table in chapter 1, becomes a proper schema with constraints (customers, a category
tree, products, orders, order items, reviews), answers business questions with joins, aggregates and window functions,
gains JSON product attributes and full-text search, gets indexes and a million-row data set to tune against, learns to
handle concurrent orders safely, moves business rules into functions and triggers, is locked down with roles and
row-level security, and is finally backed up, replicated and monitored. The capstone (chapter 24) assembles the
complete database with migrations, plus the applications that use it; its full source is in
[`examples/inkwell/`](examples/inkwell/).

## Markers

One marker appears throughout. It always uses the same wording, so you can search for it.

> **Just a taste — you don't need to learn or fully understand this now.** This marks a snippet that only shows that a
> feature exists. A later chapter (named in the note) teaches it properly, or the feature is niche enough that knowing
> it exists is all you need.

Snippets without a marker are meant to be learned.

## Table of contents

### Part I — Foundations

1. [Getting started](01-getting-started.md) — what PostgreSQL is, versions and support, installing it, clusters,
   databases and connections, your first table and queries, what happens when you run a query.
2. [psql and the command-line tools](02-psql-and-the-command-line-tools.md) — connecting, describing objects with
   backslash commands, output formats, variables, scripts, `\copy`, `.psqlrc`, connection settings and `.pgpass`.
3. [Data types](03-data-types.md) — integers, `numeric` and floating point, text, booleans, dates and times, `uuid`
   (with `uuidv7()`, 18+), `bytea`, enums, arrays, `NULL`, casting, and how to choose.
4. [Tables and constraints](04-tables-and-constraints.md) — `CREATE TABLE`, identity columns, defaults, `NOT NULL`,
   `CHECK`, `UNIQUE`, primary and foreign keys, generated columns (virtual by default in 18+), `ALTER TABLE`, domains,
   and the Inkwell schema.
5. [Inserting, updating and deleting](05-inserting-updating-and-deleting.md) — `INSERT`, `RETURNING` (with
   `OLD`/`NEW`, 18+), `UPDATE ... FROM`, `DELETE ... USING`, upserts with `ON CONFLICT`, `MERGE`, `COPY`, and the
   reset scripts.

### Part II — Querying

6. [Querying with SELECT](06-querying-with-select.md) — filtering, sorting, paging, `DISTINCT` and `DISTINCT ON`,
   `CASE`, three-valued logic and `NULL`, pattern matching, and the logical order in which a query runs.
7. [Joins](07-joins.md) — inner, outer, cross and self joins, semi- and anti-joins, the `NOT IN` trap, `LATERAL`.
8. [Aggregation and grouping](08-aggregation-and-grouping.md) — aggregate functions, `GROUP BY`, `HAVING`, `FILTER`,
   `string_agg` and `array_agg`, `ROLLUP`/`CUBE`/`GROUPING SETS`, ordered-set aggregates.
9. [Subqueries and common table expressions](09-subqueries-and-ctes.md) — scalar, correlated and `EXISTS`
   subqueries, `WITH`, recursive queries over the category tree, data-modifying CTEs.
10. [Window functions](10-window-functions.md) — `OVER`, partitions, frames, ranking, `lag`/`lead`, running totals,
    top-N per group, gaps and islands.
11. [Text, dates and times](11-text-dates-and-times.md) — string functions, regular expressions, collations
    (`casefold()`, 18+), `timestamptz` and time zones, intervals, `date_trunc`, `generate_series` reports.
12. [JSON, JSONB and arrays](12-json-jsonb-and-arrays.md) — `jsonb` operators, containment, SQL/JSON path, `JSON_TABLE`
    and the SQL/JSON query functions (17+), building JSON, array functions (`array_sort()`, 18+), when to use which.
13. [Full-text search](13-full-text-search.md) — `tsvector` and `tsquery`, parsing and stemming, ranking,
    highlighting, `websearch_to_tsquery`, fuzzy matching with `pg_trgm`.

### Part III — Design and performance

14. [Data modeling](14-data-modeling.md) — normalization, keys, relationships, hierarchies, JSON vs. columns, range
    types and exclusion constraints, temporal keys with `WITHOUT OVERLAPS` (18+), schemas.
15. [Indexes](15-indexes.md) — how B-trees work, multicolumn, partial, expression and covering indexes, skip scan
    (18+), GIN, GiST, BRIN, building indexes without downtime, finding missing and unused indexes.
16. [Query planning and EXPLAIN](16-query-planning-and-explain.md) — the planner and its statistics, reading
    `EXPLAIN ANALYZE`, scan and join methods, estimate errors, extended statistics, `pg_stat_statements`.
17. [Transactions and concurrency](17-transactions-and-concurrency.md) — ACID, MVCC, isolation levels and their
    anomalies, row locks, `SKIP LOCKED` job queues, deadlocks, advisory locks, safe schema changes.
18. [Storage, VACUUM and maintenance](18-storage-vacuum-and-maintenance.md) — pages and tuples, TOAST, WAL and
    checkpoints, VACUUM and autovacuum, bloat, HOT updates, transaction ID wraparound, the statistics views.

### Part IV — Server-side programming and production

19. [Functions, procedures and triggers](19-functions-procedures-and-triggers.md) — SQL and PL/pgSQL functions,
    volatility, procedures with transaction control, triggers, auditing, `LISTEN`/`NOTIFY`.
20. [Views, materialized views and partitioning](20-views-materialized-views-and-partitioning.md) — views and updatable
    views, materialized views and concurrent refresh, declarative partitioning, partition pruning and maintenance.
21. [Roles, privileges and row-level security](21-roles-privileges-and-row-level-security.md) — roles and
    membership, `GRANT`, default privileges, schemas and `search_path` safety, row-level security policies,
    `pg_hba.conf`, authentication and TLS.
22. [Using PostgreSQL from Node.js and PHP](22-using-postgresql-from-node-and-php.md) — connections and pools,
    parameters, type mapping, transactions and retries, error codes, keyset pagination, connection poolers,
    migrations.
23. [Backup, replication and operations](23-backup-replication-and-operations.md) — `pg_dump` and `pg_restore`,
    physical backups and point-in-time recovery, incremental backups (17+), streaming and logical replication, upgrades,
    configuration, monitoring.
24. [Capstone project: Inkwell](24-capstone-project.md) — the complete database with migrations, roles, functions and
    reports, plus a Node.js API, a PHP report and tests.

## Repository layout

```text
postgresql-tutorial/
  README.md              # this file
  01-...md … 24-...md    # the chapters
  sql/                   # reset.sql, schema.sql, seed.sql, bulk.sql: rebuild the Inkwell database
  examples/              # runnable application code (chapter 22 and the capstone)
```

## Conventions

- Code blocks are tagged with their language: `sql` for SQL and `psql` commands, `bash` for shell commands, `text` for
  output, `js` for Node.js, `php` for PHP, and so on.
- Shell commands start without a prompt character, so you can paste them directly. SQL blocks are meant to be typed or
  pasted at the `psql` prompt (`inkwell=#`), which is also left out.
- Output is shown as `psql` prints it with its default settings. Error messages are shown as they appear in an
  interactive session (`ERROR:  ...`); when you run a script with `psql -f`, each line is prefixed with the file name
  and line number.
- Timings, cost estimates, process IDs and object IDs in plans and statistics depend on your machine. Where they appear
  in output, yours will differ; the chapter says what to look at.
- Keywords are written in upper case (`SELECT`, `WHERE`), names in lower case with underscores (`order_items`). That is
  a convention for readability; SQL keywords are case-insensitive.
