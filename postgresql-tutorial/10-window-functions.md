# Chapter 10 — Window functions

## What you'll learn

- What a window function is: an aggregate-like calculation that keeps every row
- `OVER`, `PARTITION BY` and `ORDER BY` inside a window
- Running totals, shares of a total, and why ties change running totals
- Ranking with `row_number`, `rank`, `dense_rank`, `ntile` and `percent_rank`
- Top-N per group
- Looking at neighbouring rows with `lag` and `lead`, and `first_value`/`last_value`
- Window frames: `ROWS`, `RANGE` and `GROUPS`, moving averages, and time-based windows
- Named windows, windows over aggregates, and the "gaps and islands" technique

Aggregates with `GROUP BY` answer "what is the total per group?", but they collapse each group into one row. Very
often you want the detail rows **and** a group-level value next to each: each order with the customer's running total,
each product with its rank in its category, each month with the change from the previous month. That is what window
functions do. They are one of the most powerful features of SQL, and once you know them you'll use them everywhere.
Start from the reference data (chapter 5).

## From aggregate to window

Compare an aggregate with the same function used as a window function. The aggregate collapses the rows:

```sql
SELECT customer_id, count(*) AS orders
FROM orders
WHERE customer_id IN (1, 2)
GROUP BY customer_id
ORDER BY customer_id;
```

```text
 customer_id | orders
-------------+--------
           1 |      3
           2 |      2
(2 rows)
```

Adding `OVER (...)` after the function turns it into a **window function**: it is computed over a set of rows related
to the current row (the *window*), and every row stays in the result:

```sql
SELECT id, customer_id, created_at::date AS day,
       count(*) OVER (PARTITION BY customer_id) AS customer_orders,
       count(*) OVER () AS all_orders
FROM orders
WHERE customer_id IN (1, 2)
ORDER BY customer_id, id;
```

```text
 id | customer_id |    day     | customer_orders | all_orders
----+-------------+------------+-----------------+------------
  1 |           1 | 2026-01-06 |               3 |          5
  4 |           1 | 2026-02-10 |               3 |          5
 11 |           1 | 2026-04-09 |               3 |          5
  2 |           2 | 2026-01-15 |               2 |          5
  7 |           2 | 2026-03-21 |               2 |          5
(5 rows)
```

- `OVER ()` makes the window the whole result (after `WHERE`): 5 rows.
- `OVER (PARTITION BY customer_id)` splits the rows into **partitions** by customer, like `GROUP BY` does, but
  without collapsing them. Each row sees the rows of its own partition.

Any aggregate (`sum`, `avg`, `min`, `max`, `count`, `string_agg`, …) can be used as a window function, plus a set of
functions that only make sense in windows (ranking, `lag`, `lead`, …).

### Where window functions run

Window functions are computed after `WHERE`, `GROUP BY` and `HAVING`, and before `ORDER BY` and `LIMIT`. So they see
the filtered (and grouped) rows, and they **can't be used in `WHERE`**:

```sql
SELECT id, row_number() OVER (ORDER BY id) AS n FROM orders WHERE row_number() OVER (ORDER BY id) <= 3;
```

```text
ERROR:  window functions are not allowed in WHERE
LINE 1: ...umber() OVER (ORDER BY id) AS n FROM orders WHERE row_number...
                                                             ^
```

To filter on a window function's result, compute it in a subquery or CTE and filter outside, as in the top-N section
below.

## Shares of a total

A window over the whole result gives you the grand total on every row, which makes percentages trivial. Each
category's share of the total stock value:

```sql
SELECT cat.name,
       sum(p.price * p.stock) AS stock_value,
       round(100 * sum(p.price * p.stock) / sum(sum(p.price * p.stock)) OVER (), 1) AS pct
FROM products AS p
JOIN categories AS cat ON cat.id = p.category_id
WHERE p.active
GROUP BY cat.id
ORDER BY stock_value DESC;
```

```text
      name      | stock_value | pct
----------------+-------------+------
 Ballpoint pens |     2035.00 | 21.8
 Notebooks      |     1710.00 | 18.3
 Pencils        |     1700.00 | 18.2
 Loose paper    |     1598.00 | 17.1
 Fountain pens  |      963.00 | 10.3
 Ink            |      880.00 |  9.4
 Accessories    |      450.00 |  4.8
(7 rows)
```

`sum(sum(...)) OVER ()` looks odd but follows from the evaluation order: the inner `sum` is the `GROUP BY` aggregate
(one value per category), and the outer `sum ... OVER ()` adds those up across all the grouped rows. Windows run after
grouping, so they can use aggregates.

## ORDER BY in a window: running totals

With `ORDER BY` inside `OVER`, an aggregate becomes **cumulative**: each row sees the rows of its partition from the
first up to itself. Each customer's running total of spending, order by order:

```sql
WITH order_totals AS (
    SELECT o.id, o.customer_id, o.created_at, sum(oi.quantity * oi.unit_price) AS total
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    WHERE o.status <> 'cancelled'
    GROUP BY o.id
)
SELECT customer_id, id AS order_id, created_at::date AS day, total,
       sum(total) OVER (PARTITION BY customer_id ORDER BY created_at) AS running_total,
       count(*) OVER (PARTITION BY customer_id ORDER BY created_at) AS order_number
FROM order_totals
WHERE customer_id IN (1, 3, 5)
ORDER BY customer_id, created_at;
```

```text
 customer_id | order_id |    day     | total  | running_total | order_number
-------------+----------+------------+--------+---------------+--------------
           1 |        1 | 2026-01-06 |  80.00 |         80.00 |            1
           1 |        4 | 2026-02-10 |  42.40 |        122.40 |            2
           1 |       11 | 2026-04-09 |  25.70 |        148.10 |            3
           3 |        3 | 2026-02-03 | 145.00 |        145.00 |            1
           3 |        9 | 2026-04-04 |  35.00 |        180.00 |            2
           5 |        6 | 2026-03-02 |  72.80 |         72.80 |            1
           5 |       12 | 2026-04-10 |  42.00 |        114.80 |            2
(7 rows)
```

The `ORDER BY` inside `OVER` only defines the window; it doesn't sort the output. The final `ORDER BY` does that. They
are often the same, but they don't have to be.

### The tie trap

By default, an ordered window includes the current row **and all rows that tie with it** in the window's `ORDER BY`
(its *peers*). With unique sort keys, as above, that doesn't matter. With ties, the running total jumps:

```sql
SELECT sku, price,
       sum(price) OVER (ORDER BY price) AS running_default,
       sum(price) OVER (ORDER BY price ROWS UNBOUNDED PRECEDING) AS running_rows
FROM products
WHERE category_id IN (6, 8);
```

```text
  sku   | price | running_default | running_rows
--------+-------+-----------------+--------------
 NB-003 |  9.50 |            9.50 |         9.50
 NB-002 | 11.00 |           20.50 |        20.50
 NB-001 | 14.00 |           34.50 |        34.50
 IN-001 | 16.00 |           66.50 |        50.50
 IN-002 | 16.00 |           66.50 |        66.50
(5 rows)
```

The two ink bottles both cost 16.00, so with the default window both rows include both bottles. The default frame is
`RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW`, where `RANGE` treats peers as a group. `ROWS UNBOUNDED
PRECEDING` counts physical rows instead, giving a true row-by-row running total, but then the order among the tied rows
is arbitrary. The best fix is to make the window's ordering unique: `ORDER BY price, sku`. Frames get their own
section below.

## Ranking

Ranking functions number the rows of each partition in the window's order:

| Function | Ties | Example values |
|---|---|---|
| `row_number()` | broken arbitrarily | 1, 2, 3, 4 |
| `rank()` | same rank, then a gap | 1, 2, 2, 4 |
| `dense_rank()` | same rank, no gap | 1, 2, 2, 3 |
| `percent_rank()` | `(rank - 1) / (rows - 1)`, from 0 to 1 | 0, 0.33, 0.33, 1 |
| `ntile(n)` | splits the rows into `n` nearly equal buckets | 1, 1, 2, 2 |

```sql
SELECT sku, price,
       row_number() OVER w AS row_number,
       rank() OVER w AS rank,
       dense_rank() OVER w AS dense_rank,
       round(percent_rank() OVER w::numeric, 2) AS pct_rank,
       ntile(3) OVER w AS tercile
FROM products
WHERE active
WINDOW w AS (ORDER BY price DESC)
ORDER BY price DESC, sku;
```

```text
  sku   | price  | row_number | rank | dense_rank | pct_rank | tercile
--------+--------+------------+------+------------+----------+---------
 FP-002 | 129.00 |          1 |    1 |          1 |     0.00 |       1
 FP-001 |  48.00 |          2 |    2 |          2 |     0.08 |       1
 PC-002 |  18.50 |          3 |    3 |          3 |     0.17 |       1
 IN-001 |  16.00 |          5 |    4 |          4 |     0.25 |       1
 IN-002 |  16.00 |          4 |    4 |          4 |     0.25 |       1
 NB-001 |  14.00 |          6 |    6 |          5 |     0.42 |       2
 NB-002 |  11.00 |          7 |    7 |          6 |     0.50 |       2
 NB-003 |   9.50 |          8 |    8 |          7 |     0.58 |       2
 LP-001 |   7.99 |          9 |    9 |          8 |     0.67 |       2
 BP-002 |   6.90 |         10 |   10 |          9 |     0.75 |       3
 AC-001 |   5.00 |         11 |   11 |         10 |     0.83 |       3
 BP-001 |   2.50 |         12 |   12 |         11 |     0.92 |       3
 PC-001 |   1.20 |         13 |   13 |         12 |     1.00 |       3
(13 rows)
```

Look at the two inks: they tie on price, so `rank` and `dense_rank` give both 4, while `row_number` numbers them 4
and 5 in an arbitrary order (here `IN-002` got 4, although the output is sorted by SKU). `ntile(3)` split the 13 rows
into buckets of 5, 4 and 4.

The `WINDOW w AS (...)` clause names a window definition so that several functions can share it, which keeps long
queries readable. (The cast to `numeric` is there because `round(x, 2)` needs a `numeric` and `percent_rank` returns a
`double precision`.)

### Top N per group

The single most common use of ranking: the best X per Y. The two most expensive active products in each category:

```sql
WITH ranked AS (
    SELECT cat.name AS category, p.name, p.price,
           row_number() OVER (PARTITION BY p.category_id ORDER BY p.price DESC, p.id) AS rn
    FROM products AS p
    JOIN categories AS cat ON cat.id = p.category_id
    WHERE p.active
)
SELECT category, name, price
FROM ranked
WHERE rn <= 2
ORDER BY category, rn;
```

```text
    category    |          name          | price
----------------+------------------------+--------
 Accessories    | Brass Pen Clip         |   5.00
 Ballpoint pens | Click Ballpoint 3-pack |   6.90
 Ballpoint pens | Everyday Ballpoint     |   2.50
 Fountain pens  | Heron Fountain Pen     | 129.00
 Fountain pens  | Kestrel Fountain Pen   |  48.00
 Ink            | Midnight Blue Ink 50ml |  16.00
 Ink            | Sepia Ink 50ml         |  16.00
 Loose paper    | Copy Paper 500 sheets  |   7.99
 Notebooks      | Dot Grid Notebook A5   |  14.00
 Notebooks      | Lined Notebook A4      |  11.00
 Pencils        | Sketching Pencil Set   |  18.50
 Pencils        | Graphite Pencil HB     |   1.20
(12 rows)
```

Use `row_number()` for exactly N rows per group, `rank()` or `dense_rank()` when ties should all be included. Compare
`DISTINCT ON` (chapter 6), which handles the N = 1 case, and `LATERAL` (chapter 7), which is often faster for top-N per
group when there is a suitable index and few groups.

## Looking at other rows: lag, lead, first_value

`lag(expr, n, default)` returns `expr` from the row `n` rows **before** the current one in the window's order (default
`n` is 1); `lead` looks **after**. They are how you compute differences between consecutive rows. Days between a
customer's orders:

```sql
SELECT customer_id, id AS order_id, created_at::date AS day,
       lag(created_at::date) OVER w AS previous_day,
       created_at::date - lag(created_at::date) OVER w AS days_since_previous
FROM orders
WHERE customer_id IN (1, 2, 3)
WINDOW w AS (PARTITION BY customer_id ORDER BY created_at)
ORDER BY customer_id, created_at;
```

```text
 customer_id | order_id |    day     | previous_day | days_since_previous
-------------+----------+------------+--------------+---------------------
           1 |        1 | 2026-01-06 |              |
           1 |        4 | 2026-02-10 | 2026-01-06   |                  35
           1 |       11 | 2026-04-09 | 2026-02-10   |                  58
           2 |        2 | 2026-01-15 |              |
           2 |        7 | 2026-03-21 | 2026-01-15   |                  65
           3 |        3 | 2026-02-03 |              |
           3 |        9 | 2026-04-04 | 2026-02-03   |                  60
(7 rows)
```

The first order of each customer has no previous row, so `lag` returns `NULL` (or the default, if you pass a third
argument).

Month-over-month revenue change. A CTE first computes the revenue per month; `lag` then reads the previous month's
value on each row:

```sql
WITH monthly AS (
    SELECT date_trunc('month', o.created_at)::date AS month,
           sum(oi.quantity * oi.unit_price) AS revenue
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    WHERE o.status <> 'cancelled'
    GROUP BY month
)
SELECT month, revenue,
       revenue - lag(revenue) OVER (ORDER BY month) AS change,
       round(100 * (revenue / lag(revenue) OVER (ORDER BY month) - 1), 1) AS pct_change
FROM monthly
ORDER BY month;
```

```text
   month    | revenue | change | pct_change
------------+---------+--------+------------
 2026-01-01 |  119.00 |        |
 2026-02-01 |  187.40 |  68.40 |       57.5
 2026-03-01 |  192.75 |   5.35 |        2.9
 2026-04-01 |  231.70 |  38.95 |       20.2
(4 rows)
```

`first_value(expr)` and `last_value(expr)` return the value from the first or last row of the window frame. Each
review compared with the first review of the same product:

```sql
SELECT product_id, id AS review_id, rating,
       first_value(rating) OVER (PARTITION BY product_id ORDER BY created_at) AS first_rating
FROM reviews
WHERE product_id = 1;
```

```text
 product_id | review_id | rating | first_rating
------------+-----------+--------+--------------
          1 |         1 |      5 |            5
          1 |         7 |      4 |            5
(2 rows)
```

`last_value` is a classic trap: with the default frame (up to the current row and its peers), the "last" row of the
frame is the current row itself. To get the last row of the partition, extend the frame to the end:
`last_value(x) OVER (PARTITION BY ... ORDER BY ... ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING)`, or use
`first_value` with the order reversed.

## Window frames

For aggregates and `first_value`/`last_value`/`nth_value`, the **frame** decides which rows of the partition are
included for the current row. Ranking functions and `lag`/`lead` ignore it. A frame clause has the form:

```text
{ ROWS | RANGE | GROUPS } BETWEEN frame_start AND frame_end

frame_start / frame_end:
    UNBOUNDED PRECEDING | n PRECEDING | CURRENT ROW | n FOLLOWING | UNBOUNDED FOLLOWING
```

- `ROWS`: offsets count physical rows. `ROWS BETWEEN 2 PRECEDING AND CURRENT ROW` is the current row and the two
  before it.
- `RANGE`: offsets are distances in the value of the (single) `ORDER BY` column. `RANGE BETWEEN interval '7 days'
  PRECEDING AND CURRENT ROW` is every row whose timestamp is within the last 7 days.
- `GROUPS`: offsets count groups of peers (rows with equal sort values).

The defaults: with `ORDER BY` in the window, `RANGE BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW`; without it, the
whole partition.

### Moving averages

A moving average smooths noisy data. The average of each order and the two orders before it, shop-wide:

```sql
WITH order_totals AS (
    SELECT o.id, o.created_at, sum(oi.quantity * oi.unit_price) AS total
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    GROUP BY o.id
)
SELECT id, created_at::date AS day, total,
       round(avg(total) OVER (ORDER BY created_at ROWS BETWEEN 2 PRECEDING AND CURRENT ROW), 2) AS moving_avg_3
FROM order_totals
ORDER BY created_at;
```

```text
 id |    day     | total  | moving_avg_3
----+------------+--------+--------------
  1 | 2026-01-06 |  80.00 |        80.00
  2 | 2026-01-15 |  39.00 |        59.50
  3 | 2026-02-03 | 145.00 |        88.00
  4 | 2026-02-10 |  42.40 |        75.47
  5 | 2026-02-20 |  18.50 |        68.63
  6 | 2026-03-02 |  72.80 |        44.57
  7 | 2026-03-21 |  39.95 |        43.75
  8 | 2026-03-28 |  80.00 |        64.25
  9 | 2026-04-04 |  35.00 |        51.65
 10 | 2026-04-08 | 129.00 |        81.33
 11 | 2026-04-09 |  25.70 |        63.23
 12 | 2026-04-10 |  42.00 |        65.57
(12 rows)
```

The first two rows average over fewer rows, because there aren't two rows before them.

### Time-based windows with RANGE

A `ROWS` frame counts rows, which is wrong for time series with gaps: "the last 3 orders" may span a day or a month.
For "the last 30 days" use `RANGE` with an interval. Revenue in the 30 days up to and including each order:

```sql
WITH order_totals AS (
    SELECT o.id, o.created_at, sum(oi.quantity * oi.unit_price) AS total
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    WHERE o.status <> 'cancelled'
    GROUP BY o.id
)
SELECT id, created_at::date AS day, total,
       sum(total) OVER (ORDER BY created_at RANGE BETWEEN interval '30 days' PRECEDING AND CURRENT ROW) AS last_30_days
FROM order_totals
ORDER BY created_at;
```

```text
 id |    day     | total  | last_30_days
----+------------+--------+--------------
  1 | 2026-01-06 |  80.00 |        80.00
  2 | 2026-01-15 |  39.00 |       119.00
  3 | 2026-02-03 | 145.00 |       264.00
  4 | 2026-02-10 |  42.40 |       226.40
  6 | 2026-03-02 |  72.80 |       260.20
  7 | 2026-03-21 |  39.95 |       112.75
  8 | 2026-03-28 |  80.00 |       192.75
  9 | 2026-04-04 |  35.00 |       154.95
 10 | 2026-04-08 | 129.00 |       283.95
 11 | 2026-04-09 |  25.70 |       309.65
 12 | 2026-04-10 |  42.00 |       351.65
(11 rows)
```

The frame clause can also exclude rows with `EXCLUDE CURRENT ROW`, `EXCLUDE GROUP` (the current row and its peers) or
`EXCLUDE TIES`, for example to compare each value with the average of the *others*.

## Gaps and islands

A classic problem: find runs of consecutive values ("islands") in a sequence with gaps. Say Inkwell logs which days a
customer visited the site, and you want each streak of consecutive days:

```sql
WITH visits (day) AS (
    VALUES (date '2026-04-01'), ('2026-04-02'), ('2026-04-03'),
           ('2026-04-06'), ('2026-04-07'),
           ('2026-04-10')
),
numbered AS (
    SELECT day, day - row_number() OVER (ORDER BY day)::integer AS island
    FROM visits
)
SELECT min(day) AS streak_start, max(day) AS streak_end, count(*) AS days
FROM numbered
GROUP BY island
ORDER BY streak_start;
```

```text
 streak_start | streak_end | days
--------------+------------+------
 2026-04-01   | 2026-04-03 |    3
 2026-04-06   | 2026-04-07 |    2
 2026-04-10   | 2026-04-10 |    1
(3 rows)
```

The trick: subtract each row's position (`row_number`) from its date. Within a run of consecutive days, both increase
by one per row, so the difference is constant; when a gap occurs, the difference jumps. That constant identifies the
island, and a plain `GROUP BY` does the rest. The same trick finds runs of consecutive ids, consecutive months with
sales, or consecutive failed logins.

## Performance notes

- Each distinct window (partition + order) usually needs the rows sorted that way. Several windows with the same
  definition share one sort; naming the window with `WINDOW` makes that easy to see.
- An index matching `PARTITION BY ... ORDER BY ...` can provide the order without a sort.
- Window functions compute over all rows that reach them. `WHERE` conditions apply first, so filter as early as you
  can, but remember that filtering changes what the window sees: a running total over "orders since April" starts at
  zero in April.

## Key takeaways

- A window function (`function(...) OVER (...)`) computes over a set of related rows and keeps every row.
  `PARTITION BY` splits the rows; `ORDER BY` inside `OVER` orders them within the window.
- Windows run after `WHERE`/`GROUP BY`/`HAVING`. They can use aggregates (`sum(sum(x)) OVER ()`), but can't be used
  in `WHERE`: compute them in a CTE and filter outside.
- With `ORDER BY`, aggregates become cumulative. The default frame includes peers (ties); make the order unique or
  use `ROWS`.
- `row_number`, `rank`, `dense_rank` and `ntile` rank rows; `row_number() ... WHERE rn <= N` gives the top N per group.
- `lag` and `lead` read neighbouring rows: differences, gaps, changes. `last_value` needs an explicit frame.
- Frames (`ROWS`, `RANGE`, `GROUPS`) define moving windows. Use `RANGE` with an interval for time-based windows.
- "Value minus row_number" identifies islands of consecutive values.

## Exercises

1. **Rank within category.** For each active product, show its category name, its price, and its price rank within the
   category (1 = most expensive), using `dense_rank`.

<details>
<summary>Solution</summary>

```sql
SELECT cat.name AS category, p.name, p.price,
       dense_rank() OVER (PARTITION BY p.category_id ORDER BY p.price DESC) AS price_rank
FROM products AS p
JOIN categories AS cat ON cat.id = p.category_id
WHERE p.active
ORDER BY cat.name, price_rank, p.name;
```

```text
    category    |          name          | price  | price_rank
----------------+------------------------+--------+------------
 Accessories    | Brass Pen Clip         |   5.00 |          1
 Ballpoint pens | Click Ballpoint 3-pack |   6.90 |          1
 Ballpoint pens | Everyday Ballpoint     |   2.50 |          2
 Fountain pens  | Heron Fountain Pen     | 129.00 |          1
 Fountain pens  | Kestrel Fountain Pen   |  48.00 |          2
 Ink            | Midnight Blue Ink 50ml |  16.00 |          1
 Ink            | Sepia Ink 50ml         |  16.00 |          1
 Loose paper    | Copy Paper 500 sheets  |   7.99 |          1
 Notebooks      | Dot Grid Notebook A5   |  14.00 |          1
 Notebooks      | Lined Notebook A4      |  11.00 |          2
 Notebooks      | Pocket Notebook 3-pack |   9.50 |          3
 Pencils        | Sketching Pencil Set   |  18.50 |          1
 Pencils        | Graphite Pencil HB     |   1.20 |          2
(13 rows)
```

The two inks share rank 1 because they cost the same.

</details>

2. **Percent of customer spending.** For each order line of customer 1 (ignoring cancelled orders), show the order id,
   product name, line total, and what percentage of the customer's total spending that line represents.

<details>
<summary>Solution</summary>

```sql
SELECT o.id AS order_id, p.name,
       oi.quantity * oi.unit_price AS line_total,
       round(100 * oi.quantity * oi.unit_price / sum(oi.quantity * oi.unit_price) OVER (), 1) AS pct
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
JOIN products AS p ON p.id = oi.product_id
WHERE o.customer_id = 1 AND o.status <> 'cancelled'
ORDER BY pct DESC;
```

```text
 order_id |          name          | line_total | pct
----------+------------------------+------------+------
        1 | Kestrel Fountain Pen   |      48.00 | 32.4
        1 | Midnight Blue Ink 50ml |      32.00 | 21.6
        4 | Dot Grid Notebook A5   |      28.00 | 18.9
       11 | Sketching Pencil Set   |      18.50 | 12.5
        4 | Graphite Pencil HB     |      14.40 |  9.7
       11 | Graphite Pencil HB     |       7.20 |  4.9
(6 rows)
```

`OVER ()` is enough because the `WHERE` clause already restricts the rows to one customer. For several customers at
once, use `OVER (PARTITION BY o.customer_id)`.

</details>

3. **Latest review per product.** Using `row_number`, list the most recent review of each product that has reviews,
   with the product name, rating and date.

<details>
<summary>Solution</summary>

```sql
WITH ranked AS (
    SELECT r.*, row_number() OVER (PARTITION BY r.product_id ORDER BY r.created_at DESC, r.id DESC) AS rn
    FROM reviews AS r
)
SELECT p.name, ranked.rating, ranked.created_at::date AS day
FROM ranked
JOIN products AS p ON p.id = ranked.product_id
WHERE ranked.rn = 1
ORDER BY p.name;
```

```text
          name          | rating |    day
------------------------+--------+------------
 Dot Grid Notebook A5   |      5 | 2026-01-25
 Everyday Ballpoint     |      3 | 2026-01-25
 Heron Fountain Pen     |      5 | 2026-02-15
 Kestrel Fountain Pen   |      4 | 2026-03-10
 Leather Pen Case       |      5 | 2026-04-05
 Lined Notebook A4      |      2 | 2026-03-12
 Midnight Blue Ink 50ml |      4 | 2026-01-21
 Sepia Ink 50ml         |      4 | 2026-02-16
(8 rows)
```

For the Kestrel pen, Emma's March review wins over Ana's from January.

</details>

4. **Gap to the next order.** For every order, show how many hours passed until the next order in the shop (any
   customer). The last order should show `NULL`. (Hint: subtracting two `timestamptz` values gives an `interval`;
   `extract(epoch FROM interval) / 3600` converts it to hours.)

<details>
<summary>Solution</summary>

```sql
SELECT id, created_at,
       round(extract(epoch FROM lead(created_at) OVER (ORDER BY created_at) - created_at) / 3600, 1) AS hours_to_next
FROM orders
ORDER BY created_at;
```

```text
 id |       created_at       | hours_to_next
----+------------------------+---------------
  1 | 2026-01-06 10:00:00+00 |         218.0
  2 | 2026-01-15 12:00:00+00 |         463.5
  3 | 2026-02-03 19:30:00+00 |         157.3
  4 | 2026-02-10 08:45:00+00 |         244.5
  5 | 2026-02-20 13:15:00+00 |         241.8
  6 | 2026-03-02 15:00:00+00 |         450.5
  7 | 2026-03-21 09:30:00+00 |         176.2
  8 | 2026-03-28 17:40:00+00 |         161.5
  9 | 2026-04-04 11:11:00+00 |          91.3
 10 | 2026-04-08 06:30:00+00 |          37.6
 11 | 2026-04-09 20:05:00+00 |          15.9
 12 | 2026-04-10 12:00:00+00 |
(12 rows)
```

`extract(epoch FROM ...)` returns the number of seconds in an interval as a `numeric`, which `round` accepts directly.

</details>

5. **Streaks of order months.** Using the gaps-and-islands technique, find each customer's streaks of consecutive months
   with at least one order. (Hint: number the distinct months per customer, and compute the island key as
   `month - row_number * interval '1 month'`.)

<details>
<summary>Solution</summary>

```sql
WITH months AS (
    SELECT DISTINCT customer_id, date_trunc('month', created_at)::date AS month
    FROM orders
),
numbered AS (
    SELECT customer_id, month,
           month - (row_number() OVER (PARTITION BY customer_id ORDER BY month) * interval '1 month') AS island
    FROM months
)
SELECT customer_id, min(month) AS first_month, max(month) AS last_month, count(*) AS months
FROM numbered
GROUP BY customer_id, island
ORDER BY customer_id, first_month;
```

```text
 customer_id | first_month | last_month | months
-------------+-------------+------------+--------
           1 | 2026-01-01  | 2026-02-01 |      2
           1 | 2026-04-01  | 2026-04-01 |      1
           2 | 2026-01-01  | 2026-01-01 |      1
           2 | 2026-03-01  | 2026-03-01 |      1
           3 | 2026-02-01  | 2026-02-01 |      1
           3 | 2026-04-01  | 2026-04-01 |      1
           4 | 2026-02-01  | 2026-02-01 |      1
           5 | 2026-03-01  | 2026-04-01 |      2
           6 | 2026-03-01  | 2026-03-01 |      1
           8 | 2026-04-01  | 2026-04-01 |      1
(10 rows)
```

Ana ordered in January, February and April: two streaks. Months have different lengths, so the island key must be
computed with month arithmetic (`interval '1 month'`), not by subtracting days, and it works because every value is the
first of a month.

</details>

---
← Previous: [Subqueries and common table expressions](09-subqueries-and-ctes.md) | Next: [Text, dates and times](11-text-dates-and-times.md) →
