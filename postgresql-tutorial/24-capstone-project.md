# Chapter 24 — Capstone project: Inkwell

## What you'll learn

- How the pieces of this tutorial fit together in one real project: schema, functions, triggers, roles, row-level
  security, reporting, migrations, application code and tests
- How to organize a database project: cluster-wide roles, database creation, numbered migrations, seed data
- Where to put business rules, and how the database can enforce them even when the application has a bug
- How a web API uses a pool, transactions, row-level security and error codes, request by request
- How to test a database: SQL-level tests in rolled-back transactions, and end-to-end tests against a real server
- How to check that the access paths still work with realistic data volumes, and fix one that doesn't
- What else a project like this needs before it goes to production

Twenty-three chapters built Inkwell piece by piece, each piece in isolation. This chapter assembles the complete
shop, the way you would set it up for a real project. Nothing in it is new SQL: it is all the earlier chapters,
combined, with the decisions that connect them explained.

The full source is in [`examples/inkwell/`](examples/inkwell/). The chapter shows the important parts; read the
files themselves for the rest. Everything here was run against PostgreSQL 18, Node.js 22 and PHP 8.3, and the test
suite passes.

## The project

The shop needs:

- a **catalog** that anyone can browse and search;
- **customers** who place orders, see their own orders (and only theirs), cancel them while they're not shipped yet,
  and write one review per product;
- **stock** that never goes negative, even when two customers buy the last pen at the same moment;
- an **audit trail** of every change to orders and prices, that the application itself can't tamper with;
- **reports** for the business, run by a separate tool with read-only access;
- **migrations** that move every environment (laptops, CI, production) to the same schema, and **tests** that prove
  it all works.

```text
                       ┌──────────────────────────────────────── PostgreSQL ───────────────────────────────┐
  HTTP clients         │                                                                                   │
  ───────────► Node.js API ──(pool, inkwell_app)──► tables  ◄── policies (RLS): customers see their rows   │
               (node:http, pg)   per request:        │     ◄── triggers: updated_at, audit log, NOTIFY      │
                                 SET LOCAL ROLE      │     ◄── functions: place_order, cancel_order, search │
                                 inkwell_portal      │                                                       │
                                                     ▼                                                       │
  PHP report ───────────(inkwell_report)──► reporting schema: views, materialized view                     │
                                                                                                           │
  migrate.js ───────────(inkwell_migrate → inkwell_owner)──► db/migrations/*.sql                           │
                       └───────────────────────────────────────────────────────────────────────────────────┘
```

## The layout

```text
examples/inkwell/
  README.md                 quick start
  compose.yaml              optional: PostgreSQL 18 and the API in containers
  db/
    roles.sql               cluster-wide roles, run once per cluster by a superuser
    create-database.sql     a new database, owned by inkwell_owner
    migrations/
      001_schema.sql        types, tables, constraints, indexes
      002_functions.sql     current_customer_id, search_products, place_order, cancel_order
      003_triggers.sql      updated_at, audit log, order notifications
      004_security.sql      grants and row-level security
      005_reporting.sql     reporting schema: a view and a materialized view
    seed.sql                sample data, the same as the tutorial's
    docker-init.sh          used by compose.yaml
  api/
    package.json            one dependency: pg
    .env.example            connection settings to copy into .env
    .env.test.example       the same for the test database
    src/
      config.js             settings from the environment
      db.js                 pool, withTransaction(), asCustomer()
      errors.js             SQLSTATE → HTTP status
      app.js                routes and handlers
      server.js             start and graceful shutdown
    scripts/
      migrate.js            the migration runner
      reset-db.js           rebuilds a development or test database
    test/
      support.js            test helpers
      api.test.js           HTTP tests
      database.test.js      tests of functions, policies, privileges and triggers
  report/
    monthly-report.php      the sales report
```

The database lives in plain SQL files, in version control, next to the code that uses it. No step of the setup
happens by hand in `psql`: every environment is built by the same files in the same order.

## Getting it running

You need PostgreSQL 18 (17 works too; the 18-only features are noted), Node.js 22.9 or newer, PHP 8.3 or newer with
`pdo_pgsql` for the report, and a superuser login for the setup. Install the API's dependency and create your
`.env` from the example:

```bash
cd examples/inkwell/api
npm install
cp .env.example .env
```

```text
DATABASE_URL=postgresql://inkwell_app:app-dev-password@localhost:5432/inkwell_dev
MIGRATION_DATABASE_URL=postgresql://inkwell_migrate:migrate-dev-password@localhost:5432/inkwell_dev
ADMIN_DATABASE_URL=postgresql://postgres@localhost:5432/postgres
PORT=3000
```

Three connection strings for three roles: the API runs as `inkwell_app`, migrations as `inkwell_migrate`, and only the
reset script uses a superuser. Adjust host, port and the superuser's password (or rely on `~/.pgpass`, chapter 2).
The development database is called `inkwell_dev`, so your tutorial database `inkwell` stays untouched. The roles are
the same as in chapters 21 and 22, since roles belong to the whole cluster; `roles.sql` brings them to the state the
capstone expects.

Then build the database and start the API:

```bash
npm run db:reset
npm start
```

```text
> inkwell-api@1.0.0 db:reset
> node --env-file-if-exists=.env scripts/reset-db.js

database inkwell_dev is ready

> inkwell-api@1.0.0 start
> node --env-file-if-exists=.env src/server.js

inkwell-api listening on http://localhost:3000
```

`node --env-file-if-exists` (Node.js 22.9+) loads `.env` into `process.env`, so no extra package is needed for it. In
a second terminal, take a short tour. Search the catalog:

```bash
curl 'http://localhost:3000/products?q=fountain+pen&limit=2'
```

```text
{"items":[{"id":2,"name":"Heron Fountain Pen","price":"129.00","snippet":"weighty brass <b>fountain</b> <b>pen</b> with a medium gold-plated nib, balanced for long writing sessions."},{"id":1,"name":"Kestrel Fountain Pen","price":"48.00","snippet":"light resin <b>fountain</b> <b>pen</b> with a fine steel nib. Smooth and reliable for everyday writing."}]}
```

Place an order as customer 5, Emma. The `X-Customer-Id` header stands in for real authentication (more on that
below):

```bash
curl -H 'X-Customer-Id: 5' -H 'content-type: application/json' \
     -d '{"items": [{"product_id": 7, "quantity": 2}, {"product_id": 13, "quantity": 1}]}' \
     http://localhost:3000/orders
```

```text
{"id":13,"status":"pending","created_at":"2026-10-11T20:55:17.555Z","shipped_at":null,"total":"33.00","items":[{"product_id":7,"name":"Dot Grid Notebook A5","quantity":2,"unit_price":"14.00","amount":"28.00"},{"product_id":13,"name":"Brass Pen Clip","quantity":1,"unit_price":"5.00","amount":"5.00"}]}
```

Try to buy something that's sold out, look at another customer's order, and cancel your own:

```bash
curl -H 'X-Customer-Id: 5' -H 'content-type: application/json' \
     -d '{"items": [{"product_id": 9, "quantity": 1}]}' http://localhost:3000/orders
curl -H 'X-Customer-Id: 1' http://localhost:3000/orders/13
curl -X POST -H 'X-Customer-Id: 5' http://localhost:3000/orders/13/cancel
```

```text
{"error":{"code":"out_of_stock","message":"product 9 is not available in quantity 1"}}
{"error":{"code":"not_found","message":"order not found"}}
{"id":13,"status":"cancelled","created_at":"2026-10-11T20:55:17.555Z","shipped_at":null,"total":"33.00","items":[...]}
```

Every one of these answers comes from a database rule, not from a check in JavaScript: the stock check is in
`place_order()`, the hidden order is row-level security, and the cancellation rule is in `cancel_order()`. The rest of
the chapter shows how.

The API's endpoints:

| Method and path | Who | What |
|---|---|---|
| `GET /health` | anyone | `200` if the database answers |
| `GET /products?q=…` or `?after=…&limit=…` | anyone | search (chapter 13) or browse with a keyset (chapter 22) |
| `GET /products/:id` | anyone | details, category, average rating |
| `POST /products/:id/reviews` | customer | one review per product |
| `GET /me` | customer | the customer's profile |
| `GET /orders?limit=…&cursor=…` | customer | the customer's orders, newest first |
| `GET /orders/:id` | customer | one order with its items |
| `POST /orders` | customer | place an order |
| `POST /orders/:id/cancel` | customer | cancel a pending or paid order |

## The database

### Roles first

Roles are cluster-wide (chapter 21), so they can't be created by a migration that runs inside one database.
`db/roles.sql` runs once per cluster, as a superuser, and is idempotent: it creates what's missing and sets the rest,
so running it again is harmless.

| Role | Logs in | Purpose |
|---|---|---|
| `inkwell_owner` | no | owns every object; nobody uses it directly |
| `inkwell_migrate` | yes | runs migrations; every session switches to `inkwell_owner` |
| `inkwell_readonly` | no | group: read all tables |
| `inkwell_readwrite` | no | group: readonly plus insert, update, delete |
| `inkwell_portal` | no | group: what a signed-in customer may do |
| `inkwell_app` | yes | the API: readwrite, and may switch to the portal role |
| `inkwell_report` | yes | the PHP report: readonly plus the reporting schema, read-only sessions |

```sql
GRANT inkwell_readonly TO inkwell_readwrite;
GRANT inkwell_readwrite TO inkwell_app;
GRANT inkwell_readonly TO inkwell_report;

-- SET TRUE, INHERIT FALSE (16+): the role may switch with SET ROLE, but doesn't use the privileges otherwise.
GRANT inkwell_portal TO inkwell_app WITH INHERIT FALSE, SET TRUE;
GRANT inkwell_owner TO inkwell_migrate WITH INHERIT FALSE, SET TRUE;

-- Every migration session starts as the owner, so new objects get the right owner without SET ROLE in each file.
ALTER ROLE inkwell_migrate SET role = 'inkwell_owner';
-- Safety nets (chapter 21). The report script is read-only unless it explicitly starts a READ WRITE transaction.
ALTER ROLE inkwell_app SET statement_timeout = '5s';
ALTER ROLE inkwell_app SET idle_in_transaction_session_timeout = '30s';
ALTER ROLE inkwell_report SET default_transaction_read_only = on;
ALTER ROLE inkwell_report SET statement_timeout = '2min';
```

The design follows chapter 21's rules: the application never owns what it uses, no login role is a superuser, and
each program gets exactly the access it needs. The `INHERIT FALSE` memberships (PostgreSQL 16+) matter twice here.
`inkwell_app` can *become* the portal role for a customer request, but its normal sessions don't carry the portal's
privileges. And `inkwell_migrate` can act as the owner, but only by switching to it, which `ALTER ROLE ... SET role`
does at every login.

### Creating the database

`db/create-database.sql` creates a database owned by `inkwell_owner`, with the ICU locale from chapter 11, UTC as the
session time zone, and no access for `PUBLIC`:

```sql
CREATE DATABASE :"dbname"
    OWNER inkwell_owner
    TEMPLATE template0
    ENCODING 'UTF8'
    LOCALE_PROVIDER icu
    ICU_LOCALE 'en';

ALTER DATABASE :"dbname" SET timezone TO 'UTC';
REVOKE ALL ON DATABASE :"dbname" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"dbname" TO inkwell_migrate;
```

`:"dbname"` is a `psql` variable, quoted as an identifier (chapter 2), so one file serves every environment:

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f db/roles.sql
psql -X -v ON_ERROR_STOP=1 -d postgres -v dbname=inkwell_dev -f db/create-database.sql
```

`npm run db:reset` does exactly this from Node.js, then runs the migrations and loads `db/seed.sql`. It refuses to
drop any database whose name doesn't start with `inkwell_dev` or `inkwell_test`, because a reset script pointed at the
wrong `.env` is a classic way to lose production data.

### Migrations

The schema is built by numbered SQL files in `db/migrations/`, applied in order by `scripts/migrate.js`. It's the
runner from chapter 22, with the checksum from that chapter's exercise 5:

```bash
npm run migrate
```

```text
> inkwell-api@1.0.0 migrate
> node --env-file-if-exists=.env scripts/migrate.js

applied 001_schema.sql
applied 002_functions.sql
applied 003_triggers.sql
applied 004_security.sql
applied 005_reporting.sql
5 migration(s) applied
```

Run it again and it prints `database is up to date`. The rules that make migrations safe:

- **Each file runs in its own transaction**, together with the row in `schema_migrations` that records it. A failing
  migration leaves no trace, and the runner stops there. (A file that must run outside a transaction, like
  `CREATE INDEX CONCURRENTLY`, needs special handling; chapter 17 explains why.)
- **An advisory lock** (chapter 17) makes a second runner wait, so two deployments starting at once can't apply the
  same migration twice.
- **Applied files are history.** Their SHA-256 checksum is stored, and the runner refuses to continue if an applied
  file was edited later. To change the schema, add a new file.
- **Files don't name the database.** The one statement that needs it (granting `CONNECT`) builds the name from
  `current_database()` in a `DO` block, so the same files build `inkwell_dev`, `inkwell_test` and production.
- **Migrations run as the owner**, never as the application's role, and never as a superuser.

### The schema (001)

`001_schema.sql` is the schema from chapter 4, with what later chapters added. Each decision has a reason:

| Decision | Why | Chapter |
|---|---|---|
| `bigint GENERATED ALWAYS AS IDENTITY` keys | no accidental manual ids, no sequence privileges needed | 4, 21 |
| `numeric(10, 2)` for money, `timestamptz` for times | exact amounts; unambiguous instants | 3, 11 |
| `CHECK` constraints on prices, stock, ratings, emails, status and `shipped_at` | invalid rows can't exist, whatever writes them | 4 |
| `order_items.unit_price` | the price at the time of the order, not today's | 4, 14 |
| a stored generated `search` column with a GIN index | full-text search without keeping a copy in sync | 13, 15 |
| `orders (customer_id, created_at DESC, id DESC)` | one index serves "my orders, newest first", with keyset pages | 15, 22 |
| `audit_log` with `jsonb` old and new rows | one generic audit table for every audited table | 12, 19 |
| `ALTER DEFAULT PRIVILEGES REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC` | new functions are private until granted | 21 |

The last line is the first statement in the file on purpose: it applies to every function the later migrations
create. Indexes follow the queries that the API actually runs (chapter 15): foreign keys that are searched, the
search column, and the order listing. Chapter 16's method decides whether they work, as the
[pagination section](#pagination-and-a-plan-that-didnt-scale) shows.

### Business rules in functions (002)

Placing an order touches three tables and must check stock atomically. Chapter 19 put that in a function. The
capstone's version has one important change: it takes the customer from the database's own notion of who is signed in,
not from a parameter.

```sql
CREATE FUNCTION place_order(p_items jsonb)
RETURNS bigint
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, public, pg_temp
AS $$
DECLARE
    v_customer_id  bigint := current_customer_id();
    v_order_id     bigint;
    v_item         record;
    v_price        numeric(10, 2);
BEGIN
    IF v_customer_id IS NULL THEN
        RAISE EXCEPTION 'no customer is signed in' USING ERRCODE = 'IW002';
    END IF;
    IF jsonb_typeof(p_items) IS DISTINCT FROM 'array' OR jsonb_array_length(p_items) = 0 THEN
        RAISE EXCEPTION 'an order needs at least one item' USING ERRCODE = 'invalid_parameter_value';
    END IF;

    INSERT INTO orders (customer_id)
    VALUES (v_customer_id)
    RETURNING id INTO v_order_id;

    -- One line per product (the same product twice is merged), locked in id order so that
    -- concurrent orders can't deadlock (chapter 17).
    FOR v_item IN
        SELECT (x ->> 'product_id')::bigint AS product_id,
               sum((x ->> 'quantity')::integer)::integer AS quantity
        FROM jsonb_array_elements(p_items) AS x
        GROUP BY 1
        ORDER BY 1
    LOOP
        IF v_item.product_id IS NULL OR v_item.quantity IS NULL OR v_item.quantity <= 0 THEN
            RAISE EXCEPTION 'every item needs a product_id and a positive quantity'
                USING ERRCODE = 'invalid_parameter_value';
        END IF;

        UPDATE products
        SET stock = stock - v_item.quantity
        WHERE id = v_item.product_id AND active AND stock >= v_item.quantity
        RETURNING price INTO v_price;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'product % is not available in quantity %', v_item.product_id, v_item.quantity
                USING ERRCODE = 'IW001',
                      HINT = 'Reload the basket to see current stock.';
        END IF;

        INSERT INTO order_items (order_id, product_id, quantity, unit_price)
        VALUES (v_order_id, v_item.product_id, v_item.quantity, v_price);
    END LOOP;

    RETURN v_order_id;
END;
$$;
```

Why this shape:

- **The stock check is the `UPDATE` itself** (`... AND stock >= quantity`). Its row lock makes a concurrent order for
  the same product wait, then re-check the condition against the new stock (chapter 17). Two customers can't both
  buy the last pen, without any explicit locking or `SERIALIZABLE`.
- **One error rolls everything back.** If the second product isn't available, the first one's stock change and the
  order row disappear with the transaction.
- **`SECURITY DEFINER`** lets customers place orders without any privilege to insert orders or change stock: the
  portal role can only call this function, and the function checks everything. The fixed `search_path` with
  `pg_temp` last is what makes that safe (chapters 19 and 21).
- **`current_customer_id()`** reads the `app.customer_id` setting that the API sets per transaction. Even if a bug in
  the API passed the wrong customer somewhere else, this function can only order for the customer of the current
  transaction.

`cancel_order(p_order_id)` follows the same pattern: it locks the customer's own order with `FOR UPDATE`, checks the
status and puts the items back in stock. An order of another customer gets the same answer as one that doesn't exist,
so the API doesn't reveal which order numbers are taken. The functions report problems with their own SQLSTATE codes,
which the API turns into HTTP statuses:

| SQLSTATE | Meaning | HTTP |
|---|---|---|
| `IW001` | a product isn't available in the requested quantity | 409 |
| `IW002` | no customer is signed in | 401 |
| `IW003` | the order doesn't exist, or isn't the customer's | 404 |
| `IW004` | the order can no longer be cancelled | 409 |

`search_products()` is chapter 19's SQL-standard function (`BEGIN ATOMIC`), now also returning the price.

### Triggers (003)

Three triggers from chapter 19: `updated_at` on customers, a notification on every new order (`LISTEN order_placed`,
used by the tests and by exercise 5), and the audit log. The audit trigger is `SECURITY DEFINER`, which solves a
problem chapter 19 left open: the roles that change orders have **no privileges on `audit_log`** at all, so they can't
forge or delete entries, while the trigger can still write them. It records the login role and the signed-in
customer:

```sql
SELECT id, operation, row_id, changed_by, customer_id,
       old_row ->> 'status' AS old_status, new_row ->> 'status' AS new_status
FROM audit_log
WHERE table_name = 'orders' AND row_id = 13
ORDER BY id;
```

```text
 id | operation | row_id | changed_by  | customer_id | old_status | new_status
----+-----------+--------+-------------+-------------+------------+------------
 13 | INSERT    |     13 | inkwell_app |           5 |            | pending
 15 | UPDATE    |     13 | inkwell_app |           5 | pending    | cancelled
(2 rows)
```

`changed_by` is `session_user`, the login role: inside a `SECURITY DEFINER` function, `current_user` would always be
the owner. The gap at id 14 is the order that failed for lack of stock: its audit row was rolled back with it, but the
identity value was used up (chapter 4).

### Privileges and row-level security (004)

`004_security.sql` grants each role what the table above describes, and enables row-level security on customers,
orders, order items and reviews. The policies follow chapter 21's pattern, one for staff and one for customers:

```sql
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
CREATE POLICY orders_staff ON orders TO inkwell_readonly USING (true);
CREATE POLICY orders_customer ON orders FOR SELECT TO inkwell_portal
    USING (customer_id = current_customer_id());

ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
CREATE POLICY reviews_staff ON reviews TO inkwell_readonly USING (true);
CREATE POLICY reviews_public ON reviews FOR SELECT TO inkwell_portal USING (true);
CREATE POLICY reviews_customer_insert ON reviews FOR INSERT TO inkwell_portal
    WITH CHECK (customer_id = current_customer_id());
```

Customers may read their orders but not change them (no `UPDATE` grant, and no policy for it); changes go through the
functions, which run as the owner, and the owner bypasses row-level security. Reviews are public, but a customer can
only insert one in their own name. The privileges are easy to check:

```sql
SELECT r.rolname,
       has_table_privilege(r.rolname, 'orders', 'SELECT')                 AS read_orders,
       has_table_privilege(r.rolname, 'orders', 'UPDATE')                 AS update_orders,
       has_table_privilege(r.rolname, 'audit_log', 'INSERT')              AS write_audit,
       has_function_privilege(r.rolname, 'place_order(jsonb)', 'EXECUTE') AS place_order,
       has_schema_privilege(r.rolname, 'reporting', 'USAGE')              AS reporting
FROM pg_roles AS r
WHERE r.rolname IN ('inkwell_app', 'inkwell_portal', 'inkwell_report', 'inkwell_migrate')
ORDER BY r.rolname;
```

```text
     rolname     | read_orders | update_orders | write_audit | place_order | reporting
-----------------+-------------+---------------+-------------+-------------+-----------
 inkwell_app     | t           | t             | f           | f           | f
 inkwell_migrate | f           | f             | f           | f           | f
 inkwell_portal  | t           | f             | f           | t           | f
 inkwell_report  | t           | f             | f           | f           | t
(4 rows)
```

Note `inkwell_app` can't call `place_order()` and `inkwell_migrate` can't do anything: both hold the necessary role
with `INHERIT FALSE`, so they must switch to it first, which is exactly the point. Tests check these rules too, later
in the chapter.

### Reporting (005)

The `reporting` schema holds what the business reads: `reporting.monthly_revenue`, a view, and
`reporting.product_sales`, a materialized view with a unique index so it can be refreshed concurrently (chapter 20).
Both are owned by `inkwell_owner`, so they see all rows despite row-level security, and `inkwell_report` may read them
without any access to the tables underneath. Two details:

- `date_trunc('month', created_at, 'UTC')` (16+) names the time zone, so the months don't depend on the session's
  `TimeZone` setting.
- `GRANT MAINTAIN ON reporting.product_sales TO inkwell_report` (17+) lets the report refresh the materialized view
  without owning it.

## The API

The API is plain `node:http` with the `pg` driver: no web framework, so that every database interaction is visible.
In a real project you'd likely use a framework for routing and validation; the database code would look the same.

### The pool and the customer context

`src/db.js` creates one pool for the process, and two helpers from chapter 22: `withTransaction()`, which retries
serialization failures and deadlocks, and `asCustomer()`:

```js
export function asCustomer(customerId, fn, options) {
  return withTransaction(async (client) => {
    await client.query('SET LOCAL ROLE inkwell_portal');
    await client.query("SELECT set_config('app.customer_id', $1, true)", [String(customerId)]);
    return fn(client);
  }, options);
}
```

Every customer request runs in a transaction that has switched to `inkwell_portal` and set `app.customer_id`. From
then on, row-level security and the functions do the rest. Both settings are transaction-local (`SET LOCAL`, and
`true` for `set_config`), so they end with `COMMIT` or `ROLLBACK`, and the connection goes back to the pool clean.
That's also what makes this design work behind PgBouncer in transaction mode (chapter 22): nothing outlives the
transaction.

The same file sets a type parser for `bigint`, so ids arrive as numbers instead of strings, with a check that fails
loudly if a value ever exceeds JavaScript's safe range. `numeric` stays a string: prices are `"48.00"` all the way to
the client, never binary floating-point numbers.

### A request from start to finish

```js
async function createOrder({ request }) {
  const customerId = customerIdOf(request);
  const { items } = await readJson(request);
  const valid = Array.isArray(items) && items.length > 0 && items.length <= 50
    && items.every((item) => Number.isSafeInteger(item?.product_id) && item.product_id > 0
                             && Number.isInteger(item.quantity) && item.quantity > 0 && item.quantity <= 100);
  if (!valid) {
    throw new HttpError(400, 'invalid_request',
      'items must be 1-50 objects with a positive product_id and a quantity of 1-100');
  }
  const order = await asCustomer(customerId, async (client) => {
    // A JSON string for the jsonb parameter: a JS array would be sent as a PostgreSQL array (chapter 22).
    const { rows: [{ id }] } = await client.query('SELECT place_order($1::jsonb) AS id', [JSON.stringify(items)]);
    return fetchOrder(client, id);
  });
  return [201, order];
}
```

What happens for `POST /orders`:

```text
  API                                              PostgreSQL (one pooled connection)
  ───                                              ─────────────────────────────────
  validate the JSON body (400 if wrong)
  BEGIN ISOLATION LEVEL READ COMMITTED  ──────────►
  SET LOCAL ROLE inkwell_portal         ──────────►  now subject to RLS, may call place_order()
  set_config('app.customer_id', '5', true) ───────►
  SELECT place_order($1::jsonb)         ──────────►  runs as inkwell_owner: insert order, lock and
                                                     update stock, insert items; triggers: audit, NOTIFY
  fetchOrder(): SELECT order, items     ──────────►  as the portal role: RLS shows only customer 5's rows
  COMMIT                                ──────────►  NOTIFY order_placed is delivered now
  201 + the order as JSON
```

The API validates the request's shape, because good error messages belong there; the database enforces the rules,
because that's the only place where they can't be bypassed. Both layers check quantities, for example, and that's
fine: the API's check gives a friendly `400`, the database's guarantees correctness for every client.

### Errors

Every handler throws; one `catch` in `handleRequest()` turns errors into responses. `src/errors.js` maps SQLSTATE codes
(chapter 22) to HTTP:

| SQLSTATE | Source | Response |
|---|---|---|
| `IW001`–`IW004` | Inkwell's functions | 409, 401, 404, 409, with the function's message |
| `23505` unique violation | a second review of the same product | 409, message chosen by constraint name |
| `23503` foreign key violation | a review of a product that doesn't exist | 404 |
| `22023`, `22P02`, `23514` | invalid values that got past the API's validation | 400 |
| `57014` query canceled | `statement_timeout` (5 s for `inkwell_app`) | 503 |
| anything else | a bug | 500 with no details; the full error goes to the log |

Unexpected errors are never sent to the client: an error message can contain table names, values or SQL, which help
an attacker and confuse a user.

### Authentication is out of scope

`X-Customer-Id` is a stand-in. A real shop verifies a session cookie or a token in `customerIdOf()` and takes the
customer's id from it; everything after that point stays as it is. Trusting a header like this in production would let
anyone act as any customer. Note what the database design buys you, though: whichever id the API hands to
`asCustomer()`, a request can only ever see and change that one customer's data.

### Pagination, and a plan that didn't scale

`GET /orders` pages through a customer's orders with a keyset on `(created_at, id)` (chapter 22). The first version of
its query joined the order items and grouped to get each order's total:

```sql
SELECT o.id, o.status, o.created_at, sum(oi.quantity * oi.unit_price) AS total
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE $1::timestamptz IS NULL OR (o.created_at, o.id) < ($1::timestamptz, $2::bigint)
GROUP BY o.id
ORDER BY o.created_at DESC, o.id DESC
LIMIT $3;
```

On the sample data that's instant: twelve orders. The rule from chapter 16 is to check plans with realistic volumes,
so give the development database 200,000 more orders, as a superuser (`npm run db:reset` removes them again):

```sql
INSERT INTO orders (customer_id, status, created_at, shipped_at)
SELECT 1 + g % 8, 'delivered', t, t + interval '1 day'
FROM generate_series(1, 200000) AS g,
     LATERAL (SELECT timestamptz '2024-01-01' + g * interval '4 minutes' AS t) AS x;
INSERT INTO order_items (order_id, product_id, quantity, unit_price)
SELECT o.id, 1 + o.id % 13, 1 + o.id % 3, 5.00 FROM orders AS o WHERE o.id > 12;
ANALYZE;
```

Then look at the first page for customer 5, exactly as the API runs it: as the portal role, with row-level security:

```sql
BEGIN;
SET LOCAL ROLE inkwell_portal;
SELECT set_config('app.customer_id', '5', true);
EXPLAIN (ANALYZE, COSTS OFF, BUFFERS OFF)
SELECT o.id, o.status, o.created_at, sum(oi.quantity * oi.unit_price) AS total
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE NULL::timestamptz IS NULL OR (o.created_at, o.id) < (NULL::timestamptz, NULL::bigint)
GROUP BY o.id
ORDER BY o.created_at DESC, o.id DESC
LIMIT 10;
```

```text
 Limit (actual time=94.957..94.962 rows=10.00 loops=1)
   ->  Sort (actual time=94.955..94.959 rows=10.00 loops=1)
         Sort Key: o.created_at DESC, o.id DESC
         Sort Method: top-N heapsort  Memory: 25kB
         ->  GroupAggregate (actual time=80.420..92.722 rows=25002.00 loops=1)
               Group Key: o.id
               ->  Sort (actual time=80.390..81.456 rows=25004.00 loops=1)
                     Sort Key: o.id DESC
                     Sort Method: quicksort  Memory: 2136kB
                     ->  Nested Loop (actual time=2.137..74.920 rows=25004.00 loops=1)
                           ->  Bitmap Heap Scan on orders o (actual time=2.108..6.842 rows=25002.00 loops=1)
                                 Recheck Cond: (customer_id = (NULLIF(current_setting('app.customer_id'::text, true), ''::text))::bigint)
                                 Heap Blocks: exact=1667
                                 ->  Bitmap Index Scan on orders_customer_created_idx (actual time=1.938..1.940 rows=25002.00 loops=1)
                                       Index Cond: (customer_id = (NULLIF(current_setting('app.customer_id'::text, true), ''::text))::bigint)
                                       Index Searches: 1
                           ->  Index Scan using order_items_pkey on order_items oi (actual time=0.002..0.002 rows=1.00 loops=25002)
                                 Index Cond: (order_id = o.id)
                                 Filter: EXISTS(SubPlan 1)
                                 Index Searches: 25002
                                 SubPlan 1
                                   ->  Index Scan using orders_pkey on orders o_1 (actual time=0.001..0.001 rows=1.00 loops=25004)
                                         Index Cond: (id = oi.order_id)
                                         Filter: (customer_id = (NULLIF(current_setting('app.customer_id'::text, true), ''::text))::bigint)
                                         Index Searches: 25004
 Planning Time: 1.113 ms
 Execution Time: 95.192 ms
(27 rows)
```

Read it bottom-up, as chapter 16 taught. Some good news first: the row-level security policy works as an index
condition (`current_customer_id()` is a `STABLE` SQL function, so it was inlined), and the order items' policy shows up
as the `EXISTS` subplan. The bad news: to return 10 orders, PostgreSQL totals **all 25,002** of the customer's orders,
joining 25,004 order items, then sorts them and keeps the newest ten. The `GROUP BY` forces it: the planner must form
every group before it knows the order of the groups. 95 ms per page, and it grows with every order the customer
places.

The fix is to compute the total only for the rows on the page, with a `LATERAL` subquery (chapter 7):

```sql
SELECT o.id, o.status, o.created_at, t.total
FROM orders AS o
CROSS JOIN LATERAL (SELECT sum(oi.quantity * oi.unit_price) AS total
                    FROM order_items AS oi WHERE oi.order_id = o.id) AS t
WHERE NULL::timestamptz IS NULL OR (o.created_at, o.id) < (NULL::timestamptz, NULL::bigint)
ORDER BY o.created_at DESC, o.id DESC
LIMIT 10;
ROLLBACK;
```

```text
 Limit (actual time=0.038..0.081 rows=10.00 loops=1)
   ->  Nested Loop (actual time=0.037..0.079 rows=10.00 loops=1)
         ->  Index Scan using orders_customer_created_idx on orders o (actual time=0.016..0.019 rows=10.00 loops=1)
               Index Cond: (customer_id = (NULLIF(current_setting('app.customer_id'::text, true), ''::text))::bigint)
               Index Searches: 1
         ->  Aggregate (actual time=0.005..0.006 rows=1.00 loops=10)
               ->  Index Scan using order_items_pkey on order_items oi (actual time=0.004..0.004 rows=1.20 loops=10)
                     Index Cond: (order_id = o.id)
                     Filter: EXISTS(SubPlan 1)
                     Index Searches: 10
                     SubPlan 1
                       ->  Index Scan using orders_pkey on orders o_1 (actual time=0.001..0.001 rows=1.00 loops=12)
                             Index Cond: (id = oi.order_id)
                             Filter: (customer_id = (NULLIF(current_setting('app.customer_id'::text, true), ''::text))::bigint)
                             Index Searches: 12
 Planning Time: 0.255 ms
 Execution Time: 0.110 ms
(17 rows)
```

The index delivers the customer's orders already in the right order, the scan stops after ten, and each of those ten
gets its total from the primary key of `order_items`: 0.11 ms, the same for the first customer and the millionth
order. On later pages, the cursor condition `(created_at, id) < (…)` becomes part of the index condition too, so every
page costs the same. The API uses the `LATERAL` version. The lesson is the method: an index is only half of the story,
and the query must let the planner use it.

### Graceful shutdown

`src/server.js` handles `SIGTERM` (sent by Docker, systemd or Kubernetes on a deploy) and `Ctrl+C`: it stops accepting
connections, lets running requests finish, then closes the pool, so no transaction is cut off halfway and the
server's connections close cleanly instead of timing out.

```text
inkwell-api listening on http://localhost:3000
SIGTERM received, shutting down
bye
```

## Tests

Chapter 19 promised a way to test database code from the application's test suite. The capstone uses Node.js's
built-in test runner (`node:test`) with two kinds of tests:

| Kind | File | How |
|---|---|---|
| database tests | `test/database.test.js` | call functions and query tables as a given role, inside a transaction that is always rolled back |
| API tests | `test/api.test.js` | start the real server on a free port and send HTTP requests with `fetch` |

Both run against a dedicated database, `inkwell_test`, that `npm test` rebuilds from scratch first: the `pretest`
script runs `reset-db.js` with `.env.test`. So the tests always start from the same known state, and also prove that
the migrations build a working database from nothing.

```bash
cp .env.test.example .env.test
npm test
```

```text
✔ GET /health reports the database as reachable (35.813352ms)
▶ catalog
  ✔ full-text search ranks name matches first (9.01128ms)
  ✔ browsing pages through active products with a keyset (7.803357ms)
  ✔ a product shows its category and rating; prices stay exact strings (4.352657ms)
  ✔ inactive, unknown and malformed product ids are 404 (9.872914ms)
✔ catalog (31.53604ms)
▶ orders
  ✔ need a signed-in customer (3.488418ms)
  ✔ placing an order charges current prices and takes the items from stock (24.225043ms)
  ✔ an unavailable item rejects the whole order (10.039852ms)
  ✔ invalid baskets are rejected before reaching the database (6.7772ms)
  ✔ customers see only their own orders (7.261325ms)
  ✔ pagination returns every order exactly once (12.46715ms)
  ✔ cancelling puts the items back in stock, once (20.549328ms)
  ✔ a customer can't cancel someone else's order (2.329178ms)
✔ orders (87.947411ms)
▶ reviews
  ✔ one review per customer and product (7.574596ms)
  ✔ ratings outside 1-5 are rejected (1.703303ms)
✔ reviews (9.555918ms)
✔ place_order needs a signed-in customer (20.033651ms)
✔ place_order merges repeated products into one line (9.704432ms)
✔ the portal role sees exactly the signed-in customer's rows (4.615871ms)
✔ the portal role can't change orders directly (2.405141ms)
✔ a customer can only post reviews in their own name (2.622365ms)
✔ order changes are audited, and the audit log is read-only for the application (4.388376ms)
✔ search_products ranks matches in the name above matches in the description (2.766143ms)
✔ a committed order notifies listeners on the order_placed channel (12.973288ms)
ℹ tests 23
ℹ suites 3
ℹ pass 23
ℹ fail 0
ℹ cancelled 0
ℹ skipped 0
ℹ todo 0
ℹ duration_ms 555.096476
```

(When the output isn't a terminal, for example in CI, the runner prints the TAP format instead.) The scripts pass
`--test-concurrency=1`: the test files share one database, and running them one after another keeps their effects from
overlapping.

### Database tests in rolled-back transactions

The helper that makes database tests cheap:

```js
export async function rolledBack(fn) {
  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    return await fn(client);
  } finally {
    await client.query('ROLLBACK');
    client.release();
  }
}
```

A test can insert, update and call functions freely; whatever happens, the `ROLLBACK` leaves the database as it was,
so tests don't need cleanup and don't depend on each other. With `signIn()`, which does what `asCustomer()` does,
a test can act as any role:

```js
test('order changes are audited, and the audit log is read-only for the application', async () => {
  await rolledBack(async (client) => {
    await signIn(client, 6);
    const { rows: [{ id }] } = await client.query(
      `SELECT place_order('[{"product_id": 3, "quantity": 1}]') AS id`);
    await client.query('RESET ROLE'); // back to inkwell_app, which may read the audit log
    const { rows } = await client.query(
      `SELECT operation, changed_by, customer_id, new_row ->> 'status' AS status
       FROM audit_log WHERE table_name = 'orders' AND row_id = $1`, [id]);
    assert.deepEqual(rows, [{ operation: 'INSERT', changed_by: 'inkwell_app', customer_id: 6, status: 'pending' }]);

    await assert.rejects(client.query('DELETE FROM audit_log'), { code: '42501' });
  });
});
```

Security rules deserve tests as much as business rules do: a migration that accidentally grants too much, or drops a
policy, should fail CI, not be found in production. `assert.rejects` with a `code` checks for the exact SQLSTATE, so the
test fails if the statement fails for a different reason.

Two things can't be tested inside a rolled-back transaction: anything that needs a commit (the `NOTIFY` test commits a
real order, and uses its own listener connection), and concurrency between transactions. For the latter, chapter 22's
exercise on deadlocks shows the technique: two connections, explicit steps.

### API tests against the real server

```js
test('an unavailable item rejects the whole order', async () => {
  const before = await stockOf(1);
  const { status, body } = await api.request('POST', '/orders', {
    customer: 5,
    body: { items: [{ product_id: 1, quantity: 1 }, { product_id: 9, quantity: 1 }] }, // 9 is sold out
  });
  assert.equal(status, 409);
  assert.equal(body.error.code, 'out_of_stock');
  assert.equal(body.error.message, 'product 9 is not available in quantity 1');
  assert.equal(await stockOf(1), before, 'product 1 must not have been taken from stock');
});
```

The last assertion is the interesting one: it proves the order is atomic across products. API tests commit real data,
so they're written to be independent of each other's effects: they compare stock before and after instead of
expecting fixed numbers, and use different customers and products where results could interfere.

## The PHP report

`report/monthly-report.php` is a command-line script for the business: monthly revenue and the best-selling products,
as text or CSV. It connects as `inkwell_report` with PDO (chapter 22), and since the DSN only names the database,
host, port and password come from the same `PG*` variables and `~/.pgpass` that `psql` uses:

```bash
cd examples/inkwell/report
php monthly-report.php
```

```text
Inkwell sales report, generated 2026-10-11 20:50 UTC

Month     Orders  Customers      Revenue
2026-01        2          2       119.00
2026-02        2          2       187.40
2026-03        3          3       192.75
2026-04        4          4       231.70
Total         11                  730.85

Top 5 products by revenue
Product                     Units      Revenue
Heron Fountain Pen              2       258.00
Kestrel Fountain Pen            3       141.00
Dot Grid Notebook A5            6        84.00
Midnight Blue Ink 50ml          3        48.00
Copy Paper 500 sheets           5        39.95
```

The interesting parts of the script:

```php
if (isset($options['refresh'])) {
    // The role's sessions are read-only by default (db/roles.sql); this one transaction may write.
    // CONCURRENTLY: readers of the old contents aren't blocked while it runs (chapter 20).
    $db->exec('START TRANSACTION READ WRITE');
    $db->exec('REFRESH MATERIALIZED VIEW CONCURRENTLY reporting.product_sales');
    $db->exec('COMMIT');
}

// ...

// Totals are summed by PostgreSQL: revenue arrives as a string ("119.00"), and adding the strings as PHP
// floats would invite rounding errors.
$total = $db->query('SELECT sum(orders) AS orders, sum(revenue) AS revenue FROM reporting.monthly_revenue')
            ->fetch();
```

`--refresh` refreshes the materialized view first, which the report role may do thanks to the `MAINTAIN` privilege
(17+), in an explicit `READ WRITE` transaction because its sessions are read-only by default. `--csv` prints the
monthly table as CSV, and `--top=N` changes the length of the product list. Connected as any other role, the report
fails, as it should:

```text
report failed (42501): SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  permission denied for schema reporting
LINE 2:          FROM reporting.monthly_revenue
                      ^
```

## Running it in containers

`compose.yaml` starts PostgreSQL 18 and the API in containers, for those who prefer not to install PostgreSQL locally.
It was validated with `docker compose config`; Compose itself is outside the scope of this tutorial.

> **Just a taste — you don't need to learn or fully understand this now.** Container orchestration is a topic of its own; the essentials below are what matter for the database.

```yaml
services:
  db:
    image: postgres:18
    environment:
      POSTGRES_PASSWORD: postgres-dev-password
    volumes:
      - pgdata:/var/lib/postgresql   # PostgreSQL 18+ images: mount the parent directory (chapter 1)
      - ./db:/inkwell/db:ro
      - ./db/docker-init.sh:/docker-entrypoint-initdb.d/inkwell.sh:ro
    healthcheck:
      test: ["CMD", "pg_isready", "-h", "127.0.0.1", "-U", "postgres", "-d", "inkwell_dev"]
  api:
    image: node:24-alpine
    command: sh -c "npm ci --omit=dev && node scripts/migrate.js && exec node src/server.js"
    depends_on:
      db:
        condition: service_healthy
```

The database-specific points: `docker-init.sh` runs `roles.sql` and `create-database.sql` once, when the volume is
empty; the API container runs the migrations at every start (they're idempotent) before starting the server; the
health check connects over TCP, because during its first start the image runs a temporary server on the Unix socket
only; and `exec` makes `node` the process that receives `SIGTERM`, so the graceful shutdown runs on
`docker compose stop`.

## Taking it to production

The capstone is a complete *development* setup. Going live needs the rest of the tutorial:

| Area | What to add | Chapter |
|---|---|---|
| Authentication | real sessions or tokens in `customerIdOf()`; TLS between API and database (`sslmode=verify-full`) | 21, 22 |
| Secrets | real passwords for every login role, set with `\password`, kept in a secret store, not in `.env` files | 21 |
| Network | `pg_hba.conf` allowing only the API's and report's hosts, with `scram-sha-256` | 21 |
| Connections | pool size per instance × instances below `max_connections`, or PgBouncer in transaction mode | 22 |
| Backups | WAL archiving with a backup tool, restore tests, a restore point before each migration | 23 |
| High availability | a streaming standby and a cluster manager | 23 |
| Monitoring | `pg_stat_statements`, slow-query log, `auto_explain`, alerts on replication, slots, disk, long transactions | 16, 23 |
| Maintenance | autovacuum tuned for the large tables, `REFRESH` of the report on a schedule | 18, 20 |
| Schema changes | migrations in CI against a copy of production data; `lock_timeout` and `CREATE INDEX CONCURRENTLY` for big tables | 17 |
| Growth | partition `orders` and `audit_log` by month when they get large | 20 |

## Key takeaways

- A database project is code: roles, database creation, migrations and seed data in version control, applied by
  scripts, identical in every environment.
- Put the rules that protect your data in the database (constraints, functions, row-level security) and the rules that
  shape the user experience in the application. Then a bug in one place doesn't corrupt everything.
- `SECURITY DEFINER` functions with a fixed `search_path` give roles narrow, audited ways to do things they otherwise
  can't, such as placing an order without the right to insert orders.
- Pass the user to the database per transaction (`SET LOCAL ROLE`, `set_config(..., true)`), and let row-level
  security filter every query. It's compatible with connection pools and transaction poolers.
- Test the database like code: functions and policies in rolled-back transactions, the API against a freshly built
  test database, security rules included.
- An index is only half the work: check plans with realistic data, as the role that runs the query, and shape the query
  so the planner can use the index (here, `LATERAL` instead of `GROUP BY`).
- Map database errors to responses by SQLSTATE, use your own codes for business errors, and never send unexpected
  error details to clients.

## Exercises

These exercises extend the capstone. Work in a copy of `examples/inkwell/`, and run `npm test` after each one.
For new migrations, use the next free number; `npm run db:reset` (or `npm run migrate`) applies them.

1. **Test the cancellation rules.** Add a database test to `test/database.test.js` that checks that
   `cancel_order()` refuses a delivered order (order 1, customer 1) with `IW004`, and another customer's order
   (order 12, as customer 1) with `IW003`.

<details>
<summary>Solution</summary>

```js
test("cancel_order refuses delivered orders and other customers' orders", async () => {
  await rolledBack(async (client) => {
    await signIn(client, 1);
    await assert.rejects(client.query('SELECT cancel_order(1)'),
      { code: 'IW004', message: 'order 1 is delivered and can no longer be cancelled' });
  });
  await rolledBack(async (client) => {
    await signIn(client, 1);
    await assert.rejects(client.query('SELECT cancel_order(12)'), { code: 'IW003', message: 'order 12 not found' });
  });
});
```

Each check needs its own transaction: after the first error, the transaction is aborted, and any further statement in
it would fail with `25P02` ("current transaction is aborted") instead of the error you want to see. With the test added,
`npm test` reports 24 passing tests.

</details>

2. **List a product's reviews.** Add `GET /products/:id/reviews`, returning the reviews of an active product, newest
   first, with only the author's first name (for privacy), and an API test for it.

<details>
<summary>Solution</summary>

In `src/app.js`, a handler:

```js
// GET /products/:id/reviews
async function listReviews({ params }) {
  const { rows } = await pool.query(
    `SELECT r.id, r.rating, r.body, r.created_at, split_part(c.full_name, ' ', 1) AS author
     FROM reviews AS r
     JOIN products AS p ON p.id = r.product_id
     JOIN customers AS c ON c.id = r.customer_id
     WHERE r.product_id = $1 AND p.active
     ORDER BY r.created_at DESC, r.id DESC`, [params.id]);
  return [200, { items: rows }];
}
```

and a route next to the other product routes:

```js
  ['GET', /^\/products\/(?<id>[^/]+)\/reviews$/, listReviews],
```

The query runs as `inkwell_app`, which may read customers (staff policy); the API decides to expose only the first
name. The test, in `test/api.test.js`:

```js
test("a product's reviews, newest first, with first names only", async () => {
  const { status, body } = await api.request('GET', '/products/1/reviews');
  assert.equal(status, 200);
  assert.deepEqual(body.items.map((r) => [r.author, r.rating]), [['Emma', 4], ['Ana', 5]]);
});
```

```bash
curl http://localhost:3000/products/1/reviews
```

```text
{"items":[{"id":7,"rating":4,"body":"Good starter pen.","created_at":"2026-03-10T18:00:00.000Z","author":"Emma"},{"id":1,"rating":5,"body":"Smooth nib, writes every time.","created_at":"2026-01-20T08:00:00.000Z","author":"Ana"}]}
```

A product without reviews returns an empty list. To return `404` for a product that doesn't exist, check for it first,
or select from `products` with a `LEFT JOIN` to the reviews.

</details>

3. **Let customers edit their profile.** Customers may change their `full_name` and `country`, but not their email.
   Write a migration that allows exactly that through row-level security and column privileges, and a
   `PATCH /me` endpoint. What happens if a customer sends an invalid country such as `"Germany"`?

<details>
<summary>Solution</summary>

`db/migrations/006_customer_profile.sql`:

```sql
-- 006: customers may change their own name and country (not their email).
GRANT UPDATE (full_name, country) ON customers TO inkwell_portal;

CREATE POLICY customers_customer_update ON customers FOR UPDATE TO inkwell_portal
    USING (id = current_customer_id());
```

The column privilege limits *what* may change; the policy limits *which row*. Without a `WITH CHECK` clause, the
`USING` expression also checks the new row, so a customer can't move the row to another id either. The existing
`customers_customer` policy for `SELECT` matters too: an `UPDATE ... WHERE` needs to read the rows it updates.

In `src/app.js`:

```js
// PATCH /me  {"full_name": "...", "country": "DE"}  (either or both)
async function updateMe({ request }) {
  const customerId = customerIdOf(request);
  const { full_name: fullName = null, country = null } = await readJson(request);
  if ((fullName !== null && typeof fullName !== 'string') || (country !== null && typeof country !== 'string')) {
    throw new HttpError(400, 'invalid_request', 'full_name and country must be strings');
  }
  const customer = await asCustomer(customerId, async (client) => {
    const { rows } = await client.query(
      `UPDATE customers
       SET full_name = coalesce($1, full_name), country = coalesce($2, country)
       WHERE id = current_customer_id()
       RETURNING id, email, full_name, country, updated_at`, [fullName, country]);
    return rows[0];
  });
  return [200, customer];
}
```

with the route `['PATCH', /^\/me$/, updateMe]`.

```bash
curl -X PATCH -H 'X-Customer-Id: 5' -H 'content-type: application/json' \
     -d '{"full_name": "Emma Schulz-Berg"}' http://localhost:3000/me
curl -X PATCH -H 'X-Customer-Id: 5' -H 'content-type: application/json' \
     -d '{"country": "Germany"}' http://localhost:3000/me
```

```text
{"id":5,"email":"emma@example.com","full_name":"Emma Schulz-Berg","country":"DE","updated_at":"2026-10-11T20:52:20.995Z"}
{"error":{"code":"invalid_request","message":"invalid value"}}
```

`"Germany"` violates the `CHECK (country ~ '^[A-Z]{2}$')` constraint (`23514`), which `errors.js` turns into a `400`.
`updated_at` changed although the portal role has no privilege on that column: the `BEFORE UPDATE` trigger sets it,
and privileges are checked only for the columns the statement itself names.

</details>

4. **Low stock in the report.** Add a view `reporting.low_stock` (active products with fewer than 20 in stock, with the
   units sold in the last 30 days, not counting cancelled orders) and a `--low-stock` option to the PHP report that
   prints it.

<details>
<summary>Solution</summary>

`db/migrations/007_low_stock.sql`:

```sql
-- 007: products running low, for the report.
CREATE VIEW reporting.low_stock AS
SELECT p.id, p.sku, p.name, p.stock,
       coalesce(sum(oi.quantity) FILTER (WHERE o.created_at >= now() - interval '30 days'), 0) AS sold_last_30_days
FROM products AS p
LEFT JOIN order_items AS oi ON oi.product_id = p.id
LEFT JOIN orders AS o ON o.id = oi.order_id AND o.status <> 'cancelled'
WHERE p.active AND p.stock < 20
GROUP BY p.id;

GRANT SELECT ON reporting.low_stock TO inkwell_report;
```

The status condition sits in the join's `ON` clause, so a cancelled order's items join to no order, and the `FILTER`
on `o.created_at` (then `NULL`) leaves them out; products with no sales at all keep their row thanks to the outer
joins. In the PHP script, add `'low-stock'` to the `getopt()` list, fetch the rows inside the `try` block:

```php
    $lowStock = $db->query(
        'SELECT sku, name, stock, sold_last_30_days FROM reporting.low_stock ORDER BY stock, sku'
    )->fetchAll();
```

and print them at the end:

```php
if (isset($options['low-stock'])) {
    printf("\nLow stock (fewer than 20 left)\n");
    printf("%-8s %-26s %6s %14s\n", 'SKU', 'Product', 'Stock', 'Sold (30 days)');
    foreach ($lowStock as $row) {
        printf("%-8s %-26s %6d %14d\n", $row['sku'], $row['name'], $row['stock'], $row['sold_last_30_days']);
    }
}
```

```text
Low stock (fewer than 20 left)
SKU      Product                     Stock Sold (30 days)
NB-003   Pocket Notebook 3-pack          0              0
FP-002   Heron Fountain Pen              3              0
FP-001   Kestrel Fountain Pen           12              0
```

(The sample orders are from early 2026, so nothing was sold in the last 30 days.)

</details>

5. **An order confirmation worker.** Write `scripts/order-worker.js`, a long-running process that listens on the
   `order_placed` channel and prints a confirmation "email" (name, address, order number, total) for every new
   order. What happens to orders placed while the worker isn't running?

<details>
<summary>Solution</summary>

```js
// Prints a confirmation "email" for every new order (LISTEN order_placed).
import pg from 'pg';
import { requireEnv } from '../src/config.js';

const connectionString = requireEnv('DATABASE_URL');
const db = new pg.Pool({ connectionString, max: 2, application_name: 'inkwell-order-worker' });
// LISTEN needs its own connection that stays open: never a pooled one (chapter 22).
const listener = new pg.Client({ connectionString, application_name: 'inkwell-order-listener' });

async function confirm(orderId) {
  const { rows: [order] } = await db.query(
    `SELECT o.id, c.email, c.full_name, sum(oi.quantity * oi.unit_price) AS total
     FROM orders AS o
     JOIN customers AS c ON c.id = o.customer_id
     JOIN order_items AS oi ON oi.order_id = o.id
     WHERE o.id = $1
     GROUP BY o.id, c.email, c.full_name`, [orderId]);
  console.log(`to ${order.full_name} <${order.email}>: thank you for order ${order.id}, total ${order.total}`);
}

listener.on('notification', ({ payload }) => {
  const { order_id: orderId } = JSON.parse(payload);
  confirm(orderId).catch((err) => console.error(`order ${orderId}: ${err.message}`));
});
listener.on('error', (err) => {
  console.error('listener connection lost:', err.message);
  process.exit(1); // let the process manager restart the worker
});

await listener.connect();
await listener.query('LISTEN order_placed');
console.log('waiting for orders');

process.once('SIGINT', async () => {
  await listener.end();
  await db.end();
});
```

```bash
node --env-file=.env scripts/order-worker.js
```

```text
waiting for orders
to Ben Carter <ben@example.com>: thank you for order 14, total 10.00
to Farah Haddad <farah@example.com>: thank you for order 15, total 30.00
```

It works while it runs. But notifications are only delivered to sessions that are listening at that moment (chapter
19). Orders placed while the worker is stopped, crashed or reconnecting are never confirmed, and nothing tells you.
`NOTIFY` is a wake-up signal, not a queue. Exercise 6 fixes that.

</details>

6. **Make the worker reliable.** Change the worker so that every order is confirmed at least once, including orders
   placed while no worker runs, and so that several workers can run at the same time without confirming an order
   twice. Use the database as the queue (chapter 17) and `NOTIFY` only to wake the workers up.

<details>
<summary>Solution</summary>

The orders themselves are the queue: a migration records whether an order was confirmed.

```sql
-- 008: remember which orders were confirmed, so the worker can catch up after downtime.
-- Existing orders count as confirmed. A non-volatile DEFAULT fills them without rewriting the table
-- (chapter 17); dropping the default afterwards makes new orders start unconfirmed (NULL).
ALTER TABLE orders ADD COLUMN confirmation_sent_at timestamptz DEFAULT now();
ALTER TABLE orders ALTER COLUMN confirmation_sent_at DROP DEFAULT;

-- The worker's queue: a partial index that only contains unconfirmed orders, so it stays tiny.
CREATE INDEX orders_unconfirmed_idx ON orders (id) WHERE confirmation_sent_at IS NULL;
```

The worker takes one unconfirmed order at a time with `FOR UPDATE SKIP LOCKED`, confirms it and marks it in the same
transaction. It drains the queue at startup and whenever a notification arrives:

```js
// Confirms one unconfirmed order; returns false when there is none left. SKIP LOCKED lets several workers
// share the queue without waiting for each other or confirming the same order twice (chapter 17).
async function confirmNext() {
  const client = await db.connect();
  try {
    await client.query('BEGIN');
    const { rows: [order] } = await client.query(
      `SELECT o.id, c.email, c.full_name
       FROM orders AS o
       JOIN customers AS c ON c.id = o.customer_id
       WHERE o.confirmation_sent_at IS NULL
       ORDER BY o.id
       LIMIT 1
       FOR UPDATE OF o SKIP LOCKED`);
    if (!order) {
      await client.query('COMMIT');
      return false;
    }
    // A real worker would send the email here. If it crashes after sending and before COMMIT, the order is
    // sent again after the restart: at-least-once delivery.
    console.log(`[${process.pid}] to ${order.full_name} <${order.email}>: thank you for order ${order.id}`);
    await client.query('UPDATE orders SET confirmation_sent_at = now() WHERE id = $1', [order.id]);
    await client.query('COMMIT');
    return true;
  } catch (err) {
    await client.query('ROLLBACK').catch(() => {});
    throw err;
  } finally {
    client.release();
  }
}

// Drains the queue. Notifications that arrive while draining set `again`, so none is lost.
let running = false;
let again = false;
async function drain() {
  if (running) {
    again = true;
    return;
  }
  running = true;
  try {
    do {
      again = false;
      while (await confirmNext());
    } while (again);
  } catch (err) {
    console.error('confirmation failed:', err.message);
  } finally {
    running = false;
  }
}

listener.on('notification', drain);
// ... the 'error' handler and the SIGINT handler as in exercise 5 ...

await listener.connect();
await listener.query('LISTEN order_placed'); // listen first, then catch up: no order slips through the gap
await drain();
console.log(`[${process.pid}] waiting for orders`);
```

(`db` and `listener` are created as in exercise 5.) To try it: place two orders with no worker running, start two
workers in two terminals, and place six more orders. Each order is confirmed exactly once; the two that waited are
picked up at startup:

```text
[14048] to Ana Souza <ana@example.com>: thank you for order 16
[14048] waiting for orders
[14048] to Chloé Martin <chloe@example.com>: thank you for order 18
[14048] to Dev Patel <dev@example.com>: thank you for order 19
...
```

```text
[14050] to Ben Carter <ben@example.com>: thank you for order 17
[14050] waiting for orders
[14050] to Emma Schulz-Berg <emma@example.com>: thank you for order 20
```

The order of `LISTEN` and the first `drain()` matters: draining first and listening afterwards would miss an order
placed in between. The delivery is *at least once*: sending an email can't be part of a database transaction, so a
crash between sending and `COMMIT` sends that email again. That's why real systems make the receiving side idempotent,
for example with the order id as a deduplication key. This design is often called the **transactional outbox**.

</details>

---
← Previous: [Backup, replication and operations](23-backup-replication-and-operations.md) | Next: [Back to the table of contents](README.md) →
