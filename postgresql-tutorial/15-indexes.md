# Chapter 15 — Indexes

## What you'll learn

- Why a query without a suitable index reads the whole table, and how an index avoids it
- How a B-tree index works, and which conditions and orderings it can serve
- How the planner chooses between a sequential scan, an index scan and a bitmap scan
- Multicolumn indexes, column order, and skip scan (18+)
- Index-only scans and covering indexes (`INCLUDE`)
- Partial, expression and unique indexes, and indexes for `LIKE 'prefix%'`
- The other index types: GIN, GiST, BRIN and hash, and what each is for
- What indexes cost, how to build them without downtime, and how to find missing and unused ones

An index is a separate data structure that lets PostgreSQL find rows without reading the whole table, the way a book's
index lets you find a topic without reading every page. Indexes are the single most important tool for performance,
and also one of the easiest to misuse: the wrong index costs space and slows every write without helping any query.
This chapter teaches you to choose them deliberately.

Indexes only make sense with enough data, so this chapter starts by loading three years of order history.

## Loading the bulk data

`sql/bulk.sql` adds 100,000 customers, a million orders, about 2.5 million order items and about 46,000 reviews to the
reference data. It is pseudo-random but repeatable, so you'll get the same rows as shown here. Reset and load it from
the shell (it takes a minute or so):

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
psql -X -v ON_ERROR_STOP=1 -d inkwell -f sql/bulk.sql
```

Then, in psql connected to `inkwell`:

```sql
SELECT (SELECT count(*) FROM customers) AS customers,
       (SELECT count(*) FROM orders) AS orders,
       (SELECT count(*) FROM order_items) AS order_items,
       pg_size_pretty(pg_table_size('orders')) AS orders_size,
       pg_size_pretty(pg_table_size('order_items')) AS order_items_size;
```

```text
 customers | orders  | order_items | orders_size | order_items_size
-----------+---------+-------------+-------------+------------------
    100008 | 1000012 |     2498811 | 65 MB       | 144 MB
(1 row)
```

PostgreSQL can split a large scan across several processes (*parallel query*, chapter 16). That makes the plans in
this chapter longer without changing the ideas, so turn it off for this session:

```sql
SET max_parallel_workers_per_gather = 0;
```

```text
SET
```

Most examples use `EXPLAIN` to show *how* PostgreSQL runs a query. Chapter 16 covers it in depth; for now you need two
forms. `EXPLAIN (COSTS OFF) query` shows the plan without running the query. `EXPLAIN (ANALYZE, ...)` runs it and adds
what really happened, such as the number of rows each step produced and how many it threw away. The options
`COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF` used below hide the numbers that vary from run to run, leaving the
plan and the row counts.

## Life without an index

How many orders were placed on 1 June 2024?

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, customer_id
FROM orders
WHERE created_at >= '2024-06-01' AND created_at < '2024-06-02';
```

```text
                                                                      QUERY PLAN
------------------------------------------------------------------------------------------------------------------------------------------------------
 Seq Scan on orders (actual rows=913.00 loops=1)
   Filter: ((created_at >= '2024-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2024-06-02 00:00:00+00'::timestamp with time zone))
   Rows Removed by Filter: 999099
(3 rows)
```

A **sequential scan** (`Seq Scan`) reads every row of the table and tests the filter on each. To return 913 rows, it
examined a million and threw away 999,099 of them. That is fine for a report that runs once a night, and terrible for
a page that loads a hundred times a second.

```sql
CREATE INDEX orders_created_at_idx ON orders (created_at);

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, customer_id
FROM orders
WHERE created_at >= '2024-06-01' AND created_at < '2024-06-02';
```

```text
CREATE INDEX
                                                                        QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------------------------
 Index Scan using orders_created_at_idx on orders (actual rows=913.00 loops=1)
   Index Cond: ((created_at >= '2024-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2024-06-02 00:00:00+00'::timestamp with time zone))
   Index Searches: 1
(3 rows)
```

Now an **index scan** walks the index straight to the first matching entry, reads entries until the range ends, and
fetches only those 913 rows from the table. `Index Cond` shows the condition the index applied; `Index Searches: 1`
(a PostgreSQL 18 addition) says it descended the index once. Measured with `\timing`, the difference looks like this
on a laptop (your numbers will differ):

```text
Seq Scan:   Time: 48.540 ms
Index Scan: Time: 0.812 ms
```

Roughly sixty times faster here, and the gap grows with the table: the sequential scan's work is proportional to the
table size, the index scan's to the number of matching rows (plus a few page reads to descend the tree).

## How a B-tree works

`CREATE INDEX` builds a **B-tree** unless you ask for another type. A B-tree is a balanced tree of 8 kB pages:

```text
                         ┌──────────────────────────────┐
  root page              │  2023-09-12 │ 2024-05-20 │ ... │
                         └──────┬───────────┬───────────┘
                    ┌───────────┘           └──────────────┐
          ┌─────────▼─────────┐                  ┌─────────▼─────────┐
 internal │ 2023-01-01 │ ...  │       ...        │ 2024-05-20 │ ...  │
          └────┬──────────────┘                  └────┬──────────────┘
               ▼                                      ▼
          ┌──────────────┐   ┌──────────────┐    ┌──────────────┐
 leaves   │ key → (page, │ ⇄ │ key → (page, │ ⇄  │ key → (page, │ ⇄ ...
          │ row) sorted  │   │ row) sorted  │    │ row) sorted  │
          └──────────────┘   └──────────────┘    └──────────────┘
```

- The **leaf pages** hold every indexed value in sorted order, each with a pointer to its row in the table (a *TID*:
  page number and position within the page). Leaves are linked to their neighbours, so a range is read by walking
  sideways.
- **Internal pages** hold separator keys that say which child page covers which range of values.
- Each page holds hundreds of entries, so the tree is very shallow: a few levels cover millions of rows, and a lookup
  reads only a handful of pages.

> **Just a taste — you don't need to learn or fully understand this now.** The `pageinspect` extension shows an index's internal structure; it is a tool for curiosity and debugging.

```sql
CREATE EXTENSION IF NOT EXISTS pageinspect;
SELECT level AS levels_above_leaves FROM bt_metap('orders_pkey');
```

```text
CREATE EXTENSION
 levels_above_leaves
---------------------
                   2
(1 row)
```

A million orders, and the primary key's root is only two levels above the leaves. Every lookup by `id` reads three
index pages and one table page.

Because the entries are sorted, a B-tree serves:

- equality and ranges: `=`, `<`, `<=`, `>`, `>=`, `BETWEEN`, `IN (...)`, `= ANY(array)`, and `IS NULL`;
- **ordering**: `ORDER BY created_at` (or `DESC`) can read the index in order instead of sorting, which makes
  `ORDER BY ... LIMIT n` almost free;
- `min()` and `max()`, which read one end of the index;
- `LIKE 'prefix%'`, with a suitable operator class (below).

It does not help with conditions on a *transformed* column (`lower(email) = ...`, `created_at::date = ...`) unless
the index is on that same expression, and not with `LIKE '%middle%'`.

Ordering in action, the ten most recent orders:

```sql
EXPLAIN (COSTS OFF)
SELECT id, created_at FROM orders ORDER BY created_at DESC LIMIT 10;
```

```text
                           QUERY PLAN
-----------------------------------------------------------------
 Limit
   ->  Index Scan Backward using orders_created_at_idx on orders
(2 rows)
```

`Index Scan Backward` reads the index from the end and stops after ten entries. Without the index, PostgreSQL would
have to read and sort (or at least partially sort) a million rows.

## The planner decides

Having an index doesn't mean PostgreSQL uses it. The **planner** estimates the cost of each possible plan and picks the
cheapest (chapter 16 shows how). The main factor is **selectivity**: what fraction of the rows the query returns.

Fetching rows through an index means jumping around the table, one random page read per row in the worst case. Reading
the table sequentially is much cheaper per page. So an index wins when a query needs a small fraction of the rows, and
a sequential scan wins when it needs a large fraction. In between, a **bitmap scan** combines the two: it first
collects the locations of all matching rows from the index, sorts them by page, then reads each needed table page once,
in physical order.

```sql
EXPLAIN (COSTS OFF) SELECT * FROM orders WHERE customer_id = 42;
EXPLAIN (COSTS OFF) SELECT * FROM orders WHERE customer_id < 50000;
```

```text
                    QUERY PLAN
---------------------------------------------------
 Bitmap Heap Scan on orders
   Recheck Cond: (customer_id = 42)
   ->  Bitmap Index Scan on orders_customer_id_idx
         Index Cond: (customer_id = 42)
(4 rows)

           QUERY PLAN
---------------------------------
 Seq Scan on orders
   Filter: (customer_id < 50000)
(2 rows)
```

Customer 42 has 269 orders, spread over three years and therefore over the whole table: a bitmap scan collects their
locations from the foreign-key index and reads each of those pages once. Customers below 50,000 placed about 70%
of all orders, and for that many rows, reading the whole table front to back is cheapest.

The physical order of the rows matters too. Orders were inserted in date order, so the orders of one quarter sit
together in consecutive pages, and fetching them through the date index reads the table almost sequentially:

```sql
EXPLAIN (COSTS OFF) SELECT * FROM orders WHERE created_at >= '2025-10-01';
EXPLAIN (COSTS OFF) SELECT * FROM orders WHERE created_at >= '2024-01-01';
```

```text
                                    QUERY PLAN
----------------------------------------------------------------------------------
 Index Scan using orders_created_at_idx on orders
   Index Cond: (created_at >= '2025-10-01 00:00:00+00'::timestamp with time zone)
(2 rows)

                                  QUERY PLAN
------------------------------------------------------------------------------
 Seq Scan on orders
   Filter: (created_at >= '2024-01-01 00:00:00+00'::timestamp with time zone)
(2 rows)
```

For a quarter (about 9% of the table) the planner still prefers a plain index scan, because the rows it fetches are
neighbours; for two years, a sequential scan. The planner knows how well a column follows the physical order from its
statistics (the *correlation*, which you'll meet again with BRIN indexes below). It gets these decisions right as long
as its statistics are up to date (chapter 16), which is why you shouldn't fight it with tricks; when it chooses badly,
fix the statistics or the query.

That is also why indexing a column with only a few distinct values rarely helps. 97% of orders are `delivered`, so an
index on `status` is useless for finding delivered orders. It *is* useful for finding the rare ones, which is the job
of a partial index (below).

## Multicolumn indexes

An index can cover several columns. Its entries are sorted by the first column, then by the second within equal
values of the first, and so on, like a phone book sorted by last name, then first name. The typical query on a
customer's account page:

```sql
EXPLAIN (COSTS OFF)
SELECT id, status, created_at
FROM orders
WHERE customer_id = 90000
ORDER BY created_at DESC
LIMIT 5;
```

```text
                          QUERY PLAN
---------------------------------------------------------------
 Limit
   ->  Sort
         Sort Key: created_at DESC
         ->  Bitmap Heap Scan on orders
               Recheck Cond: (customer_id = 90000)
               ->  Bitmap Index Scan on orders_customer_id_idx
                     Index Cond: (customer_id = 90000)
(7 rows)
```

The foreign-key index finds the customer's orders, and then they must be sorted to find the newest five. For a
customer with a few orders that is cheap, but for one with hundreds it means fetching and sorting all of them to keep
five. (For very frequent customers the planner may instead walk the date index backwards and check each order's
customer until it has found five, which is fast only if that customer ordered recently.) An index on `(customer_id,
created_at)` stores each customer's orders already in date order, so the query reads exactly five entries, whoever the
customer is:

```sql
CREATE INDEX orders_customer_id_created_at_idx ON orders (customer_id, created_at);

EXPLAIN (COSTS OFF)
SELECT id, status, created_at
FROM orders
WHERE customer_id = 90000
ORDER BY created_at DESC
LIMIT 5;
```

```text
CREATE INDEX
                                 QUERY PLAN
-----------------------------------------------------------------------------
 Limit
   ->  Index Scan Backward using orders_customer_id_created_at_idx on orders
         Index Cond: (customer_id = 90000)
(3 rows)
```

The `Sort` is gone: `Index Cond` finds the customer directly, and the entries come out in date order. The rule for
column order: **columns tested with `=` first, then the column used for a range or for ordering.** An index on
`(created_at, customer_id)` would be far less useful here, because one customer's entries would be scattered across
the whole date range.

The new index also makes `orders_customer_id_idx` redundant: any query that can use an index on `customer_id` alone
can use one that *starts with* `customer_id`. Redundant indexes cost writes and memory for nothing, so drop it (the
foreign key only needs *some* index with `customer_id` first):

```sql
DROP INDEX orders_customer_id_idx;
```

```text
DROP INDEX
```

### Skip scan (18+)

Before PostgreSQL 18, a multicolumn B-tree was only useful for queries that constrained its **leading** column. Since
18, the planner can use **skip scan** when the leading column is missing from the query but has few distinct values:
it performs one quick search per distinct value of the leading column.

```sql
CREATE INDEX orders_status_created_at_idx ON orders (status, created_at);
DROP INDEX orders_created_at_idx;

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id
FROM orders
WHERE created_at >= '2024-06-01' AND created_at < '2024-06-02';
```

```text
CREATE INDEX
DROP INDEX
                                                                        QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------------------------
 Index Scan using orders_status_created_at_idx on orders (actual rows=913.00 loops=1)
   Index Cond: ((created_at >= '2024-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2024-06-02 00:00:00+00'::timestamp with time zone))
   Index Searches: 5
(3 rows)
```

The query doesn't mention `status`, yet the index on `(status, created_at)` was used: `Index Searches: 5` shows one
descent per value of the `order_status` enum. Skip scan is a welcome safety net, but it is only efficient when the
skipped column has few distinct values; an index that starts with the column you filter on is still better. Put the
single-column index back and remove the experiment:

```sql
DROP INDEX orders_status_created_at_idx;
CREATE INDEX orders_created_at_idx ON orders (created_at);
```

```text
DROP INDEX
CREATE INDEX
```

## Index-only scans and covering indexes

An index scan normally visits the table for every matching entry, to fetch the other columns. If **all** the columns a
query needs are in the index, PostgreSQL can answer from the index alone: an **index-only scan**. There is one
complication: the index doesn't know whether a row version is visible to your transaction (chapter 17). PostgreSQL
checks the table's **visibility map**, which marks pages whose rows are all visible to everyone; for such pages it can
skip the table. `VACUUM` maintains the visibility map (chapter 18), and `sql/bulk.sql` ran one at the end.

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT created_at FROM orders
WHERE created_at >= '2024-06-01' AND created_at < '2024-06-02';
```

```text
                                                                        QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------------------------
 Index Only Scan using orders_created_at_idx on orders (actual rows=913.00 loops=1)
   Index Cond: ((created_at >= '2024-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2024-06-02 00:00:00+00'::timestamp with time zone))
   Heap Fetches: 0
   Index Searches: 1
(4 rows)
```

`Heap Fetches: 0` means not a single table page was read. ("Heap" is PostgreSQL's name for a table's main storage.)

To make an index *cover* a query without changing what it's sorted by, add the extra columns with `INCLUDE`. They are
stored in the leaf entries only, so they don't affect the order and can be of types that a B-tree couldn't sort:

```sql
CREATE INDEX order_items_product_id_qty_idx ON order_items (product_id) INCLUDE (quantity);

SELECT pg_size_pretty(pg_relation_size('order_items_product_id_idx')) AS plain,
       pg_size_pretty(pg_relation_size('order_items_product_id_qty_idx')) AS covering;

DROP INDEX order_items_product_id_idx;

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT sum(quantity) FROM order_items WHERE product_id = 2;
```

```text
CREATE INDEX
 plain | covering
-------+----------
 15 MB | 75 MB
(1 row)

DROP INDEX
                                                QUERY PLAN
-----------------------------------------------------------------------------------------------------------
 Aggregate (actual rows=1.00 loops=1)
   ->  Index Only Scan using order_items_product_id_qty_idx on order_items (actual rows=206239.00 loops=1)
         Index Cond: (product_id = 2)
         Heap Fetches: 0
         Index Searches: 1
(5 rows)
```

Units sold per product now come straight from the index, without touching the 144 MB table. But look at the sizes. The
plain index on `product_id` was small because there are only twelve distinct products: since PostgreSQL 13, B-tree
**deduplication** stores a repeated key once with a list of row pointers. Every entry of the covering index carries its
own `quantity`, so it can't be deduplicated, and it is five times larger. The covering index serves everything the
plain one did, so it replaces it, but the trade is clear: covering indexes are a precise tool for a few very frequent
queries; adding columns "just in case" makes indexes big and slows writes.

## Partial indexes

A **partial index** indexes only the rows that match a `WHERE` clause. The shop's operations dashboard lists the open
orders (pending and paid). There are a handful among a million:

```sql
CREATE INDEX orders_open_idx ON orders (created_at) WHERE status IN ('pending', 'paid');

SELECT pg_size_pretty(pg_relation_size('orders_open_idx')) AS partial_index,
       pg_size_pretty(pg_relation_size('orders_created_at_idx')) AS full_index;
```

```text
CREATE INDEX
 partial_index | full_index
---------------+------------
 16 kB         | 21 MB
(1 row)
```

The partial index is two pages (a metapage and one leaf), against 21 MB for the full one, and stays tiny as history
grows. The planner uses it when the query's `WHERE` clause implies the index's:

```sql
EXPLAIN (COSTS OFF)
SELECT id, customer_id, created_at
FROM orders
WHERE status IN ('pending', 'paid')
ORDER BY created_at;
```

```text
                 QUERY PLAN
--------------------------------------------
 Index Scan using orders_open_idx on orders
(1 row)
```

The query must match the index condition in a form the planner can prove. `WHERE status = 'paid'` also works (it
implies the index condition); a condition that only your application knows to be equivalent doesn't. Partial indexes
are ideal for "the few interesting rows": unprocessed jobs, open tickets, unverified accounts, and soft-deleted tables
(`WHERE deleted_at IS NULL`, chapter 14). A partial **unique** index enforces uniqueness among some rows only, as the
coupon example in chapter 14 did.

## Expression indexes

An index can store the result of an expression instead of a column. Searching customers by name case-insensitively
can't use an index on `full_name`, because the query compares `lower(full_name)`:

```sql
CREATE INDEX customers_lower_full_name_idx ON customers (lower(full_name));

EXPLAIN (COSTS OFF)
SELECT id, full_name FROM customers WHERE lower(full_name) = 'ines olsen';
```

```text
CREATE INDEX
                         QUERY PLAN
-------------------------------------------------------------
 Bitmap Heap Scan on customers
   Recheck Cond: (lower(full_name) = 'ines olsen'::text)
   ->  Bitmap Index Scan on customers_lower_full_name_idx
         Index Cond: (lower(full_name) = 'ines olsen'::text)
(4 rows)
```

The query must use **exactly** the indexed expression. `WHERE full_name ILIKE 'ines olsen'` means the same to a human,
but not to the planner, which reads the whole table. The expression must also be **immutable**: always the same
result for the same input. That rules out anything that depends on settings, such as truncating a `timestamptz`, which
depends on the session's time zone:

```sql
CREATE INDEX ON orders (date_trunc('month', created_at));
```

```text
ERROR:  functions in index expression must be marked IMMUTABLE
```

Spell out the time zone to make it immutable: `date_trunc('month', created_at, 'UTC')` or
`(created_at AT TIME ZONE 'UTC')`. Usually, though, the better fix is to rewrite the query as a range on the plain
column, which the ordinary index already serves: `created_at >= '2024-06-01' AND created_at < '2024-07-01'` rather
than `date_trunc('month', created_at) = '2024-06-01'`.

## Unique indexes

A `UNIQUE` or `PRIMARY KEY` constraint is implemented by a unique B-tree index. You can also create one directly, which
allows options a constraint doesn't have, such as an expression or a `WHERE` clause. The common case is
case-insensitive uniqueness:

```sql
CREATE UNIQUE INDEX customers_email_lower_key ON customers (lower(email));
```

```text
CREATE INDEX
```

Inkwell already forces emails to lower case with a check, so here this index would be redundant, but on a table
without that check it is the standard way to stop `Ana@Example.com` and `ana@example.com` from both registering. Drop
it again:

```sql
DROP INDEX customers_email_lower_key;
```

```text
DROP INDEX
```

By default a unique index allows any number of `NULL`s, because `NULL` is not equal to `NULL`. Since PostgreSQL 15,
`UNIQUE NULLS NOT DISTINCT` treats them as equal, allowing at most one.

## Pattern matching with B-trees

`LIKE 'customer4242%'` looks like a range query, and in byte order it is: everything from `customer4242` up to (but not
including) `customer4243`. But Inkwell's text uses an ICU collation, in which that isn't true in general, so the
ordinary index on `email` can't be used:

```sql
EXPLAIN (COSTS OFF) SELECT id FROM customers WHERE email LIKE 'customer4242%';
```

```text
                 QUERY PLAN
--------------------------------------------
 Seq Scan on customers
   Filter: (email ~~ 'customer4242%'::text)
(2 rows)
```

An index with the `text_pattern_ops` **operator class** compares strings byte by byte, which makes prefix searches
indexable:

```sql
CREATE INDEX customers_email_pattern_idx ON customers (email text_pattern_ops);

EXPLAIN (COSTS OFF) SELECT id FROM customers WHERE email LIKE 'customer4242%';
```

```text
CREATE INDEX
                                       QUERY PLAN
----------------------------------------------------------------------------------------
 Index Scan using customers_email_pattern_idx on customers
   Index Cond: ((email ~>=~ 'customer4242'::text) AND (email ~<~ 'customer4243'::text))
   Filter: (email ~~ 'customer4242%'::text)
(3 rows)
```

The planner turned the pattern into a range (`~>=~` and `~<~` are the byte-wise comparison operators) and rechecks the
`LIKE` on each match. A `text_pattern_ops` index can't serve ordinary `<` comparisons or `ORDER BY`, so if you need
both, you need both indexes. (In a database whose collation is `C` or `C.UTF-8`, a plain index serves both, one of the
advantages of those collations from chapter 11.) For patterns with a leading wildcard, `LIKE '%olsen%'`, use a trigram
index (GIN, below).

## Other index types

B-trees handle values that sort. Other kinds of data need other structures:

| Type | Organises | Typical uses |
|---|---|---|
| **B-tree** | sorted values | equality, ranges, sorting: the default for almost everything |
| **GIN** | an inverted index: each element → the rows containing it | `jsonb` (`@>`, `?`), arrays (`@>`, `&&`), full-text search, trigrams |
| **GiST** | a balanced tree of bounding "regions" | ranges and exclusion constraints, geometry (PostGIS), `ltree`, nearest-neighbour (`<->`) |
| **SP-GiST** | space-partitioned trees | non-balanced data such as IP addresses, phone-number prefixes, quadtrees |
| **BRIN** | a summary (min/max) per block range | huge tables whose values follow the physical order, like append-only timestamps |
| **Hash** | hash of the value | equality only; rarely better than a B-tree |

### GIN

A GIN index splits each value into **elements** (the keys of a `jsonb` document, the elements of an array, the lexemes
of a `tsvector`, the trigrams of a string) and lists, for each element, the rows that contain it. That makes "which
rows contain X?" fast. Chapters 12 and 13 used GIN for `jsonb` and full-text search. With 100,000 customers, a trigram
index shows the effect on a `LIKE` with a leading wildcard:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM customers WHERE full_name ILIKE '%olsen%';
```

```text
CREATE EXTENSION
                        QUERY PLAN
-----------------------------------------------------------
 Aggregate (actual rows=1.00 loops=1)
   ->  Seq Scan on customers (actual rows=4935.00 loops=1)
         Filter: (full_name ~~* '%olsen%'::text)
         Rows Removed by Filter: 95073
(4 rows)
```

```sql
CREATE INDEX customers_full_name_trgm_idx ON customers USING gin (full_name gin_trgm_ops);

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM customers WHERE full_name ILIKE '%olsen%';
```

```text
CREATE INDEX
                                         QUERY PLAN
---------------------------------------------------------------------------------------------
 Aggregate (actual rows=1.00 loops=1)
   ->  Bitmap Heap Scan on customers (actual rows=4935.00 loops=1)
         Recheck Cond: (full_name ~~* '%olsen%'::text)
         Heap Blocks: exact=1064
         ->  Bitmap Index Scan on customers_full_name_trgm_idx (actual rows=4935.00 loops=1)
               Index Cond: (full_name ~~* '%olsen%'::text)
               Index Searches: 1
(7 rows)
```

GIN indexes are fast to search but expensive to update, because one row touches many index entries. PostgreSQL buffers
new entries in a *pending list* and merges them in bulk (the `fastupdate` storage parameter, on by default), which
makes writes cheaper at the cost of slightly slower searches until the next merge. GIN can't help with ordering, and
`jsonb_path_ops` (chapter 12) is a smaller GIN variant for containment-only queries.

### GiST

GiST (*generalized search tree*) indexes data where values can overlap or contain each other, such as ranges and
shapes. Each tree node stores a bounding region of everything below it. Chapter 14 used GiST through `WITHOUT OVERLAPS`
and exclusion constraints, and chapter 13 mentioned `gist_trgm_ops`. GiST is also how PostgreSQL answers
"nearest first" queries (`ORDER BY location <-> point LIMIT 10`) without examining every row.

### BRIN

A **BRIN** (*block range index*) stores just the minimum and maximum value for each range of table pages (128 pages by
default). It is tiny, and it works when the column's values follow the physical order of the table, as timestamps do
in an append-only table. The planner's statistics measure this as the column's **correlation**:

```sql
SELECT attname, round(correlation::numeric, 1) AS correlation
FROM pg_stats
WHERE tablename = 'orders' AND attname IN ('created_at', 'customer_id')
ORDER BY attname;
```

```text
   attname   | correlation
-------------+-------------
 created_at  |         1.0
 customer_id |         0.0
(2 rows)
```

`created_at` is almost perfectly correlated with the physical order (1.0), `customer_id` not at all. A BRIN index on
`created_at`:

```sql
CREATE INDEX orders_created_at_brin ON orders USING brin (created_at);

SELECT pg_size_pretty(pg_relation_size('orders_created_at_brin')) AS brin,
       pg_size_pretty(pg_relation_size('orders_created_at_idx')) AS btree;
```

```text
CREATE INDEX
 brin  | btree
-------+-------
 24 kB | 21 MB
(1 row)
```

Close to a thousand times smaller than the B-tree. To see it at work, hide the B-tree inside a transaction that you
roll back (`DROP INDEX` is transactional, chapter 4):

```sql
BEGIN;
DROP INDEX orders_created_at_idx;
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM orders WHERE created_at >= '2024-06-01' AND created_at < '2024-06-02';
ROLLBACK;
```

```text
BEGIN
DROP INDEX
                                                                              QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------------------------------------
 Aggregate (actual rows=1.00 loops=1)
   ->  Bitmap Heap Scan on orders (actual rows=913.00 loops=1)
         Recheck Cond: ((created_at >= '2024-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2024-06-02 00:00:00+00'::timestamp with time zone))
         Rows Removed by Index Recheck: 29813
         Heap Blocks: lossy=256
         ->  Bitmap Index Scan on orders_created_at_brin (actual rows=2560.00 loops=1)
               Index Cond: ((created_at >= '2024-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2024-06-02 00:00:00+00'::timestamp with time zone))
               Index Searches: 1
(8 rows)

ROLLBACK
```

The BRIN index can only say "matching rows may be in these block ranges". PostgreSQL reads those 256 pages (`lossy`)
and rechecks every row on them, discarding the rest (`Rows Removed by Index Recheck`). That is far more work than a
B-tree lookup, and far less than a full scan. BRIN shines for very large, append-only tables (logs, events, sensor
readings) where a B-tree would be gigabytes; on a table whose rows are updated and moved around, its ranges blur and
it stops helping. Drop it, as the B-tree serves this table better:

```sql
DROP INDEX orders_created_at_brin;
```

```text
DROP INDEX
```

### Hash

A **hash index** supports only `=`. It can be smaller than a B-tree for long values, such as URLs, but a B-tree does
everything a hash index does and more, so hash indexes are a niche choice. (They are crash-safe and replicated since
PostgreSQL 10; older advice against them is about earlier versions.)

## What indexes cost

Every index makes reads that use it faster and makes **every write** to the table slower:

- Each `INSERT` adds an entry to every index of the table. Each `DELETE` leaves entries for `VACUUM` to clean up.
- An `UPDATE` normally writes a new row version and new entries in **every** index. PostgreSQL avoids this with
  **HOT updates** (*heap-only tuples*) when no indexed column changed and the new version fits on the same page
  (chapter 18). Indexing a frequently updated column, such as a `last_seen_at`, disables HOT for those updates.
- Indexes take disk space and, more importantly, memory: the hot parts of every index compete for the cache.

The sizes on the two big tables, now:

```sql
SELECT indexrelid::regclass AS index,
       pg_size_pretty(pg_relation_size(indexrelid)) AS size
FROM pg_index
WHERE indrelid IN ('orders'::regclass, 'order_items'::regclass)
ORDER BY indrelid::regclass::text, pg_relation_size(indexrelid) DESC;
```

```text
               index               | size
-----------------------------------+-------
 order_items_product_id_qty_idx    | 75 MB
 order_items_pkey                  | 75 MB
 orders_customer_id_created_at_idx | 30 MB
 orders_pkey                       | 21 MB
 orders_created_at_idx             | 21 MB
 orders_open_idx                   | 16 kB
(6 rows)
```

The indexes on `order_items` together are about as large as the table itself (144 MB). That is normal, and it is why
each index should earn its place.

## Building indexes on a live system

`CREATE INDEX` blocks writes to the table (`INSERT`, `UPDATE`, `DELETE`) until it finishes. On a busy table that can
mean minutes of failing requests. `CREATE INDEX CONCURRENTLY` builds the index without blocking writes:

```sql
CREATE INDEX CONCURRENTLY reviews_created_at_idx ON reviews (created_at);
```

```text
CREATE INDEX
```

The price: it scans the table twice, waits for all transactions that might use the old state to finish, takes longer
overall, and can't run inside a transaction block. If it fails (for example, a unique index finds a duplicate, or the
session is cancelled), it leaves behind an **invalid** index that is maintained on every write but never used. Check
for invalid indexes, then drop and retry:

```sql
SELECT indexrelid::regclass AS index, indisvalid
FROM pg_index
WHERE indrelid = 'reviews'::regclass
ORDER BY 1;
```

```text
               index                | indisvalid
------------------------------------+------------
 reviews_pkey                       | t
 reviews_product_id_customer_id_key | t
 reviews_customer_id_idx            | t
 reviews_created_at_idx             | t
(4 rows)
```

The same applies to rebuilding: `REINDEX INDEX CONCURRENTLY name` (12+) builds a fresh copy and swaps it in without
blocking writes. That is how you fix a bloated or corrupted index, or rebuild text indexes after a collation change
(chapter 11). While a big build runs, `pg_stat_progress_create_index` shows how far it has got, and raising
`maintenance_work_mem` for the session makes builds faster.

In migrations, use `CONCURRENTLY` for every index on a table that is in use, and remember that many migration tools wrap
each migration in a transaction, which `CONCURRENTLY` doesn't allow; they usually have an option to turn that off.

## Finding missing and unused indexes

The statistics views count how tables and indexes are used since the statistics were last reset:

- `pg_stat_user_tables`: `seq_scan` and `seq_tup_read` per table. A large table with many sequential scans that read
  millions of rows each suggests a missing index.
- `pg_stat_user_indexes`: `idx_scan` per index. An index with `idx_scan = 0` after weeks of normal traffic is a
  candidate for removal (check replicas too, and indexes that back constraints must stay).

```sql
SELECT relname, seq_scan, idx_scan
FROM pg_stat_user_tables
WHERE relname IN ('orders', 'order_items', 'customers')
ORDER BY relname;
```

The counts depend on everything that has run since the statistics were reset, so your numbers will differ. The most
direct way to find missing indexes, though, is to find the slow queries first, with `pg_stat_statements` and
`EXPLAIN`, which is the subject of chapter 16.

## An indexing checklist

- Index every foreign key you join or delete through (chapter 4).
- Index for the queries you actually run, starting with the most frequent and the slowest.
- Put equality columns first in a multicolumn index, then the range or `ORDER BY` column.
- Prefer one multicolumn index that serves several queries to several single-column ones; drop indexes that another
  index's leading columns make redundant.
- Use partial indexes for "the few interesting rows", expression indexes for transformed lookups, `INCLUDE` for a few
  very hot queries.
- Write conditions that can use an index: compare bare columns with ranges rather than wrapping the column in a
  function or cast.
- Build with `CONCURRENTLY` in production; check for invalid indexes.
- Review unused and duplicate indexes now and then; every index costs writes, space and memory.

## Key takeaways

- Without a suitable index, a query reads the whole table. A B-tree index finds rows in a few page reads and serves
  `=`, ranges, `IN`, `IS NULL`, `ORDER BY ... LIMIT`, `min`/`max` and (with `text_pattern_ops` or a `C` collation)
  `LIKE 'prefix%'`.
- The planner chooses between index, bitmap and sequential scans by estimated selectivity; low-selectivity queries are
  better off without an index.
- Multicolumn indexes: equality columns first, then range/order columns. A leading prefix of an index acts as an index
  on those columns. Skip scan (18+) helps when a leading low-cardinality column is missing from the query.
- Index-only scans avoid the table when the index covers the query and the visibility map is up to date; `INCLUDE`
  adds payload columns.
- Partial indexes index only interesting rows; expression indexes must match the query's expression exactly and be
  immutable.
- GIN for containment, arrays, `jsonb`, full-text and trigrams; GiST for ranges, geometry and nearest-neighbour; BRIN
  for huge, naturally ordered tables.
- Indexes slow writes and use memory; build them `CONCURRENTLY` in production and remove the ones nobody uses.

## Exercises

These exercises continue on the bulk data, with the indexes created in this chapter.

1. **Why isn't my index used?** Each of these queries counts the orders of 1 June 2024, and none can use
   `orders_created_at_idx` efficiently. Explain why, and rewrite each one so that it can, checking with
   `EXPLAIN (COSTS OFF)`.

   ```sql
   SELECT count(*) FROM orders WHERE created_at::date = '2024-06-01';
   SELECT count(*) FROM orders WHERE to_char(created_at, 'YYYY-MM-DD') = '2024-06-01';
   SELECT count(*) FROM orders WHERE created_at + interval '1 day' > '2024-06-02' AND created_at < '2024-06-02';
   ```

<details>
<summary>Solution</summary>

All three wrap the indexed column in an expression (a cast, a function, arithmetic), so the B-tree on the bare column
doesn't apply; the index can only serve conditions of the form `created_at <op> value`. The third one keeps a usable
condition (`created_at < '2024-06-02'`), but on its own that matches 17 months of orders. The rewrite is the same for
all three, a half-open range on the bare column:

```sql
EXPLAIN (COSTS OFF)
SELECT count(*) FROM orders WHERE created_at::date = '2024-06-01';

EXPLAIN (COSTS OFF)
SELECT count(*) FROM orders WHERE created_at >= '2024-06-01' AND created_at < '2024-06-02';
```

```text
                        QUERY PLAN
-----------------------------------------------------------
 Aggregate
   ->  Seq Scan on orders
         Filter: ((created_at)::date = '2024-06-01'::date)
(3 rows)

                                                                           QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------------------------------
 Aggregate
   ->  Index Only Scan using orders_created_at_idx on orders
         Index Cond: ((created_at >= '2024-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2024-06-02 00:00:00+00'::timestamp with time zone))
(3 rows)
```

The rewritten query uses an index-only scan. (Watch the time zone: `created_at::date` takes the date in the session's
time zone, so the equivalent range must use the same zone; both are UTC here.)

</details>

2. **The open-orders dashboard.** The dashboard also shows the open orders of one customer:
   `WHERE customer_id = 1 AND status IN ('pending', 'paid')`. Check which index the planner uses now. Then explain
   whether `orders_open_idx` should rather be on `(customer_id, created_at)`, and what changes if you make it so.

<details>
<summary>Solution</summary>

```sql
EXPLAIN (COSTS OFF)
SELECT id, created_at FROM orders WHERE customer_id = 1 AND status IN ('pending', 'paid');
```

```text
                 QUERY PLAN
--------------------------------------------
 Index Scan using orders_open_idx on orders
   Filter: (customer_id = 1)
(2 rows)
```

The planner picked the tiny partial index: it holds only the open orders, so scanning all of them and filtering by
customer costs almost nothing. That stays true as long as open orders are few. An index on `(customer_id, created_at)
WHERE status IN ('pending', 'paid')` would serve the per-customer query directly, but the global dashboard query
(`ORDER BY created_at` over all open orders) could then no longer read the index in order. With few open orders, the
current index is the better general-purpose choice; a second partial index only pays off if open orders become
numerous.

</details>

3. **Case-insensitive name search.** Show that the expression index `customers_lower_full_name_idx` is used for
   `lower(full_name) = 'ines olsen'` but not for `full_name ILIKE 'ines olsen'`, and count how many customers have that
   name. Then show which index a `LIKE 'ines ol%'` on `lower(full_name)` could use, if any.

<details>
<summary>Solution</summary>

```sql
EXPLAIN (COSTS OFF) SELECT count(*) FROM customers WHERE lower(full_name) = 'ines olsen';
EXPLAIN (COSTS OFF) SELECT count(*) FROM customers WHERE full_name ILIKE 'ines olsen';
SELECT count(*) FROM customers WHERE lower(full_name) = 'ines olsen';
EXPLAIN (COSTS OFF) SELECT count(*) FROM customers WHERE lower(full_name) LIKE 'ines ol%';
```

```text
                            QUERY PLAN
-------------------------------------------------------------------
 Aggregate
   ->  Bitmap Heap Scan on customers
         Recheck Cond: (lower(full_name) = 'ines olsen'::text)
         ->  Bitmap Index Scan on customers_lower_full_name_idx
               Index Cond: (lower(full_name) = 'ines olsen'::text)
(5 rows)

                          QUERY PLAN
---------------------------------------------------------------
 Aggregate
   ->  Bitmap Heap Scan on customers
         Recheck Cond: (full_name ~~* 'ines olsen'::text)
         ->  Bitmap Index Scan on customers_full_name_trgm_idx
               Index Cond: (full_name ~~* 'ines olsen'::text)
(5 rows)

 count
-------
   294
(1 row)

                       QUERY PLAN
--------------------------------------------------------
 Aggregate
   ->  Seq Scan on customers
         Filter: (lower(full_name) ~~ 'ines ol%'::text)
(3 rows)
```

The `ILIKE` query isn't written in terms of `lower(full_name)`, so the expression index doesn't apply, but the trigram
index created earlier can serve it (trigram indexes handle `ILIKE` with or without wildcards). For the prefix `LIKE` on
`lower(full_name)`, the expression index uses the ICU collation and can't serve `LIKE`; an index on
`lower(full_name) text_pattern_ops` could. With the indexes as they are, PostgreSQL scans the table.

</details>

4. **A covering index for a report.** The finance team often runs
   `SELECT order_id, quantity * unit_price FROM order_items WHERE product_id = 2`. Find out whether the current index
   on `order_items` can answer it with an index-only scan, then replace that index with one that can, and verify
   `Heap Fetches: 0`. Compare the sizes of the old and new index.

<details>
<summary>Solution</summary>

```sql
EXPLAIN (COSTS OFF)
SELECT order_id, quantity * unit_price FROM order_items WHERE product_id = 2;

SELECT pg_size_pretty(pg_relation_size('order_items_product_id_qty_idx')) AS old_size;

CREATE INDEX order_items_product_id_cover_idx
    ON order_items (product_id) INCLUDE (order_id, quantity, unit_price);
DROP INDEX order_items_product_id_qty_idx;

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT order_id, quantity * unit_price FROM order_items WHERE product_id = 2;

SELECT pg_size_pretty(pg_relation_size('order_items_product_id_cover_idx')) AS new_size;
```

```text
                        QUERY PLAN
-----------------------------------------------------------
 Bitmap Heap Scan on order_items
   Recheck Cond: (product_id = 2)
   ->  Bitmap Index Scan on order_items_product_id_qty_idx
         Index Cond: (product_id = 2)
(4 rows)

 old_size
----------
 75 MB
(1 row)

CREATE INDEX
DROP INDEX
                                              QUERY PLAN
-------------------------------------------------------------------------------------------------------
 Index Only Scan using order_items_product_id_cover_idx on order_items (actual rows=206239.00 loops=1)
   Index Cond: (product_id = 2)
   Heap Fetches: 0
   Index Searches: 1
(4 rows)

 new_size
----------
 118 MB
(1 row)
```

The old index lacked `order_id` and `unit_price`, so every match needed a visit to the table. The covering index is
larger, the price of including more columns, and every write to `order_items` now maintains a bigger index. Whether
that is worth it depends on how often the report runs.

</details>

5. **Redundant indexes.** Write a catalog query that lists, for the `orders` and `order_items` tables, every
   non-unique, non-partial index whose columns are a leading prefix of another index on the same table. Test it by
   creating `orders_customer_id_idx` again, then drop it.

<details>
<summary>Solution</summary>

```sql
CREATE INDEX orders_customer_id_idx ON orders (customer_id);

SELECT a.indexrelid::regclass AS redundant,
       b.indexrelid::regclass AS covered_by
FROM pg_index AS a
JOIN pg_index AS b
  ON b.indrelid = a.indrelid
 AND b.indexrelid <> a.indexrelid
 AND (b.indkey::text || ' ') LIKE (a.indkey::text || ' %')
WHERE a.indrelid IN ('orders'::regclass, 'order_items'::regclass)
  AND NOT a.indisunique
  AND a.indpred IS NULL
  AND a.indexprs IS NULL
  AND b.indpred IS NULL
ORDER BY 1;

DROP INDEX orders_customer_id_idx;
```

```text
CREATE INDEX
       redundant        |            covered_by
------------------------+-----------------------------------
 orders_customer_id_idx | orders_customer_id_created_at_idx
(1 row)

DROP INDEX
```

`indkey` lists the indexed column numbers, for example `3 4` for `(customer_id, created_at)`; comparing the strings
finds prefixes. Unique indexes are excluded because they enforce a constraint, and partial or expression indexes
because they don't cover the same rows or values; and the covering index `b` must not be partial, or it wouldn't
contain all the rows. Tools such as `pg_qualstats` and the community "index advisor" queries go
further, but even this simple check finds real waste in many databases.

</details>

6. **Partial unique index.** Customers may save several shipping addresses, but at most one may be the default.
   Create `customer_addresses (id, customer_id, line1, city, country, is_default boolean)` and enforce the rule with a
   partial unique index. Show that a second default address for the same customer fails, while a second non-default
   address works.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE customer_addresses (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id bigint NOT NULL REFERENCES customers ON DELETE CASCADE,
    line1       text NOT NULL,
    city        text NOT NULL,
    country     char(2) NOT NULL,
    is_default  boolean NOT NULL DEFAULT false
);
CREATE UNIQUE INDEX customer_addresses_one_default_idx
    ON customer_addresses (customer_id) WHERE is_default;

INSERT INTO customer_addresses (customer_id, line1, city, country, is_default)
VALUES (1, 'Rua A 1', 'São Paulo', 'BR', true);
INSERT INTO customer_addresses (customer_id, line1, city, country, is_default)
VALUES (1, 'Rua B 2', 'Campinas', 'BR', false);
INSERT INTO customer_addresses (customer_id, line1, city, country, is_default)
VALUES (1, 'Rua C 3', 'Santos', 'BR', true);

DROP TABLE customer_addresses;
```

```text
CREATE TABLE
CREATE INDEX
INSERT 0 1
INSERT 0 1
ERROR:  duplicate key value violates unique constraint "customer_addresses_one_default_idx"
DETAIL:  Key (customer_id)=(1) already exists.
DROP TABLE
```

The index contains only default addresses, keyed by customer, so two defaults for one customer collide while
non-default addresses are not in the index at all. To switch the default, update the old one to `false` and the new
one to `true` in one transaction, old one first (or make the index deferrable through a constraint, which partial
indexes can't be; the order of the two updates is the simple fix). The index also serves "find this customer's default
address" directly.

</details>

---
← Previous: [Data modeling](14-data-modeling.md) | Next: [Query planning and EXPLAIN](16-query-planning-and-explain.md) →
