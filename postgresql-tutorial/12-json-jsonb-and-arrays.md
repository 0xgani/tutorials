# Chapter 12 — JSON, JSONB and arrays

## What you'll learn

- The difference between `json` and `jsonb`, and why you almost always want `jsonb`
- Reading values with `->`, `->>`, `#>>` and subscripts, and converting them to SQL types
- Testing keys and containment (`?`, `@>`), and indexing those tests with GIN
- SQL/JSON path expressions (`@?`, `@@`, `jsonb_path_query`)
- The SQL-standard JSON functions: `IS JSON` (16+), `JSON_VALUE`, `JSON_QUERY`, `JSON_EXISTS` and `JSON_TABLE` (17+)
- Changing documents with `||`, `-`, `jsonb_set`, and what such an update costs
- Building JSON from rows for APIs, and turning JSON back into rows
- Arrays in depth: operators, `unnest`, `ANY`, array functions including `array_sort()` (18+), and GIN indexes
- When a column, a JSON document, an array or a child table is the right choice

Relational tables are at their best when every row has the same shape. Some data doesn't: a fountain pen has a nib
size, a notebook has a page count and a ruling, an ink has a volume. Inkwell keeps such product-specific details in
`products.attributes`, a `jsonb` column, and short labels in `products.tags`, a `text[]` array. PostgreSQL treats both
as first-class types, with operators, functions and indexes. This chapter teaches you to use them well, and when not
to use them at all. Start from the reference data (chapter 5). Examples that change data run inside a transaction
that is rolled back, so the data stays as it was.

Here is what the attributes look like:

```sql
SELECT id, name, attributes
FROM products
WHERE id IN (1, 4, 6, 7, 11)
ORDER BY id;
```

```text
 id |          name          |                     attributes
----+------------------------+-----------------------------------------------------
  1 | Kestrel Fountain Pen   | {"nib": "F", "color": "black", "material": "resin"}
  4 | Click Ballpoint 3-pack | {"pack": 3, "color": "black", "refillable": true}
  6 | Sketching Pencil Set   | {"grades": ["2H", "HB", "2B", "4B", "6B"]}
  7 | Dot Grid Notebook A5   | {"size": "A5", "pages": 192, "ruling": "dot"}
 11 | Midnight Blue Ink 50ml | {"color": "blue", "volume_ml": 50}
(5 rows)
```

## json versus jsonb

PostgreSQL has two JSON types:

- `json` stores the **text** exactly as given: whitespace, key order, even duplicate keys. Every operation re-parses it.
- `jsonb` stores a parsed **binary** form. Duplicate keys are removed (the last one wins), keys are stored in its own
  order, and whitespace is discarded. Processing is faster, and it supports containment operators and indexes.

```sql
SELECT '{"b": 1, "a": 1, "a": 2,   "cc": 3}'::json AS json,
       '{"b": 1, "a": 1, "a": 2,   "cc": 3}'::jsonb AS jsonb;
```

```text
                json                 |           jsonb
-------------------------------------+---------------------------
 {"b": 1, "a": 1, "a": 2,   "cc": 3} | {"a": 2, "b": 1, "cc": 3}
(1 row)
```

`jsonb` sorts keys by length and then bytewise; don't rely on any key order in JSON objects. Numbers in `jsonb` are
stored as `numeric`, so they keep their precision (`1.0` stays `1.0`, but `1e2` becomes `100`).

Use `jsonb` unless you have a specific reason to keep the exact text, such as storing a webhook payload byte for byte
for auditing. Invalid JSON is rejected on input either way:

```sql
SELECT '{"nib": "F",}'::jsonb;
```

```text
ERROR:  invalid input syntax for type json
LINE 1: SELECT '{"nib": "F",}'::jsonb;
               ^
DETAIL:  Expected string, but found "}".
CONTEXT:  JSON data, line 1: {"nib": "F",}
```

A JSON value has its own types: object, array, string, number, boolean and `null`. Note that JSON `null` is a value,
different from SQL `NULL` (no value at all):

```sql
SELECT jsonb_typeof('{"a": 1}') AS object,
       jsonb_typeof('[1, 2]') AS array,
       jsonb_typeof('"F"') AS string,
       jsonb_typeof('3.5') AS number,
       jsonb_typeof('null') AS json_null,
       'null'::jsonb IS NULL AS is_sql_null;
```

```text
 object | array | string | number | json_null | is_sql_null
--------+-------+--------+--------+-----------+-------------
 object | array | string | number | null      | f
(1 row)
```

`IS JSON` (16+) checks whether a text value is valid JSON, optionally of a particular kind, without raising an error.
It is useful for validating text before casting it:

```sql
SELECT '{"a": 1}' IS JSON AS valid,
       '{"a": 1}' IS JSON ARRAY AS is_array,
       '[1, 2' IS JSON AS broken,
       '{"a": 1, "a": 2}' IS JSON WITH UNIQUE KEYS AS unique_keys;
```

```text
 valid | is_array | broken | unique_keys
-------+----------+--------+-------------
 t     | f        | f      | f
(1 row)
```

## Reading values

The basic operators:

| Operator | Returns | Example | Result |
|---|---|---|---|
| `->` key or index | `jsonb` | `attributes -> 'pages'` | `192` (as JSON) |
| `->>` key or index | `text` | `attributes ->> 'size'` | `A5` |
| `#>` path array | `jsonb` | `attributes #> '{grades,0}'` | `"2H"` |
| `#>>` path array | `text` | `attributes #>> '{grades,0}'` | `2H` |

The difference between `->` and `->>` is the type of the result. `->` keeps JSON, so you can keep navigating; `->>`
gives text, which is what you compare with and convert to SQL types:

```sql
SELECT name,
       attributes -> 'size' AS size_json,
       attributes ->> 'size' AS size_text,
       (attributes ->> 'pages')::int AS pages,
       attributes #>> '{grades,0}' AS first_grade
FROM products
WHERE id IN (6, 7, 10)
ORDER BY id;
```

```text
         name          | size_json | size_text | pages | first_grade
-----------------------+-----------+-----------+-------+-------------
 Sketching Pencil Set  |           |           |       | 2H
 Dot Grid Notebook A5  | "A5"      | A5        |   192 |
 Copy Paper 500 sheets | "A4"      | A4        |       |
(3 rows)
```

`size_json` is the JSON string `"A5"`, quotes included; `size_text` is plain text. A missing key gives SQL `NULL`, not
an error. To turn a JSON number into an SQL number, take it out as text and cast it.

Since PostgreSQL 14 you can also use **subscripts**, which read like array access and return `jsonb`:

```sql
SELECT attributes['grades'][1] AS second_grade,
       attributes['grades'] AS grades
FROM products
WHERE id = 6;
```

```text
 second_grade |             grades
--------------+--------------------------------
 "HB"         | ["2H", "HB", "2B", "4B", "6B"]
(1 row)
```

JSON arrays are indexed from **0**, unlike PostgreSQL arrays, which start at 1.

Filtering works like with any other expression. Remember that `->>` returns text, so compare it with text, or cast it
before comparing numerically:

```sql
SELECT name, attributes ->> 'pages' AS pages
FROM products
WHERE (attributes ->> 'pages')::int >= 100
ORDER BY name;
```

```text
         name         | pages
----------------------+-------
 Dot Grid Notebook A5 | 192
 Lined Notebook A4    | 160
(2 rows)
```

Comparing the text instead, `attributes ->> 'pages' >= '100'`, would compare strings, and `'64' >= '100'` is true
because `6` sorts after `1`.

## Keys and containment

`jsonb` has operators that test a document's structure:

| Operator | Meaning |
|---|---|
| `doc ? 'key'` | the object has this top-level key (or the array contains this string) |
| `doc ?\| array['a','b']` | has any of these keys |
| `doc ?& array['a','b']` | has all of these keys |
| `doc @> fragment` | `doc` **contains** the fragment |
| `fragment <@ doc` | the same, the other way round |

```sql
SELECT name FROM products WHERE attributes ? 'nib' ORDER BY name;
```

```text
         name
----------------------
 Heron Fountain Pen
 Kestrel Fountain Pen
(2 rows)
```

Containment is the most useful of these. `a @> b` is true if everything in `b` also appears in `a`, at the same place
in the structure:

```sql
SELECT name, attributes
FROM products
WHERE attributes @> '{"color": "blue"}'
ORDER BY name;
```

```text
          name          |               attributes
------------------------+----------------------------------------
 Everyday Ballpoint     | {"color": "blue", "refillable": false}
 Midnight Blue Ink 50ml | {"color": "blue", "volume_ml": 50}
(2 rows)
```

```sql
SELECT name
FROM products
WHERE attributes @> '{"pack": 3, "refillable": true}';
```

```text
          name
------------------------
 Click Ballpoint 3-pack
(1 row)
```

Containment compares types too: `'{"pack": 3}'` does not match `{"pack": "3"}`. For arrays, it checks that each
element of the fragment is somewhere in the array, ignoring order:

```sql
SELECT name
FROM products
WHERE attributes @> '{"grades": ["6B", "HB"]}';
```

```text
         name
----------------------
 Sketching Pencil Set
(1 row)
```

### Indexing JSON

`?` and `@>` can use a **GIN index** (a *generalized inverted index*, which maps each key and value to the rows that
contain it; chapter 15 explains how it works). There are two operator classes:

- the default `jsonb_ops` indexes every key and value separately, and supports `?`, `?|`, `?&`, `@>` and the path
  operators;
- `jsonb_path_ops` indexes hashes of complete paths. It is smaller and faster for `@>` and path queries, but can't
  answer the `?` key-existence operators.

With only 14 products the planner would rather read the whole table, so this example turns sequential scans off to
show that the index *can* be used. It runs in a transaction that is rolled back, so the index doesn't stay:

```sql
BEGIN;
CREATE INDEX products_attributes_idx ON products USING gin (attributes jsonb_path_ops);
SET LOCAL enable_seqscan = off;
EXPLAIN (COSTS OFF)
SELECT name FROM products WHERE attributes @> '{"color": "blue"}';
ROLLBACK;
```

```text
BEGIN
CREATE INDEX
SET
                           QUERY PLAN
----------------------------------------------------------------
 Bitmap Heap Scan on products
   Recheck Cond: (attributes @> '{"color": "blue"}'::jsonb)
   ->  Bitmap Index Scan on products_attributes_idx
         Index Cond: (attributes @> '{"color": "blue"}'::jsonb)
(4 rows)

ROLLBACK
```

`SET LOCAL` changes a setting only until the end of the transaction. A GIN index on a whole document serves any
containment query. When you always filter on one particular key, an ordinary B-tree **expression index** on that key
is smaller and also supports `<`, `>` and sorting: `CREATE INDEX ON products ((attributes ->> 'color'))`. The query
must then use exactly the same expression. Chapter 15 covers both kinds.

## SQL/JSON path

For anything deeper than one level, PostgreSQL implements the SQL/JSON **path language** (type `jsonpath`). A path
starts at `$`, the document, and walks it: `$.grades` is a key, `$.grades[0]` an element, `$.grades[*]` every
element, and `? (...)` filters with `@` standing for the current item.

| Function or operator | Returns |
|---|---|
| `doc @? path` | true if the path returns any item |
| `doc @@ predicate` | the result of a path predicate (true, false or `NULL`) |
| `jsonb_path_query(doc, path)` | every item the path returns, one row each |
| `jsonb_path_query_array(doc, path)` | the items as one JSON array |
| `jsonb_path_query_first(doc, path)` | the first item |
| `jsonb_path_exists(doc, path)` | like `@?` |

```sql
SELECT name
FROM products
WHERE attributes @? '$.pages ? (@ > 100)'
ORDER BY name;
```

```text
         name
----------------------
 Dot Grid Notebook A5
 Lined Notebook A4
(2 rows)
```

The path says: take `pages`, and keep it if it is greater than 100. Unlike the `->>` version earlier, there is no
cast, and documents without `pages` or with a non-numeric value simply don't match. `@@` evaluates a predicate:

```sql
SELECT name
FROM products
WHERE attributes @@ '$.volume_ml == 50';
```

```text
          name
------------------------
 Midnight Blue Ink 50ml
 Sepia Ink 50ml
(2 rows)
```

`jsonb_path_query` returns every matching item, one row per item, which is useful for arrays:

```sql
SELECT jsonb_path_query(attributes, '$.grades[*] ? (@ like_regex "B$")') AS soft_grade
FROM products
WHERE id = 6;
```

```text
 soft_grade
------------
 "HB"
 "2B"
 "4B"
 "6B"
(4 rows)
```

Paths support arithmetic, comparisons, `&&`, `||`, `!`, `like_regex`, `starts with`, `exists (...)` and item methods
such as `.size()`, `.type()`, `.double()` and `.keyvalue()`. Since PostgreSQL 17 there are also conversion methods
such as `.integer()`, `.date()` and `.timestamp_tz()`:

```sql
SELECT jsonb_path_query('{"grades": ["2H", "HB", "2B"]}', '$.grades.size()') AS how_many,
       jsonb_path_query('{"pages": "192"}', '$.pages.integer()') AS as_number,
       jsonb_path_query('{"due": "2026-04-30"}', '$.due.date()') AS as_date;
```

```text
 how_many | as_number |   as_date
----------+-----------+--------------
 3        | 192       | "2026-04-30"
(1 row)
```

By default paths run in **lax** mode, which forgives structural mismatches: a missing key returns nothing instead of
raising an error, and an array is unwrapped automatically where a single value is expected. Prefix a path with
`strict` to get errors instead, which is useful when you validate documents.

## The SQL-standard JSON query functions

PostgreSQL 17 added the SQL standard's JSON query functions. They take a path and give you precise control over the
result type and over what happens when a value is missing or wrong:

| Function | Returns |
|---|---|
| `JSON_EXISTS(doc, path)` | boolean |
| `JSON_VALUE(doc, path [RETURNING type])` | a single scalar, converted to an SQL type |
| `JSON_QUERY(doc, path [WITH WRAPPER])` | a JSON object or array |

```sql
SELECT name,
       JSON_VALUE(attributes, '$.pages' RETURNING int) AS pages,
       JSON_VALUE(attributes, '$.ruling' DEFAULT 'none' ON EMPTY) AS ruling,
       JSON_EXISTS(attributes, '$.size') AS has_size
FROM products
WHERE id IN (7, 9, 10)
ORDER BY id;
```

```text
          name          | pages | ruling | has_size
------------------------+-------+--------+----------
 Dot Grid Notebook A5   |   192 | dot    | t
 Pocket Notebook 3-pack |    64 | plain  | t
 Copy Paper 500 sheets  |       | none   | t
(3 rows)
```

`RETURNING int` converts the value, and `DEFAULT ... ON EMPTY` supplies a value when the path finds nothing. There is
also `ON ERROR`, which applies when the path finds something unsuitable, for example an array where a scalar was
expected:

```sql
SELECT JSON_VALUE(attributes, '$.grades' DEFAULT 'several' ON ERROR) AS grade,
       JSON_QUERY(attributes, '$.grades[0 to 1]' WITH WRAPPER) AS first_two
FROM products
WHERE id = 6;
```

```text
  grade  |  first_two
---------+--------------
 several | ["2H", "HB"]
(1 row)
```

`JSON_QUERY` returns JSON. `$.grades[0 to 1]` selects two items, and `WITH WRAPPER` puts them into an array, since a
sequence of items isn't a single JSON value.

### JSON_TABLE: from documents to rows

`JSON_TABLE` (17+) turns JSON into a table that you use in `FROM`, with typed columns and nested arrays flattened into
rows. It is the cleanest way to load a JSON payload, such as an order submitted by a web shop:

```sql
SELECT jt.*
FROM JSON_TABLE(
    '{"customer": "hana@example.com",
      "items": [{"sku": "FP-001", "qty": 1},
                {"sku": "IN-001", "qty": 2},
                {"sku": "NB-001"}]}'::jsonb,
    '$'
    COLUMNS (
        customer text PATH '$.customer',
        NESTED PATH '$.items[*]' COLUMNS (
            line FOR ORDINALITY,
            sku  text PATH '$.sku',
            qty  int  PATH '$.qty' DEFAULT 1 ON EMPTY
        )
    )
) AS jt;
```

```text
     customer     | line |  sku   | qty
------------------+------+--------+-----
 hana@example.com |    1 | FP-001 |   1
 hana@example.com |    2 | IN-001 |   2
 hana@example.com |    3 | NB-001 |   1
(3 rows)
```

The row path `'$'` produces one row for the whole document; `NESTED PATH` produces one row per item, repeating the
outer columns. `FOR ORDINALITY` numbers the rows, and `DEFAULT 1 ON EMPTY` fills in the missing quantity. From here
the payload joins to real tables like any other row source:

```sql
SELECT jt.sku, p.name, jt.qty, p.price * jt.qty AS line_total
FROM JSON_TABLE(
    '[{"sku": "FP-001", "qty": 1}, {"sku": "IN-001", "qty": 2}]'::jsonb,
    '$[*]' COLUMNS (sku text PATH '$.sku', qty int PATH '$.qty')
) AS jt
JOIN products AS p ON p.sku = jt.sku
ORDER BY jt.sku;
```

```text
  sku   |          name          | qty | line_total
--------+------------------------+-----+------------
 FP-001 | Kestrel Fountain Pen   |   1 |      48.00
 IN-001 | Midnight Blue Ink 50ml |   2 |      32.00
(2 rows)
```

`JSON_TABLE` can also read a column, with the document coming from a table earlier in `FROM` (an implicit `LATERAL`,
chapter 9):

```sql
SELECT p.name, g.grade
FROM products AS p,
     JSON_TABLE(p.attributes, '$.grades[*]' COLUMNS (grade text PATH '$')) AS g
ORDER BY p.name, g.grade;
```

```text
         name         | grade
----------------------+-------
 Sketching Pencil Set | 2B
 Sketching Pencil Set | 2H
 Sketching Pencil Set | 4B
 Sketching Pencil Set | 6B
 Sketching Pencil Set | HB
(5 rows)
```

Before PostgreSQL 17, the same jobs were done with `jsonb_to_recordset` and `jsonb_array_elements`, which you'll
still see in a lot of code (they are covered below).

## Changing JSON documents

| Expression | Effect |
|---|---|
| `doc \|\| '{"k": v}'` | merges the top-level keys (right side wins); concatenates arrays |
| `doc - 'key'` | removes a key |
| `doc - array['a','b']` | removes several keys |
| `doc #- '{path,to,key}'` | removes the item at a path |
| `jsonb_set(doc, '{path}', value)` | replaces (or adds) the item at a path |
| `jsonb_insert(doc, '{path}', value)` | inserts into an array at a position |
| `jsonb_strip_nulls(doc)` | removes object fields whose value is JSON `null` |

```sql
BEGIN;
UPDATE products
SET attributes = attributes || '{"color": "red", "limited": true}'
WHERE id = 1
RETURNING attributes;

UPDATE products
SET attributes = jsonb_set(attributes, '{grades,0}', '"3H"') - 'unused'
WHERE id = 6
RETURNING attributes;
ROLLBACK;
```

```text
BEGIN
                             attributes
--------------------------------------------------------------------
 {"nib": "F", "color": "red", "limited": true, "material": "resin"}
(1 row)

UPDATE 1
                 attributes
--------------------------------------------
 {"grades": ["3H", "HB", "2B", "4B", "6B"]}
(1 row)

UPDATE 1
ROLLBACK
```

`||` merges only the top level: the old `color` was replaced, `limited` was added, and a nested object on the right
would replace a nested object on the left entirely rather than merging into it. Removing a key that isn't there
(`- 'unused'`) is not an error. `jsonb_set` takes the new value as `jsonb`, which is why the string is written
`'"3H"'` with JSON quotes inside SQL quotes.

What an update costs: PostgreSQL never changes a `jsonb` value in place. Changing one key writes a complete new
version of the whole row, document included (chapter 18 explains why). For small documents that is no problem. For
large documents that change often, a key that is updated frequently, a counter say, belongs in its own column.

## Building JSON

Applications often want JSON, and building it in the database saves a round of reshaping in application code. The
main building blocks:

| Function | Builds |
|---|---|
| `to_jsonb(value)` | any value, including a whole row, as JSON |
| `jsonb_build_object(k1, v1, k2, v2, ...)` | an object from alternating keys and values |
| `jsonb_build_array(v1, v2, ...)` | an array |
| `jsonb_agg(expr ORDER BY ...)` | an aggregate: one array from many rows |
| `jsonb_object_agg(k, v)` | an aggregate: one object from key/value rows |
| `JSON_OBJECT(...)`, `JSON_ARRAY(...)`, `JSON_ARRAYAGG(...)`, `JSON_OBJECTAGG(...)` (16+) | the SQL-standard equivalents |

A whole row converts to an object with the column names as keys:

```sql
SELECT to_jsonb(c) AS customer
FROM customers AS c
WHERE id = 3;
```

```text
                                                             customer
----------------------------------------------------------------------------------------------------------------------------------
 {"id": 3, "email": "chloe@example.com", "country": "FR", "full_name": "Chloé Martin", "created_at": "2026-01-20T18:45:00+00:00"}
(1 row)
```

To control the keys, build the object yourself. This query returns an order exactly as an API might send it, with its
customer and its lines nested inside:

```sql
SELECT jsonb_pretty(jsonb_build_object(
           'id', o.id,
           'status', o.status,
           'created_at', o.created_at,
           'customer', jsonb_build_object('id', c.id, 'name', c.full_name),
           'items', (SELECT jsonb_agg(jsonb_build_object('sku', p.sku,
                                                         'quantity', oi.quantity,
                                                         'unit_price', oi.unit_price)
                                      ORDER BY p.sku)
                     FROM order_items AS oi
                     JOIN products AS p ON p.id = oi.product_id
                     WHERE oi.order_id = o.id)
       )) AS order_doc
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
WHERE o.id = 4;
```

```text
                   order_doc
-----------------------------------------------
 {                                            +
     "id": 4,                                 +
     "items": [                               +
         {                                    +
             "sku": "NB-001",                 +
             "quantity": 2,                   +
             "unit_price": 14.00              +
         },                                   +
         {                                    +
             "sku": "PC-001",                 +
             "quantity": 12,                  +
             "unit_price": 1.20               +
         }                                    +
     ],                                       +
     "status": "delivered",                   +
     "customer": {                            +
         "id": 1,                             +
         "name": "Ana Souza"                  +
     },                                       +
     "created_at": "2026-02-10T08:45:00+00:00"+
 }
(1 row)
```

`jsonb_pretty` only formats the output for reading. Timestamps become ISO 8601 strings, and `numeric` values become
JSON numbers (`14.00`). Be aware that many JSON parsers, JavaScript's included, read numbers as binary floating point,
so money values are often safer sent as strings: `oi.unit_price::text`.

Aggregating into an object keyed by a column turns a table into a lookup map:

```sql
SELECT jsonb_object_agg(sku, stock ORDER BY sku) AS stock_by_sku
FROM products
WHERE category_id = 6;
```

```text
         stock_by_sku
------------------------------
 {"IN-001": 30, "IN-002": 25}
(1 row)
```

The SQL-standard constructors from PostgreSQL 16 do the same jobs with different syntax, using `key : value` pairs and
explicit handling of `NULL`s:

```sql
SELECT JSON_OBJECT('sku' : sku, 'description' : NULLIF(description, '') ABSENT ON NULL) AS obj,
       JSON_ARRAY(1, 'two', NULL) AS arr
FROM products
WHERE id = 5;
```

```text
                                              obj                                              |    arr
-----------------------------------------------------------------------------------------------+------------
 {"sku" : "PC-001", "description" : "A classic HB graphite pencil for writing and sketching."} | [1, "two"]
(1 row)
```

`JSON_OBJECT` keeps `NULL` values as JSON `null` unless you say `ABSENT ON NULL`; `JSON_ARRAY` drops them unless you
say `NULL ON NULL`. Note that these return `json`, not `jsonb`, unless you add `RETURNING jsonb`.

## From JSON back to rows

These set-returning functions expand JSON into rows. They predate `JSON_TABLE` and are still the quickest way to look
at a document's contents:

| Function | Produces |
|---|---|
| `jsonb_each(obj)`, `jsonb_each_text(obj)` | one row per key, with columns `key` and `value` |
| `jsonb_object_keys(obj)` | one row per key |
| `jsonb_array_elements(arr)`, `jsonb_array_elements_text(arr)` | one row per element |
| `jsonb_to_record(obj)`, `jsonb_to_recordset(arr)` | typed columns, declared in an `AS` clause |
| `jsonb_populate_record(null::sometype, obj)` | a row of an existing table type |

```sql
SELECT key, value
FROM products, jsonb_each_text(attributes)
WHERE id = 9;
```

```text
  key   | value
--------+-------
 pack   | 3
 size   | A6
 pages  | 64
 ruling | plain
(4 rows)
```

Because every product can have different keys, it's worth asking which keys are actually in use. This is the first
thing to run against an unfamiliar JSON column:

```sql
SELECT k AS key, count(*) AS products
FROM products, jsonb_object_keys(attributes) AS k
GROUP BY k
ORDER BY products DESC, key;
```

```text
    key     | products
------------+----------
 color      |        6
 material   |        4
 size       |        4
 pages      |        3
 ruling     |        3
 nib        |        2
 pack       |        2
 refillable |        2
 volume_ml  |        2
 grade      |        1
 grades     |        1
 slots      |        1
 weight_gsm |        1
(13 rows)
```

`jsonb_to_recordset` turns an array of objects into typed rows; the `AS` clause names and types the columns you want:

```sql
SELECT *
FROM jsonb_to_recordset('[{"sku": "FP-001", "qty": 1}, {"sku": "IN-001", "qty": 2}]')
     AS x (sku text, qty int);
```

```text
  sku   | qty
--------+-----
 FP-001 |   1
 IN-001 |   2
(2 rows)
```

## Arrays in depth

Chapter 3 introduced arrays. Inkwell uses one for product tags:

```sql
SELECT name, tags
FROM products
WHERE cardinality(tags) > 0
ORDER BY name;
```

```text
          name          |       tags
------------------------+-------------------
 Click Ballpoint 3-pack | {bestseller}
 Dot Grid Notebook A5   | {bestseller}
 Heron Fountain Pen     | {gift}
 Kestrel Fountain Pen   | {gift,bestseller}
 Leather Pen Case       | {gift}
 Sketching Pencil Set   | {gift}
(6 rows)
```

`cardinality` counts all elements. `array_length(arr, 1)` gives the length of the first dimension, but returns `NULL`
for an empty array, which surprises people; prefer `cardinality`.

### Searching arrays

| Expression | True when |
|---|---|
| `value = ANY (arr)` | `value` equals some element |
| `value <> ALL (arr)` | `value` differs from every element |
| `arr @> ARRAY[...]` | `arr` contains all of the given elements |
| `arr <@ ARRAY[...]` | every element of `arr` is among the given ones |
| `arr && ARRAY[...]` | the arrays overlap (share at least one element) |

```sql
SELECT name FROM products WHERE 'gift' = ANY (tags) ORDER BY name;
```

```text
         name
----------------------
 Heron Fountain Pen
 Kestrel Fountain Pen
 Leather Pen Case
 Sketching Pencil Set
(4 rows)
```

```sql
SELECT name,
       tags @> ARRAY['gift', 'bestseller'] AS both_tags,
       tags && ARRAY['bestseller', 'sale'] AS any_tag
FROM products
WHERE cardinality(tags) > 0
ORDER BY name;
```

```text
          name          | both_tags | any_tag
------------------------+-----------+---------
 Click Ballpoint 3-pack | f         | t
 Dot Grid Notebook A5   | f         | t
 Heron Fountain Pen     | f         | f
 Kestrel Fountain Pen   | t         | t
 Leather Pen Case       | f         | f
 Sketching Pencil Set   | f         | f
(6 rows)
```

`@>`, `<@` and `&&` can use a GIN index on the array column (`CREATE INDEX ON products USING gin (tags)`); the
`= ANY` form can't, so prefer `tags @> ARRAY['gift']` in queries on large tables.

`= ANY` is very useful in the other direction, with an array **parameter**. An application that needs rows for a list
of ids sends one array instead of building `IN (...)` with a varying number of placeholders. In psql:

```sql
SELECT id, sku FROM products WHERE id = ANY ('{3,7,11}'::int[]) ORDER BY id;
```

```text
 id |  sku
----+--------
  3 | BP-001
  7 | NB-001
 11 | IN-001
(3 rows)
```

From Node.js that is `client.query('SELECT ... WHERE id = ANY ($1)', [[3, 7, 11]])`: one statement whatever the list
length (chapter 22).

### Unnesting and aggregating

`unnest` expands an array into rows. `WITH ORDINALITY` adds each element's position:

```sql
SELECT p.name, t.tag, t.pos
FROM products AS p,
     unnest(p.tags) WITH ORDINALITY AS t (tag, pos)
WHERE p.id IN (1, 2)
ORDER BY p.name, t.pos;
```

```text
         name         |    tag     | pos
----------------------+------------+-----
 Heron Fountain Pen   | gift       |   1
 Kestrel Fountain Pen | gift       |   1
 Kestrel Fountain Pen | bestseller |   2
(3 rows)
```

`array_agg` (chapter 8) goes the other way. The two together answer questions such as "how often is each tag used?":

```sql
SELECT tag, count(*) AS products, array_agg(sku ORDER BY sku) AS skus
FROM products, unnest(tags) AS tag
GROUP BY tag
ORDER BY products DESC, tag;
```

```text
    tag     | products |             skus
------------+----------+-------------------------------
 gift       |        4 | {AC-002,FP-001,FP-002,PC-002}
 bestseller |        3 | {BP-002,FP-001,NB-001}
(2 rows)
```

### Array functions

```sql
SELECT array_append(ARRAY['gift'], 'sale') AS appended,
       array_prepend('new', ARRAY['gift']) AS prepended,
       ARRAY['a', 'b'] || ARRAY['c'] AS concatenated,
       array_remove(ARRAY['gift', 'sale', 'gift'], 'gift') AS removed,
       array_replace(ARRAY[1, 2, 1], 1, 9) AS replaced,
       array_position(ARRAY['x', 'y', 'z'], 'y') AS position,
       (ARRAY[10, 20, 30, 40])[2:3] AS slice,
       array_to_string(ARRAY['gift', NULL, 'sale'], ', ', '-') AS joined;
```

```text
  appended   | prepended  | concatenated | removed | replaced | position |  slice  |    joined
-------------+------------+--------------+---------+----------+----------+---------+---------------
 {gift,sale} | {new,gift} | {a,b,c}      | {sale}  | {9,2,9}  |        2 | {20,30} | gift, -, sale
(1 row)
```

PostgreSQL 18 adds `array_sort()` and `array_reverse()`. `array_sort` sorts using the element type's ordering (and the
collation, for text); optional arguments sort in descending order and choose where `NULL`s go:

```sql
SELECT array_sort(ARRAY[30, 10, 20]) AS sorted,
       array_sort(ARRAY[30, NULL, 10], true) AS descending,
       array_sort(ARRAY['sale', 'Gift', 'gift']) AS text_sorted,
       array_reverse(ARRAY[1, 2, 3]) AS reversed;
```

```text
   sorted   |  descending  |   text_sorted    | reversed
------------+--------------+------------------+----------
 {10,20,30} | {NULL,30,10} | {gift,Gift,sale} | {3,2,1}
(1 row)
```

Before version 18 you sorted an array with `ARRAY(SELECT x FROM unnest(arr) AS x ORDER BY x)`. That subquery form is
still the tool for anything more elaborate, such as removing duplicates (`SELECT DISTINCT`) while sorting.

Arrays can have several dimensions (`integer[][]`, written `'{{1,2},{3,4}}'`), but a multi-dimensional array must be
rectangular, and they are rarely the right model; you'll seldom need them.

### Updating arrays

```sql
BEGIN;
UPDATE products
SET tags = array_append(tags, 'sale')
WHERE category_id = 6
  AND NOT tags @> ARRAY['sale']
RETURNING sku, tags;
ROLLBACK;
```

```text
BEGIN
  sku   |  tags
--------+--------
 IN-001 | {sale}
 IN-002 | {sale}
(2 rows)

UPDATE 2
ROLLBACK
```

The `NOT tags @> ...` condition makes the update idempotent: running it twice doesn't add the tag twice. As with
`jsonb`, every change rewrites the whole array.

## Choosing: columns, JSON, arrays or a child table

| Use | When |
|---|---|
| **Ordinary columns** | the field applies to (almost) every row, you filter, join, sort or constrain on it, or it's needed for correctness (price, stock, status) |
| **`jsonb`** | attributes that vary by kind of row, data you store and return whole, external payloads, rarely queried details |
| **Arrays** | a short list of simple values that belongs to the row and is read with it (tags, a few phone numbers) |
| **A child table** | the elements have their own attributes, are referenced by other tables, need foreign keys, or the list can grow large |

The common mistake is to treat `jsonb` as a way to avoid designing a schema. You lose a lot: there are no foreign keys
into a document, no `NOT NULL` or type per key, the planner has no statistics about values inside a document (so it
guesses row counts, chapter 16), and every query must remember to cast. Promote a key to a real column as soon as you
find yourself filtering or joining on it regularly. A generated column (chapter 4) can extract it while the document
stays the source of truth:

```sql
BEGIN;
ALTER TABLE products ADD COLUMN color text GENERATED ALWAYS AS (attributes ->> 'color');
SELECT sku, color FROM products WHERE color IS NOT NULL ORDER BY sku;
ROLLBACK;
```

```text
BEGIN
ALTER TABLE
  sku   | color
--------+-------
 BP-001 | blue
 BP-002 | black
 FP-001 | black
 FP-002 | green
 IN-001 | blue
 IN-002 | brown
(6 rows)

ROLLBACK
```

The column is virtual (the default since PostgreSQL 18), so it costs no storage, and it reads like any other column.
When you do keep JSON, constrain its shape with `CHECK` constraints, as you would any other column:

```sql
BEGIN;
ALTER TABLE products
    ADD CONSTRAINT products_attributes_object CHECK (jsonb_typeof(attributes) = 'object'),
    ADD CONSTRAINT products_pages_positive CHECK ((attributes ->> 'pages')::int > 0);
UPDATE products SET attributes = attributes || '{"pages": 0}' WHERE id = 7;
ROLLBACK;
```

```text
BEGIN
ALTER TABLE
ERROR:  new row for relation "products" violates check constraint "products_pages_positive"
DETAIL:  Failing row contains (7, NB-001, Dot Grid Notebook A5, A5 notebook with 192 pages of dotted paper that handles fountain..., 8, 14.00, 75, {"size": "A5", "pages": 0, "ruling": "dot"}, {bestseller}, t, 2025-12-01 09:00:00+00).
ROLLBACK
```

The second check is `NULL`, and therefore passes, for products without a `pages` key, which is exactly what we want.
Arrays get the same treatment: `CHECK (cardinality(tags) <= 10)` or `CHECK (NOT tags @> ARRAY[''])` (no empty
tags).

## Key takeaways

- Use `jsonb`, not `json`, unless you need the exact original text. JSON `null` is not SQL `NULL`.
- `->` returns `jsonb`, `->>` returns `text`; cast `->>` results before comparing numbers. JSON arrays start at index
  0, PostgreSQL arrays at 1.
- `?` tests for keys and `@>` for containment; both can use a GIN index (`jsonb_path_ops` is smaller but supports only
  containment and path queries).
- Path expressions (`@?`, `@@`, `jsonb_path_query`) filter inside nested documents. `JSON_VALUE`, `JSON_QUERY`,
  `JSON_EXISTS` and `JSON_TABLE` (17+) add typed results and `ON EMPTY`/`ON ERROR` handling.
- `JSON_TABLE` is the cleanest way to turn JSON payloads into rows; `jsonb_each`, `jsonb_object_keys` and
  `jsonb_to_recordset` remain handy for exploring.
- `||` merges only top-level keys; `jsonb_set` changes a nested path. Every change rewrites the whole row.
- Build API-shaped JSON with `jsonb_build_object` and `jsonb_agg` in one query; consider sending money as strings.
- For arrays, use `@>`, `&&` and `<@` (indexable with GIN), `= ANY` for array parameters, `unnest` and `array_agg` to
  convert, and `array_sort` (18+) to sort.
- JSON and arrays complement columns; they don't replace a schema. Promote keys you filter on, and add `CHECK`
  constraints to the JSON you keep.

## Exercises

1. **Colours.** List the distinct `color` values found in product attributes, with how many products have each,
   most common first. Products without a colour should not appear.

<details>
<summary>Solution</summary>

```sql
SELECT attributes ->> 'color' AS color, count(*) AS products
FROM products
WHERE attributes ? 'color'
GROUP BY 1
ORDER BY products DESC, color;
```

```text
 color | products
-------+----------
 black |        2
 blue  |        2
 brown |        1
 green |        1
(4 rows)
```

`attributes ? 'color'` keeps only documents that have the key (it could use a GIN index with the default `jsonb_ops`
class). `WHERE attributes ->> 'color' IS NOT NULL` would also work.

</details>

2. **Two ways to filter.** Find the refillable products, first with containment and then with a path predicate.
   Then find the products whose `pack` is greater than 1 using `JSON_VALUE`.

<details>
<summary>Solution</summary>

```sql
SELECT name FROM products WHERE attributes @> '{"refillable": true}';
SELECT name FROM products WHERE attributes @@ '$.refillable == true';
SELECT name, JSON_VALUE(attributes, '$.pack' RETURNING int) AS pack
FROM products
WHERE JSON_VALUE(attributes, '$.pack' RETURNING int) > 1
ORDER BY name;
```

```text
          name
------------------------
 Click Ballpoint 3-pack
(1 row)

          name
------------------------
 Click Ballpoint 3-pack
(1 row)

          name          | pack
------------------------+------
 Click Ballpoint 3-pack |    3
 Pocket Notebook 3-pack |    3
(2 rows)
```

All the forms understand JSON types: `true` is a JSON boolean, and the string `"true"` would not match. Containment is
the form a GIN index serves best.

</details>

3. **Customer order history as JSON.** For customer 1 (Ana), return a single JSON document: her name and an array of
   her orders, oldest first, each with its id, status and total (sum of quantity × unit price).

<details>
<summary>Solution</summary>

```sql
SELECT jsonb_pretty(jsonb_build_object(
           'customer', c.full_name,
           'orders', (SELECT jsonb_agg(jsonb_build_object('id', o.id,
                                                          'status', o.status,
                                                          'total', t.total)
                                       ORDER BY o.created_at)
                      FROM orders AS o
                      CROSS JOIN LATERAL (SELECT sum(oi.quantity * oi.unit_price) AS total
                                          FROM order_items AS oi
                                          WHERE oi.order_id = o.id) AS t
                      WHERE o.customer_id = c.id)
       )) AS history
FROM customers AS c
WHERE c.id = 1;
```

```text
              history
-----------------------------------
 {                                +
     "orders": [                  +
         {                        +
             "id": 1,             +
             "total": 80.00,      +
             "status": "delivered"+
         },                       +
         {                        +
             "id": 4,             +
             "total": 42.40,      +
             "status": "delivered"+
         },                       +
         {                        +
             "id": 11,            +
             "total": 25.70,      +
             "status": "paid"     +
         }                        +
     ],                           +
     "customer": "Ana Souza"      +
 }
(1 row)
```

The `LATERAL` subquery computes each order's total; `jsonb_agg` with `ORDER BY` collects the orders in date order.

</details>

4. **Attribute report.** Using `JSON_TABLE`, produce one row per notebook (category 8) with typed columns `size`
   (text), `pages` (int) and `ruling` (text), plus `pack` (int) defaulting to 1 when the key is missing.

<details>
<summary>Solution</summary>

```sql
SELECT p.name, a.*
FROM products AS p,
     JSON_TABLE(p.attributes, '$' COLUMNS (
         size   text PATH '$.size',
         pages  int  PATH '$.pages',
         ruling text PATH '$.ruling',
         pack   int  PATH '$.pack' DEFAULT 1 ON EMPTY
     )) AS a
WHERE p.category_id = 8
ORDER BY p.id;
```

```text
          name          | size | pages | ruling | pack
------------------------+------+-------+--------+------
 Dot Grid Notebook A5   | A5   |   192 | dot    |    1
 Lined Notebook A4      | A4   |   160 | lined  |    1
 Pocket Notebook 3-pack | A6   |    64 | plain  |    3
(3 rows)
```

The row path `'$'` produces exactly one row per document, so this behaves like a set of `JSON_VALUE` calls with typed
results, written once.

</details>

5. **Tag maintenance.** In a transaction that you roll back: (a) remove the tag `gift` from every product that has
   it, and show the new tags; (b) then add the tag `pens` to every product in categories 3 and 4, without creating
   duplicates, and show those products' tags sorted alphabetically.

<details>
<summary>Solution</summary>

```sql
BEGIN;
UPDATE products
SET tags = array_remove(tags, 'gift')
WHERE tags @> ARRAY['gift']
RETURNING sku, tags;

UPDATE products
SET tags = array_sort(array_append(tags, 'pens'))
WHERE category_id IN (3, 4)
  AND NOT tags @> ARRAY['pens']
RETURNING sku, tags;
ROLLBACK;
```

```text
BEGIN
  sku   |     tags
--------+--------------
 FP-001 | {bestseller}
 FP-002 | {}
 PC-002 | {}
 AC-002 | {}
(4 rows)

UPDATE 4
  sku   |       tags
--------+-------------------
 BP-001 | {pens}
 BP-002 | {bestseller,pens}
 FP-001 | {bestseller,pens}
 FP-002 | {pens}
(4 rows)

UPDATE 4
ROLLBACK
```

The `WHERE` clauses touch only rows that need a change; rows that already have the tag (or don't have the tag to remove)
are not rewritten at all. `array_sort` (18+) keeps the arrays in a canonical order.

</details>

6. **Bulk price update from JSON.** A supplier sends new prices as JSON:
   `[{"sku": "FP-001", "price": 49.50}, {"sku": "BP-001", "price": 2.75}, {"sku": "XX-999", "price": 1}]`. In a
   transaction you roll back, update the matching products in one statement, returning the sku with the old and new
   price. Then find which skus in the payload don't exist.

<details>
<summary>Solution</summary>

```sql
BEGIN;
UPDATE products AS p
SET price = j.price
FROM JSON_TABLE(
    '[{"sku": "FP-001", "price": 49.50}, {"sku": "BP-001", "price": 2.75}, {"sku": "XX-999", "price": 1}]'::jsonb,
    '$[*]' COLUMNS (sku text PATH '$.sku', price numeric(10,2) PATH '$.price')
) AS j
WHERE p.sku = j.sku
RETURNING p.sku, old.price AS old_price, new.price AS new_price;
ROLLBACK;

SELECT j.sku AS unknown_sku
FROM JSON_TABLE(
    '[{"sku": "FP-001", "price": 49.50}, {"sku": "BP-001", "price": 2.75}, {"sku": "XX-999", "price": 1}]'::jsonb,
    '$[*]' COLUMNS (sku text PATH '$.sku')
) AS j
WHERE NOT EXISTS (SELECT FROM products AS p WHERE p.sku = j.sku);
```

```text
BEGIN
  sku   | old_price | new_price
--------+-----------+-----------
 FP-001 |     48.00 |     49.50
 BP-001 |      2.50 |      2.75
(2 rows)

UPDATE 2
ROLLBACK
 unknown_sku
-------------
 XX-999
(1 row)
```

`UPDATE ... FROM` (chapter 5) joins the JSON rows to the table. `old.price` and `new.price` in `RETURNING` are
PostgreSQL 18's way to see both versions of the row. The unknown sku is silently skipped by the join, so the anti-join
afterwards is how you report it back to the supplier.

</details>

---
← Previous: [Text, dates and times](11-text-dates-and-times.md) | Next: [Full-text search](13-full-text-search.md) →
