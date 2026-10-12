# Chapter 16 — Query planning and EXPLAIN

## What you'll learn

- What happens between sending a query and getting rows back: parser, rewriter, planner and executor
- How to read `EXPLAIN` output: plan trees, costs, estimated rows and width
- How costs are computed, and what the cost settings mean
- `EXPLAIN ANALYZE`: actual times, rows, loops and buffers, and how to run it safely on data-changing statements
- Scan methods, join methods (nested loop, hash, merge) and the other common plan nodes
- Where the planner's estimates come from (`pg_stats`) and how to fix them when they're wrong, including extended
  statistics
- Sorts that spill to disk, `work_mem`, parallel query and JIT
- Finding the queries worth optimising with `pg_stat_statements`, and a method for fixing them

Chapter 15 showed that the right index can make a query hundreds of times faster, and that PostgreSQL decides for
itself whether to use one. This chapter opens up that decision. Once you can read a query plan, performance problems
stop being mysterious: the plan tells you what PostgreSQL did, what it expected, and where the two differ.

## Setting up

This chapter uses the bulk data from chapter 15. Load it fresh, so that the only indexes are the ones from the schema:

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
psql -X -v ON_ERROR_STOP=1 -d inkwell -f sql/bulk.sql
```

Then, in psql, re-create the date index from chapter 15 and simplify the plans for now by turning off parallel query
and JIT compilation (both are explained near the end of the chapter):

```sql
CREATE INDEX orders_created_at_idx ON orders (created_at);
SET max_parallel_workers_per_gather = 0;
SET jit = off;
```

```text
CREATE INDEX
SET
SET
```

The numbers in your plans will differ slightly from the ones printed here. Costs depend on statistics that
PostgreSQL gathers from a random sample of each table, and times depend on your machine. The *shapes* of the plans and
the actual row counts should match.

## From SQL to rows

Every query goes through four stages:

```text
 SQL text ──► parser ──► rewriter ──► planner ──► executor ──► rows
              syntax      views,       chooses      runs the
              check,      rules        the plan     plan tree
              names
```

1. The **parser** checks the syntax and resolves names: which table is `orders`, which function is `lower`, what
   types everything has.
2. The **rewriter** expands views into their definitions (chapter 20) and applies row-level security policies
   (chapter 21).
3. The **planner** (or *optimizer*) considers the ways the query could be executed (which indexes, which join order,
   which join method) estimates the cost of each, and picks the cheapest.
4. The **executor** runs the chosen plan, a tree of nodes. Each node asks its children for rows one at a time,
   processes them, and passes results up to its parent; the root node delivers the rows to you.

SQL says *what* you want; the planner decides *how*. That is why the same query can be fast one day and slow the
next, after the data or the statistics changed, and why `EXPLAIN` is the tool for understanding performance.

## Reading EXPLAIN

`EXPLAIN` shows the plan the planner chose, without running the query:

```sql
EXPLAIN SELECT * FROM orders;
```

```text
                           QUERY PLAN
-----------------------------------------------------------------
 Seq Scan on orders  (cost=0.00..18332.12 rows=1000012 width=36)
(1 row)
```

One node: a sequential scan of `orders`. The numbers in parentheses are estimates:

- `cost=0.00..18332.12`: the **startup cost** (before the first row can be returned) and the **total cost** (to
  return all rows). The unit is arbitrary: by definition, reading one page sequentially costs 1.0.
- `rows=1000012`: how many rows the node is expected to return.
- `width=36`: the average row size in bytes.

A sequential scan can return its first row immediately (startup cost 0); a sort can't return anything until it has
read all its input, so its startup cost is almost its total. The difference matters with `LIMIT`, which stops early.

### Where costs come from

The planner's cost model is a set of settings. The main ones:

| Setting | Default | Cost of |
|---|---|---|
| `seq_page_cost` | 1.0 | reading a page as part of a sequential scan |
| `random_page_cost` | 4.0 | reading a page at a random position (an index lookup) |
| `cpu_tuple_cost` | 0.01 | processing one row |
| `cpu_index_tuple_cost` | 0.005 | processing one index entry |
| `cpu_operator_cost` | 0.0025 | evaluating one operator or function call |

A sequential scan reads every page and processes every row, and the planner knows the table's size from `pg_class`:

```sql
SELECT relpages, reltuples,
       relpages * current_setting('seq_page_cost')::float
         + reltuples * current_setting('cpu_tuple_cost')::float AS seq_scan_cost
FROM pg_class
WHERE relname = 'orders';
```

```text
 relpages |  reltuples   |   seq_scan_cost
----------+--------------+--------------------
     8332 | 1.000012e+06 | 18332.120000000003
(1 row)
```

8332 pages at 1.0 plus a million rows at 0.01 is exactly the 18332.12 in the plan. A `WHERE` clause adds
`cpu_operator_cost` per row and per operator. `relpages` and `reltuples` are updated by `VACUUM`, `ANALYZE` and
`CREATE INDEX`; they are estimates too.

`random_page_cost = 4` assumes spinning disks, where random reads are much slower than sequential ones. On SSDs the
difference is small, and many installations lower `random_page_cost` to around 1.1, which makes the planner more
willing to use indexes. Change cost settings only to describe your hardware, never to force a particular plan.

### A plan with several nodes

```sql
EXPLAIN
SELECT c.country, count(*) AS orders
FROM customers AS c
JOIN orders AS o ON o.customer_id = c.id
GROUP BY c.country;
```

```text
                                      QUERY PLAN
--------------------------------------------------------------------------------------
 HashAggregate  (cost=29280.50..29280.64 rows=14 width=11)
   Group Key: c.country
   ->  Hash Join  (cost=3323.18..24280.44 rows=1000012 width=3)
         Hash Cond: (o.customer_id = c.id)
         ->  Seq Scan on orders o  (cost=0.00..18332.12 rows=1000012 width=8)
         ->  Hash  (cost=2073.08..2073.08 rows=100008 width=11)
               ->  Seq Scan on customers c  (cost=0.00..2073.08 rows=100008 width=11)
(7 rows)
```

Read a plan from the innermost (most indented) nodes outwards: data flows upwards. Here PostgreSQL scans `customers`
and builds a hash table from it (`Hash`), scans `orders` and looks each order's customer up in the hash table
(`Hash Join`), and finally counts per country in another hash table (`HashAggregate`). Each node's cost includes the
cost of its children. The estimate of 14 groups comes from the statistics on `customers.country`.

## EXPLAIN ANALYZE: what really happened

`EXPLAIN ANALYZE` **runs** the query and reports, for every node, what actually happened next to what was estimated:

```sql
EXPLAIN ANALYZE
SELECT o.id, c.full_name
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
WHERE o.created_at >= '2025-06-01' AND o.created_at < '2025-06-01 06:00';
```

```text
                                                                           QUERY PLAN
----------------------------------------------------------------------------------------------------------------------------------------------------------------
 Nested Loop  (cost=0.72..1501.37 rows=218 width=19) (actual time=0.031..0.801 rows=228.00 loops=1)
   Buffers: shared hit=687 read=4
   ->  Index Scan using orders_created_at_idx on orders o  (cost=0.42..13.79 rows=218 width=16) (actual time=0.022..0.079 rows=228.00 loops=1)
         Index Cond: ((created_at >= '2025-06-01 00:00:00+00'::timestamp with time zone) AND (created_at < '2025-06-01 06:00:00+00'::timestamp with time zone))
         Index Searches: 1
         Buffers: shared hit=3 read=4
   ->  Index Scan using customers_pkey on customers c  (cost=0.29..6.82 rows=1 width=19) (actual time=0.003..0.003 rows=1.00 loops=228)
         Index Cond: (id = o.customer_id)
         Index Searches: 228
         Buffers: shared hit=684
 Planning:
   Buffers: shared hit=20
 Planning Time: 0.113 ms
 Execution Time: 0.820 ms
(14 rows)
```

The new parts:

- `actual time=X..Y`: milliseconds until the node produced its first row and its last row.
- `rows=...`: rows actually produced, **per loop**. PostgreSQL 18 shows a fraction, because it is an average over
  loops.
- `loops=...`: how many times the node ran. The inner side of a nested loop runs once per outer row, so its time and
  rows are per loop: multiply by `loops` for the total.
- `Buffers: shared hit=... read=...`: pages found in PostgreSQL's cache (`hit`) and pages read from the operating
  system (`read`). Since PostgreSQL 18, `EXPLAIN ANALYZE` includes buffer counts by default. Pages are the real unit
  of work, and unlike times, they don't depend on how busy the machine is.
- `Planning Time` and `Execution Time` for the whole statement.

This plan is a **nested loop**: for each order found through the date index, look up its customer through the primary
key index (`Index Scan using customers_pkey`, with `loops` equal to the number of orders). That is efficient because
there are few outer rows and each inner lookup is cheap. Widen the range to a whole day and the planner switches to a
hash join, which reads all customers once instead of doing a thousand separate lookups; somewhere in between, the two
plans cost the same.

`EXPLAIN ANALYZE` really executes the statement. For `INSERT`, `UPDATE` and `DELETE`, wrap it in a transaction and
roll back:

```sql
BEGIN;
EXPLAIN (ANALYZE, COSTS OFF)
UPDATE products SET stock = stock - 1 WHERE id = 3;
ROLLBACK;
```

```text
BEGIN
                               QUERY PLAN
-------------------------------------------------------------------------
 Update on products (actual time=0.052..0.052 rows=0.00 loops=1)
   Buffers: shared hit=5
   ->  Seq Scan on products (actual time=0.006..0.007 rows=1.00 loops=1)
         Filter: (id = 3)
         Rows Removed by Filter: 13
         Buffers: shared hit=1
 Planning:
   Buffers: shared hit=8
 Planning Time: 0.084 ms
 Execution Time: 0.098 ms
(10 rows)

ROLLBACK
```

### Useful options

| Option | Adds |
|---|---|
| `ANALYZE` | actual times, rows, loops (and, since 18, buffers) |
| `BUFFERS` | buffer usage (the default with `ANALYZE` since 18; `BUFFERS OFF` hides it) |
| `VERBOSE` | output column lists, schema-qualified names, per-worker details |
| `SETTINGS` | settings that differ from the defaults and affect planning |
| `WAL` | WAL records and bytes generated by data changes |
| `SERIALIZE` (17+) | the cost of converting the result to the text sent to the client |
| `MEMORY` (17+) | memory used by the planner |
| `COSTS OFF`, `TIMING OFF`, `SUMMARY OFF` | hide estimates, per-node timing, or the planning/execution totals |
| `FORMAT JSON` (or `YAML`, `XML`) | machine-readable output, for visualisers |

For big plans, paste the text (or JSON) output into a visualiser such as explain.depesz.com or explain.dalibo.com,
which highlights the slow nodes. Per-node timing has some overhead on very fast nodes; `TIMING OFF` removes it when you
only need row counts.

## Scan methods

| Node | Reads |
|---|---|
| `Seq Scan` | the whole table, page by page |
| `Index Scan` | index entries in order, then each row from the table |
| `Index Only Scan` | the index alone (pages marked all-visible need no table visit) |
| `Bitmap Index Scan` + `Bitmap Heap Scan` | all matching locations from one or more indexes, then the table pages in physical order |
| `Tid Scan`/`Tid Range Scan` | rows by physical location (`ctid`) |
| `Function Scan`, `Values Scan`, `CTE Scan`, `Subquery Scan` | the output of a function, a `VALUES` list, a materialized CTE, a subquery |

Chapter 15 showed the first four. Bitmap scans have one more trick: they can **combine indexes**. With an index on
`customer_id` and one on `created_at`, an `OR` of conditions on both columns uses both:

```sql
EXPLAIN (COSTS OFF)
SELECT id FROM orders WHERE customer_id = 90000 OR created_at >= '2025-12-31 12:00';
```

```text
                                                  QUERY PLAN
---------------------------------------------------------------------------------------------------------------
 Bitmap Heap Scan on orders
   Recheck Cond: ((customer_id = 90000) OR (created_at >= '2025-12-31 12:00:00+00'::timestamp with time zone))
   ->  BitmapOr
         ->  Bitmap Index Scan on orders_customer_id_idx
               Index Cond: (customer_id = 90000)
         ->  Bitmap Index Scan on orders_created_at_idx
               Index Cond: (created_at >= '2025-12-31 12:00:00+00'::timestamp with time zone)
(7 rows)
```

`BitmapOr` merges the two bitmaps of matching pages (and `BitmapAnd` intersects them for an `AND`). A multicolumn
index is usually faster for a combination you query often, but bitmap combining lets a few single-column indexes cover
many different queries.

## Join methods

PostgreSQL has three ways to join two inputs. The planner considers each for every join and picks by cost.

### Nested loop

For each row of the outer input, scan the inner input for matches. With an index on the inner side, each scan is a
quick lookup, so nested loops are ideal when the outer side is small, as in the `EXPLAIN ANALYZE` example above. With
no index, the inner side is scanned completely once per outer row, which is a disaster for large inputs.

### Hash join

Read the smaller input completely and build an in-memory **hash table** keyed on the join column (the `Hash` node),
then scan the larger input once and probe the hash table for each row. Hash joins are the workhorse for joining large
sets with `=`. They need memory for the hash table; if it doesn't fit in `work_mem` (times `hash_mem_multiplier`), the
join splits the work into **batches** on disk:

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF)
SELECT c.country, count(*) AS orders
FROM customers AS c
JOIN orders AS o ON o.customer_id = c.id
GROUP BY c.country;
```

```text
                                QUERY PLAN
---------------------------------------------------------------------------
 HashAggregate (actual rows=14.00 loops=1)
   Group Key: c.country
   Batches: 1  Memory Usage: 32kB
   Buffers: shared hit=3356 read=6049
   ->  Hash Join (actual rows=1000012.00 loops=1)
         Hash Cond: (o.customer_id = c.id)
         Buffers: shared hit=3356 read=6049
         ->  Seq Scan on orders o (actual rows=1000012.00 loops=1)
               Buffers: shared hit=2283 read=6049
         ->  Hash (actual rows=100008.00 loops=1)
               Buckets: 131072  Batches: 1  Memory Usage: 5712kB
               Buffers: shared hit=1073
               ->  Seq Scan on customers c (actual rows=100008.00 loops=1)
                     Buffers: shared hit=1073
 Planning:
   Buffers: shared hit=14
 Planning Time: 0.125 ms
 Execution Time: 505.941 ms
(18 rows)
```

`Buckets: 131072 Batches: 1 Memory Usage: ...` says the hash table of 100,008 customers fit in memory in one batch.

### Merge join

Sort both inputs on the join key (or read them in order from indexes) and walk them side by side, like merging two
sorted lists. Merge joins shine when both inputs are large and already sorted, for example joining two tables on
columns that both have indexes:

```sql
EXPLAIN (COSTS OFF)
SELECT o.id, sum(oi.quantity) AS units
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
GROUP BY o.id;
```

```text
                           QUERY PLAN
-----------------------------------------------------------------
 GroupAggregate
   Group Key: o.id
   ->  Merge Join
         Merge Cond: (o.id = oi.order_id)
         ->  Index Only Scan using orders_pkey on orders o
         ->  Index Scan using order_items_pkey on order_items oi
(6 rows)
```

Both primary key indexes return rows in `order_id` order, so no sort is needed, and the output is still in `o.id`
order, so the grouping can be done on the fly (`GroupAggregate`) without a hash table.

### Comparing the alternatives

The `enable_*` settings (`enable_hashjoin`, `enable_mergejoin`, `enable_nestloop`, `enable_seqscan`, ...) make the
planner avoid a method, which is a good way to see what the alternative would have cost. Never set them in
production; use them to learn and to diagnose:

```sql
SET enable_mergejoin = off;
EXPLAIN (COSTS OFF)
SELECT o.id, sum(oi.quantity) AS units
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
GROUP BY o.id;
RESET enable_mergejoin;
```

```text
SET
               QUERY PLAN
-----------------------------------------
 HashAggregate
   Group Key: o.id
   ->  Hash Join
         Hash Cond: (oi.order_id = o.id)
         ->  Seq Scan on order_items oi
         ->  Hash
               ->  Seq Scan on orders o
(7 rows)

RESET
```

Without a merge join, the planner hashes `orders` and then needs a separate hash aggregate. Run both versions with
`EXPLAIN ANALYZE` and you'll see why it preferred the merge join.

## Other common nodes

| Node | Does |
|---|---|
| `Sort` | sorts its input; `Sort Method` says whether in memory (`quicksort`, `top-N heapsort`) or on disk (`external merge`) |
| `Incremental Sort` | sorts input that is already sorted by a prefix of the keys, group by group |
| `HashAggregate` / `GroupAggregate` | `GROUP BY` with a hash table, or on sorted input |
| `Limit` | stops after n rows, and stops asking its children for more |
| `Unique` | removes adjacent duplicates (for `DISTINCT` on sorted input) |
| `WindowAgg` | computes window functions (chapter 10) |
| `Materialize` | stores its input's rows so a parent can re-read them cheaply |
| `Memoize` | caches the results of a parameterized inner scan by key, for nested loops with repeated keys |
| `Append` / `Merge Append` | concatenates inputs: `UNION ALL`, partitions (chapter 20) |
| `Gather` / `Gather Merge` | collects rows from parallel workers |
| `SubPlan`, `InitPlan` | a subquery run per row, or once |

### Sorts and work_mem

Each sort or hash table may use up to **`work_mem`** of memory (4 MB by default) before it spills to temporary files.
A sort that spills is much slower than one in memory:

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, shipped_at FROM orders ORDER BY shipped_at;
```

```text
                        QUERY PLAN
-----------------------------------------------------------
 Sort (actual rows=1000012.00 loops=1)
   Sort Key: shipped_at
   Sort Method: external merge  Disk: 25224kB
   ->  Seq Scan on orders (actual rows=1000012.00 loops=1)
(4 rows)
```

`external merge Disk: ...` means the sort didn't fit in 4 MB. Raise `work_mem` for the session and run it again:

```sql
SET work_mem = '128MB';
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, shipped_at FROM orders ORDER BY shipped_at;
RESET work_mem;
```

```text
SET
                        QUERY PLAN
-----------------------------------------------------------
 Sort (actual rows=1000012.00 loops=1)
   Sort Key: shipped_at
   Sort Method: quicksort  Memory: 55591kB
   ->  Seq Scan on orders (actual rows=1000012.00 loops=1)
(4 rows)

RESET
```

Now it sorts in memory. Be careful with `work_mem` in the server configuration: the limit applies **per sort or hash
node, per query, per connection**, so a complex query run by 100 connections can use many multiples of it. Keep the
global value modest and raise it per session or per role for reports and batch jobs (`ALTER ROLE reporting SET
work_mem = '256MB'`). Notice also that the sort in memory needed about twice as much memory as the sorted data took
on disk: rows in memory carry per-row overhead.

Sorting isn't always all-or-nothing. If the input is already sorted by the first sort key, for example because it
comes from an index, an **Incremental Sort** (13+) only sorts each group of equal leading keys:

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, customer_id, created_at FROM orders ORDER BY customer_id, created_at DESC;
```

```text
                                         QUERY PLAN
---------------------------------------------------------------------------------------------
 Incremental Sort (actual rows=1000012.00 loops=1)
   Sort Key: customer_id, created_at DESC
   Presorted Key: customer_id
   Full-sort Groups: 24800  Sort Method: quicksort  Average Memory: 27kB  Peak Memory: 27kB
   Pre-sorted Groups: 891  Sort Method: quicksort  Average Memory: 194kB  Peak Memory: 196kB
   ->  Index Scan using orders_customer_id_idx on orders (actual rows=1000012.00 loops=1)
         Index Searches: 1
(7 rows)
```

The index on `customer_id` delivers the rows grouped by customer, and only each customer's orders are sorted by date,
in small batches that easily fit in memory.

`LIMIT` changes that calculation. A query that needs only the top rows can use a cheaper sort:

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, shipped_at FROM orders ORDER BY shipped_at LIMIT 10;
```

```text
                           QUERY PLAN
-----------------------------------------------------------------
 Limit (actual rows=10.00 loops=1)
   ->  Sort (actual rows=10.00 loops=1)
         Sort Key: shipped_at
         Sort Method: top-N heapsort  Memory: 25kB
         ->  Seq Scan on orders (actual rows=1000012.00 loops=1)
(5 rows)
```

A `top-N heapsort` keeps only the best 10 rows seen so far, so it needs almost no memory however large the input is.

## Where estimates come from: statistics

The planner's choices are only as good as its **row estimates**, and those come from statistics that `ANALYZE`
collects. For each column, `ANALYZE` reads a random sample of the table (300 × `default_statistics_target` rows, so
30,000 by default) and stores a summary that you can see in the `pg_stats` view:

```sql
SELECT attname, round(null_frac::numeric, 2) AS null_frac, round(n_distinct::numeric, 2) AS n_distinct,
       most_common_vals::text::text[] AS common_values,
       round(most_common_freqs[1]::numeric, 2) AS top_freq
FROM pg_stats
WHERE tablename = 'orders' AND attname IN ('status', 'shipped_at')
ORDER BY attname;
```

```text
  attname   | null_frac | n_distinct |     common_values     | top_freq
------------+-----------+------------+-----------------------+----------
 shipped_at |      0.03 |      -0.97 |                       |
 status     |      0.00 |       2.00 | {delivered,cancelled} |     0.97
(2 rows)
```

- `null_frac`: the fraction of `NULL`s (3% of orders have no `shipped_at`: the cancelled ones and the 12 reference
  orders that haven't shipped).
- `n_distinct`: the number of distinct values; a negative number is a fraction of the row count (`-0.97` means "97% of
  the rows have distinct values", which scales as the table grows).
- `most_common_vals` and `most_common_freqs`: the most common values and their frequencies (the *MCV list*).
- `histogram_bounds` (not shown): values that divide the rest of the data into buckets of equal population, used for
  range conditions.
- `correlation`: how well the physical row order follows the column's order (chapter 15).

With these, the planner estimates **selectivity**: `status = 'cancelled'` is looked up in the MCV list (3%); a range
on `created_at` is located in the histogram; a value that isn't in the MCV list gets the remaining frequency divided by
the remaining distinct values.

**Autovacuum** runs `ANALYZE` automatically when enough of a table has changed (by default 10% of its rows plus
50). After a bulk load or a big data migration, run `ANALYZE` yourself instead of waiting, because plans made with stale
statistics can be terrible. For columns with skewed or many distinct values, a larger sample gives more MCVs and
histogram buckets: `ALTER TABLE orders ALTER COLUMN customer_id SET STATISTICS 1000`, then `ANALYZE orders`.

## When estimates go wrong

Most bad plans come from bad row estimates. In `EXPLAIN ANALYZE`, compare each node's estimated `rows` with its actual
`rows`. A difference of a few percent doesn't matter; a factor of 10 or 100 usually leads to a poor choice of join
method or join order. The common causes:

### Correlated columns

The planner assumes conditions on different columns are **independent**, and multiplies their selectivities. In
Inkwell, `status = 'cancelled'` and `shipped_at IS NULL` are anything but independent: every cancelled order is
unshipped.

```sql
EXPLAIN (ANALYZE, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM orders WHERE status = 'cancelled' AND shipped_at IS NULL;
```

```text
                                           QUERY PLAN
-------------------------------------------------------------------------------------------------
 Aggregate  (cost=20834.18..20834.19 rows=1 width=8) (actual rows=1.00 loops=1)
   ->  Seq Scan on orders  (cost=0.00..20832.15 rows=810 width=0) (actual rows=30151.00 loops=1)
         Filter: ((shipped_at IS NULL) AND (status = 'cancelled'::order_status))
         Rows Removed by Filter: 969861
(4 rows)
```

The planner multiplied 3% by 3% and expected about a thousand rows; there are 30,151. Inside a bigger query, an
underestimate like this leads to nested loops that run thirty times more often than planned. **Extended statistics**
teach the planner about relationships between columns. `CREATE STATISTICS` can collect functional dependencies,
distinct counts of column combinations, and multi-column MCV lists, and since PostgreSQL 14 it can include
expressions:

```sql
CREATE STATISTICS orders_status_unshipped ON status, (shipped_at IS NULL) FROM orders;
ANALYZE orders;

EXPLAIN (ANALYZE, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM orders WHERE status = 'cancelled' AND shipped_at IS NULL;
```

```text
CREATE STATISTICS
ANALYZE
                                            QUERY PLAN
---------------------------------------------------------------------------------------------------
 Aggregate  (cost=20908.49..20908.49 rows=1 width=8) (actual rows=1.00 loops=1)
   ->  Seq Scan on orders  (cost=0.00..20832.15 rows=30534 width=0) (actual rows=30151.00 loops=1)
         Filter: ((shipped_at IS NULL) AND (status = 'cancelled'::order_status))
         Rows Removed by Filter: 969861
(4 rows)
```

Now the estimate is right. The classic real-world cases are city and postal code, country and currency, or model and
manufacturer. Extended statistics aren't created automatically: add them where `EXPLAIN ANALYZE` shows a
misestimate on combined conditions.

### Functions and expressions

The planner has no statistics for an expression on a column, so it falls back to fixed guesses (0.5% for an
equality):

```sql
EXPLAIN SELECT * FROM customers WHERE lower(full_name) = 'ines olsen';
```

```text
                          QUERY PLAN
---------------------------------------------------------------
 Seq Scan on customers  (cost=0.00..2573.12 rows=500 width=55)
   Filter: (lower(full_name) = 'ines olsen'::text)
(2 rows)
```

A guess of 500 rows for a name that 294 customers share happens to be close; for a unique value it would be 500 times
too high, and for a common one far too low. An expression index on `lower(full_name)` makes `ANALYZE` collect
statistics for the expression too (chapter 15), and `CREATE STATISTICS ... ON (lower(full_name)) FROM customers` does
the same without an index.

### Values outside the histogram

Statistics describe the table at the last `ANALYZE`. Rows added since then, such as today's orders in a table that is
analyzed once a day, fall outside the histogram, and the planner has to guess. PostgreSQL mitigates this by looking at
the actual minimum and maximum in an index when a range condition reaches past the histogram's end, which is why
time-ordered columns with an index usually stay well estimated.

### Parameters and generic plans

A prepared statement (which most drivers use for parameterized queries, chapter 22) is planned with the actual
parameter values for its first five executions. After that, PostgreSQL may switch to a **generic plan** that ignores
the values, if it doesn't look more expensive on average. That is usually good, as it saves planning time, but for
skewed data, where one value matches 10 rows and another matches a million, a generic plan can be bad for some values.
The setting `plan_cache_mode` (`auto`, `force_custom_plan`, `force_generic_plan`) controls this, per session or per
role.

## Parallel query

For large scans, joins and aggregates, PostgreSQL can split the work among several **worker processes**. Turn it back
on:

```sql
RESET max_parallel_workers_per_gather;
EXPLAIN (COSTS OFF)
SELECT status, count(*) FROM orders GROUP BY status;
```

```text
RESET
                     QUERY PLAN
-----------------------------------------------------
 Finalize GroupAggregate
   Group Key: status
   ->  Gather Merge
         Workers Planned: 2
         ->  Sort
               Sort Key: status
               ->  Partial HashAggregate
                     Group Key: status
                     ->  Parallel Seq Scan on orders
(9 rows)
```

Read it bottom-up: each worker (plus the leader process itself) scans part of the table (`Parallel Seq Scan`) and
aggregates its share (`Partial HashAggregate`); `Gather Merge` collects the partial results in sorted order; and
`Finalize GroupAggregate` combines them. The relevant settings are `max_parallel_workers_per_gather` (2 by default),
`max_parallel_workers` and `max_worker_processes` (the server-wide limits), and `min_parallel_table_scan_size` (8 MB:
smaller tables are never scanned in parallel). Parallel query speeds up reporting queries on big tables; for the short
queries of a typical web application it makes no difference, since they never reach the thresholds.

## JIT compilation

For expensive queries, PostgreSQL can compile parts of the query, such as expressions and tuple deforming, to machine
code with LLVM (*just-in-time compilation*), if it was built with LLVM support, as the standard packages are. A plan
that used JIT shows a `JIT:` section at the end of `EXPLAIN ANALYZE`. The decision is based on the estimated cost
(`jit_above_cost`, 100,000 by default), not on the actual run time, and compilation itself takes tens to hundreds of
milliseconds. For analytical queries that run for seconds, JIT can help; for an application whose queries take a few
milliseconds but occasionally get a high cost estimate, it can make them much slower. Many production systems that
serve web traffic set `jit = off`. Check with `EXPLAIN ANALYZE` before deciding.

## Finding the queries worth optimising

Optimising a query that runs once a day for 200 ms is pointless; one that runs 50 times a second for 20 ms is worth an
afternoon. You need to know which queries take the most total time.

**`pg_stat_statements`** is the standard tool. It is an extension shipped with PostgreSQL that records statistics for
every distinct query (with constants replaced by placeholders): how often it ran, total and mean time, rows, buffer
usage and more. It must be loaded at server start, so it needs a line in `postgresql.conf` and a restart:

```text
shared_preload_libraries = 'pg_stat_statements'
```

Then create the extension in the database you want to inspect:

```sql
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
SELECT pg_stat_statements_reset() IS NOT NULL AS reset;
```

```text
CREATE EXTENSION
 reset
-------
 t
(1 row)
```

Now simulate a little application traffic. psql's `\watch` re-runs a query; `i=0` means without a pause and `c=`
sets the number of runs, and `\o /dev/null` discards the output meanwhile (`\o` alone restores it):

```sql
\o /dev/null
SELECT * FROM customers WHERE id = 4242 \watch i=0 c=200
SELECT id, created_at FROM orders WHERE customer_id = 50 ORDER BY created_at DESC LIMIT 10 \watch i=0 c=100
SELECT count(*) FROM orders WHERE status = 'cancelled' AND shipped_at IS NULL \watch i=0 c=5
SELECT c.country, sum(oi.quantity * oi.unit_price)
FROM customers AS c
JOIN orders AS o ON o.customer_id = c.id
JOIN order_items AS oi ON oi.order_id = o.id
GROUP BY c.country \watch i=0 c=2
\o
```

Then list the statements by total execution time:

```sql
SELECT calls,
       round(total_exec_time::numeric, 1) AS total_ms,
       round(mean_exec_time::numeric, 2) AS mean_ms,
       rows,
       left(regexp_replace(query, '\s+', ' ', 'g'), 50) AS query
FROM pg_stat_statements
ORDER BY total_exec_time DESC
LIMIT 5;
```

On the machine used for this tutorial, the result was (your timings will differ):

```text
 calls | total_ms | mean_ms | rows |                       query
-------+----------+---------+------+----------------------------------------------------
     2 |   1665.0 |  832.51 |   28 | SELECT c.country, sum(oi.quantity * oi.unit_price)
     5 |    116.4 |   23.29 |    5 | SELECT count(*) FROM orders WHERE status = $1 AND
   100 |     12.2 |    0.12 | 1000 | SELECT id, created_at FROM orders WHERE customer_i
   200 |      0.8 |    0.00 |  200 | SELECT * FROM customers WHERE id = $1
     1 |      0.7 |    0.73 |    1 | SELECT pg_stat_statements_reset() IS NOT NULL AS r
(5 rows)
```

The revenue report tops the list although it ran only twice, at more than 0.8 seconds a run; the customer lookup ran
200 times, but at a few microseconds each it hardly matters. `rows` is the total over all calls (100 calls × 10 rows =
1000), and even the reset call shows up, because it ran after the reset. Constants such as `'cancelled'` and `4242`
became `$1`, so all executions of the same query shape are counted together, whatever the values. Sort by
`total_exec_time` to find what costs the server most, by `mean_exec_time` to find the slowest individual queries, and
compare `shared_blks_hit` and `shared_blks_read` to find queries that read a lot from disk. Reset the statistics after
a deployment (`pg_stat_statements_reset()`) to measure the new code.

Two server settings complement it:

- **`log_min_duration_statement`** logs every statement that takes longer than the given time, with its parameters,
  for example `'500ms'`.
- The **`auto_explain`** module logs the plan of slow statements automatically, which captures the plan that actually
  ran, with the actual parameters, rather than the one you get when you try later.

> **Just a taste — you don't need to learn or fully understand this now.** auto_explain is configured in `postgresql.conf`; chapter 23 covers server configuration.

```text
session_preload_libraries = 'auto_explain'
auto_explain.log_min_duration = '500ms'
auto_explain.log_analyze = on
```

## A method for slow queries

1. **Find** the query: `pg_stat_statements`, the slow-query log, or the application's tracing.
2. **Reproduce** it with realistic parameters and run `EXPLAIN (ANALYZE, BUFFERS)`. For data-changing statements,
   inside a transaction you roll back.
3. **Find the expensive nodes**: where does the time go (remember to multiply by `loops`), and which nodes read the
   most buffers?
4. **Compare estimates with actual rows**, starting from the bottom. The first big misestimate is usually the root
   cause of a bad plan above it.
5. **Look for the classic patterns**: a `Seq Scan` on a large table that returns few rows (missing index, or a
   condition that can't use one, chapter 15); `Rows Removed by Filter` in the millions; a nested loop with a huge
   `loops` count; a sort or hash spilling to disk; an index scan with many `Heap Fetches` (needs `VACUUM`).
6. **Fix the cause**: add or change an index, rewrite the condition, update or extend statistics, raise `work_mem` for
   that workload, or change the schema. Then measure again.

## Key takeaways

- The planner turns SQL into a tree of plan nodes by estimating costs; the executor runs the tree, with rows flowing
  upwards. Read plans from the innermost nodes out.
- `cost=startup..total` is in arbitrary units based on `seq_page_cost`, `random_page_cost` and the CPU costs;
  `rows` and `width` are estimates.
- `EXPLAIN ANALYZE` runs the query and shows actual time, rows and loops (per loop), and, since 18, buffers. Wrap
  data-changing statements in `BEGIN` ... `ROLLBACK`.
- Scans: sequential, index, index-only, bitmap (which can combine indexes). Joins: nested loop (small outer side,
  indexed inner), hash join (large unsorted inputs), merge join (sorted inputs).
- `work_mem` limits each sort and hash; spilling shows as `external merge` or extra hash batches. Raise it per session
  or role, not globally.
- Estimates come from `pg_stats`. Misestimates come from correlated columns (fix with `CREATE STATISTICS`),
  expressions (expression indexes or statistics), stale statistics (`ANALYZE`) and generic plans.
- Parallel query helps big scans and aggregates; JIT helps long analytical queries and can hurt short ones.
- Use `pg_stat_statements` to find the queries that cost the most in total, then fix them one at a time with
  `EXPLAIN ANALYZE`.

## Exercises

The exercises continue in the same session, on the bulk data.

1. **Cost by hand.** Using `pg_class`, compute the estimated total cost of `SELECT * FROM customers` and check it
   against `EXPLAIN`. Then predict how the cost changes for `SELECT * FROM customers WHERE country = 'SE'` (one
   operator evaluated per row), and check.

<details>
<summary>Solution</summary>

```sql
SELECT relpages, reltuples,
       relpages * 1.0 + reltuples * 0.01 AS seq_scan,
       relpages * 1.0 + reltuples * (0.01 + 0.0025) AS with_filter
FROM pg_class
WHERE relname = 'customers';

EXPLAIN SELECT * FROM customers;
EXPLAIN SELECT * FROM customers WHERE country = 'SE';
```

```text
 relpages | reltuples | seq_scan |    with_filter
----------+-----------+----------+--------------------
     1073 |    100008 |  2073.08 | 2323.1000000000004
(1 row)

                            QUERY PLAN
------------------------------------------------------------------
 Seq Scan on customers  (cost=0.00..2073.08 rows=100008 width=55)
(1 row)

                           QUERY PLAN
----------------------------------------------------------------
 Seq Scan on customers  (cost=0.00..2323.10 rows=5037 width=55)
   Filter: (country = 'SE'::text)
(2 rows)
```

Each row now costs `cpu_tuple_cost` plus one `cpu_operator_cost` for the `=` comparison. The estimated number of
Swedish customers comes from the MCV list of `country`, so your `rows` may differ slightly.

</details>

2. **Read the plan.** Run the following with `EXPLAIN ANALYZE` and answer: which join method is used, which node
   produced the most rows, and how many times did the inner index scan run?

   ```sql
   SELECT c.full_name, o.id, o.created_at
   FROM customers AS c
   JOIN orders AS o ON o.customer_id = c.id
   WHERE c.email = 'customer42@example.net';
   ```

<details>
<summary>Solution</summary>

```sql
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT c.full_name, o.id, o.created_at
FROM customers AS c
JOIN orders AS o ON o.customer_id = c.id
WHERE c.email = 'customer42@example.net';
```

```text
                                      QUERY PLAN
--------------------------------------------------------------------------------------
 Nested Loop (actual rows=235.00 loops=1)
   ->  Index Scan using customers_email_key on customers c (actual rows=1.00 loops=1)
         Index Cond: (email = 'customer42@example.net'::text)
         Index Searches: 1
   ->  Bitmap Heap Scan on orders o (actual rows=235.00 loops=1)
         Recheck Cond: (c.id = customer_id)
         Heap Blocks: exact=234
         ->  Bitmap Index Scan on orders_customer_id_idx (actual rows=235.00 loops=1)
               Index Cond: (customer_id = c.id)
               Index Searches: 1
(10 rows)
```

A nested loop: the unique index on `email` finds exactly one customer (outer side, one row), and for that row the
bitmap scan on `orders_customer_id_idx` finds all their orders. The inner side ran once (`loops=1`) and produced the
most rows: all the orders of that customer, which is customer 50 (the bulk customers start at id 9).

</details>

3. **Spot the misestimate.** Run `EXPLAIN ANALYZE` on
   `SELECT count(*) FROM orders WHERE created_at >= '2025-12-31' AND customer_id < 1000`. Compare estimated and
   actual rows at each level. Which assumption did the planner make, and is it a problem for this query?

<details>
<summary>Solution</summary>

```sql
EXPLAIN (ANALYZE, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM orders WHERE created_at >= '2025-12-31' AND customer_id < 1000;
```

```text
                                                        QUERY PLAN
--------------------------------------------------------------------------------------------------------------------------
 Aggregate  (cost=371.50..371.51 rows=1 width=8) (actual rows=1.00 loops=1)
   ->  Index Scan using orders_created_at_idx on orders  (cost=0.42..369.26 rows=898 width=0) (actual rows=12.00 loops=1)
         Index Cond: (created_at >= '2025-12-31 00:00:00+00'::timestamp with time zone)
         Filter: (customer_id < 1000)
         Index Searches: 1
(5 rows)
```

The final count is 12, but the index scan expected hundreds of rows. Two things happened. The date condition alone
matches about 925 orders (the last day of the bulk data plus the 12 reference orders of 2026), yet the planner
expected around ten times as many: the range falls into the last bucket of the histogram, which stretches all the way
to the reference orders in April 2026, and the planner assumes rows are spread evenly within a bucket, while in fact
there is a three-month gap with almost no orders. Then it multiplied by the fraction of orders with `customer_id <
1000` (about 10%), assuming independence, which is roughly right here.

Is it a problem? Not for this query: even with a perfect estimate, an index scan on the date is the right plan. Not
every difference is a problem; what matters is whether a better estimate would have led to a different plan. Inside a
larger query, though, a tenfold overestimate could tip a join from a nested loop to a hash join.

</details>

4. **work_mem threshold.** Find (roughly) the smallest `work_mem` at which
   `SELECT id, shipped_at FROM orders ORDER BY shipped_at` sorts in memory. Try a few values with `SET work_mem` and
   `EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)`.

<details>
<summary>Solution</summary>

```sql
SET work_mem = '32MB';
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, shipped_at FROM orders ORDER BY shipped_at;
SET work_mem = '64MB';
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT id, shipped_at FROM orders ORDER BY shipped_at;
RESET work_mem;
```

```text
SET
                        QUERY PLAN
-----------------------------------------------------------
 Sort (actual rows=1000012.00 loops=1)
   Sort Key: shipped_at
   Sort Method: external merge  Disk: 25208kB
   ->  Seq Scan on orders (actual rows=1000012.00 loops=1)
(4 rows)

SET
                        QUERY PLAN
-----------------------------------------------------------
 Sort (actual rows=1000012.00 loops=1)
   Sort Key: shipped_at
   Sort Method: quicksort  Memory: 55591kB
   ->  Seq Scan on orders (actual rows=1000012.00 loops=1)
(4 rows)

RESET
```

32 MB isn't enough, 64 MB is: the in-memory sort reports about 54 MB. An in-memory sort needs noticeably more memory
than the sorted data occupies on disk, because each row is held with per-row overhead and pointers. Instead of giving
a query that much memory, an index on `shipped_at` would make the sort unnecessary, if this query mattered enough to
justify the index.

</details>

5. **Correlated columns in your own table.** Create a table of 100,000 addresses in which the postal code determines
   the city: `CREATE TABLE addresses AS SELECT g AS id, 'City ' || (g % 100) AS city, 'PC' || (g % 100) AS postcode
   FROM generate_series(1, 100000) AS g;` then `ANALYZE` it. Show the misestimate for `city = 'City 7' AND postcode =
   'PC7'`, fix it with extended statistics, and drop the table.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE addresses AS
SELECT g AS id, 'City ' || (g % 100) AS city, 'PC' || (g % 100) AS postcode
FROM generate_series(1, 100000) AS g;
ANALYZE addresses;

EXPLAIN (ANALYZE, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT * FROM addresses WHERE city = 'City 7' AND postcode = 'PC7';

CREATE STATISTICS addresses_city_postcode (dependencies) ON city, postcode FROM addresses;
ANALYZE addresses;

EXPLAIN (ANALYZE, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT * FROM addresses WHERE city = 'City 7' AND postcode = 'PC7';

DROP TABLE addresses;
```

```text
SELECT 100000
ANALYZE
                                         QUERY PLAN
--------------------------------------------------------------------------------------------
 Seq Scan on addresses  (cost=0.00..2140.00 rows=11 width=15) (actual rows=1000.00 loops=1)
   Filter: ((city = 'City 7'::text) AND (postcode = 'PC7'::text))
   Rows Removed by Filter: 99000
(3 rows)

CREATE STATISTICS
ANALYZE
                                          QUERY PLAN
----------------------------------------------------------------------------------------------
 Seq Scan on addresses  (cost=0.00..2140.00 rows=1043 width=15) (actual rows=1000.00 loops=1)
   Filter: ((city = 'City 7'::text) AND (postcode = 'PC7'::text))
   Rows Removed by Filter: 99000
(3 rows)

DROP TABLE
```

Each condition alone matches 1% of the rows, so the planner multiplied 1% by 1% and expected 10 rows instead of 1,000.
The `dependencies` statistics record that `postcode` determines `city`, and the estimate becomes right. With this table
the plan is a sequential scan either way, but in a join the hundredfold underestimate could easily pick the wrong join
method.

</details>

6. **Merge, hash or nested loop?** For the query that sums units per order (the merge join example), use the
   `enable_*` settings to force each of the three join methods in turn, run each with
   `EXPLAIN (ANALYZE, COSTS OFF, BUFFERS OFF)`, and compare the execution times. Did the planner choose the fastest?

<details>
<summary>Solution</summary>

```sql
SET max_parallel_workers_per_gather = 0;
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, BUFFERS OFF)
SELECT o.id, sum(oi.quantity) FROM orders AS o JOIN order_items AS oi ON oi.order_id = o.id GROUP BY o.id;

SET enable_mergejoin = off;
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, BUFFERS OFF)
SELECT o.id, sum(oi.quantity) FROM orders AS o JOIN order_items AS oi ON oi.order_id = o.id GROUP BY o.id;

SET enable_hashjoin = off;
EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, BUFFERS OFF)
SELECT o.id, sum(oi.quantity) FROM orders AS o JOIN order_items AS oi ON oi.order_id = o.id GROUP BY o.id;

RESET enable_mergejoin;
RESET enable_hashjoin;
RESET max_parallel_workers_per_gather;
```

```text
SET
                                            QUERY PLAN
--------------------------------------------------------------------------------------------------
 GroupAggregate (actual rows=1000012.00 loops=1)
   Group Key: o.id
   ->  Merge Join (actual rows=2498811.00 loops=1)
         Merge Cond: (o.id = oi.order_id)
         ->  Index Only Scan using orders_pkey on orders o (actual rows=1000012.00 loops=1)
               Heap Fetches: 0
               Index Searches: 1
         ->  Index Scan using order_items_pkey on order_items oi (actual rows=2498811.00 loops=1)
               Index Searches: 1
 Planning Time: 0.171 ms
 Execution Time: 806.636 ms
(11 rows)

SET
                               QUERY PLAN
-------------------------------------------------------------------------
 HashAggregate (actual rows=1000012.00 loops=1)
   Group Key: o.id
   Batches: 17  Memory Usage: 8345kB  Disk Usage: 80184kB
   ->  Hash Join (actual rows=2498811.00 loops=1)
         Hash Cond: (oi.order_id = o.id)
         ->  Seq Scan on order_items oi (actual rows=2498811.00 loops=1)
         ->  Hash (actual rows=1000012.00 loops=1)
               Buckets: 262144  Batches: 8  Memory Usage: 6935kB
               ->  Seq Scan on orders o (actual rows=1000012.00 loops=1)
 Planning Time: 0.139 ms
 Execution Time: 2067.231 ms
(11 rows)

SET
                                            QUERY PLAN
--------------------------------------------------------------------------------------------------
 GroupAggregate (actual rows=1000012.00 loops=1)
   Group Key: o.id
   ->  Nested Loop (actual rows=2498811.00 loops=1)
         ->  Index Only Scan using orders_pkey on orders o (actual rows=1000012.00 loops=1)
               Heap Fetches: 0
               Index Searches: 1
         ->  Index Scan using order_items_pkey on order_items oi (actual rows=2.50 loops=1000012)
               Index Cond: (order_id = o.id)
               Index Searches: 1000012
 Planning Time: 0.144 ms
 Execution Time: 1816.112 ms
(11 rows)

RESET
RESET
RESET
```

On this data the planner's choice, the merge join, is clearly the fastest: both inputs come sorted from their primary
keys, and the result needs no separate aggregation step. The hash join must build a hash table of a million orders,
which doesn't fit in `work_mem` and is split into batches on disk, and then aggregate in a second hash table that also
spills (`Disk Usage`). The nested loop performs a million index lookups (`loops=1000012`); they are cheap because the
outer side arrives in order and the index pages stay in the cache. Your times will differ, but the merge join should
win. (`TIMING OFF` still reports the total execution time; it only skips timing each node.)

</details>

---
← Previous: [Indexes](15-indexes.md) | Next: [Transactions and concurrency](17-transactions-and-concurrency.md) →
