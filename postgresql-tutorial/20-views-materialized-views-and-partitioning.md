# Chapter 20 — Views, materialized views and partitioning

## What you'll learn

- Views: saved queries that act like tables, how the planner sees through them, and how to change them safely
- Updatable views, `WITH CHECK OPTION`, and `INSTEAD OF` triggers for the views that aren't updatable
- Materialized views: storing a query's result, indexing it, and refreshing it with and without blocking readers
- Declarative partitioning: range, list and hash partitions, the default partition, and what the partition key
  means for keys and indexes
- Partition pruning at planning and execution time
- Partition maintenance: creating partitions ahead, detaching and dropping old ones, attaching existing tables
- When partitioning helps, and when it only adds work

This chapter is about three ways of putting a layer between how data is stored and how it's queried. A **view** gives
a query a name. A **materialized view** stores the result of that query, trading freshness for speed. **Partitioning**
splits one logical table into many physical ones, so that the database can ignore most of a huge table most of the
time, and throw old data away in an instant.

## Setting up

Views work on any amount of data, but materialized views and partitioning only show their value on a lot of it. Load
the bulk data from chapter 15, and turn off parallel query to keep the plans simple (chapter 16):

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
psql -X -v ON_ERROR_STOP=1 -d inkwell -f sql/bulk.sql
```

```sql
SET max_parallel_workers_per_gather = 0;
```

```text
SET
```

## Views

A **view** is a stored `SELECT` with a name. It holds no data: every time you query it, PostgreSQL substitutes its
definition into your query. A summary line per order is something many screens and reports need:

```sql
CREATE VIEW order_summaries AS
SELECT o.id,
       o.customer_id,
       c.full_name AS customer_name,
       o.status,
       o.created_at,
       count(oi.product_id) AS item_count,
       coalesce(sum(oi.quantity * oi.unit_price), 0) AS total
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
LEFT JOIN order_items AS oi ON oi.order_id = o.id
GROUP BY o.id, c.full_name;

SELECT * FROM order_summaries WHERE id IN (1, 4242) ORDER BY id;
```

```text
CREATE VIEW
  id  | customer_id | customer_name |  status   |          created_at           | item_count | total
------+-------------+---------------+-----------+-------------------------------+------------+-------
    1 |           1 | Ana Souza     | delivered | 2026-01-06 10:00:00+00        |          2 | 80.00
 4242 |       16927 | Ivan Khan     | delivered | 2023-01-05 15:08:10.700993+00 |          3 | 36.86
(2 rows)
```

(Grouping by `o.id` alone is enough for all of `orders`' columns, because it's the primary key; chapter 8 explained
why.) Views are good for:

- **Naming a concept once.** "Order total" is defined in one place instead of in every query that needs it.
- **Simplifying queries** for people and tools that don't need to know the joins.
- **A stable interface.** The application reads the view; you can reorganize the tables behind it and redefine the
  view to match.
- **Hiding columns or rows** from roles that may read the view but not the tables (chapter 21).

### The planner sees through views

Because the view's definition is merged into the query, the planner optimises the whole thing. A filter on the view
is pushed down into it, so asking for one order doesn't compute the summary of a million orders first:

```sql
EXPLAIN (COSTS OFF) SELECT * FROM order_summaries WHERE id = 4242;
```

```text
                               QUERY PLAN
------------------------------------------------------------------------
 GroupAggregate
   Group Key: c.full_name
   ->  Sort
         Sort Key: c.full_name
         ->  Nested Loop Left Join
               ->  Nested Loop
                     ->  Index Scan using orders_pkey on orders o
                           Index Cond: (id = 4242)
                     ->  Index Scan using customers_pkey on customers c
                           Index Cond: (id = o.customer_id)
               ->  Index Scan using order_items_pkey on order_items oi
                     Index Cond: (order_id = 4242)
(12 rows)
```

The plan reads one order through `orders_pkey`, its customer through `customers_pkey`, and its items through the
primary key of `order_items`. There is no `order_summaries` node at all: the view simply disappeared into the plan. A
view costs nothing by itself. It can only hide an expensive query, which then runs every time.

Pushdown has limits. A filter on a column that is *computed* by the aggregate, such as `WHERE total > 500`, can only be
applied after the groups are built, so it does need to compute every order's total. That's no different from writing
the query out by hand, but the view makes it easy to forget.

### Changing a view

`\d+ order_summaries` shows a view's columns and its definition, and `pg_get_viewdef()` returns it as text. To change
a view, `CREATE OR REPLACE VIEW` works as long as the existing columns keep their names, types and order; new columns
may only be added at the end:

```sql
CREATE OR REPLACE VIEW order_summaries AS
SELECT o.id,
       o.customer_id,
       c.full_name AS customer_name,
       o.status,
       o.created_at,
       count(oi.product_id) AS item_count,
       coalesce(sum(oi.quantity * oi.unit_price), 0) AS total,
       c.country
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
LEFT JOIN order_items AS oi ON oi.order_id = o.id
GROUP BY o.id, c.full_name, c.country;
```

```text
CREATE VIEW
```

Anything else (renaming or removing a column, changing its type) needs `DROP VIEW` and `CREATE VIEW`, in one
transaction so that nobody sees the view missing. Two more rules worth knowing:

- **Views depend on the columns they use.** PostgreSQL won't let you drop or change the type of a column that a view
  uses:

  ```sql
  ALTER TABLE customers DROP COLUMN country;
  ```

  ```text
  ERROR:  cannot drop column country of table customers because other objects depend on it
  DETAIL:  view order_summaries depends on column country of table customers
  HINT:  Use DROP ... CASCADE to drop the dependent objects too.
  ```

  `DROP ... CASCADE` would drop the view as well, which is rarely what you want. Migrations that change such columns
  drop and recreate the dependent views around the change.
- **`SELECT *` in a view is expanded when the view is created.** A column added to the table later doesn't appear in
  the view until you recreate it.

### Views for soft deletes

Chapter 14 suggested a view of live rows for tables with soft deletes. The shop's inactive products are a mild form
of that: most screens should only ever see active products.

```sql
CREATE VIEW active_products AS
SELECT id, sku, name, category_id, price, stock
FROM products
WHERE active;

SELECT count(*) AS all_products, (SELECT count(*) FROM active_products) AS active FROM products;
```

```text
CREATE VIEW
 all_products | active
--------------+--------
           14 |     13
(1 row)
```

## Updatable views

A view like `active_products`, which selects from a single table without aggregates, `DISTINCT`, `GROUP BY`, set
operations or window functions, is **automatically updatable**. `INSERT`, `UPDATE` and `DELETE` on it are turned
into the same commands on `products`:

```sql
UPDATE active_products SET price = 16.50 WHERE sku = 'IN-001' RETURNING id, name, price;

INSERT INTO active_products (sku, name, category_id, price, stock)
VALUES ('IN-003', 'Emerald Ink 50ml', 6, 16.00, 20)
RETURNING id, name;
```

```text
 id |          name          | price
----+------------------------+-------
 11 | Midnight Blue Ink 50ml | 16.50
(1 row)

UPDATE 1
 id |       name
----+------------------
 15 | Emerald Ink 50ml
(1 row)

INSERT 0 1
```

Columns that the view doesn't show get their defaults (`active` is `true` by default, so the new ink is active). But
nothing stops a change that makes a row disappear from the view, such as deactivating it:

```sql
UPDATE active_products SET name = 'Emerald Ink 30ml' WHERE sku = 'IN-003';
UPDATE products SET active = false WHERE sku = 'IN-003';
SELECT count(*) FROM active_products WHERE sku = 'IN-003';
```

```text
UPDATE 1
UPDATE 1
 count
-------
     0
(1 row)
```

The second update had to go through the table, because the view doesn't have an `active` column. A view that does
show a filtered column could insert rows that it then can't see. **`WITH CHECK OPTION`** forbids that: every row
inserted or updated through the view must satisfy its `WHERE` clause. A view for the staff who manage the ink
category:

```sql
CREATE VIEW ink_products AS
SELECT id, sku, name, category_id, price, stock
FROM products
WHERE category_id = 6
WITH CHECK OPTION;

UPDATE ink_products SET price = 17.00 WHERE sku = 'IN-002';
UPDATE ink_products SET category_id = 8 WHERE sku = 'IN-002';
```

```text
CREATE VIEW
UPDATE 1
ERROR:  new row violates check option for view "ink_products"
DETAIL:  Failing row contains (12, IN-002, Sepia Ink 50ml, Warm brown fountain pen ink with a vintage look., 8, 17.00, 25, {"color": "brown", "volume_ml": 50}, {}, t, 2025-12-01 09:00:00+00).
```

Moving the ink into the notebooks category would make it vanish from the view, so the check option rejects it.

### INSTEAD OF triggers

A view with joins or aggregates isn't automatically updatable:

```sql
UPDATE order_summaries SET status = 'cancelled' WHERE id = 12;
```

```text
ERROR:  cannot update view "order_summaries"
DETAIL:  Views containing GROUP BY are not automatically updatable.
HINT:  To enable updating the view, provide an INSTEAD OF UPDATE trigger or an unconditional ON UPDATE DO INSTEAD rule.
```

When it makes sense to write through such a view, an **`INSTEAD OF` trigger** (chapter 19) tells PostgreSQL what to
do instead. Here, an update of the status through `order_summaries` updates the order; changes to any other column are
refused:

```sql
CREATE FUNCTION order_summaries_update() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF (NEW.id, NEW.customer_id, NEW.customer_name, NEW.created_at, NEW.item_count, NEW.total, NEW.country)
       IS DISTINCT FROM
       (OLD.id, OLD.customer_id, OLD.customer_name, OLD.created_at, OLD.item_count, OLD.total, OLD.country) THEN
        RAISE EXCEPTION 'only the status can be changed through order_summaries';
    END IF;
    UPDATE orders SET status = NEW.status WHERE id = OLD.id;
    RETURN NEW;
END;
$$;

CREATE TRIGGER order_summaries_update
    INSTEAD OF UPDATE ON order_summaries
    FOR EACH ROW
    EXECUTE FUNCTION order_summaries_update();

UPDATE order_summaries SET status = 'cancelled' WHERE id = 12;
SELECT id, status FROM orders WHERE id = 12;
```

```text
CREATE FUNCTION
CREATE TRIGGER
UPDATE 1
 id |  status
----+-----------
 12 | cancelled
(1 row)
```

`INSTEAD OF` triggers are always row-level. Use them sparingly: an application that needs to change orders can
usually just update `orders`.

## Materialized views

A view runs its query every time. When the query is expensive and the answer doesn't need to be up to the second,
a **materialized view** stores the result in a real table, which you refresh when you choose. A monthly sales report
per product, over a million orders, is a typical case. First, the query on its own:

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, BUFFERS OFF)
SELECT date_trunc('month', o.created_at) AS month, oi.product_id,
       sum(oi.quantity) AS units, sum(oi.quantity * oi.unit_price) AS revenue
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status = 'delivered'
GROUP BY 1, 2;
```

```text
                               QUERY PLAN
-------------------------------------------------------------------------
 HashAggregate (actual rows=444.00 loops=1)
   Group Key: date_trunc('month'::text, o.created_at), oi.product_id
   Batches: 1  Memory Usage: 817kB
   ->  Hash Join (actual rows=2423745.00 loops=1)
         Hash Cond: (oi.order_id = o.id)
         ->  Seq Scan on order_items oi (actual rows=2498811.00 loops=1)
         ->  Hash (actual rows=969856.00 loops=1)
               Buckets: 262144  Batches: 8  Memory Usage: 7735kB
               ->  Seq Scan on orders o (actual rows=969856.00 loops=1)
                     Filter: (status = 'delivered'::order_status)
                     Rows Removed by Filter: 30156
 Planning Time: 0.252 ms
 Execution Time: 2107.556 ms
(13 rows)
```

A hash join of two big tables and an aggregate: about two seconds on the machine used for this tutorial, for a report
that only changes once a day. Materialize it:

```sql
CREATE MATERIALIZED VIEW monthly_product_sales AS
SELECT date_trunc('month', o.created_at) AS month, oi.product_id,
       sum(oi.quantity) AS units, sum(oi.quantity * oi.unit_price) AS revenue
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status = 'delivered'
GROUP BY 1, 2;

CREATE UNIQUE INDEX monthly_product_sales_key ON monthly_product_sales (month, product_id);

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, BUFFERS OFF)
SELECT * FROM monthly_product_sales WHERE month = '2025-06-01' ORDER BY revenue DESC;
```

```text
SELECT 444
CREATE INDEX
                                       QUERY PLAN
----------------------------------------------------------------------------------------
 Sort (actual rows=12.00 loops=1)
   Sort Key: revenue DESC
   Sort Method: quicksort  Memory: 25kB
   ->  Bitmap Heap Scan on monthly_product_sales (actual rows=12.00 loops=1)
         Recheck Cond: (month = '2025-06-01 00:00:00+00'::timestamp with time zone)
         Heap Blocks: exact=4
         ->  Bitmap Index Scan on monthly_product_sales_key (actual rows=12.00 loops=1)
               Index Cond: (month = '2025-06-01 00:00:00+00'::timestamp with time zone)
               Index Searches: 1
 Planning Time: 0.124 ms
 Execution Time: 0.051 ms
(11 rows)
```

The report now reads 12 rows from a small table through an index, in a fraction of a millisecond. A materialized view
is a table in every way that matters for reading: it has storage, statistics and indexes, and it shows up in `\dm`.
The best-selling products of June 2025:

```sql
SELECT p.name, s.units, s.revenue
FROM monthly_product_sales AS s
JOIN products AS p ON p.id = s.product_id
WHERE s.month = '2025-06-01'
ORDER BY s.revenue DESC
LIMIT 3;
```

```text
         name         | units |  revenue
----------------------+-------+------------
 Heron Fountain Pen   | 10218 | 1318122.00
 Kestrel Fountain Pen | 19709 |  946032.00
 Sketching Pencil Set | 16384 |  303104.00
(3 rows)
```

### Refreshing

The materialized view doesn't change when the tables do. A new delivered order in June 2025 doesn't show up until the
next **refresh**:

```sql
INSERT INTO orders (customer_id, status, created_at, shipped_at)
VALUES (42, 'delivered', '2025-06-15 10:00', '2025-06-16 09:00');
INSERT INTO order_items (order_id, product_id, quantity, unit_price)
VALUES (currval('orders_id_seq'), 2, 10, 129.00);

SELECT units, revenue FROM monthly_product_sales WHERE month = '2025-06-01' AND product_id = 2;
REFRESH MATERIALIZED VIEW monthly_product_sales;
SELECT units, revenue FROM monthly_product_sales WHERE month = '2025-06-01' AND product_id = 2;
```

```text
INSERT 0 1
INSERT 0 1
 units |  revenue
-------+------------
 10218 | 1318122.00
(1 row)

REFRESH MATERIALIZED VIEW
 units |  revenue
-------+------------
 10228 | 1319412.00
(1 row)
```

(`currval('orders_id_seq')` returns the id the previous insert got from the identity column's sequence, in this
session.) A plain `REFRESH` re-runs the query and replaces the contents, holding an `ACCESS EXCLUSIVE` lock for the
duration: anyone who queries the view meanwhile waits, for the whole two seconds here, or for an hour on a really big
report. **`REFRESH MATERIALIZED VIEW CONCURRENTLY`** avoids that. It computes the new result in the background,
compares it with the old one, and applies only the differences, while readers keep reading the old contents. It needs
a unique index on the materialized view (to match old and new rows), which is why the one above was created `UNIQUE`:

```sql
REFRESH MATERIALIZED VIEW CONCURRENTLY monthly_product_sales;
```

```text
REFRESH MATERIALIZED VIEW
```

The trade-offs:

| | `REFRESH` | `REFRESH ... CONCURRENTLY` |
|---|---|---|
| Readers during the refresh | blocked | keep reading the old data |
| Requirements | none | a unique index without a `WHERE` clause, covering all rows |
| Speed | faster: rewrites the table | slower: computes the differences, and leaves dead tuples behind |
| Best when | the view is small, or nobody reads it during the refresh | the view is read constantly |

Neither is incremental: both run the whole query. Refresh on a schedule (a cron job, a scheduler in the application,
or the `pg_cron` extension where it's available) as often as the report's freshness requires and the query's cost
allows. A materialized view can also be created `WITH NO DATA`, which creates it empty and unusable until the first
`REFRESH`, useful in migrations that shouldn't run the expensive query.

### Materialized view or summary table?

Chapter 19 maintained `product_review_stats` with triggers: always exact, but every write pays for it, and concurrent
writes to the same product wait for each other. A materialized view is the opposite: writes cost nothing extra, and
reads are as stale as the last refresh. Choose by what the data is for: a dashboard of yesterday's sales is fine with a
nightly refresh; a stock level is not.

## Partitioning

Some tables grow without end: events, logs, measurements, audit trails, order history. At hundreds of millions of rows,
three things get hard. Queries that only want recent data still pay for indexes built over all of it; `VACUUM` has to
work through the whole table; and deleting old data with `DELETE` is slow, generates huge amounts of WAL and leaves
bloat behind (chapter 18).

**Partitioning** splits one logical table into several physical tables, the **partitions**, by the value of a
**partition key**. Queries go to the parent table as usual, and PostgreSQL routes each row to the right partition and
skips partitions that can't contain matching rows. Three methods:

| Method | Each partition holds | Typical use |
|---|---|---|
| `RANGE` | a range of key values, e.g. one month of timestamps | time-based data: the most common case |
| `LIST` | an explicit list of values, e.g. a set of countries | data split by region, tenant tier or category |
| `HASH` | rows whose key hashes to it (`MODULUS n, REMAINDER r`) | spreading a big table evenly when there's no natural range |

### A partitioned event table

The shop wants a history of events for each order. Every order produces a "created" event and, when shipped, a second
one, so this table grows twice as fast as `orders` and is mostly queried for recent periods. Create it partitioned by
time, with one partition per year (monthly partitions are more common for really big tables, but would make the plans
below very long):

```sql
CREATE TABLE order_events (
    order_id    bigint NOT NULL,
    event       text NOT NULL,
    created_at  timestamptz NOT NULL,
    details     jsonb NOT NULL DEFAULT '{}'
) PARTITION BY RANGE (created_at);

CREATE TABLE order_events_2023 PARTITION OF order_events
    FOR VALUES FROM ('2023-01-01') TO ('2024-01-01');
CREATE TABLE order_events_2024 PARTITION OF order_events
    FOR VALUES FROM ('2024-01-01') TO ('2025-01-01');
CREATE TABLE order_events_2025 PARTITION OF order_events
    FOR VALUES FROM ('2025-01-01') TO ('2026-01-01');
```

```text
CREATE TABLE
CREATE TABLE
CREATE TABLE
CREATE TABLE
```

The parent table `order_events` stores nothing itself. Range bounds include the lower value and exclude the upper one,
so the partitions fit together without gaps or overlaps. Fill it from the order history:

```sql
INSERT INTO order_events (order_id, event, created_at)
SELECT id, 'created', created_at FROM orders WHERE created_at < '2026-01-01'
UNION ALL
SELECT id, 'shipped', shipped_at FROM orders WHERE shipped_at < '2026-01-01';

CREATE INDEX ON order_events (order_id);
ANALYZE order_events;

SELECT tableoid::regclass AS partition, count(*)
FROM order_events
GROUP BY 1
ORDER BY 1;
```

```text
INSERT 0 1969304
CREATE INDEX
ANALYZE
     partition     | count
-------------------+--------
 order_events_2023 | 655211
 order_events_2024 | 658592
 order_events_2025 | 655501
(3 rows)
```

`tableoid` is a hidden column that says which table a row is physically in. The index created on the parent was
created on every partition (and will be on future ones), named after each partition.

A row whose key fits no partition is rejected:

```sql
INSERT INTO order_events (order_id, event, created_at) VALUES (1, 'created', '2026-01-06 10:00');
```

```text
ERROR:  no partition of relation "order_events" found for row
DETAIL:  Partition key of the failing row contains (created_at) = (2026-01-06 10:00:00+00).
```

Two ways to deal with that: create partitions ahead of time (the usual approach for time ranges), and optionally a
**default partition**, which catches everything that fits no other partition:

```sql
CREATE TABLE order_events_2026 PARTITION OF order_events
    FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
CREATE TABLE order_events_default PARTITION OF order_events DEFAULT;

INSERT INTO order_events (order_id, event, created_at)
VALUES (1, 'created', '2026-01-06 10:00'), (1, 'created', '1999-12-31 23:59');

SELECT tableoid::regclass AS partition, order_id, created_at
FROM order_events
WHERE order_id = 1;
```

```text
CREATE TABLE
CREATE TABLE
INSERT 0 2
      partition       | order_id |       created_at
----------------------+----------+------------------------
 order_events_2026    |        1 | 2026-01-06 10:00:00+00
 order_events_default |        1 | 1999-12-31 23:59:00+00
(2 rows)
```

A default partition is a safety net, but a costly one to rely on: before you can add a partition for a range, any rows
of that range must be moved out of the default partition, and PostgreSQL scans the default partition to check. Keep it
empty, and alert when it isn't.

### Partition pruning

The point of all this: a query that filters on the partition key reads only the partitions that can match. This is
**partition pruning**:

```sql
EXPLAIN (COSTS OFF)
SELECT count(*) FROM order_events
WHERE created_at >= '2025-03-01' AND created_at < '2025-04-01';
```

```text
                                                                         QUERY PLAN
------------------------------------------------------------------------------------------------------------------------------------------------------------
 Aggregate
   ->  Seq Scan on order_events_2025 order_events
         Filter: ((created_at >= '2025-03-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2025-04-01 00:00:00+00'::timestamp with time zone))
(3 rows)
```

Only `order_events_2025` is scanned; the other four partitions were eliminated while planning. With monthly
partitions, it would read one month instead of a year. A query that *doesn't* filter on the key has to look at every
partition:

```sql
EXPLAIN (COSTS OFF) SELECT * FROM order_events WHERE order_id = 4242;
```

```text
                                        QUERY PLAN
-------------------------------------------------------------------------------------------
 Append
   ->  Index Scan using order_events_2023_order_id_idx on order_events_2023 order_events_1
         Index Cond: (order_id = 4242)
   ->  Index Scan using order_events_2024_order_id_idx on order_events_2024 order_events_2
         Index Cond: (order_id = 4242)
   ->  Index Scan using order_events_2025_order_id_idx on order_events_2025 order_events_3
         Index Cond: (order_id = 4242)
   ->  Bitmap Heap Scan on order_events_2026 order_events_4
         Recheck Cond: (order_id = 4242)
         ->  Bitmap Index Scan on order_events_2026_order_id_idx
               Index Cond: (order_id = 4242)
   ->  Bitmap Heap Scan on order_events_default order_events_5
         Recheck Cond: (order_id = 4242)
         ->  Bitmap Index Scan on order_events_default_order_id_idx
               Index Cond: (order_id = 4242)
(15 rows)
```

Five small index scans instead of one big one. With 36 monthly partitions it would be 36, and with thousands of
partitions, planning alone gets slow. So **choose the partition key to match how the table is queried**, and keep the
number of partitions reasonable (dozens to a few thousand, not tens of thousands).

Pruning also happens **at execution time**, when the value isn't known while planning: a parameter of a prepared
statement (chapter 16's generic plans), or a value from a subquery or join. `EXPLAIN ANALYZE` then reports how many
partitions were skipped:

```sql
PREPARE events_in_month(timestamptz) AS
SELECT count(*) FROM order_events
WHERE created_at >= $1 AND created_at < $1 + interval '1 month';

SET plan_cache_mode = force_generic_plan;
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF) EXECUTE events_in_month('2024-02-01');
RESET plan_cache_mode;
DEALLOCATE events_in_month;
```

```text
PREPARE
SET
                                       QUERY PLAN
-----------------------------------------------------------------------------------------
 Aggregate (actual rows=1.00 loops=1)
   ->  Append (actual rows=52125.00 loops=1)
         Subplans Removed: 4
         ->  Seq Scan on order_events_2024 order_events_1 (actual rows=52125.00 loops=1)
               Filter: ((created_at >= $1) AND (created_at < ($1 + '1 mon'::interval)))
               Rows Removed by Filter: 606467
(6 rows)

RESET
DEALLOCATE
```

`Subplans Removed: 4`: the generic plan includes all five partitions, and four were pruned when the parameter's value
became known.

### Keys, constraints and indexes

A partitioned table has no index of its own; each partition has its own copy. That has a consequence for uniqueness:
PostgreSQL can only enforce a `UNIQUE` constraint or primary key if it **includes the partition key**, because then
each value can only be in one partition, and each partition's own index is enough to check it.

```sql
ALTER TABLE order_events ADD PRIMARY KEY (order_id, event);
ALTER TABLE order_events ADD PRIMARY KEY (order_id, event, created_at);
```

```text
ERROR:  unique constraint on partitioned table must include all partitioning columns
DETAIL:  PRIMARY KEY constraint on table "order_events" lacks column "created_at" which is part of the partition key.
ALTER TABLE
```

That's why `orders` itself would be awkward to partition by `created_at`: its primary key is `id`, and `order_items`
and other tables reference that key. Partitioning it would mean a key of `(id, created_at)` and foreign keys that
include `created_at` too. Tables that are referenced by others are usually poor candidates; tables at the edge of the
schema, like events, logs and history, are ideal.

Foreign keys *from* a partitioned table to other tables work normally, and since PostgreSQL 12 a partitioned table can
also be referenced by a foreign key, as long as the referenced columns include the partition key.

### Partition maintenance

For time-based partitions, the routine is: create next month's (or year's) partition before it's needed, and remove
the oldest when it's no longer needed.

**Creating partitions ahead** is usually done by a scheduled job, or by the `pg_partman` extension where it's
available. A function that creates the partition for a given year, if it doesn't exist yet:

```sql
CREATE FUNCTION create_order_events_partition(p_year integer)
RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
    EXECUTE format(
        'CREATE TABLE IF NOT EXISTS %I PARTITION OF order_events FOR VALUES FROM (%L) TO (%L)',
        'order_events_' || p_year,
        make_date(p_year, 1, 1),
        make_date(p_year + 1, 1, 1));
END;
$$;

SELECT create_order_events_partition(2027);
SELECT create_order_events_partition(2027);
```

```text
CREATE FUNCTION
 create_order_events_partition
-------------------------------

(1 row)

NOTICE:  relation "order_events_2027" already exists, skipping
 create_order_events_partition
-------------------------------

(1 row)
```

**Removing old data** is where partitioning shines. Instead of a `DELETE` that touches millions of rows, you detach the
partition (it becomes an ordinary table), and then archive it, or simply drop it:

```sql
ALTER TABLE order_events DETACH PARTITION order_events_2023 CONCURRENTLY;
ALTER TABLE order_events DETACH PARTITION order_events_2023;
SELECT count(*) FROM order_events WHERE created_at < '2024-01-01';
SELECT count(*) FROM order_events_2023;
DROP TABLE order_events_2023;
```

```text
ERROR:  cannot detach partitions concurrently when a default partition exists
ALTER TABLE
 count
-------
     1
(1 row)

 count
--------
 655211
(1 row)

DROP TABLE
```

`DETACH PARTITION ... CONCURRENTLY` (14+) detaches a partition without blocking queries on the parent table, but it has
restrictions: it can't run inside a transaction block, and it refuses to work when the table has a default partition,
as here. The plain form takes an `ACCESS EXCLUSIVE` lock on the parent table, but only for a moment, since it doesn't
need to scan anything. The detached table kept its 655,211 rows, while the partitioned table no longer contains them
(the one row still matching the filter is the 1999 test row in the default partition). Dropping a table is nearly
instant, generates almost no WAL and leaves nothing for `VACUUM` to do.

**Attaching an existing table** works the other way round, for example to bring archived data back, or to load a big
batch into a separate table first and then add it in one step. `ATTACH PARTITION` checks that every row fits the
partition's bounds, which means a full scan under lock, unless the table already has a `CHECK` constraint that proves
it:

```sql
CREATE TABLE order_events_2022 (LIKE order_events INCLUDING DEFAULTS);
INSERT INTO order_events_2022 (order_id, event, created_at)
VALUES (1, 'imported', '2022-06-01 12:00');
ALTER TABLE order_events_2022
    ADD CONSTRAINT order_events_2022_bounds
    CHECK (created_at >= '2022-01-01' AND created_at < '2023-01-01');

ALTER TABLE order_events ATTACH PARTITION order_events_2022
    FOR VALUES FROM ('2022-01-01') TO ('2023-01-01');
SELECT tableoid::regclass, order_id, event FROM order_events WHERE created_at < '2023-01-01';
```

```text
CREATE TABLE
INSERT 0 1
ALTER TABLE
ALTER TABLE
       tableoid       | order_id |  event
----------------------+----------+----------
 order_events_2022    |        1 | imported
 order_events_default |        1 | created
(2 rows)
```

The attach succeeded (the 1999 row is the one in the default partition), and the parent's index on `order_id` was
created on the new partition automatically. With the
`CHECK` constraint in place, PostgreSQL skipped the validation scan, which on a big table is the difference between a
moment and many minutes of an exclusive lock. (Once attached, the constraint is redundant and can be dropped.)

### When to partition

Partitioning has real costs: more objects to manage, more planning work, uniqueness only per partition key, and
queries that don't filter on the key get slower. A good rule is **don't partition until a table is large** (tens of
gigabytes or hundreds of millions of rows) **and you have a concrete reason**:

- Old data must be removed or archived regularly: drop partitions instead of deleting rows.
- Most queries filter on the same key (usually time), so pruning skips most of the data.
- Maintenance on the whole table has become a problem: `VACUUM`, index rebuilds or bulk loads work per partition.

A table that doesn't match these is better served by good indexes (chapter 15), and maybe by BRIN indexes for data
that arrives in time order.

> **Just a taste — you don't need to learn or fully understand this now.** Partitions can themselves be partitioned
> (sub-partitioning), for example by month and then by hash of a customer id, and two tables partitioned the same way
> can be joined partition by partition with `enable_partitionwise_join`. Both are for very large systems.

```sql
CREATE TABLE measurements (device_id integer, taken_at timestamptz, value numeric)
    PARTITION BY RANGE (taken_at);
CREATE TABLE measurements_2026 PARTITION OF measurements
    FOR VALUES FROM ('2026-01-01') TO ('2027-01-01')
    PARTITION BY HASH (device_id);
CREATE TABLE measurements_2026_h0 PARTITION OF measurements_2026 FOR VALUES WITH (MODULUS 2, REMAINDER 0);
CREATE TABLE measurements_2026_h1 PARTITION OF measurements_2026 FOR VALUES WITH (MODULUS 2, REMAINDER 1);
DROP TABLE measurements;
```

```text
CREATE TABLE
CREATE TABLE
CREATE TABLE
CREATE TABLE
DROP TABLE
```

## Key takeaways

- A view is a named query. The planner merges it into each query that uses it, so it costs nothing by itself and
  filters on it are pushed down.
- `CREATE OR REPLACE VIEW` can only add columns at the end; views block changes to the columns they use; `SELECT *` is
  frozen when the view is created.
- Simple single-table views are updatable; `WITH CHECK OPTION` keeps writes inside the view's filter. Other views need
  `INSTEAD OF` triggers.
- A materialized view stores a query's result, can be indexed, and must be refreshed. `REFRESH ... CONCURRENTLY`
  keeps it readable during the refresh but needs a unique index.
- Partitioning splits a table by range, list or hash of a partition key. Unique constraints must include the key, so
  partition tables at the edge of the schema, not ones that other tables reference.
- Pruning skips partitions that can't match, at planning time or at execution time. Queries that don't filter on the
  key visit every partition.
- Create partitions ahead of time, keep the default partition empty, and remove old data with `DETACH` and `DROP`.
  Attach prepared tables with a matching `CHECK` constraint to avoid a validation scan.
- Partition only large tables with a concrete reason: retention, pruning, or per-partition maintenance.

## Exercises

The exercises continue on the bulk data, in the state the chapter left it.

1. **A customer overview.** Create a view `customer_stats` with one row per customer: id, name, country, number of
   orders, number of delivered orders, and the date of the last order. Query it for customer 4242, and check with
   `EXPLAIN` that only that customer's orders are read.

<details>
<summary>Solution</summary>

```sql
CREATE VIEW customer_stats AS
SELECT c.id, c.full_name, c.country,
       count(o.id) AS orders,
       count(o.id) FILTER (WHERE o.status = 'delivered') AS delivered,
       max(o.created_at)::date AS last_order
FROM customers AS c
LEFT JOIN orders AS o ON o.customer_id = c.id
GROUP BY c.id;

SELECT * FROM customer_stats WHERE id = 4242;
EXPLAIN (COSTS OFF) SELECT * FROM customer_stats WHERE id = 4242;
```

```text
CREATE VIEW
  id  | full_name  | country | orders | delivered | last_order
------+------------+---------+--------+-----------+------------
 4242 | Mei Nguyen | DE      |     29 |        29 | 2025-12-12
(1 row)

                          QUERY PLAN
---------------------------------------------------------------
 GroupAggregate
   ->  Nested Loop Left Join
         ->  Index Scan using customers_pkey on customers c
               Index Cond: (id = 4242)
         ->  Bitmap Heap Scan on orders o
               Recheck Cond: (customer_id = 4242)
               ->  Bitmap Index Scan on orders_customer_id_idx
                     Index Cond: (customer_id = 4242)
(8 rows)
```

The filter `id = 4242` was pushed into the view: the plan fetches one customer by its primary key and that customer's
orders through `orders_customer_id_idx` (as a bitmap scan, since there are a few dozen). The view has a `LEFT JOIN`, so
customers without orders appear with zeros, and `GROUP BY c.id` is enough because `id` is the primary key.

</details>

2. **A guarded view for discount staff.** Create a view that lets staff see and change the prices of active products
   priced under 10.00, without being able to raise a price to 10.00 or more or to touch other products. Show one
   allowed and one rejected update.

<details>
<summary>Solution</summary>

```sql
CREATE VIEW cheap_products AS
SELECT id, name, price
FROM products
WHERE active AND price < 10.00
WITH CHECK OPTION;

UPDATE cheap_products SET price = 2.25 WHERE id = 3 RETURNING id, name, price;
UPDATE cheap_products SET price = 12.00 WHERE id = 3;
UPDATE cheap_products SET price = 1.00 WHERE id = 1;
```

```text
CREATE VIEW
 id |        name        | price
----+--------------------+-------
  3 | Everyday Ballpoint |  2.25
(1 row)

UPDATE 1
ERROR:  new row violates check option for view "cheap_products"
DETAIL:  Failing row contains (3, BP-001, Everyday Ballpoint, A dependable disposable ballpoint pen with blue ink., 4, 12.00, 400, {"color": "blue", "refillable": false}, {}, t, 2025-12-01 09:00:00+00).
UPDATE 0
```

Lowering the ballpoint's price is fine. Raising it to 12.00 would move it out of the view, so `WITH CHECK OPTION`
rejects it. The update of the Kestrel pen (48.00) matches no row of the view, so nothing happens: `UPDATE 0`. Because
the view doesn't show `active`, staff can't reactivate products through it either. To stop staff from bypassing the
view, they would get privileges on the view but not on the table (chapter 21).

</details>

3. **Daily revenue.** Create a materialized view `daily_revenue` (day, number of orders, revenue) over delivered orders,
   with the index needed for concurrent refreshes. Then add a delivered order dated 2025-12-31 and show the revenue for
   that day before and after `REFRESH ... CONCURRENTLY`.

<details>
<summary>Solution</summary>

```sql
CREATE MATERIALIZED VIEW daily_revenue AS
SELECT o.created_at::date AS day,
       count(DISTINCT o.id) AS orders,
       sum(oi.quantity * oi.unit_price) AS revenue
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status = 'delivered'
GROUP BY 1;

CREATE UNIQUE INDEX daily_revenue_day ON daily_revenue (day);

SELECT * FROM daily_revenue WHERE day = '2025-12-31';

INSERT INTO orders (customer_id, status, created_at, shipped_at)
VALUES (7, 'delivered', '2025-12-31 18:00', '2025-12-31 20:00');
INSERT INTO order_items (order_id, product_id, quantity, unit_price)
VALUES (currval('orders_id_seq'), 1, 1, 48.00);

REFRESH MATERIALIZED VIEW CONCURRENTLY daily_revenue;
SELECT * FROM daily_revenue WHERE day = '2025-12-31';
```

```text
SELECT 1101
CREATE INDEX
 day | orders | revenue
-----+--------+---------
(0 rows)

INSERT 0 1
INSERT 0 1
REFRESH MATERIALIZED VIEW
    day     | orders | revenue
------------+--------+---------
 2025-12-31 |      1 |   48.00
(1 row)
```

There were no delivered orders on that day until the new one, which appears after the refresh. `created_at::date`
converts to a date in the session's time zone (UTC for Inkwell); a report for a shop in another zone would use
`(created_at AT TIME ZONE 'Europe/Berlin')::date` (chapter 11).

</details>

4. **Did you mean, materialized.** Chapter 13 suggested keeping the search vocabulary in a materialized view with a
   trigram index, instead of running `ts_stat` on every request. Build it from the product names and descriptions, and
   use it to suggest a correction for the misspelled `notebok`.

<details>
<summary>Solution</summary>

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE MATERIALIZED VIEW search_vocabulary AS
SELECT word, ndoc
FROM ts_stat($$SELECT to_tsvector('simple', name || ' ' || description) FROM public.products WHERE active$$);

CREATE UNIQUE INDEX search_vocabulary_word ON search_vocabulary (word);
CREATE INDEX search_vocabulary_trgm ON search_vocabulary USING gin (word gin_trgm_ops);

SELECT word, ndoc, round(similarity(word, 'notebok')::numeric, 2) AS similarity
FROM search_vocabulary
WHERE word % 'notebok'
ORDER BY similarity DESC, ndoc DESC
LIMIT 3;
```

```text
CREATE EXTENSION
SELECT 99
CREATE INDEX
CREATE INDEX
   word    | ndoc | similarity
-----------+------+------------
 notebook  |    3 |       0.70
 notebooks |    1 |       0.50
 notes     |    1 |       0.40
(3 rows)
```

Note `public.products` in the query string. Since PostgreSQL 17, `CREATE MATERIALIZED VIEW` and `REFRESH` run with a
restricted `search_path` (`pg_catalog, pg_temp`), so that a malicious object can't hijack a maintenance operation.
Table names in the view's own query are resolved when the view is created, but the text passed to `ts_stat` is only
parsed when it runs, so it must name the schema. The `'simple'` configuration keeps whole, unstemmed words, which is
what you want to suggest to a person. The unique index allows concurrent refreshes, the trigram index makes the `%`
search fast, and a nightly refresh keeps the vocabulary current enough.

</details>

5. **Monthly partitions.** Create a new partitioned table `page_views (product_id bigint, viewed_at timestamptz)`
   partitioned by month, and write a `DO` block that creates the partitions for the 12 months of 2026. Insert one view
   on 2026-03-15 and check which partition it went to, and that a query for March prunes the other eleven.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE page_views (
    product_id  bigint NOT NULL,
    viewed_at   timestamptz NOT NULL
) PARTITION BY RANGE (viewed_at);

DO $$
DECLARE
    m date;
BEGIN
    FOR m IN SELECT generate_series(date '2026-01-01', date '2026-12-01', interval '1 month')::date LOOP
        EXECUTE format('CREATE TABLE %I PARTITION OF page_views FOR VALUES FROM (%L) TO (%L)',
                       'page_views_' || to_char(m, 'YYYY_MM'), m, (m + interval '1 month')::date);
    END LOOP;
END;
$$;

INSERT INTO page_views VALUES (7, '2026-03-15 12:00');
SELECT tableoid::regclass, * FROM page_views;
EXPLAIN (COSTS OFF)
SELECT count(*) FROM page_views WHERE viewed_at >= '2026-03-01' AND viewed_at < '2026-04-01';
```

```text
CREATE TABLE
DO
INSERT 0 1
      tableoid      | product_id |       viewed_at
--------------------+------------+------------------------
 page_views_2026_03 |          7 | 2026-03-15 12:00:00+00
(1 row)

                                                                        QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------------------------
 Aggregate
   ->  Seq Scan on page_views_2026_03 page_views
         Filter: ((viewed_at >= '2026-03-01 00:00:00+00'::timestamp with time zone) AND (viewed_at < '2026-04-01 00:00:00+00'::timestamp with time zone))
(3 rows)
```

`DO` runs an anonymous PL/pgSQL block once, without creating a function, which is handy in migrations. The bounds are
dates, interpreted as midnight in the session's time zone; if your users are in another zone, decide which midnight a
"month" starts at, and use `timestamptz` literals with an explicit offset.

</details>

6. **Why not partition orders?** Try to turn the idea "partition `orders` by `created_at`" into a table definition:
   create a partitioned copy of `orders` with the same primary key, and read the error. Then explain what it would take
   to make the copy work with `order_items`.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE orders_partitioned (
    id           bigint NOT NULL,
    customer_id  bigint NOT NULL REFERENCES customers (id),
    status       order_status NOT NULL DEFAULT 'pending',
    created_at   timestamptz NOT NULL DEFAULT now(),
    shipped_at   timestamptz,
    PRIMARY KEY (id)
) PARTITION BY RANGE (created_at);
```

```text
ERROR:  unique constraint on partitioned table must include all partitioning columns
DETAIL:  PRIMARY KEY constraint on table "orders_partitioned" lacks column "created_at" which is part of the partition key.
```

A primary key on `id` alone can't be enforced across partitions. The key would have to be `(id, created_at)`, and then
`order_items` (and `reviews`, `invoices` and anything else pointing at orders) would need a `created_at` column of its
own to reference it with a two-column foreign key, which every insert would have to fill in correctly. Alternatively,
you give up the foreign keys. Both are big changes for a table at the centre of the schema; that's the cost to weigh
against the benefits. For Inkwell's `orders`, a good index on `created_at` (or a BRIN index) gives most of the
benefit, while history tables like `order_events` take the partitioning.

</details>

---
← Previous: [Functions, procedures and triggers](19-functions-procedures-and-triggers.md) | Next: [Roles, privileges and row-level security](21-roles-privileges-and-row-level-security.md) →
