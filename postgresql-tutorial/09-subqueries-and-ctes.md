# Chapter 9 — Subqueries and common table expressions

## What you'll learn

- The kinds of subqueries: scalar, row, list (`IN`, `ANY`, `ALL`), `EXISTS`, and derived tables in `FROM`
- Correlated subqueries, which refer to the outer query, and what they cost
- Common table expressions (`WITH`) for writing big queries as named steps
- When a CTE is inlined and when it is materialized
- Recursive CTEs: walking the category tree to any depth, with `SEARCH` and `CYCLE`
- Data-modifying CTEs: several `INSERT`/`UPDATE`/`DELETE`s in one atomic statement

A **subquery** is a `SELECT` inside another statement. You have already met two kinds: `EXISTS` in chapter 7 and
derived tables in chapter 8. This chapter covers all of them, then shows how `WITH` turns a tangle of nested
subqueries into a readable sequence of steps. Start from the reference data (chapter 5).

## Scalar subqueries

A subquery in parentheses that returns **one column and at most one row** can be used anywhere a single value can.
Products priced above the average:

```sql
SELECT name, price
FROM products
WHERE price > (SELECT avg(price) FROM products)
ORDER BY price DESC;
```

```text
         name         | price
----------------------+--------
 Heron Fountain Pen   | 129.00
 Kestrel Fountain Pen |  48.00
 Leather Pen Case     |  35.00
(3 rows)
```

If a scalar subquery returns no rows, its value is `NULL`. If it returns more than one row, that is an error, raised
only when it happens, which can be long after the query was written:

```sql
SELECT name FROM products WHERE category_id = (SELECT id FROM categories WHERE parent_id = 2);
```

```text
ERROR:  more than one row returned by a subquery used as an expression
```

Use `IN` (below) when a subquery can return several rows.

## Correlated subqueries

A subquery that refers to columns of the outer query is **correlated**: conceptually, it is run once per outer row.
In the `SELECT` list, that gives you a per-row lookup or aggregate:

```sql
SELECT p.name,
       p.price,
       (SELECT count(*) FROM reviews AS r WHERE r.product_id = p.id) AS reviews,
       (SELECT max(o.created_at)::date
        FROM order_items AS oi JOIN orders AS o ON o.id = oi.order_id
        WHERE oi.product_id = p.id) AS last_sold
FROM products AS p
WHERE p.category_id IN (3, 6)
ORDER BY p.id;
```

```text
          name          | price  | reviews | last_sold
------------------------+--------+---------+------------
 Kestrel Fountain Pen   |  48.00 |       2 | 2026-03-28
 Heron Fountain Pen     | 129.00 |       1 | 2026-04-08
 Midnight Blue Ink 50ml |  16.00 |       1 | 2026-04-04
 Sepia Ink 50ml         |  16.00 |       1 | 2026-02-03
(4 rows)
```

In `WHERE`, a correlated subquery compares each row with its own group. Products that cost more than the average of
their own category:

```sql
SELECT p.name, p.category_id, p.price
FROM products AS p
WHERE p.price > (SELECT avg(p2.price) FROM products AS p2 WHERE p2.category_id = p.category_id)
ORDER BY p.category_id, p.name;
```

```text
          name          | category_id | price
------------------------+-------------+--------
 Heron Fountain Pen     |           3 | 129.00
 Click Ballpoint 3-pack |           4 |   6.90
 Sketching Pencil Set   |           5 |  18.50
 Dot Grid Notebook A5   |           8 |  14.00
 Leather Pen Case       |          10 |  35.00
(5 rows)
```

The same table appears twice with different aliases, `p` for the outer row and `p2` for the rows being averaged.

"Run once per outer row" is the logical meaning, and for a few hundred rows it doesn't matter. On large tables a
correlated subquery in the `SELECT` list really can execute once per row; the planner turns `EXISTS` and `IN`
subqueries into joins, but not most scalar ones. When a report over many rows is slow, rewriting per-row subqueries
as a join to a pre-aggregated derived table (chapter 8) is often the fix. Chapter 16 shows how to see the difference
in a query plan.

## List subqueries: IN, ANY and ALL

`x IN (subquery)` is true if `x` equals any value the subquery returns. `ANY` and `ALL` generalize that to any
comparison operator:

| Expression | True when |
|---|---|
| `x IN (subquery)` | `x` equals at least one value (same as `x = ANY (subquery)`) |
| `x > ANY (subquery)` | `x` is greater than at least one value |
| `x > ALL (subquery)` | `x` is greater than every value |

Products more expensive than every pen:

```sql
SELECT name, price
FROM products
WHERE price > ALL (SELECT price FROM products WHERE category_id IN (3, 4));
```

```text
 name | price
------+-------
(0 rows)
```

Nothing is more expensive than the 129.00 Heron pen. Two traps: `> ALL` of an **empty** subquery is true for every row
("greater than all of none"), and `NULL`s in the subquery make `ALL` and `NOT IN` return `NULL`, as chapter 7 showed.

`ANY` and `ALL` also work with arrays, which is how you'll usually see them: `WHERE id = ANY (ARRAY[1, 2, 3])`, or with
a parameter from an application, `WHERE id = ANY ($1)`, which passes a whole list as one array parameter (chapter 22).

### Row comparisons

A subquery can return several columns, compared as a **row value** with `(a, b) = (subquery)` or
`(a, b) IN (subquery)`. The products that appear in the most recent order of each customer:

```sql
SELECT DISTINCT p.name
FROM order_items AS oi
JOIN products AS p ON p.id = oi.product_id
JOIN orders AS o ON o.id = oi.order_id
WHERE (o.customer_id, o.created_at) IN (SELECT customer_id, max(created_at)
                                        FROM orders
                                        GROUP BY customer_id)
ORDER BY p.name;
```

```text
          name
------------------------
 Copy Paper 500 sheets
 Dot Grid Notebook A5
 Graphite Pencil HB
 Heron Fountain Pen
 Kestrel Fountain Pen
 Leather Pen Case
 Midnight Blue Ink 50ml
 Pocket Notebook 3-pack
 Sketching Pencil Set
(9 rows)
```

## Derived tables

A subquery in `FROM` must have an alias, and behaves like a temporary table for the rest of the query. You used them
in chapter 8 to aggregate before joining. They are also the way to filter on a computed column without repeating its
expression:

```sql
SELECT *
FROM (
    SELECT o.id, sum(oi.quantity * oi.unit_price) AS total
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    GROUP BY o.id
) AS t
WHERE t.total > 100
ORDER BY t.total DESC;
```

```text
 id | total
----+--------
  3 | 145.00
 10 | 129.00
(2 rows)
```

Nesting derived tables several levels deep quickly becomes unreadable, though, because you have to read them inside
out. Common table expressions fix that.

## Common table expressions (WITH)

A **common table expression** (CTE) gives a subquery a name, in a `WITH` clause before the main query. Several CTEs can
follow each other, and each can use the ones before it, so a complex query reads top to bottom as a series of steps.

Which customers' spending is above the average customer's, and by how much?

```sql
WITH order_totals AS (
    SELECT o.id, o.customer_id, sum(oi.quantity * oi.unit_price) AS total
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    WHERE o.status <> 'cancelled'
    GROUP BY o.id
),
customer_totals AS (
    SELECT customer_id, sum(total) AS spent, count(*) AS orders
    FROM order_totals
    GROUP BY customer_id
),
average AS (
    SELECT avg(spent) AS avg_spent FROM customer_totals
)
SELECT c.full_name, ct.orders, ct.spent, round(ct.spent - a.avg_spent, 2) AS above_average
FROM customer_totals AS ct
JOIN customers AS c ON c.id = ct.customer_id
CROSS JOIN average AS a
WHERE ct.spent > a.avg_spent
ORDER BY ct.spent DESC;
```

```text
   full_name    | orders | spent  | above_average
----------------+--------+--------+---------------
 Chloé Martin   |      2 | 180.00 |         58.19
 Ana Souza      |      3 | 148.10 |         26.29
 Hana Kobayashi |      1 | 129.00 |          7.19
(3 rows)
```

Each step can be checked on its own while developing: replace the final `SELECT` with `SELECT * FROM order_totals`
and look. The single-row `average` CTE is cross-joined so its value is available in every row.

### Inlined or materialized?

Since PostgreSQL 12, a CTE that is referenced once and has no side effects is **inlined**: the planner treats it
like a derived table and optimizes the whole query together, pushing conditions into it. A CTE referenced more than
once is **materialized**: computed once, stored in memory (or on disk if large), and read by each reference. You can
override the choice:

```sql
WITH recent AS MATERIALIZED (
    SELECT * FROM orders WHERE created_at >= '2026-04-01'
)
SELECT id, status FROM recent WHERE customer_id = 1;
```

```text
 id | status
----+--------
 11 | paid
(1 row)
```

`MATERIALIZED` acts as an "optimization fence": the `customer_id = 1` condition is applied after computing all recent
orders, not pushed into the CTE. That is occasionally useful to stop the planner from choosing a bad plan, but usually
slower; `NOT MATERIALIZED` forces inlining even for a CTE referenced several times. Leave both off unless a query plan
(chapter 16) shows you need them. (Before PostgreSQL 12, every CTE was a fence, so old advice about CTEs being slow is
outdated.)

## Recursive CTEs

`WITH RECURSIVE` lets a CTE refer to **itself**, which makes it possible to walk hierarchies of any depth: category
trees, organization charts, threaded comments, bills of materials. A recursive CTE has two parts joined by `UNION ALL`:

```text
WITH RECURSIVE name AS (
    anchor query                  -- the starting rows
    UNION ALL
    recursive query               -- joins name to find the next level; runs until it returns no rows
)
```

PostgreSQL runs the anchor query, then repeatedly runs the recursive part on the rows found in the previous round,
until a round finds nothing. The whole Inkwell category tree, with each category's depth and full path:

```sql
WITH RECURSIVE tree AS (
    SELECT id, name, parent_id, 1 AS depth, name AS path
    FROM categories
    WHERE parent_id IS NULL                      -- anchor: the top-level categories

    UNION ALL

    SELECT c.id, c.name, c.parent_id, t.depth + 1, t.path || ' > ' || c.name
    FROM categories AS c
    JOIN tree AS t ON c.parent_id = t.id         -- recursive step: children of the last level
)
SELECT id, depth, path
FROM tree
ORDER BY path;
```

```text
 id | depth |              path
----+-------+---------------------------------
 10 |     1 | Accessories
  7 |     1 | Paper
  9 |     2 | Paper > Loose paper
  8 |     2 | Paper > Notebooks
  1 |     1 | Writing
  6 |     2 | Writing > Ink
  5 |     2 | Writing > Pencils
  2 |     2 | Writing > Pens
  4 |     3 | Writing > Pens > Ballpoint pens
  3 |     3 | Writing > Pens > Fountain pens
(10 rows)
```

Compare this with chapter 7, exercise 4, which needed one self join per level. This works for a tree of any depth.

### Everything below a node

The most common tree question is "this category and all its descendants". All products anywhere under "Writing":

```sql
WITH RECURSIVE subtree AS (
    SELECT id FROM categories WHERE slug = 'writing'
    UNION ALL
    SELECT c.id FROM categories AS c JOIN subtree AS s ON c.parent_id = s.id
)
SELECT p.name, p.category_id
FROM products AS p
WHERE p.category_id IN (SELECT id FROM subtree)
ORDER BY p.category_id, p.name;
```

```text
          name          | category_id
------------------------+-------------
 Heron Fountain Pen     |           3
 Kestrel Fountain Pen   |           3
 Click Ballpoint 3-pack |           4
 Everyday Ballpoint     |           4
 Graphite Pencil HB     |           5
 Sketching Pencil Set   |           5
 Midnight Blue Ink 50ml |           6
 Sepia Ink 50ml         |           6
(8 rows)
```

And the reverse, walking **up** from a node to the root, to build breadcrumbs for a product page:

```sql
WITH RECURSIVE ancestors AS (
    SELECT id, parent_id, name, 0 AS up
    FROM categories WHERE slug = 'fountain-pens'
    UNION ALL
    SELECT c.id, c.parent_id, c.name, a.up + 1
    FROM categories AS c JOIN ancestors AS a ON c.id = a.parent_id
)
SELECT string_agg(name, ' > ' ORDER BY up DESC) AS breadcrumbs FROM ancestors;
```

```text
          breadcrumbs
--------------------------------
 Writing > Pens > Fountain pens
(1 row)
```

### Ordering and cycles: SEARCH and CYCLE

Sorting the tree output by the `path` string works for display, but PostgreSQL 14 added clauses that do it properly.
`SEARCH DEPTH FIRST BY col SET ordercol` computes a column that sorts the rows depth-first (each node followed by its
whole subtree), and `SEARCH BREADTH FIRST` sorts level by level:

```sql
WITH RECURSIVE tree AS (
    SELECT id, name, parent_id, 1 AS depth FROM categories WHERE parent_id IS NULL
    UNION ALL
    SELECT c.id, c.name, c.parent_id, t.depth + 1
    FROM categories AS c JOIN tree AS t ON c.parent_id = t.id
) SEARCH DEPTH FIRST BY name SET ordering
SELECT repeat('    ', depth - 1) || name AS category
FROM tree
ORDER BY ordering;
```

```text
        category
------------------------
 Accessories
 Paper
     Loose paper
     Notebooks
 Writing
     Ink
     Pencils
     Pens
         Ballpoint pens
         Fountain pens
(10 rows)
```

If the data contains a **cycle** (A is the parent of B, and B of A), a recursive query loops forever, or until it runs
out of memory. A parent-child table usually can't prevent cycles with ordinary constraints. The `CYCLE` clause detects
them: `CYCLE id SET is_cycle USING path_ids` tracks the ids visited on each path and stops when one repeats:

```sql
WITH RECURSIVE walk AS (
    SELECT 1 AS id, 2 AS next
    UNION ALL
    SELECT w.next, CASE w.next WHEN 2 THEN 3 WHEN 3 THEN 1 END
    FROM walk AS w
) CYCLE id SET is_cycle USING visited
SELECT id, next, is_cycle, visited FROM walk;
```

```text
 id | next | is_cycle |      visited
----+------+----------+-------------------
  1 |    2 | f        | {(1)}
  2 |    3 | f        | {(1),(2)}
  3 |    1 | f        | {(1),(2),(3)}
  1 |      | t        | {(1),(2),(3),(1)}
(4 rows)
```

(The anchor and recursive parts here generate a 1 → 2 → 3 → 1 loop out of thin air, just to show the clause. On real
data the recursive part would join a table.) When you're unsure whether data can contain cycles, add `CYCLE`, or at
least a depth limit (`WHERE t.depth < 100`) in the recursive part.

### UNION versus UNION ALL

In a recursive CTE, `UNION` (without `ALL`) removes duplicate rows, and a row already seen is not processed again,
which also stops simple cycles. In ordinary queries the difference is the same: `UNION ALL` appends results; `UNION`
appends and then removes duplicates, which costs a sort or hash of the whole result. Use `UNION ALL` unless you need
deduplication. `INTERSECT` and `EXCEPT` return the rows common to both queries, or in the first but not the second:

```sql
SELECT customer_id FROM orders
EXCEPT
SELECT customer_id FROM reviews
ORDER BY customer_id;
```

```text
 customer_id
-------------
           4
           8
(2 rows)
```

## Data-modifying CTEs

A CTE can also be an `INSERT`, `UPDATE`, `DELETE` or `MERGE` with `RETURNING`, and the main statement (or later CTEs)
can use the returned rows. The whole statement runs as one atomic unit. This lets you do in one round trip what would
otherwise take several statements and a transaction.

Placing an order in one statement: create the order, add its lines at the current prices, and take the items out of
stock:

```sql
WITH new_order AS (
    INSERT INTO orders (customer_id) VALUES (7)
    RETURNING id
),
wanted (product_id, quantity) AS (
    VALUES (7, 2), (13, 1)
),
lines AS (
    INSERT INTO order_items (order_id, product_id, quantity, unit_price)
    SELECT new_order.id, w.product_id, w.quantity, p.price
    FROM new_order
    CROSS JOIN wanted AS w
    JOIN products AS p ON p.id = w.product_id
    RETURNING order_id, product_id, quantity, unit_price
),
stock AS (
    UPDATE products AS p
    SET stock = p.stock - l.quantity
    FROM lines AS l
    WHERE p.id = l.product_id
    RETURNING p.id, p.stock
)
SELECT l.order_id, l.product_id, l.quantity, l.unit_price, s.stock AS stock_left
FROM lines AS l
JOIN stock AS s ON s.id = l.product_id
ORDER BY l.product_id;
```

```text
 order_id | product_id | quantity | unit_price | stock_left
----------+------------+----------+------------+------------
       13 |          7 |        2 |      14.00 |         73
       13 |         13 |        1 |       5.00 |         89
(2 rows)
```

If any part fails, for example because the stock check constraint rejects an update, the whole statement fails and
nothing is inserted. A `VALUES` list can be a CTE too (`wanted`), with column names in parentheses.

Two rules make data-modifying CTEs predictable:

- **Every part sees the same snapshot** of the database, taken before the statement started. The `stock` CTE can't
  see the rows `lines` inserted by querying `order_items`; it sees them only through `lines`' `RETURNING`. Pass data
  between steps with `RETURNING`, never by re-reading the table.
- **Every data-modifying CTE runs to completion**, even if the main query doesn't read its result.

Moving rows between tables, atomically, in one statement (compare chapter 5, exercise 6):

```sql
CREATE TABLE reviews_archive (LIKE reviews INCLUDING ALL);

WITH moved AS (
    DELETE FROM reviews
    WHERE created_at < '2026-02-01'
    RETURNING *
)
INSERT INTO reviews_archive OVERRIDING SYSTEM VALUE
SELECT * FROM moved;
```

```text
CREATE TABLE
INSERT 0 4
```

And since `RETURNING` has no `ORDER BY`, wrapping a modifying statement in a CTE is also how you sort what it returns:

```sql
WITH changed AS (
    UPDATE products SET price = round(price * 1.02, 2)
    WHERE category_id = 8
    RETURNING sku, old.price AS old_price, new.price AS new_price
)
SELECT * FROM changed ORDER BY sku;
```

```text
  sku   | old_price | new_price
--------+-----------+-----------
 NB-001 |     14.00 |     14.28
 NB-002 |     11.00 |     11.22
 NB-003 |      9.50 |      9.69
(3 rows)
```

Reset the database before the exercises (`psql -X -d postgres -f sql/reset.sql`).

## Key takeaways

- A scalar subquery returns one value (or `NULL` for no rows) and fails if it returns several rows; use `IN` or `ANY`
  when there can be more.
- Correlated subqueries refer to the outer row. They are convenient, but per-row scalar subqueries over large tables can
  be slow; join to a pre-aggregated derived table instead.
- `> ALL` of an empty set is true, and `NULL`s poison `ALL` and `NOT IN`.
- CTEs (`WITH`) name the steps of a complex query. Since PostgreSQL 12, single-use CTEs are inlined;
  `MATERIALIZED`/`NOT MATERIALIZED` override that.
- `WITH RECURSIVE` walks trees and graphs of any depth: an anchor, `UNION ALL`, and a recursive step. `SEARCH` orders
  the result; `CYCLE` (or a depth limit) protects against loops.
- Data-modifying CTEs chain `INSERT`/`UPDATE`/`DELETE` with `RETURNING` in one atomic statement. All parts see the same
  snapshot; pass data between them through `RETURNING`.
- Prefer `UNION ALL` over `UNION` unless you need duplicates removed.

## Exercises

1. **Above-average reviews.** List the reviews whose rating is above the average rating of all reviews, with the
   product name, the reviewer's name and the rating.

<details>
<summary>Solution</summary>

```sql
SELECT p.name AS product, c.full_name AS reviewer, r.rating
FROM reviews AS r
JOIN products AS p ON p.id = r.product_id
JOIN customers AS c ON c.id = r.customer_id
WHERE r.rating > (SELECT avg(rating) FROM reviews)
ORDER BY r.rating DESC, r.id;
```

```text
       product        |   reviewer   | rating
----------------------+--------------+--------
 Kestrel Fountain Pen | Ana Souza    |      5
 Dot Grid Notebook A5 | Ben Carter   |      5
 Heron Fountain Pen   | Chloé Martin |      5
 Leather Pen Case     | Farah Haddad |      5
(4 rows)
```

The average is about 4.1, so only the 5-star reviews qualify.

</details>

2. **Each customer's biggest order.** For every customer with orders, show the name, the id of their most valuable
   order and its total. Use CTEs: one for order totals, then `DISTINCT ON` or a correlated subquery to pick the biggest.

<details>
<summary>Solution</summary>

```sql
WITH order_totals AS (
    SELECT o.id, o.customer_id, sum(oi.quantity * oi.unit_price) AS total
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    GROUP BY o.id
)
SELECT DISTINCT ON (ot.customer_id) c.full_name, ot.id AS order_id, ot.total
FROM order_totals AS ot
JOIN customers AS c ON c.id = ot.customer_id
ORDER BY ot.customer_id, ot.total DESC, ot.id;
```

```text
   full_name    | order_id | total
----------------+----------+--------
 Ana Souza      |        1 |  80.00
 Ben Carter     |        7 |  39.95
 Chloé Martin   |        3 | 145.00
 Dev Patel      |        5 |  18.50
 Emma Schulz    |        6 |  72.80
 Farah Haddad   |        8 |  80.00
 Hana Kobayashi |       10 | 129.00
(7 rows)
```

The `ot.id` at the end of the `ORDER BY` decides between two orders with the same total, so the result is
deterministic.

</details>

3. **Product counts including subcategories.** For each top-level category, count the active products in it and in
   all its descendants, with a recursive CTE.

<details>
<summary>Solution</summary>

```sql
WITH RECURSIVE tree AS (
    SELECT id, id AS top_id FROM categories WHERE parent_id IS NULL
    UNION ALL
    SELECT c.id, t.top_id FROM categories AS c JOIN tree AS t ON c.parent_id = t.id
)
SELECT top.name, count(p.id) AS active_products
FROM tree AS t
JOIN categories AS top ON top.id = t.top_id
LEFT JOIN products AS p ON p.category_id = t.id AND p.active
GROUP BY top.id
ORDER BY top.name;
```

```text
    name     | active_products
-------------+-----------------
 Accessories |               1
 Paper       |               4
 Writing     |               8
(3 rows)
```

The trick is to carry the top-level id (`top_id`) down the recursion unchanged, so every category knows which tree it
belongs to. Accessories has two products, but the Leather Pen Case is inactive.

</details>

4. **A date series without generate_series.** Use a recursive CTE to produce the dates from 2026-03-28 to 2026-04-03,
   one per row. Then show, for each date, how many orders were created on it.

<details>
<summary>Solution</summary>

```sql
WITH RECURSIVE days AS (
    SELECT date '2026-03-28' AS day
    UNION ALL
    SELECT day + 1 FROM days WHERE day < date '2026-04-03'
)
SELECT d.day, count(o.id) AS orders
FROM days AS d
LEFT JOIN orders AS o ON o.created_at::date = d.day
GROUP BY d.day
ORDER BY d.day;
```

```text
    day     | orders
------------+--------
 2026-03-28 |      1
 2026-03-29 |      0
 2026-03-30 |      0
 2026-03-31 |      0
 2026-04-01 |      0
 2026-04-02 |      0
 2026-04-03 |      0
(7 rows)
```

The `WHERE day < ...` in the recursive part is the stop condition: without it the query would never end.
`generate_series` is simpler for this particular job, but the pattern ("start, step, stop") is general.
`o.created_at::date` converts each timestamp to a date in the session's time zone (UTC here); chapter 11 discusses why
that matters.

</details>

5. **Cancel an order in one statement.** Write a single statement with data-modifying CTEs that cancels order 10 (status
   `paid`): set its status to `cancelled`, put its items back into stock, and return the products and their new stock.
   Make sure it does nothing if the order is not in status `paid`.

<details>
<summary>Solution</summary>

```sql
WITH cancelled AS (
    UPDATE orders SET status = 'cancelled'
    WHERE id = 10 AND status = 'paid'
    RETURNING id
),
restocked AS (
    UPDATE products AS p
    SET stock = p.stock + oi.quantity
    FROM order_items AS oi
    JOIN cancelled AS c ON c.id = oi.order_id
    WHERE p.id = oi.product_id
    RETURNING p.sku, p.stock
)
SELECT * FROM restocked ORDER BY sku;
```

```text
  sku   | stock
--------+-------
 FP-002 |     4
(1 row)
```

The stock update joins `cancelled`, so if the first `UPDATE` changed nothing (wrong id, or the order was already
shipped), `cancelled` is empty and no stock changes. Run the statement a second time and it returns no rows. This
"condition in the `WHERE` of the `UPDATE`" pattern is also what makes it safe when two clerks click "cancel" at the
same moment (chapter 17).

</details>

---
← Previous: [Aggregation and grouping](08-aggregation-and-grouping.md) | Next: [Window functions](10-window-functions.md) →
