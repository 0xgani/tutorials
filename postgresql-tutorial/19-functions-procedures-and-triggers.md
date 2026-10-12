# Chapter 19 — Functions, procedures and triggers

## What you'll learn

- When it makes sense to put logic in the database, and when it doesn't
- SQL functions: scalar and set-returning, SQL-standard bodies, and how to call them
- Volatility (`IMMUTABLE`, `STABLE`, `VOLATILE`) and other function attributes, and why they matter to the planner
- PL/pgSQL: variables, control flow, queries, errors and exception handling, dynamic SQL
- Procedures, and committing inside them
- Triggers: `BEFORE` and `AFTER`, row and statement level, `NEW` and `OLD`, transition tables
- Common trigger uses: timestamps, validation, audit logs and summary tables, and their pitfalls
- `LISTEN` and `NOTIFY`
- `SECURITY DEFINER`, `search_path` safety, and how to manage server-side code

So far, all logic has lived in the SQL statements you sent. PostgreSQL can also store code: functions you call from
queries, procedures you `CALL`, and triggers that run automatically when data changes. Used well, they keep rules
next to the data they protect, so that every application and every psql session obeys them, and they save round trips
for multi-step operations. Used badly, they hide behaviour where nobody looks for it. This chapter covers both the
mechanics and the judgement.

## Setting up

Back to the small Inkwell database:

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
```

## Where should logic live?

There is no single right answer, but there are good defaults:

| Put it in the database | Keep it in the application |
|---|---|
| rules that must hold for every writer: constraints first, triggers where constraints can't express them | business workflows that call other services (payment, email, shipping) |
| multi-statement operations that must be atomic and are called from several places | logic that changes often and needs the application's tests, logging and deployment |
| set-based computations close to the data (reports, search) | anything that needs libraries or network access |
| audit trails that must not be bypassable | presentation and formatting |

Two rules of thumb: prefer a **constraint** over a trigger when one can express the rule (chapter 4), and prefer one
clear, documented function over logic scattered across triggers that fire each other.

## SQL functions

The simplest functions are written in SQL itself. A function that computes an order's total:

```sql
CREATE FUNCTION order_total(p_order_id bigint)
RETURNS numeric
LANGUAGE sql
STABLE
RETURN (SELECT coalesce(sum(quantity * unit_price), 0)
        FROM order_items
        WHERE order_id = p_order_id);

SELECT id, order_total(id) FROM orders WHERE customer_id = 1 ORDER BY id;
```

```text
CREATE FUNCTION
 id | order_total
----+-------------
  1 |       80.00
  4 |       42.40
 11 |       25.70
(3 rows)
```

The pieces:

- `p_order_id bigint` is a named parameter. The `p_` prefix is a common convention that keeps parameter names from
  clashing with column names.
- `RETURNS numeric` is the result type.
- `LANGUAGE sql` says the body is plain SQL. `STABLE` is the function's *volatility*, explained shortly.
- `RETURN expression` (PostgreSQL 14+) is the SQL-standard form for a function whose body is one expression. The body
  is parsed when the function is created, and PostgreSQL records which tables and columns it uses, so you can't drop
  `order_items` while the function depends on it.

For several statements, the SQL-standard form is `BEGIN ATOMIC ... END`. The older and still very common form puts the
body in a string, usually quoted with **dollar quotes** (`$$ ... $$`), which need no escaping of the single quotes
inside:

```sql
CREATE FUNCTION customer_display_name(p_customer_id bigint)
RETURNS text
LANGUAGE sql
STABLE
AS $$
    SELECT full_name || ' (' || country || ')'
    FROM customers
    WHERE id = p_customer_id;
$$;

SELECT customer_display_name(3);
```

```text
CREATE FUNCTION
 customer_display_name
-----------------------
 Chloé Martin (FR)
(1 row)
```

A string body is only parsed when the function runs, so mistakes surface late, and dependencies aren't recorded. Its
advantage is that it works for every language, including PL/pgSQL below. A tag can go between the dollars when the
body itself contains `$$`: `$body$ ... $body$`.

In psql, `\df` lists functions and `\sf` shows a function's source:

```sql
\sf order_total
```

```text
CREATE OR REPLACE FUNCTION public.order_total(p_order_id bigint)
 RETURNS numeric
 LANGUAGE sql
 STABLE
RETURN (SELECT COALESCE(sum(((order_items.quantity)::numeric * order_items.unit_price)), (0)::numeric) AS "coalesce" FROM order_items WHERE (order_items.order_id = order_total.p_order_id))
```

### Functions that return rows

A function can return a whole result set, and then it is used in `FROM` like a table. `RETURNS TABLE (...)` declares
the columns. Chapter 13 built a weighted search column and a ranked search query; here it becomes a function that an
application can call with one line:

```sql
ALTER TABLE products
    ADD COLUMN search tsvector
    GENERATED ALWAYS AS (setweight(to_tsvector('english', name), 'A')
                         || setweight(to_tsvector('english', description), 'B')) STORED;
CREATE INDEX products_search_idx ON products USING gin (search);

CREATE FUNCTION search_products(p_query text, p_limit integer DEFAULT 10)
RETURNS TABLE (id bigint, name text, rank real, snippet text)
LANGUAGE sql
STABLE
BEGIN ATOMIC
    SELECT top.id, top.name, top.rank,
           ts_headline('english', top.description, top.q, 'StartSel=<b>, StopSel=</b>')
    FROM (
        SELECT p.id, p.name, p.description, q, ts_rank(p.search, q) AS rank
        FROM products AS p, websearch_to_tsquery('english', p_query) AS q
        WHERE p.search @@ q AND p.active
        ORDER BY rank DESC, p.name
        LIMIT p_limit
    ) AS top
    ORDER BY top.rank DESC, top.name;
END;

SELECT * FROM search_products('blue ink');
SELECT name, round(rank::numeric, 3) AS rank FROM search_products('notebook', p_limit => 2);
```

```text
ALTER TABLE
CREATE INDEX
CREATE FUNCTION
 id |          name          |    rank    |                              snippet
----+------------------------+------------+--------------------------------------------------------------------
 11 | Midnight Blue Ink 50ml |  0.9989093 | Deep <b>blue</b> fountain pen <b>ink</b> with subtle shading.
  3 | Everyday Ballpoint     | 0.39641288 | A dependable disposable ballpoint pen with <b>blue</b> <b>ink</b>.
(2 rows)

         name         | rank
----------------------+-------
 Dot Grid Notebook A5 | 0.669
 Lined Notebook A4    | 0.669
(2 rows)
```

`p_limit integer DEFAULT 10` makes the second argument optional, and `p_limit => 2` passes it by name. Other
set-returning forms are `RETURNS SETOF products` (rows shaped like a table) and `RETURNS SETOF text` (one column).

Set-returning functions combine well with `LATERAL` (chapter 7): run the function once per row of another table.
The two best matches for each of two queries:

```sql
SELECT q.term, r.name
FROM (VALUES ('ink'), ('pencil')) AS q(term)
CROSS JOIN LATERAL search_products(q.term, 2) AS r
ORDER BY q.term, r.rank DESC;
```

```text
  term  |          name
--------+------------------------
 ink    | Midnight Blue Ink 50ml
 ink    | Sepia Ink 50ml
 pencil | Graphite Pencil HB
 pencil | Sketching Pencil Set
(4 rows)
```

## Volatility and other attributes

Every function has a **volatility** category, which is a promise to the planner about what the function does:

| Category | Promise | Examples | The planner may |
|---|---|---|---|
| `IMMUTABLE` | same arguments always give the same result; reads no tables | `lower()`, `abs()`, arithmetic | evaluate it once at planning time; use it in index expressions |
| `STABLE` | same result for the same arguments within one statement; may read tables, but doesn't change anything | `now()`, `to_char()` with a time zone, functions that query tables | evaluate it once per statement, e.g. to drive an index scan |
| `VOLATILE` (the default) | anything goes: may change data, or return something different each time | `random()`, `nextval()`, `clock_timestamp()`, functions that write | must call it for every row where it's used |

The difference shows up in plans. Three functions return the same number, declared with three volatilities. (They are
written in PL/pgSQL, introduced below, because the planner would otherwise inline these one-line SQL functions and
see the constant directly.)

```sql
CREATE FUNCTION answer_immutable() RETURNS bigint LANGUAGE plpgsql IMMUTABLE AS $$ BEGIN RETURN 2; END $$;
CREATE FUNCTION answer_stable() RETURNS bigint LANGUAGE plpgsql STABLE AS $$ BEGIN RETURN 2; END $$;
CREATE FUNCTION answer_volatile() RETURNS bigint LANGUAGE plpgsql VOLATILE AS $$ BEGIN RETURN 2; END $$;

SET enable_seqscan = off;   -- the tables are tiny; ask for an index whenever one is possible
EXPLAIN (COSTS OFF) SELECT id FROM orders WHERE customer_id = answer_immutable();
EXPLAIN (COSTS OFF) SELECT id FROM orders WHERE customer_id = answer_stable();
EXPLAIN (COSTS OFF) SELECT id FROM orders WHERE customer_id = answer_volatile();
RESET enable_seqscan;
```

```text
CREATE FUNCTION
CREATE FUNCTION
CREATE FUNCTION
SET
                    QUERY PLAN
---------------------------------------------------
 Index Scan using orders_customer_id_idx on orders
   Index Cond: (customer_id = '2'::bigint)
(2 rows)

                    QUERY PLAN
---------------------------------------------------
 Index Scan using orders_customer_id_idx on orders
   Index Cond: (customer_id = answer_stable())
(2 rows)

                 QUERY PLAN
---------------------------------------------
 Seq Scan on orders
   Disabled: true
   Filter: (customer_id = answer_volatile())
(3 rows)

RESET
```

- The `IMMUTABLE` call was replaced by its result, `2`, while planning.
- The `STABLE` call is in the index condition: it is evaluated once when the scan starts.
- The `VOLATILE` call can't drive an index, because it might return a different value for every row, so the only
  option is a sequential scan that calls it for each row (`Disabled: true` marks that the plan breaks the
  `enable_seqscan = off` request, because nothing else was possible).

Declare the strictest category that is **true**. A function that reads a table but is marked `IMMUTABLE` will be
folded into cached plans and index entries, and silently return stale results when the table changes. A function
that writes data must be `VOLATILE`. Simple SQL functions like `order_total` are often *inlined*: the planner replaces
the call with the function's body and optimises the query as a whole, which works best when the volatility is right.

Other attributes you'll meet:

| Attribute | Meaning |
|---|---|
| `STRICT` (or `RETURNS NULL ON NULL INPUT`) | if any argument is `NULL`, the result is `NULL` without calling the function |
| `PARALLEL SAFE` | the function may run in parallel workers (chapter 16); the default is `PARALLEL UNSAFE`, which prevents parallel plans for queries that call it |
| `LEAKPROOF` | the function reveals nothing about its arguments except its result; matters for row-level security (chapter 21) |
| `COST n`, `ROWS n` | hints to the planner about how expensive the function is and how many rows a set-returning function returns |
| `SECURITY DEFINER` | runs with the privileges of the function's owner (see "Security" below) |
| `SET param = value` | sets a configuration parameter for the duration of the call |

## PL/pgSQL

SQL functions are limited to SQL statements. **PL/pgSQL** is PostgreSQL's procedural language: variables, `IF`,
loops, error handling, and SQL statements embedded naturally. It is installed in every database. A PL/pgSQL function
body is a **block**:

```text
[ <<label>> ]
DECLARE
    declarations;
BEGIN
    statements;
EXCEPTION
    WHEN condition THEN handler statements;
END;
```

`DECLARE` and `EXCEPTION` are optional. Note that this `BEGIN` only groups statements: it doesn't start a transaction.
A function always runs inside the transaction of the statement that called it.

### Placing an order

The checkout of a web shop is a good candidate for a function. It inserts the order, checks and reduces the stock of
each product, and inserts the items, and all of it must succeed or fail together. As one function call, it is one
round trip from the application and one atomic statement:

```sql
CREATE FUNCTION place_order(p_customer_id bigint, p_items jsonb)
RETURNS bigint
LANGUAGE plpgsql
AS $$
DECLARE
    v_order_id  bigint;
    v_item      record;
    v_price     numeric(10, 2);
BEGIN
    IF jsonb_typeof(p_items) IS DISTINCT FROM 'array' OR jsonb_array_length(p_items) = 0 THEN
        RAISE EXCEPTION 'an order needs at least one item'
            USING ERRCODE = 'invalid_parameter_value';
    END IF;

    INSERT INTO orders (customer_id)
    VALUES (p_customer_id)
    RETURNING id INTO v_order_id;

    -- Lock and update the products in id order, so that concurrent orders can't deadlock (chapter 17).
    FOR v_item IN
        SELECT (x ->> 'product_id')::bigint AS product_id,
               (x ->> 'quantity')::integer AS quantity
        FROM jsonb_array_elements(p_items) AS x
        ORDER BY 1
    LOOP
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

```text
CREATE FUNCTION
```

Reading it from the top:

- **Variables** are declared in `DECLARE` with a type. `record` holds a row of any shape; you could also write
  `products.price%TYPE` to borrow a column's type, or `products%ROWTYPE` for a whole row.
- **`IF ... THEN ... END IF`** works as you'd expect (with `ELSIF` and `ELSE`). There's also `CASE`.
- **SQL statements are just written in the body.** PL/pgSQL variables and parameters can be used wherever a value is
  allowed. Each statement is prepared once and its plan cached for the session.
- **`INTO`** stores a query's (first) row in variables: `RETURNING id INTO v_order_id`, or `SELECT ... INTO v_x`.
  `SELECT ... INTO STRICT v_x` raises an error unless the query returns exactly one row.
- **`FOR v_item IN query LOOP ... END LOOP`** runs the body once per row. Other loops are `WHILE condition LOOP`,
  `FOR i IN 1..10 LOOP`, `FOREACH x IN ARRAY arr LOOP` and a plain `LOOP` that you leave with `EXIT WHEN ...`.
- **`FOUND`** is a special variable, true when the last statement affected or returned at least one row. (`GET
  DIAGNOSTICS v_count = ROW_COUNT` gives the exact number.)
- **`RAISE EXCEPTION`** aborts with an error. The `%` placeholders are filled from the arguments after the message,
  and `USING` sets the SQLSTATE, a `HINT`, a `DETAIL` and more. `RAISE NOTICE` and `RAISE WARNING` print a message
  without stopping.

A successful order:

```sql
SELECT place_order(5, '[{"product_id": 11, "quantity": 2}, {"product_id": 7, "quantity": 1}]');
SELECT product_id, quantity, unit_price FROM order_items WHERE order_id = 13 ORDER BY product_id;
SELECT id, stock FROM products WHERE id IN (7, 11) ORDER BY id;
```

```text
 place_order
-------------
          13
(1 row)

 product_id | quantity | unit_price
------------+----------+------------
          7 |        1 |      14.00
         11 |        2 |      16.00
(2 rows)

 id | stock
----+-------
  7 |    74
 11 |    28
(2 rows)
```

And one that asks for more than the stock (the Heron pen, product 2, has 3):

```sql
SELECT place_order(5, '[{"product_id": 7, "quantity": 1}, {"product_id": 2, "quantity": 5}]');
\echo :LAST_ERROR_SQLSTATE
SELECT count(*) AS orders, (SELECT stock FROM products WHERE id = 7) AS notebook_stock FROM orders;
```

```text
ERROR:  product 2 is not available in quantity 5
HINT:  Reload the basket to see current stock.
CONTEXT:  PL/pgSQL function place_order(bigint,jsonb) line 29 at RAISE
IW001
 orders | notebook_stock
--------+----------------
     13 |             74
(1 row)
```

The error carries the custom SQLSTATE `IW001` (any five uppercase letters or digits that don't collide with
PostgreSQL's own codes; an application can match on it, see chapter 22), and the `HINT`. Nothing of the failed order
remains: the order row, the notebook's stock reduction and its order item were all part of the statement that failed,
so all were rolled back. The `CONTEXT` line tells you where in the function the error was raised.

### Handling errors

An `EXCEPTION` section catches errors raised in its block. The block's changes are rolled back to where the block
started, and the handler runs instead. A variant of the checkout that returns `NULL` instead of failing when a product
is out of stock:

```sql
CREATE FUNCTION try_place_order(p_customer_id bigint, p_items jsonb)
RETURNS bigint
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN place_order(p_customer_id, p_items);
EXCEPTION
    WHEN SQLSTATE 'IW001' THEN
        RAISE NOTICE 'order not placed: %', SQLERRM;
        RETURN NULL;
END;
$$;

SELECT try_place_order(5, '[{"product_id": 2, "quantity": 5}]') AS order_id;
```

```text
CREATE FUNCTION
NOTICE:  order not placed: product 2 is not available in quantity 5
 order_id
----------

(1 row)
```

`SQLERRM` and `SQLSTATE` hold the caught error's message and code inside a handler. Conditions can be named
(`unique_violation`, `foreign_key_violation`, `division_by_zero`; the full list is in the documentation's "PostgreSQL
Error Codes" appendix) or given as `SQLSTATE 'xxxxx'`. `WHEN OTHERS` catches everything except cancellation, and is
usually a mistake: an error you didn't expect should reach the caller.

Two things to know about `EXCEPTION` blocks. They are implemented with a savepoint, which costs time on every entry,
so don't put one inside a loop over many rows without need. And catching an error doesn't make a failed operation
succeed; it only lets you decide what to do next.

### Dynamic SQL

Sometimes the statement itself is only known at run time: a table name passed as an argument, or optional filters.
`EXECUTE` runs a statement from a string. Build it with `format()`, quoting identifiers with `%I` and passing values
with `USING` (never by concatenation, which invites SQL injection):

```sql
CREATE FUNCTION count_rows(p_table regclass, p_where_column text DEFAULT NULL, p_value text DEFAULT NULL)
RETURNS bigint
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    v_count bigint;
BEGIN
    IF p_where_column IS NULL THEN
        EXECUTE format('SELECT count(*) FROM %s', p_table) INTO v_count;
    ELSE
        EXECUTE format('SELECT count(*) FROM %s WHERE %I::text = $1', p_table, p_where_column)
            INTO v_count
            USING p_value;
    END IF;
    RETURN v_count;
END;
$$;

SELECT count_rows('orders') AS all_orders,
       count_rows('orders', 'status', 'delivered') AS delivered,
       count_rows('customers', 'country', 'US') AS us_customers;
```

```text
CREATE FUNCTION
 all_orders | delivered | us_customers
------------+-----------+--------------
         13 |         6 |            2
(1 row)
```

The `regclass` parameter type does its own validation: a table that doesn't exist fails when the argument is
converted, and its value is already safely quoted when printed with `%s`. A column name that doesn't exist fails when
the statement runs. A value passed with `USING` is a parameter, so it can never change the statement's structure.

## Procedures

A **procedure** is like a function that returns nothing, called with `CALL`. Its special ability: it can **commit and
roll back** in the middle, which a function never can. That makes procedures the tool for batch jobs that must not
hold one giant transaction open, such as the backfills chapter 17 recommended running in batches.

The shop wants to mark delivered every order shipped more than 14 days ago, a few rows at a time:

```sql
CREATE PROCEDURE mark_delivered(p_batch_size integer DEFAULT 1000)
LANGUAGE plpgsql
AS $$
DECLARE
    v_rows integer;
    v_total integer := 0;
BEGIN
    LOOP
        UPDATE orders
        SET status = 'delivered'
        WHERE id IN (
            SELECT id FROM orders
            WHERE status = 'shipped' AND shipped_at < now() - interval '14 days'
            ORDER BY id
            LIMIT p_batch_size
            FOR UPDATE SKIP LOCKED
        );
        GET DIAGNOSTICS v_rows = ROW_COUNT;
        EXIT WHEN v_rows = 0;
        v_total := v_total + v_rows;
        COMMIT;   -- release the locks, make this batch permanent
        RAISE NOTICE 'batch done, % orders so far', v_total;
    END LOOP;
END;
$$;

CALL mark_delivered(1);
SELECT id, status FROM orders WHERE id IN (7, 9) ORDER BY id;
```

```text
CREATE PROCEDURE
NOTICE:  batch done, 1 orders so far
NOTICE:  batch done, 2 orders so far
CALL
 id |  status
----+-----------
  7 | delivered
  9 | delivered
(2 rows)
```

Each batch is its own transaction, so a failure in batch 50 keeps batches 1–49, the locks are held only briefly, and
`VACUUM` can clean up behind the job while it runs. (`FOR UPDATE SKIP LOCKED` lets two copies of the job run side by
side without stepping on each other.) A procedure can only commit when it's called on its own, not inside a
`BEGIN` ... `COMMIT` block or from a function, because then the transaction belongs to the caller:

```sql
BEGIN;
UPDATE orders SET status = 'shipped', shipped_at = '2026-04-10 15:00' WHERE id = 12;
CALL mark_delivered();
ROLLBACK;
```

```text
BEGIN
UPDATE 1
ERROR:  invalid transaction termination
CONTEXT:  PL/pgSQL function mark_delivered(integer) line 19 at COMMIT
ROLLBACK
```

## Triggers

A **trigger** runs a function automatically when rows are inserted, updated, deleted or truncated. You define the
function first, with the special return type `trigger`, and then attach it to a table:

```text
CREATE TRIGGER name
    { BEFORE | AFTER | INSTEAD OF } { INSERT | UPDATE [ OF column, ... ] | DELETE | TRUNCATE } [ OR ... ]
    ON table
    [ REFERENCING { OLD | NEW } TABLE AS name ]
    FOR EACH { ROW | STATEMENT }
    [ WHEN ( condition ) ]
    EXECUTE FUNCTION function_name ( arguments );
```

| | `BEFORE` | `AFTER` |
|---|---|---|
| **`FOR EACH ROW`** | runs before each row is written; can change the row (`NEW`) or skip it (return `NULL`) | runs after each row is written; sees the final row; return value ignored |
| **`FOR EACH STATEMENT`** | runs once before the statement | runs once after the statement; can see all changed rows through transition tables |

Inside a PL/pgSQL trigger function, `NEW` is the new row (for `INSERT` and `UPDATE`), `OLD` the old row (for `UPDATE`
and `DELETE`), and `TG_OP`, `TG_TABLE_NAME`, `TG_WHEN` and `TG_LEVEL` describe the event. `INSTEAD OF` triggers exist
for views (chapter 20).

### Maintaining a column: updated_at

The most common trigger keeps a "last modified" timestamp correct, whoever changes the row. Customers get one:

```sql
ALTER TABLE customers ADD COLUMN updated_at timestamptz NOT NULL DEFAULT now();

CREATE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

CREATE TRIGGER customers_set_updated_at
    BEFORE UPDATE ON customers
    FOR EACH ROW
    WHEN (OLD IS DISTINCT FROM NEW)
    EXECUTE FUNCTION set_updated_at();
```

```text
ALTER TABLE
CREATE FUNCTION
CREATE TRIGGER
```

A `BEFORE ROW` trigger function returns the row to write: `NEW`, possibly modified. Returning `NULL` silently skips
the row. The `WHEN` clause skips the trigger for updates that change nothing (so `updated_at` means "content last
changed"), and it's checked without even calling the function. The function is generic: attach it to every table
with an `updated_at` column. (One restriction: a `BEFORE` trigger's `WHEN` clause can't compare whole rows on a table
with generated columns, such as `products` with its `search` column. There, compare the relevant columns instead, or
do the check inside the function.)

Remember customer 1's current timestamp, then try three updates: one that changes nothing, one that tries to set
`updated_at` itself, and a real change:

```sql
SELECT updated_at AS before FROM customers WHERE id = 1 \gset
UPDATE customers SET country = country WHERE id = 1;
SELECT updated_at = :'before' AS untouched FROM customers WHERE id = 1;
UPDATE customers SET updated_at = '2000-01-01' WHERE id = 1;
SELECT updated_at > '2000-01-01' AS fake_date_ignored FROM customers WHERE id = 1;
UPDATE customers SET full_name = 'Ana Souza Lima' WHERE id = 1;
SELECT full_name, updated_at > :'before' AS moved_on FROM customers WHERE id = 1;
```

```text
UPDATE 1
 untouched
-----------
 t
(1 row)

UPDATE 1
 fake_date_ignored
-------------------
 t
(1 row)

UPDATE 1
   full_name    | moved_on
----------------+----------
 Ana Souza Lima | t
(1 row)
```

The update that changed nothing was skipped by `WHEN`, so `updated_at` kept its value. The attempt to set
`updated_at` to the year 2000 was a change, so the trigger ran and overwrote it with `now()`: nobody can fake the
timestamp. And the real change moved it forward.

### Enforcing a rule: verified reviews

The shop wants reviews only from customers who actually received the product. That rule spans two tables, which a
`CHECK` constraint can't express, so it's a job for a trigger:

```sql
CREATE FUNCTION check_verified_review() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NOT EXISTS (
        SELECT
        FROM orders AS o
        JOIN order_items AS oi ON oi.order_id = o.id
        WHERE o.customer_id = NEW.customer_id
          AND oi.product_id = NEW.product_id
          AND o.status = 'delivered'
    ) THEN
        RAISE EXCEPTION 'customer % has not received product %', NEW.customer_id, NEW.product_id
            USING ERRCODE = 'IW002';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER reviews_verified
    BEFORE INSERT OR UPDATE OF customer_id, product_id ON reviews
    FOR EACH ROW
    EXECUTE FUNCTION check_verified_review();
```

```text
CREATE FUNCTION
CREATE TRIGGER
```

Hana (customer 8) has paid for a Heron pen (order 10), but it hasn't arrived yet. Emma (customer 5) received the
Click Ballpoint 3-pack in order 6:

```sql
INSERT INTO reviews (product_id, customer_id, rating, body)
VALUES (2, 8, 5, 'Gorgeous pen.');

INSERT INTO reviews (product_id, customer_id, rating, body)
VALUES (4, 5, 4, 'Clicks nicely.')
RETURNING product_id, customer_id, rating;
```

```text
ERROR:  customer 8 has not received product 2
CONTEXT:  PL/pgSQL function check_verified_review() line 11 at RAISE
 product_id | customer_id | rating
------------+-------------+--------
          4 |           5 |      4
(1 row)

INSERT 0 1
```

A trigger like this has a weakness that a constraint doesn't: under read committed, it checks a snapshot, so a
concurrent transaction that changes the order's status at the same moment isn't seen (chapter 17's write skew). For
a review, that's harmless. For a rule that must never break under concurrency, lock the row the rule depends on
(`SELECT ... FOR SHARE` on the order) inside the trigger, or use serializable transactions.

### An audit log

An audit log records who changed what, and when. One generic trigger function can serve every table, by storing the
old and new rows as `jsonb`:

```sql
CREATE TABLE audit_log (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    table_name  text NOT NULL,
    operation   text NOT NULL,
    row_id      bigint,
    old_row     jsonb,
    new_row     jsonb,
    changed_by  text NOT NULL DEFAULT current_user,
    changed_at  timestamptz NOT NULL DEFAULT now()
);

CREATE FUNCTION audit_row_change() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO audit_log (table_name, operation, row_id, old_row, new_row)
    VALUES (TG_TABLE_NAME,
            TG_OP,
            CASE WHEN TG_OP = 'DELETE' THEN OLD.id ELSE NEW.id END,
            CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END,
            CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END);
    RETURN NULL;   -- the return value of an AFTER trigger is ignored
END;
$$;

CREATE TRIGGER orders_audit
    AFTER INSERT OR UPDATE OR DELETE ON orders
    FOR EACH ROW
    EXECUTE FUNCTION audit_row_change();
```

```text
CREATE TABLE
CREATE FUNCTION
CREATE TRIGGER
```

It's an `AFTER` trigger, so it records what was actually written, after all `BEFORE` triggers have had their say.
Change an order's status, and look at what changed, by comparing the two `jsonb` documents key by key:

```sql
UPDATE orders SET status = 'paid' WHERE id = 12;

SELECT a.table_name, a.operation, a.row_id, a.changed_by,
       n.key AS column_name, a.old_row -> n.key AS old_value, n.value AS new_value
FROM audit_log AS a
CROSS JOIN LATERAL jsonb_each(a.new_row) AS n
WHERE a.old_row -> n.key IS DISTINCT FROM n.value
ORDER BY a.id, n.key;
```

```text
UPDATE 1
 table_name | operation | row_id | changed_by | column_name | old_value | new_value
------------+-----------+--------+------------+-------------+-----------+-----------
 orders     | UPDATE    |     12 | postgres   | status      | "pending" | "paid"
(1 row)
```

Also, the order created by `place_order` earlier is not in the log, because the trigger didn't exist yet; the log only
knows what happened since. Things to decide when you build an audit log:

- **What to store.** Whole rows are simplest; storing only changed columns saves space. Never log secrets such as
  password hashes.
- **Who changed it.** `current_user` is the database role. With a web application that connects as one role for all
  users, pass the real user in a setting: `SET LOCAL app.user_id = '42'` in each transaction, and read it in the
  trigger with `current_setting('app.user_id', true)`.
- **Growth.** Audit tables grow forever. Partition them by month (chapter 20) so old months can be dropped or
  archived cheaply.

### Summary tables with transition tables

A row-level `AFTER` trigger runs once per row. For a statement that changes 10,000 rows, a **statement-level** trigger
with **transition tables** is far cheaper: it runs once and sees all changed rows as tables. Here it keeps a per-product
review summary up to date:

```sql
CREATE TABLE product_review_stats (
    product_id    bigint PRIMARY KEY REFERENCES products (id),
    review_count  integer NOT NULL,
    rating_sum    integer NOT NULL
);
INSERT INTO product_review_stats
SELECT product_id, count(*), sum(rating) FROM reviews GROUP BY product_id;

CREATE FUNCTION reviews_update_stats() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO product_review_stats AS s (product_id, review_count, rating_sum)
    SELECT product_id, count(*), sum(rating)
    FROM new_reviews
    GROUP BY product_id
    ON CONFLICT (product_id) DO UPDATE
    SET review_count = s.review_count + excluded.review_count,
        rating_sum = s.rating_sum + excluded.rating_sum;
    RETURN NULL;
END;
$$;

CREATE TRIGGER reviews_stats_insert
    AFTER INSERT ON reviews
    REFERENCING NEW TABLE AS new_reviews
    FOR EACH STATEMENT
    EXECUTE FUNCTION reviews_update_stats();
```

```text
CREATE TABLE
INSERT 0 9
CREATE FUNCTION
CREATE TRIGGER
```

`REFERENCING NEW TABLE AS new_reviews` makes the inserted rows available as a table called `new_reviews` inside the
function, so one set-based statement updates all affected products. Ana (customer 1) received graphite pencils
(product 5) and dot grid notebooks (product 7) in order 4, and reviews both:

```sql
INSERT INTO reviews (product_id, customer_id, rating, body)
VALUES (5, 1, 4, 'Smooth and dark.'), (7, 1, 5, 'Lovely paper.');

SELECT product_id, review_count, rating_sum,
       round(rating_sum::numeric / review_count, 2) AS average
FROM product_review_stats
WHERE product_id IN (5, 7)
ORDER BY product_id;
```

```text
INSERT 0 2
 product_id | review_count | rating_sum | average
------------+--------------+------------+---------
          5 |            1 |          4 |    4.00
          7 |            2 |         10 |    5.00
(2 rows)
```

Both reviews passed the verified-review trigger, and the summary now counts the pencils' first review and the
notebook's second. The two kinds of trigger fired in their order: the `BEFORE ROW` trigger for each row during the
insert, the `AFTER STATEMENT` trigger once at the end.

This trigger only handles inserts; a complete version also needs `AFTER UPDATE` and `AFTER DELETE` triggers with `OLD
TABLE` transition tables (exercise 4). Summary tables maintained by triggers are fast to read, but every review insert
now also updates a row in `product_review_stats`, and concurrent reviews of the same product wait for each other on
that row. For data that may be a few minutes stale, a materialized view (chapter 20) is often simpler.

### Trigger pitfalls

- **Invisible behaviour.** Someone reading `INSERT INTO reviews ...` in the application has no idea a trigger may
  reject it or update another table. Name triggers clearly, keep them small, and document them. `\d table` lists a
  table's triggers.
- **Cost on bulk operations.** A row trigger on a table that gets a million-row `UPDATE` runs a million times. Prefer
  statement-level triggers with transition tables for anything heavy.
- **Order.** Triggers of the same kind on the same table fire in alphabetical order of their names.
- **Cascades.** A trigger that changes another table fires that table's triggers, which can fire more.
  `pg_trigger_depth()` tells a trigger how deeply nested it is, which helps to stop recursion.
- **Bypassing them.** `ALTER TABLE ... DISABLE TRIGGER name` turns a trigger off for everyone (an `ACCESS EXCLUSIVE`
  lock, so not casually in production). `SET session_replication_role = replica` makes a session skip ordinary
  triggers, which is what data-copy tools do. `TRUNCATE` doesn't fire `DELETE` triggers; it has its own event.
- **Replace safely.** `CREATE OR REPLACE TRIGGER` (14+) and `CREATE OR REPLACE FUNCTION` change a trigger or its
  function in one step. Replacing a function can't change its argument or result types; drop and recreate it for that.

> **Just a taste — you don't need to learn or fully understand this now.** *Event triggers* fire on DDL rather than on
> data changes, for example to log or forbid schema changes; they are an administrator's tool.

```sql
CREATE FUNCTION log_ddl() RETURNS event_trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE NOTICE 'DDL command: %', tg_tag;
END;
$$;
CREATE EVENT TRIGGER log_ddl_commands ON ddl_command_end EXECUTE FUNCTION log_ddl();
CREATE TABLE scratch (x integer);
DROP EVENT TRIGGER log_ddl_commands;
DROP TABLE scratch;
```

```text
CREATE FUNCTION
CREATE EVENT TRIGGER
NOTICE:  DDL command: CREATE TABLE
CREATE TABLE
DROP EVENT TRIGGER
DROP TABLE
```

## LISTEN and NOTIFY

PostgreSQL has a small built-in publish/subscribe system. A session runs `LISTEN channel`; any session can `NOTIFY
channel, 'payload'` (or call `pg_notify(channel, payload)`), and every listener receives the payload. Notifications
are **transactional**: they are delivered when the notifying transaction commits, and not at all if it rolls back.

That makes them a good fit for triggers. Chapter 17's job queue workers polled the `jobs` table; with a trigger, they
can sleep until there's work. A simpler example: tell listeners whenever an order is paid.

```sql
CREATE FUNCTION notify_order_paid() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM pg_notify('order_paid', json_build_object('order_id', NEW.id, 'customer_id', NEW.customer_id)::text);
    RETURN NULL;
END;
$$;

CREATE TRIGGER orders_notify_paid
    AFTER UPDATE OF status ON orders
    FOR EACH ROW
    WHEN (NEW.status = 'paid' AND OLD.status IS DISTINCT FROM 'paid')
    EXECUTE FUNCTION notify_order_paid();
```

```text
CREATE FUNCTION
CREATE TRIGGER
```

(`PERFORM` runs a query and discards its result, for calling functions whose result you don't need.) Session B
listens; session A pays an order in a transaction:

```sql
-- Session B
LISTEN order_paid;
```

```text
LISTEN
```

```sql
-- Session A
BEGIN;
UPDATE orders SET status = 'paid' WHERE id = 13;
```

```text
BEGIN
UPDATE 1
```

Nothing is delivered yet. When session A commits, the notification goes out. psql only shows notifications after it
runs a command, so run an empty one (`;` on its own) in session B:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

```sql
-- Session B
;
```

```text
Asynchronous notification "order_paid" with payload "{"order_id" : 13, "customer_id" : 5}" received from server process with PID 6081.
```

Your process id will be different. An application using a driver gets the notification as an event as soon as it
arrives, without polling (chapter 22 shows it in Node.js). Things to know:

- The payload is text, up to 8000 bytes. Send ids, not whole rows, and let the listener query what it needs.
- Notifications are not stored. A listener that isn't connected when one is sent never sees it, so use them as a
  wake-up signal for something durable (like the jobs table), not as the only record.
- Identical notifications in one transaction are delivered once.
- `LISTEN` needs a dedicated connection. It doesn't work through a pooler in transaction mode (chapter 22).

## Security: SECURITY DEFINER and search_path

By default a function runs with the privileges of the user who **calls** it (`SECURITY INVOKER`). A `SECURITY DEFINER`
function runs with the privileges of its **owner**, like a setuid program. That's useful: a role that may not update
`products` directly can be allowed to call `place_order`, which updates stock only in the controlled way the function
allows. Chapter 21 covers roles and privileges in detail.

It is also dangerous, because of the **`search_path`**. Unqualified names in the function body (`orders`, `products`,
even the `=` operator) are looked up through the caller's `search_path`, and a caller who can create objects in a
schema early in that path can plant their own `orders` table or `=` operator, which then runs with the owner's
privileges. So **every `SECURITY DEFINER` function must set its own `search_path`**:

```sql
ALTER FUNCTION place_order(bigint, jsonb)
    SECURITY DEFINER
    SET search_path = pg_catalog, public, pg_temp;

SELECT proname, prosecdef, proconfig FROM pg_proc WHERE proname = 'place_order';
```

```text
ALTER FUNCTION
   proname   | prosecdef |                  proconfig
-------------+-----------+---------------------------------------------
 place_order | t         | {"search_path=pg_catalog, public, pg_temp"}
(1 row)
```

Putting `pg_catalog` first means the built-in functions and operators can't be overridden; the shop's own schema
comes next. `pg_temp` goes **last on purpose**: every session can create temporary tables, and when `pg_temp` isn't
listed at all it is searched *first* for tables, so a caller could shadow `orders` with a temporary table of their own
(chapter 21 demonstrates it). (Make sure ordinary users can't create objects in that schema: in PostgreSQL 15+, they can't create in
`public` by default.) Also note that **`EXECUTE` on a new function is granted to `PUBLIC`** by default. For a security
definer function, revoke that and grant it only to the roles that need it:

```sql
REVOKE EXECUTE ON FUNCTION place_order(bigint, jsonb) FROM PUBLIC;
```

```text
REVOKE
```

## Managing server-side code

- **Keep it in version control**, in migration files, like the schema. A function in the database that exists nowhere
  else will eventually be lost or changed without review.
- **Functions are identified by name and argument types.** `place_order(bigint, jsonb)` and `place_order(bigint,
  jsonb, text)` are two different functions (overloading). `DROP FUNCTION` and `ALTER FUNCTION` need the argument
  types when the name is overloaded.
- **`CREATE OR REPLACE FUNCTION`** keeps privileges and dependent triggers intact, but can't change the signature or
  return type.
- **Test them.** A function is easy to test from SQL: call it inside `BEGIN` ... `ROLLBACK` and check the results.
  Chapter 24's capstone runs such tests from the application's test suite. (Dedicated frameworks such as pgTAP
  exist too.)
- **Debug** with `RAISE NOTICE` and `\sf`; the `CONTEXT` line of an error names the function and line.
- **Other languages.** PL/pgSQL covers most needs. PostgreSQL also ships PL/Perl, PL/Python and PL/Tcl, and third
  parties provide others. Languages marked *untrusted* (such as `plpython3u`) can access the server's file system and
  network, so only superusers can create functions in them.

## Key takeaways

- SQL functions (`LANGUAGE sql`) suit queries with parameters; SQL-standard bodies (`RETURN ...` or `BEGIN ATOMIC`) are
  checked at creation time and track dependencies. Set-returning functions are used in `FROM`.
- Volatility is a promise: `IMMUTABLE` functions are folded at planning time and allowed in indexes, `STABLE` ones can
  drive index scans, `VOLATILE` ones run for every row. Declare the strictest category that's true.
- PL/pgSQL adds variables, control flow, `RAISE`, exception handling and dynamic SQL. A function runs inside the
  caller's transaction, so an error undoes everything the statement did.
- Use `format()` with `%I` and `EXECUTE ... USING` for dynamic SQL, never string concatenation of values.
- Procedures can `COMMIT` between batches when called outside a transaction block.
- `BEFORE ROW` triggers can modify or reject rows; `AFTER` triggers see the final result. Statement-level triggers
  with transition tables handle bulk changes efficiently.
- Triggers are good for timestamps, cross-table rules, audit logs and summaries, but they hide behaviour; keep them
  small and documented.
- `LISTEN`/`NOTIFY` delivers transactional, non-durable notifications; use them to wake workers.
- A `SECURITY DEFINER` function must set its own `search_path`, and `EXECUTE` should be revoked from `PUBLIC`.

## Exercises

The exercises continue in the same database, in the state the chapter left it.

1. **Lifetime value.** Write a SQL function `customer_lifetime_value(p_customer_id bigint)` returning the total of all
   the customer's delivered orders (using `order_total`), and use it to list the three most valuable customers. Choose
   the right volatility.

<details>
<summary>Solution</summary>

```sql
CREATE FUNCTION customer_lifetime_value(p_customer_id bigint)
RETURNS numeric
LANGUAGE sql
STABLE
RETURN (SELECT coalesce(sum(order_total(o.id)), 0)
        FROM orders AS o
        WHERE o.customer_id = p_customer_id AND o.status = 'delivered');

SELECT id, full_name, customer_lifetime_value(id) AS ltv
FROM customers
ORDER BY ltv DESC, id
LIMIT 3;
```

```text
CREATE FUNCTION
 id |   full_name    |  ltv
----+----------------+--------
  3 | Chloé Martin   | 180.00
  1 | Ana Souza Lima | 122.40
  6 | Farah Haddad   |  80.00
(3 rows)
```

`STABLE`, because it reads tables: the result can change between statements, but not within one. Calling a function
per row is fine for a handful of customers; for a report over all customers, a single `GROUP BY` query is much
faster than 100,000 function calls, each running its own queries.

</details>

2. **Order history.** Create an `order_status_history` table (order id, old status, new status, changed at) and a
   trigger that adds a row whenever an order's status changes, and only then. Test it by moving order 11 from `paid`
   to `shipped` (remember the `shipped_at` rule) and then updating it without changing the status.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE order_status_history (
    order_id    bigint NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    old_status  order_status,
    new_status  order_status NOT NULL,
    changed_at  timestamptz NOT NULL DEFAULT now()
);

CREATE FUNCTION record_status_change() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    INSERT INTO order_status_history (order_id, old_status, new_status)
    VALUES (NEW.id, OLD.status, NEW.status);
    RETURN NULL;
END;
$$;

CREATE TRIGGER orders_status_history
    AFTER UPDATE OF status ON orders
    FOR EACH ROW
    WHEN (OLD.status IS DISTINCT FROM NEW.status)
    EXECUTE FUNCTION record_status_change();

UPDATE orders SET status = 'shipped', shipped_at = now() WHERE id = 11;
UPDATE orders SET shipped_at = shipped_at + interval '1 hour' WHERE id = 11;
SELECT order_id, old_status, new_status FROM order_status_history;
```

```text
CREATE TABLE
CREATE FUNCTION
CREATE TRIGGER
UPDATE 1
UPDATE 1
 order_id | old_status | new_status
----------+------------+------------
       11 | paid       | shipped
(1 row)
```

`UPDATE OF status` makes the trigger consider only updates that mention the column, and the `WHEN` condition filters
out those that set it to the same value. The second update doesn't mention `status`, so the trigger isn't even
considered. To record the initial status of new orders too, add `AFTER INSERT` with a second trigger (`OLD` is `NULL`
for inserts).

</details>

3. **Cancel an order.** Write a PL/pgSQL function `cancel_order(p_order_id bigint)` that cancels a `pending` or `paid`
   order and puts its items back in stock, and raises a clear error (SQLSTATE `IW003`) for an order that doesn't exist
   or can't be cancelled any more. Make it safe against two clerks cancelling the same order at once. Test it on orders
   12 (paid by now) and 1 (delivered).

<details>
<summary>Solution</summary>

```sql
CREATE FUNCTION cancel_order(p_order_id bigint)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    v_status order_status;
BEGIN
    UPDATE orders
    SET status = 'cancelled'
    WHERE id = p_order_id AND status IN ('pending', 'paid');

    IF NOT FOUND THEN
        SELECT status INTO v_status FROM orders WHERE id = p_order_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'order % does not exist', p_order_id USING ERRCODE = 'IW003';
        END IF;
        RAISE EXCEPTION 'order % is % and can no longer be cancelled', p_order_id, v_status
            USING ERRCODE = 'IW003';
    END IF;

    UPDATE products AS p
    SET stock = p.stock + oi.quantity
    FROM order_items AS oi
    WHERE oi.order_id = p_order_id AND oi.product_id = p.id;
END;
$$;

SELECT stock FROM products WHERE id = 7;
SELECT cancel_order(12);
SELECT stock FROM products WHERE id = 7;
SELECT cancel_order(1);
```

```text
CREATE FUNCTION
 stock
-------
    74
(1 row)

 cancel_order
--------------

(1 row)

 stock
-------
    77
(1 row)

ERROR:  order 1 is delivered and can no longer be cancelled
CONTEXT:  PL/pgSQL function cancel_order(bigint) line 14 at RAISE
```

The conditional `UPDATE` is the concurrency guard (chapter 17): if two clerks cancel at once, the second one's update
waits, re-checks `status IN ('pending', 'paid')` against the cancelled row, updates nothing, and gets the error, so the
stock is returned exactly once. The audit trigger from the chapter recorded the cancellation too.

</details>

4. **Complete the summary.** The chapter's `product_review_stats` only handles inserts. Add statement-level triggers
   for `DELETE` and `UPDATE` (a rating can change). Test by changing one of Ana's new ratings and deleting the other,
   then compare the stats table with a fresh `GROUP BY` over `reviews`.

<details>
<summary>Solution</summary>

One function can serve all three operations, using whichever transition tables exist. For `UPDATE`, it subtracts the
old rows and adds the new ones:

```sql
CREATE OR REPLACE FUNCTION reviews_update_stats() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        UPDATE product_review_stats AS s
        SET review_count = s.review_count - d.n,
            rating_sum = s.rating_sum - d.total
        FROM (SELECT product_id, count(*) AS n, sum(rating) AS total
              FROM old_reviews GROUP BY product_id) AS d
        WHERE s.product_id = d.product_id;
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        INSERT INTO product_review_stats AS s (product_id, review_count, rating_sum)
        SELECT product_id, count(*), sum(rating)
        FROM new_reviews
        GROUP BY product_id
        ON CONFLICT (product_id) DO UPDATE
        SET review_count = s.review_count + excluded.review_count,
            rating_sum = s.rating_sum + excluded.rating_sum;
    END IF;
    RETURN NULL;
END;
$$;

CREATE TRIGGER reviews_stats_update
    AFTER UPDATE ON reviews
    REFERENCING OLD TABLE AS old_reviews NEW TABLE AS new_reviews
    FOR EACH STATEMENT
    EXECUTE FUNCTION reviews_update_stats();

CREATE TRIGGER reviews_stats_delete
    AFTER DELETE ON reviews
    REFERENCING OLD TABLE AS old_reviews
    FOR EACH STATEMENT
    EXECUTE FUNCTION reviews_update_stats();

UPDATE reviews SET rating = 2 WHERE product_id = 5 AND customer_id = 1;
DELETE FROM reviews WHERE product_id = 7 AND customer_id = 1;

SELECT s.product_id, s.review_count, s.rating_sum, r.n, r.total
FROM product_review_stats AS s
FULL JOIN (SELECT product_id, count(*) AS n, sum(rating) AS total FROM reviews GROUP BY product_id) AS r
    ON r.product_id = s.product_id
WHERE s.review_count IS DISTINCT FROM coalesce(r.n, 0)
   OR s.rating_sum IS DISTINCT FROM coalesce(r.total, 0);
```

```text
CREATE FUNCTION
CREATE TRIGGER
CREATE TRIGGER
UPDATE 1
DELETE 1
 product_id | review_count | rating_sum | n | total
------------+--------------+------------+---+-------
(0 rows)
```

The comparison query returns no rows: the summary agrees with the data. (A product whose last review is deleted keeps
a row with zeros, which the `coalesce` accepts.) Each trigger can only reference the transition tables its event has,
which is why there are three triggers but one function.

</details>

5. **A procedure with progress.** Write a procedure `purge_audit_log(p_older_than interval, p_batch_size integer)`
   that deletes old audit rows in batches, committing after each batch and reporting progress with `RAISE NOTICE`.
   Test it by back-dating the existing audit rows by a year and calling it with a batch size of 2.

<details>
<summary>Solution</summary>

```sql
CREATE PROCEDURE purge_audit_log(p_older_than interval, p_batch_size integer DEFAULT 10000)
LANGUAGE plpgsql
AS $$
DECLARE
    v_deleted integer;
    v_total bigint := 0;
BEGIN
    LOOP
        DELETE FROM audit_log
        WHERE id IN (SELECT id FROM audit_log
                     WHERE changed_at < now() - p_older_than
                     ORDER BY id
                     LIMIT p_batch_size);
        GET DIAGNOSTICS v_deleted = ROW_COUNT;
        EXIT WHEN v_deleted = 0;
        v_total := v_total + v_deleted;
        COMMIT;
        RAISE NOTICE 'deleted % audit rows', v_total;
    END LOOP;
END;
$$;

SELECT count(*) AS audit_rows FROM audit_log;
UPDATE audit_log SET changed_at = changed_at - interval '1 year';
CALL purge_audit_log(interval '6 months', 2);
SELECT count(*) AS audit_rows FROM audit_log;
```

```text
CREATE PROCEDURE
 audit_rows
------------
          5
(1 row)

UPDATE 5
NOTICE:  deleted 2 audit rows
NOTICE:  deleted 4 audit rows
NOTICE:  deleted 5 audit rows
CALL
 audit_rows
------------
          0
(1 row)
```

In production, the subquery would use an index on `changed_at`, and a partitioned audit table (chapter 20) would make
the job a matter of dropping old partitions.

</details>

6. **Who changed it?** Extend the audit log so that it records the application's user, passed with `SET LOCAL
   app.user_id = '...'` in each transaction, and falls back to `current_user` when the setting is missing. Show both
   cases.

<details>
<summary>Solution</summary>

Change the column default rather than the trigger function, so every way of inserting into `audit_log` gets it:

```sql
ALTER TABLE audit_log
    ALTER COLUMN changed_by SET DEFAULT coalesce(nullif(current_setting('app.user_id', true), ''), current_user);

BEGIN;
SET LOCAL app.user_id = 'staff:emma';
UPDATE orders SET status = 'shipped', shipped_at = now() WHERE id = 13;
COMMIT;

UPDATE orders SET status = 'delivered' WHERE id = 13;

SELECT row_id, new_row ->> 'status' AS status, changed_by
FROM audit_log
WHERE row_id = 13
ORDER BY id;
```

```text
ALTER TABLE
BEGIN
SET
UPDATE 1
COMMIT
UPDATE 1
 row_id |  status   | changed_by
--------+-----------+------------
     13 | shipped   | staff:emma
     13 | delivered | postgres
(2 rows)
```

`current_setting('app.user_id', true)` returns `NULL` instead of an error when the setting doesn't exist (the `true`
means "missing is OK"). After a `SET LOCAL` in an earlier transaction, the setting exists but is reset to an empty
string, hence the `nullif`. Custom settings need a dot in their name, which keeps them apart from PostgreSQL's own.
`SET LOCAL` lasts only until the end of the transaction, which matters with connection pools: the next request on the
same connection won't inherit the user. Note that this records what the application *claims*; it's for accountability
in a trusted application, not a security boundary.

</details>

---
← Previous: [Storage, VACUUM and maintenance](18-storage-vacuum-and-maintenance.md) | Next: [Views, materialized views and partitioning](20-views-materialized-views-and-partitioning.md) →
