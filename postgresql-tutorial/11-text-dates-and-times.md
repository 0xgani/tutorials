# Chapter 11 — Text, dates and times

## What you'll learn

- Everyday string functions: characters versus bytes, case, trimming, padding, slicing and splitting
- How `||` treats `NULL`, and building strings safely with `format()`
- POSIX regular expressions: the `~` operators and the `regexp_*` functions
- What a collation decides, the three collation providers, and why collation versions matter after upgrades
- Case- and accent-insensitive matching: `lower()`, `casefold()` (18+), `citext`, nondeterministic collations,
  `unaccent` and Unicode normalization
- The different "current time" functions and when each one moves
- Time zones in depth: the `TimeZone` setting, `AT TIME ZONE` in both directions, and daylight-saving traps
- Intervals, `age`, `extract`, `date_trunc`, `date_bin`, formatting and parsing
- Calendar reports with `generate_series`, and grouping by the customer's local day

Almost every real query touches text or time. Text looks simple until you need to sort names from several languages,
match an email address case-insensitively or pull a number out of a description. Time looks simple until a customer
in Tokyo places an order "yesterday" that your UTC report files under today, or a reminder scheduled "one day later"
fires an hour early because the clocks changed. This chapter gives you the tools and the mental models to get both
right. Start from the reference data (chapter 5); nothing here changes it except a few extensions and collations that
the next reset removes.

## Strings

Chapter 3 introduced `text` and `varchar`. Here are the functions you use on them.

### Characters and bytes

`length()` (also spelled `char_length()`) counts characters. `octet_length()` counts bytes. In UTF-8 they differ as
soon as a string contains anything outside plain ASCII:

```sql
SELECT full_name, length(full_name) AS chars, octet_length(full_name) AS bytes
FROM customers
WHERE id IN (2, 3)
ORDER BY id;
```

```text
  full_name   | chars | bytes
--------------+-------+-------
 Ben Carter   |    10 |    10
 Chloé Martin |    12 |    13
(2 rows)
```

The `é` in Chloé takes two bytes. This matters when another system limits a field in bytes (some APIs, some older
databases, index entry size limits) while PostgreSQL's `varchar(n)` limits it in characters.

### Concatenation and NULL

`||` concatenates. It accepts a non-text value on one side and converts it to text. But if either side is `NULL`, the
result is `NULL`, which is a frequent surprise when you glue optional columns together. `concat()` and `concat_ws()`
(with a separator) skip `NULL`s instead:

```sql
SELECT 'Order #' || 42 AS label,
       'Hello ' || NULL AS with_null,
       concat('Hello ', NULL, 'world') AS concat,
       concat_ws(' / ', 'Writing', NULL, 'Pens') AS path;
```

```text
   label   | with_null |   concat    |      path
-----------+-----------+-------------+----------------
 Order #42 |           | Hello world | Writing / Pens
(1 row)
```

`with_null` is `NULL`, shown as an empty cell. When a missing part should become a placeholder, use `coalesce`:

```sql
SELECT id, 'Review: ' || coalesce(body, '(no text)') AS summary
FROM reviews
WHERE id IN (3, 4)
ORDER BY id;
```

```text
 id |                       summary
----+------------------------------------------------------
  3 | Review: Great paper, no ghosting with fountain pens.
  4 | Review: (no text)
(2 rows)
```

### Case, trimming and padding

```sql
SELECT upper('inkwell') AS upper,
       initcap('midnight blue ink') AS initcap,
       trim('  dot grid  ') AS trim,
       btrim('--FP-001--', '-') AS btrim,
       lpad('42', 6, '0') AS lpad,
       rpad('ab', 5, '.') AS rpad;
```

```text
  upper  |      initcap      |   trim   | btrim  |  lpad  | rpad
---------+-------------------+----------+--------+--------+-------
 INKWELL | Midnight Blue Ink | dot grid | FP-001 | 000042 | ab...
(1 row)
```

`trim` removes spaces from both ends by default; `ltrim`/`rtrim` do one end, and the two-argument forms remove any of
the listed characters. `lpad` is handy for fixed-width codes, but don't use it to store numbers as zero-padded text:
store the number and format it when you display it.

### Slicing

```sql
SELECT name,
       left(name, 5) AS first5,
       right(sku, 3) AS sku_number,
       split_part(name, ' ', 1) AS first_word,
       substring(name FROM 7 FOR 4) AS from7_for4,
       position(' ' IN name) AS first_space
FROM products
WHERE id IN (2, 9, 14)
ORDER BY id;
```

```text
          name          | first5 | sku_number | first_word | from7_for4 | first_space
------------------------+--------+------------+------------+------------+-------------
 Heron Fountain Pen     | Heron  | 002        | Heron      | Foun       |           6
 Pocket Notebook 3-pack | Pocke  | 003        | Pocket     |  Not       |           7
 Leather Pen Case       | Leath  | 002        | Leather    | r Pe       |           8
(3 rows)
```

Positions count from 1, not 0. `substring(s FROM start FOR count)` is the SQL-standard form; `substr(s, start,
count)` does the same. `split_part` takes a field number, and since PostgreSQL 14 a negative number counts from the
end:

```sql
SELECT split_part('Writing/Pens/Fountain pens', '/', -1) AS last_part,
       split_part('Writing/Pens/Fountain pens', '/', 2) AS second_part;
```

```text
   last_part   | second_part
---------------+-------------
 Fountain pens | Pens
(1 row)
```

### Replacing and rearranging

```sql
SELECT replace('Dot Grid Notebook A5', ' ', '-') AS replaced,
       translate('Chloé Lindqvist', 'éi', 'eI') AS translated,
       repeat('*', 5) AS stars,
       reverse('Inkwell') AS reversed;
```

```text
       replaced       |   translated    | stars | reversed
----------------------+-----------------+-------+----------
 Dot-Grid-Notebook-A5 | Chloe LIndqvIst | ***** | llewknI
(1 row)
```

`replace` swaps whole substrings. `translate` maps single characters, position by position: every `é` becomes `e` and
every `i` becomes `I`.

### Splitting into arrays and rows

```sql
SELECT string_to_array('gift,bestseller,,new', ',') AS tags;
```

```text
           tags
--------------------------
 {gift,bestseller,"",new}
(1 row)
```

The empty field between the two commas becomes an empty string `""`. Add a third argument to turn a particular value
into `NULL`: `string_to_array('a,,b', ',', '')`. To get one row per piece instead of an array, use `string_to_table`
(14+):

```sql
SELECT tag FROM string_to_table('gift,bestseller,new', ',') AS tag;
```

```text
    tag
------------
 gift
 bestseller
 new
(3 rows)
```

The opposite direction, many rows into one string, is the `string_agg` aggregate from chapter 8.

### Building strings safely with format()

`format()` fills placeholders into a template, like `printf`:

```sql
SELECT format('%s has %s in stock', name, stock) AS line
FROM products
WHERE id IN (1, 9)
ORDER BY id;
```

```text
                 line
---------------------------------------
 Kestrel Fountain Pen has 12 in stock
 Pocket Notebook 3-pack has 0 in stock
(2 rows)
```

`%s` inserts the value as plain text (`NULL` becomes an empty string). Two more placeholders matter whenever you build
**SQL text** inside the database, as you will in PL/pgSQL functions (chapter 19) and did with `\gexec` in chapter 2:

- `%I` quotes an **identifier** (a table or column name) only when it needs quoting.
- `%L` quotes a **literal** value, doubling embedded quotes, and writes `NULL` without quotes.

```sql
SELECT format('SELECT * FROM %I WHERE name = %L', 'Order Items', 'O''Brien') AS sql;
```

```text
                         sql
-----------------------------------------------------
 SELECT * FROM "Order Items" WHERE name = 'O''Brien'
(1 row)
```

```sql
SELECT format('%I.%I', 'public', 'products') AS ident,
       format('%L', NULL) AS null_literal,
       format('[%-8s|%8s]', 'left', 'right') AS padded,
       format('%2$s, %1$s', 'Ana', 'Souza') AS positional;
```

```text
      ident      | null_literal |       padded        | positional
-----------------+--------------+---------------------+------------
 public.products | NULL         | [left    |   right] | Souza, Ana
(1 row)
```

`products` needs no quotes, so `%I` leaves it alone. `%-8s` pads to eight characters on the right and `%8s` on the
left; `%2$s` picks an argument by position. The functions `quote_ident()`, `quote_literal()` and `quote_nullable()`
do the same quoting one value at a time.

Building SQL from strings is how SQL injection happens. Inside the database, `format()` with `%I` and `%L` makes it
safe. In application code, you never build SQL by pasting values at all: you send the values as **parameters**
(chapter 22), and the server never confuses them with SQL.

## Regular expressions

`LIKE` (chapter 6) only knows `%` and `_`. For real patterns, PostgreSQL has POSIX regular expressions, in a flavour
it calls *advanced regular expressions*, close to what you know from most programming languages: character classes
such as `\d`, `\w` and `\s`, quantifiers such as `+`, `*`, `?` and `{2,4}`, groups, alternation, non-greedy
quantifiers and lookahead.

| Operator | Meaning |
|---|---|
| `s ~ p` | `s` matches pattern `p` (case-sensitive) |
| `s ~* p` | matches, case-insensitive |
| `s !~ p` | does not match |
| `s !~* p` | does not match, case-insensitive |

A regular expression matches anywhere in the string unless you anchor it with `^` and `$`:

```sql
SELECT sku, name
FROM products
WHERE sku ~ '^(FP|IN)-'
ORDER BY sku;
```

```text
  sku   |          name
--------+------------------------
 FP-001 | Kestrel Fountain Pen
 FP-002 | Heron Fountain Pen
 IN-001 | Midnight Blue Ink 50ml
 IN-002 | Sepia Ink 50ml
(4 rows)
```

```sql
SELECT name
FROM products
WHERE name ~* 'pen\M'
ORDER BY name;
```

```text
         name
----------------------
 Brass Pen Clip
 Heron Fountain Pen
 Kestrel Fountain Pen
 Leather Pen Case
(4 rows)
```

`\M` means "end of a word", so `pen\M` matches the word *pen* but not *pencil*. Watch out for word boundaries: in
PostgreSQL `\y` is a word boundary, `\m` the start of a word and `\M` the end of one. `\b` means a backspace
character, not a word boundary as it does in many other regex engines.

Backslashes need no doubling in ordinary string literals, because `standard_conforming_strings` is on (the default
since PostgreSQL 9.1). Only in `E'...'` strings is `\` an escape character, and there you would write `E'\\d'`.

### The regexp functions

| Function | Returns |
|---|---|
| `regexp_like(s, p [, flags])` (15+) | boolean, like `~`, with flags such as `'i'` |
| `regexp_match(s, p)` | an array of the first match's capture groups (or the whole match if there are none) |
| `regexp_matches(s, p, 'g')` | one row per match, each an array |
| `regexp_replace(s, p, replacement [, flags])` | the string with the first match (or every match with `'g'`) replaced |
| `regexp_count(s, p)` (15+) | the number of matches |
| `regexp_substr(s, p)` (15+) | the first matching substring |
| `regexp_split_to_array(s, p)`, `regexp_split_to_table(s, p)` | the pieces between matches |

Capture groups pull structured parts out of a string:

```sql
SELECT sku, regexp_match(sku, '^([A-Z]+)-(\d+)$') AS parts
FROM products
WHERE id IN (1, 9)
ORDER BY id;
```

```text
  sku   |  parts
--------+----------
 FP-001 | {FP,001}
 NB-003 | {NB,003}
(2 rows)
```

The result is a `text[]`, so `(regexp_match(...))[2]` is the number part. `regexp_substr` returns just the matching
text, which is easier when you need one value:

```sql
SELECT name, regexp_substr(description, '\d+(?= pages)') AS pages
FROM products
WHERE category_id = 8
ORDER BY id;
```

```text
          name          | pages
------------------------+-------
 Dot Grid Notebook A5   | 192
 Lined Notebook A4      |
 Pocket Notebook 3-pack | 64
(3 rows)
```

`(?= pages)` is a *lookahead*: it requires ` pages` after the digits without including it in the match. The lined
notebook's description says "160 lined pages", so nothing matches there and the result is `NULL`. Patterns are
literal-minded; test them against your real data.

`regexp_replace` with the `'g'` flag replaces every match, and `\1`, `\2` in the replacement refer to capture groups:

```sql
SELECT regexp_replace('Dot Grid Notebook (A5)', '[^A-Za-z0-9]+', '-', 'g') AS dashed,
       regexp_replace('2026-04-08', '(\d+)-(\d+)-(\d+)', '\3.\2.\1') AS reordered,
       regexp_count('banana', 'an') AS count;
```

```text
        dashed         | reordered  | count
-----------------------+------------+-------
 Dot-Grid-Notebook-A5- | 08.04.2026 |     2
(1 row)
```

The closing parenthesis became a trailing dash in `dashed`; exercise 1 deals with that. `regexp_matches` with `'g'` is
a set-returning function, producing one row per match:

```sql
SELECT m[1] AS colour
FROM regexp_matches('Palette: #003366, #993300 and #fff', '#([0-9a-f]{6})\M', 'g') AS m;
```

```text
 colour
--------
 003366
 993300
(2 rows)
```

The short `#fff` doesn't match because the pattern asks for exactly six hex digits followed by the end of a word.

SQL also defines `SIMILAR TO`, a hybrid of `LIKE` and regular expressions (`'abc' SIMILAR TO '(a|x)%'`). It exists for
standards compliance; regular expressions are clearer and more capable, so prefer them.

Like `LIKE '%...'`, a regular expression can't use an ordinary B-tree index, so `WHERE description ~ '...'` reads the
whole table. The `pg_trgm` extension can index such searches (chapters 13 and 15). Also, don't use regular expressions
to search for words in prose; full-text search (chapter 13) understands words, stems and ranking.

## Collations

A **collation** is a set of rules for comparing strings. It decides:

- **sort order**: does `apple` come before `Banana`? Where does `é` go?
- **equality**: are two strings the same? (For normal, *deterministic* collations, only if they are the same bytes.)
- **character classes**: what `upper()`, `lower()` and `\w` consider a letter, and what its other case is (this part is
  called the *ctype*).

Every text value has a collation, usually the database's default. The `COLLATE` clause overrides it in an expression:

```sql
SELECT 'a' < 'B' AS default_collation,
       'a' < 'B' COLLATE "C" AS c_collation;
```

```text
 default_collation | c_collation
-------------------+-------------
 t                 | f
(1 row)
```

The Inkwell database uses ICU's English rules, where letters compare alphabetically first and case only breaks ties.
The `C` collation compares bytes (code points), and every upper-case ASCII letter comes before every lower-case one.
The same list, sorted both ways:

```sql
SELECT string_agg(word, ', ' ORDER BY word) AS english,
       string_agg(word, ', ' ORDER BY word COLLATE "C") AS bytewise
FROM (VALUES ('banana'), ('Apple'), ('apple'), ('Cherry'), ('éclair')) AS t (word);
```

```text
               english                |               bytewise
--------------------------------------+--------------------------------------
 apple, Apple, banana, Cherry, éclair | Apple, Cherry, apple, banana, éclair
(1 row)
```

### Collation providers

A collation comes from one of three **providers**:

| Provider | Where the rules come from | Notes |
|---|---|---|
| `libc` | the operating system's C library | the classic default; results differ between operating systems and can change when the OS is upgraded |
| `icu` | the ICU library | the same results on every platform for a given ICU version; language-aware, with many options |
| `builtin` (17+) | PostgreSQL itself | `C`, `C.UTF-8` (17+) and `PG_UNICODE_FAST` (18+); code-point sort order that never changes |

```sql
SELECT collname, collprovider, colllocale
FROM pg_collation
WHERE collname IN ('C', 'ucs_basic', 'pg_c_utf8', 'pg_unicode_fast', 'unicode', 'en-x-icu')
ORDER BY collprovider, collname;
```

```text
    collname     | collprovider |   colllocale
-----------------+--------------+-----------------
 pg_c_utf8       | b            | C.UTF-8
 pg_unicode_fast | b            | PG_UNICODE_FAST
 ucs_basic       | b            | C
 C               | c            |
 en-x-icu        | i            | en
 unicode         | i            | und
(6 rows)
```

`collprovider` is `b` for builtin, `c` for libc and `i` for ICU. ICU collations are named after their language tag
with an `-x-icu` suffix (`en-x-icu`, `de-x-icu`, `sv-x-icu`, ...); `unicode` is ICU's language-neutral root order.
`\dO` in psql lists the collations you created yourself, and `\dOS` lists the built-in ones too (there are hundreds).

The database's default collation is fixed when the database is created. Inkwell's was set by `sql/reset.sql`:

```sql
SELECT datname, datlocprovider, datlocale
FROM pg_database
WHERE datname = 'inkwell';
```

```text
 datname | datlocprovider | datlocale
---------+----------------+-----------
 inkwell | i              | en
(1 row)
```

To create a database with the builtin provider, for example, name it together with `template0` (the default template
might use a different locale):

```sql
CREATE DATABASE collation_demo TEMPLATE template0
    LOCALE_PROVIDER builtin BUILTIN_LOCALE 'C.UTF-8';
SELECT datname, datlocprovider, datlocale FROM pg_database WHERE datname = 'collation_demo';
DROP DATABASE collation_demo;
```

```text
CREATE DATABASE
    datname     | datlocprovider | datlocale
----------------+----------------+-----------
 collation_demo | b              | C.UTF-8
(1 row)

DROP DATABASE
```

`initdb` takes the same choices for the whole cluster (`--locale-provider=builtin --builtin-locale=C.UTF-8`, or
`--locale-provider=icu --icu-locale=en`).

Which one should you choose? Two reasonable strategies:

- **A language-aware default** (ICU, as Inkwell does): `ORDER BY name` gives a human-friendly order everywhere, at a
  small cost in comparison speed.
- **A code-point default** (`builtin` with `C.UTF-8`): the fastest comparisons and an order that never changes, so
  indexes never need rebuilding because of collation changes. Ask for a language-aware order only where people see it:
  `ORDER BY name COLLATE "en-x-icu"`.

### Collation versions and upgrades

A B-tree index on a text column stores its entries in collation order. If the rules change underneath it, for example
because an operating-system upgrade brings a new C library or ICU version that sorts some characters differently, the
index is silently out of order: lookups miss rows that are there, and a unique index can let duplicates in. This has
happened for real (glibc 2.28 changed the sort order of many strings in 2018).

PostgreSQL records the collation version a database was created with and compares it with what the library reports
when you connect. On a mismatch you see a warning like this:

```text
WARNING:  database "inkwell" has a collation version mismatch
DETAIL:  The database was created using collation version 153.120, but the operating system provides version 153.125.
HINT:  Rebuild all objects in this database that use the default collation and run ALTER DATABASE inkwell REFRESH COLLATION VERSION, or build PostgreSQL with the right library version.
```

The fix is to rebuild the affected indexes and then record the new version:

```sql
REINDEX DATABASE inkwell;
ALTER DATABASE inkwell REFRESH COLLATION VERSION;
```

```text
REINDEX
NOTICE:  version has not changed
ALTER DATABASE
```

Here nothing had changed, so PostgreSQL says so. `REINDEX DATABASE` rebuilds every index in the current database and
blocks writes to each table while it works; on a large production database you would rebuild only the indexes on
text columns, with `REINDEX INDEX CONCURRENTLY` (chapter 15).

You can check for a mismatch yourself:

```sql
SELECT datname,
       datcollversion = pg_database_collation_actual_version(oid) AS up_to_date
FROM pg_database
WHERE datname = current_database();
```

```text
 datname | up_to_date
---------+------------
 inkwell | t
(1 row)
```

The rule for operations: **when you move a database to a machine with a different OS or ICU version (including a
physical replica, chapter 23), check collation versions and reindex text indexes if they changed.** The builtin
provider's code-point order is exempt, which is one argument for it.

### Case-insensitive matching

Customers type their email in any case, and they expect `Ana@Example.com` to find `ana@example.com`. There are several
ways to compare text case-insensitively, from simplest to most specialised.

**1. Normalise on the way in.** Inkwell stores emails in lower case and enforces it with the
`customers_email_lowercase` check (chapter 4). Lower-case the input as well, and a plain `=` with a plain index works.
This is the simplest approach whenever you control the writes.

**2. Compare `lower()` on both sides**, and index the expression `lower(email)` (chapter 15) so it stays fast:

```sql
SELECT full_name FROM customers WHERE lower(email) = lower('Ana@Example.COM');
```

```text
 full_name
-----------
 Ana Souza
(1 row)
```

**3. `casefold()`** (18+) is the correct tool for caseless comparison. `lower()` maps each character to its
lower-case form, but some characters need *case folding*, which can change the length of the string:

```sql
SELECT lower('Straße') = lower('STRASSE') AS by_lower,
       casefold('Straße') = casefold('STRASSE') AS by_casefold,
       casefold('Straße') AS folded;
```

```text
 by_lower | by_casefold | folded
----------+-------------+---------
 f        | t           | strasse
(1 row)
```

German `ß` folds to `ss`, so `casefold` treats the two spellings as equal. Greek has two lower-case forms of sigma
(`σ`, and `ς` at the end of a word), which `casefold` also unifies. Folding follows the collation's provider: with
ICU or `PG_UNICODE_FAST` it uses full Unicode case folding; with `C.UTF-8` it uses simple one-to-one mappings (so
`ß` stays `ß`), and with `C` it only folds the ASCII letters. For data in many languages, prefer `casefold(x)` to `lower(x)` in comparisons and in expression indexes.

**4. The `citext` extension** provides a case-insensitive text type. Comparisons on it use `lower()` internally:

```sql
CREATE EXTENSION IF NOT EXISTS citext;
SELECT 'Kestrel'::citext = 'KESTREL' AS equal,
       'Kestrel'::citext < 'heron' AS less;
```

```text
CREATE EXTENSION
 equal | less
-------+------
 t     | f
(1 row)
```

`citext` is convenient (a `UNIQUE` constraint on a `citext` column is case-insensitive), but it is an extra type that
every function and driver must handle, and it is slower than plain text. The PostgreSQL documentation itself suggests
considering nondeterministic collations instead.

**5. Nondeterministic ICU collations** change what *equal* means. ICU's comparison has strength levels: level 1
compares base letters only (`a` = `A` = `á`), level 2 adds accents, level 3 adds case. A collation that stops at
level 2 is case-insensitive but accent-sensitive; one that stops at level 1 also ignores accents:

```sql
CREATE COLLATION case_insensitive (provider = icu, locale = 'und-u-ks-level2', deterministic = false);
CREATE COLLATION ignore_accent_case (provider = icu, locale = 'und-u-ks-level1', deterministic = false);

SELECT 'Kestrel' = 'KESTREL' COLLATE case_insensitive AS ci,
       'Chloé' = 'chloe' COLLATE case_insensitive AS ci_accent,
       'Chloé' = 'chloe' COLLATE ignore_accent_case AS ai;
```

```text
CREATE COLLATION
CREATE COLLATION
 ci | ci_accent | ai
----+-----------+----
 t  | f         | t
(1 row)
```

You can use such a collation in a query or declare it on a column, where it makes `=`, `UNIQUE`, `GROUP BY`
and `DISTINCT` all case-insensitive:

```sql
SELECT full_name FROM customers WHERE full_name = 'CHLOÉ MARTIN' COLLATE case_insensitive;
```

```text
  full_name
--------------
 Chloé Martin
(1 row)
```

Since PostgreSQL 18, `LIKE` works with nondeterministic collations too. Regular expressions still don't:

```sql
SELECT count(*) FROM customers WHERE full_name LIKE 'chloé%' COLLATE case_insensitive;
SELECT count(*) FROM customers WHERE full_name ~ '^chloé' COLLATE case_insensitive;
```

```text
 count
-------
     1
(1 row)

ERROR:  nondeterministic collations are not supported for regular expressions
```

Nondeterministic collations compare more slowly, and an index built with one can't serve `LIKE 'abc%'` prefix
searches. They are the right tool when case-insensitive equality is part of the data's meaning (usernames, tags) and
you can't normalise on the way in.

### Accents and Unicode normalization

The `unaccent` extension strips accents, which helps search: a customer typing `chloe` should find `Chloé`:

```sql
CREATE EXTENSION IF NOT EXISTS unaccent;
SELECT unaccent('Chloé, crème brûlée') AS plain;
SELECT full_name FROM customers WHERE unaccent(lower(full_name)) LIKE '%chloe%';
```

```text
CREATE EXTENSION
        plain
---------------------
 Chloe, creme brulee
(1 row)

  full_name
--------------
 Chloé Martin
(1 row)
```

`unaccent()` is not marked `IMMUTABLE` (its result depends on a dictionary file that could change), so you can't index
it directly. Chapter 13 shows the standard workaround when it is combined with full-text search.

Unicode has a further trap: the same visible text can be stored in different ways. `é` can be one code point (U+00E9)
or an `e` followed by a combining accent (U+0301). They look identical, but they are different strings:

```sql
SELECT U&'\00E9' AS composed, U&'e\0301' AS decomposed,
       U&'\00E9' = U&'e\0301' AS equal,
       normalize(U&'\00E9') = normalize(U&'e\0301') AS equal_after_normalize,
       length(U&'e\0301') AS chars,
       U&'e\0301' IS NFC NORMALIZED AS is_nfc;
```

```text
 composed | decomposed | equal | equal_after_normalize | chars | is_nfc
----------+------------+-------+-----------------------+-------+--------
 é        | é          | f     | t                     |     2 | f
(1 row)
```

`U&'...'` is a Unicode escape string literal in which `\XXXX` is a code point. `normalize()` converts to a normal form
(NFC by default, the composed form). Text pasted from macOS file names or some keyboards may arrive decomposed; if
you normalise to NFC on the way in, comparisons behave the way people expect.

## The current time

PostgreSQL has several "now" functions, and they differ in **when** they are read:

| Function | Value |
|---|---|
| `now()`, `current_timestamp`, `transaction_timestamp()` | the start of the current **transaction** |
| `statement_timestamp()` | the start of the current statement |
| `clock_timestamp()` | the actual time, changing even during a single statement |
| `current_date` | today's date in the session's time zone |
| `localtimestamp` | the start of the transaction as a `timestamp` (no time zone), in the session's time zone |

```sql
BEGIN;
SELECT pg_sleep(0.2);
SELECT now() = transaction_timestamp() AS same_as_tx,
       statement_timestamp() > now() AS statement_later,
       clock_timestamp() > statement_timestamp() AS clock_later;
COMMIT;
```

```text
BEGIN
 pg_sleep
----------

(1 row)

 same_as_tx | statement_later | clock_later
------------+-----------------+-------------
 t          | t               | t
(1 row)

COMMIT
```

`pg_sleep(0.2)` pauses for 0.2 seconds, so the second statement starts after the transaction did. `now()` stays fixed
for the whole transaction. That is a feature: every row an import inserts in one transaction gets the same
`created_at`, and "now" can't move between two checks in the same transaction. Use `clock_timestamp()` when you
measure how long something takes inside a function or a long transaction.

PostgreSQL also accepts the special input strings `'now'`, `'today'`, `'tomorrow'`, `'yesterday'`, `'epoch'`,
`'infinity'` and `'-infinity'`. Never write `DEFAULT 'now'` in a table definition: the string is converted to a
timestamp once, when the table is created, and every row gets that same moment. Write `DEFAULT now()`, which calls the
function for each insert. `'infinity'` is genuinely useful, for example as the end of a validity period that has none
yet; it compares greater than every other timestamp.

## Time zones

Chapter 3 established the key fact: a `timestamptz` stores an absolute instant (in UTC), and the session's `TimeZone`
setting decides how it is displayed and how input without an explicit offset is interpreted.

### Where TimeZone comes from

| Level | How it's set |
|---|---|
| Server default | `timezone` in `postgresql.conf` (`initdb` copies it from the operating system) |
| Per database | `ALTER DATABASE inkwell SET timezone TO 'UTC'` (what `sql/reset.sql` does) |
| Per role | `ALTER ROLE reporting SET timezone TO 'Europe/Berlin'` |
| Per session | `SET timezone = 'Asia/Tokyo'`, or a driver or connection option; libpq clients such as psql also honour the `PGTZ` environment variable |

```sql
SHOW timezone;
```

```text
 TimeZone
----------
 UTC
(1 row)
```

A good default for applications is to keep every session in **UTC**, store `timestamptz`, and convert to a user's time
zone only at the edges: when you display a value, or in a report that groups by the user's local day. Never store
"local time" in a `timestamptz` column by pretending it is UTC.

Use **IANA time zone names** such as `Europe/Berlin` and `America/New_York`. They know each region's daylight-saving
rules and their history. Abbreviations such as `CET` or `EST` are fixed offsets, and some are ambiguous (`IST` is used
in India, Ireland and Israel). The view `pg_timezone_names` lists all names with their current offsets:

```sql
SELECT name FROM pg_timezone_names WHERE name LIKE 'Europe/L%' ORDER BY name;
```

```text
       name
-------------------
 Europe/Lisbon
 Europe/Ljubljana
 Europe/London
 Europe/Luxembourg
(4 rows)
```

Beware of numeric offsets written as time zone names. They follow the POSIX convention, in which positive means
**west** of Greenwich, the opposite of ISO 8601:

```sql
SELECT timestamptz '2026-04-08 12:00+00' AT TIME ZONE 'UTC+3' AS posix_name,
       timestamptz '2026-04-08 12:00+00' AT TIME ZONE INTERVAL '+03:00' AS iso_interval;
```

```text
     posix_name      |    iso_interval
---------------------+---------------------
 2026-04-08 09:00:00 | 2026-04-08 15:00:00
(1 row)
```

`'UTC+3'` turned noon UTC into 09:00, three hours *behind*. An `INTERVAL` offset uses the ISO sign. Inside a
timestamp literal, `+03` is ISO too (`'2026-04-08 15:00+03'` is noon UTC). The safest habit is to use region names.

### AT TIME ZONE, in both directions

`AT TIME ZONE` converts between the two timestamp types, and its meaning depends on which one you give it:

- `timestamptz AT TIME ZONE zone` gives a `timestamp`: **what a wall clock in that zone showed** at that instant.
- `timestamp AT TIME ZONE zone` gives a `timestamptz`: **the instant** at which a wall clock in that zone showed
  that reading.

Order 10 was placed by Hana, who lives in Japan. What time was it for her?

```sql
SELECT id, created_at, created_at AT TIME ZONE 'Asia/Tokyo' AS tokyo_time
FROM orders
WHERE id = 10;
```

```text
 id |       created_at       |     tokyo_time
----+------------------------+---------------------
 10 | 2026-04-08 06:30:00+00 | 2026-04-08 15:30:00
(1 row)
```

The other direction: the Berlin warehouse opens at 09:00 local time on 10 April. Which instant is that?

```sql
SELECT timestamp '2026-04-10 09:00' AT TIME ZONE 'Europe/Berlin' AS opens_at;
```

```text
        opens_at
------------------------
 2026-04-10 07:00:00+00
(1 row)
```

Germany is on summer time (UTC+2) in April, so 09:00 there is 07:00 UTC. You can also write the zone inside a
timestamp literal: `timestamptz '2026-04-10 09:00 Europe/Berlin'` is the same instant. `AT LOCAL` (17+) converts to the
session's own time zone, like `AT TIME ZONE current_setting('TimeZone')`.

A table that maps countries to time zones turns this into a report. Here a `VALUES` list stands in for such a table
(real countries can span several zones; this is a simplification):

```sql
SELECT o.id, c.full_name, z.zone,
       o.created_at AT TIME ZONE z.zone AS local_time
FROM orders AS o
JOIN customers AS c ON c.id = o.customer_id
JOIN (VALUES ('BR', 'America/Sao_Paulo'), ('US', 'America/New_York'), ('FR', 'Europe/Paris'),
             ('GB', 'Europe/London'), ('DE', 'Europe/Berlin'), ('JP', 'Asia/Tokyo')) AS z (country, zone)
  ON z.country = c.country
WHERE o.id IN (1, 6, 8, 10)
ORDER BY o.id;
```

```text
 id |   full_name    |       zone        |     local_time
----+----------------+-------------------+---------------------
  1 | Ana Souza      | America/Sao_Paulo | 2026-01-06 07:00:00
  6 | Emma Schulz    | Europe/Berlin     | 2026-03-02 16:00:00
  8 | Farah Haddad   | America/New_York  | 2026-03-28 13:40:00
 10 | Hana Kobayashi | Asia/Tokyo        | 2026-04-08 15:30:00
(4 rows)
```

### Grouping by the local day

`created_at::date` takes the date **in the session's time zone**. That is right only if the session's zone is the zone
the report is about. Orders 3, 8 and 11 were placed late in the UTC day:

```sql
SELECT id, created_at,
       created_at::date AS utc_day,
       (created_at AT TIME ZONE 'Asia/Tokyo')::date AS tokyo_day
FROM orders
WHERE id IN (3, 8, 11)
ORDER BY id;
```

```text
 id |       created_at       |  utc_day   | tokyo_day
----+------------------------+------------+------------
  3 | 2026-02-03 19:30:00+00 | 2026-02-03 | 2026-02-04
  8 | 2026-03-28 17:40:00+00 | 2026-03-28 | 2026-03-29
 11 | 2026-04-09 20:05:00+00 | 2026-04-09 | 2026-04-10
(3 rows)
```

A daily report for a Tokyo-based shop must group by `(created_at AT TIME ZONE 'Asia/Tokyo')::date`, or it files evening
orders under the wrong day. The same applies to range filters: in a UTC session, `created_at >= '2026-03-01'` means
midnight **UTC**. For a month as seen in Berlin, write the zone into the boundaries:

```sql
SELECT count(*) AS march_berlin
FROM orders
WHERE created_at >= timestamptz '2026-03-01 00:00 Europe/Berlin'
  AND created_at <  timestamptz '2026-04-01 00:00 Europe/Berlin';
```

```text
 march_berlin
--------------
            3
(1 row)
```

Notice the **half-open** range, `>=` the start and `<` the next start. `BETWEEN '2026-03-01' AND '2026-03-31'` would
stop at midnight at the *start* of 31 March and miss that whole day, and `BETWEEN ... AND '2026-03-31 23:59:59'`
misses the last fraction of a second. Half-open ranges have no gaps and no overlaps, and they keep the column bare so
an index on `created_at` can be used (chapter 15).

### Daylight-saving traps

On 29 March 2026, clocks in Germany jumped from 02:00 to 03:00. Adding an interval to a `timestamptz` is done in the
session's time zone, and a day is not always 24 hours:

```sql
SET timezone = 'Europe/Berlin';
SELECT timestamptz '2026-03-28 12:00' + interval '1 day'    AS plus_1_day,
       timestamptz '2026-03-28 12:00' + interval '24 hours' AS plus_24_hours;
RESET timezone;
```

```text
SET
       plus_1_day       |     plus_24_hours
------------------------+------------------------
 2026-03-29 12:00:00+02 | 2026-03-29 13:00:00+02
(1 row)

RESET
```

`'1 day'` means "the same wall-clock time tomorrow" (only 23 hours later that weekend), while `'24 hours'` means
exactly 24 elapsed hours, which ends at 13:00 local time. Choose the one you mean: a daily reminder at noon wants
`'1 day'`; a 24-hour token expiry wants `'24 hours'`. The answer depends on the session's time zone, which is easy to
forget. `date_add()` (16+) takes the zone as an argument instead:

```sql
SELECT date_add(timestamptz '2026-03-28 11:00+00', interval '1 day', 'Europe/Berlin') AS next_day;
```

```text
        next_day
------------------------
 2026-03-29 10:00:00+00
(1 row)
```

Wall-clock times can also be impossible or ambiguous. 02:30 on 29 March didn't exist in Berlin, and 02:30 on 25 October
happens twice. PostgreSQL resolves both without an error: a missing time is read with the offset in force just before
the jump, and a repeated time with the offset in force just after it:

```sql
SELECT timestamp '2026-03-29 02:30' AT TIME ZONE 'Europe/Berlin' AS nonexistent,
       timestamp '2026-10-25 02:30' AT TIME ZONE 'Europe/Berlin' AS ambiguous;
```

```text
      nonexistent       |       ambiguous
------------------------+------------------------
 2026-03-29 01:30:00+00 | 2026-10-25 01:30:00+00
(1 row)
```

If such times matter to your application (a scheduler, say), check for them explicitly rather than relying on the
default.

## Intervals

An `interval` stores three separate parts: months, days and microseconds. Months and days are kept apart because their
length varies: a month is 28 to 31 days, and a day is 23 to 25 hours around a daylight-saving change.

```sql
SELECT interval '1 month' = interval '30 days' AS compares_equal,
       timestamptz '2026-01-31 00:00+00' + interval '1 month' AS plus_month,
       timestamptz '2026-01-31 00:00+00' + interval '30 days' AS plus_30_days;
```

```text
 compares_equal |       plus_month       |      plus_30_days
----------------+------------------------+------------------------
 t              | 2026-02-28 00:00:00+00 | 2026-03-02 00:00:00+00
(1 row)
```

For comparison and sorting, PostgreSQL treats a month as 30 days, so the two intervals compare equal. But they are
added differently: one month after 31 January is the end of February, while 30 days later is 2 March.

Subtracting two timestamps gives an interval in days and time. `age()` gives a "human" answer in years, months and
days instead:

```sql
SELECT timestamptz '2026-04-10 12:00+00' - timestamptz '2026-01-06 09:12+00' AS difference,
       age(timestamptz '2026-04-10 12:00+00', timestamptz '2026-01-06 09:12+00') AS age;
```

```text
    difference    |          age
------------------+------------------------
 94 days 02:48:00 | 3 mons 4 days 02:48:00
(1 row)
```

With one argument, `age(ts)` measures from midnight today, which is what you want for "how old is this customer
account?". The `justify_*` functions convert between units, and `make_interval` builds an interval from numbers:

```sql
SELECT justify_hours(interval '50 hours') AS hours_to_days,
       justify_days(interval '45 days') AS days_to_months,
       make_interval(days => 3, hours => 4) AS made,
       interval '90 minutes' AS ninety;
```

```text
  hours_to_days  | days_to_months |      made       |  ninety
-----------------+----------------+-----------------+----------
 2 days 02:00:00 | 1 mon 15 days  | 3 days 04:00:00 | 01:30:00
(1 row)
```

To multiply a number by a unit, write `n * interval '1 day'`; it works with column values, which string literals can't
do. The display format follows the `IntervalStyle` setting; `iso_8601` produces the format many JSON APIs expect:

```sql
SET intervalstyle = 'iso_8601';
SELECT interval '1 day 90 minutes' AS iso;
RESET intervalstyle;
```

```text
SET
    iso
-----------
 P1DT1H30M
(1 row)

RESET
```

## Extracting, truncating and binning

`extract(field FROM value)` pulls one field out of a date, timestamp or interval. Since PostgreSQL 14 it returns
`numeric`, which keeps fractional seconds exact. `date_part('field', value)` is the older function form, returning
`double precision`.

```sql
SELECT extract(year FROM created_at) AS year,
       extract(quarter FROM created_at) AS quarter,
       extract(month FROM created_at) AS month,
       extract(dow FROM created_at) AS dow,
       extract(isodow FROM created_at) AS isodow,
       extract(week FROM created_at) AS iso_week,
       extract(epoch FROM created_at) AS epoch
FROM orders
WHERE id = 10;
```

```text
 year | quarter | month | dow | isodow | iso_week |       epoch
------+---------+-------+-----+--------+----------+-------------------
 2026 |       2 |     4 |   3 |      3 |       15 | 1775629800.000000
(1 row)
```

8 April 2026 is a Wednesday. `dow` counts Sunday as 0; `isodow` counts Monday as 1 and Sunday as 7, which is usually
what you want. `week` is the ISO week number (weeks start on Monday, and week 1 contains the year's first Thursday),
so early January days can belong to week 52 or 53 of the previous year; pair it with `isoyear`. `epoch` is seconds
since 1970-01-01 00:00 UTC, the Unix timestamp, and `extract(epoch FROM interval '...')` gives an interval's length in
seconds.

`date_trunc(unit, value)` rounds down to the start of a unit, the workhorse of time-series reports. Truncating a
`timestamptz` happens in the session's time zone unless you pass one as a third argument:

```sql
SELECT created_at,
       date_trunc('month', created_at) AS month,
       date_trunc('week', created_at) AS week,
       date_trunc('day', created_at, 'Asia/Tokyo') AS tokyo_day_start
FROM orders
WHERE id = 10;
```

```text
       created_at       |         month          |          week          |    tokyo_day_start
------------------------+------------------------+------------------------+------------------------
 2026-04-08 06:30:00+00 | 2026-04-01 00:00:00+00 | 2026-04-06 00:00:00+00 | 2026-04-07 15:00:00+00
(1 row)
```

`week` truncates to Monday. The Tokyo day containing this order started at 15:00 UTC the day before.

`date_bin(stride, value, origin)` (14+) rounds down to buckets of any size, aligned to an origin, for example
15-minute slots:

```sql
SELECT created_at, date_bin(interval '15 minutes', created_at, timestamptz '2000-01-01') AS slot
FROM orders
WHERE id IN (4, 9)
ORDER BY id;
```

```text
       created_at       |          slot
------------------------+------------------------
 2026-02-10 08:45:00+00 | 2026-02-10 08:45:00+00
 2026-04-04 11:11:00+00 | 2026-04-04 11:00:00+00
(2 rows)
```

## Formatting and parsing

`to_char(value, pattern)` formats dates and numbers. A few of the many pattern codes:

| Code | Meaning | Code | Meaning |
|---|---|---|---|
| `YYYY` | year | `HH24` | hour, 00–23 |
| `MM` | month number | `MI` | minute |
| `DD` | day of month | `SS` | second |
| `Mon` / `Month` | month name | `TZ` | time zone abbreviation |
| `Dy` / `Day` | weekday name | `IYYY`, `IW` | ISO year and week |
| `Q` | quarter | `FM` | prefix: no padding for the next code |

```sql
SELECT to_char(created_at, 'Dy DD Mon YYYY HH24:MI TZ') AS readable,
       to_char(created_at, 'FMDay, FMDDth FMMonth') AS long_form,
       to_char(created_at, 'IYYY-"W"IW') AS iso_week
FROM orders
WHERE id = 8;
```

```text
         readable          |      long_form       | iso_week
---------------------------+----------------------+----------
 Sat 28 Mar 2026 17:40 UTC | Saturday, 28th March | 2026-W13
(1 row)
```

Text in double quotes inside the pattern is copied literally; `th` adds an ordinal suffix. Names such as `Day` are
padded to a fixed width unless you add `FM`. `to_char` also formats numbers:

```sql
SELECT to_char(1234567.891, 'FM9,999,999.00') AS money,
       to_char(7, 'FM000') AS padded,
       to_char(-42.5, 'FM990.00') AS negative,
       to_char(2026, 'FMRN') AS roman;
```

```text
    money     | padded | negative | roman
--------------+--------+----------+--------
 1,234,567.89 | 007    | -42.50   | MMXXVI
(1 row)
```

In a number pattern, `9` is an optional digit, `0` a digit that is always shown, and `,` and `.` are literal
separators (`G` and `D` use the locale's separators instead). The result is rounded to the pattern, as `.891` became
`.89`. `RN` gives Roman numerals.

Use `to_char` for exports and reports. For values that an application displays, send the raw `timestamptz` or number
and format it in the application, where the user's language and preferences are known.

Parsing goes the other way. Prefer ISO 8601 input with a cast (`'2026-03-28'::date`), which needs no pattern. For other
formats, `to_date` and `to_timestamp` take a pattern; `to_timestamp` with a number converts a Unix timestamp. The
`make_*` functions build values from numbers:

```sql
SELECT to_date('28/03/2026', 'DD/MM/YYYY') AS parsed_date,
       to_timestamp('2026-03-28 17:40', 'YYYY-MM-DD HH24:MI') AS parsed_ts,
       to_timestamp(1775000000) AS from_unix,
       make_date(2026, 3, 28) AS made_date,
       make_timestamptz(2026, 3, 28, 18, 40, 0, 'Europe/Berlin') AS made_ts;
```

```text
 parsed_date |       parsed_ts        |       from_unix        | made_date  |        made_ts
-------------+------------------------+------------------------+------------+------------------------
 2026-03-28  | 2026-03-28 17:40:00+00 | 2026-03-31 23:33:20+00 | 2026-03-28 | 2026-03-28 17:40:00+00
(1 row)
```

`to_timestamp` with a pattern interprets the result in the session's time zone (UTC here).

## Calendar reports

Chapter 8 used `generate_series` to fill gaps in a daily report. It works with any step, so a monthly revenue report
for the first half of the year, including months without sales, is a series of month starts left-joined to the data:

```sql
SELECT to_char(m, 'YYYY-MM') AS month,
       count(DISTINCT o.id) AS orders,
       coalesce(sum(oi.quantity * oi.unit_price), 0) AS revenue
FROM generate_series(timestamptz '2026-01-01', timestamptz '2026-06-01', interval '1 month') AS m
LEFT JOIN orders AS o
       ON o.created_at >= m AND o.created_at < m + interval '1 month'
      AND o.status <> 'cancelled'
LEFT JOIN order_items AS oi ON oi.order_id = o.id
GROUP BY m
ORDER BY m;
```

```text
  month  | orders | revenue
---------+--------+---------
 2026-01 |      2 |  119.00
 2026-02 |      2 |  187.40
 2026-03 |      3 |  192.75
 2026-04 |      4 |  231.70
 2026-05 |      0 |       0
 2026-06 |      0 |       0
(6 rows)
```

The series steps in the session's time zone; since PostgreSQL 16 an optional fourth argument names the zone, so you can
build local-midnight series without changing the session:

```sql
SELECT d FROM generate_series(timestamptz '2026-03-28 00:00 Europe/Berlin',
                              timestamptz '2026-03-30 00:00 Europe/Berlin',
                              interval '1 day', 'Europe/Berlin') AS d;
```

```text
           d
------------------------
 2026-03-27 23:00:00+00
 2026-03-28 23:00:00+00
 2026-03-29 22:00:00+00
(3 rows)
```

Each row is midnight in Berlin; the offset changes from 23:00 UTC to 22:00 UTC after the clocks went forward.

A date series also answers calendar questions directly, with no table at all, for example the first Monday of each
month in the second quarter:

```sql
SELECT min(d)::date AS first_monday
FROM generate_series(date '2026-04-01', date '2026-06-30', interval '1 day') AS d
WHERE extract(isodow FROM d) = 1
GROUP BY date_trunc('month', d)
ORDER BY 1;
```

```text
 first_monday
--------------
 2026-04-06
 2026-05-04
 2026-06-01
(3 rows)
```

To test whether two periods overlap, SQL has the `OVERLAPS` operator: `(start1, end1) OVERLAPS (start2, end2)` treats
each period as half-open. Chapter 14 introduces **range types** (`tstzrange`), which store a period as one value, can
be indexed, and let a constraint forbid overlapping bookings.

## Key takeaways

- `length` counts characters, `octet_length` bytes. `||` returns `NULL` if any part is `NULL`; `concat`, `concat_ws`
  and `coalesce` handle optional parts.
- Build SQL text inside the database only with `format()` and `%I`/`%L`; in applications, use parameters.
- Regular expressions (`~`, `~*`, `regexp_*`) cover what `LIKE` can't. Word boundaries are `\y`, `\m`, `\M`, not `\b`.
- A collation decides sort order, equality and character classes. Providers are libc, ICU and builtin; ICU gives the
  same results everywhere, and builtin `C.UTF-8` gives a code-point order that never changes.
- After an OS or ICU upgrade, a collation version mismatch means text indexes may be corrupt: `REINDEX`, then
  `REFRESH COLLATION VERSION`.
- For case-insensitive matching, normalise on input when you can; otherwise use `casefold()` (18+) or `lower()` with an
  expression index, a nondeterministic ICU collation, or `citext`. `unaccent` and `normalize` deal with accents and
  Unicode forms.
- `now()` is fixed for the transaction; `clock_timestamp()` is the real time. Write `DEFAULT now()`, never
  `DEFAULT 'now'`.
- Store `timestamptz`, keep sessions in UTC, use IANA zone names, and convert at the edges with `AT TIME ZONE`.
  Group by the local day with `(ts AT TIME ZONE zone)::date`.
- Filter time with half-open ranges (`>= start AND < next_start`), not `BETWEEN`.
- `interval '1 day'` and `interval '24 hours'` differ across daylight-saving changes, and the session's time zone
  decides; `date_add` (16+) and the zone arguments of `date_trunc` and `generate_series` (16+) make it explicit.
- `date_trunc`, `date_bin`, `extract`, `to_char` and `generate_series` are the building blocks of time reports.

## Exercises

1. **Slugs.** Produce a URL slug for every active product in category 8 (notebooks): lower case, every run of
   characters other than letters and digits replaced by a single `-`, and no `-` at either end. "Pocket Notebook
   3-pack" should become `pocket-notebook-3-pack`.

<details>
<summary>Solution</summary>

```sql
SELECT name,
       trim(BOTH '-' FROM regexp_replace(lower(name), '[^a-z0-9]+', '-', 'g')) AS slug
FROM products
WHERE category_id = 8 AND active
ORDER BY id;
```

```text
          name          |          slug
------------------------+------------------------
 Dot Grid Notebook A5   | dot-grid-notebook-a5
 Lined Notebook A4      | lined-notebook-a4
 Pocket Notebook 3-pack | pocket-notebook-3-pack
(3 rows)
```

Lower-casing first lets the character class be just `[a-z0-9]`. `trim(BOTH '-' FROM ...)` removes leading and trailing
dashes, which appear when a name starts or ends with punctuation. For names with accents, apply `unaccent()` first, or
`é` would become a dash.

</details>

2. **Size from the name.** Several products end in a size or pack count: `50ml`, `A5`, `A4`, `3-pack`. Using
   `regexp_match` with a capture group, show each product whose name ends in a number followed by `ml` or `-pack`,
   with the number as an integer.

<details>
<summary>Solution</summary>

```sql
SELECT name, (regexp_match(name, '(\d+)(ml|-pack)$'))[1]::int AS amount
FROM products
WHERE name ~ '\d+(ml|-pack)$'
ORDER BY id;
```

```text
          name          | amount
------------------------+--------
 Click Ballpoint 3-pack |      3
 Pocket Notebook 3-pack |      3
 Midnight Blue Ink 50ml |     50
 Sepia Ink 50ml         |     50
(4 rows)
```

`regexp_match` returns an array of the capture groups; `[1]` is the digits, and the cast turns the text into an
integer. Filtering with `~` first keeps rows without a match out of the result (otherwise they'd show `NULL`).

</details>

3. **Out of hours.** The Berlin warehouse team is on duty from 08:00 to 18:00 local time. List the orders placed
   outside those hours, showing the Berlin local time of each.

<details>
<summary>Solution</summary>

```sql
SELECT id, created_at,
       created_at AT TIME ZONE 'Europe/Berlin' AS berlin_time
FROM orders
WHERE (created_at AT TIME ZONE 'Europe/Berlin')::time NOT BETWEEN time '08:00' AND time '18:00'
ORDER BY id;
```

```text
 id |       created_at       |     berlin_time
----+------------------------+---------------------
  3 | 2026-02-03 19:30:00+00 | 2026-02-03 20:30:00
  8 | 2026-03-28 17:40:00+00 | 2026-03-28 18:40:00
 11 | 2026-04-09 20:05:00+00 | 2026-04-09 22:05:00
(3 rows)
```

Converting to Berlin wall-clock time and then casting to `time` keeps only the time of day. Order 8 (17:40 UTC) was
placed at 18:40 in Berlin because of summer time, even though its UTC time looks like office hours. Here `BETWEEN` is
fine because a time of day has no "next day" problem; strictly, 18:00:00 itself counts as on duty.

</details>

4. **Orders by weekday.** Count orders by ISO weekday (Monday first), showing the weekday's name and including
   weekdays with no orders. Use the UTC date.

<details>
<summary>Solution</summary>

```sql
SELECT wd AS isodow,
       to_char(date '2026-01-05' + wd - 1, 'Day') AS weekday,
       count(o.id) AS orders
FROM generate_series(1, 7) AS wd
LEFT JOIN orders AS o ON extract(isodow FROM o.created_at) = wd
GROUP BY wd
ORDER BY wd;
```

```text
 isodow |  weekday  | orders
--------+-----------+--------
      1 | Monday    |      1
      2 | Tuesday   |      3
      3 | Wednesday |      1
      4 | Thursday  |      2
      5 | Friday    |      2
      6 | Saturday  |      3
      7 | Sunday    |      0
(7 rows)
```

5 January 2026 is a Monday, so adding `wd - 1` days gives a date with each weekday's name. `generate_series(1, 7)`
supplies all seven weekdays so that empty ones show `0`. `extract` returns `numeric`, which compares fine with the
integer `wd`.

</details>

5. **Business days.** (a) How many working days (Monday to Friday) are there in April 2026? (b) Inkwell promises
   dispatch within 3 working days. For each paid order, compute the latest dispatch date: the third working day after
   the order's (UTC) date.

<details>
<summary>Solution</summary>

```sql
SELECT count(*) AS working_days
FROM generate_series(date '2026-04-01', date '2026-04-30', interval '1 day') AS d
WHERE extract(isodow FROM d) < 6;
```

```text
 working_days
--------------
           22
(1 row)
```

```sql
SELECT o.id, o.created_at::date AS ordered,
       (SELECT d::date
        FROM generate_series(o.created_at::date + 1, o.created_at::date + 14, interval '1 day') AS d
        WHERE extract(isodow FROM d) < 6
        ORDER BY d
        OFFSET 2 LIMIT 1) AS dispatch_by
FROM orders AS o
WHERE o.status = 'paid'
ORDER BY o.id;
```

```text
 id |  ordered   | dispatch_by
----+------------+-------------
 10 | 2026-04-08 | 2026-04-13
 11 | 2026-04-09 | 2026-04-14
(2 rows)
```

The correlated subquery generates the next two weeks after each order date, keeps working days, and skips two to land
on the third. Order 11 was placed on a Thursday, so its deadline is the following Tuesday. A real shop also skips
public holidays, which you would keep in a table and exclude with `NOT EXISTS`.

</details>

6. **Case-insensitive uniqueness.** Create a table `newsletter_signups (email text PRIMARY KEY)` whose email column
   uses a nondeterministic, case-insensitive ICU collation, so that `Ana@Example.com` and `ana@example.com` count as
   the same address. Show that the second insert fails, and that a lookup in a different case finds the row. Drop the
   table afterwards.

<details>
<summary>Solution</summary>

```sql
CREATE COLLATION IF NOT EXISTS case_insensitive
    (provider = icu, locale = 'und-u-ks-level2', deterministic = false);
CREATE TABLE newsletter_signups (
    email text COLLATE case_insensitive PRIMARY KEY
);
INSERT INTO newsletter_signups VALUES ('Ana@Example.com');
INSERT INTO newsletter_signups VALUES ('ana@example.com');
SELECT * FROM newsletter_signups WHERE email = 'ANA@EXAMPLE.COM';
DROP TABLE newsletter_signups;
```

```text
CREATE COLLATION
CREATE TABLE
INSERT 0 1
ERROR:  duplicate key value violates unique constraint "newsletter_signups_pkey"
DETAIL:  Key (email)=(ana@example.com) already exists.
      email
-----------------
 Ana@Example.com
(1 row)

DROP TABLE
```

The primary key's index uses the column's collation, so uniqueness is case-insensitive, and so is `=` in the lookup.
The stored value keeps the case the user typed. The alternative designs are a `citext` column, or a plain column plus
a unique index on `casefold(email)` (chapter 15).

</details>

---
← Previous: [Window functions](10-window-functions.md) | Next: [JSON, JSONB and arrays](12-json-jsonb-and-arrays.md) →
