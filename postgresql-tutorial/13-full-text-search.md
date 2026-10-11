# Chapter 13 — Full-text search

## What you'll learn

- Why `LIKE '%word%'` is the wrong tool for searching text
- Documents and queries: the `tsvector` and `tsquery` types and the `@@` match operator
- How text is parsed, normalised and stemmed, and how text search configurations control it
- Turning user input into queries safely with `websearch_to_tsquery`, plus phrase and prefix searches
- Weighting fields, ranking results with `ts_rank`, and highlighting matches with `ts_headline`
- Storing a search vector in a generated column and indexing it with GIN
- Accent-insensitive search with an `unaccent` configuration
- Fuzzy matching and "did you mean" suggestions with the `pg_trgm` extension
- Where PostgreSQL's search stops and a dedicated search engine starts

A customer types "fountain pens for writing" into the shop's search box. `ILIKE '%fountain pens for writing%'` finds
nothing, because no description contains exactly that string. Splitting the words and requiring all of them with
`ILIKE` fails too: the descriptions say "pen", not "pens", and "writing" appears in some of them while "write" might
appear in others. Even when it works, `ILIKE '%...%'` can't use an ordinary index, and it can't tell you which result
is the best match.

Full-text search solves all three problems. It understands words rather than characters, reduces them to a common
root (*stemming*), ignores filler words, ranks results by relevance and is fast with the right index. It is built into
PostgreSQL, and for a product catalogue, a help centre or a blog it is often all you need. Start from the reference
data (chapter 5). This chapter adds a search column, an index and two text search configurations; the next reset
removes them.

## Documents and queries

Full-text search works on two data types:

- A **`tsvector`** is a document prepared for searching: a sorted list of distinct normalised words, called
  **lexemes**, each with the positions where it appears.
- A **`tsquery`** is a search condition: lexemes combined with `&` (and), `|` (or), `!` (not) and `<->` (followed by).

`to_tsvector` turns text into a `tsvector`:

```sql
SELECT to_tsvector('english', description) AS document
FROM products
WHERE id = 1;
```

```text
                                                        document
------------------------------------------------------------------------------------------------------------------------
 'everyday':15 'fine':8 'fountain':4 'light':2 'nib':10 'pen':5 'reliabl':13 'resin':3 'smooth':11 'steel':9 'write':16
(1 row)
```

Compare it with the original text, "A light resin fountain pen with a fine steel nib. Smooth and reliable for everyday
writing.":

- Everything is lower case, and the punctuation is gone.
- *Stop words* such as `a`, `with`, `and` and `for` were dropped. They are so common that they carry no meaning for
  search.
- Words were **stemmed**: `writing` became `write`, and `reliable` became `reliabl`. A lexeme is not always a real
  word; it only needs to be the same for every form of the word.
- Each lexeme lists its positions in the text (`'pen':5`), which phrase searches and ranking use. Positions count the
  removed stop words too.

`to_tsquery` turns a query into a `tsquery`, normalising the words the same way, and `@@` tests whether a document
matches:

```sql
SELECT to_tsquery('english', 'pens & writing') AS query,
       to_tsvector('english', description) @@ to_tsquery('english', 'pens & writing') AS matches
FROM products
WHERE id = 1;
```

```text
      query      | matches
-----------------+---------
 'pen' & 'write' | t
(1 row)
```

`pens` became `pen` and `writing` became `write`, exactly as in the document, so the plural and the different word
form still match. Here is the search across all products:

```sql
SELECT name
FROM products
WHERE to_tsvector('english', name || ' ' || description) @@ to_tsquery('english', 'pens & writing')
ORDER BY name;
```

```text
         name
----------------------
 Heron Fountain Pen
 Kestrel Fountain Pen
(2 rows)
```

Both fountain pens match: their descriptions contain "pen" and "writing". The Graphite Pencil's description mentions
writing but no pen, and the Everyday Ballpoint is a pen whose description doesn't mention writing.

## Configurations, parsers and dictionaries

The first argument, `'english'`, is a **text search configuration**. It decides how text becomes lexemes in two steps:

1. A **parser** splits the text into tokens and classifies each one (`asciiword`, `word` for words with non-ASCII
   letters, `numword`, `email`, `url`, `int`, `float`, `blank`, ...).
2. For each token type, a list of **dictionaries** turns the token into lexemes, or into nothing (a stop word). The
   first dictionary that recognises the token decides.

`ts_debug` shows each step, and is the tool to reach for whenever a search doesn't match what you expected:

```sql
SELECT alias, token, dictionaries, lexemes
FROM ts_debug('english', 'The pens'' nibs are writing smoothly!')
WHERE alias <> 'blank';
```

```text
   alias   |  token   |  dictionaries  | lexemes
-----------+----------+----------------+----------
 asciiword | The      | {english_stem} | {}
 asciiword | pens     | {english_stem} | {pen}
 asciiword | nibs     | {english_stem} | {nib}
 asciiword | are      | {english_stem} | {}
 asciiword | writing  | {english_stem} | {write}
 asciiword | smoothly | {english_stem} | {smooth}
(6 rows)
```

`english_stem` is a *Snowball* stemmer, which knows English suffix rules and an English stop-word list. `The` and
`are` produced an empty lexeme list, which means "stop word". PostgreSQL ships configurations for about 30 languages,
and `simple`, which only lower-cases and removes nothing:

```sql
SELECT to_tsvector('english', 'The pens are writing') AS english,
       to_tsvector('simple', 'The pens are writing') AS simple,
       to_tsvector('german', 'Die Füller schreiben') AS german;
```

```text
      english      |                simple                |        german
-------------------+--------------------------------------+----------------------
 'pen':2 'write':4 | 'are':3 'pens':2 'the':1 'writing':4 | 'full':2 'schreib':3
(1 row)
```

`\dF` in psql lists the configurations, and `\dF+ english` shows which dictionaries each token type uses.

If you leave out the configuration, PostgreSQL uses the `default_text_search_config` setting, which `initdb` chose
from your operating system's locale. **Always pass the configuration explicitly.** It makes results independent of
server settings, and as you'll see below, only the two-argument form can be used in an index.

Use `english` (or the language of your text) for prose, where stemming helps. Use `simple` for names, codes and other
text where stemming would do harm. For content in several languages, store the language with each row in a
`regconfig` column and call `to_tsvector(lang, body)`.

## Writing queries

User input should never go into `to_tsquery` directly, because it expects operator syntax and rejects anything else:

```sql
SELECT to_tsquery('english', 'fountain pen');
```

```text
ERROR:  syntax error in tsquery: "fountain pen"
```

There are four functions that build a `tsquery`, from strictest to most forgiving:

| Function | Input | `fountain pens` becomes |
|---|---|---|
| `to_tsquery` | operator syntax: `&`, `\|`, `!`, `<->`, `:*`, parentheses | an error (needs an operator) |
| `plainto_tsquery` | plain text; all words must appear | `'fountain' & 'pen'` |
| `phraseto_tsquery` | plain text; the words must appear in this order, adjacent | `'fountain' <-> 'pen'` |
| `websearch_to_tsquery` | search-engine syntax: `"quoted phrases"`, `or`, `-excluded` | `'fountain' & 'pen'` |

`websearch_to_tsquery` is the one for search boxes. It accepts the syntax people already know from web search engines
and never raises an error, whatever the user types:

```sql
SELECT websearch_to_tsquery('english', 'fountain pens') AS plain,
       websearch_to_tsquery('english', '"fountain pen" ink') AS phrase,
       websearch_to_tsquery('english', 'notebook -lined') AS excluded,
       websearch_to_tsquery('english', 'pencil or ballpoint') AS alternatives,
       websearch_to_tsquery('english', '((( & !') AS garbage;
```

```text
NOTICE:  text-search query contains only stop words or doesn't contain lexemes, ignored
       plain        |            phrase            |       excluded       |      alternatives      | garbage
--------------------+------------------------------+----------------------+------------------------+---------
 'fountain' & 'pen' | 'fountain' <-> 'pen' & 'ink' | 'notebook' & !'line' | 'pencil' | 'ballpoint' |
(1 row)
```

The last query consists only of operators; it becomes an empty query, which matches nothing (PostgreSQL prints a
notice, and you should check for empty input in your application rather than run the search).

### Phrases and proximity

`<->` means "immediately followed by", and `<N>` means "followed at distance N". A phrase query uses the positions
stored in the `tsvector`:

```sql
SELECT name,
       to_tsvector('english', description) @@ to_tsquery('english', 'pen <-> ink') AS adjacent,
       to_tsvector('english', description) @@ to_tsquery('english', 'pen <3> ink') AS three_apart
FROM products
WHERE to_tsvector('english', description) @@ to_tsquery('english', 'pen & ink')
ORDER BY name;
```

```text
          name          | adjacent | three_apart
------------------------+----------+-------------
 Dot Grid Notebook A5   | t        | f
 Everyday Ballpoint     | f        | t
 Midnight Blue Ink 50ml | t        | f
 Sepia Ink 50ml         | t        | f
(4 rows)
```

Four descriptions contain both words. Three of them say "fountain pen ink", so `ink` directly follows `pen`. The
Everyday Ballpoint says "pen with blue ink": `ink` is three positions after `pen`, because the stop word `with` still
occupies a position. `phraseto_tsquery` and quoted phrases in `websearch_to_tsquery` produce `<->` queries.

### Prefix matching

`:*` after a word in `to_tsquery` matches every lexeme that starts with it, which is how search-as-you-type works:

```sql
SELECT name
FROM products
WHERE to_tsvector('english', name) @@ to_tsquery('english', 'note:*')
ORDER BY name;
```

```text
          name
------------------------
 Dot Grid Notebook A5
 Lined Notebook A4
 Pocket Notebook 3-pack
(3 rows)
```

Be careful when you build a prefix query from user input: `to_tsquery` would reject or misinterpret characters such as
`&` or `:` in what the user typed. Strip the input to letters and digits first in your application, or quote it with
`quote_literal`: `to_tsquery('english', quote_literal('note') || ':*')`.

## Searching several fields, with weights

A product's name is a better indicator of relevance than its description. A `tsvector` can carry a **weight** per
lexeme: `A` (highest), `B`, `C` or `D` (the default). `setweight` labels a whole vector, and `||` combines vectors:

```sql
SELECT setweight(to_tsvector('english', name), 'A')
       || setweight(to_tsvector('english', description), 'B') AS document
FROM products
WHERE id = 11;
```

```text
                                                 document
-----------------------------------------------------------------------------------------------------------
 '50ml':4A 'blue':2A,6B 'deep':5B 'fountain':7B 'ink':3A,9B 'midnight':1A 'pen':8B 'shade':12B 'subtl':11B
(1 row)
```

`'blue':2A,6B` means "blue" appears at position 2 with weight A (in the name) and at position 6 with weight B (in the
description). `||` shifts the positions of the second vector to follow the first, so the last word of the name and the
first word of the description count as adjacent for phrase searches; that rarely matters.

## Ranking

`@@` only says whether a document matches. To show the best matches first, compute a rank:

- `ts_rank(vector, query)` scores by how often the query's lexemes appear, scaled by their weights.
- `ts_rank_cd(vector, query)` uses *cover density*: matches close to each other score higher.

```sql
SELECT name,
       round(ts_rank(setweight(to_tsvector('english', name), 'A')
                     || setweight(to_tsvector('english', description), 'B'),
                     websearch_to_tsquery('english', 'fountain pen'))::numeric, 4) AS rank
FROM products
WHERE (setweight(to_tsvector('english', name), 'A')
       || setweight(to_tsvector('english', description), 'B'))
      @@ websearch_to_tsquery('english', 'fountain pen')
ORDER BY rank DESC, name;
```

```text
          name          |  rank
------------------------+--------
 Heron Fountain Pen     | 0.9990
 Kestrel Fountain Pen   | 0.9990
 Dot Grid Notebook A5   | 0.3964
 Midnight Blue Ink 50ml | 0.3964
 Sepia Ink 50ml         | 0.3964
(5 rows)
```

The two products with "Fountain Pen" in the name come first, because weight A counts for more (by default the
weights D, C, B and A are worth 0.1, 0.2, 0.4 and 1.0). The ranks are only meaningful compared with each other within
one query; don't show them to users or store them.

Repeating the long expression is tedious and slow, because the vector is rebuilt for every row and every use. The
solution is to store it.

## Storing and indexing the search vector

A **stored generated column** computes the `tsvector` when a row is written and keeps it up to date automatically. A
**GIN index** on it maps each lexeme to the rows that contain it, so a search reads only the matching rows:

```sql
ALTER TABLE products
    ADD COLUMN search tsvector
    GENERATED ALWAYS AS (setweight(to_tsvector('english', name), 'A')
                         || setweight(to_tsvector('english', description), 'B')) STORED;

CREATE INDEX products_search_idx ON products USING gin (search);
```

```text
ALTER TABLE
CREATE INDEX
```

Two details matter here. The column must be `STORED`: PostgreSQL 18 makes generated columns virtual by default, and a
virtual column can't be indexed. And the expression must be **immutable**, which is why it names the configuration
explicitly: the one-argument `to_tsvector(text)` depends on a setting, so PostgreSQL refuses it in a generated column
or an index.

Searches now read naturally:

```sql
SELECT name, round(ts_rank(search, q)::numeric, 4) AS rank
FROM products, websearch_to_tsquery('english', 'ink') AS q
WHERE search @@ q
ORDER BY rank DESC, name;
```

```text
          name          |  rank
------------------------+--------
 Midnight Blue Ink 50ml | 0.6687
 Sepia Ink 50ml         | 0.6687
 Dot Grid Notebook A5   | 0.2432
 Everyday Ballpoint     | 0.2432
(4 rows)
```

Putting the query in `FROM` as `q` lets you write it once and use it in both `WHERE` and `ts_rank`. With 14 rows the
planner prefers to read the whole table, so to see the index used, switch sequential scans off for a moment:

```sql
SET enable_seqscan = off;
EXPLAIN (COSTS OFF)
SELECT name FROM products WHERE search @@ websearch_to_tsquery('english', 'ink');
RESET enable_seqscan;
```

```text
SET
                     QUERY PLAN
----------------------------------------------------
 Bitmap Heap Scan on products
   Recheck Cond: (search @@ '''ink'''::tsquery)
   ->  Bitmap Index Scan on products_search_idx
         Index Cond: (search @@ '''ink'''::tsquery)
(4 rows)

RESET
```

The alternative to a column is an **expression index**, `CREATE INDEX ON products USING gin (to_tsvector('english',
description))`. It saves the storage, but every query must repeat exactly the same expression to use it, and ranking
must recompute the vector for each result. The generated column is easier to get right.

GIN indexes are fast to search but relatively slow to update; PostgreSQL batches new entries in a *pending list* to
soften this (chapter 15). For search over text that changes constantly, that trade-off is usually still worth it.

## Highlighting matches

`ts_headline` returns a fragment of the original text with the matching words marked, for a result list:

```sql
SELECT name,
       ts_headline('english', description, q) AS snippet
FROM products, websearch_to_tsquery('english', 'fountain pen ink') AS q
WHERE search @@ q
ORDER BY ts_rank(search, q) DESC, name;
```

```text
          name          |                                                     snippet
------------------------+-----------------------------------------------------------------------------------------------------------------
 Midnight Blue Ink 50ml | Deep blue <b>fountain</b> <b>pen</b> <b>ink</b> with subtle shading.
 Sepia Ink 50ml         | Warm brown <b>fountain</b> <b>pen</b> <b>ink</b> with a vintage look.
 Dot Grid Notebook A5   | A5 notebook with 192 pages of dotted paper that handles <b>fountain</b> <b>pen</b> <b>ink</b> without bleeding.
(3 rows)
```

Options control the markers and the fragment size:

```sql
SELECT ts_headline('english', description, websearch_to_tsquery('english', 'pages'),
                   'StartSel=[, StopSel=], MaxWords=8, MinWords=4') AS snippet
FROM products
WHERE id = 7;
```

```text
         snippet
-------------------------
 [pages] of dotted paper
(1 row)
```

`ts_headline` works on the original text, not the `tsvector`, so it is relatively expensive. Apply it only to the rows
you display, after `LIMIT`, for example in an outer query around the ranked search. And when you insert its output
into a web page, remember that it doesn't escape HTML in the source text.

## Accent-insensitive search

Chapter 11 showed the `unaccent` extension, and noted that the `unaccent()` function can't be used in an index. The
solution for search is to use `unaccent` as a **dictionary** inside your own text search configuration, which then
removes accents as part of normalisation. Customer names are a good example: they shouldn't be stemmed, so the new
configuration starts from `simple`:

```sql
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE TEXT SEARCH CONFIGURATION names (COPY = simple);
ALTER TEXT SEARCH CONFIGURATION names
    ALTER MAPPING FOR hword, hword_part, word WITH unaccent, simple;

SELECT to_tsvector('names', 'Chloé Martin') AS vector;
```

```text
CREATE EXTENSION
CREATE TEXT SEARCH CONFIGURATION
ALTER TEXT SEARCH CONFIGURATION
        vector
----------------------
 'chloe':1 'martin':2
(1 row)
```

The mapping applies to `word`, `hword` and `hword_part`, the token types for words that contain non-ASCII letters
(plain ASCII words have nothing to unaccent). For each such word, `unaccent` strips the accents and passes the result
on to `simple`. Because the configuration is named explicitly, `to_tsvector('names', ...)` is immutable and can be
indexed:

```sql
CREATE INDEX customers_name_search_idx ON customers USING gin (to_tsvector('names', full_name));

SELECT full_name
FROM customers
WHERE to_tsvector('names', full_name) @@ websearch_to_tsquery('names', 'chloe');
```

```text
CREATE INDEX
  full_name
--------------
 Chloé Martin
(1 row)
```

The query is normalised by the same configuration, so `chloe`, `Chloé` and `CHLOE` all find her. A stemming
configuration works the same way: `CREATE TEXT SEARCH CONFIGURATION shop (COPY = english)` with `ALTER MAPPING FOR
hword, hword_part, word WITH unaccent, english_stem`. If you later change a configuration that an index uses, rebuild
the index (`REINDEX`), because the stored lexemes were made by the old rules.

## Fuzzy matching with pg_trgm

Full-text search matches *words*. It doesn't help when the user misspells one: "kestral" has no lexeme in common with
"Kestrel". The `pg_trgm` extension compares strings by their **trigrams**, the groups of three consecutive characters
in each word (padded with spaces at the ends). Two strings that share most of their trigrams are similar:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
SELECT show_trgm('pen') AS trigrams,
       similarity('Kestrel', 'Kestral') AS similar,
       similarity('Kestrel', 'Heron') AS different;
```

```text
CREATE EXTENSION
        trigrams         |  similar   | different
-------------------------+------------+-----------
 {"  p"," pe","en ",pen} | 0.45454547 |         0
(1 row)
```

`similarity` returns a number from 0 (nothing in common) to 1 (identical). The `%` operator is true when the
similarity is above the `pg_trgm.similarity_threshold` setting (0.3 by default). When you compare a short search term
with a longer text, `word_similarity` and its operator `<%` are more useful: they compare the term with the most
similar *part* of the text:

```sql
SELECT name,
       round(word_similarity('kestral', name)::numeric, 3) AS word_sim
FROM products
WHERE 'kestral' <% name
ORDER BY word_sim DESC, name;
```

```text
         name         | word_sim
----------------------+----------
 Kestrel Fountain Pen |    0.625
(1 row)
```

The distance operator `<->` (1 minus similarity) gives "closest first" ordering, which is a good fallback when a
full-text search returns nothing:

```sql
SELECT name, round((name <-> 'leathr case')::numeric, 3) AS distance
FROM products
ORDER BY name <-> 'leathr case'
LIMIT 3;
```

```text
       name        | distance
-------------------+----------
 Leather Pen Case  |    0.474
 Brass Pen Clip    |    0.962
 Lined Notebook A4 |    0.966
(3 rows)
```

Trigram indexes come in two kinds (chapter 15): a **GIN** index (`gin_trgm_ops`) speeds up `%`, `<%`, and, very
usefully, `LIKE`, `ILIKE` and regular expressions with wildcards anywhere; a **GiST** index (`gist_trgm_ops`) also
supports ordering by `<->` to find the nearest matches directly:

```sql
CREATE INDEX products_name_trgm_idx ON products USING gin (name gin_trgm_ops);
SET enable_seqscan = off;
EXPLAIN (COSTS OFF)
SELECT name FROM products WHERE name ILIKE '%pocket%';
RESET enable_seqscan;
```

```text
CREATE INDEX
SET
                    QUERY PLAN
---------------------------------------------------
 Bitmap Heap Scan on products
   Recheck Cond: (name ~~* '%pocket%'::text)
   ->  Bitmap Index Scan on products_name_trgm_idx
         Index Cond: (name ~~* '%pocket%'::text)
(4 rows)

RESET
```

That is the answer to chapter 6's warning that `ILIKE '%word%'` can't use an index: with a trigram index it can.

### "Did you mean ...?"

`ts_stat` reads a set of `tsvector`s and lists every lexeme with its document count. Using the `simple` configuration,
so that the words are not stemmed, it produces a vocabulary of real words from the catalogue, and trigram similarity
picks the closest one to a misspelt search such as `pencel`:

```sql
SELECT word, ndoc, round(similarity(word, 'pencel')::numeric, 3) AS similarity
FROM ts_stat($$SELECT to_tsvector('simple', name || ' ' || description) FROM products$$)
ORDER BY similarity(word, 'pencel') DESC, ndoc DESC
LIMIT 3;
```

```text
  word   | ndoc | similarity
---------+------+------------
 pencil  |    2 |      0.400
 pen     |    8 |      0.375
 pencils |    2 |      0.364
(3 rows)
```

`$$ ... $$` is *dollar quoting*, a way to write a string literal without doubling the single quotes inside it
(chapter 19 uses it for function bodies). `ts_stat` scans every document, so a real application would keep the
vocabulary in a table, refreshed now and then (a materialized view, chapter 20, is ideal), with a trigram index on it.

## How far does this go?

PostgreSQL's full-text search is a strong choice when:

- the data already lives in PostgreSQL, and search results must be consistent with it (a product appears in search
  the moment its transaction commits, and never after it is deleted);
- the collection is up to millions of documents of modest size;
- you need language-aware matching, ranking, phrases, prefixes, highlighting and typo tolerance.

It is weaker when you need relevance ranking that uses statistics across the whole collection (the *BM25* scoring that
search engines use; `ts_rank` looks at each document on its own), faceted navigation over huge result sets,
cross-language search, or search across hundreds of millions of documents. That is the territory of dedicated engines
such as OpenSearch, Elasticsearch, Meilisearch or Typesense, at the cost of a second system to run and keep in sync.
Searching by *meaning* rather than words ("something to write letters with") needs vector embeddings, for which the
third-party `pgvector` extension is the usual choice in PostgreSQL.

## Key takeaways

- `ILIKE '%...%'` matches characters, not words: no stemming, no ranking and no ordinary index. Use full-text search
  for words and `pg_trgm` for substrings and typos.
- A `tsvector` is a document as sorted, normalised lexemes with positions; a `tsquery` combines lexemes with `&`, `|`,
  `!` and `<->`; `@@` matches them.
- The text search configuration (`english`, `simple`, ...) controls parsing, stop words and stemming. Always pass it
  explicitly; `ts_debug` explains surprising results.
- Use `websearch_to_tsquery` for user input: it understands quotes, `or` and `-`, and never raises an error.
- Weight fields with `setweight`, rank with `ts_rank` or `ts_rank_cd`, and highlight only the displayed rows with
  `ts_headline`.
- Store the vector in a `STORED` generated column with a GIN index; virtual generated columns can't be indexed.
- For accent-insensitive search, put the `unaccent` dictionary into a custom configuration.
- `pg_trgm` provides `similarity`, `%`, `<%` and `<->`, and its GIN index speeds up `LIKE`/`ILIKE`/regex with
  wildcards anywhere.

## Exercises

Each solution starts from the reference data, so create the extensions and the search column again where you need
them.

1. **Search box.** Find the products matching the user input `notebook for fountain pens`, using
   `websearch_to_tsquery` on name and description, best match first, and show the rank rounded to 4 places.

<details>
<summary>Solution</summary>

```sql
SELECT name,
       round(ts_rank(to_tsvector('english', name || ' ' || description), q)::numeric, 4) AS rank
FROM products, websearch_to_tsquery('english', 'notebook for fountain pens') AS q
WHERE to_tsvector('english', name || ' ' || description) @@ q
ORDER BY rank DESC, name;
```

```text
         name         |  rank
----------------------+--------
 Dot Grid Notebook A5 | 0.2102
(1 row)
```

`for` is a stop word and disappears from the query, which becomes `'notebook' & 'fountain' & 'pen'`. Only the Dot Grid
Notebook mentions fountain pens.

</details>

2. **Reviews.** Search the review texts for `smooth or balance`, showing the product name, the rating and the review
   with the matching words highlighted between `*` characters. Explain why "balanced" matches.

<details>
<summary>Solution</summary>

```sql
SELECT p.name, r.rating,
       ts_headline('english', r.body, q, 'StartSel=*, StopSel=*') AS review
FROM reviews AS r
JOIN products AS p ON p.id = r.product_id,
     websearch_to_tsquery('english', 'smooth or balance') AS q
WHERE to_tsvector('english', coalesce(r.body, '')) @@ q
ORDER BY p.name;
```

```text
         name         | rating |              review
----------------------+--------+----------------------------------
 Heron Fountain Pen   |      5 | Heavy, *balanced* and beautiful.
 Kestrel Fountain Pen |      5 | *Smooth* nib, writes every time.
(2 rows)
```

The stemmer reduces both `balance` and `balanced` to the lexeme `balanc`. `coalesce` turns the review with no text
into an empty string; `to_tsvector` of `NULL` is `NULL`, which would simply not match, so here it is mostly for
clarity.

</details>

3. **Search as you type.** The user has typed `penc` so far. Find the products whose name or description contains a
   word starting with it. Then explain why `pen:*` returns more products than `pens` would.

<details>
<summary>Solution</summary>

```sql
SELECT name
FROM products
WHERE to_tsvector('english', name || ' ' || description) @@ to_tsquery('english', 'penc:*')
ORDER BY name;
```

```text
         name
----------------------
 Brass Pen Clip
 Graphite Pencil HB
 Sketching Pencil Set
(3 rows)
```

The prefix `penc` matches the lexeme `pencil`. A prefix query on `pen:*` would match every lexeme starting with
`pen`: `pen`, `pencil` and anything else, so it finds pens, pencils and the products that mention them. `pens` is
stemmed to `pen` and matches only that lexeme. The prefix itself is stemmed too: `to_tsquery('english',
'pencils:*')` becomes `'pencil':*`.

</details>

4. **Stemmed or not.** Compare `to_tsvector('english', ...)` and `to_tsvector('simple', ...)` on the text
   `Three plain pocket notebooks with 64 pages each.` Which lexemes differ, and which configuration would you use to
   search product codes such as `NB-003`?

<details>
<summary>Solution</summary>

```sql
SELECT to_tsvector('english', 'Three plain pocket notebooks with 64 pages each.') AS english,
       to_tsvector('simple', 'Three plain pocket notebooks with 64 pages each.') AS simple;
SELECT alias, token, lexemes FROM ts_debug('simple', 'NB-003') WHERE alias <> 'blank';
```

```text
                           english                           |                                     simple
-------------------------------------------------------------+---------------------------------------------------------------------------------
 '64':6 'notebook':4 'page':7 'plain':2 'pocket':3 'three':1 | '64':6 'each':8 'notebooks':4 'pages':7 'plain':2 'pocket':3 'three':1 'with':5
(1 row)

   alias   | token | lexemes
-----------+-------+---------
 asciiword | NB    | {nb}
 int       | -003  | {-003}
(2 rows)
```

`english` drops the stop words (`with`, `each`) and stems `notebooks` to `notebook` and `pages` to `page`; `simple`
keeps every word as written, lower-cased. The parser reads `NB-003` as the word `nb` followed by the negative
integer `-003`, which loses its meaning, so codes are better matched exactly (`sku = upper(...)`) or with a trigram index, not with full-text search.

</details>

5. **Did you mean?** A user searched for `notbook` and got no results. Write a query that suggests the closest word
   from the product names and descriptions, and then use the suggestion in a real search.

<details>
<summary>Solution</summary>

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;

WITH vocabulary AS (
    SELECT word
    FROM ts_stat($$SELECT to_tsvector('simple', name || ' ' || description) FROM products$$)
),
suggestion AS (
    SELECT word
    FROM vocabulary
    WHERE word % 'notbook'
    ORDER BY similarity(word, 'notbook') DESC
    LIMIT 1
)
SELECT s.word AS did_you_mean, p.name
FROM suggestion AS s
JOIN products AS p
  ON to_tsvector('english', p.name || ' ' || p.description) @@ plainto_tsquery('english', s.word)
ORDER BY p.name;
```

```text
CREATE EXTENSION
 did_you_mean |          name
--------------+------------------------
 notebook     | Dot Grid Notebook A5
 notebook     | Lined Notebook A4
 notebook     | Pocket Notebook 3-pack
(3 rows)
```

The `%` filter keeps only reasonably similar words (above the 0.3 threshold), so a hopeless query suggests nothing
rather than something random. `notebook` and `notebooks` are both in the vocabulary; the closer one wins, and
`plainto_tsquery` stems it anyway.

</details>

6. **A search function's core.** Combine everything: a stored `search` column with weights (name `A`, description
   `B`) and a GIN index, then a query for the input `blue ink` that returns the name, the rank, and a highlighted
   snippet computed only for the top 2 results.

<details>
<summary>Solution</summary>

```sql
ALTER TABLE products
    ADD COLUMN search tsvector
    GENERATED ALWAYS AS (setweight(to_tsvector('english', name), 'A')
                         || setweight(to_tsvector('english', description), 'B')) STORED;
CREATE INDEX products_search_idx ON products USING gin (search);

SELECT top.name, round(top.rank::numeric, 4) AS rank,
       ts_headline('english', top.description, top.q, 'StartSel=<b>, StopSel=</b>') AS snippet
FROM (
    SELECT p.name, p.description, q, ts_rank(p.search, q) AS rank
    FROM products AS p, websearch_to_tsquery('english', 'blue ink') AS q
    WHERE p.search @@ q
    ORDER BY rank DESC, p.name
    LIMIT 2
) AS top
ORDER BY top.rank DESC, top.name;
```

```text
ALTER TABLE
CREATE INDEX
          name          |  rank  |                              snippet
------------------------+--------+--------------------------------------------------------------------
 Midnight Blue Ink 50ml | 0.9989 | Deep <b>blue</b> fountain pen <b>ink</b> with subtle shading.
 Everyday Ballpoint     | 0.3964 | A dependable disposable ballpoint pen with <b>blue</b> <b>ink</b>.
(2 rows)
```

The inner query finds and ranks matches using the index and the stored vector; only the two rows that survive `LIMIT`
reach `ts_headline` in the outer query. Chapter 19 wraps a query like this in a function, and chapter 22 calls it from
Node.js and PHP.

</details>

---
← Previous: [JSON, JSONB and arrays](12-json-jsonb-and-arrays.md) | Next: [Data modeling](14-data-modeling.md) →
