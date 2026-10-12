# Chapter 14 — Data modeling

## What you'll learn

- How to get from requirements to tables: entities, relationships and cardinality
- Normalization in practice: the anomalies it prevents and the normal forms that name them
- Snapshots of facts in time versus duplicated data, and when to denormalise on purpose
- Choosing keys: surrogate or natural, `bigint` or UUID, composite keys
- Relationship patterns: one-to-one, one-to-many, many-to-many, self-references, and the "exclusive arc"
- Three ways to model attributes that vary by kind of row: JSON, entity-attribute-value and subtype tables
- Hierarchies: adjacency lists, materialized paths with `ltree`, and closure tables
- Enums versus lookup tables versus `CHECK` constraints
- Soft deletes, archives and history
- Range types, exclusion constraints, and temporal primary and foreign keys with `WITHOUT OVERLAPS` and `PERIOD`
  (18+)
- Schemas as namespaces, `search_path`, and options for multi-tenant applications

Chapters 4 and 5 built the Inkwell schema without much discussion of *why* it looks the way it does. This chapter is
about those decisions. A good data model makes correct data easy and wrong data impossible; it keeps queries simple;
and it survives the next five years of feature requests. A poor one makes every query a workaround and lets bad data
in through the cracks. Unlike application code, a schema full of production data is expensive to change, so it pays to
get the important decisions right early.

Start from the reference data (chapter 5). This chapter adds tables, columns, extensions and a schema to illustrate
the patterns; the next reset removes them all.

## From requirements to tables

Data modeling starts with the language of the business, not with SQL. Listen for:

- **Entities**, the nouns that have an identity and a life of their own: customer, product, order, review. Each
  becomes a table.
- **Attributes**, the facts about one entity: a customer's email, a product's price. Each becomes a column.
- **Relationships** between entities, and their **cardinality**: a customer places *many* orders, an order belongs to
  *one* customer; an order contains *many* products and a product appears in *many* orders.
- **Rules**: an email is unique, stock can't be negative, an order can only be shipped after it was placed. Each
  becomes a constraint.

Inkwell's model (chapter 4) in that vocabulary:

```text
 customers 1───n orders 1───n order_items n───1 products n───1 categories ─┐
     │                                             │              ▲         │ parent_id
     └──────────────────1   n reviews n────────────┘              └─────────┘
```

The many-to-many relationship between orders and products became a table of its own, `order_items`, because the
relationship has attributes of its own (quantity and unit price). That is common: when you find attributes that
belong to a relationship rather than to either side, the relationship is an entity.

## Normalization

**Normalization** means storing each fact exactly once. To see why that matters, build the kind of table a
spreadsheet user would design: one row per order line, with everything on it.

```sql
CREATE TABLE order_lines_flat AS
SELECT o.id AS order_id, o.created_at, c.email, c.full_name,
       p.sku, p.name AS product_name, oi.quantity, oi.unit_price
FROM order_items AS oi
JOIN orders AS o ON o.id = oi.order_id
JOIN customers AS c ON c.id = o.customer_id
JOIN products AS p ON p.id = oi.product_id;

SELECT order_id, email, full_name, sku
FROM order_lines_flat
WHERE email = 'ana@example.com'
ORDER BY order_id, sku;
```

```text
SELECT 21
 order_id |      email      | full_name |  sku
----------+-----------------+-----------+--------
        1 | ana@example.com | Ana Souza | FP-001
        1 | ana@example.com | Ana Souza | IN-001
        4 | ana@example.com | Ana Souza | NB-001
        4 | ana@example.com | Ana Souza | PC-001
       11 | ana@example.com | Ana Souza | PC-001
       11 | ana@example.com | Ana Souza | PC-002
(6 rows)
```

`CREATE TABLE ... AS SELECT` creates a table from a query's result. Ana's email and name are stored once per order
line. This redundancy causes three classic **anomalies**:

- **Update anomaly**: if Ana changes her name, every one of her rows must change. Miss one and the database
  contradicts itself:

  ```sql
  UPDATE order_lines_flat SET full_name = 'Ana Souza Lima' WHERE order_id = 1;
  SELECT DISTINCT email, full_name FROM order_lines_flat WHERE email = 'ana@example.com';
  ```

  ```text
  UPDATE 2
        email      |   full_name
  -----------------+----------------
   ana@example.com | Ana Souza
   ana@example.com | Ana Souza Lima
  (2 rows)
  ```

- **Insertion anomaly**: Gus has registered but never ordered. There is no way to store him without inventing an
  order line.
- **Deletion anomaly**: Dev's only order was cancelled. Delete that order's lines and Dev disappears from the database
  entirely.

In the normalised Inkwell schema, Ana's name lives in exactly one row of `customers`; orders point to it with a
foreign key; and a join reassembles the flat view whenever you need it. The flat table has served its purpose:

```sql
DROP TABLE order_lines_flat;
```

```text
DROP TABLE
```

### The normal forms, briefly

The normal forms are a checklist for spotting redundancy. In practice, the first three cover almost everything:

| Normal form | Rule | Violation example |
|---|---|---|
| **1NF** | each column holds one value; no repeating groups | `phone1, phone2, phone3` columns, or `'pen,ink'` in one text column |
| **2NF** | every non-key column depends on the **whole** key | in `order_items (order_id, product_id, quantity, product_name)`, `product_name` depends only on `product_id` |
| **3NF** | non-key columns depend on the key and **nothing else** | in `orders (id, customer_id, customer_email)`, `customer_email` depends on `customer_id`, not on the order |

The classic summary: every non-key column must depend on "the key, the whole key, and nothing but the key". Boyce-Codd
normal form (BCNF) and the higher forms tighten this for unusual cases with several overlapping candidate keys; when
you meet a design where they matter, the principle is the same.

Arrays and `jsonb` don't automatically break 1NF in a useful sense: Inkwell's `tags` array is a single value (a set of
labels) that is always read and written as a whole. It becomes a problem when you need to treat the elements as
entities in their own right, as the section on many-to-many relationships shows.

### Snapshots are not redundancy

`order_items.unit_price` looks like a copy of `products.price`. It isn't: it records the price **at the moment of the
order**, which is a different fact. Prices change; invoices must not:

```sql
SELECT o.id AS order_id, p.sku, oi.unit_price AS price_paid, p.price AS current_price
FROM order_items AS oi
JOIN orders AS o ON o.id = oi.order_id
JOIN products AS p ON p.id = oi.product_id
WHERE oi.unit_price <> p.price;
```

```text
 order_id |  sku   | price_paid | current_price
----------+--------+------------+---------------
        8 | FP-001 |      45.00 |         48.00
(1 row)
```

Order 8 bought the Kestrel pen during a sale. If the order line only referenced the product, the order's total would
silently change every time the catalogue price did. The same applies to a shipping address, a tax rate, an exchange
rate or a product name on an invoice: when a document must show what was true at the time, copy the value into it.
The question to ask is "should this change when the source changes?" If not, it is a snapshot and belongs in the row.

### Denormalising on purpose

Sometimes you deliberately store a derived value, such as an order total or a product's review count, so that a hot
query doesn't have to aggregate every time. That is a trade: reads get cheaper, while every write must keep the copy
correct, and a bug leaves the two disagreeing. In order of preference:

1. Don't: compute it in the query, with an index that makes it fast (chapter 15). Most "slow" aggregates over a
   well-indexed table are fast enough.
2. Compute it in a **view** or **materialized view** (chapter 20), so the logic lives in one place.
3. Store it, maintained by a **trigger** in the same transaction as the change (chapter 19), so it can't drift.
4. Store it, maintained by application code. This is the most fragile option, because every code path that writes
   must remember.

## Keys

### Surrogate or natural?

A **natural key** is made of real-world data: an email, a product's SKU, a country's ISO code. A **surrogate key** is
a meaningless value generated by the database, such as Inkwell's identity columns. Chapter 4 recommended a surrogate
primary key plus `UNIQUE` constraints on the natural keys. The reasons:

- Natural keys change. People change their email; products get re-coded. A primary key is copied into every foreign
  key that references it, so changing it means updating many tables.
- Natural keys are often not as unique as they seem (two customers sharing an email address, a SKU reused years
  later), and they may be long, composite or personal data you'd rather not spread around.
- A surrogate key is small, stable and uniform, which makes joins and application code simpler.

Natural keys still make good **primary keys** when they are truly stable, short and standard. A table of countries is
the classic example: the ISO code is the identity, and using it as the key means `customers.country` is readable
without a join:

```sql
CREATE TABLE countries (
    code char(2) PRIMARY KEY CHECK (code ~ '^[A-Z]{2}$'),
    name text NOT NULL UNIQUE
);
INSERT INTO countries VALUES
    ('BR', 'Brazil'), ('DE', 'Germany'), ('FR', 'France'), ('GB', 'United Kingdom'),
    ('JP', 'Japan'), ('SE', 'Sweden'), ('US', 'United States');
ALTER TABLE customers
    ADD CONSTRAINT customers_country_fkey FOREIGN KEY (country) REFERENCES countries (code);
```

```text
CREATE TABLE
INSERT 0 7
ALTER TABLE
```

Adding the foreign key checked every existing customer; had one used an unknown code, the `ALTER TABLE` would have
failed. Inkwell's `CHECK (country ~ '^[A-Z]{2}$')` only checks the *shape* of a code; the foreign key checks that the
country exists. That is the general difference between a `CHECK` constraint and a lookup table, discussed below.

### bigint or UUID?

For surrogate keys, the two common choices are `bigint` identity columns and UUIDs (chapter 3):

| | `bigint` identity | UUID (`uuidv7()`, 18+) |
|---|---|---|
| Size | 8 bytes | 16 bytes |
| Generated | by the database, in order | anywhere (database, application, offline client), without coordination |
| Guessable | yes: id 1001 suggests ids 1 to 1000 exist | no |
| Index locality | excellent (always appends) | good with v7 (time-ordered); poor with random v4 |
| Readability | `orders/1042` | `orders/0199a8c4-5d1e-7c2a-...` |

A sensible default: `bigint` identity for internal keys, and when ids appear in URLs or APIs where guessing or
counting them would leak information, either use `uuidv7()` keys or add a separate `public_id uuid UNIQUE` column.
Avoid random version 4 UUIDs as primary keys on large, write-heavy tables: inserts land on random pages of the index,
which makes the index larger and slower than it needs to be. Always use `bigint` rather than `integer` for tables that
can grow: running out of a 2.1-billion `integer` range in production is a painful, real-world outage.

### Composite keys

A junction table's natural primary key is the pair of foreign keys, as in `order_items (order_id, product_id)`. It
prevents duplicates and needs no extra column. The column order matters for the index behind the key (chapter 15):
`(order_id, product_id)` serves "all items of an order" directly, so Inkwell adds a separate index on `product_id` for
"all orders of a product".

## Relationship patterns

### One-to-many

The most common relationship: a foreign key on the "many" side, `orders.customer_id`. Index it (chapter 4). Decide
whether it may be `NULL` (an optional relationship) and what `ON DELETE` should do: `RESTRICT`/`NO ACTION` (the
default; usually right for business data), `CASCADE` (for parts that can't exist without their parent, such as order
items), or `SET NULL`.

### One-to-one

Two tables whose rows correspond one-to-one, typically to split rarely used or sensitive columns from a busy table.
Make the child's primary key also its foreign key, which enforces "at most one" with no extra constraint:

```sql
CREATE TABLE customer_preferences (
    customer_id bigint PRIMARY KEY REFERENCES customers ON DELETE CASCADE,
    newsletter  boolean NOT NULL DEFAULT false,
    language    text NOT NULL DEFAULT 'en'
);
INSERT INTO customer_preferences (customer_id, newsletter) VALUES (1, true);
INSERT INTO customer_preferences (customer_id) VALUES (1);
```

```text
CREATE TABLE
INSERT 0 1
ERROR:  duplicate key value violates unique constraint "customer_preferences_pkey"
DETAIL:  Key (customer_id)=(1) already exists.
```

`REFERENCES customers` with no column list refers to the other table's primary key. Before splitting a table like
this, check that you actually need to: a table with 30 columns is not a problem in itself.

### Many-to-many

A many-to-many relationship needs a **junction table**. Inkwell stores tags in an array, which is fine while a tag is
just a label. Suppose tags gain their own attributes (a description, a colour for the badge) or need renaming in one
place: then they are entities, and the array becomes a pair of tables. Migrating is straightforward:

```sql
CREATE TABLE tags (
    id   integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL UNIQUE
);
CREATE TABLE product_tags (
    product_id bigint  NOT NULL REFERENCES products ON DELETE CASCADE,
    tag_id     integer NOT NULL REFERENCES tags ON DELETE CASCADE,
    PRIMARY KEY (product_id, tag_id)
);
CREATE INDEX product_tags_tag_id_idx ON product_tags (tag_id);

INSERT INTO tags (name)
SELECT DISTINCT unnest(tags) FROM products ORDER BY 1;

INSERT INTO product_tags (product_id, tag_id)
SELECT p.id, t.id
FROM products AS p
CROSS JOIN LATERAL unnest(p.tags) AS u (name)
JOIN tags AS t ON t.name = u.name;

SELECT t.name AS tag, string_agg(p.sku, ', ' ORDER BY p.sku) AS products
FROM tags AS t
JOIN product_tags AS pt ON pt.tag_id = t.id
JOIN products AS p ON p.id = pt.product_id
GROUP BY t.name
ORDER BY t.name;
```

```text
CREATE TABLE
CREATE TABLE
CREATE INDEX
INSERT 0 2
INSERT 0 7
    tag     |            products
------------+--------------------------------
 bestseller | BP-002, FP-001, NB-001
 gift       | AC-002, FP-001, FP-002, PC-002
(2 rows)
```

Now renaming a tag is one `UPDATE` on `tags`, a misspelt tag can't exist unless someone creates it, and deleting a
product removes its tag links automatically. The cost is a join wherever you need the tags. In a real migration you
would then drop the array column, after switching the application over.

### Self-references and the exclusive arc

A foreign key can point at its own table: `categories.parent_id` builds a tree (more on hierarchies below), and an
`employees.manager_id` column builds an org chart.

Sometimes one kind of row can belong to one of **several** parents. Say staff can attach a note either to a customer or
to an order. A tempting design is a *polymorphic association*: `parent_type text, parent_id bigint`. Avoid it: no
foreign key can check that `parent_id` exists in the table named by `parent_type`, so orphans and typos are
guaranteed over time. Use one nullable foreign key per possible parent, and a `CHECK` that exactly one is set, a
pattern called an **exclusive arc**:

```sql
CREATE TABLE notes (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id bigint REFERENCES customers ON DELETE CASCADE,
    order_id    bigint REFERENCES orders ON DELETE CASCADE,
    body        text NOT NULL,
    CONSTRAINT notes_one_parent CHECK (num_nonnulls(customer_id, order_id) = 1)
);
INSERT INTO notes (customer_id, body) VALUES (3, 'Prefers delivery after 18:00.');
INSERT INTO notes (order_id, body) VALUES (7, 'Customer asked to combine with next order.');
INSERT INTO notes (customer_id, order_id, body) VALUES (3, 9, 'Which one is it?');
```

```text
CREATE TABLE
INSERT 0 1
INSERT 0 1
ERROR:  new row for relation "notes" violates check constraint "notes_one_parent"
DETAIL:  Failing row contains (3, 3, 9, Which one is it?).
```

`num_nonnulls` counts its non-`NULL` arguments. Every reference is a real foreign key with its own `ON DELETE`
behaviour, and adding a third kind of parent later means adding one column and updating the check. Add a partial index
for each foreign key column if you look notes up by it (`CREATE INDEX ON notes (order_id) WHERE order_id IS NOT
NULL`).

## Attributes that vary by kind

Pens have nib sizes, notebooks have page counts, inks have volumes. There are three ways to model attributes that
apply only to some rows:

**1. A `jsonb` column**, as Inkwell does with `products.attributes` (chapter 12). Flexible, simple and indexable with
GIN. The database doesn't enforce types or required keys unless you add `CHECK` constraints, and the planner knows
little about the values inside.

**2. Entity-attribute-value (EAV)**: a table `product_attribute_values (product_id, attribute, value text)` with one row
per attribute. It looks infinitely flexible, but everything is text, nothing can be required or typed, and reading one
product back into a row needs a pivot:

```sql
CREATE TABLE product_attribute_values (
    product_id bigint NOT NULL REFERENCES products ON DELETE CASCADE,
    attribute  text   NOT NULL,
    value      text   NOT NULL,
    PRIMARY KEY (product_id, attribute)
);
INSERT INTO product_attribute_values
SELECT p.id, a.key, a.value #>> '{}'
FROM products AS p, jsonb_each(p.attributes) AS a
WHERE jsonb_typeof(a.value) <> 'array';

SELECT p.name,
       max(v.value) FILTER (WHERE v.attribute = 'size')  AS size,
       max(v.value) FILTER (WHERE v.attribute = 'pages')::int AS pages,
       max(v.value) FILTER (WHERE v.attribute = 'ruling') AS ruling
FROM products AS p
JOIN product_attribute_values AS v ON v.product_id = p.id
WHERE p.category_id = 8
GROUP BY p.id, p.name
ORDER BY p.id;
```

```text
CREATE TABLE
INSERT 0 31
          name          | size | pages | ruling
------------------------+------+-------+--------
 Dot Grid Notebook A5   | A5   |   192 | dot
 Lined Notebook A4      | A4   |   160 | lined
 Pocket Notebook 3-pack | A6   |    64 | plain
(3 rows)
```

The `INSERT` copies the scalar attributes out of the JSON documents; an array such as the pencil grades doesn't fit in
one text value, which is already a limitation. `#>> '{}'` extracts a JSON scalar as text without its quotes. Even this
small example shows the costs of EAV: one aggregate per attribute to rebuild a row, casts everywhere, and no type
checking on the way in. EAV predates `jsonb`; in PostgreSQL, a `jsonb` column does the same job better.

**3. Subtype tables**: one table per kind with real, typed columns, sharing the parent's primary key (a one-to-one
relationship, as above):

```sql
CREATE TABLE pen_details (
    product_id bigint PRIMARY KEY REFERENCES products ON DELETE CASCADE,
    nib        text NOT NULL CHECK (nib IN ('EF', 'F', 'M', 'B')),
    material   text NOT NULL
);
INSERT INTO pen_details
SELECT id, attributes ->> 'nib', attributes ->> 'material'
FROM products
WHERE category_id = 3;
SELECT p.name, d.nib, d.material
FROM products AS p
JOIN pen_details AS d ON d.product_id = p.id;
```

```text
CREATE TABLE
INSERT 0 2
         name         | nib | material
----------------------+-----+----------
 Kestrel Fountain Pen | F   | resin
 Heron Fountain Pen   | M   | brass
(2 rows)
```

Subtype tables give full constraints and types, at the price of a table per kind and a join. They are the right
choice when the kinds are few and stable and their attributes matter to the business (an insurance system's policy
types, say). For a shop catalogue whose product kinds come and go, `jsonb` plus a few `CHECK` constraints is usually
the better trade.

## Hierarchies

Inkwell's categories form a tree stored as an **adjacency list**: each row points to its parent. It is simple, the
foreign key keeps it consistent, and moving a subtree is a single `UPDATE`. Reading a whole subtree needs a recursive
query (chapter 9), which is fast for trees of thousands of nodes.

When you read subtrees far more often than you change the tree, a **materialized path** stores each node's full path.
The `ltree` extension provides a type for such paths, with operators and index support:

```sql
CREATE EXTENSION IF NOT EXISTS ltree;
ALTER TABLE categories ADD COLUMN path ltree;

WITH RECURSIVE tree AS (
    SELECT id, slug::ltree AS path
    FROM categories
    WHERE parent_id IS NULL
    UNION ALL
    SELECT c.id, t.path || c.slug
    FROM categories AS c
    JOIN tree AS t ON c.parent_id = t.id
)
UPDATE categories AS c
SET path = tree.path
FROM tree
WHERE tree.id = c.id;

SELECT name, path FROM categories WHERE path <@ 'writing' ORDER BY path;
```

```text
CREATE EXTENSION
ALTER TABLE
UPDATE 10
      name      |            path
----------------+-----------------------------
 Writing        | writing
 Ink            | writing.ink
 Pencils        | writing.pencils
 Pens           | writing.pens
 Ballpoint pens | writing.pens.ballpoint-pens
 Fountain pens  | writing.pens.fountain-pens
(6 rows)
```

`<@` means "is a descendant of (or equal to)", and `@>` means "is an ancestor of". A GiST index on the `path` column
(`CREATE INDEX ON categories USING gist (path)`) makes these lookups fast on large trees. More operators:

```sql
SELECT name, path, nlevel(path) AS depth
FROM categories
WHERE path @> 'writing.pens.fountain-pens'
ORDER BY depth;
```

```text
     name      |            path            | depth
---------------+----------------------------+-------
 Writing       | writing                    |     1
 Pens          | writing.pens               |     2
 Fountain pens | writing.pens.fountain-pens |     3
(3 rows)
```

That lists every ancestor of the fountain pens category, root first. Since PostgreSQL 16, `ltree` labels may contain
hyphens, so the slugs could be used directly. The weakness of materialized paths is maintenance: moving a node means
rewriting the paths of its whole subtree, and nothing but your own code (or a trigger, chapter 19) keeps `path`
consistent with `parent_id`.

Two other designs you'll meet:

- A **closure table** stores every ancestor-descendant pair in a separate table `(ancestor_id, descendant_id,
  depth)`. Subtree and ancestor queries become plain joins, and both columns can have foreign keys, but the table
  grows quickly and every insert or move must update many rows.
- **Nested sets** number the nodes in a depth-first walk (`lft`, `rgt`), which makes subtree reads a range query but
  makes every insert renumber half the tree. It is mostly of historical interest.

Start with an adjacency list and recursive queries; switch to `ltree` or a closure table only when measurements show
you need to.

## Fixed sets of values

Inkwell models order status as an enum type. There are three ways to restrict a column to a fixed set of values:

| | `CHECK (status IN (...))` | enum type | lookup table + foreign key |
|---|---|---|---|
| Add a value | alter the constraint | `ALTER TYPE ... ADD VALUE` (cheap) | `INSERT` a row |
| Remove or rename a value | alter the constraint | rename: `ALTER TYPE ... RENAME VALUE`; remove: not possible | `UPDATE`/`DELETE` a row |
| Extra attributes per value (label, sort order, "is final") | no | no | yes |
| Sort order | alphabetical | declaration order | any column you like |
| Portable to other databases | yes | no | yes |

Enums are compact and convenient when the set is small, stable and purely technical. A lookup table is better when the
values carry data of their own, when non-developers manage them, or when they change often. A `CHECK` constraint is the
simplest option and fine for most small sets.

## Deleting, archiving and history

### Soft deletes

A **soft delete** marks a row as deleted instead of removing it, usually with a `deleted_at timestamptz` column. It
makes undo easy and keeps references intact. The costs are real, though:

- Every query must remember `WHERE deleted_at IS NULL`. Forgetting it once shows deleted data to users.
- Unique constraints must ignore deleted rows, which needs a **partial unique index**:

```sql
CREATE TABLE coupons (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code       text NOT NULL,
    percent    integer NOT NULL CHECK (percent BETWEEN 1 AND 50),
    deleted_at timestamptz
);
CREATE UNIQUE INDEX coupons_code_live_key ON coupons (code) WHERE deleted_at IS NULL;

INSERT INTO coupons (code, percent) VALUES ('SPRING', 10);
UPDATE coupons SET deleted_at = now() WHERE code = 'SPRING';
INSERT INTO coupons (code, percent) VALUES ('SPRING', 15);
INSERT INTO coupons (code, percent) VALUES ('SPRING', 20);
SELECT code, percent, deleted_at IS NOT NULL AS deleted FROM coupons ORDER BY id;
```

```text
CREATE TABLE
CREATE INDEX
INSERT 0 1
UPDATE 1
INSERT 0 1
ERROR:  duplicate key value violates unique constraint "coupons_code_live_key"
DETAIL:  Key (code)=(SPRING) already exists.
  code  | percent | deleted
--------+---------+---------
 SPRING |      10 | t
 SPRING |      15 | f
(2 rows)
```

The index only covers live rows, so a deleted `SPRING` doesn't block a new one, while two live ones still conflict.

- Foreign keys don't know about soft deletes: an order can still reference a "deleted" product, and `ON DELETE
  CASCADE` never fires.
- Deleted rows stay in the table and its indexes forever, slowing everything down a little.
- Privacy law may require you to really delete personal data on request.

Alternatives are often better: keep a status that means something in the business (`active = false` for products that
are no longer sold, which Inkwell already has), or move deleted rows to an **archive table** in the same transaction,
as shown in the next section. A view of live rows (chapter 20) or row-level security (chapter 21) can hide soft-deleted
rows if you do use them.

### History and auditing

To know *what changed and when*, the usual patterns are an **audit table** written by triggers (chapter 19), which
records old and new values for every change, and **temporal tables** that store each version of a row with the period
during which it was valid. PostgreSQL 18 has first-class support for the second pattern, built on range types.

## Time periods: range types

A **range** is a single value representing an interval of some ordered type. The built-in range types are
`int4range`, `int8range`, `numrange`, `daterange`, `tsrange` and `tstzrange`. A range's bounds are inclusive `[` or
exclusive `)`, and a missing bound means unbounded:

```sql
SELECT daterange('2026-03-01', '2026-03-31', '[]') AS march,
       daterange('2026-03-01', '2026-04-01') @> date '2026-03-31' AS contains_31st,
       daterange('2026-03-01', '2026-04-01') && daterange('2026-03-15', '2026-04-15') AS overlaps,
       daterange('2026-03-01', '2026-04-01') * daterange('2026-03-15', '2026-04-15') AS intersection,
       daterange('2026-03-01', '2026-04-01') -|- daterange('2026-04-01', '2026-05-01') AS adjacent,
       upper_inf(daterange('2026-03-01', NULL)) AS open_ended;
```

```text
          march          | contains_31st | overlaps |      intersection       | adjacent | open_ended
-------------------------+---------------+----------+-------------------------+----------+------------
 [2026-03-01,2026-04-01) | t             | t        | [2026-03-15,2026-04-01) | t        | t
(1 row)
```

Discrete ranges such as `daterange` are stored in canonical **half-open** form: `[2026-03-01,2026-03-31]` became
`[2026-03-01,2026-04-01)`. Half-open ranges, as with timestamps in chapter 11, tile time without gaps or overlaps:
March ends exactly where April begins. The important operators are `@>` (contains), `<@` (is contained by), `&&`
(overlaps), `-|-` (is adjacent to), `*` (intersection), `+` (union) and `-` (difference), and the functions `lower`,
`upper`, `isempty`, `lower_inf` and `upper_inf`.

Since PostgreSQL 14, every range type has a **multirange** type, a set of non-overlapping ranges, and `range_agg`
merges ranges into one:

```sql
SELECT range_agg(r) AS covered
FROM (VALUES (daterange('2026-01-01', '2026-02-01')),
             (daterange('2026-01-15', '2026-03-01')),
             (daterange('2026-04-01', '2026-05-01'))) AS v (r);
```

```text
                      covered
---------------------------------------------------
 {[2026-01-01,2026-03-01),[2026-04-01,2026-05-01)}
(1 row)
```

### Temporal primary keys (18+)

Here is a price history for products. Each row says "from this moment until that one, the price was X". Two rows for
the same product must never overlap, or the price at some moment would be ambiguous. Before PostgreSQL 18 you
expressed that with an *exclusion constraint* (shown in exercise 5). PostgreSQL 18 lets you say it directly in the
primary key with `WITHOUT OVERLAPS`:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE product_prices (
    product_id   bigint NOT NULL REFERENCES products,
    valid_during tstzrange NOT NULL,
    price        numeric(10, 2) NOT NULL CHECK (price >= 0),
    PRIMARY KEY (product_id, valid_during WITHOUT OVERLAPS)
);

INSERT INTO product_prices VALUES
    (1, '[2025-12-01, 2026-03-25)', 48.00),
    (1, '[2026-03-25, 2026-04-01)', 45.00),
    (1, '[2026-04-01,)', 48.00);

INSERT INTO product_prices VALUES (1, '[2026-03-30, 2026-04-15)', 39.00);
```

```text
CREATE EXTENSION
CREATE TABLE
INSERT 0 3
ERROR:  conflicting key value violates exclusion constraint "product_prices_pkey"
DETAIL:  Key (product_id, valid_during)=(1, ["2026-03-30 00:00:00+00","2026-04-15 00:00:00+00")) conflicts with existing key (product_id, valid_during)=(1, ["2026-03-25 00:00:00+00","2026-04-01 00:00:00+00")).
```

The key is unique in a temporal sense: the same product may appear many times, but its periods may not overlap. The
key is enforced by a GiST index, and `btree_gist` provides the GiST support for the plain `bigint` column. The last
range, `[2026-04-01,)`, has no upper bound: that price is valid until further notice.

The question "what did it cost at that moment?" becomes a containment join. Here it confirms that every order paid the
price in force at the time:

```sql
SELECT o.id AS order_id, o.created_at, oi.unit_price, pp.price AS list_price_then
FROM order_items AS oi
JOIN orders AS o ON o.id = oi.order_id
JOIN product_prices AS pp
  ON pp.product_id = oi.product_id AND pp.valid_during @> o.created_at
WHERE oi.product_id = 1
ORDER BY o.created_at;
```

```text
 order_id |       created_at       | unit_price | list_price_then
----------+------------------------+------------+-----------------
        1 | 2026-01-06 10:00:00+00 |      48.00 |           48.00
        6 | 2026-03-02 15:00:00+00 |      48.00 |           48.00
        8 | 2026-03-28 17:40:00+00 |      45.00 |           45.00
(3 rows)
```

### Temporal foreign keys (18+)

A temporal foreign key uses `PERIOD` to require that the referenced rows **cover** the referencing period, possibly
across several rows. Say the homepage features products for a period, and a product may only be featured while it has
a price:

```sql
CREATE TABLE featured_products (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id   bigint NOT NULL,
    valid_during tstzrange NOT NULL,
    FOREIGN KEY (product_id, PERIOD valid_during)
        REFERENCES product_prices (product_id, PERIOD valid_during)
);

INSERT INTO featured_products (product_id, valid_during) VALUES (1, '[2026-03-20, 2026-04-10)');
INSERT INTO featured_products (product_id, valid_during) VALUES (1, '[2025-11-20, 2025-12-10)');
```

```text
CREATE TABLE
INSERT 0 1
ERROR:  insert or update on table "featured_products" violates foreign key constraint "featured_products_product_id_valid_during_fkey"
DETAIL:  Key (product_id, valid_during)=(1, ["2025-11-20 00:00:00+00","2025-12-10 00:00:00+00")) is not present in table "product_prices".
```

The first feature spans three price rows, which together cover it, so it is accepted. The second starts before the
product had any price, so it is rejected. Temporal keys are new in PostgreSQL 18 and work with range (and multirange)
columns. The related SQL-standard syntax for changing only part of a period (`UPDATE ... FOR PORTION OF`) is not
available in PostgreSQL 18, so splitting a period is still done by hand: shorten the old row and insert the new one in
one transaction.

## Schemas: namespaces in a database

A **schema** is a namespace inside a database. Tables, views, functions and types all live in a schema, and the same
name can exist in several schemas. Everything so far has lived in `public`. Schemas are useful for grouping objects by
purpose (`app`, `audit`, `reporting`), for separating an extension's objects from yours, and for managing privileges
per group (chapter 21).

As an example, here is an archive for deleted reviews, the alternative to soft deletes mentioned above:

```sql
CREATE SCHEMA archive;
CREATE TABLE archive.reviews (
    LIKE reviews,
    archived_at timestamptz NOT NULL DEFAULT now()
);

WITH moved AS (
    DELETE FROM reviews WHERE id = 4 RETURNING *
)
INSERT INTO archive.reviews SELECT * FROM moved;

SELECT id, product_id, rating, archived_at IS NOT NULL AS archived FROM archive.reviews;
SELECT count(*) AS live_reviews FROM reviews;
```

```text
CREATE SCHEMA
CREATE TABLE
INSERT 0 1
 id | product_id | rating | archived
----+------------+--------+----------
  4 |          3 |      3 | t
(1 row)

 live_reviews
--------------
            8
(1 row)
```

`LIKE reviews` copies the column definitions (add `INCLUDING ALL` to copy defaults, constraints and indexes too). The
data-modifying CTE (chapter 9) moves the row in one statement, so it can never be in both tables or in neither. The
archive's extra `archived_at` column gets its default, because `SELECT * FROM moved` supplies only the first columns.

### search_path

An unqualified name like `reviews` is looked up in the schemas listed in the `search_path` setting, in order:

```sql
SHOW search_path;
SET search_path = archive, public;
SELECT count(*) AS reviews_seen FROM reviews;
RESET search_path;
SELECT count(*) AS reviews_seen FROM reviews;
```

```text
   search_path
-----------------
 "$user", public
(1 row)

SET
 reviews_seen
--------------
            1
(1 row)

RESET
 reviews_seen
--------------
            8
(1 row)
```

`"$user"` means "a schema with the same name as the current role, if it exists". With `archive` first in the path,
`reviews` silently meant `archive.reviews`. That is the power and the danger of `search_path`: it is convenient, but
the same query can mean different things in different sessions. In application code and especially in functions, use
schema-qualified names or set the path explicitly (chapter 19 shows why this is also a security issue). `\dn` lists
the schemas, and `\dt archive.*` lists the tables in one.

Since PostgreSQL 15, ordinary users can no longer create objects in the `public` schema of a new database unless they
are granted the right, which closed a long-standing security hole on shared databases.

### Multi-tenant applications

A SaaS application serving many customer organisations (tenants) has three main options:

| Design | Isolation | Operations |
|---|---|---|
| One database per tenant | strongest; easy to move or restore one tenant | many connections and databases to manage; cross-tenant reporting is hard |
| One schema per tenant, same tables in each | good; per-tenant backup with `pg_dump -n` | every migration runs once per schema; thousands of schemas strain the catalog and tools |
| Shared tables with a `tenant_id` column | weakest by default; strengthen with row-level security (chapter 21) | one schema to migrate; scales to many tenants; every key and index should start with `tenant_id` |

For most applications with many small tenants, shared tables with a `tenant_id` and row-level security is the
pragmatic choice. Separate databases suit a few large tenants with strict isolation requirements.

## A design checklist

Before you create a table, ask:

- What is one row? Can you say it in a sentence ("one row per product per price period")?
- What is the primary key, and which natural keys need `UNIQUE`?
- Which columns can be `NULL`, and what would `NULL` mean? Default to `NOT NULL`.
- Which rules can the database enforce: `CHECK`, foreign keys, exclusion constraints?
- Is any column a copy of a fact stored elsewhere? Is it a snapshot (keep it) or redundancy (remove it)?
- How will the table be queried, and which indexes does that need (chapter 15)?
- How big will it grow, and does anything need archiving or partitioning (chapter 20)?
- What happens on delete, for this table and for every table referencing it?

## Key takeaways

- Model the business first: entities become tables, attributes columns, relationships foreign keys or junction tables,
  rules constraints.
- Normalise so that each fact is stored once; redundancy causes update, insertion and deletion anomalies. Snapshots of
  facts at a point in time (prices on an order) are not redundancy.
- Denormalise only deliberately, preferably in views, materialized views or trigger-maintained columns.
- Prefer surrogate primary keys plus `UNIQUE` natural keys; use natural keys for small, stable standards like country
  codes. Use `bigint`, not `integer`; use `uuidv7()` when ids must be generated anywhere or must not be guessable.
- One-to-one: shared primary key. Many-to-many: a junction table with a composite key. Several possible parents: an
  exclusive arc with `num_nonnulls`, never a polymorphic `type`/`id` pair.
- For varying attributes prefer `jsonb` (with `CHECK`s) or subtype tables over EAV.
- Trees: start with an adjacency list and recursive queries; use `ltree` or a closure table when reads dominate.
- Soft deletes have hidden costs (every query, unique constraints, foreign keys); consider archive tables.
- Range types model periods; `WITHOUT OVERLAPS` primary keys and `PERIOD` foreign keys (18+) enforce temporal rules.
- Schemas are namespaces; qualify names or control `search_path`. For multi-tenancy, shared tables with `tenant_id`
  and row-level security usually scale best.

## Exercises

1. **Spot the problems.** A colleague proposes
   `shipments (order_id, customer_email, carrier_name, carrier_phone, tracking_number, shipped_at)`, one row per
   shipment. Which columns violate which normal form? Write a better design as `CREATE TABLE` statements that fit the
   Inkwell schema.

<details>
<summary>Solution</summary>

`customer_email` depends on the order's customer, not on the shipment: it is already reachable through
`orders.customer_id`, so it violates 3NF and should go. `carrier_phone` depends on the carrier, not on the shipment
(3NF again): every shipment by the same carrier repeats it. Carriers are an entity of their own.

```sql
CREATE TABLE carriers (
    id    integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name  text NOT NULL UNIQUE,
    phone text NOT NULL
);
CREATE TABLE shipments (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id        bigint NOT NULL REFERENCES orders,
    carrier_id      integer NOT NULL REFERENCES carriers,
    tracking_number text NOT NULL,
    shipped_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (carrier_id, tracking_number)
);
CREATE INDEX shipments_order_id_idx ON shipments (order_id);
CREATE INDEX shipments_carrier_id_idx ON shipments (carrier_id);
```

```text
CREATE TABLE
CREATE TABLE
CREATE INDEX
CREATE INDEX
```

A tracking number is unique per carrier, not globally, hence the composite `UNIQUE`. Allowing several shipments per
order (rather than a column on `orders`) supports split deliveries.

</details>

2. **Relational division.** Using the `tags` and `product_tags` tables from this chapter (re-create them first), find
   the products that have **both** the `gift` and the `bestseller` tags.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE tags (
    id   integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL UNIQUE
);
CREATE TABLE product_tags (
    product_id bigint  NOT NULL REFERENCES products ON DELETE CASCADE,
    tag_id     integer NOT NULL REFERENCES tags ON DELETE CASCADE,
    PRIMARY KEY (product_id, tag_id)
);
INSERT INTO tags (name) SELECT DISTINCT unnest(tags) FROM products;
INSERT INTO product_tags
SELECT p.id, t.id
FROM products AS p
CROSS JOIN LATERAL unnest(p.tags) AS u (name)
JOIN tags AS t ON t.name = u.name;

SELECT p.sku, p.name
FROM products AS p
JOIN product_tags AS pt ON pt.product_id = p.id
JOIN tags AS t ON t.id = pt.tag_id
WHERE t.name IN ('gift', 'bestseller')
GROUP BY p.id, p.sku, p.name
HAVING count(*) = 2;
```

```text
CREATE TABLE
CREATE TABLE
INSERT 0 2
INSERT 0 7
  sku   |         name
--------+----------------------
 FP-001 | Kestrel Fountain Pen
(1 row)
```

"Has all of these" is called *relational division*. Filter to the wanted tags, group per product and keep the groups
that found all of them. `count(*) = 2` is safe because the primary key prevents a product from having the same tag
twice; with the array design this was simply `tags @> ARRAY['gift', 'bestseller']`.

</details>

3. **One parent, checked.** Add a `review_replies` table in which the shop can reply either to a review or to another
   reply (threads), but not both. Insert a reply to review 1 and a reply to that reply, and show that a reply with
   neither parent is rejected.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE review_replies (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    review_id       bigint REFERENCES reviews ON DELETE CASCADE,
    parent_reply_id bigint REFERENCES review_replies ON DELETE CASCADE,
    body            text NOT NULL,
    CONSTRAINT review_replies_one_parent CHECK (num_nonnulls(review_id, parent_reply_id) = 1)
);
INSERT INTO review_replies (review_id, body) VALUES (1, 'Thank you, Ana!');
INSERT INTO review_replies (parent_reply_id, body) VALUES (1, 'You are welcome.');
INSERT INTO review_replies (body) VALUES ('Orphan reply');
SELECT id, review_id, parent_reply_id, body FROM review_replies ORDER BY id;
```

```text
CREATE TABLE
INSERT 0 1
INSERT 0 1
ERROR:  new row for relation "review_replies" violates check constraint "review_replies_one_parent"
DETAIL:  Failing row contains (3, null, null, Orphan reply).
 id | review_id | parent_reply_id |       body
----+-----------+-----------------+------------------
  1 |         1 |                 | Thank you, Ana!
  2 |           |               1 | You are welcome.
(2 rows)
```

The table combines both patterns from this chapter: a self-reference (`parent_reply_id`) and an exclusive arc. To read
a whole thread you would use a recursive query starting at the replies with `review_id = 1`.

</details>

4. **Price on a date.** Re-create `product_prices` as in this chapter (with its three rows for product 1). Write a
   query that returns the Kestrel pen's price on 2026-03-28 at noon UTC and on 2026-06-01, and then show the full
   history with a column saying whether each period is the current one.

<details>
<summary>Solution</summary>

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE TABLE product_prices (
    product_id   bigint NOT NULL REFERENCES products,
    valid_during tstzrange NOT NULL,
    price        numeric(10, 2) NOT NULL CHECK (price >= 0),
    PRIMARY KEY (product_id, valid_during WITHOUT OVERLAPS)
);
INSERT INTO product_prices VALUES
    (1, '[2025-12-01, 2026-03-25)', 48.00),
    (1, '[2026-03-25, 2026-04-01)', 45.00),
    (1, '[2026-04-01,)', 48.00);

SELECT t.at, pp.price
FROM (VALUES (timestamptz '2026-03-28 12:00+00'), (timestamptz '2026-06-01 00:00+00')) AS t (at)
JOIN product_prices AS pp ON pp.product_id = 1 AND pp.valid_during @> t.at
ORDER BY t.at;

SELECT lower(valid_during) AS valid_from, upper(valid_during) AS valid_until, price,
       valid_during @> now() AS is_current
FROM product_prices
WHERE product_id = 1
ORDER BY valid_during;
```

```text
CREATE EXTENSION
CREATE TABLE
INSERT 0 3
           at           | price
------------------------+-------
 2026-03-28 12:00:00+00 | 45.00
 2026-06-01 00:00:00+00 | 48.00
(2 rows)

       valid_from       |      valid_until       | price | is_current
------------------------+------------------------+-------+------------
 2025-12-01 00:00:00+00 | 2026-03-25 00:00:00+00 | 48.00 | f
 2026-03-25 00:00:00+00 | 2026-04-01 00:00:00+00 | 45.00 | f
 2026-04-01 00:00:00+00 |                        | 48.00 | t
(3 rows)
```

Because the periods can't overlap, the containment join finds at most one price for each moment. Ranges sort by their
lower bound first, so `ORDER BY valid_during` is chronological. An empty `valid_until` means "no end".

</details>

5. **No double bookings.** The shop runs calligraphy workshops in two rooms. Create a `room_bookings (room, during,
   booked_by)` table with an **exclusion constraint** (the pre-18 way, still useful for rules that aren't keys) so
   that the same room can't be booked twice at overlapping times. Show that an overlapping booking of the same room
   fails, while the same time in the other room, and a booking that starts exactly when another ends, succeed.

<details>
<summary>Solution</summary>

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE TABLE room_bookings (
    id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    room      text NOT NULL,
    during    tstzrange NOT NULL,
    booked_by text NOT NULL,
    EXCLUDE USING gist (room WITH =, during WITH &&)
);
INSERT INTO room_bookings (room, during, booked_by)
VALUES ('Atelier', '[2026-05-02 10:00+00, 2026-05-02 12:00+00)', 'Beginners');
INSERT INTO room_bookings (room, during, booked_by)
VALUES ('Atelier', '[2026-05-02 11:00+00, 2026-05-02 13:00+00)', 'Copperplate');
INSERT INTO room_bookings (room, during, booked_by)
VALUES ('Studio', '[2026-05-02 11:00+00, 2026-05-02 13:00+00)', 'Copperplate');
INSERT INTO room_bookings (room, during, booked_by)
VALUES ('Atelier', '[2026-05-02 12:00+00, 2026-05-02 14:00+00)', 'Italic');
```

```text
CREATE EXTENSION
CREATE TABLE
INSERT 0 1
ERROR:  conflicting key value violates exclusion constraint "room_bookings_room_during_excl"
DETAIL:  Key (room, during)=(Atelier, ["2026-05-02 11:00:00+00","2026-05-02 13:00:00+00")) conflicts with existing key (room, during)=(Atelier, ["2026-05-02 10:00:00+00","2026-05-02 12:00:00+00")).
INSERT 0 1
INSERT 0 1
```

`EXCLUDE USING gist (room WITH =, during WITH &&)` means "no two rows may have equal rooms **and** overlapping
periods". The half-open ranges make the 10:00–12:00 and 12:00–14:00 bookings adjacent rather than overlapping. In
PostgreSQL 18, `PRIMARY KEY (room, during WITHOUT OVERLAPS)` would express the same rule; an exclusion constraint can
also express rules that aren't keys, for example only checking bookings whose status isn't `cancelled` (with a
`WHERE` clause).

</details>

6. **Paths for display.** Without adding any column, write a recursive query that shows every category with its full
   path as text, like `Writing > Pens > Fountain pens`, ordered by that path. Then explain when storing an `ltree`
   path would be worth it.

<details>
<summary>Solution</summary>

```sql
WITH RECURSIVE tree AS (
    SELECT id, name, name AS full_path
    FROM categories
    WHERE parent_id IS NULL
    UNION ALL
    SELECT c.id, c.name, t.full_path || ' > ' || c.name
    FROM categories AS c
    JOIN tree AS t ON c.parent_id = t.id
)
SELECT full_path FROM tree ORDER BY full_path;
```

```text
            full_path
---------------------------------
 Accessories
 Paper
 Paper > Loose paper
 Paper > Notebooks
 Writing
 Writing > Ink
 Writing > Pencils
 Writing > Pens
 Writing > Pens > Ballpoint pens
 Writing > Pens > Fountain pens
(10 rows)
```

For a tree of ten categories, computing the paths on demand is instant, and there is nothing to keep in sync. Storing
an `ltree` path pays off when the tree is large (thousands of nodes or more), when subtree and ancestor lookups happen
in hot queries, especially combined with other filters that an index should serve, and when the tree changes rarely.

</details>

---
← Previous: [Full-text search](13-full-text-search.md) | Next: [Indexes](15-indexes.md) →
