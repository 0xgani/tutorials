# Chapter 5 — Inserting, updating and deleting

## What you'll learn

- Emptying tables with `TRUNCATE`, and resetting identity columns
- `INSERT` with column lists, multiple rows, `DEFAULT`, and `INSERT ... SELECT`
- Getting generated values back with `RETURNING`, including old and new values (PostgreSQL 18+)
- How to load the full Inkwell sample data, and the reset script you'll use for the rest of the tutorial
- `UPDATE`, `UPDATE ... FROM`, `DELETE` and `DELETE ... USING`
- Transactions: `BEGIN`, `COMMIT`, `ROLLBACK`, savepoints, and what an error does to a transaction
- Upserts with `INSERT ... ON CONFLICT`
- `MERGE` for synchronizing a table with a source of changes
- Bulk loading with `COPY`, including skipping bad rows (PostgreSQL 17+) and limiting how many (18+)

## Starting from empty tables

The tables from chapter 4 still hold the test rows you inserted while trying out the constraints. `TRUNCATE` empties
tables much faster than `DELETE`: instead of deleting rows one by one, it replaces the table's storage with a fresh,
empty file. `RESTART IDENTITY` also resets the identity sequences, so the next customer gets id 1 again:

```sql
TRUNCATE customers, categories, products, orders, order_items, reviews RESTART IDENTITY;
```

```text
TRUNCATE TABLE
```

`TRUNCATE` refuses to empty a table that other tables reference, unless those tables are emptied in the same command
(as here) or you add `CASCADE`, which truncates every referencing table too. Remember that: `TRUNCATE ... CASCADE`
can empty far more than you meant to.

| | `DELETE FROM t` | `TRUNCATE t` |
|---|---|---|
| Speed on big tables | slow: visits and marks every row | instant, regardless of size |
| `WHERE` clause | yes | no, always everything |
| Fires row triggers | yes | no (only `TRUNCATE` triggers) |
| Space returned to the OS | only after VACUUM (chapter 18) | immediately |
| Transactional | yes | yes, it can be rolled back |
| Locks | row locks | an exclusive lock on the table: blocks even readers while it runs |

## INSERT

### One row, several rows

```sql
INSERT INTO customers (email, full_name, country, created_at)
VALUES ('ana@example.com', 'Ana Souza', 'BR', '2026-01-05 09:12:00+00');
```

```text
INSERT 0 1
```

Always list the columns. The values match the list by position, and columns you leave out get their default (or
`NULL`). Here `id` is generated, and `created_at` is given explicitly because the sample data records when each
customer signed up; normally you'd leave it to its `DEFAULT now()`.

Several rows in one statement are faster than separate statements (one round trip, one transaction):

```sql
INSERT INTO customers (email, full_name, country, created_at)
VALUES ('ben@example.com',   'Ben Carter',   'US', '2026-01-11 14:30:00+00'),
       ('chloe@example.com', 'Chloé Martin', 'FR', '2026-01-20 18:45:00+00')
RETURNING id, email;
```

```text
 id |       email
----+-------------------
  2 | ben@example.com
  3 | chloe@example.com
(2 rows)

INSERT 0 2
```

### RETURNING

`RETURNING` makes an `INSERT`, `UPDATE`, `DELETE` or `MERGE` return rows, like a `SELECT`, for the rows it changed. It
is the way to get generated ids and defaults back without a second query, and it is atomic: the values are exactly
the ones that were written. Any expression works in the list, including `*`.

The keyword `DEFAULT` in place of a value asks explicitly for the column's default:

```sql
INSERT INTO customers (email, full_name, country, created_at)
VALUES ('dev@example.com', 'Dev Patel', 'GB', DEFAULT)
RETURNING id, created_at > '2026-10-01' AS recent;
```

```text
 id | recent
----+--------
  4 | t
(1 row)

INSERT 0 1
```

### INSERT ... SELECT

Instead of `VALUES`, an `INSERT` can take its rows from a query. That is the standard way to copy, transform or
archive data inside the database, without sending it through a client. For example, a quick backup of some rows
before a risky change:

```sql
CREATE TABLE customers_backup (LIKE customers);

INSERT INTO customers_backup
SELECT * FROM customers
WHERE country <> 'GB';
```

```text
CREATE TABLE
INSERT 0 3
```

`CREATE TABLE ... (LIKE customers)` copies the column definitions (without the identity property, so explicit ids can
be inserted). `SELECT *` matches the columns by position, which is safe here only because both tables were just made
with identical columns. You'll see more realistic uses of `INSERT ... SELECT` throughout the tutorial. Drop the backup
table again:

```sql
DROP TABLE customers_backup;
```

```text
DROP TABLE
```

## The Inkwell sample data

Typing in seventy rows of sample data by hand teaches nothing new. The tutorial's [`sql/`](sql/) directory has
three files that do it for you:

| File | Contents |
|---|---|
| [`schema.sql`](sql/schema.sql) | every `CREATE` statement from chapter 4 |
| [`seed.sql`](sql/seed.sql) | the sample data: 8 customers, 10 categories, 14 products, 12 orders with 21 order lines, 9 reviews |
| [`reset.sql`](sql/reset.sql) | drops the `inkwell` database, creates it again, loads `schema.sql` and `seed.sql` |

Here is `reset.sql`:

```sql
-- reset.sql (excerpt)
DROP DATABASE IF EXISTS inkwell WITH (FORCE);

CREATE DATABASE inkwell
    TEMPLATE template0
    ENCODING 'UTF8'
    LOCALE_PROVIDER icu
    ICU_LOCALE 'en';

ALTER DATABASE inkwell SET timezone TO 'UTC';

\connect inkwell
\ir schema.sql
\ir seed.sql
ANALYZE;
```

- `WITH (FORCE)` disconnects any other sessions using the database, which would otherwise block the `DROP`.
- The database is created with ICU's English collation, so text sorts identically whatever operating system you
  run, and with the `UTF8` encoding. `template0` is required whenever you choose locale settings that differ from the
  cluster's defaults (chapter 11 explains collations).
- `ALTER DATABASE ... SET timezone` makes every new session in this database use UTC, so the timestamps you see match
  the ones printed in this tutorial. (A real application database would normally keep the server default and let each
  client choose; chapter 11 discusses this.)
- `\ir` includes the other files relative to the script's own directory, so it works from anywhere.
- `ANALYZE` collects statistics for the planner (chapter 16).

And an excerpt of `seed.sql`. It relies on the database being fresh, so that the identity columns number the rows in
the order of each `VALUES` list:

```sql
-- seed.sql (excerpt)
INSERT INTO categories (parent_id, name, slug) VALUES
    (NULL, 'Writing',        'writing'),          -- 1
    (1,    'Pens',           'pens'),             -- 2
    (2,    'Fountain pens',  'fountain-pens'),    -- 3
    ...

INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES
    (1, 1, 1, 48.00), (1, 11, 2, 16.00),
    ...
```

Run the reset script **from the shell**, in the tutorial's directory. It must connect to another database (here
`postgres`), because you can't drop the database you're connected to. If you're in `psql`, quit first:

```bash
cd postgresql-tutorial
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
```

```text
DROP DATABASE
CREATE DATABASE
ALTER DATABASE
You are now connected to database "inkwell" as user "postgres".
CREATE TYPE
CREATE TABLE
...
INSERT 0 9
ANALYZE
```

**From now on, every chapter starts from this state.** Whenever you've experimented and want the original data back,
run the same command again; it takes about a second. Now reconnect with `psql inkwell` and look at what you have.

```sql
SELECT id, parent_id, name, slug FROM categories ORDER BY id;
```

```text
 id | parent_id |      name      |      slug
----+-----------+----------------+----------------
  1 |           | Writing        | writing
  2 |         1 | Pens           | pens
  3 |         2 | Fountain pens  | fountain-pens
  4 |         2 | Ballpoint pens | ballpoint-pens
  5 |         1 | Pencils        | pencils
  6 |         1 | Ink            | ink
  7 |           | Paper          | paper
  8 |         7 | Notebooks      | notebooks
  9 |         7 | Loose paper    | loose-paper
 10 |           | Accessories    | accessories
(10 rows)
```

```sql
SELECT id, sku, name, price, stock, active FROM products ORDER BY id;
```

```text
 id |  sku   |          name          | price  | stock | active
----+--------+------------------------+--------+-------+--------
  1 | FP-001 | Kestrel Fountain Pen   |  48.00 |    12 | t
  2 | FP-002 | Heron Fountain Pen     | 129.00 |     3 | t
  3 | BP-001 | Everyday Ballpoint     |   2.50 |   400 | t
  4 | BP-002 | Click Ballpoint 3-pack |   6.90 |   150 | t
  5 | PC-001 | Graphite Pencil HB     |   1.20 |   800 | t
  6 | PC-002 | Sketching Pencil Set   |  18.50 |    40 | t
  7 | NB-001 | Dot Grid Notebook A5   |  14.00 |    75 | t
  8 | NB-002 | Lined Notebook A4      |  11.00 |    60 | t
  9 | NB-003 | Pocket Notebook 3-pack |   9.50 |     0 | t
 10 | LP-001 | Copy Paper 500 sheets  |   7.99 |   200 | t
 11 | IN-001 | Midnight Blue Ink 50ml |  16.00 |    30 | t
 12 | IN-002 | Sepia Ink 50ml         |  16.00 |    25 | t
 13 | AC-001 | Brass Pen Clip         |   5.00 |    90 | t
 14 | AC-002 | Leather Pen Case       |  35.00 |    10 | f
(14 rows)
```

```sql
SELECT id, customer_id, status, created_at, shipped_at FROM orders ORDER BY id;
```

```text
 id | customer_id |  status   |       created_at       |       shipped_at
----+-------------+-----------+------------------------+------------------------
  1 |           1 | delivered | 2026-01-06 10:00:00+00 | 2026-01-07 09:00:00+00
  2 |           2 | delivered | 2026-01-15 12:00:00+00 | 2026-01-16 10:30:00+00
  3 |           3 | delivered | 2026-02-03 19:30:00+00 | 2026-02-05 08:15:00+00
  4 |           1 | delivered | 2026-02-10 08:45:00+00 | 2026-02-11 14:00:00+00
  5 |           4 | cancelled | 2026-02-20 13:15:00+00 |
  6 |           5 | delivered | 2026-03-02 15:00:00+00 | 2026-03-03 11:20:00+00
  7 |           2 | shipped   | 2026-03-21 09:30:00+00 | 2026-03-22 16:45:00+00
  8 |           6 | delivered | 2026-03-28 17:40:00+00 | 2026-03-30 09:10:00+00
  9 |           3 | shipped   | 2026-04-04 11:11:00+00 | 2026-04-06 13:00:00+00
 10 |           8 | paid      | 2026-04-08 06:30:00+00 |
 11 |           1 | paid      | 2026-04-09 20:05:00+00 |
 12 |           5 | pending   | 2026-04-10 12:00:00+00 |
(12 rows)
```

A few things in the data are deliberate, and later chapters use them: Gus (customer 7) has never ordered; the Brass
Pen Clip (product 13) has never been sold; the Leather Pen Case (14) is inactive; the Pocket Notebook (9) is out of
stock; order 5 was cancelled; order 8 bought a Kestrel pen at 45.00, below its current price; one review has no text.

## UPDATE

`UPDATE` sets new values for the rows matching its `WHERE` clause. The new value can be any expression, and it can
refer to the row's current values:

```sql
BEGIN;
UPDATE products SET price = price * 1.10 WHERE category_id = 6;
SELECT sku, name, price FROM products WHERE category_id = 6 ORDER BY sku;
ROLLBACK;
```

```text
BEGIN
UPDATE 2
  sku   |          name          | price
--------+------------------------+-------
 IN-001 | Midnight Blue Ink 50ml | 17.60
 IN-002 | Sepia Ink 50ml         | 17.60
(2 rows)

ROLLBACK
```

The `BEGIN` ... `ROLLBACK` around it is a habit worth adopting: try a change, look at the result, and undo it.
After the `ROLLBACK` the prices are back to 16.00. (Transactions get their own section below.)

Notice that `price * 1.10` was rounded to the column's two decimal places when stored. Be explicit about rounding in
money calculations rather than relying on that; `round(price * 1.10, 2)` says what you mean.

### RETURNING old and new values (PostgreSQL 18+)

`RETURNING` on an `UPDATE` returns the new values by default. Since PostgreSQL 18 you can ask for both versions of the
row with the `old` and `new` prefixes:

```sql
UPDATE products
SET price = round(price * 1.05, 2)
WHERE sku IN ('IN-001', 'IN-002')
RETURNING sku, old.price AS old_price, new.price AS new_price;
```

```text
  sku   | old_price | new_price
--------+-----------+-----------
 IN-001 |     16.00 |     16.80
 IN-002 |     16.00 |     16.80
(2 rows)

UPDATE 2
```

That is perfect for audit messages ("price changed from 16.00 to 16.80") and for checking exactly what a bulk update
did. Before version 18, getting the old values needed a self-join or a trigger.

### UPDATE ... FROM

Sometimes the new values come from another table. `UPDATE ... FROM` joins the target with other tables. Say order 10
(one Heron fountain pen, still `paid`) is being shipped, so its products leave the warehouse:

```sql
UPDATE products AS p
SET stock = p.stock - oi.quantity
FROM order_items AS oi
WHERE oi.product_id = p.id
  AND oi.order_id = 10
RETURNING p.sku, old.stock AS before, new.stock AS after;
```

```text
  sku   | before | after
--------+--------+-------
 FP-002 |      3 |     2
(1 row)

UPDATE 1
```

- `AS p` and `AS oi` are **aliases**, short names for the tables in this statement.
- The `WHERE` clause connects the two tables (`oi.product_id = p.id`, a *join condition*, chapter 7) and filters
  the order.
- If a target row matches *several* rows from `FROM`, it is updated only once, with values from an unpredictable one of
  them. Make sure the join produces at most one row per target row. Here the primary key of `order_items` guarantees
  it: an order has each product at most once.

Now mark the order as shipped. The first attempt forgets `shipped_at`, and the constraint from chapter 4 catches it:

```sql
UPDATE orders SET status = 'shipped' WHERE id = 10;
```

```text
ERROR:  new row for relation "orders" violates check constraint "orders_shipped_at_matches_status"
DETAIL:  Failing row contains (10, 8, shipped, 2026-04-08 06:30:00+00, null).
```

```sql
UPDATE orders
SET status = 'shipped', shipped_at = '2026-04-09 10:00:00+00'
WHERE id = 10;
```

```text
UPDATE 1
```

An `UPDATE` sets all its columns at once, from the row as it was before the statement: in `SET a = b, b = a`, the
two columns swap.

## DELETE

```sql
DELETE FROM reviews WHERE body IS NULL RETURNING id, product_id, rating;
```

```text
 id | product_id | rating
----+------------+--------
  4 |          3 |      3
(1 row)

DELETE 1
```

`DELETE ... USING` is the counterpart of `UPDATE ... FROM`: it deletes rows of the target that match rows in other
tables. Remove the lines of cancelled orders:

```sql
DELETE FROM order_items AS oi
USING orders AS o
WHERE o.id = oi.order_id
  AND o.status = 'cancelled'
RETURNING oi.order_id, oi.product_id;
```

```text
 order_id | product_id
----------+------------
        5 |          6
(1 row)

DELETE 1
```

Deleting rows is rarely as simple in real systems as in a tutorial. Before deleting, ask whether you need the history
(orders, payments and audit data usually must be kept for years), whether something else references the rows, and
whether marking them (`active = false`, `deleted_at = now()`) would be better. Chapter 14 discusses "soft deletes" and
their costs.

## Transactions

A **transaction** groups statements so that they take effect together or not at all. Without an explicit transaction,
each statement is its own transaction ("autocommit"). With one:

```sql
BEGIN;
-- statements...
COMMIT;      -- make all changes permanent and visible to others
-- or
ROLLBACK;    -- undo all changes since BEGIN
```

Until `COMMIT`, other sessions don't see your changes (chapter 17 explains how), and if your session crashes or loses
its connection, the transaction is rolled back automatically.

### An error aborts the transaction

When a statement inside a transaction fails, PostgreSQL marks the **whole transaction** as failed. Every following
statement is refused until you end it, and ending it can only roll back:

```sql
BEGIN;
UPDATE products SET stock = stock - 5 WHERE sku = 'FP-002';
SELECT stock FROM products WHERE sku = 'FP-002';
ROLLBACK;
```

```text
BEGIN
ERROR:  new row for relation "products" violates check constraint "products_stock_check"
DETAIL:  Failing row contains (2, FP-002, Heron Fountain Pen, A weighty brass fountain pen with a medium gold-plated nib, bala..., 3, 129.00, -3, {"nib": "M", "color": "green", "material": "brass"}, {gift}, t, 2025-12-01 09:00:00+00).
ERROR:  current transaction is aborted, commands ignored until end of transaction block
ROLLBACK
```

(Only 2 Heron pens are left, so taking 5 would make the stock negative.) This is a feature: a transaction that has had
an error is in an unknown state, and carrying on could commit half a change. If you type `COMMIT` on an aborted
transaction, PostgreSQL performs a `ROLLBACK` and says so. In interactive `psql`, the `ON_ERROR_ROLLBACK` setting from
chapter 2 makes typos less painful by wrapping each of your statements in a savepoint.

### Savepoints

A **savepoint** marks a point inside a transaction that you can roll back to, without abandoning the whole
transaction:

```sql
BEGIN;
UPDATE products SET stock = stock + 10 WHERE sku = 'FP-002';
SAVEPOINT before_risky_part;
UPDATE products SET stock = stock - 100 WHERE sku = 'FP-002';
ROLLBACK TO SAVEPOINT before_risky_part;
SELECT stock FROM products WHERE sku = 'FP-002';
COMMIT;
```

```text
BEGIN
UPDATE 1
SAVEPOINT
ERROR:  new row for relation "products" violates check constraint "products_stock_check"
DETAIL:  Failing row contains (2, FP-002, Heron Fountain Pen, A weighty brass fountain pen with a medium gold-plated nib, bala..., 3, 129.00, -88, {"nib": "M", "color": "green", "material": "brass"}, {gift}, t, 2025-12-01 09:00:00+00).
ROLLBACK
 stock
-------
    12
(1 row)

COMMIT
```

The first update (+10) survived; the failed one was undone. Savepoints are how frameworks implement "nested
transactions". Don't overuse them: each one has a cost, and thousands in a single transaction slow it down noticeably.

## Upserts: INSERT ... ON CONFLICT

A common need: "insert this row, or if it already exists, update it". Doing it as "check, then insert or update" in
two statements has a race: two sessions can both see "doesn't exist" and both insert, and one fails. `ON CONFLICT`
does it atomically.

A supplier delivery arrives as a list of SKUs and quantities. Existing products get their stock increased, new ones
are created:

```sql
INSERT INTO products (sku, name, category_id, price, stock)
VALUES ('IN-002', 'Sepia Ink 50ml',   6, 16.80, 20),
       ('IN-003', 'Emerald Ink 50ml', 6, 17.50, 30)
ON CONFLICT (sku) DO UPDATE
SET stock = products.stock + EXCLUDED.stock
RETURNING id, sku, old.stock AS old_stock, new.stock AS new_stock;
```

```text
 id |  sku   | old_stock | new_stock
----+--------+-----------+-----------
 12 | IN-002 |        25 |        45
 16 | IN-003 |           |        30
(2 rows)

INSERT 0 2
```

- `ON CONFLICT (sku)` names the **conflict target**: a unique constraint or index on `sku`. A conflict on anything else
  (say, the primary key) still raises an error.
- In `DO UPDATE`, `products` refers to the existing row and `EXCLUDED` to the row that was proposed for insertion.
- With `RETURNING old.*` (PostgreSQL 18+), `old` is `NULL` for rows that were inserted, which tells you which path each
  row took.
- The new product got id **16**, not 15. The first row consumed id 15 from the sequence before PostgreSQL discovered the
  conflict. Upserts burn sequence values; that's normal.

`DO NOTHING` skips conflicting rows silently, and is perfect for idempotent imports. Here customer 1 has already
reviewed product 1:

```sql
INSERT INTO reviews (product_id, customer_id, rating, body)
VALUES (1, 1, 4, 'Still good after three months, but the cap got loose.')
ON CONFLICT (product_id, customer_id) DO NOTHING;
```

```text
INSERT 0 0
```

A `WHERE` clause on `DO UPDATE` updates only when it's worthwhile. Letting a customer edit their review, but only
touching the row if the rating actually changed:

```sql
INSERT INTO reviews (product_id, customer_id, rating, body)
VALUES (1, 1, 4, 'Still good after three months, but the cap got loose.')
ON CONFLICT (product_id, customer_id) DO UPDATE
SET rating = EXCLUDED.rating, body = EXCLUDED.body, created_at = now()
WHERE reviews.rating IS DISTINCT FROM EXCLUDED.rating
RETURNING id, rating, body;
```

```text
 id | rating |                         body
----+--------+-------------------------------------------------------
  1 |      4 | Still good after three months, but the cap got loose.
(1 row)

INSERT 0 1
```

`IS DISTINCT FROM` is like `<>` but treats `NULL`s as comparable values (chapter 6). The old rating was 5, so the row
was updated.

`DO UPDATE` needs a conflict target, because PostgreSQL must know which row to update:

```sql
INSERT INTO products (sku, name, category_id, price) VALUES ('FP-001', 'x', 3, 1)
ON CONFLICT DO UPDATE SET price = 1;
```

```text
ERROR:  ON CONFLICT DO UPDATE requires inference specification or constraint name
LINE 2: ON CONFLICT DO UPDATE SET price = 1;
        ^
HINT:  For example, ON CONFLICT (column_name).
```

## MERGE

`MERGE` (PostgreSQL 15+) is the SQL-standard statement for synchronizing a table with a source: for each source row,
decide whether it matches a target row, and then insert, update, delete or do nothing, each with optional conditions.
Where `ON CONFLICT` handles "insert or update one row", `MERGE` handles rule sets like "apply this price list".

A new price list from the purchasing department arrives in a temporary table. A price of 0 means "discontinue":

```sql
CREATE TEMP TABLE price_list (sku text PRIMARY KEY, price numeric(10, 2) NOT NULL);
INSERT INTO price_list (sku, price)
VALUES ('FP-001', 49.00), ('BP-001', 2.50), ('NB-002', 0), ('NB-004', 12.00);
```

```text
CREATE TABLE
INSERT 0 4
```

```sql
MERGE INTO products AS p
USING price_list AS l ON l.sku = p.sku
WHEN MATCHED AND l.price = 0 THEN
    UPDATE SET active = false
WHEN MATCHED AND l.price <> p.price THEN
    UPDATE SET price = l.price
WHEN NOT MATCHED THEN
    INSERT (sku, name, category_id, price)
    VALUES (l.sku, 'New product ' || l.sku, 8, l.price)
RETURNING merge_action() AS action, p.sku, p.price, p.active;
```

```text
 action |  sku   | price | active
--------+--------+-------+--------
 UPDATE | FP-001 | 49.00 | t
 UPDATE | NB-002 | 11.00 | f
 INSERT | NB-004 | 12.00 | t
(3 rows)

MERGE 3
```

- `USING ... ON` defines the source and how a source row matches a target row.
- The `WHEN` clauses are tried **in order**, and the first one whose condition holds wins. `BP-001` matched, but its
  price hadn't changed, so no clause applied and nothing happened.
- `RETURNING` with `merge_action()` (PostgreSQL 17+) tells you what happened to each row.

> **Just a taste — you don't need to learn or fully understand this now.** PostgreSQL 17 added `WHEN NOT MATCHED BY
> SOURCE`, for target rows that have no matching source row. With it, one statement can deactivate every product that
> is missing from the price list, a full synchronization.

```sql
BEGIN;
MERGE INTO products AS p
USING price_list AS l ON l.sku = p.sku
WHEN NOT MATCHED BY SOURCE AND p.active THEN UPDATE SET active = false;
ROLLBACK;
```

`MERGE` versus `ON CONFLICT`: `ON CONFLICT` is designed for concurrency, guaranteeing an insert-or-update even when
other sessions insert the same key at the same moment. `MERGE` evaluates its join once, and concurrent inserts can make
it fail with a unique violation. Use `ON CONFLICT` for application upserts, and `MERGE` for batch synchronization jobs.

## Bulk loading with COPY

`COPY` is the fastest way to get many rows in or out of a table: typically ten or more times faster than individual
`INSERT`s. Chapter 2 showed `\copy` with files. `COPY ... FROM STDIN` reads the data that follows the command, which
is how `pg_dump` files and most drivers' bulk-load features work. In `psql`, the data ends with a line containing only
`\.`.

A warehouse stock count arrives as CSV. Load it into a staging table first, then apply it, rather than writing straight
into `products`:

```sql
CREATE TEMP TABLE stock_import (sku text NOT NULL, stock integer NOT NULL);

COPY stock_import (sku, stock) FROM STDIN WITH (FORMAT csv, HEADER);
sku,stock
FP-001,20
BP-001,lots
NB-001,90
\.
```

```text
CREATE TABLE
ERROR:  invalid input syntax for type integer: "lots"
CONTEXT:  COPY stock_import, line 3, column stock: "lots"
```

`COPY` is all or nothing: one bad value and no rows are loaded. Since PostgreSQL 17, `ON_ERROR ignore` skips rows with
values that can't be converted to the column type, and `LOG_VERBOSITY verbose` reports each one:

```sql
COPY stock_import (sku, stock) FROM STDIN
WITH (FORMAT csv, HEADER, ON_ERROR ignore, LOG_VERBOSITY verbose);
sku,stock
FP-001,20
BP-001,lots
NB-001,90
\.
```

```text
NOTICE:  skipping row due to data type incompatibility at line 3 for column "stock": "lots"
NOTICE:  1 row was skipped due to data type incompatibility
COPY 2
```

`ON_ERROR ignore` only covers **conversion errors**. A row that breaks a constraint (`NOT NULL`, `CHECK`, a unique key)
still fails the whole `COPY`, which is one more reason to load into a permissive staging table and validate with SQL
afterwards. PostgreSQL 18 adds `REJECT_LIMIT n` (with `ON_ERROR ignore`): fail after all if more than `n` rows are bad,
because a file where every row is broken is a wrong file, not a few typos.

Now apply the staging table with an `UPDATE ... FROM`:

```sql
UPDATE products AS p
SET stock = s.stock
FROM stock_import AS s
WHERE s.sku = p.sku
RETURNING p.sku, old.stock AS before, new.stock AS after;
```

```text
  sku   | before | after
--------+--------+-------
 FP-001 |     12 |    20
 NB-001 |     75 |    90
(2 rows)

UPDATE 2
```

Temporary tables disappear when you disconnect, so there's nothing to clean up.

### Loading lots of data fast

When you need to load millions of rows:

- Use `COPY` (or your driver's `COPY` support) instead of `INSERT`. Next best are multi-row `INSERT`s of a few hundred
  to a few thousand rows each.
- Load in **one transaction**, or a few large ones. Committing every row forces a disk flush per row.
- On a brand-new table, create indexes and foreign keys **after** loading: building an index once is much faster
  than updating it a million times.
- Run `ANALYZE` afterwards so the planner knows what the table now contains.

## Back to the reference state

This chapter changed the data in many places. Restore the original sample data before moving on (quit `psql` first,
or run it from another terminal):

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
```

## Key takeaways

- Always list the columns in `INSERT`. Use multi-row `VALUES` or `INSERT ... SELECT` for many rows, and `RETURNING` to
  get generated ids and values back. PostgreSQL 18 adds `old` and `new` in `RETURNING`.
- `UPDATE ... FROM` and `DELETE ... USING` change rows based on other tables. Make sure each target row matches at most
  one source row.
- Wrap experiments in `BEGIN` ... `ROLLBACK`. A failed statement aborts the whole transaction; savepoints let you
  recover part of it.
- `INSERT ... ON CONFLICT` is the atomic, concurrency-safe upsert. `DO NOTHING` makes imports idempotent; `DO UPDATE`
  uses `EXCLUDED` for the proposed row.
- `MERGE` applies a set of rules to synchronize a table with a source; `merge_action()` (17+) reports what it did.
- `COPY` is the bulk loader. `ON_ERROR ignore` (17+) skips unconvertible rows, `REJECT_LIMIT` (18+) caps how many.
  Load into a staging table, validate, then apply.
- `TRUNCATE` empties tables instantly but takes an exclusive lock; `RESTART IDENTITY` resets their sequences.
- `sql/reset.sql` restores the Inkwell sample data whenever you need it.

## Exercises

Start each exercise from a freshly reset database.

1. **A new order, by hand.** Gus (customer 7) orders two Dot Grid notebooks (product 7) and one Brass Pen Clip (product
   13) at their current prices. In one transaction, insert the order, capture its id with `RETURNING ... \gset` (chapter
   2), insert the two order lines using that id, and commit. Then show the order's lines.

<details>
<summary>Solution</summary>

```sql
BEGIN;
INSERT INTO orders (customer_id) VALUES (7) RETURNING id AS order_id \gset
INSERT INTO order_items (order_id, product_id, quantity, unit_price)
SELECT :order_id, id, CASE id WHEN 7 THEN 2 ELSE 1 END, price
FROM products
WHERE id IN (7, 13);
COMMIT;

SELECT * FROM order_items WHERE order_id = :order_id ORDER BY product_id;
```

```text
 order_id | product_id | quantity | unit_price
----------+------------+----------+------------
       13 |          7 |        2 |      14.00
       13 |         13 |        1 |       5.00
(2 rows)
```

Taking `unit_price` from the `products` table inside the same `INSERT ... SELECT` guarantees the order line records
the price as it is at that moment. `CASE` picks a value per row (chapter 6). In an application you would send the
quantities as parameters; chapter 9 shows how to do the whole thing in a single statement with a data-modifying CTE.

</details>

2. **A price rise with a receipt.** Raise the price of all fountain pens (category 3) by 4%, rounded to whole cents,
   and return each product's SKU with the old price, the new price and the difference.

<details>
<summary>Solution</summary>

```sql
UPDATE products
SET price = round(price * 1.04, 2)
WHERE category_id = 3
RETURNING sku, old.price AS old_price, new.price AS new_price, new.price - old.price AS increase;
```

```text
  sku   | old_price | new_price | increase
--------+-----------+-----------+----------
 FP-001 |     48.00 |     49.92 |     1.92
 FP-002 |    129.00 |    134.16 |     5.16
(2 rows)

UPDATE 2
```

(Rows may come back in a different order; add nothing, since `RETURNING` has no `ORDER BY`. If you need them sorted,
chapter 9 shows how to wrap the statement in a CTE and sort the result.)

</details>

3. **Idempotent import.** Write an `INSERT` that adds the categories `Erasers` (slug `erasers`) and `Ink` (slug `ink`,
   which already exists), both under `Writing` (id 1), in a way that can be run any number of times without errors and
   without creating duplicates. Run it twice. What does `psql` report each time?

<details>
<summary>Solution</summary>

```sql
INSERT INTO categories (parent_id, name, slug)
VALUES (1, 'Erasers', 'erasers'),
       (1, 'Ink', 'ink')
ON CONFLICT (slug) DO NOTHING;
```

The first run reports `INSERT 0 1` (only Erasers is new), the second `INSERT 0 0`. Each run still consumes identity
values for the rows it tried to insert, so the next category's id will have a gap; that's harmless.

</details>

4. **Synchronize stock with MERGE.** A stock file says: `FP-001` has 15, `NB-003` has 40, and `XX-999` (unknown) has 5.
   Write a `MERGE` that updates the stock of known products, ignores unknown SKUs, and reports what it did. Use a
   `VALUES` list as the source instead of a table.

<details>
<summary>Solution</summary>

```sql
MERGE INTO products AS p
USING (VALUES ('FP-001', 15), ('NB-003', 40), ('XX-999', 5)) AS s (sku, stock)
    ON s.sku = p.sku
WHEN MATCHED AND p.stock <> s.stock THEN
    UPDATE SET stock = s.stock
RETURNING merge_action() AS action, p.sku, p.stock;
```

```text
 action |  sku   | stock
--------+--------+-------
 UPDATE | FP-001 |    15
 UPDATE | NB-003 |    40
(2 rows)

MERGE 2
```

A `VALUES` list with an alias and column names (`AS s (sku, stock)`) works anywhere a table does. With no
`WHEN NOT MATCHED` clause, unknown SKUs are simply ignored. In a real import you would want to *know* about unknown
SKUs; a query for source rows without a match (an anti-join, chapter 7) can report them before or after the `MERGE`.

</details>

5. **The aborted transaction.** Predict the final stock of product 1 after this session, then run it to check. Why?

   ```sql
   BEGIN;
   UPDATE products SET stock = stock + 5 WHERE id = 1;
   UPDATE products SET stock = 'many' WHERE id = 1;
   UPDATE products SET stock = stock + 5 WHERE id = 1;
   COMMIT;
   SELECT stock FROM products WHERE id = 1;
   ```

<details>
<summary>Solution</summary>

The stock stays at 12, its original value. The second `UPDATE` fails (`'many'` isn't an integer), which aborts the
transaction. The third `UPDATE` is refused with "current transaction is aborted", and `COMMIT` on an aborted transaction
performs a rollback, reporting `ROLLBACK` instead of `COMMIT`. So even the first, successful update is undone. That is
exactly what you want in an application: either the whole unit of work happens or none of it.

</details>

6. **Archive old reviews.** Create a table `reviews_archive (LIKE reviews INCLUDING ALL)` and move all reviews created
   before February 2026 into it, in one transaction, so that no review is ever in both tables or in neither. How many
   rows moved?

<details>
<summary>Solution</summary>

```sql
CREATE TABLE reviews_archive (LIKE reviews INCLUDING ALL);

BEGIN;
INSERT INTO reviews_archive OVERRIDING SYSTEM VALUE
SELECT * FROM reviews WHERE created_at < '2026-02-01';
DELETE FROM reviews WHERE created_at < '2026-02-01';
COMMIT;
```

```text
CREATE TABLE
BEGIN
INSERT 0 4
DELETE 4
COMMIT
```

Four reviews move (the ones from January). `INCLUDING ALL` copies defaults, constraints, indexes and the identity
definition, so `reviews_archive.id` is a `GENERATED ALWAYS` identity column too, and it refuses the explicit ids that
`SELECT *` supplies unless you write `OVERRIDING SYSTEM VALUE`. Keeping the original ids is what you want in an
archive. (`SELECT *` is safe here only because both tables have the same columns in the same order.)

Inside the transaction, other sessions see either the state before (all in `reviews`) or after (moved), never the
in-between. Chapter 9 shows a single-statement version with `WITH moved AS (DELETE ... RETURNING *) INSERT ...`.

</details>

---
← Previous: [Tables and constraints](04-tables-and-constraints.md) | Next: [Querying with SELECT](06-querying-with-select.md) →
