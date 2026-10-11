# Chapter 6 — Querying with SELECT

## What you'll learn

- The parts of a `SELECT` statement and the order in which PostgreSQL logically evaluates them
- Choosing and computing columns, and naming them with aliases
- Filtering with `WHERE`: comparisons, `AND`/`OR`/`NOT`, `BETWEEN`, `IN`, `LIKE` and `ILIKE`
- Three-valued logic: how `NULL` behaves in conditions, and `coalesce`, `nullif` and `IS DISTINCT FROM`
- Sorting with `ORDER BY`, including where `NULL`s go and why ties need a tie-breaker
- Paging with `LIMIT`, `OFFSET` and `FETCH FIRST ... WITH TIES`, and why deep `OFFSET`s are slow
- Removing duplicates with `DISTINCT` and PostgreSQL's `DISTINCT ON`
- Conditional values with `CASE`

`SELECT` is most of what you'll write. This chapter covers querying a single table; the next ones add joins,
aggregation, subqueries and window functions. Start from the reference data (chapter 5), connected to `inkwell`.

## The shape of a SELECT

```sql
SELECT   columns and expressions         -- what to return
FROM     tables                          -- where rows come from
WHERE    condition                       -- which rows to keep
GROUP BY expressions                     -- how to group them (chapter 8)
HAVING   condition                       -- which groups to keep (chapter 8)
ORDER BY expressions                     -- how to sort the result
LIMIT    n OFFSET m;                     -- how many rows to return
```

Every clause except `SELECT` is optional. You can even leave out `FROM` and use `SELECT` as a calculator, as chapter 1
did.

### The logical order of evaluation

You write the clauses in the order above, but PostgreSQL evaluates them, logically, in a different order:

```text
 1. FROM       build the set of input rows
 2. WHERE      drop rows that don't match
 3. GROUP BY   collapse rows into groups
 4. HAVING     drop groups that don't match
 5. SELECT     compute the output columns
 6. DISTINCT   remove duplicate output rows
 7. ORDER BY   sort
 8. LIMIT      cut
```

("Logically" because the planner is free to do things in any order that gives the same result; but the result is
always *as if* this order were followed.) This order explains rules that otherwise seem arbitrary. For example, a
column alias defined in `SELECT` doesn't exist yet when `WHERE` runs, but it does when `ORDER BY` runs:

```sql
SELECT name, price * 1.2 AS gross
FROM products
WHERE gross > 50;
```

```text
ERROR:  column "gross" does not exist
LINE 3: WHERE gross > 50;
              ^
```

```sql
SELECT name, price * 1.2 AS gross
FROM products
WHERE price * 1.2 > 50
ORDER BY gross DESC;
```

```text
         name         |  gross
----------------------+---------
 Heron Fountain Pen   | 154.800
 Kestrel Fountain Pen |  57.600
(2 rows)
```

## Choosing columns

`SELECT *` returns every column. It's fine at the `psql` prompt, but avoid it in application code and views: you fetch
data you don't need, and when someone adds a column, the query's result changes shape under you. Name the columns.

Expressions can compute anything, and `AS` names the result. Without a name, PostgreSQL makes one up (often the
unhelpful `?column?`):

```sql
SELECT sku,
       upper(name) AS shouting,
       price,
       round(price / 1.2, 2) AS net_of_vat,
       stock * price AS stock_value,
       stock > 0 AS in_stock
FROM products
WHERE category_id = 3;
```

```text
  sku   |       shouting       | price  | net_of_vat | stock_value | in_stock
--------+----------------------+--------+------------+-------------+----------
 FP-001 | KESTREL FOUNTAIN PEN |  48.00 |      40.00 |      576.00 | t
 FP-002 | HERON FOUNTAIN PEN   | 129.00 |     107.50 |      387.00 | t
(2 rows)
```

Aliases follow the same rules as other identifiers: unquoted, they are folded to lower case; quote them
(`AS "Stock value"`) if you want spaces or capitals in the output, e.g. for a report.

Tables can have aliases too: `FROM products AS p` lets you write `p.price`. They become essential once a query
involves several tables (chapter 7), and many people use them always.

## Filtering with WHERE

`WHERE` keeps only the rows for which the condition is **true**. Not false, and not `NULL`; this distinction matters
and gets its own section below.

### Comparisons and logic

The comparison operators are `=`, `<>` (or `!=`), `<`, `<=`, `>`, `>=`. Combine conditions with `AND`, `OR` and `NOT`.
`AND` binds more tightly than `OR`, so use parentheses whenever you mix them:

```sql
-- Active fountain pens or anything under 2.00. Without the parentheses,
-- "active AND category_id = 3 OR price < 2" would mean something else.
SELECT sku, name, price
FROM products
WHERE active AND (category_id = 3 OR price < 2)
ORDER BY price;
```

```text
  sku   |         name         | price
--------+----------------------+--------
 PC-001 | Graphite Pencil HB   |   1.20
 FP-001 | Kestrel Fountain Pen |  48.00
 FP-002 | Heron Fountain Pen   | 129.00
(3 rows)
```

`WHERE active` works because `active` is a boolean; you don't need `= true`.

### BETWEEN and IN

`BETWEEN a AND b` is shorthand for `>= a AND <= b`, both ends included. `IN (list)` tests membership:

```sql
SELECT sku, name, price
FROM products
WHERE price BETWEEN 10 AND 20
  AND category_id IN (6, 8)
ORDER BY sku;
```

```text
  sku   |          name          | price
--------+------------------------+-------
 IN-001 | Midnight Blue Ink 50ml | 16.00
 IN-002 | Sepia Ink 50ml         | 16.00
 NB-001 | Dot Grid Notebook A5   | 14.00
 NB-002 | Lined Notebook A4      | 11.00
(4 rows)
```

Be careful with `BETWEEN` on timestamps. `created_at BETWEEN '2026-03-01' AND '2026-03-31'` includes March 31st only
up to midnight at its *start*, so it misses almost the whole last day. For time ranges, always use a half-open
interval, inclusive at the start and exclusive at the end:

```sql
SELECT id, customer_id, created_at
FROM orders
WHERE created_at >= '2026-03-01' AND created_at < '2026-04-01'
ORDER BY created_at;
```

```text
 id | customer_id |       created_at
----+-------------+------------------------
  6 |           5 | 2026-03-02 15:00:00+00
  7 |           2 | 2026-03-21 09:30:00+00
  8 |           6 | 2026-03-28 17:40:00+00
(3 rows)
```

That pattern works for any granularity (a day, a month, a year) and never double-counts a row that falls exactly on a
boundary.

### Pattern matching: LIKE and ILIKE

`LIKE` matches a string against a pattern in which `%` matches any sequence of characters (including none) and `_`
matches exactly one character. `ILIKE` is PostgreSQL's case-insensitive variant:

```sql
SELECT sku, name
FROM products
WHERE name ILIKE '%ink%'
ORDER BY sku;
```

```text
  sku   |          name
--------+------------------------
 IN-001 | Midnight Blue Ink 50ml
 IN-002 | Sepia Ink 50ml
(2 rows)
```

To match a literal `%` or `_`, escape it with a backslash: `LIKE '100\%'`. PostgreSQL also has regular expressions
(`~`, `~*`) and `SIMILAR TO`; chapter 11 covers them. For searching words in text, full-text search (chapter 13) is
both more powerful and much faster than `ILIKE '%word%'`, which can't use an ordinary index.

## NULL and three-valued logic

SQL conditions have three possible values: **true**, **false** and **null** (unknown). Comparing anything with `NULL`
gives `NULL`, and `WHERE` keeps only rows where the condition is true. The consequence surprises everyone at least
once. Look at the orders' `shipped_at` column:

```sql
SELECT count(*) AS all_orders,
       count(*) FILTER (WHERE shipped_at < '2026-03-01') AS shipped_before_march,
       count(*) FILTER (WHERE NOT (shipped_at < '2026-03-01')) AS not_shipped_before_march
FROM orders;
```

```text
 all_orders | shipped_before_march | not_shipped_before_march
------------+----------------------+--------------------------
         12 |                    4 |                        4
(1 row)
```

(`count(*) FILTER (WHERE ...)` counts the rows that match a condition; chapter 8 covers it.) Four plus four is not
twelve. The four unshipped orders have `shipped_at` `NULL`, so both conditions are `NULL` for them, and neither count
includes them. A condition and its negation don't together cover all rows when `NULL`s are involved.

The truth tables for `AND` and `OR` follow from "`NULL` means unknown":

| a | b | a AND b | a OR b |
|---|---|---|---|
| true | null | null | true |
| false | null | false | null |
| null | null | null | null |

`NOT null` is `null`. `false AND anything` is false, and `true OR anything` is true, because the unknown value can't
change the result.

### Testing for NULL

`= NULL` is never true. Use `IS NULL` and `IS NOT NULL`:

```sql
SELECT id, status FROM orders WHERE shipped_at = NULL;
```

```text
 id | status
----+--------
(0 rows)
```

```sql
SELECT id, status FROM orders WHERE shipped_at IS NULL ORDER BY id;
```

```text
 id |  status
----+-----------
  5 | cancelled
 10 | paid
 11 | paid
 12 | pending
(4 rows)
```

The first query isn't an error, which is what makes the mistake dangerous: it silently returns nothing.

### IS DISTINCT FROM

`a <> b` is `NULL` when either side is `NULL`. `a IS DISTINCT FROM b` treats `NULL` as an ordinary, comparable value:
two `NULL`s are not distinct, and `NULL` is distinct from any other value. It is what you want when comparing "old and
new" values to detect changes, as in the upsert of chapter 5:

```sql
SELECT 1 <> NULL AS ne, 1 IS DISTINCT FROM NULL AS distinct_1,
       NULL::int IS DISTINCT FROM NULL AS distinct_2;
```

```text
 ne | distinct_1 | distinct_2
----+------------+------------
    | t          | f
(1 row)
```

### coalesce and nullif

`coalesce(a, b, ...)` returns the first argument that isn't `NULL`. It is the standard way to supply a fallback:

```sql
SELECT id, rating, coalesce(body, '(no text)') AS body
FROM reviews
WHERE product_id = 3;
```

```text
 id | rating |   body
----+--------+-----------
  4 |      3 | (no text)
(1 row)
```

`nullif(a, b)` does the opposite: it returns `NULL` if `a = b`, else `a`. Its classic use is avoiding division by
zero, since dividing by `NULL` gives `NULL` instead of an error:

```sql
SELECT 100 / nullif(0, 0) AS safe_division;
```

```text
 safe_division
---------------

(1 row)
```

## Sorting with ORDER BY

`ORDER BY` takes one or more expressions, each optionally followed by `ASC` (the default) or `DESC`. Later keys break
ties in earlier ones:

```sql
SELECT category_id, sku, price
FROM products
WHERE active
ORDER BY category_id, price DESC;
```

```text
 category_id |  sku   | price
-------------+--------+--------
           3 | FP-002 | 129.00
           3 | FP-001 |  48.00
           4 | BP-002 |   6.90
           4 | BP-001 |   2.50
           5 | PC-002 |  18.50
           5 | PC-001 |   1.20
           6 | IN-002 |  16.00
           6 | IN-001 |  16.00
           8 | NB-001 |  14.00
           8 | NB-002 |  11.00
           8 | NB-003 |   9.50
           9 | LP-001 |   7.99
          10 | AC-001 |   5.00
(13 rows)
```

You can sort by an output alias (`ORDER BY gross`), by an expression that isn't in the output, or by column position
(`ORDER BY 2`). Position numbers break silently when someone edits the column list, so use names.

### Where NULLs go

`NULL` sorts as if it were larger than every other value: last in ascending order, first in descending order. Override
it with `NULLS FIRST` or `NULLS LAST`:

```sql
SELECT id, shipped_at
FROM orders
ORDER BY shipped_at DESC NULLS LAST
LIMIT 5;
```

```text
 id |       shipped_at
----+------------------------
  9 | 2026-04-06 13:00:00+00
  8 | 2026-03-30 09:10:00+00
  7 | 2026-03-22 16:45:00+00
  6 | 2026-03-03 11:20:00+00
  4 | 2026-02-11 14:00:00+00
(5 rows)
```

Without `NULLS LAST`, the "most recently shipped" list would start with the four unshipped orders.

### Ties and stable results

When rows have equal sort keys, their order among each other is **undefined** and can change from one execution to the
next. Two products cost 16.00:

```sql
SELECT sku, name, price FROM products ORDER BY price DESC LIMIT 6;
```

```text
  sku   |          name          | price
--------+------------------------+--------
 FP-002 | Heron Fountain Pen     | 129.00
 FP-001 | Kestrel Fountain Pen   |  48.00
 AC-002 | Leather Pen Case       |  35.00
 PC-002 | Sketching Pencil Set   |  18.50
 IN-001 | Midnight Blue Ink 50ml |  16.00
 IN-002 | Sepia Ink 50ml         |  16.00
(6 rows)
```

Whether `IN-001` or `IN-002` comes first is not guaranteed, and with `LIMIT 5` it would be undefined which of them is
included at all. That matters for paging: page 2 may repeat a row from page 1 and skip another. Whenever the order
matters, end the `ORDER BY` with a unique column (`ORDER BY price DESC, id`).

### Text sorts by collation

Text is sorted according to its collation, the language rules for ordering strings. The Inkwell database uses ICU's
English rules (chapter 5), in which case differences matter only as a tie-breaker:

```sql
SELECT word FROM (VALUES ('banana'), ('Apple'), ('apple'), ('Cherry'), ('éclair')) AS t (word)
ORDER BY word;
```

```text
  word
--------
 apple
 Apple
 banana
 Cherry
 éclair
(5 rows)
```

A database created with the `C` collation would sort by byte value instead: `Apple`, `Cherry`, `apple`, `banana`,
`éclair` (all capitals before all lower-case letters, and accented letters last). Chapter 11 covers collations in
detail. `VALUES` here is used as a small inline table, with the alias `t (word)` naming it and its column.

## LIMIT, OFFSET and FETCH

`LIMIT n` returns at most `n` rows; `OFFSET m` skips the first `m`. Together they give simple paging:

```sql
SELECT id, full_name FROM customers ORDER BY id LIMIT 3 OFFSET 3;
```

```text
 id |  full_name
----+--------------
  4 | Dev Patel
  5 | Emma Schulz
  6 | Farah Haddad
(3 rows)
```

`LIMIT` without `ORDER BY` gives you *some* rows, not the first ones in any meaningful sense.

The SQL-standard spelling is `OFFSET 3 ROWS FETCH FIRST 3 ROWS ONLY`. It has one feature `LIMIT` lacks, `WITH TIES`,
which includes every row that ties with the last one:

```sql
SELECT sku, price
FROM products
ORDER BY price DESC
FETCH FIRST 5 ROWS WITH TIES;
```

```text
  sku   | price
--------+--------
 FP-002 | 129.00
 FP-001 |  48.00
 AC-002 |  35.00
 PC-002 |  18.50
 IN-001 |  16.00
 IN-002 |  16.00
(6 rows)
```

Five rows were asked for, six came back: the fifth and sixth both cost 16.00, and neither is "more" fifth than the
other.

### Why deep OFFSETs are slow

`OFFSET 100000` doesn't jump anywhere. The database produces the first 100,000 rows in order and throws them away, so
page 5,000 of a listing costs far more than page 1. For deep paging, use **keyset pagination** instead: remember the
sort key of the last row shown, and ask for rows after it:

```sql
-- First page:
SELECT id, full_name FROM customers ORDER BY id LIMIT 3;
-- Next page, after the last id shown (3):
SELECT id, full_name FROM customers WHERE id > 3 ORDER BY id LIMIT 3;
```

```text
 id |  full_name
----+--------------
  1 | Ana Souza
  2 | Ben Carter
  3 | Chloé Martin
(3 rows)

 id |  full_name
----+--------------
  4 | Dev Patel
  5 | Emma Schulz
  6 | Farah Haddad
(3 rows)
```

With an index on the sort key, every page is equally fast, and rows inserted meanwhile don't shift the pages. Chapter
22 shows keyset pagination with multi-column sort keys from an application.

## DISTINCT and DISTINCT ON

`DISTINCT` removes duplicate rows from the result:

```sql
SELECT DISTINCT country FROM customers ORDER BY country;
```

```text
 country
---------
 BR
 DE
 FR
 GB
 JP
 SE
 US
(7 rows)
```

`DISTINCT` applies to the whole output row, not just the first column: `SELECT DISTINCT country, full_name` returns
every customer. Reaching for `DISTINCT` to make duplicates "go away" is often a sign that a join is wrong (chapter 7);
find out where the duplicates come from first.

PostgreSQL's `DISTINCT ON (expressions)` keeps only the **first row of each group** of rows that have the same values
for the expressions, where "first" is decided by `ORDER BY`. It answers "the latest X per Y" questions very concisely.
The most recent order of each customer:

```sql
SELECT DISTINCT ON (customer_id) customer_id, id AS order_id, created_at, status
FROM orders
ORDER BY customer_id, created_at DESC;
```

```text
 customer_id | order_id |       created_at       |  status
-------------+----------+------------------------+-----------
           1 |       11 | 2026-04-09 20:05:00+00 | paid
           2 |        7 | 2026-03-21 09:30:00+00 | shipped
           3 |        9 | 2026-04-04 11:11:00+00 | shipped
           4 |        5 | 2026-02-20 13:15:00+00 | cancelled
           5 |       12 | 2026-04-10 12:00:00+00 | pending
           6 |        8 | 2026-03-28 17:40:00+00 | delivered
           8 |       10 | 2026-04-08 06:30:00+00 | paid
(7 rows)
```

The `ORDER BY` must start with the `DISTINCT ON` expressions; the rest of it decides which row of each group wins.
Chapter 10 shows the portable alternative with window functions.

## CASE: conditional values

`CASE` chooses a value based on conditions, like `if`/`else if`/`else` in a programming language. It is an
expression, so it works anywhere a value does:

```sql
SELECT sku,
       stock,
       CASE
           WHEN stock = 0 THEN 'sold out'
           WHEN stock < 20 THEN 'low'
           ELSE 'ok'
       END AS stock_level
FROM products
WHERE active
ORDER BY stock;
```

```text
  sku   | stock | stock_level
--------+-------+-------------
 NB-003 |     0 | sold out
 FP-002 |     3 | low
 FP-001 |    12 | low
 IN-002 |    25 | ok
 IN-001 |    30 | ok
 PC-002 |    40 | ok
 NB-002 |    60 | ok
 NB-001 |    75 | ok
 AC-001 |    90 | ok
 BP-002 |   150 | ok
 LP-001 |   200 | ok
 BP-001 |   400 | ok
 PC-001 |   800 | ok
(13 rows)
```

The conditions are tried in order and the first true one wins. Without an `ELSE`, unmatched rows get `NULL`. The short
form compares one expression with several values:

```sql
SELECT id,
       CASE status
           WHEN 'pending' THEN 'Waiting for payment'
           WHEN 'paid' THEN 'Being packed'
           WHEN 'shipped' THEN 'On its way'
           WHEN 'delivered' THEN 'Delivered'
           ELSE 'Cancelled'
       END AS customer_message
FROM orders
WHERE customer_id = 1
ORDER BY id;
```

```text
 id | customer_message
----+------------------
  1 | Delivered
  4 | Delivered
 11 | Being packed
(3 rows)
```

`CASE` in `ORDER BY` gives custom sort orders, for example putting sold-out products last:
`ORDER BY stock = 0, name`. (Sorting by a boolean puts `false` before `true`.)

## A few everyday functions

The full function list is huge; chapter 11 covers text and dates in depth. These you'll use constantly:

| Function | Example | Result |
|---|---|---|
| `round(x, n)` | `round(2.345, 2)` | `2.35` |
| `abs(x)`, `ceil(x)`, `floor(x)` | `ceil(2.1)` | `3` |
| `greatest(...)`, `least(...)` | `greatest(3, 7, 5)` | `7` (ignores `NULL`s) |
| `lower(s)`, `upper(s)`, `length(s)` | `length('Inkwell')` | `7` |
| `concat_ws(sep, ...)` | `concat_ws(', ', 'a', NULL, 'b')` | `a, b` (skips `NULL`s) |
| `left(s, n)`, `right(s, n)` | `left('FP-001', 2)` | `FP` |
| `now()`, `current_date` | | the current timestamp / date |

## Putting it together

A product listing for the shop's front page: active products in stock, labelled with a friendly price band, cheapest
first, with a short teaser of the description:

```sql
SELECT CASE
           WHEN price < 10 THEN 'under 10'
           WHEN price < 50 THEN '10 to 50'
           ELSE '50 and up'
       END AS price_band,
       name,
       price,
       left(description, 30) || '…' AS teaser
FROM products
WHERE active AND stock > 0
ORDER BY price, id;
```

```text
 price_band |          name          | price  |             teaser
------------+------------------------+--------+---------------------------------
 under 10   | Graphite Pencil HB     |   1.20 | A classic HB graphite pencil f…
 under 10   | Everyday Ballpoint     |   2.50 | A dependable disposable ballpo…
 under 10   | Brass Pen Clip         |   5.00 | A solid brass clip that fits m…
 under 10   | Click Ballpoint 3-pack |   6.90 | Three refillable retractable b…
 under 10   | Copy Paper 500 sheets  |   7.99 | A ream of 80 gsm A4 paper for …
 10 to 50   | Lined Notebook A4      |  11.00 | A4 notebook with 160 lined pag…
 10 to 50   | Dot Grid Notebook A5   |  14.00 | A5 notebook with 192 pages of …
 10 to 50   | Midnight Blue Ink 50ml |  16.00 | Deep blue fountain pen ink wit…
 10 to 50   | Sepia Ink 50ml         |  16.00 | Warm brown fountain pen ink wi…
 10 to 50   | Sketching Pencil Set   |  18.50 | Five graphite pencils from 2H …
 10 to 50   | Kestrel Fountain Pen   |  48.00 | A light resin fountain pen wit…
 50 and up  | Heron Fountain Pen     | 129.00 | A weighty brass fountain pen w…
(12 rows)
```

Since the bands are defined by price, sorting by price already puts them in order. When the sort key isn't directly
available, sorting by a `CASE` expression is the general tool.

## Key takeaways

- Clauses are evaluated logically in the order FROM, WHERE, GROUP BY, HAVING, SELECT, DISTINCT, ORDER BY, LIMIT. That
  is why `WHERE` can't use a `SELECT` alias and `ORDER BY` can.
- Name your columns instead of `SELECT *` in code. Use parentheses when mixing `AND` and `OR`.
- Use half-open intervals (`>= start AND < end`) for time ranges, not `BETWEEN`.
- `WHERE` keeps rows where the condition is true; `NULL` conditions drop rows. Test with `IS NULL`, compare
  null-safely with `IS DISTINCT FROM`, and substitute with `coalesce`.
- Without `ORDER BY`, order is undefined. With ties, the order of tied rows is undefined too: add a unique tie-breaker.
  `NULLS FIRST`/`NULLS LAST` control where `NULL`s go.
- `LIMIT`/`OFFSET` is fine for shallow paging; use keyset pagination (`WHERE key > last_seen`) for deep paging.
- `DISTINCT ON (...)` with a matching `ORDER BY` returns the first row per group.
- `CASE` is an expression: use it in `SELECT`, `WHERE` and `ORDER BY`.

## Exercises

1. **Cheap and plentiful.** List the SKU, name, price and stock of active products that cost less than 10 and have at
   least 100 in stock, most stock first.

<details>
<summary>Solution</summary>

```sql
SELECT sku, name, price, stock
FROM products
WHERE active AND price < 10 AND stock >= 100
ORDER BY stock DESC;
```

```text
  sku   |          name          | price | stock
--------+------------------------+-------+-------
 PC-001 | Graphite Pencil HB     |  1.20 |   800
 BP-001 | Everyday Ballpoint     |  2.50 |   400
 LP-001 | Copy Paper 500 sheets  |  7.99 |   200
 BP-002 | Click Ballpoint 3-pack |  6.90 |   150
(4 rows)
```

</details>

2. **Orders in February.** List the ids, statuses and creation times of all orders placed in February 2026, using a
   half-open interval. Then explain why `created_at BETWEEN '2026-02-01' AND '2026-02-28'` would be wrong.

<details>
<summary>Solution</summary>

```sql
SELECT id, status, created_at
FROM orders
WHERE created_at >= '2026-02-01' AND created_at < '2026-03-01'
ORDER BY created_at;
```

```text
 id |  status   |       created_at
----+-----------+------------------------
  3 | delivered | 2026-02-03 19:30:00+00
  4 | delivered | 2026-02-10 08:45:00+00
  5 | cancelled | 2026-02-20 13:15:00+00
(3 rows)
```

`'2026-02-28'` converted to a `timestamptz` is midnight at the *start* of February 28th, so `BETWEEN` would miss
everything on that day after 00:00. In the sample data no order falls on February 28th, so the bug would go
unnoticed until one did, which is exactly what makes it dangerous.

</details>

3. **Reviews with fallbacks.** Show every review's id, rating, and body, where an empty body shows as `-`. Sort by rating
   descending, then by id. Make sure ties are broken deterministically.

<details>
<summary>Solution</summary>

```sql
SELECT id, rating, coalesce(body, '-') AS body
FROM reviews
ORDER BY rating DESC, id;
```

```text
 id | rating |                     body
----+--------+----------------------------------------------
  1 |      5 | Smooth nib, writes every time.
  3 |      5 | Great paper, no ghosting with fountain pens.
  5 |      5 | Heavy, balanced and beautiful.
  9 |      5 | Fits three pens perfectly.
  2 |      4 | Lovely color, dries a bit slowly.
  6 |      4 | Warm brown, nice shading.
  7 |      4 | Good starter pen.
  4 |      3 | -
  8 |      2 | Paper is thinner than expected.
(9 rows)
```

The `id` at the end of the `ORDER BY` is the tie-breaker: several reviews share each rating.

</details>

4. **First customer per country.** Using `DISTINCT ON`, list the earliest customer to sign up in each country, with the
   country, name and sign-up time, sorted by country.

<details>
<summary>Solution</summary>

```sql
SELECT DISTINCT ON (country) country, full_name, created_at
FROM customers
ORDER BY country, created_at;
```

```text
 country |   full_name    |       created_at
---------+----------------+------------------------
 BR      | Ana Souza      | 2026-01-05 09:12:00+00
 DE      | Emma Schulz    | 2026-02-14 11:00:00+00
 FR      | Chloé Martin   | 2026-01-20 18:45:00+00
 GB      | Dev Patel      | 2026-02-02 08:05:00+00
 JP      | Hana Kobayashi | 2026-04-01 07:55:00+00
 SE      | Gus Lindqvist  | 2026-03-18 10:10:00+00
 US      | Ben Carter     | 2026-01-11 14:30:00+00
(7 rows)
```

For the US, Ben (January) wins over Farah (March). Every other country has one customer.

</details>

5. **The NULL trap.** Predict how many rows each query returns, then run them. Explain the difference.

   ```sql
   SELECT count(*) FROM orders WHERE shipped_at IS NULL OR shipped_at >= '2026-03-01';
   SELECT count(*) FROM orders WHERE NOT (shipped_at < '2026-03-01');
   SELECT count(*) FROM orders WHERE shipped_at IS DISTINCT FROM NULL;
   ```

<details>
<summary>Solution</summary>

8, 4 and 8. The first counts the 4 unshipped orders plus the 4 that shipped from March on. The second looks like the
same question, but `NOT (NULL < ...)` is `NULL`, so the unshipped orders are dropped: only 4. The third is a roundabout
way of writing `IS NOT NULL`: the 8 shipped orders. When a column can be `NULL`, decide explicitly what a condition
should do with those rows, and write the `IS NULL` case out.

</details>

6. **A sort order for humans.** List all orders with their status, sorted so that orders needing attention come first:
   `paid` (needs shipping), then `pending`, then `shipped`, then `delivered`, then `cancelled`; within each status,
   oldest first.

<details>
<summary>Solution</summary>

```sql
SELECT id, status, created_at
FROM orders
ORDER BY CASE status
             WHEN 'paid' THEN 1
             WHEN 'pending' THEN 2
             WHEN 'shipped' THEN 3
             WHEN 'delivered' THEN 4
             ELSE 5
         END,
         created_at;
```

```text
 id |  status   |       created_at
----+-----------+------------------------
 10 | paid      | 2026-04-08 06:30:00+00
 11 | paid      | 2026-04-09 20:05:00+00
 12 | pending   | 2026-04-10 12:00:00+00
  7 | shipped   | 2026-03-21 09:30:00+00
  9 | shipped   | 2026-04-04 11:11:00+00
  1 | delivered | 2026-01-06 10:00:00+00
  2 | delivered | 2026-01-15 12:00:00+00
  3 | delivered | 2026-02-03 19:30:00+00
  4 | delivered | 2026-02-10 08:45:00+00
  6 | delivered | 2026-03-02 15:00:00+00
  8 | delivered | 2026-03-28 17:40:00+00
  5 | cancelled | 2026-02-20 13:15:00+00
(12 rows)
```

Sorting by the enum directly (`ORDER BY status`) would use the declaration order (`pending`, `paid`, `shipped`, …),
which is the lifecycle order, not the priority order this screen needs. `CASE` lets you define any order without
changing the type.

</details>

---
← Previous: [Inserting, updating and deleting](05-inserting-updating-and-deleting.md) | Next: [Joins](07-joins.md) →
