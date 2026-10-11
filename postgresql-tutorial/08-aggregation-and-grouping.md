# Chapter 8 — Aggregation and grouping

## What you'll learn

- Aggregate functions: `count`, `sum`, `avg`, `min`, `max`, and how each treats `NULL` and empty input
- `GROUP BY`: one output row per group, and the rule about which columns you may select
- `HAVING` versus `WHERE`
- Conditional aggregation with `FILTER`
- Building lists with `string_agg` and `array_agg`, and other useful aggregates
- Medians, percentiles and the most common value with ordered-set aggregates
- Subtotals and grand totals with `ROLLUP`, `CUBE` and `GROUPING SETS`
- How to aggregate several child tables without the fan-out bug from chapter 7

Aggregation turns many rows into a few: totals, averages, counts per category, revenue per month. Most of the questions
a business asks of its database are aggregation queries. Start from the reference data (chapter 5).

## Aggregate functions

An **aggregate function** computes one value from a set of rows. Without `GROUP BY`, the whole table (after `WHERE`)
is one set, and the result is exactly one row:

```sql
SELECT count(*)          AS products,
       count(DISTINCT category_id) AS categories_used,
       sum(stock)        AS units_in_stock,
       min(price)        AS cheapest,
       max(price)        AS dearest,
       round(avg(price), 2) AS average_price
FROM products
WHERE active;
```

```text
 products | categories_used | units_in_stock | cheapest | dearest | average_price
----------+-----------------+----------------+----------+---------+---------------
       13 |               7 |           1885 |     1.20 |  129.00 |         21.97
(1 row)
```

| Function | Returns |
|---|---|
| `count(*)` | the number of rows |
| `count(expr)` | the number of rows where `expr` is not `NULL` |
| `count(DISTINCT expr)` | the number of different non-null values |
| `sum(expr)`, `avg(expr)` | the sum and the arithmetic mean of the non-null values |
| `min(expr)`, `max(expr)` | the smallest and largest non-null value (works for text, dates, …) |

### Aggregates and NULL

Every aggregate except `count(*)` **ignores `NULL`s**. That is usually what you want (the average rating of reviews
that have a rating), but it changes meanings in subtle ways. Compare `count(*)` and `count(column)`:

```sql
SELECT count(*) AS orders, count(shipped_at) AS shipped FROM orders;
```

```text
 orders | shipped
--------+---------
     12 |       8
(1 row)
```

And on **empty input**, `count` returns 0 but `sum`, `avg`, `min` and `max` return `NULL`, not 0:

```sql
SELECT count(*) AS n, sum(quantity) AS total, coalesce(sum(quantity), 0) AS total_or_zero
FROM order_items
WHERE product_id = 13;
```

```text
 n | total | total_or_zero
---+-------+---------------
 0 |       |             0
(1 row)
```

The Brass Pen Clip has never been sold. "The sum of no values" is `NULL` in SQL; wrap sums in `coalesce(..., 0)` when a
report should show 0.

`avg` of integers returns a `numeric` with many decimals; round it for display. And `avg` is not always the right
"typical value": one huge order drags the mean up. The median (below) is often more honest.

## GROUP BY

`GROUP BY` splits the rows into groups with equal values of the grouping expressions, and the aggregates are computed
per group. One output row per group:

```sql
SELECT status, count(*) AS orders
FROM orders
GROUP BY status
ORDER BY status;
```

```text
  status   | orders
-----------+--------
 pending   |      1
 paid      |      2
 shipped   |      2
 delivered |      6
 cancelled |      1
(5 rows)
```

(Ordered by the enum's declaration order, which is the lifecycle order.)

### Revenue per order

The value of an order is the sum of its lines. Grouping `order_items` by order:

```sql
SELECT order_id,
       count(*) AS lines,
       sum(quantity) AS units,
       sum(quantity * unit_price) AS total
FROM order_items
GROUP BY order_id
ORDER BY order_id;
```

```text
 order_id | lines | units | total
----------+-------+-------+--------
        1 |     2 |     3 |  80.00
        2 |     2 |    11 |  39.00
        3 |     2 |     2 | 145.00
        4 |     2 |    14 |  42.40
        5 |     1 |     1 |  18.50
        6 |     3 |     4 |  72.80
        7 |     1 |     5 |  39.95
        8 |     2 |     2 |  80.00
        9 |     2 |     3 |  35.00
       10 |     1 |     1 | 129.00
       11 |     2 |     7 |  25.70
       12 |     1 |     3 |  42.00
(12 rows)
```

### The grouping rule

Every column in the `SELECT` list must either be in `GROUP BY` or be inside an aggregate. Otherwise, which of the
group's many values should PostgreSQL show?

```sql
SELECT o.customer_id, o.status, count(*)
FROM orders AS o
GROUP BY o.customer_id;
```

```text
ERROR:  column "o.status" must appear in the GROUP BY clause or be used in an aggregate function
LINE 1: SELECT o.customer_id, o.status, count(*)
                              ^
```

There is one useful exception: if you group by a table's **primary key**, you may select any other column of that
table, because each group then has exactly one value for it (the columns are *functionally dependent* on the key).
Revenue per customer, with the customer's name, grouping only by `c.id`:

```sql
SELECT c.id, c.full_name, c.country,
       count(DISTINCT o.id) AS orders,
       sum(oi.quantity * oi.unit_price) AS revenue
FROM customers AS c
JOIN orders AS o       ON o.customer_id = c.id
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status <> 'cancelled'
GROUP BY c.id
ORDER BY revenue DESC;
```

```text
 id |   full_name    | country | orders | revenue
----+----------------+---------+--------+---------
  3 | Chloé Martin   | FR      |      2 |  180.00
  1 | Ana Souza      | BR      |      3 |  148.10
  8 | Hana Kobayashi | JP      |      1 |  129.00
  5 | Emma Schulz    | DE      |      2 |  114.80
  6 | Farah Haddad   | US      |      1 |   80.00
  2 | Ben Carter     | US      |      2 |   78.95
(6 rows)
```

`count(DISTINCT o.id)` is needed because the join with `order_items` repeats each order once per line. The `sum` is
correct, because each order line appears exactly once.

### Grouping by expressions

You can group by any expression, not only columns. Revenue per month, using `date_trunc` to cut each timestamp down to
the first moment of its month (chapter 11 covers date functions):

```sql
SELECT date_trunc('month', o.created_at) AS month,
       count(DISTINCT o.id) AS orders,
       sum(oi.quantity * oi.unit_price) AS revenue
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status <> 'cancelled'
GROUP BY month
ORDER BY month;
```

```text
         month          | orders | revenue
------------------------+--------+---------
 2026-01-01 00:00:00+00 |      2 |  119.00
 2026-02-01 00:00:00+00 |      2 |  187.40
 2026-03-01 00:00:00+00 |      3 |  192.75
 2026-04-01 00:00:00+00 |      4 |  231.70
(4 rows)
```

`GROUP BY month` refers to the output column alias, a PostgreSQL convenience (the SQL standard would make you repeat
the expression). If an alias and an input column have the same name, the input column wins, so pick aliases that
don't collide.

## HAVING: filtering groups

`WHERE` filters rows **before** grouping; `HAVING` filters groups **after** aggregation, so it can use aggregates. The
customers who spent more than 100 in total:

```sql
SELECT c.full_name, sum(oi.quantity * oi.unit_price) AS revenue
FROM customers AS c
JOIN orders AS o       ON o.customer_id = c.id
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status <> 'cancelled'
GROUP BY c.id
HAVING sum(oi.quantity * oi.unit_price) > 100
ORDER BY revenue DESC;
```

```text
   full_name    | revenue
----------------+---------
 Chloé Martin   |  180.00
 Ana Souza      |  148.10
 Hana Kobayashi |  129.00
 Emma Schulz    |  114.80
(4 rows)
```

The `HAVING` clause has to repeat the expression: like `WHERE`, it runs before `SELECT`, so the `revenue` alias
doesn't exist yet. Put every condition that doesn't need an aggregate into `WHERE`: filtering early means fewer rows to
group.

## FILTER: conditional aggregation

`FILTER (WHERE ...)` after an aggregate restricts it to the rows that match, so one query can compute several
differently filtered aggregates over the same groups. Order counts by status, one row per customer:

```sql
SELECT c.full_name,
       count(o.id) AS all_orders,
       count(o.id) FILTER (WHERE o.status IN ('paid', 'pending')) AS open,
       count(o.id) FILTER (WHERE o.status IN ('shipped', 'delivered')) AS completed,
       count(o.id) FILTER (WHERE o.status = 'cancelled') AS cancelled
FROM customers AS c
LEFT JOIN orders AS o ON o.customer_id = c.id
GROUP BY c.id
ORDER BY c.id;
```

```text
   full_name    | all_orders | open | completed | cancelled
----------------+------------+------+-----------+-----------
 Ana Souza      |          3 |    1 |         2 |         0
 Ben Carter     |          2 |    0 |         2 |         0
 Chloé Martin   |          2 |    0 |         2 |         0
 Dev Patel      |          1 |    0 |         0 |         1
 Emma Schulz    |          2 |    1 |         1 |         0
 Farah Haddad   |          1 |    0 |         1 |         0
 Gus Lindqvist  |          0 |    0 |         0 |         0
 Hana Kobayashi |          1 |    1 |         0 |         0
(8 rows)
```

That turns rows into columns, a "pivot". Older code (and other databases) does the same with
`sum(CASE WHEN ... THEN 1 ELSE 0 END)`; `FILTER` is clearer and works with every aggregate. The `LEFT JOIN` and
`count(o.id)` keep Gus in the list with zeros.

## Building lists: string_agg and array_agg

`string_agg(expr, separator)` concatenates values into one string; `array_agg(expr)` collects them into an array. Both
accept an `ORDER BY` inside the parentheses, and you almost always want one, since otherwise the order is arbitrary:

```sql
SELECT o.id AS order_id,
       string_agg(p.name, ', ' ORDER BY p.name) AS products,
       array_agg(oi.quantity ORDER BY p.name) AS quantities
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
JOIN products AS p     ON p.id = oi.product_id
WHERE o.customer_id = 5
GROUP BY o.id
ORDER BY o.id;
```

```text
 order_id |                            products                             | quantities
----------+-----------------------------------------------------------------+------------
        6 | Click Ballpoint 3-pack, Kestrel Fountain Pen, Lined Notebook A4 | {2,1,1}
       12 | Dot Grid Notebook A5                                            | {3}
(2 rows)
```

Other handy aggregates:

| Aggregate | Use |
|---|---|
| `bool_and(expr)`, `bool_or(expr)` | are all / any of the values true? |
| `any_value(expr)` (16+) | any one value from the group, when you know they're all equal or don't care |
| `json_agg(expr)`, `jsonb_object_agg(k, v)` | build JSON from rows (chapter 12) |
| `stddev(expr)`, `variance(expr)`, `corr(y, x)` | statistics |

```sql
SELECT cat.name,
       count(*) AS products,
       bool_and(p.stock > 0) AS all_in_stock,
       bool_or('gift' = ANY (p.tags)) AS has_gift_idea
FROM products AS p
JOIN categories AS cat ON cat.id = p.category_id
WHERE p.active
GROUP BY cat.id
ORDER BY cat.name;
```

```text
      name      | products | all_in_stock | has_gift_idea
----------------+----------+--------------+---------------
 Accessories    |        1 | t            | f
 Ballpoint pens |        2 | t            | f
 Fountain pens  |        2 | t            | t
 Ink            |        2 | t            | f
 Loose paper    |        1 | t            | f
 Notebooks      |        3 | f            | f
 Pencils        |        2 | t            | t
(7 rows)
```

## Ordered-set aggregates: medians and percentiles

Some aggregates need their input sorted. They use the `WITHIN GROUP (ORDER BY ...)` syntax:

- `percentile_cont(f)`: the value at fraction `f` of the sorted input, interpolating between neighbours. `0.5` is the
  median.
- `percentile_disc(f)`: the first actual input value at or above fraction `f` (no interpolation).
- `mode()`: the most frequent value.

Typical order value, compared with the mean:

```sql
SELECT round(avg(total), 2) AS mean,
       percentile_cont(0.5) WITHIN GROUP (ORDER BY total) AS median,
       percentile_disc(0.9) WITHIN GROUP (ORDER BY total) AS p90
FROM (
    SELECT order_id, sum(quantity * unit_price) AS total
    FROM order_items
    GROUP BY order_id
) AS order_totals;
```

```text
 mean  | median |  p90
-------+--------+--------
 62.45 |   42.2 | 129.00
(1 row)
```

The inner query computes one total per order; the outer one aggregates those totals. (A query in `FROM` like this is a
*derived table*; chapter 9 covers subqueries.) The two orders with an expensive Heron pen pull the mean well above the
median: half of all orders are worth less than 42.20. (`percentile_cont` interpolates, so it returns a
`double precision`, printed as `42.2`.)

```sql
SELECT mode() WITHIN GROUP (ORDER BY rating) AS most_common_rating FROM reviews;
```

```text
 most_common_rating
--------------------
                  5
(1 row)
```

## Subtotals: ROLLUP, CUBE and GROUPING SETS

Reports often need subtotals and a grand total. `GROUP BY ROLLUP (a, b)` produces groups for `(a, b)`, then `(a)`,
then `()`, i.e. detail rows, a subtotal per `a`, and a grand total. Revenue by top-level category and month:

```sql
SELECT top.name AS top_category,
       to_char(o.created_at, 'YYYY-MM') AS month,
       sum(oi.quantity * oi.unit_price) AS revenue
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
JOIN products AS p     ON p.id = oi.product_id
JOIN categories AS cat ON cat.id = p.category_id
JOIN categories AS top ON top.id = coalesce(
         (SELECT parent.parent_id FROM categories AS parent WHERE parent.id = cat.parent_id),
         cat.parent_id,
         cat.id)
WHERE o.status <> 'cancelled'
GROUP BY ROLLUP (top.name, month)
ORDER BY top.name, month;
```

```text
 top_category |  month  | revenue
--------------+---------+---------
 Accessories  | 2026-03 |   35.00
 Accessories  |         |   35.00
 Paper        | 2026-01 |   14.00
 Paper        | 2026-02 |   28.00
 Paper        | 2026-03 |   50.95
 Paper        | 2026-04 |   61.00
 Paper        |         |  153.95
 Writing      | 2026-01 |  105.00
 Writing      | 2026-02 |  159.40
 Writing      | 2026-03 |  106.80
 Writing      | 2026-04 |  170.70
 Writing      |         |  541.90
              |         |  730.85
(13 rows)
```

The rows with an empty `month` are the subtotals per category, and the last row (both empty) is the grand total. The
join to `top` finds each product's top-level category by climbing at most two levels, which is enough for Inkwell's
tree; chapter 9 shows the general solution.

The empty cells are `NULL`s, which is ambiguous when the grouped columns can themselves be `NULL`. The `GROUPING()`
function tells you which columns were rolled up in a row (1) and which are real groups (0), so you can label them:

```sql
SELECT CASE WHEN GROUPING(c.country) = 1 THEN 'All countries' ELSE c.country END AS country,
       count(*) AS customers
FROM customers AS c
GROUP BY ROLLUP (c.country)
ORDER BY GROUPING(c.country), c.country;
```

```text
    country    | customers
---------------+-----------
 BR            |         1
 DE            |         1
 FR            |         1
 GB            |         1
 JP            |         1
 SE            |         1
 US            |         2
 All countries |         8
(8 rows)
```

- `CUBE (a, b)` produces every combination: `(a, b)`, `(a)`, `(b)` and `()`.
- `GROUPING SETS ((a, b), (a), ())` lists exactly the groupings you want. `ROLLUP` and `CUBE` are shorthands for
  common grouping sets.

## Aggregating several child tables correctly

Chapter 7 showed that joining a parent to two independent child tables multiplies rows, so that counts and sums come
out wrong. The fix is to aggregate each child table **before** joining, so each joins at most one row per parent. With
derived tables:

```sql
SELECT c.full_name,
       coalesce(o.orders, 0) AS orders,
       coalesce(r.reviews, 0) AS reviews,
       r.avg_rating
FROM customers AS c
LEFT JOIN (SELECT customer_id, count(*) AS orders
           FROM orders GROUP BY customer_id) AS o ON o.customer_id = c.id
LEFT JOIN (SELECT customer_id, count(*) AS reviews, round(avg(rating), 1) AS avg_rating
           FROM reviews GROUP BY customer_id) AS r ON r.customer_id = c.id
ORDER BY c.id;
```

```text
   full_name    | orders | reviews | avg_rating
----------------+--------+---------+------------
 Ana Souza      |      3 |       2 |        4.5
 Ben Carter     |      2 |       2 |        4.0
 Chloé Martin   |      2 |       2 |        4.5
 Dev Patel      |      1 |       0 |
 Emma Schulz    |      2 |       2 |        3.0
 Farah Haddad   |      1 |       1 |        5.0
 Gus Lindqvist  |      0 |       0 |
 Hana Kobayashi |      1 |       0 |
(8 rows)
```

Now Ana has 3 orders and 2 reviews, as she should. The `LATERAL` form from chapter 7 works too, and some find it
easier to read; PostgreSQL usually plans the derived-table form more efficiently when you want the result for all
parents.

## Filling the gaps

`GROUP BY` produces rows only for groups that exist. A daily sales report for the first week of April shows only the
days with orders. To show every day, generate the calendar with `generate_series` and left-join the data to it:

```sql
SELECT d::date AS day,
       count(o.id) AS orders
FROM generate_series('2026-04-01'::timestamptz, '2026-04-07', interval '1 day') AS d
LEFT JOIN orders AS o
       ON o.created_at >= d AND o.created_at < d + interval '1 day'
GROUP BY d
ORDER BY d;
```

```text
    day     | orders
------------+--------
 2026-04-01 |      0
 2026-04-02 |      0
 2026-04-03 |      0
 2026-04-04 |      1
 2026-04-05 |      0
 2026-04-06 |      0
 2026-04-07 |      0
(7 rows)
```

`generate_series(start, stop, step)` is a *set-returning function*: it produces one row per step. Chapter 11 uses it
for more calendar work.

## Key takeaways

- Aggregates collapse a set of rows into one value. All except `count(*)` ignore `NULL`s, and `sum`/`avg`/`min`/`max`
  of no rows is `NULL`: use `coalesce` where a report needs 0.
- `GROUP BY` makes one output row per group. Every selected column must be grouped or aggregated, unless it depends
  on a grouped primary key.
- `WHERE` filters rows before grouping; `HAVING` filters groups after. Keep non-aggregate conditions in `WHERE`.
- `FILTER (WHERE ...)` computes several conditional aggregates in one pass and pivots rows into columns.
- Give `string_agg` and `array_agg` an `ORDER BY` inside the call.
- `percentile_cont(0.5) WITHIN GROUP (ORDER BY x)` is the median; it is often a better "typical value" than `avg`.
- `ROLLUP`, `CUBE` and `GROUPING SETS` add subtotals and totals; `GROUPING()` labels them.
- Aggregate each child table separately before joining it to the parent; otherwise fan-out corrupts the numbers.
- `generate_series` plus a `LEFT JOIN` fills in groups that have no rows.

## Exercises

1. **Stock value per category.** For each category that has products, show the category name, the number of active
   products, and the total value of the stock (`price * stock`) of active products. Sort by stock value, highest first.

<details>
<summary>Solution</summary>

```sql
SELECT cat.name,
       count(*) AS products,
       sum(p.price * p.stock) AS stock_value
FROM products AS p
JOIN categories AS cat ON cat.id = p.category_id
WHERE p.active
GROUP BY cat.id
ORDER BY stock_value DESC;
```

```text
      name      | products | stock_value
----------------+----------+-------------
 Ballpoint pens |        2 |     2035.00
 Notebooks      |        3 |     1710.00
 Pencils        |        2 |     1700.00
 Loose paper    |        1 |     1598.00
 Fountain pens  |        2 |      963.00
 Ink            |        2 |      880.00
 Accessories    |        1 |      450.00
(7 rows)
```

</details>

2. **Best sellers.** List the products that have sold more than 2 units in total (ignoring cancelled orders), with
   the total units and revenue, best-selling first. Then extend the query to include products that never sold, with 0
   units, using a `LEFT JOIN`, and drop the "more than 2" condition.

<details>
<summary>Solution</summary>

```sql
SELECT p.name, sum(oi.quantity) AS units, sum(oi.quantity * oi.unit_price) AS revenue
FROM products AS p
JOIN order_items AS oi ON oi.product_id = p.id
JOIN orders AS o       ON o.id = oi.order_id
WHERE o.status <> 'cancelled'
GROUP BY p.id
HAVING sum(oi.quantity) > 2
ORDER BY units DESC, p.name;
```

```text
          name          | units | revenue
------------------------+-------+---------
 Graphite Pencil HB     |    18 |   21.60
 Everyday Ballpoint     |    10 |   25.00
 Dot Grid Notebook A5   |     6 |   84.00
 Copy Paper 500 sheets  |     5 |   39.95
 Kestrel Fountain Pen   |     3 |  141.00
 Midnight Blue Ink 50ml |     3 |   48.00
(6 rows)
```

With every product: the cancelled-order condition must move into the join, or it would remove the unsold products
(chapter 7's `WHERE` trap). Because `o` is joined to `oi`, the inner structure needs care: join `order_items` and
`orders` together first in a derived table:

```sql
SELECT p.name,
       coalesce(sum(s.quantity), 0) AS units,
       coalesce(sum(s.quantity * s.unit_price), 0) AS revenue
FROM products AS p
LEFT JOIN (SELECT oi.product_id, oi.quantity, oi.unit_price
           FROM order_items AS oi
           JOIN orders AS o ON o.id = oi.order_id
           WHERE o.status <> 'cancelled') AS s ON s.product_id = p.id
GROUP BY p.id
ORDER BY units DESC, p.name;
```

The Brass Pen Clip and the Sketching Pencil Set's cancelled order show how the two pitfalls (unsold products and
cancelled orders) interact: the pencil set appears with 1 unit (from order 11, not the cancelled order 5), and the clip
with 0.

</details>

3. **Ratings report.** For each product with at least one review, show the name, the number of reviews, the average
   rating rounded to one decimal, and a comma-separated list of the ratings in descending order. Only include products
   whose average is at least 4.

<details>
<summary>Solution</summary>

```sql
SELECT p.name,
       count(*) AS reviews,
       round(avg(r.rating), 1) AS avg_rating,
       string_agg(r.rating::text, ', ' ORDER BY r.rating DESC) AS ratings
FROM reviews AS r
JOIN products AS p ON p.id = r.product_id
GROUP BY p.id
HAVING avg(r.rating) >= 4
ORDER BY avg_rating DESC, p.name;
```

```text
          name          | reviews | avg_rating | ratings
------------------------+---------+------------+---------
 Dot Grid Notebook A5   |       1 |        5.0 | 5
 Heron Fountain Pen     |       1 |        5.0 | 5
 Leather Pen Case       |       1 |        5.0 | 5
 Kestrel Fountain Pen   |       2 |        4.5 | 5, 4
 Midnight Blue Ink 50ml |       1 |        4.0 | 4
 Sepia Ink 50ml         |       1 |        4.0 | 4
(6 rows)
```

`string_agg` needs text, hence `r.rating::text`. `HAVING` repeats `avg(r.rating)` rather than using the rounded alias,
which would also be wrong in principle: a 3.96 average rounds to 4.0 but isn't "at least 4".

</details>

4. **Countries pivot.** Produce one row per order status with the number of orders from each of three countries as
   columns: `us`, `fr`, `other`. Use `FILTER`.

<details>
<summary>Solution</summary>

```sql
SELECT o.status,
       count(*) FILTER (WHERE c.country = 'US') AS us,
       count(*) FILTER (WHERE c.country = 'FR') AS fr,
       count(*) FILTER (WHERE c.country NOT IN ('US', 'FR')) AS other
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
GROUP BY o.status
ORDER BY o.status;
```

```text
  status   | us | fr | other
-----------+----+----+-------
 pending   |  0 |  0 |     1
 paid      |  0 |  0 |     2
 shipped   |  1 |  1 |     0
 delivered |  2 |  1 |     3
 cancelled |  0 |  0 |     1
(5 rows)
```

</details>

5. **Subtotals by customer and status.** Using `GROUPING SETS`, produce in one query: the number of orders per
   (customer, status), per customer, and overall. Label the rolled-up rows with `GROUPING()`.

<details>
<summary>Solution</summary>

```sql
SELECT CASE WHEN GROUPING(c.full_name) = 1 THEN '(all)' ELSE c.full_name END AS customer,
       CASE WHEN GROUPING(o.status) = 1 THEN '(all)' ELSE o.status::text END AS status,
       count(*) AS orders
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
GROUP BY GROUPING SETS ((c.full_name, o.status), (c.full_name), ())
ORDER BY GROUPING(c.full_name), c.full_name, GROUPING(o.status), o.status;
```

```text
    customer    |  status   | orders
----------------+-----------+--------
 Ana Souza      | paid      |      1
 Ana Souza      | delivered |      2
 Ana Souza      | (all)     |      3
 Ben Carter     | shipped   |      1
 Ben Carter     | delivered |      1
 Ben Carter     | (all)     |      2
 Chloé Martin   | shipped   |      1
 Chloé Martin   | delivered |      1
 Chloé Martin   | (all)     |      2
 Dev Patel      | cancelled |      1
 Dev Patel      | (all)     |      1
 Emma Schulz    | pending   |      1
 Emma Schulz    | delivered |      1
 Emma Schulz    | (all)     |      2
 Farah Haddad   | delivered |      1
 Farah Haddad   | (all)     |      1
 Hana Kobayashi | paid      |      1
 Hana Kobayashi | (all)     |      1
 (all)          | (all)     |     12
(19 rows)
```

`o.status::text` is needed because both branches of a `CASE` must have the same type, and `'(all)'` is not a valid
`order_status`.

</details>

---
← Previous: [Joins](07-joins.md) | Next: [Subqueries and common table expressions](09-subqueries-and-ctes.md) →
