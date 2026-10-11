# Chapter 7 — Joins

## What you'll learn

- What a join is, starting from the cross product, and how join conditions select the matching pairs
- Inner joins across several tables, with aliases and qualified column names
- Outer joins (`LEFT`, `RIGHT`, `FULL`), and the classic mistake of filtering an outer join in `WHERE`
- Cross joins and self joins
- Semi-joins with `EXISTS`, and why joins can multiply rows ("fan-out")
- Anti-joins, and why `NOT IN` with a subquery is a trap
- `LATERAL` joins for "top N per row" questions

Relational databases split data into tables so that each fact is stored once: a customer's name lives in `customers`,
not copied into every order. Joins put the pieces back together at query time. Start from the reference data
(chapter 5).

## From cross product to join

The simplest way to combine two tables is to pair **every** row of one with **every** row of the other, the *cross
product*. With 8 customers and 12 orders that is 96 pairs:

```sql
SELECT count(*) FROM customers CROSS JOIN orders;
```

```text
 count
-------
    96
(1 row)
```

Most of those pairs are meaningless: Ana with Ben's order. A **join condition** keeps only the pairs that belong
together, here where the order's `customer_id` equals the customer's `id`:

```sql
SELECT o.id AS order_id, c.full_name, o.status
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
WHERE o.created_at < '2026-02-15'
ORDER BY o.id;
```

```text
 order_id |  full_name   |  status
----------+--------------+-----------
        1 | Ana Souza    | delivered
        2 | Ben Carter   | delivered
        3 | Chloé Martin | delivered
        4 | Ana Souza    | delivered
(4 rows)
```

That is the mental model for every join: conceptually, form all pairs, keep those that satisfy `ON`. (The planner
never actually builds the cross product; chapter 16 shows the clever algorithms it uses instead. The result is the
same.)

- `JOIN` means `INNER JOIN`: only pairs that satisfy the condition appear.
- `orders AS o` gives the table an alias. Once you alias a table, use the alias everywhere in the query.
- `c.id` and `o.customer_id` are **qualified** column names. Qualify every column in a multi-table query, even when the
  name is unique: it tells the reader where each column comes from, and the query won't break when someone adds a
  column with the same name to another table. If you don't, and the name exists in both tables, PostgreSQL refuses:

```sql
SELECT id, full_name FROM orders JOIN customers ON customers.id = orders.customer_id;
```

```text
ERROR:  column reference "id" is ambiguous
LINE 1: SELECT id, full_name FROM orders JOIN customers ON customers...
               ^
```

## Joining several tables

Joins chain. Each order line, with the order, the customer, the product and its category:

```sql
SELECT o.id AS order_id,
       c.full_name AS customer,
       p.name AS product,
       cat.name AS category,
       oi.quantity,
       oi.unit_price,
       oi.quantity * oi.unit_price AS line_total
FROM orders AS o
JOIN customers AS c     ON c.id = o.customer_id
JOIN order_items AS oi  ON oi.order_id = o.id
JOIN products AS p      ON p.id = oi.product_id
JOIN categories AS cat  ON cat.id = p.category_id
WHERE o.customer_id = 1
ORDER BY o.id, p.name;
```

```text
 order_id | customer  |        product         |   category    | quantity | unit_price | line_total
----------+-----------+------------------------+---------------+----------+------------+------------
        1 | Ana Souza | Kestrel Fountain Pen   | Fountain pens |        1 |      48.00 |      48.00
        1 | Ana Souza | Midnight Blue Ink 50ml | Ink           |        2 |      16.00 |      32.00
        4 | Ana Souza | Dot Grid Notebook A5   | Notebooks     |        2 |      14.00 |      28.00
        4 | Ana Souza | Graphite Pencil HB     | Pencils       |       12 |       1.20 |      14.40
       11 | Ana Souza | Graphite Pencil HB     | Pencils       |        6 |       1.20 |       7.20
       11 | Ana Souza | Sketching Pencil Set   | Pencils       |        1 |      18.50 |      18.50
(6 rows)
```

Read the `FROM` clause as a path through the data model: from orders to their customer, to their lines, to each line's
product, to the product's category. Each `JOIN ... ON` adds one step. The order in which you write inner joins doesn't
change the result, and the planner picks its own order for executing them.

### USING and NATURAL JOIN

When the joining columns have the same name in both tables, `USING (column)` is a shorthand for `ON a.column =
b.column`, and the column appears only once in `SELECT *`:

```sql
SELECT order_id, product_id, quantity, status
FROM order_items
JOIN (SELECT id AS order_id, status FROM orders) AS o USING (order_id)
WHERE order_id = 1;
```

```text
 order_id | product_id | quantity |  status
----------+------------+----------+-----------
        1 |          1 |        1 | delivered
        1 |         11 |        2 | delivered
(2 rows)
```

(The parenthesized `SELECT` in the `FROM` clause is a *derived table*, a subquery used as a table, chapter 9.) With
the `<table>_id` naming convention, foreign keys and primary keys have different names (`customer_id` vs `id`), so
`USING` is less useful than it looks. `NATURAL JOIN` goes further and joins on *all* columns with matching names. Avoid
it: add a `created_at` column to two tables and every natural join between them silently changes meaning.

## Outer joins

An inner join drops rows that have no partner. Gus has never ordered, so he can't appear in any inner join with
`orders`. A **left outer join** keeps every row of the left table, and fills the right side with `NULL`s where there is
no match:

```sql
SELECT c.full_name, o.id AS order_id, o.status
FROM customers AS c
LEFT JOIN orders AS o ON o.customer_id = c.id
WHERE c.country IN ('US', 'SE')
ORDER BY c.full_name, o.id;
```

```text
   full_name   | order_id |  status
---------------+----------+-----------
 Ben Carter    |        2 | delivered
 Ben Carter    |        7 | shipped
 Farah Haddad  |        8 | delivered
 Gus Lindqvist |          |
(4 rows)
```

`LEFT JOIN` is short for `LEFT OUTER JOIN`. A `RIGHT JOIN` is the mirror image (keep every row of the right table);
it's rarely used because any right join can be written as a left join with the tables swapped, which reads more
naturally. A `FULL JOIN` keeps unmatched rows from both sides.

### The WHERE-clause trap

Where you put a condition matters for outer joins. Suppose you want every customer, with their **paid** orders if they
have any. Put the condition on `orders` in `WHERE`:

```sql
SELECT c.full_name, o.id AS order_id
FROM customers AS c
LEFT JOIN orders AS o ON o.customer_id = c.id
WHERE o.status = 'paid'
ORDER BY c.full_name;
```

```text
   full_name    | order_id
----------------+----------
 Ana Souza      |       11
 Hana Kobayashi |       10
(2 rows)
```

Most customers vanished. Logically, the `LEFT JOIN` runs first and produces Gus with `NULL` order columns; then
`WHERE o.status = 'paid'` is `NULL` for that row, and it is dropped, along with every customer whose orders are all
unpaid. The `WHERE` condition turned the outer join into an inner join. Conditions on the optional side belong in `ON`:

```sql
SELECT c.full_name, o.id AS order_id
FROM customers AS c
LEFT JOIN orders AS o ON o.customer_id = c.id AND o.status = 'paid'
ORDER BY c.full_name;
```

```text
   full_name    | order_id
----------------+----------
 Ana Souza      |       11
 Ben Carter     |
 Chloé Martin   |
 Dev Patel      |
 Emma Schulz    |
 Farah Haddad   |
 Gus Lindqvist  |
 Hana Kobayashi |       10
(8 rows)
```

Rule of thumb: in a `LEFT JOIN`, conditions about which right-side rows count as a match go in `ON`; conditions about
which left-side rows to return go in `WHERE`.

### FULL JOIN: comparing two lists

`FULL JOIN` is the tool for reconciling two lists: what's in one, the other, or both. Compare a supplier's catalogue
with Inkwell's product list:

```sql
SELECT p.sku AS our_sku, s.sku AS supplier_sku, p.price AS our_price, s.price AS supplier_price
FROM (SELECT sku, price FROM products WHERE category_id = 6) AS p
FULL JOIN (VALUES ('IN-001', 9.50), ('IN-003', 10.00)) AS s (sku, price) ON s.sku = p.sku
ORDER BY coalesce(p.sku, s.sku);
```

```text
 our_sku | supplier_sku | our_price | supplier_price
---------+--------------+-----------+----------------
 IN-001  | IN-001       |     16.00 |           9.50
 IN-002  |              |     16.00 |
         | IN-003       |           |          10.00
(3 rows)
```

`IN-002` is only ours, `IN-003` only the supplier's, and `IN-001` is in both.

## Cross joins

`CROSS JOIN` (the cross product) is occasionally exactly what you want: all combinations. For example, a grid of every
top-level category against every month of the first quarter, to be filled with sales figures later (chapter 8):

```sql
SELECT cat.name, m.month
FROM categories AS cat
CROSS JOIN (VALUES ('2026-01'), ('2026-02'), ('2026-03')) AS m (month)
WHERE cat.parent_id IS NULL
ORDER BY cat.name, m.month;
```

```text
    name     |  month
-------------+---------
 Accessories | 2026-01
 Accessories | 2026-02
 Accessories | 2026-03
 Paper       | 2026-01
 Paper       | 2026-02
 Paper       | 2026-03
 Writing     | 2026-01
 Writing     | 2026-02
 Writing     | 2026-03
(9 rows)
```

Writing `FROM a, b` with a comma is an older spelling of the same cross join, usually followed by join conditions in
`WHERE`. You'll see it in old code. Prefer explicit `JOIN ... ON`: it keeps join conditions next to their tables, and
forgetting a condition is much harder to miss.

## Self joins

A table can be joined to itself, with different aliases for the two roles. Each category with its parent's name:

```sql
SELECT child.name AS category, parent.name AS parent
FROM categories AS child
LEFT JOIN categories AS parent ON parent.id = child.parent_id
ORDER BY child.id;
```

```text
    category    | parent
----------------+---------
 Writing        |
 Pens           | Writing
 Fountain pens  | Pens
 Ballpoint pens | Pens
 Pencils        | Writing
 Ink            | Writing
 Paper          |
 Notebooks      | Paper
 Loose paper    | Paper
 Accessories    |
(10 rows)
```

The `LEFT JOIN` keeps the top-level categories. A self join walks exactly one level of the tree; walking the whole
tree, however deep, needs a recursive query (chapter 9).

## Semi-joins: "has at least one"

Which customers have bought a pen (a fountain pen or a ballpoint)? A join gives the right customers, but with
duplicates, one per matching order line:

```sql
SELECT c.full_name
FROM customers AS c
JOIN orders AS o       ON o.customer_id = c.id
JOIN order_items AS oi ON oi.order_id = o.id
JOIN products AS p     ON p.id = oi.product_id
WHERE p.category_id IN (3, 4)
ORDER BY c.full_name;
```

```text
   full_name
----------------
 Ana Souza
 Ben Carter
 Chloé Martin
 Emma Schulz
 Emma Schulz
 Farah Haddad
 Hana Kobayashi
(7 rows)
```

This is **fan-out**: a join returns one row per matching *pair*. Emma bought a fountain pen and a pack of ballpoints
in the same order, so she appears twice. Adding `DISTINCT` hides the symptom, but the question wasn't "give me all pairs", it was "which customers
have at least one". That is a **semi-join**, and SQL's direct way to say it is `EXISTS`:

```sql
SELECT c.full_name
FROM customers AS c
WHERE EXISTS (
    SELECT 1
    FROM orders AS o
    JOIN order_items AS oi ON oi.order_id = o.id
    JOIN products AS p     ON p.id = oi.product_id
    WHERE o.customer_id = c.id
      AND p.category_id IN (3, 4)
)
ORDER BY c.full_name;
```

```text
   full_name
----------------
 Ana Souza
 Ben Carter
 Chloé Martin
 Emma Schulz
 Farah Haddad
 Hana Kobayashi
(6 rows)
```

`EXISTS (subquery)` is true if the subquery returns at least one row. The subquery refers to `c.id` from the outer
query (it is *correlated*, chapter 9), and what it selects doesn't matter, so `SELECT 1` is the convention. Besides
being correct by construction, `EXISTS` lets the database stop at the first match for each customer instead of finding
them all. `IN (subquery)` expresses the same thing:

```sql
SELECT full_name
FROM customers
WHERE id IN (SELECT o.customer_id
             FROM orders AS o
             JOIN order_items AS oi ON oi.order_id = o.id
             JOIN products AS p ON p.id = oi.product_id
             WHERE p.category_id IN (3, 4))
ORDER BY full_name;
```

### Fan-out breaks sums

Fan-out is harmless with `EXISTS` and `DISTINCT`, but it silently corrupts aggregates. Try to compute, per customer,
the number of orders and the number of reviews in one join:

```sql
SELECT c.full_name, count(o.id) AS orders, count(r.id) AS reviews
FROM customers AS c
LEFT JOIN orders AS o  ON o.customer_id = c.id
LEFT JOIN reviews AS r ON r.customer_id = c.id
GROUP BY c.full_name
ORDER BY c.full_name;
```

```text
   full_name    | orders | reviews
----------------+--------+---------
 Ana Souza      |      6 |       6
 Ben Carter     |      4 |       4
 Chloé Martin   |      4 |       4
 Dev Patel      |      1 |       0
 Emma Schulz    |      4 |       4
 Farah Haddad   |      1 |       1
 Gus Lindqvist  |      0 |       0
 Hana Kobayashi |      1 |       0
(8 rows)
```

Ana has 3 orders and 2 reviews, not 6 and 6. Joining one customer to her 3 orders and, independently, to her 2 reviews
produces 3 × 2 = 6 rows, and both counts see all of them. Whenever you join a parent to **two independent child
tables**, aggregate each child separately first (chapter 8 shows how, with subqueries or `LATERAL`), or count distinct
keys (`count(DISTINCT o.id)`), which works for counts but not for sums.

## Anti-joins: "has none"

Which products have never been ordered? That is an **anti-join**. Two correct ways to write it:

```sql
SELECT p.sku, p.name
FROM products AS p
WHERE NOT EXISTS (SELECT 1 FROM order_items AS oi WHERE oi.product_id = p.id)
ORDER BY p.sku;
```

```text
  sku   |      name
--------+----------------
 AC-001 | Brass Pen Clip
(1 row)
```

```sql
SELECT p.sku, p.name
FROM products AS p
LEFT JOIN order_items AS oi ON oi.product_id = p.id
WHERE oi.product_id IS NULL
ORDER BY p.sku;
```

```text
  sku   |      name
--------+----------------
 AC-001 | Brass Pen Clip
(1 row)
```

The second form uses the outer join's `NULL`-filling on purpose: products with no matching order line get `NULL`s on
the right, and `WHERE ... IS NULL` keeps exactly those. Test a column that can't be `NULL` in a real match, such as
the join column or a primary key. PostgreSQL plans both forms the same way (an "anti join"); `NOT EXISTS` states the
intent more clearly.

### The NOT IN trap

The third obvious way is `NOT IN`. Which categories are leaves, i.e. not the parent of any other category?

```sql
SELECT name
FROM categories
WHERE id NOT IN (SELECT parent_id FROM categories);
```

```text
 name
------
(0 rows)
```

No rows, although most categories are leaves. The subquery returns the `parent_id`s, and three of them (the top-level
categories) are `NULL`. `x NOT IN (1, 2, NULL)` means `x <> 1 AND x <> 2 AND x <> NULL`, and `x <> NULL` is `NULL`, so
the whole condition is never true. **Never use `NOT IN` with a subquery**; a single `NULL` in its result makes it
return nothing, and the column may become nullable long after you wrote the query. `NOT EXISTS` has no such problem:

```sql
SELECT c.name
FROM categories AS c
WHERE NOT EXISTS (SELECT 1 FROM categories AS child WHERE child.parent_id = c.id)
ORDER BY c.name;
```

```text
      name
----------------
 Accessories
 Ballpoint pens
 Fountain pens
 Ink
 Loose paper
 Notebooks
 Pencils
(7 rows)
```

(`NOT IN` with a literal list of non-null values, like `status NOT IN ('cancelled', 'pending')`, is fine.)

## LATERAL joins

A subquery in `FROM` normally can't refer to the other tables in the same `FROM` clause. With `LATERAL` it can: it is
evaluated once for each row of the tables before it, like a loop. That is the natural way to ask "for each X, the top N
Ys". The two most expensive lines of each of Ana's orders:

```sql
SELECT o.id AS order_id, top.name, top.line_total
FROM orders AS o
CROSS JOIN LATERAL (
    SELECT p.name, oi.quantity * oi.unit_price AS line_total
    FROM order_items AS oi
    JOIN products AS p ON p.id = oi.product_id
    WHERE oi.order_id = o.id
    ORDER BY line_total DESC
    LIMIT 2
) AS top
WHERE o.customer_id = 1
ORDER BY o.id, top.line_total DESC;
```

```text
 order_id |          name          | line_total
----------+------------------------+------------
        1 | Kestrel Fountain Pen   |      48.00
        1 | Midnight Blue Ink 50ml |      32.00
        4 | Dot Grid Notebook A5   |      28.00
        4 | Graphite Pencil HB     |      14.40
       11 | Sketching Pencil Set   |      18.50
       11 | Graphite Pencil HB     |       7.20
(6 rows)
```

`CROSS JOIN LATERAL` drops outer rows for which the subquery returns nothing; `LEFT JOIN LATERAL (...) AS x ON true`
keeps them, with `NULL`s. `LATERAL` is also the clean fix for the fan-out problem above: aggregate each child table in
its own lateral subquery. Chapter 10 shows the alternative with window functions.

## Key takeaways

- A join pairs rows from two tables and keeps the pairs that satisfy the `ON` condition. Think "cross product, then
  filter"; the planner does it far more cleverly.
- Alias tables and qualify every column in multi-table queries. Avoid `NATURAL JOIN`.
- `LEFT JOIN` keeps unmatched left rows with `NULL`s on the right. Conditions on the right table belong in `ON`; in
  `WHERE` they turn the outer join into an inner join.
- `FULL JOIN` reconciles two lists; `CROSS JOIN` builds all combinations; a self join compares rows within one table.
- Joins fan out: one row per matching pair. Use `EXISTS` for "has at least one", don't reach for `DISTINCT`, and never
  aggregate across two independent child tables in one join.
- Use `NOT EXISTS` (or `LEFT JOIN ... WHERE key IS NULL`) for "has none". Never use `NOT IN` with a subquery that might
  return `NULL`.
- `LATERAL` lets a subquery in `FROM` refer to earlier tables: the tool for "top N per group".

## Exercises

1. **Order summary.** List every order with its id, the customer's full name and country, the status, and the number of
   lines in the order, sorted by order id. (You may peek at chapter 8 for `count(*)` and `GROUP BY`, or count the lines
   with a `LATERAL` subquery.)

<details>
<summary>Solution</summary>

```sql
SELECT o.id, c.full_name, c.country, o.status, lines.n AS lines
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
CROSS JOIN LATERAL (SELECT count(*) AS n FROM order_items AS oi WHERE oi.order_id = o.id) AS lines
ORDER BY o.id;
```

```text
 id |   full_name    | country |  status   | lines
----+----------------+---------+-----------+-------
  1 | Ana Souza      | BR      | delivered |     2
  2 | Ben Carter     | US      | delivered |     2
  3 | Chloé Martin   | FR      | delivered |     2
  4 | Ana Souza      | BR      | delivered |     2
  5 | Dev Patel      | GB      | cancelled |     1
  6 | Emma Schulz    | DE      | delivered |     3
  7 | Ben Carter     | US      | shipped   |     1
  8 | Farah Haddad   | US      | delivered |     2
  9 | Chloé Martin   | FR      | shipped   |     2
 10 | Hana Kobayashi | JP      | paid      |     1
 11 | Ana Souza      | BR      | paid      |     2
 12 | Emma Schulz    | DE      | pending   |     1
(12 rows)
```

An aggregate without `GROUP BY` always returns exactly one row, so the lateral subquery produces a row even for an
order without lines (with `n` = 0), and `CROSS JOIN LATERAL` never drops an order here. The cancelled order 5 shows one
line, because the reset restored it.

</details>

2. **Who reviewed what they bought?** List each review with the reviewer's name, the product name and the rating, plus a
   column `verified` that is true when the reviewer has actually ordered that product (in an order that wasn't
   cancelled).

<details>
<summary>Solution</summary>

```sql
SELECT c.full_name, p.name AS product, r.rating,
       EXISTS (SELECT 1
               FROM orders AS o
               JOIN order_items AS oi ON oi.order_id = o.id
               WHERE o.customer_id = r.customer_id
                 AND oi.product_id = r.product_id
                 AND o.status <> 'cancelled') AS verified
FROM reviews AS r
JOIN customers AS c ON c.id = r.customer_id
JOIN products AS p  ON p.id = r.product_id
ORDER BY r.id;
```

```text
  full_name   |        product         | rating | verified
--------------+------------------------+--------+----------
 Ana Souza    | Kestrel Fountain Pen   |      5 | t
 Ana Souza    | Midnight Blue Ink 50ml |      4 | t
 Ben Carter   | Dot Grid Notebook A5   |      5 | t
 Ben Carter   | Everyday Ballpoint     |      3 | t
 Chloé Martin | Heron Fountain Pen     |      5 | t
 Chloé Martin | Sepia Ink 50ml         |      4 | t
 Emma Schulz  | Kestrel Fountain Pen   |      4 | t
 Emma Schulz  | Lined Notebook A4      |      2 | t
 Farah Haddad | Leather Pen Case       |      5 | t
(9 rows)
```

`EXISTS` is an ordinary boolean expression, so it works in the `SELECT` list too. Every review in the sample data is
verified: Inkwell only lets customers review what they bought, and the query confirms that the data agrees. A rule
like that can't be a `CHECK` constraint, because it involves other tables; chapter 19 shows how a trigger can enforce
it.

</details>

3. **Customers without a review.** List the customers who have placed at least one order but never written a review.
   Write it once with `NOT EXISTS` and once with `LEFT JOIN`.

<details>
<summary>Solution</summary>

```sql
SELECT c.full_name
FROM customers AS c
WHERE EXISTS (SELECT 1 FROM orders AS o WHERE o.customer_id = c.id)
  AND NOT EXISTS (SELECT 1 FROM reviews AS r WHERE r.customer_id = c.id)
ORDER BY c.full_name;
```

```text
   full_name
----------------
 Dev Patel
 Hana Kobayashi
(2 rows)
```

```sql
SELECT DISTINCT c.full_name
FROM customers AS c
JOIN orders AS o ON o.customer_id = c.id
LEFT JOIN reviews AS r ON r.customer_id = c.id
WHERE r.id IS NULL
ORDER BY c.full_name;
```

The second version needs `DISTINCT` because the join with `orders` fans out: Dev and Hana happen to have one order
each, but a customer with several orders would appear several times. That is a good illustration of why the `EXISTS` form is
usually clearer.

</details>

4. **Category paths.** Using two self joins, show each fountain pen and ballpoint pen product with its full category
   path, like `Writing > Pens > Fountain pens`.

<details>
<summary>Solution</summary>

```sql
SELECT p.name,
       concat_ws(' > ', grandparent.name, parent.name, cat.name) AS path
FROM products AS p
JOIN categories AS cat ON cat.id = p.category_id
LEFT JOIN categories AS parent ON parent.id = cat.parent_id
LEFT JOIN categories AS grandparent ON grandparent.id = parent.parent_id
WHERE cat.slug IN ('fountain-pens', 'ballpoint-pens')
ORDER BY path, p.name;
```

```text
          name          |              path
------------------------+---------------------------------
 Click Ballpoint 3-pack | Writing > Pens > Ballpoint pens
 Everyday Ballpoint     | Writing > Pens > Ballpoint pens
 Heron Fountain Pen     | Writing > Pens > Fountain pens
 Kestrel Fountain Pen   | Writing > Pens > Fountain pens
(4 rows)
```

`concat_ws` skips `NULL`s, so the same expression works for shallower categories. But the number of self joins fixes
the maximum depth: a fourth level would need a third join. Chapter 9 solves this for any depth with a recursive CTE.

</details>

5. **Spot the bug.** A colleague wants every product with its number of 5-star reviews, including products with none,
   and writes:

   ```sql
   SELECT p.name, count(r.id) AS five_star
   FROM products AS p
   LEFT JOIN reviews AS r ON r.product_id = p.id
   WHERE r.rating = 5
   GROUP BY p.name;
   ```

   What's wrong, and how do you fix it?

<details>
<summary>Solution</summary>

`WHERE r.rating = 5` drops all products without a 5-star review (including those without any review), turning the
left join into an inner join; the query returns only 4 products. Move the condition into the join:

```sql
SELECT p.name, count(r.id) AS five_star
FROM products AS p
LEFT JOIN reviews AS r ON r.product_id = p.id AND r.rating = 5
GROUP BY p.name
ORDER BY five_star DESC, p.name;
```

Now all 14 products appear, most with 0. `count(r.id)` counts only non-null values, so a product with no matching
review counts 0, while `count(*)` would count the one `NULL`-filled row and wrongly give 1 (chapter 8).

</details>

---
← Previous: [Querying with SELECT](06-querying-with-select.md) | Next: [Aggregation and grouping](08-aggregation-and-grouping.md) →
