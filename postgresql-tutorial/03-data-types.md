# Chapter 3 — Data types

## What you'll learn

- Why choosing the right data type matters for correctness, storage and speed
- Integers, exact decimals (`numeric`) and floating point, and why money is never a `float`
- Text types, string literals, escapes and dollar quoting
- Booleans, dates, times, timestamps with and without time zone, and intervals
- `uuid` (including time-ordered `uuidv7()`, PostgreSQL 18+), `bytea`, enums and arrays
- What `NULL` is and how it behaves in expressions
- How casting works, and why PostgreSQL refuses to guess
- A cheat sheet for choosing types

Every column, every expression and every function argument in PostgreSQL has a **data type**. The type decides which
values are allowed, how they are stored, how they compare and sort, and which operators and functions apply. A good
type is your first constraint: a `date` column cannot hold February 30th, and an `integer` column cannot hold
`'n/a'`. This chapter is a tour of the types you'll use every day. Run the examples in `psql`, connected to `inkwell`.

## Numbers

### Integers

| Type | Size | Range | Use for |
|---|---|---|---|
| `smallint` (`int2`) | 2 bytes | −32,768 to 32,767 | small counters where space really matters |
| `integer` (`int`, `int4`) | 4 bytes | about ±2.1 billion | quantities, counts, most numbers |
| `bigint` (`int8`) | 8 bytes | about ±9.2 × 10¹⁸ | ids of tables that can grow large, big sums |

Integer arithmetic that leaves the range is an **error**, never a silent wrap-around:

```sql
SELECT 2147483647 + 1;
```

```text
ERROR:  integer out of range
```

```sql
SELECT 2147483647::bigint + 1;
```

```text
  ?column?
------------
 2147483648
(1 row)
```

`::bigint` is a **cast**: it converts the value to another type (more on casting at the end of this chapter). Once one
operand is a `bigint`, the arithmetic is done in `bigint`. Many production systems have hit the 2.1 billion limit of an
`integer` primary key; the fix (changing the column to `bigint`) rewrites the whole table, which on a big table means a
long outage. Use `bigint` for ids of tables that may grow.

Integer division throws away the remainder. Make one side a decimal to get a decimal result:

```sql
SELECT 7 / 2 AS int_div, 7 % 2 AS remainder, 7 / 2.0 AS numeric_div;
```

```text
 int_div | remainder |    numeric_div
---------+-----------+--------------------
       3 |         1 | 3.5000000000000000
(1 row)
```

That surprises people in averages and percentages: `count_a / count_b * 100` is 0 whenever `count_a < count_b`.
Write `count_a * 100.0 / count_b` instead.

### Exact decimals: `numeric`

`numeric` (also spelled `decimal`) stores decimal numbers **exactly**, with up to 131,072 digits before the decimal
point and 16,383 after. `numeric(p, s)` limits it to `p` significant digits, `s` of them after the point. Values are
rounded to the scale, and a value that doesn't fit is an error:

```sql
SELECT 1.005::numeric(10,2) AS rounded, 10::numeric(3,1) AS fits;
```

```text
 rounded | fits
---------+------
    1.01 | 10.0
(1 row)
```

```sql
SELECT 100::numeric(3,1);
```

```text
ERROR:  numeric field overflow
DETAIL:  A field with precision 3, scale 1 must round to an absolute value less than 10^2.
```

`numeric` rounds half away from zero, the way people expect in money calculations.

### Floating point: `real` and `double precision`

`real` (`float4`, about 6 significant digits) and `double precision` (`float8`, about 15 digits) are IEEE 754 binary
floating-point numbers, the same as `number` in JavaScript or `float` in PHP. They are fast and compact, but **they
cannot represent most decimal fractions exactly**:

```sql
SELECT 0.1 + 0.2 AS numeric_sum, 0.1::float8 + 0.2::float8 AS float_sum;
```

```text
 numeric_sum |      float_sum
-------------+---------------------
         0.3 | 0.30000000000000004
(1 row)
```

```sql
SELECT 0.1 + 0.2 = 0.3 AS numeric_equal,
       0.1::float8 + 0.2::float8 = 0.3::float8 AS float_equal;
```

```text
 numeric_equal | float_equal
---------------+-------------
 t             | f
(1 row)
```

A literal such as `0.1` is a `numeric` in PostgreSQL unless you cast it, which is why the first sum is exact. Rounding
also differs: `round(2.5)` on a `numeric` gives `3`, but `round(2.5::float8)` gives `2`, because floating-point
rounding goes to the nearest even number.

The rule is simple: **money and anything people add up by hand is `numeric`** (or an integer number of cents).
Floating point is for measurements and science, where tiny relative errors don't matter: sensor readings, coordinates,
statistical results.

Both `numeric` and the float types support the special values `'NaN'`, `'Infinity'` and `'-Infinity'`. There is also a
`money` type, but it depends on the server's locale setting for its currency symbol and formatting. Avoid it.

### Number literals

`42` is an `integer`, `3000000000` (too big for `integer`) is a `bigint`, and anything with a decimal point or an
exponent (`1.5`, `1e3`) is a `numeric`. PostgreSQL 16 added underscores as separators and hexadecimal, octal and binary
integer literals:

```sql
SELECT 1_000_000 AS big, 0x1F AS hex, 0b101 AS bin, 1e3 AS sci;
```

```text
   big   | hex | bin | sci
---------+-----+-----+------
 1000000 |  31 |   5 | 1000
(1 row)
```

`pg_typeof()` tells you the type of any expression, which is the quickest way to answer "what type is this?":

```sql
SELECT pg_typeof(1), pg_typeof(3000000000), pg_typeof(1.5), pg_typeof('a');
```

```text
 pg_typeof | pg_typeof | pg_typeof | pg_typeof
-----------+-----------+-----------+-----------
 integer   | bigint    | numeric   | unknown
(1 row)
```

A quoted literal such as `'a'` starts life as type `unknown`: PostgreSQL decides what it is from context, as you'll see
under casting.

## Text

| Type | Meaning |
|---|---|
| `text` | a string of any length (up to 1 GB) |
| `varchar(n)` (`character varying(n)`) | a string of at most `n` characters |
| `char(n)` (`character(n)`) | exactly `n` characters, padded with spaces |

In PostgreSQL **all three are stored the same way** and perform the same. `varchar(n)` is just `text` plus a length
check, and `char(n)` is `text` plus padding rules that cause bugs:

```sql
SELECT 'abc'::char(5) AS padded,
       length('abc'::char(5)) AS len,
       'abc'::char(5) || 'x' AS concat;
```

```text
 padded | len | concat
--------+-----+--------
 abc    |   3 | abcx
(1 row)
```

The value is stored as `'abc  '`, yet `length()` ignores the trailing spaces and concatenation drops them. Never use
`char(n)`.

Use `text` by default. When a column has a real business limit (a two-letter country code, a 50-character display
name), enforce it with a `CHECK` constraint (chapter 4), which can say exactly what is allowed and is easy to change.
`varchar(n)` also works, and is common, but has a trap: an explicit cast **silently truncates**:

```sql
SELECT 'too long'::varchar(5);
```

```text
 varchar
---------
 too l
(1 row)
```

Storing a too-long value in a `varchar(5)` column, on the other hand, is an error, as it should be:

```sql
CREATE TABLE type_demo (code varchar(5));
INSERT INTO type_demo (code) VALUES ('too long');
```

```text
CREATE TABLE
ERROR:  value too long for type character varying(5)
```

### String literals

Strings go in single quotes. A single quote inside is doubled. For text with many quotes, or code, use **dollar
quoting**: everything between `$$` and `$$` (or `$tag$` and `$tag$`) is taken literally:

```sql
SELECT 'It''s' AS doubled, $$It's "quoted"$$ AS dollar_quoted;
```

```text
 doubled | dollar_quoted
---------+---------------
 It's    | It's "quoted"
(1 row)
```

Backslashes are ordinary characters in standard strings. For C-style escapes (`\n`, `\t`, `é`), put an `E` before
the quote: `E'line one\nline two'`.

`||` concatenates. If either side is `NULL`, the result is `NULL`. `concat()` treats `NULL` as an empty string:

```sql
SELECT 'Ink' || 'well' AS joined, 'Ink' || NULL AS with_null, concat('Ink', NULL, 'well') AS concat;
```

```text
 joined  | with_null | concat
---------+-----------+---------
 Inkwell |           | Inkwell
(1 row)
```

### Encodings and collations, briefly

Every database has a **character encoding**, which today should always be `UTF8`; `\l` shows it. Text in PostgreSQL is
always valid in that encoding: an invalid UTF-8 byte sequence is rejected on input. Lengths and positions count
characters, not bytes (`octet_length()` counts bytes).

Every text value also has a **collation**, which decides how strings sort and compare: whether `'a' < 'B'`, where
accented letters go. It comes from the database's locale unless you ask for another. Chapter 11 covers collations in
depth.

## Booleans

`boolean` has three states: `true`, `false` and `NULL` (unknown). It accepts several spellings on input and prints
`t` or `f`:

```sql
SELECT 'yes'::boolean, 'off'::boolean, 't'::boolean, '1'::boolean;
```

```text
 bool | bool | bool | bool
------+------+------+------
 t    | f    | t    | t
(1 row)
```

```sql
SELECT 'maybe'::boolean;
```

```text
ERROR:  invalid input syntax for type boolean: "maybe"
LINE 1: SELECT 'maybe'::boolean;
               ^
```

In `WHERE` clauses you can use a boolean column directly: `WHERE active`, `WHERE NOT active`.

## Dates and times

| Type | Stores | Example |
|---|---|---|
| `date` | a calendar date | `2026-03-28` |
| `time` | a time of day, no date | `17:40:00` |
| `timestamp` (`timestamp without time zone`) | a date and time, **no** time zone: a reading of a wall clock somewhere | `2026-03-28 17:40:00` |
| `timestamptz` (`timestamp with time zone`) | an **absolute instant** in time | `2026-03-28 17:40:00+00` |
| `interval` | a duration | `1 day 02:00:00` |

Timestamps have microsecond resolution and cover 4713 BC to 294276 AD. Input is easiest in ISO 8601 format
(`2026-03-28` and `2026-03-28 17:40:00+02`), which works regardless of the `DateStyle` setting.

### `timestamptz` is the one you want

The names are misleading. A `timestamptz` does **not** store a time zone. It stores an instant (internally, the number
of microseconds since 2000-01-01 00:00 UTC). When you put a value in, PostgreSQL converts it from the time zone you
wrote (or the session's `TimeZone` setting) to UTC; when you read it, it converts it to the **session's** time zone for
display. The same stored instant, viewed from three time zones:

```sql
SET timezone = 'UTC';
SELECT timestamptz '2026-03-28 17:40:00+00' AS stored;
```

```text
SET
         stored
------------------------
 2026-03-28 17:40:00+00
(1 row)
```

```sql
SET timezone = 'America/New_York';
SELECT timestamptz '2026-03-28 17:40:00+00' AS stored;
```

```text
SET
         stored
------------------------
 2026-03-28 13:40:00-04
(1 row)
```

```sql
SET timezone = 'Asia/Tokyo';
SELECT timestamptz '2026-03-28 17:40:00+00' AS stored,
       timestamp   '2026-03-28 17:40:00+00' AS no_tz;
```

```text
SET
         stored         |        no_tz
------------------------+---------------------
 2026-03-29 02:40:00+09 | 2026-03-28 17:40:00
(1 row)
```

```sql
RESET timezone;
```

```text
RESET
```

The plain `timestamp` simply **ignored** the `+00` and kept the wall-clock reading. Two `timestamp` values written from
different time zones can't be compared meaningfully, and daylight-saving changes produce ambiguous or impossible values.
Use `timestamptz` for anything that happened at a moment in time: when an order was placed, when a row was created,
when a token expires. Use `timestamp` only for genuinely zone-less wall-clock values, such as "the shop opens at 09:00
local time on 2026-12-24" in a calendar app, and even then store the time zone name next to it.

`SET` changes a setting for your session only; `RESET` restores the default. The default `TimeZone` comes from the
server configuration, and can be set per database or per role (chapter 11). Your application's driver usually sets
it too (chapter 22).

### Date arithmetic

```sql
SELECT date '2026-02-28' + 1 AS next_day,
       date '2026-03-01' - date '2026-02-01' AS days,
       date '2026-01-31' + interval '1 month' AS plus_month;
```

```text
  next_day  | days |     plus_month
------------+------+---------------------
 2026-03-01 |   28 | 2026-02-28 00:00:00
(1 row)
```

A `date` plus an integer adds days; a `date` minus a `date` is a number of days. Adding an `interval` gives a
timestamp. Month arithmetic clamps to the end of the month: January 31st plus one month is February 28th.

```sql
SELECT interval '1 day 2 hours' * 3 AS tripled,
       timestamptz '2026-01-01 00:00+00' - timestamptz '2025-12-24 18:30+00' AS elapsed;
```

```text
     tripled     |     elapsed
-----------------+-----------------
 3 days 06:00:00 | 7 days 05:30:00
(1 row)
```

Invalid dates are rejected:

```sql
SELECT '2026-02-30'::date;
```

```text
ERROR:  date/time field value out of range: "2026-02-30"
LINE 1: SELECT '2026-02-30'::date;
               ^
```

`now()` returns the current `timestamptz`; it is fixed at the start of the transaction, so every row inserted by one
statement gets the same value. `current_date` is today's date in the session's time zone. Chapter 11 goes much deeper
into time zones, formatting, truncation and series of dates.

## UUIDs

A `uuid` is a 128-bit identifier written as 32 hexadecimal digits, stored in 16 bytes. UUIDs can be generated anywhere
(in the database, in the application, offline on a phone) without coordination, and they don't reveal how many rows a
table has.

```sql
SELECT gen_random_uuid() AS v4, uuidv7() AS v7;
```

```text
                  v4                  |                  v7
--------------------------------------+--------------------------------------
 42a6c4ce-faea-473c-90d4-0a7618bda18f | 01a12ba6-687c-7559-b9d6-e46676286e2f
(1 row)
```

(Your values will differ, of course.)

- `gen_random_uuid()` (or `uuidv4()`, PostgreSQL 18+) makes a **version 4** UUID: 122 random bits.
- `uuidv7()` (PostgreSQL 18+) makes a **version 7** UUID: the first 48 bits are a millisecond timestamp, the rest
  random. Values generated later sort later.

That ordering matters for primary keys. Random v4 UUIDs land at random positions in the primary-key index, so inserts
touch pages all over it, which hurts cache efficiency and causes index bloat on large tables. Time-ordered v7 UUIDs
are appended at the "right-hand end" of the index like an increasing integer. If you want UUID keys, prefer `uuidv7()`.
You can even get the timestamp back out:

```sql
SELECT uuid_extract_timestamp('01a12ba6-687c-7559-b9d6-e46676286e2f');
```

```text
   uuid_extract_timestamp
----------------------------
 2026-10-11 15:48:08.444+00
(1 row)
```

That is also the trade-off: a v7 UUID reveals when it was created. On versions before 18, generate v7 UUIDs in the
application, or use an extension.

## Binary data: `bytea`

`bytea` stores raw bytes: hashes, small files, encrypted values. It is printed in hex format, with a `\x` prefix:

```sql
SELECT '\x48656c6c6f'::bytea AS raw,
       convert_from('\x48656c6c6f'::bytea, 'UTF8') AS as_text;
```

```text
     raw      | as_text
--------------+---------
 \x48656c6c6f | Hello
(1 row)
```

For large files (images, PDFs), storing them in object storage (such as S3) and keeping only the path and metadata in
the database is usually the better design; the database's backups, replicas and cache all pay for every byte you store.

## Enums

An **enumerated type** is a fixed, ordered list of labels. Inkwell's orders move through a series of states, which is
a natural enum:

```sql
CREATE TYPE order_status AS ENUM ('pending', 'paid', 'shipped', 'delivered', 'cancelled');
```

```text
CREATE TYPE
```

```sql
SELECT 'shipped'::order_status > 'paid'::order_status AS later,
       enum_range(NULL::order_status) AS all_values;
```

```text
 later |                 all_values
-------+--------------------------------------------
 t     | {pending,paid,shipped,delivered,cancelled}
(1 row)
```

```sql
SELECT 'lost'::order_status;
```

```text
ERROR:  invalid input value for enum order_status: "lost"
LINE 1: SELECT 'lost'::order_status;
               ^
```

Enums sort in declaration order, not alphabetically, and take 4 bytes each. You can add labels later
(`ALTER TYPE order_status ADD VALUE 'refunded' AFTER 'delivered'`), but you can't remove or rename one easily. Use an
enum for a short list that is part of your program's logic and rarely changes. When the list is data that users or
admins manage (product categories, countries), use a separate table with a foreign key instead (chapter 4). A third
option, a `text` column with a `CHECK (status IN (...))` constraint, is easy to change and portable to other databases.

You'll use `order_status` in the next chapter.

## Arrays

Any type can be made into an array by adding `[]`: `integer[]`, `text[]`. Write array values with the `ARRAY[...]`
constructor or as a string literal in braces:

```sql
SELECT ARRAY['gift', 'bestseller'] AS constructed,
       '{gift,bestseller}'::text[] AS literal,
       (ARRAY[10, 20, 30])[1] AS first,
       (ARRAY[10, 20, 30])[4] AS out_of_range,
       20 = ANY (ARRAY[10, 20, 30]) AS contains_20;
```

```text
    constructed    |      literal      | first | out_of_range | contains_20
-------------------+-------------------+-------+--------------+-------------
 {gift,bestseller} | {gift,bestseller} |    10 |              | t
(1 row)
```

Arrays are **1-based**, and reading past the end gives `NULL`, not an error. Arrays are good for small lists of
values that belong to one row and are always read with it, such as tags on a product. They are a poor replacement for a
proper related table when you need to look up, join on or constrain individual elements (for example, a foreign key
from each element). Chapter 12 covers array functions and indexes.

## Other types you'll meet

PostgreSQL has many more built-in types. The ones covered later in this tutorial:

| Type | What it holds | Chapter |
|---|---|---|
| `jsonb`, `json` | JSON documents | 12 |
| `int4range`, `tstzrange`, `daterange`, … | ranges of values, e.g. a validity period | 14 |
| `tsvector`, `tsquery` | full-text search documents and queries | 13 |
| `inet`, `cidr`, `macaddr` | IP addresses, networks, MAC addresses | — |
| `point`, `line`, `polygon`, … | 2D geometry (use PostGIS for real geographic work) | — |
| `xml` | XML documents | — |

The network types are worth knowing about: `'192.168.1.10'::inet << '192.168.1.0/24'::cidr` tests whether an address is
in a subnet, and they validate their input, which a `text` column can't.

## NULL

`NULL` means "unknown" or "missing". It is not zero, not an empty string, not `false`. Because it is unknown, almost any
expression involving `NULL` is `NULL` too, including comparisons:

```sql
SELECT NULL = NULL AS equals, NULL IS NULL AS is_null, NULL + 1 AS plus_one;
```

```text
 equals | is_null | plus_one
--------+---------+----------
        | t       |
(1 row)
```

`NULL = NULL` is not true; it is `NULL` ("is one unknown value equal to another unknown value? Unknown."). That is
why you test for `NULL` with `IS NULL` and `IS NOT NULL`, never with `= NULL`. Chapter 6 covers how `NULL` behaves in
`WHERE` clauses, sorting, and logic, and the functions (`coalesce`, `nullif`, `IS DISTINCT FROM`) that tame it.

Every type can hold `NULL` unless the column is declared `NOT NULL`. Most columns should be. Allow `NULL` only when
"unknown" or "not applicable" is a legitimate state, like a `shipped_at` timestamp for an order that hasn't shipped.

## Casting

A **cast** converts a value to another type. There are three equivalent syntaxes:

```sql
SELECT CAST('42' AS integer) AS standard_sql,
       '42'::integer AS postgres_style,
       integer '42' AS typed_literal;
```

```text
 standard_sql | postgres_style | typed_literal
--------------+----------------+---------------
           42 |             42 |            42
(1 row)
```

`::` is the most common in PostgreSQL code. The typed-literal form (`date '2026-03-28'`, `interval '1 day'`) works
only for literal strings and reads well.

Converting a decimal to an integer **rounds** (half away from zero); it doesn't truncate:

```sql
SELECT 3.7::integer AS a, 3.5::integer AS b, (-3.5)::integer AS c, trunc(3.7)::integer AS d;
```

```text
 a | b | c  | d
---+---+----+---
 4 | 4 | -4 | 3
(1 row)
```

Input that doesn't parse is an error:

```sql
SELECT 'abc'::integer;
```

```text
ERROR:  invalid input syntax for type integer: "abc"
LINE 1: SELECT 'abc'::integer;
               ^
```

### PostgreSQL doesn't guess

Some databases convert freely between strings and numbers. PostgreSQL resolves the types of quoted literals from
context, but it never converts a value that already has a type in a way that could lose meaning:

```sql
SELECT '5' + 3 AS works;
```

```text
 works
-------
     8
(1 row)
```

```sql
SELECT '5'::text + 3;
```

```text
ERROR:  operator does not exist: text + integer
LINE 1: SELECT '5'::text + 3;
                         ^
HINT:  No operator matches the given name and argument types. You might need to add explicit type casts.
```

In the first query `'5'` is an untyped literal, so PostgreSQL treats it as the integer the `+` needs. In the second it
is explicitly `text`, and there is no `text + integer` operator. You'll see this error when comparing a `text` column
to a number or passing the wrong type to a function. The fix is an explicit cast of the right side, and the question
"why do I have numbers in a text column?" is worth asking too.

## Choosing types: a cheat sheet

| Data | Type | Notes |
|---|---|---|
| Surrogate primary key | `bigint` identity column (chapter 4), or `uuid` with `uuidv7()` | `integer` only for small lookup tables |
| Money, prices, quantities of money | `numeric(p, s)` or `bigint` cents | never `float`/`real`/`money` |
| Counts, quantities | `integer` | `bigint` if it can exceed 2 billion |
| Measurements, scientific values | `double precision` | |
| Names, descriptions, codes | `text` (+ `CHECK` for limits) | not `char(n)` |
| Yes/no flags | `boolean` | consider whether a timestamp (`deleted_at`) carries more information |
| A moment in time | `timestamptz` | the default for "when did this happen" |
| A calendar date (birthday, due date) | `date` | |
| A duration | `interval` | |
| A short fixed list of states | an enum, or `text` + `CHECK` | a table if users manage the list |
| Semi-structured attributes | `jsonb` | chapter 12; not a replacement for columns |
| IP addresses | `inet` | |

Storage sizes, for when you design big tables: `boolean` 1 byte, `smallint` 2, `integer` and `date` and `real` 4,
`bigint`, `double precision`, `timestamp`, `timestamptz` and `time` 8, `interval` and `uuid` 16. `text`, `numeric` and
`bytea` are variable-length: the data plus a 1-byte header for short values (4 bytes for values over 126 bytes).

Clean up the demo table:

```sql
DROP TABLE type_demo;
```

```text
DROP TABLE
```

## Key takeaways

- The data type is the first line of validation. PostgreSQL rejects invalid input instead of guessing, and integer
  overflow is an error.
- Use `integer` for counts, `bigint` for ids of growing tables, and `numeric` (or integer cents) for money. Floating
  point can't represent `0.1` exactly.
- `text` is the default string type; `varchar(n)` adds only a length check, and `char(n)` should never be used.
- Use `timestamptz` for moments in time. It stores an absolute instant and displays it in the session's time zone.
  Plain `timestamp` ignores time zones entirely.
- `uuidv7()` (PostgreSQL 18+) gives time-ordered UUIDs that behave much better as primary keys than random ones.
- Enums are ordered lists of labels; good for fixed states, awkward for lists that change.
- Arrays are 1-based, and out-of-range access returns `NULL`.
- `NULL` is unknown: `NULL = NULL` is `NULL`. Test with `IS NULL`.
- Cast with `::type` or `CAST(x AS type)`. Untyped literals adapt to context; typed values never convert implicitly in
  surprising ways.

## Exercises

1. **Type detective.** Without running them first, predict the type and value of each expression, then check with
   `pg_typeof()`: `10 / 4`, `10 / 4.0`, `10 / 4::numeric`, `2147483647 + 1::bigint`, `'2026-03-01'::date - 1`.

<details>
<summary>Solution</summary>

```sql
SELECT 10 / 4 AS a, pg_typeof(10 / 4),
       10 / 4.0 AS b, pg_typeof(10 / 4.0),
       10 / 4::numeric AS c,
       2147483647 + 1::bigint AS d, pg_typeof(2147483647 + 1::bigint),
       '2026-03-01'::date - 1 AS e, pg_typeof('2026-03-01'::date - 1);
```

- `10 / 4` is `2` (`integer`): integer division.
- `10 / 4.0` is `2.5000000000000000` (`numeric`): one operand is a `numeric`.
- `10 / 4::numeric` is also `2.5000000000000000`: the cast binds tighter than `/`, so only the `4` is cast, which is
  enough.
- `2147483647 + 1::bigint` is `2147483648` (`bigint`): the `1` is cast first, so the addition happens in `bigint`.
- `'2026-03-01'::date - 1` is `2026-02-28` (`date`): subtracting an integer from a date subtracts days.

</details>

2. **Money in floats.** Show the problem with floating-point money: add `0.10` to itself ten times as `double precision`
   and as `numeric`, and compare each sum with `1`. (Hint: `SELECT sum(x) FROM (VALUES (0.10), (0.10), ...) AS v(x)`
   works, but `generate_series` is shorter: `SELECT sum(0.10::float8) FROM generate_series(1, 10);`.)

<details>
<summary>Solution</summary>

```sql
SELECT sum(0.10::float8) AS float_sum,
       sum(0.10::float8) = 1 AS float_is_one,
       sum(0.10::numeric) AS numeric_sum,
       sum(0.10::numeric) = 1 AS numeric_is_one
FROM generate_series(1, 10);
```

```text
     float_sum      | float_is_one | numeric_sum | numeric_is_one
--------------------+--------------+-------------+----------------
 0.9999999999999999 | f            |        1.00 | t
(1 row)
```

`generate_series(1, 10)` produces ten rows (chapter 11), and `sum()` adds a value up over all rows (chapter 8). Ten
times 0.10 in floating point is not 1. In an invoice system, that error turns into reports that don't balance by a cent.

</details>

3. **Time zones.** The Inkwell customer Hana is in Tokyo. An order was placed at `2026-04-08 06:30:00+00`. Write one
   query that shows that instant as a `timestamptz` in the session's time zone, and as a local Tokyo wall-clock time.
   (Hint: `timestamptz AT TIME ZONE 'Asia/Tokyo'` converts an instant into a zone-less local time.)

<details>
<summary>Solution</summary>

```sql
SELECT timestamptz '2026-04-08 06:30:00+00' AS instant,
       timestamptz '2026-04-08 06:30:00+00' AT TIME ZONE 'Asia/Tokyo' AS tokyo_local;
```

```text
        instant         |     tokyo_local
------------------------+---------------------
 2026-04-08 06:30:00+00 | 2026-04-08 15:30:00
(1 row)
```

(The first column depends on your session's time zone; it shows `+00` in a session set to UTC.) `AT TIME ZONE` returns
a plain `timestamp`: the reading of a clock on a wall in Tokyo at that instant. It is the right tool for displaying
local times or grouping by local day; chapter 11 covers it in detail.

</details>

4. **Enum evolution.** Add a `refunded` status to `order_status`, placed after `delivered`. Then show all values in
   order. Why might `ALTER TYPE ... ADD VALUE` be preferable to recreating the type?

<details>
<summary>Solution</summary>

```sql
ALTER TYPE order_status ADD VALUE 'refunded' AFTER 'delivered';
SELECT enum_range(NULL::order_status);
```

```text
                     enum_range
-----------------------------------------------------
 {pending,paid,shipped,delivered,refunded,cancelled}
(1 row)
```

Adding a value is a quick catalog change: existing data isn't touched. Recreating the type would mean converting every
column that uses it. Removing a label is not supported at all, which is the main argument for a lookup table when the
list is likely to change.

Chapter 4 recreates `order_status` with the original five values, so you don't need to undo this.

</details>

5. **Pick the types.** Inkwell wants to record newsletter subscribers: email address, the moment they subscribed, the
   date of birth (optional, for birthday discounts), the IP address they signed up from, whether they confirmed, and
   their preferred topics (a few of `pens`, `paper`, `ink`). Write a `CREATE TABLE subscribers` with sensible types,
   then drop it again.

<details>
<summary>Solution</summary>

```sql
CREATE TABLE subscribers (
    email          text NOT NULL,
    subscribed_at  timestamptz NOT NULL DEFAULT now(),
    date_of_birth  date,
    signup_ip      inet,
    confirmed      boolean NOT NULL DEFAULT false,
    topics         text[] NOT NULL DEFAULT '{}'
);
DROP TABLE subscribers;
```

`timestamptz` for the moment, `date` for a birthday (it has no time or zone), `inet` validates addresses, and
`boolean NOT NULL DEFAULT false` avoids a third "unknown" state. `DEFAULT` gives a value when an `INSERT` doesn't
provide one (chapter 4). The topics could be a `text[]` as here, or, if you want the database to guarantee only valid
topics, a separate table of subscriptions with a foreign key to a topics table; chapter 14 discusses that trade-off.
A real table would also have a primary key and a unique email (chapter 4).

</details>

---
← Previous: [psql and the command-line tools](02-psql-and-the-command-line-tools.md) | Next: [Tables and constraints](04-tables-and-constraints.md) →
