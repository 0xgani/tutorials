# Chapter 22 — Using PostgreSQL from Node.js and PHP

## What you'll learn

- What a driver does: the wire protocol, the simple and extended query protocols, and prepared statements
- Connecting from Node.js with the `pg` package (clients and pools) and from PHP with PDO, including TLS
- Parameters: why they make SQL injection impossible, and how to pass lists, JSON and booleans
- How PostgreSQL types map to JavaScript and PHP values, and the surprises (`bigint`, `numeric`, `date`)
- Transactions in application code: one connection per transaction, and retrying serialization failures
- Turning errors into responses by their SQLSTATE code, including your own codes from PL/pgSQL
- Passing the current user to row-level security, `LISTEN`/`NOTIFY` in an application, and a small migration runner
- Keyset pagination, and why `OFFSET` gets slower page by page
- Connection pools, how many connections to use, and what breaks behind a pooler like PgBouncer

Everything so far happened in `psql`. Real applications talk to PostgreSQL through a **driver**, a library that
speaks PostgreSQL's network protocol. This chapter connects the Inkwell database to Node.js and PHP. The SQL stays the
same; what's new is everything around it: connections, parameters, types, transactions, errors and pooling, which is
where most database bugs in applications live.

All the code is in [`examples/node/`](examples/node/) and [`examples/php/`](examples/php/). Each file is a short
script you run from the command line; the important parts are shown here too.

## Setting up

The examples expect the small Inkwell database plus a few things from earlier chapters: the search function
(chapters 13 and 19), `place_order()` with its custom error (chapter 19), and the roles and row-level security
policies from chapter 21. `examples/setup.sql` recreates them on a fresh database:

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
psql -X -v ON_ERROR_STOP=1 -d inkwell -f examples/setup.sql
```

The scripts connect as `inkwell_app`, the application role from chapter 21, never as a superuser. Both drivers read
the standard **`PG*` environment variables** that `psql` uses too, so one set of exports configures everything:

```bash
export PGHOST=localhost PGPORT=5432 PGDATABASE=inkwell PGUSER=inkwell_app PGPASSWORD=app-dev-password
```

(`PGPASSWORD` in the environment is fine for a development password. In production, passwords come from a secrets
manager or the platform's secret storage, or from a `~/.pgpass` file, chapter 2.)

For Node.js (22 or newer), install the [`pg`](https://node-postgres.com/) package, the standard PostgreSQL driver for
Node, also called node-postgres:

```bash
cd examples/node
npm install
node connect.js
```

The Node.js commands in this chapter run in `examples/node/`, the PHP ones in `examples/php/`. For PHP (8.3 or
newer), PDO and its PostgreSQL driver `pdo_pgsql` usually come with the PHP package (on Debian and Ubuntu, install
`php-pgsql`). Check that the driver is there:

```bash
php -m | grep pdo_pgsql
```

```text
pdo_pgsql
```

## How a driver talks to PostgreSQL

Each connection is a TCP (or Unix socket) connection to the server, which starts a **dedicated backend process** for
it (chapter 1). The client and the backend then exchange messages in PostgreSQL's **frontend/backend protocol**:

```text
application         driver                                   backend process
-----------         ------                                   ---------------
pool.query(sql, [v]) ──► Parse  "SELECT ... WHERE id = $1"  ──►  parse and analyze
                         Bind   $1 = '42'                   ──►  plan with the value
                         Execute                            ──►  run
                         Sync                               ──►
                    ◄── RowDescription, DataRow..., CommandComplete, ReadyForQuery
```

There are two ways to send a query:

- The **simple query protocol** sends one string of SQL text, which may contain several statements separated by `;`.
  It has no parameters. This is what `psql` uses, and what drivers use for a query without parameters.
- The **extended query protocol** splits the work into *Parse* (the SQL text with `$1`, `$2` placeholders), *Bind*
  (the parameter values, sent separately from the SQL) and *Execute*. One statement per Parse. Drivers use it for
  every query with parameters.

The Parse step creates a **prepared statement**: an unnamed one that's replaced by the next query, or a named one that
stays in the session and can be executed again without parsing. You can do the same by hand in SQL:

```sql
PREPARE customer_by_email(text) AS
    SELECT id, full_name, country FROM customers WHERE email = $1;
EXECUTE customer_by_email('emma@example.com');
SELECT name, parameter_types, from_sql FROM pg_prepared_statements;
DEALLOCATE customer_by_email;
```

```text
PREPARE
 id |  full_name  | country
----+-------------+---------
  5 | Emma Schulz | DE
(1 row)

       name        | parameter_types | from_sql
-------------------+-----------------+----------
 customer_by_email | {text}          | t
(1 row)

DEALLOCATE
```

Prepared statements save parsing and, after a few executions, planning (the generic plans of chapter 16). They are
**per connection**: a statement prepared on one connection doesn't exist on another, which matters for pools and
poolers later in this chapter.

## Connecting

### Node.js: clients and pools

`pg` has two main classes. A **`Client`** is one connection. A **`Pool`** keeps a set of open clients and lends them
out, because opening a connection (a TCP handshake, maybe TLS, authentication, and a new server process) takes
milliseconds, far longer than a simple query. A web application creates one pool when it starts and uses it for every
request. `examples/node/db.js`:

```js
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
```

When `DATABASE_URL` isn't set, `connectionString` is `undefined` and `pg` falls back to the `PG*` variables.
`application_name` shows up in `pg_stat_activity` and the server log, which is the first thing you'll want when
tracking down who runs a slow query. `connectionTimeoutMillis` defaults to 0, which means "wait forever" for a free
connection; set it, so that an overloaded application fails fast instead of hanging.

`pool.query()` borrows a connection, runs one query and gives the connection back. `examples/node/connect.js`:

```js
import { pool } from './db.js';

const { rows } = await pool.query(
  "SELECT current_user, current_database(), current_setting('server_version') AS version",
);
console.log(rows[0]);

const result = await pool.query(
  'SELECT id, full_name, created_at FROM customers WHERE country = $1 ORDER BY id',
  ['US'],
);
console.log(result.rowCount, 'rows:', result.rows);
console.log('columns:', result.fields.map((field) => field.name));

await pool.end(); // close all connections, so the process can exit
```

```bash
node connect.js
```

```text
{
  current_user: 'inkwell_app',
  current_database: 'inkwell',
  version: '18.6'
}
2 rows: [
  {
    id: '2',
    full_name: 'Ben Carter',
    created_at: 2026-01-11T14:30:00.000Z
  },
  {
    id: '6',
    full_name: 'Farah Haddad',
    created_at: 2026-03-03T16:20:00.000Z
  }
]
columns: [ 'id', 'full_name', 'created_at' ]
```

Each row is an object keyed by column name. Note that `id` came back as a **string**: more on that under
[Types](#types).

### PHP: PDO

**PDO** (PHP Data Objects) is PHP's built-in database interface, with a driver per database. `examples/php/db.php`:

```php
function db(): PDO
{
    $dsn = getenv('INKWELL_DSN') ?: 'pgsql:application_name=inkwell-php';

    return new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // the default since PHP 8.0; be explicit
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // rows as ['column' => value]
    ]);
}
```

The **DSN** (data source name) starts with `pgsql:` and continues with `key=value` pairs separated by semicolons, for
example `pgsql:host=db.example.com;port=5432;dbname=inkwell;sslmode=verify-full`. PDO hands them to libpq, PostgreSQL's
own C client library (the one `psql` uses), so every libpq connection parameter works, and anything not given comes
from the `PG*` environment variables. The user name and password can be in the DSN or the second and third arguments.

PHP's usual execution model has no long-lived process to keep a pool in: each request (or each PHP-FPM worker) opens
its own connection. Connecting is cheap enough for most sites, especially through a pooler; see
[Connection pools and poolers](#connection-pools-and-poolers).

### TLS from applications

Chapter 21 explained why `sslmode=verify-full` is the only safe choice across a network. With PDO it is exactly the
libpq setting:

```php
$pdo = new PDO('pgsql:host=db.inkwell.test;dbname=inkwell;sslmode=verify-full;sslrootcert=/etc/inkwell/ca.crt',
    'inkwell_app', getenv('INKWELL_DB_PASSWORD'));
```

`pg` uses Node's own TLS implementation, configured with an `ssl` object. Giving it a CA certificate verifies both the
certificate and the host name, like `verify-full`:

```js
import { readFileSync } from 'node:fs';

const pool = new pg.Pool({
  host: 'db.inkwell.test',
  ssl: { ca: readFileSync('/etc/inkwell/ca.crt', 'utf8') }, // rejectUnauthorized defaults to true
});
```

`ssl: true` verifies against Node's built-in list of public CAs, which is right for a managed database with a publicly
trusted certificate. In a connection string, `pg` also accepts `sslmode=verify-full&sslrootcert=/path/to/ca.crt`.
Avoid `sslmode=require` in `pg` connection strings: today `pg` treats it like `verify-full` and prints a deprecation
warning, and its next major version will give it libpq's weaker meaning. And never "fix" a certificate error with
`rejectUnauthorized: false`, which turns verification off.

Against the test server from chapter 21 (certificate for `localhost` and `db.inkwell.test`), connecting by IP address
fails in both drivers, as it should:

```text
node: Hostname/IP does not match certificate's altnames: IP: 127.0.0.1 is not in the cert's list:
php:  SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: server certificate for "localhost" (and 2 other names) does not match host name "127.0.0.1"
```

## Queries and parameters

### SQL injection

Never build SQL by pasting values into the text. `examples/node/injection.js` shows why:

```js
const email = "nobody@example.com' OR '1'='1"; // what an attacker types into a login form

// WRONG: the input becomes part of the SQL text.
const unsafe = await pool.query(`SELECT id, email FROM customers WHERE email = '${email}'`);
console.log('pasted into the SQL:', unsafe.rowCount, 'rows');

// RIGHT: the SQL text and the value travel separately; the value can't change the query.
const safe = await pool.query('SELECT id, email FROM customers WHERE email = $1', [email]);
console.log('sent as a parameter:', safe.rowCount, 'rows');
```

```bash
node injection.js
```

```text
pasted into the SQL: 8 rows
sent as a parameter: 0 rows
[
  { id: '3', sku: 'BP-001' },
  { id: '7', sku: 'NB-001' },
  { id: '11', sku: 'IN-001' }
]
```

The pasted version ran `WHERE email = 'nobody@example.com' OR '1'='1'`, which is true for every customer. With a
parameter, the server receives the SQL text and the value in different protocol messages (Parse and Bind), so the
value is only ever a value: a strange e-mail address that matches nobody. Escaping functions exist, but parameters
make the whole class of bugs impossible, and they're simpler. Use them for **every** value, including the ones you
"know" are safe.

In PDO, placeholders are `?` (positional) or `:name` (named); PDO rewrites them to `$1`, `$2` for PostgreSQL.
`examples/php/search.php` calls the search function from chapter 19:

```php
$stmt = db()->prepare('SELECT id, name, round(rank::numeric, 3) AS rank, snippet FROM search_products(:terms, :limit)');
$stmt->execute(['terms' => $terms, 'limit' => 5]);
$rows = $stmt->fetchAll();
```

```bash
php search.php blue ink
```

```text
2 result(s) for "blue ink"
11	0.999	Midnight Blue Ink 50ml	Deep <b>blue</b> fountain pen <b>ink</b> with subtle shading.
3	0.396	Everyday Ballpoint	A dependable disposable ballpoint pen with <b>blue</b> <b>ink</b>.
```

`node search.js blue ink` prints the same. A search box is a classic injection target; here the user's text goes
through a parameter into `websearch_to_tsquery()` (chapter 13), which accepts any input.

### Lists, identifiers and dynamic queries

- **A list of values is one parameter**, an array, compared with `= ANY ($1)`:
  `pool.query('SELECT id, sku FROM products WHERE id = ANY ($1) ORDER BY id', [[3, 7, 11]])` produced the three
  products above. Don't build `IN ($1, $2, $3)` with a varying number of placeholders: every list length becomes a
  different statement. PDO can't bind arrays; pass the array as text in PostgreSQL's array syntax, `'{3,7,11}'`, with
  `= ANY (?::bigint[])`, or as JSON.
- **Identifiers (table and column names) can't be parameters.** When the user picks a sort column, map their choice
  to a fixed list: `const column = { name: 'name', price: 'price' }[req.query.sort] ?? 'name'`. `pg` also exports
  `escapeIdentifier()` for names that really are dynamic; inside the database, `format('%I')` does the same
  (chapter 19).
- **Dynamic `WHERE` clauses** (optional filters on a search page) are built from fixed SQL fragments with placeholders,
  collecting the values in an array as you go. Only fragments you wrote go into the SQL text; only values go into
  the parameters.

## Types

The protocol sends values as text by default, and each driver decides what to turn them into. `examples/node/types.js`
selects one value of many types:

```sql
SELECT 42::integer                          AS int4,
       9007199254740993::bigint             AS int8,
       12.50::numeric(10, 2)                AS price,
       0.1::double precision                AS float8,
       true                                 AS bool,
       timestamptz '2026-04-09 20:05:00+00' AS ts,
       date '2026-04-09'                    AS day,
       '{"nib": "fine"}'::jsonb             AS doc,
       ARRAY['ink', 'pen']                  AS tags,
       '\xdeadbeef'::bytea                  AS bytes,
       interval '1 day 02:00'               AS iv,
       NULL                                 AS nothing;
```

```bash
node types.js
```

```text
{
  int4: 42,
  int8: '9007199254740993',
  price: '12.50',
  float8: 0.1,
  bool: true,
  ts: 2026-04-09T20:05:00.000Z,
  day: 2026-04-09T00:00:00.000Z,
  doc: { nib: 'fine' },
  tags: [ 'ink', 'pen' ],
  bytes: <Buffer de ad be ef>,
  iv: PostgresInterval { days: 1, hours: 2 },
  nothing: null
}
{ int8: 9007199254740993n, day: '2026-04-09' }
```

| PostgreSQL | Node.js (`pg` defaults) | Why / what to do |
|---|---|---|
| `integer`, `smallint`, `real`, `double precision` | `number` | fits exactly |
| `bigint` | **string** | a JavaScript `number` is exact only up to 2^53 − 1; `9007199254740993` would silently become `...992`. Parse as `BigInt`, or as `Number` if your ids stay small |
| `numeric` | **string** | money must not go through binary floating point; use a decimal library, or compute in SQL |
| `boolean`, `text`, `json`/`jsonb`, arrays | `boolean`, `string`, parsed JSON, arrays | as expected |
| `timestamptz` | `Date` | correct instant; microseconds are cut to milliseconds |
| `date` | **`Date` at local midnight** | a calendar date has no time zone; on a server in New York, `2026-04-09` becomes `2026-04-09T04:00:00.000Z`. Keep dates as strings |
| `bytea` | `Buffer` | |
| `interval` | an object | |

The ids in `connect.js` were strings because `bigint` identity columns are `bigint`. The end of `types.js` changes the
two most common defaults with **`setTypeParser`**, which applies to every query in the process from then on:

```js
pg.types.setTypeParser(pg.types.builtins.INT8, (value) => BigInt(value));
pg.types.setTypeParser(pg.types.builtins.DATE, (value) => value);
```

`BigInt` is exact, but `JSON.stringify()` refuses it, so an API needs to turn ids into strings or numbers anyway. Many
applications parse `bigint` with `Number(value)` instead, which is fine while ids stay below 2^53.

Values going the other way are converted too: a `Date` parameter is sent as a timestamp with its offset, objects as
JSON. One trap: **a JavaScript array is sent as a PostgreSQL array**, even for a `jsonb` parameter, so
`pool.query('SELECT $1::jsonb', [[1, 2]])` fails with `invalid input syntax for type json`. Send JSON as a string,
`JSON.stringify(items)`, as `errors.js` does below.

PDO maps fewer types. `examples/php/types.php`:

```bash
php types.php
```

```text
array(9) {
  ["int4"]=>
  int(42)
  ["int8"]=>
  int(9007199254740993)
  ["price"]=>
  string(5) "12.50"
  ["float8"]=>
  string(3) "0.1"
  ["bool"]=>
  bool(true)
  ["ts"]=>
  string(22) "2026-04-09 20:05:00+00"
  ["doc"]=>
  string(15) "{"nib": "fine"}"
  ["tags"]=>
  string(9) "{ink,pen}"
  ["nothing"]=>
  NULL
}
fine / 2026-04-09T20:05:00+00:00
inactive products: 1
SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type boolean: ""
CONTEXT:  unnamed portal parameter $1 = ''
```

Integers (PHP's `int` is 64-bit on 64-bit systems, so `bigint` fits) and booleans become PHP types; everything else
arrives as a string in PostgreSQL's text format, which you decode yourself: `json_decode()` for JSON, `new
DateTimeImmutable()` for timestamps (the `+00` offset is part of the string), a decimal library such as `bcmath` for
`numeric`. Arrays arrive in PostgreSQL's array syntax; to get a PHP array, select `to_jsonb(tags)` and decode the JSON.

The last two lines are PDO's boolean trap: `execute([false])` sends every value as a string, and PHP turns `false`
into `''`. Bind booleans with `bindValue(..., PDO::PARAM_BOOL)`, as `types.php` does, or send `'true'`/`'false'`.

**Time zones.** Neither driver changes the session's `TimeZone`, so timestamps are printed in the database's or
role's setting (Inkwell's database uses UTC, chapter 3). `pg` turns them into `Date` objects, which are absolute
instants, so the setting doesn't matter there. For PHP, set the zone you want to receive explicitly, for example with
`options='-c TimeZone=UTC'` in the DSN or `ALTER ROLE inkwell_app SET timezone = 'UTC'`, and always use `timestamptz`
(chapter 11).

## Transactions

### One connection per transaction

A transaction belongs to a **connection**. With a pool, `pool.query('BEGIN')` followed by `pool.query(...)` may run
the two statements on different connections: the second runs outside the transaction, and the `BEGIN` stays open on
a connection that goes back to the pool for someone else to stumble over. Borrow one client for the whole
transaction:

```js
const client = await pool.connect();
try {
  await client.query('BEGIN');
  // ... queries on client ...
  await client.query('COMMIT');
} catch (err) {
  await client.query('ROLLBACK');
  throw err;
} finally {
  client.release(); // always give it back, or the pool runs dry
}
```

Writing that in every function invites mistakes, so `db.js` wraps it in a helper, `withTransaction()`, which also
does the next section's retries:

```js
const ISOLATION_LEVELS = new Set(['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE']);
const RETRYABLE = new Set(['40001', '40P01']); // serialization_failure, deadlock_detected

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
```

The isolation level is checked against a fixed list before it goes into the SQL text, because it's an SQL keyword, not
a value that could be a parameter. In PHP, PDO has `beginTransaction()`, `commit()` and `rollBack()`, and there's only
one connection per `PDO` object, so the pool problem doesn't arise. `db.php` has the same helper as a function,
`transaction(PDO $pdo, callable $fn, string $isolation = 'READ COMMITTED', int $retries = 3)`.

Keep transactions **short**: no HTTP calls, no waiting for user input, no slow computation while a transaction is
open. It holds its locks and its snapshot until it ends, and an idle open transaction holds back `VACUUM` (chapter 18).
`idle_in_transaction_session_timeout` (chapter 17) is the safety net.

### Retrying serialization failures

Under `REPEATABLE READ` and `SERIALIZABLE`, PostgreSQL aborts a transaction that would otherwise produce an anomaly,
with SQLSTATE `40001` (chapter 17). Deadlocks (`40P01`) also abort one of the transactions involved. Both mean "run
the whole transaction again", reading everything afresh. `examples/node/retry.js` runs the lost-update pattern from
chapter 17, two concurrent read-modify-write transactions, under `REPEATABLE READ`:

```js
async function sellOneHeronPen(name, startAfter) {
  await sleep(startAfter);
  const stock = await withTransaction(async (client) => {
    const { rows } = await client.query('SELECT stock FROM products WHERE id = 2');
    console.log(`${name}: read stock ${rows[0].stock}`);
    await sleep(100); // the application thinks for a while...
    await client.query('UPDATE products SET stock = $1 WHERE id = 2', [rows[0].stock - 1]);
    return rows[0].stock - 1;
  }, {
    isolation: 'REPEATABLE READ',
    onRetry: (err, attempt) => console.log(`${name}: attempt ${attempt} failed with ${err.code} (${err.message}), retrying`),
  });
  console.log(`${name}: committed, stock now ${stock}`);
}

await pool.query('UPDATE products SET stock = 3 WHERE id = 2'); // the seed value, so the demo can be repeated
await Promise.all([sellOneHeronPen('A', 0), sellOneHeronPen('B', 20)]);
```

```bash
node retry.js
```

```text
A: read stock 3
B: read stock 3
A: committed, stock now 2
B: attempt 1 failed with 40001 (could not serialize access due to concurrent update), retrying
B: read stock 2
B: committed, stock now 1
final stock: 1
```

Under `READ COMMITTED`, B would have written 2 over A's 2: a lost update, and one pen sold twice. Under
`REPEATABLE READ`, B's update failed, and the retry read the current stock. (For this particular case,
`UPDATE products SET stock = stock - 1` is simpler and needs no retry; the retry pattern is for transactions whose
logic really does read first and decide later.)

`examples/php/retry.php` shows the same failure step by step with two connections, then fixes it with the
`transaction()` helper:

```php
// ...A writes and commits first...
$a->prepare('UPDATE products SET stock = ? WHERE id = 2')->execute([$stockA - 1]);
$a->commit();

// ...so B's write would overwrite a change it has never seen.
try {
    $b->prepare('UPDATE products SET stock = ? WHERE id = 2')->execute([$stockB - 1]);
    $b->commit();
} catch (PDOException $e) {
    echo 'B failed: ', $e->getCode(), ' ', $e->errorInfo[2], "\n";
    $b->rollBack();
}

// The fix: run the whole read-modify-write through transaction(), which retries from the start.
$stock = transaction($b, function (PDO $pdo): int {
    $stock = (int) $pdo->query('SELECT stock FROM products WHERE id = 2')->fetchColumn();
    $pdo->prepare('UPDATE products SET stock = ? WHERE id = 2')->execute([$stock - 1]);
    return $stock - 1;
}, 'REPEATABLE READ');
```

```bash
php retry.php
```

```text
A and B read stock 3 and 3
B failed: 40001 ERROR:  could not serialize access due to concurrent update
B committed, stock now 1
```

Rules for retries: retry the **whole** transaction, never a single statement; retry only `40001` and `40P01` (a
unique violation won't go away by trying again); limit the attempts; wait a little, with randomness, so that the
competing transactions don't collide again; and make sure nothing outside the database (an e-mail, a payment call)
happens inside the function that's retried.

## Errors

Every error from PostgreSQL carries a five-character **SQLSTATE** code. The first two characters are the class:

| Class | Meaning | Examples |
|---|---|---|
| `08` | connection exception | `08006` connection failure |
| `22` | data exception | `22P02` invalid input syntax, `22012` division by zero, `22001` string too long |
| `23` | integrity constraint violation | `23505` unique, `23503` foreign key, `23514` check, `23502` not null |
| `25` | invalid transaction state | `25P02` "current transaction is aborted" (an earlier statement failed) |
| `28` | invalid authorization | `28P01` wrong password |
| `40` | transaction rollback | `40001` serialization failure, `40P01` deadlock |
| `42` | syntax error or access rule violation | `42601` syntax error, `42501` insufficient privilege, `42P01` undefined table |
| `53` | insufficient resources | `53300` too many connections |
| `57` | operator intervention | `57014` query canceled (`statement_timeout`), `57P01` server shutting down |

Match on the **code**, never on the message: messages can be translated (`lc_messages`) and change between versions.
Codes don't. Your own codes from `RAISE ... USING ERRCODE` (chapter 19, `IW001`) work the same way.

In `pg`, a database error is a `DatabaseError` with the protocol's error fields as properties: `code`, `message`,
`detail`, `hint`, `constraint`, `table`, `column`, `schema`, `where` (the PL/pgSQL context), `position` and more.
`examples/node/errors.js` turns the errors a shop expects into HTTP-style answers:

```js
function toResponse(err) {
  switch (err.code) {
    case '23505': // unique_violation
      return { status: 409, message: 'already exists', constraint: err.constraint };
    case '23503': // foreign_key_violation
      return { status: 422, message: 'refers to a row that does not exist', constraint: err.constraint };
    case 'IW001': // raised by place_order() (chapter 19)
      return { status: 409, message: err.message, hint: err.hint };
    default:
      throw err;
  }
}
```

```bash
node errors.js
```

```text
duplicate e-mail: {
  status: 409,
  message: 'already exists',
  constraint: 'customers_email_key'
}
unknown customer: {
  status: 422,
  message: 'refers to a row that does not exist',
  constraint: 'orders_customer_id_fkey'
}
too many pens:    {
  status: 409,
  message: 'product 2 is not available in quantity 5',
  hint: 'Reload the basket to see current stock.'
}
a notebook:       { status: 200, result: { place_order: '15' } }
unexpected: { code: '22012', message: 'division by zero', severity: 'ERROR' }
```

The constraint name tells the application *which* rule failed: a unique violation on `customers_email_key` becomes
"this e-mail address is already registered" next to the right form field. That's a good reason to name constraints
deliberately (chapter 4). Errors nobody expects (the division by zero) are not the user's business: log them with all
their fields, and answer with a generic "something went wrong".

The new order got id 15, not 13: the two failed `place_order` calls had already taken 13 and 14 from the identity
sequence. Sequences never go back (chapter 4), so gaps in ids are normal.

PDO puts the SQLSTATE in `$e->getCode()` (a string) and in `$e->errorInfo[0]`; `errorInfo[2]` holds the server's
message with its `DETAIL` and `HINT` lines. There are no separate fields for the constraint or the hint, so an
application that needs them parses that text. `examples/php/errors.php`:

```php
function toResponse(PDOException $e): array
{
    // errorInfo is [SQLSTATE, driver error code, message (with DETAIL and HINT lines)]
    [$sqlstate, , $message] = $e->errorInfo;

    return match ($sqlstate) {
        '23505' => ['status' => 409, 'message' => 'already exists'],
        '23503' => ['status' => 422, 'message' => 'refers to a row that does not exist'],
        'IW001' => ['status' => 409, 'message' => strtok(substr($message, strlen('ERROR:  ')), "\n")],
        default => throw $e,
    };
}
```

```bash
php errors.php
```

```text
duplicate e-mail: {"status":409,"message":"already exists"}
too many pens: {"status":409,"message":"product 2 is not available in quantity 5"}
unexpected: 22012 SQLSTATE[22012]: Division by zero: 7 ERROR:  division by zero
```

One more error worth knowing: after any error inside a transaction, every further statement fails with `25P02`
(`current transaction is aborted, commands ignored until end of transaction block`) until you roll back. The helpers
above always roll back; code that catches an error and carries on inside the same transaction must use a savepoint
(chapter 17).

## Passing the user to row-level security

Chapter 21's customer portal relies on two per-transaction settings: the portal role, and `app.customer_id`. Both must
be set **inside the transaction, with `LOCAL`**, so that they disappear when the connection goes back to the pool and
the next request can't inherit them. `examples/setup.sql` lets `inkwell_app` switch to `inkwell_portal` (the
membership has `INHERIT FALSE, SET TRUE`), and `db.js` builds the portal's helper on top of `withTransaction()`:

```js
export function asCustomer(customerId, fn) {
  return withTransaction(async (client) => {
    await client.query('SET LOCAL ROLE inkwell_portal');
    await client.query("SELECT set_config('app.customer_id', $1, true)", [String(customerId)]);
    return fn(client);
  });
}
```

`SET LOCAL` can't take a parameter (it's a utility statement, not a query), but the function form
`set_config(name, value, is_local)` can, so the customer id travels as a parameter like any other value.
`examples/node/portal.js`:

```js
const anasOrders = await asCustomer(1, async (client) => {
  const { rows } = await client.query('SELECT id, status FROM orders ORDER BY id');
  return rows;
});
```

```bash
node portal.js
```

```text
customer 1 sees: [
  { id: '1', status: 'delivered' },
  { id: '4', status: 'delivered' },
  { id: '11', status: 'paid' }
]
customer 1 order lines: 6
outside: { current_user: 'inkwell_app', orders: 13 }
```

The query has no `WHERE customer_id = ...`; the policies added it. Outside `asCustomer()`, the same pooled connections
are back to `inkwell_app`, which sees all 13 orders (the twelve from the seed data plus the one `errors.js` placed).
This "one login role, switch per transaction" pattern is how several hosted PostgreSQL platforms implement per-user
security. It keeps one pool for all users, and it works through a pooler in transaction mode, because nothing outlives
the transaction.

## LISTEN and NOTIFY

Chapter 19 sent notifications from a trigger. Receiving them takes a connection that stays open and does nothing but
listen, so it must not be a pooled one: the pool would lend that connection to other code, and returning it would
end the `LISTEN`. `examples/node/listen.js`:

```js
const listener = new pg.Client({ connectionString: process.env.DATABASE_URL, application_name: 'inkwell-listener' });
listener.on('notification', ({ channel, payload }) => {
  console.log(`notification on ${channel}:`, JSON.parse(payload));
});
listener.on('error', (err) => {
  // A real service reconnects here, runs LISTEN again, and then checks the durable state
  // (the orders or jobs table) for anything it missed while it was disconnected.
  console.error('listener lost its connection:', err.message);
});
await listener.connect();
await listener.query('LISTEN order_paid');

// Elsewhere (another process, or a trigger as in chapter 19), an event is sent.
// pg_notify() takes the channel and payload as ordinary values, so parameters work.
await pool.query("SELECT pg_notify('order_paid', $1)", [JSON.stringify({ order_id: 11, customer_id: 1 })]);

// Notifications sent inside a transaction are delivered when it commits, and only then.
const client = await pool.connect();
await client.query('BEGIN');
await client.query("SELECT pg_notify('order_paid', $1)", [JSON.stringify({ order_id: 10, customer_id: 8 })]);
await client.query('ROLLBACK'); // never delivered
client.release();
```

```bash
node listen.js
```

```text
notification on order_paid: { order_id: 11, customer_id: 1 }
```

Only the first notification arrived; the second was rolled back with its transaction. The `NOTIFY` statement itself
can't take parameters, which is why applications send notifications with `pg_notify()`. Because notifications are
lost while nobody listens (chapter 19), a listener should treat them as a hint to look at a table, and after every
reconnect it should look anyway.

In PHP, the `pdo_pgsql` driver adds `pgsqlGetNotify()` to the connection, which waits up to a timeout for the next
notification (`examples/php/listen.php`; PHP 8.4+ also offers it as `Pdo\Pgsql::getNotify()`):

```php
$listener = db();
$listener->exec('LISTEN order_paid');

db()->prepare("SELECT pg_notify('order_paid', ?)")
    ->execute([json_encode(['order_id' => 11, 'customer_id' => 1])]);

// Wait up to 1000 ms for a notification; returns false if none arrived.
$notification = $listener->pgsqlGetNotify(PDO::FETCH_ASSOC, 1000);
var_dump($notification);
```

```bash
php listen.php
```

```text
array(3) {
  ["message"]=>
  string(10) "order_paid"
  ["pid"]=>
  int(9477)
  ["payload"]=>
  string(31) "{"order_id":11,"customer_id":1}"
}
```

`pid` is the sending backend's process id, so yours will differ. A long-running PHP worker (a queue consumer started
from the command line) can loop on `pgsqlGetNotify()`; a web request can't usefully wait for notifications.

## Migrations

A schema changes with the application: new tables, new columns, new indexes. **Migrations** are the numbered SQL
files that make those changes, kept in version control next to the code, and applied in order to every database
(development, test, production) by a tool that remembers which ones have run. Chapter 4 promised this; chapter 21 set
up the role to run them as.

Popular tools exist for every language: node-pg-migrate, Knex, Prisma Migrate and others for Node.js; Doctrine
Migrations, Phinx and Laravel's migrations for PHP; language-independent ones like Flyway, Liquibase, Sqitch and
dbmate. They all do what this 60-line runner, `examples/node/migrate.js`, does:

```js
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
```

It applies two migrations that come with the examples, `001_wishlist_items.sql` (the table from chapter 21) and
`002_orders_created_at_index.sql` (an index for the next section). Run it as the migration role, so that the new
objects belong to `inkwell_owner` and get its default privileges (chapter 21):

```bash
PGUSER=inkwell_migrate PGPASSWORD=migrate-dev-password node migrate.js
PGUSER=inkwell_migrate PGPASSWORD=migrate-dev-password node migrate.js
```

```text
applied 001_wishlist_items.sql
applied 002_orders_created_at_index.sql
2 migration(s) applied
database is up to date
```

The second run found nothing to do. Because each file runs in a transaction (PostgreSQL's DDL is transactional,
chapter 17), a migration that fails halfway leaves nothing behind. A file `003_broken.sql` with a `CREATE TABLE` and
then a failing statement stops the run, and the table doesn't exist afterwards:

```text
migration 003_broken.sql failed: division by zero (SQLSTATE 22012)
```

The rules that keep migrations safe:

- **Never edit a migration that has run anywhere else.** Fix mistakes with a new migration. (Exercise 5 makes the
  runner detect edited files.)
- **One runner, one role.** Migrations run as the owner role, from the deployment, before the new application code
  starts, never from every application instance at startup without a lock.
- **Some statements can't run in a transaction**, notably `CREATE INDEX CONCURRENTLY` (chapter 15). A runner needs a
  way to run such a file on its own, outside `BEGIN`/`COMMIT`, as a single statement (the real tools have a flag for
  it).
- **Old and new code run side by side** during a deployment. Make changes in backward-compatible steps (add a nullable
  column, deploy code that writes it, backfill, then add the constraint; chapter 17's migration checklist), and never
  rename or drop something the running code still uses.
- **Watch the locks.** Even a quick `ALTER TABLE` waits behind long-running queries and blocks everything behind it;
  set `lock_timeout` in migrations and retry (chapter 17).

## Pagination

Lists in an API come in pages. The obvious way, `LIMIT 20 OFFSET 40`, has a cost that grows with the page number:
PostgreSQL can't jump to row 40, it must produce and throw away the first 40 rows. And when rows are inserted
between two requests, rows shift from one page to the next, so users see duplicates or miss rows.

**Keyset pagination** (also called cursor or seek pagination) instead remembers where the last page ended, and asks
for rows after that point: `WHERE (created_at, id) < ($1, $2) ORDER BY created_at DESC, id DESC LIMIT 20`. The `id`
breaks ties between equal timestamps, so the order is total and no row is skipped. With an index on exactly that
order (migration 002), every page is an index range scan that starts at the right place.

The difference shows on a big table. Rebuild Inkwell with the bulk data from chapter 15, then set up and migrate it
again:

```bash
cd ../..    # the tutorial's directory
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
psql -X -v ON_ERROR_STOP=1 -d inkwell -f sql/bulk.sql
psql -X -v ON_ERROR_STOP=1 -d inkwell -f examples/setup.sql
cd examples/node
PGUSER=inkwell_migrate PGPASSWORD=migrate-dev-password node migrate.js
```

Page 25,001 (rows 500,001 to 500,020) with `OFFSET`:

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT id, customer_id, created_at
FROM orders
ORDER BY created_at DESC, id DESC
LIMIT 20 OFFSET 500000;
```

```text
                                                                        QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------------------------------------------
 Limit  (cost=24830.94..24831.93 rows=20 width=24) (actual time=107.612..107.618 rows=20.00 loops=1)
   Buffers: shared hit=2505 read=3581 written=848
   ->  Index Scan using orders_created_at_id_idx on orders  (cost=0.42..49662.04 rows=1000012 width=24) (actual time=0.119..87.886 rows=500020.00 loops=1)
         Index Searches: 1
         Buffers: shared hit=2505 read=3581 written=848
 Planning:
   Buffers: shared hit=117 read=2
 Planning Time: 0.389 ms
 Execution Time: 107.644 ms
(9 rows)
```

The index scan returned 500,020 rows to get 20. Keyset pagination needs the key of the last row of the previous page;
here it is:

```sql
SELECT created_at, id FROM orders ORDER BY created_at DESC, id DESC OFFSET 499999 LIMIT 1;
```

```text
          created_at           |   id
-------------------------------+--------
 2024-07-01 11:12:25.520229+00 | 500025
(1 row)
```

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT id, customer_id, created_at
FROM orders
WHERE (created_at, id) < ('2024-07-01 11:12:25.520229+00', 500025)
ORDER BY created_at DESC, id DESC
LIMIT 20;
```

```text
                                                                     QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------------------------------------
 Limit  (cost=0.42..1.76 rows=20 width=24) (actual time=0.019..0.023 rows=20.00 loops=1)
   Buffers: shared hit=3 read=1
   ->  Index Scan using orders_created_at_id_idx on orders  (cost=0.42..33526.56 rows=504054 width=24) (actual time=0.018..0.020 rows=20.00 loops=1)
         Index Cond: (ROW(created_at, id) < ROW('2024-07-01 11:12:25.520229+00'::timestamp with time zone, 500025))
         Index Searches: 1
         Buffers: shared hit=3 read=1
 Planning Time: 0.134 ms
 Execution Time: 0.054 ms
(8 rows)
```

The row comparison `(created_at, id) < (...)` became the index condition, and the scan read 20 rows, the same as for
the first page. Your timings will differ; the ratio won't.

In the API, the key goes into an opaque **cursor** string that the client sends back for the next page.
`examples/node/paginate.js`:

```js
// The cursor holds the sort key of the last row on the page: (created_at, id).
// created_at goes into it as PostgreSQL's own text, because a JavaScript Date
// keeps only milliseconds and timestamptz has microseconds.
async function ordersPage(cursor, pageSize = 5) {
  const params = [pageSize];
  let where = '';
  if (cursor) {
    const { createdAt, id } = JSON.parse(Buffer.from(cursor, 'base64url').toString());
    where = 'WHERE (created_at, id) < ($2::timestamptz, $3::bigint)';
    params.push(createdAt, id);
  }
  const { rows } = await pool.query(
    `SELECT id, customer_id, created_at, created_at::text AS created_at_key
     FROM orders
     ${where}
     ORDER BY created_at DESC, id DESC
     LIMIT $1`,
    params,
  );
  const last = rows.at(-1);
  const next = rows.length === pageSize
    ? Buffer.from(JSON.stringify({ createdAt: last.created_at_key, id: last.id })).toString('base64url')
    : null;
  return { orders: rows.map(({ id, customer_id }) => ({ id, customer_id })), next };
}
```

```bash
node paginate.js
```

```text
page 1: 12, 11, 10, 9, 8
page 2: 7, 6, 5, 4, 3
page 3: 2, 1, 1000012, 1000011, 1000010
next cursor: eyJjcmVhdGVkQXQiOiIyMDI1LTEyLTMwIDIxOjQyOjIwLjMyMzI4OCswMCIsImlkIjoiMTAwMDAxMCJ9
```

The twelve seed orders from 2026 come first, then the newest bulk orders from the end of 2025. The cursor is just
base64url-encoded JSON, `{"createdAt":"2025-12-30 21:42:20.323288+00","id":"1000010"}`. Clients must treat it as
opaque, and since they can decode and change it, the server must treat it as untrusted input: here it only ever
becomes two parameters. The `${where}` in the SQL text is one of two fixed fragments, never user input. Note the microseconds trap in the comment: building the cursor from the `Date` object would round
`11:12:25.520229` to `11:12:25.520`, and the next page would start in the wrong place.

Keyset pagination can't jump to "page 7", only to the next (or, with the comparison reversed, the previous) page. For
infinite scrolling and APIs that's exactly right; for a numbered page list, `OFFSET` is acceptable while the numbers
stay small, and nobody really reads page 25,001 anyway.

## Connection pools and poolers

### How many connections?

Every connection is a server process with its own memory, and every *active* connection competes for CPU cores and
disk. Beyond a certain point, more connections make the database slower, not faster: they wait on each other's locks,
fight over the CPU, and each can use `work_mem` several times over. A good starting point for the number of
connections that actually run queries at the same time is a small multiple of the server's CPU cores (two to four
times), not hundreds.

The total matters, not the per-process setting: ten application instances with `max: 20` each can open 200
connections. Make sure the sum stays under the server's `max_connections` (100 by default), minus the slots reserved
for superusers (`superuser_reserved_connections`, 3) and for `pg_use_reserved_connections` (`reserved_connections`,
16+), minus what migrations, cron jobs and your own `psql` sessions need.

`pg` pool settings worth knowing:

| Option | Default | Use |
|---|---|---|
| `max` | 10 | the maximum number of connections in this pool |
| `idleTimeoutMillis` | 10000 | close connections idle for this long (0: never) |
| `connectionTimeoutMillis` | 0 (wait forever) | give up waiting for a free connection; set it |
| `maxUses` | `Infinity` | replace a connection after this many uses (helps balance load behind a load balancer) |
| `statement_timeout`, `lock_timeout`, `idle_in_transaction_session_timeout` | server's | session timeouts, sent when connecting (chapter 17) |
| `query_timeout` | none | a client-side timeout; prefer `statement_timeout`, which the server enforces |

Call `pool.end()` on shutdown so that connections are closed cleanly.

**PHP** usually runs many short-lived worker processes, each opening its own connection per request. PDO's
**persistent connections** (`PDO::ATTR_PERSISTENT => true`) keep a connection open per worker instead; they save the
connection time, but they carry session state (settings, temporary tables, an open transaction after a crash) from
one request to the next, and with many workers they hold many idle connections. For PHP at scale, the better answer
is a pooler.

### Poolers: PgBouncer

A **connection pooler** sits between the applications and PostgreSQL. Thousands of client connections come in; a few
dozen server connections go out. The most widely used is **PgBouncer**; others include PgCat, Odyssey, Supavisor and
the proxies of cloud providers. A minimal `pgbouncer.ini`:

```ini
[databases]
inkwell = host=127.0.0.1 port=5432 dbname=inkwell

[pgbouncer]
listen_addr = 0.0.0.0
listen_port = 6432
auth_type = scram-sha-256
auth_file = /etc/pgbouncer/userlist.txt
pool_mode = transaction
default_pool_size = 20
max_client_conn = 2000
max_prepared_statements = 200
```

Applications connect to port 6432 instead of 5432. The **pool mode** decides when a server connection goes back to
the pool:

| Mode | A server connection is assigned... | Session features | Efficiency |
|---|---|---|---|
| `session` | for the whole client connection | all work | low: same as no pooler, minus the connection setup cost |
| `transaction` | for one transaction at a time | **not all** (below) | high: idle clients hold no server connection |
| `statement` | for one statement at a time; multi-statement transactions are refused | few | highest, rarely usable |

**Transaction mode** is the usual choice, and it changes the rules: two transactions from the same client may run on
different server connections. Anything that lives in the **session** across transactions breaks or leaks:

- **`SET`** without `LOCAL` changes a server connection that other clients will use next. Use `SET LOCAL` or
  `set_config(..., true)` inside a transaction, as `asCustomer()` does, or set per-role defaults (chapter 21).
- **`LISTEN`** needs a session; connect listeners directly to PostgreSQL, bypassing the pooler.
- **Session-level advisory locks** (`pg_advisory_lock`) may be unlocked on a different connection than they were
  taken on; use the transaction-level ones (chapter 17), or run the migration runner directly against PostgreSQL.
- **Temporary tables** and **`WITH HOLD` cursors** disappear or turn up elsewhere.
- **Prepared statements**: SQL-level `PREPARE` is passed straight through, so a later `EXECUTE` may land on a server
  connection that never saw it. Protocol-level named prepared statements (PDO prepares every statement that way by
  default) are tracked by PgBouncer 1.21+ when `max_prepared_statements` is above zero (it is 200 by default since
  1.24). With it set to 0, or with older poolers, PDO fails with `prepared statement "pdo_stmt_00000001" does not
  exist`; turn them off there (`PDO::ATTR_EMULATE_PREPARES => true`, which makes PDO send values inside the SQL text,
  escaped by the driver). `pg` uses unnamed statements unless you give a query a `name`, so it's unaffected.

Many applications use both: the web app through PgBouncer in transaction mode, and migrations, listeners and admin
work directly against PostgreSQL.

## ORMs and query builders

Most applications use a layer above the driver: an **ORM** (Prisma, TypeORM, Sequelize, Drizzle for Node.js; Doctrine
and Eloquent for PHP) or a **query builder** (Knex, Kysely, Laravel's query builder). They generate the SQL and map
rows to objects, and they use the same driver underneath, so everything in this chapter still applies. Things to
keep in mind:

- **Know the SQL they generate.** Turn on query logging in development, and read it. The classic problem is
  **N + 1 queries**: loading 50 orders, then each order's items with a separate query. Ask for eager loading or write
  a join.
- **Use SQL where it's the better tool.** Reports with window functions, CTEs, `JSON_TABLE` or full-text search are
  clearer as SQL (or as a view or function in the database) than as builder calls. Every ORM has a raw-query escape
  hatch that still takes parameters.
- **Constraints belong in the database**, not only in the ORM's validation. Validations can't see concurrent
  transactions; a unique constraint can (chapter 17).

## Key takeaways

- A driver speaks PostgreSQL's protocol. Queries with parameters use the extended protocol: the SQL text and the
  values travel in separate messages, which makes SQL injection impossible. Use parameters for every value; map
  identifiers through a fixed list.
- One pool per Node.js process; `pool.query()` for single statements, and one borrowed client (`pool.connect()`, then
  `release()`) for a transaction. PDO is one connection per object. Set `application_name` and timeouts.
- Know the type mapping: in `pg`, `bigint` and `numeric` arrive as strings and `date` as a `Date` at local midnight;
  in PDO, most things arrive as strings, and booleans must be bound with `PDO::PARAM_BOOL`. Send JSON as a string.
- Use `sslmode=verify-full` (PDO) or an `ssl` object with the CA (`pg`); never disable certificate verification.
- Retry whole transactions on `40001` and `40P01`, a limited number of times, with jittered backoff, and keep side
  effects out of the retried code.
- Match errors on their SQLSTATE code and, for constraint violations, on the constraint name. Your own codes from
  PL/pgSQL work the same way. Log unexpected errors; don't show them to users.
- Pass per-request context (the current user for row-level security) with `SET LOCAL` and `set_config(..., true)`
  inside the transaction. Listeners need their own, non-pooled connection.
- Migrations are versioned SQL files applied in order, each in a transaction, by one runner under a lock, as the owner
  role. Never edit an applied migration.
- Paginate with keysets (`WHERE (created_at, id) < (...)` plus a matching index), not large `OFFSET`s.
- Keep the total number of connections small. Behind a transaction-mode pooler, nothing may depend on session state:
  no plain `SET`, no `LISTEN`, no session advisory locks, no SQL-level `PREPARE`.

## Exercises

Run the solutions from `examples/node/` and `examples/php/`, with the environment variables from
[Setting up](#setting-up). They work on the small database (reset, then `examples/setup.sql`).

1. **Look up a customer.** Write `customer.php`, which takes an e-mail address on the command line and prints the
   customer's name, country and number of orders, or "no customer with that e-mail" (and exit code 1) when there's
   none.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

require __DIR__ . '/db.php';

$email = $argv[1] ?? exit("usage: php customer.php EMAIL\n");

$stmt = db()->prepare(<<<'SQL'
    SELECT c.full_name, c.country, count(o.id) AS orders
    FROM customers c
    LEFT JOIN orders o ON o.customer_id = c.id
    WHERE c.email = lower(:email)
    GROUP BY c.id
    SQL);
$stmt->execute(['email' => $email]);
$customer = $stmt->fetch();

if ($customer === false) {
    fwrite(STDERR, "no customer with that e-mail\n");
    exit(1);
}
printf("%s (%s): %d order(s)\n", $customer['full_name'], $customer['country'], $customer['orders']);
```

```bash
php customer.php Emma@Example.com
php customer.php zoe@example.com; echo "exit code $?"
```

```text
Emma Schulz (DE): 2 order(s)
no customer with that e-mail
exit code 1
```

`fetch()` returns `false` when there is no row. `lower(:email)` matches the table's rule that e-mail addresses are
stored in lower case (chapter 4). `count(o.id)` with a `LEFT JOIN` counts 0 for a customer without orders, and
`count()` returns `bigint`, which PDO gives you as an `int`.

</details>

2. **Many rows in one statement.** Write a Node.js function `addToWishlist(customerId, productIds)` that adds several
   products to a customer's wishlist (the `wishlist_items` table from migration 001) with **one** `INSERT`, whatever
   the number of products, ignoring products that are already on the list. Return the product ids that were actually
   added. Hint: `unnest()` turns an array parameter into rows.

<details>
<summary>Solution</summary>

```js
// wishlist.js
import { pool } from './db.js';

export async function addToWishlist(customerId, productIds) {
  const { rows } = await pool.query(
    `INSERT INTO wishlist_items (customer_id, product_id)
     SELECT $1, product_id FROM unnest($2::bigint[]) AS product_id
     ON CONFLICT DO NOTHING
     RETURNING product_id`,
    [customerId, productIds],
  );
  return rows.map((row) => Number(row.product_id));
}

console.log(await addToWishlist(1, [2, 7, 11]));
console.log(await addToWishlist(1, [7, 12]));
await pool.end();
```

```bash
PGUSER=inkwell_migrate PGPASSWORD=migrate-dev-password node migrate.js   # if you haven't yet
node wishlist.js
```

```text
applied 001_wishlist_items.sql
applied 002_orders_created_at_index.sql
2 migration(s) applied
[ 2, 7, 11 ]
[ 12 ]
```

The whole array is one parameter, so the statement text is the same for 1 or 1,000 products and the server can reuse
its plan. `ON CONFLICT DO NOTHING` skips products already on the list (the primary key is
`(customer_id, product_id)`), and `RETURNING` reports only the rows that were inserted. For several columns, pass
several arrays: `unnest($2::bigint[], $3::integer[]) AS t(product_id, quantity)` zips them into rows.

</details>

3. **Deadlock retry.** Two transfers of stock between products run at the same time: one moves a unit from product 7
   to product 8, the other from 8 to 7, each updating "its" source product first. Write a Node.js script that runs
   both with `withTransaction()`, with a pause between the two updates so that they deadlock, and show that the
   helper retries the victim and both transfers complete. Then explain how to avoid the deadlock altogether.

<details>
<summary>Solution</summary>

```js
// deadlock.js
import { pool, withTransaction } from './db.js';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function transfer(from, to) {
  await withTransaction(async (client) => {
    await client.query('UPDATE products SET stock = stock - 1 WHERE id = $1', [from]);
    await sleep(100); // both transactions now hold one row lock each
    await client.query('UPDATE products SET stock = stock + 1 WHERE id = $1', [to]);
  }, {
    onRetry: (err, attempt) => console.log(`transfer ${from}->${to}: attempt ${attempt} failed with ${err.code}, retrying`),
  });
  console.log(`transfer ${from}->${to}: done`);
}

const before = await pool.query('SELECT id, stock FROM products WHERE id IN (7, 8) ORDER BY id');
await Promise.all([transfer(7, 8), transfer(8, 7)]);
const after = await pool.query('SELECT id, stock FROM products WHERE id IN (7, 8) ORDER BY id');
console.log('before:', before.rows, 'after:', after.rows);
await pool.end();
```

```bash
node deadlock.js
```

```text
transfer 7->8: attempt 1 failed with 40P01, retrying
transfer 8->7: done
transfer 7->8: done
before: [ { id: '7', stock: 75 }, { id: '8', stock: 60 } ] after: [ { id: '7', stock: 75 }, { id: '8', stock: 60 } ]
```

Each transaction locked its source row, then waited for the other's. After `deadlock_timeout` (1 s by default), the
server's deadlock detector aborted one of them with `40P01`, the other went ahead, and the helper ran the victim again.
The stock is unchanged overall, as two opposite transfers should leave it. Which transaction is the victim isn't
predictable, so the lines may come out the other way round. To avoid the deadlock instead of recovering from it,
**lock rows in a consistent order**, for example by updating the lower product id first whatever the direction, as
`place_order()` does (chapter 17), or lock both rows up front with
`SELECT ... WHERE id IN ($1, $2) ORDER BY id FOR UPDATE`.

</details>

4. **A job worker.** Create a `jobs` table (`id`, `kind`, `payload jsonb`, `run_at`, `done_at`) and a Node.js worker
   that waits for work with `LISTEN jobs`, takes jobs with `FOR UPDATE SKIP LOCKED` (chapter 17) inside a transaction,
   marks them done, and also checks the table at startup, so that jobs queued while it wasn't running aren't lost. Test
   it by queuing one job before starting the worker and one while it runs.

<details>
<summary>Solution</summary>

The table, created as the owner (in a real project, as a migration):

```sql
SET ROLE inkwell_owner;
CREATE TABLE jobs (
    id       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind     text NOT NULL,
    payload  jsonb NOT NULL DEFAULT '{}',
    run_at   timestamptz NOT NULL DEFAULT now(),
    done_at  timestamptz
);
CREATE INDEX jobs_pending_idx ON jobs (run_at) WHERE done_at IS NULL;
RESET ROLE;
INSERT INTO jobs (kind, payload) VALUES ('send_receipt', '{"order_id": 11}');
```

```text
SET
CREATE TABLE
CREATE INDEX
RESET
INSERT 0 1
```

The worker:

```js
// worker.js
import pg from 'pg';
import { pool, withTransaction } from './db.js';

// Takes and finishes one due job; returns false when there is none.
async function runOneJob() {
  return withTransaction(async (client) => {
    const { rows } = await client.query(
      `SELECT id, kind, payload FROM jobs
       WHERE done_at IS NULL AND run_at <= now()
       ORDER BY run_at
       LIMIT 1
       FOR UPDATE SKIP LOCKED`,
    );
    if (rows.length === 0) return false;
    const job = rows[0];
    console.log(`running job ${job.id}: ${job.kind}`, job.payload); // the real work goes here
    await client.query('UPDATE jobs SET done_at = now() WHERE id = $1', [job.id]);
    return true;
  });
}

let draining = false;
async function drain() {
  if (draining) return; // a notification during a drain is covered by the drain's loop
  draining = true;
  try {
    while (await runOneJob());
  } finally {
    draining = false;
  }
}

const listener = new pg.Client({ connectionString: process.env.DATABASE_URL });
await listener.connect();
listener.on('notification', () => drain());
await listener.query('LISTEN jobs');
await drain(); // jobs queued while no worker was listening

// For the test: queue a job while the worker runs, then stop after a moment.
await pool.query(`INSERT INTO jobs (kind, payload) VALUES ('send_receipt', '{"order_id": 10}')`);
await pool.query(`SELECT pg_notify('jobs', '')`);
await new Promise((resolve) => setTimeout(resolve, 300));
await listener.end();
await pool.end();
```

```bash
node worker.js
```

```text
running job 1: send_receipt { order_id: 11 }
running job 2: send_receipt { order_id: 10 }
```

The first job was found by the startup drain, the second through the notification. Several workers can run at once:
`SKIP LOCKED` makes each one skip jobs another worker is holding, instead of waiting for them. In a real application,
the code that queues a job sends the notification in the same transaction (or a trigger does), so the worker is
woken exactly when the job becomes visible. A failing job would need a retry count and an error column (chapter 17's
exercise), and a `run_at` in the future needs a timer, since nothing notifies the worker when that time arrives.

</details>

5. **Catch edited migrations.** Extend `migrate.js` so that it stores a SHA-256 checksum of each migration file when
   it applies it, and refuses to run when a file that has already been applied has changed since. Test it by editing
   `001_wishlist_items.sql` after it has run.

<details>
<summary>Solution</summary>

The changes to `migrate.js`: a `checksum` column, and a check before applying anything.

```js
import { createHash } from 'node:crypto';

const sha256 = (text) => createHash('sha256').update(text).digest('hex');

// After taking the advisory lock:
await client.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
                      version     text PRIMARY KEY,
                      applied_at  timestamptz NOT NULL DEFAULT now())`);
await client.query('ALTER TABLE schema_migrations ADD COLUMN IF NOT EXISTS checksum text');

const { rows } = await client.query('SELECT version, checksum FROM schema_migrations');
const applied = new Map(rows.map((row) => [row.version, row.checksum]));
const files = (await readdir(dir)).filter((name) => name.endsWith('.sql')).sort();

const sources = new Map();
for (const file of files) {
  const sql = await readFile(join(dir, file), 'utf8');
  sources.set(file, sql);
  const stored = applied.get(file);
  if (stored && stored !== sha256(sql)) {
    throw new Error(`${file} was changed after it was applied; add a new migration instead`);
  }
}

// In the loop, read the file from `sources` and record its checksum:
//   await client.query('INSERT INTO schema_migrations (version, checksum) VALUES ($1, $2)', [file, sha256(sql)]);
```

Migrations recorded before this change have no checksum (`stored` is `NULL`) and are accepted; a real tool would
backfill them. After applying the migrations with the new version and then adding a comment line to
`001_wishlist_items.sql`, the runner stops with an uncaught error whose message is:

```text
Error: 001_wishlist_items.sql was changed after it was applied; add a new migration instead
```

Checking all files *before* applying any keeps the database untouched when the check fails. Comparing checksums is
what Flyway and several other tools do; it catches the "quick fix" to an old migration that would make a fresh
database differ from production.

</details>

---
← Previous: [Roles, privileges and row-level security](21-roles-privileges-and-row-level-security.md) | Next: [Backup, replication and operations](23-backup-replication-and-operations.md) →
