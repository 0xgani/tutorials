# Chapter 18 — Storage, VACUUM and maintenance

## What you'll learn

- Where PostgreSQL keeps your data on disk: files, forks, 8 kB pages and tuples
- How column order affects row size, and how TOAST stores large values
- The shared buffer cache, asynchronous I/O (18+), the write-ahead log and checkpoints, and what makes a commit durable
- Why every update and delete leaves dead tuples behind, and how `VACUUM` cleans up after them
- The visibility map, HOT updates and `fillfactor`
- What stops `VACUUM` from doing its job, and how to find it
- How autovacuum decides when to run, and how to tune it
- Transaction ID wraparound and freezing
- Measuring and fixing bloat in tables and indexes, and the statistics views you need to run a database

Chapter 17 showed that an `UPDATE` never overwrites a row: it writes a new version and leaves the old one behind. This
chapter follows those versions down to the disk, and looks at the machinery that keeps a busy database from filling up
with garbage. Most of it runs automatically and needs no attention, until it suddenly does. A table that has grown to
ten times its real size, a query that got slower every week, or a database that stops accepting writes to protect
itself: all three come from this chapter's topics, and all three are avoidable once you know how the pieces fit.

## Setting up

This chapter uses the bulk data from chapter 15. Load it fresh:

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
psql -X -v ON_ERROR_STOP=1 -d inkwell -f sql/bulk.sql
```

Three extensions that ship with PostgreSQL let you look inside tables and pages. They are diagnostic tools, not
something applications use:

```sql
CREATE EXTENSION pageinspect;
CREATE EXTENSION pgstattuple;
CREATE EXTENSION pg_visibility;
```

```text
CREATE EXTENSION
CREATE EXTENSION
CREATE EXTENSION
```

- `pageinspect` shows the raw contents of a page.
- `pgstattuple` counts live and dead tuples and free space by reading a whole table or index.
- `pg_visibility` reads the visibility map, which comes up later in this chapter.

All three need superuser rights (or a role granted access by a superuser), so you may not be able to use them on a
managed cloud database.

## Where the data lives

Everything PostgreSQL stores lives in its **data directory** (`SHOW data_directory;` prints it). Each database is a
subdirectory of `base/`, and each table and index is a file in it, named by a number. `pg_relation_filepath()` tells you
which:

```sql
SELECT pg_relation_filepath('orders');
```

The result looks like `base/16384/16450`: the database's OID and the table's file number. Yours will be different
numbers. Each table has several files. In a shell on the database server, as the operating system user that runs
PostgreSQL, list them with your numbers:

```bash
cd "$PGDATA/base/16384" && ls 16450*
```

```text
16450  16450_fsm  16450_vm
```

- `16450` is the table's data, the *main fork*. Above 1 GB it continues in `16450.1`, `16450.2`, and so on.
- `16450_fsm` is the **free space map**: roughly how much free space each page has, so inserts can find room quickly.
- `16450_vm` is the **visibility map**: two bits per page, "all visible" and "all frozen".

You'll never touch these files directly. Copying them while the server runs doesn't give you a usable backup (chapter
23 covers backups). But the sizes are useful, and there's a function for each level:

| Function | Measures |
|---|---|
| `pg_relation_size(rel)` | the main fork of one table or index (`pg_relation_size(rel, 'fsm')` and `'vm'` for the others) |
| `pg_table_size(rel)` | the table with its free space map, visibility map and TOAST data, without indexes |
| `pg_indexes_size(rel)` | all indexes of a table |
| `pg_total_relation_size(rel)` | everything: table, TOAST and indexes |
| `pg_database_size(name)` | a whole database |

```sql
SELECT relname,
       pg_size_pretty(pg_relation_size(oid)) AS main,
       pg_size_pretty(pg_relation_size(oid, 'fsm')) AS fsm,
       pg_size_pretty(pg_relation_size(oid, 'vm')) AS vm,
       pg_size_pretty(pg_indexes_size(oid)) AS indexes,
       pg_size_pretty(pg_total_relation_size(oid)) AS total
FROM pg_class
WHERE relname IN ('customers', 'orders', 'order_items', 'reviews')
ORDER BY pg_total_relation_size(oid) DESC;
```

```text
   relname   |  main   |  fsm  |     vm     | indexes |  total
-------------+---------+-------+------------+---------+---------
 order_items | 144 MB  | 56 kB | 8192 bytes | 91 MB   | 234 MB
 orders      | 65 MB   | 40 kB | 8192 bytes | 33 MB   | 99 MB
 customers   | 8584 kB | 24 kB | 8192 bytes | 11 MB   | 19 MB
 reviews     | 3640 kB | 24 kB | 8192 bytes | 3368 kB | 7048 kB
(4 rows)
```

The free space map and visibility map are tiny compared with the data. The indexes are not: for `order_items`, the
two indexes take more than half as much space as the table itself. Every index costs disk space, cache space and
write time, which is why chapter 15 recommended dropping unused ones.

## Pages and tuples

Every table and index file is an array of **pages** (also called blocks) of 8 kB. PostgreSQL always reads and writes
whole pages. A table page looks like this:

```text
 ┌─────────────────────────────────────────────────────────────┐
 │ page header (24 bytes): LSN, checksum, free space pointers  │
 ├─────────────────────────────────────────────────────────────┤
 │ line pointers: (1) (2) (3) (4) ...   → grow downwards       │
 │                                                             │
 │                      free space                             │
 │                                                             │
 │                     ... tuple 4 │ tuple 3 │ tuple 2 │ tuple 1│ ← grow upwards
 └─────────────────────────────────────────────────────────────┘
```

Each **line pointer** is 4 bytes and points to a tuple's position within the page. A row's `ctid`, such as `(0,3)`, is
the page number and the line pointer number. Indexes point to rows by `ctid`, and because the line pointer stays put,
PostgreSQL can move a tuple within its page (to compact free space) without touching any index.

`pageinspect` lets you watch this happen. Make a tiny table, insert three rows, update one and delete one:

```sql
CREATE TABLE notes (id integer, body text);
INSERT INTO notes VALUES (1, 'first'), (2, 'second'), (3, 'third');
UPDATE notes SET body = 'second, edited' WHERE id = 2;
DELETE FROM notes WHERE id = 3;

SELECT lp, lp_off, lp_flags, lp_len, t_xmin, t_xmax, t_ctid
FROM heap_page_items(get_raw_page('notes', 0));
```

```text
CREATE TABLE
INSERT 0 3
UPDATE 1
DELETE 1
 lp | lp_off | lp_flags | lp_len | t_xmin | t_xmax | t_ctid
----+--------+----------+--------+--------+--------+--------
  1 |   8152 |        1 |     34 |  18751 |      0 | (0,1)
  2 |   8112 |        1 |     35 |  18751 |  18752 | (0,4)
  3 |   8072 |        1 |     34 |  18751 |  18753 | (0,3)
  4 |   8024 |        1 |     43 |  18752 |      0 | (0,4)
(4 rows)
```

Your transaction ids differ, but the picture is the same. Four tuples are on the page, though the table has two rows:

- `lp` 1 is row 1, untouched (`t_xmax` = 0).
- `lp` 2 is the old version of row 2. Its `t_xmax` is the updating transaction, and its `t_ctid` points to the new
  version, `(0,4)`, so that anyone following an old index entry finds the newest version.
- `lp` 3 is row 3, deleted: `t_xmax` is set, and `t_ctid` points to itself because there is no newer version.
- `lp` 4 is the new version of row 2, created by the updating transaction.
- `lp_off` is the tuple's byte offset in the page: the first tuple sits at the very end of the page, and each new one
  below it. `lp_flags` 1 means "normal".

Now let `VACUUM` clean up the page, and look again:

```sql
VACUUM notes;
SELECT lp, lp_off, lp_flags, lp_len, t_xmin, t_xmax, t_ctid
FROM heap_page_items(get_raw_page('notes', 0));
```

```text
VACUUM
 lp | lp_off | lp_flags | lp_len | t_xmin | t_xmax | t_ctid
----+--------+----------+--------+--------+--------+--------
  1 |   8152 |        1 |     34 |  18751 |      0 | (0,1)
  2 |      4 |        2 |      0 |        |        |
  3 |      0 |        0 |      0 |        |        |
  4 |   8104 |        1 |     43 |  18752 |      0 | (0,4)
(4 rows)
```

The dead tuples are gone. Line pointer 3 is now unused (`lp_flags` 0) and can be recycled for a new tuple. Line
pointer 2 has become a **redirect** (`lp_flags` 2) to line pointer 4: indexes may still point to `(0,2)`, so that slot
must lead to the current version. Row 4 has moved to the end of the page (`lp_off` 8104), closing the gap the dead
tuples left. You'll see why this redirect exists in the section on HOT updates.

### Inside a tuple: headers and alignment

Each tuple has a 23-byte header (`xmin`, `xmax`, `ctid`, flags, and a bitmap of which columns are `NULL`), padded so
that the data starts on an 8-byte boundary. Then come the column values, and each value is aligned according to its
type: a `bigint` or `timestamptz` must start at a multiple of 8 bytes, an `integer` at a multiple of 4. Padding bytes
fill the gaps. So **column order changes the row size**:

```sql
SELECT pg_column_size(ROW(1::smallint, 1::bigint, 1::smallint, 1::bigint)) AS mixed,
       pg_column_size(ROW(1::bigint, 1::bigint, 1::smallint, 1::smallint)) AS big_first;
```

```text
 mixed | big_first
-------+-----------
    56 |        44
(1 row)
```

The same four values take 56 bytes in one order and 44 in the other. In the first, each `smallint` (2 bytes) is
followed by 6 bytes of padding so the next `bigint` can start at a multiple of 8. On a table with a billion rows, 12
bytes per row is 12 GB. When you design a large table, put the fixed-width 8-byte columns (`bigint`, `timestamptz`,
`double precision`) first, then 4-byte ones (`integer`, `date`), then 2- and 1-byte ones (`smallint`, `boolean`), and
variable-length types (`text`, `numeric`, `jsonb`) last. For small tables, readable column order matters more.

Add the 4-byte line pointer, and every row costs at least 28 bytes of overhead before any data. An `orders` row
averages about 64 bytes:

```sql
SELECT round(avg(pg_column_size(o.*))) AS avg_row_bytes,
       round(8192 / (avg(pg_column_size(o.*)) + 4)) AS rows_per_page_estimate,
       (SELECT round(reltuples / relpages) FROM pg_class WHERE relname = 'orders') AS rows_per_page
FROM orders AS o;
```

```text
 avg_row_bytes | rows_per_page_estimate | rows_per_page
---------------+------------------------+---------------
            64 |                    121 |           120
(1 row)
```

## TOAST: large values

A tuple must fit in a page, yet a `text` value can be up to 1 GB. The mechanism that makes this work is **TOAST**
("The Oversized-Attribute Storage Technique"). When a row would be larger than about 2 kB, PostgreSQL first
**compresses** its large values, and if the row is still too big, moves them **out of line** into a separate TOAST
table, in chunks of about 2 kB, leaving an 18-byte pointer in the row. Every table with a column that might need it has
a TOAST table:

```sql
SELECT reltoastrelid::regclass FROM pg_class WHERE relname = 'products';
```

The answer is something like `pg_toast.pg_toast_16412`: a hidden table in the `pg_toast` schema. Give two products
long descriptions, one repetitive and one that looks random (MD5 hashes in hexadecimal):

```sql
UPDATE products SET description = repeat('Smooth, consistent ink flow. ', 400) WHERE id = 11;
UPDATE products
SET description = (SELECT string_agg(md5(i::text), ' ') FROM generate_series(1, 1000) AS i)
WHERE id = 14;

SELECT id, octet_length(description) AS bytes,
       pg_column_size(description) AS stored,
       pg_column_compression(description) AS compression
FROM products
WHERE id IN (1, 11, 14)
ORDER BY id;
```

```text
UPDATE 1
UPDATE 1
 id | bytes | stored | compression
----+-------+--------+-------------
  1 |    91 |     92 |
 11 | 11600 |    175 | pglz
 14 | 32999 |  32999 |
(3 rows)
```

- Product 1's short description is stored as it is: 91 bytes of text plus a 1-byte length header.
- Product 11's repetitive 11,600 bytes compress to almost nothing (`pglz` is the default compression method), and the
  result is small enough to stay in the row.
- Product 14's 33,000 bytes of hexadecimal don't compress usefully, so PostgreSQL stores them uncompressed, out of line
  in the TOAST table, which now has a few pages:

```sql
SELECT pg_size_pretty(pg_relation_size(reltoastrelid)) AS toast_size
FROM pg_class
WHERE relname = 'products';
```

```text
 toast_size
------------
 40 kB
(1 row)
```

**`lz4`** is a second compression method, much faster than `pglz` at a similar ratio. It is available if PostgreSQL
was built with it, which the usual packages are. Set it per column, or for everything with
`default_toast_compression = 'lz4'`. It applies to values written from then on:

```sql
ALTER TABLE products ALTER COLUMN description SET COMPRESSION lz4;
UPDATE products SET description = repeat('Smooth, consistent ink flow. ', 400) WHERE id = 11;
SELECT id, pg_column_size(description) AS stored, pg_column_compression(description) AS compression
FROM products
WHERE id = 11;
```

```text
ALTER TABLE
UPDATE 1
 id | stored | compression
----+--------+-------------
 11 |     93 | lz4
(1 row)
```

Each column also has a **storage strategy** (`ALTER TABLE ... ALTER COLUMN ... SET STORAGE ...`):

| Strategy | Compress? | Move out of line? | Default for |
|---|---|---|---|
| `PLAIN` | no | no | fixed-width types (`integer`, `timestamptz`, ...) |
| `MAIN` | yes | only as a last resort | `numeric` |
| `EXTENDED` | yes | yes | most variable-length types (`text`, `bytea`, `jsonb`, arrays) |
| `EXTERNAL` | no | yes | none; use it for large values you read in pieces with `substring()`, which can then fetch only the chunks it needs |

What this means in practice:

- **Wide values cost only when you read them.** A query that doesn't select `description` never touches the TOAST
  table. That is one more reason to avoid `SELECT *` in application code.
- **Updates that don't change a toasted value are cheap.** The new row version copies the 18-byte pointer, not the
  value.
- **Updates that do change a large value rewrite all of it.** Changing one key in a 1 MB `jsonb` document writes a new
  1 MB value. Keep large, frequently changed documents small, or split them.

## The buffer cache and I/O

PostgreSQL never reads a page from disk straight into a query. Pages go through **shared buffers**, a cache in shared
memory that all sessions use (`shared_buffers`, 128 MB by default; a common starting point on a dedicated server is
25% of the RAM). The operating system has its own file cache behind it, so a "read" in `EXPLAIN (ANALYZE, BUFFERS)`
(chapter 16) may still come from memory, just not PostgreSQL's.

A changed page is marked *dirty* in shared buffers and written back to its file later, by the checkpointer, the
background writer or, when the cache is full, by the session that needs a free buffer. The `pg_statio_user_tables`
view counts cache hits and reads per table:

```sql
SELECT relname, heap_blks_read, heap_blks_hit,
       round(100.0 * heap_blks_hit / nullif(heap_blks_hit + heap_blks_read, 0), 1) AS hit_pct
FROM pg_statio_user_tables
ORDER BY heap_blks_read + heap_blks_hit DESC
LIMIT 5;
```

Your numbers depend on everything this cluster has done. On a production database, the frequently used tables should
show a hit rate in the high nineties; much lower means the working set doesn't fit in memory.

### Asynchronous I/O (PostgreSQL 18+)

Until PostgreSQL 17, a session that needed a page from disk asked the operating system for it and waited. PostgreSQL 18
adds an **asynchronous I/O** subsystem: sequential scans, bitmap heap scans and `VACUUM` issue reads ahead of time, so
several are in flight while the session works on pages that have arrived. The settings:

```sql
SELECT name, setting
FROM pg_settings
WHERE name IN ('io_method', 'io_workers', 'effective_io_concurrency', 'io_combine_limit')
ORDER BY name;
```

```text
           name           | setting
--------------------------+---------
 effective_io_concurrency | 16
 io_combine_limit         | 16
 io_method                | worker
 io_workers               | 3
(4 rows)
```

- `io_method = worker` (the default) hands reads to a pool of `io_workers` background processes. `io_uring` uses
  Linux's io_uring interface instead, if PostgreSQL was built with it, and `sync` gives the old behaviour.
- `effective_io_concurrency`, how many reads to keep in flight, now defaults to 16 (it was 1).
- `io_combine_limit` lets adjacent pages be read in one operation of up to 16 pages (128 kB).

The defaults are good. The gain is largest on cloud storage, where each read has high latency but many can run in
parallel. The `pg_aios` view shows the I/O operations in progress.

## The write-ahead log and checkpoints

If changed pages are written to their files "later", what happens when the server crashes before that? The answer is
the **write-ahead log (WAL)**. Every change is first described in a WAL record, appended to the log in `pg_wal/`.
At `COMMIT`, PostgreSQL writes the WAL up to that point to disk and waits for the disk to confirm (`fsync`) before
telling you the commit succeeded. The data files themselves can be updated whenever convenient. After a crash, the
server replays the WAL from the last checkpoint and arrives exactly where it was.

```text
  UPDATE ──► change page in shared buffers ──► write WAL record
  COMMIT ──► flush WAL to disk (fsync) ──► "COMMIT" returned to the client
  later  ──► checkpointer writes dirty pages to the data files
```

Sequential appends to one log are much faster than random writes all over the data files, which is why this design
is both safe and fast. The WAL is also what replication and point-in-time recovery are built on (chapter 23).

You can measure how much WAL a statement writes by comparing the log position (LSN) before and after:

```sql
SELECT pg_current_wal_lsn() AS before_lsn \gset
UPDATE products SET stock = stock + 1;
SELECT pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), :'before_lsn')) AS wal_written;
```

```text
UPDATE 14
 wal_written
-------------
 1200 bytes
(1 row)
```

The exact number varies a little from run to run. (`EXPLAIN (ANALYZE, WAL)` reports the same for one statement, as WAL
records, full-page images and bytes; exercise 2 uses it.)

A **checkpoint** writes all dirty pages to the data files, so that the WAL before it is no longer needed for crash
recovery and can be recycled. Checkpoints happen every `checkpoint_timeout` (5 minutes by default) or when
`max_wal_size` (1 GB) of WAL has been written since the last one, whichever comes first. They are spread out over most
of the interval (`checkpoint_completion_target = 0.9`) to avoid I/O spikes. Two things to know:

- After a checkpoint, the first change to each page writes the **whole page** into the WAL (`full_page_writes`), to
  protect against a page being half-written during a crash. So frequent checkpoints mean much more WAL. If the server
  log says "checkpoints are occurring too frequently", raise `max_wal_size`.
- A longer interval means less WAL but a longer crash recovery, since more WAL must be replayed.

`pg_stat_checkpointer` (17+) shows how many checkpoints ran because of the timeout (`num_timed`) and how many were
forced by WAL volume (`num_requested`); mostly timed is what you want.

### Durability settings

| Setting | Default | What it trades |
|---|---|---|
| `fsync` | `on` | never turn it off on a database you care about: a crash can then corrupt it beyond repair |
| `synchronous_commit` | `on` | `off` returns from `COMMIT` before the WAL is on disk. A crash can lose the last fraction of a second of commits, but never corrupts anything. Can be set per transaction (`SET LOCAL synchronous_commit = off`) for low-value writes like page-view counters |
| `full_page_writes` | `on` | leave it on (unless your file system guarantees atomic 8 kB writes) |
| `wal_compression` | `off` | `lz4` or `zstd` compress full-page images in the WAL: less WAL for a little CPU |

**Unlogged tables** (`CREATE UNLOGGED TABLE ...`) skip the WAL entirely. Writing to them is much faster, but after a
crash they are emptied, and they aren't replicated. They suit data you can rebuild, such as a staging table for an
import.

### Data checksums

A **data checksum** in each page header lets PostgreSQL detect pages corrupted by faulty storage, instead of silently
returning wrong data. Since PostgreSQL 18, `initdb` enables checksums by default:

```sql
SHOW data_checksums;
```

```text
 data_checksums
----------------
 on
(1 row)
```

A cluster created by an older version may have them off. You can turn them on with the `pg_checksums` tool while the
server is stopped, and since PostgreSQL 18, `pg_upgrade` requires the old and new cluster to agree, so check before
you upgrade.

## Dead tuples and VACUUM

Now back to MVCC. Every `UPDATE` leaves the old row version behind, and every `DELETE` leaves the deleted one. Once no
transaction can see them any more, these **dead tuples** are garbage, taking up space in the table and its indexes.
Reclaiming that space is the job of **`VACUUM`**.

Normally the autovacuum daemon runs `VACUUM` for you. To watch the garbage pile up, switch it off for `orders` for the
rest of this chapter (never do that in production):

```sql
ALTER TABLE orders SET (autovacuum_enabled = off);
SELECT table_len, tuple_count, dead_tuple_count, free_percent FROM pgstattuple('orders');
```

```text
ALTER TABLE
 table_len | tuple_count | dead_tuple_count | free_percent
-----------+-------------+------------------+--------------
  68255744 |     1000012 |                0 |         0.43
(1 row)
```

`pgstattuple` reads the whole table and reports: 68 MB, one million live tuples, no dead ones, and almost no free
space, since bulk-loaded pages are packed full. Now suppose it turns out the courier's clock was an hour slow
throughout 2023, and all delivered orders of that year need their shipping time corrected:

```sql
UPDATE orders
SET shipped_at = shipped_at + interval '1 hour'
WHERE created_at < '2024-01-01' AND status = 'delivered';
SELECT pg_stat_force_next_flush();
```

```text
UPDATE 323195
 pg_stat_force_next_flush
--------------------------

(1 row)
```

(Each session sends its activity counts to the statistics system at most once a second; `pg_stat_force_next_flush()`
makes it happen right away, so the next query sees them.)

```sql
SELECT n_live_tup, n_dead_tup, n_tup_upd, n_tup_hot_upd
FROM pg_stat_user_tables
WHERE relname = 'orders';

SELECT table_len, tuple_count, dead_tuple_count, free_percent FROM pgstattuple('orders');
SELECT pg_size_pretty(pg_relation_size('orders')) AS table_size,
       pg_size_pretty(pg_indexes_size('orders')) AS indexes_size;
```

```text
 n_live_tup | n_dead_tup | n_tup_upd | n_tup_hot_upd
------------+------------+-----------+---------------
    1000012 |     323195 |    323195 |             0
(1 row)

 table_len | tuple_count | dead_tuple_count | free_percent
-----------+-------------+------------------+--------------
  90316800 |     1000012 |           323195 |         0.35
(1 row)

 table_size | indexes_size
------------+--------------
 86 MB      | 43 MB
(1 row)
```

The table still has one million live rows, but also 323,195 dead ones, and it has grown from 65 MB to 86 MB: the new
versions didn't fit in the full pages, so they went to new pages at the end of the table. The indexes grew too
(chapter 15 measured 33 MB), because each new version needed new index entries. Now run `VACUUM`, with `VERBOSE` to
see what it does:

```sql
VACUUM (VERBOSE) orders;
```

```text
INFO:  vacuuming "inkwell.public.orders"
INFO:  launched 1 parallel vacuum worker for index vacuuming (planned: 1)
INFO:  finished vacuuming "inkwell.public.orders": index scans: 1
pages: 0 removed, 11025 remain, 5472 scanned (49.63% of total), 0 eagerly scanned
tuples: 323195 removed, 999978 remain, 0 are dead but not yet removable
removable cutoff: 18761, which was 0 XIDs old when operation ended
frozen: 0 pages from table (0.00% of total) had 0 tuples frozen
visibility map: 5472 pages set all-visible, 58 pages set all-frozen (0 were all-visible)
index scan needed: 2778 pages from table (25.20% of total) had 323195 dead item identifiers removed
index "orders_pkey": pages: 3659 in total, 0 newly deleted, 0 currently deleted, 0 reusable
index "orders_customer_id_idx": pages: 1863 in total, 0 newly deleted, 0 currently deleted, 0 reusable
avg read rate: 120.206 MB/s, avg write rate: 0.197 MB/s
buffer usage: 17471 hits, 1830 reads, 3 dirtied
WAL usage: 14704 records, 1339 full page images, 12230776 bytes, 695 buffers full
system usage: CPU: user: 0.09 s, system: 0.00 s, elapsed: 0.11 s
VACUUM
```

The report, line by line:

- **pages**: no pages were removed from the end of the table; `VACUUM` read only the pages that weren't marked
  all-visible in the visibility map (the rest can't contain garbage).
- **tuples**: 323,195 dead tuples removed, none that it had to leave behind.
- **removable cutoff**: the oldest transaction id still running. Tuples deleted after it might still be visible to
  someone and must be kept. This number is yours alone.
- **visibility map**: pages now containing only live tuples visible to everyone are marked all-visible (and some
  all-frozen, explained later).
- **index scan needed**, and the index lines: dead tuples are removed from every index too, which is often the biggest
  part of the work. A parallel worker helped with the indexes.
- the read/write rates, buffer usage and WAL usage will differ on your machine.

And the table afterwards:

```sql
SELECT table_len, tuple_count, dead_tuple_count, free_percent FROM pgstattuple('orders');
```

```text
 table_len | tuple_count | dead_tuple_count | free_percent
-----------+-------------+------------------+--------------
  90316800 |     1000012 |                0 |        23.63
(1 row)
```

**The dead tuples are gone, but the table is just as big.** `VACUUM` doesn't give space back to the operating system
(except for completely empty pages at the very end of the table). It records the free space in the free space map,
where future inserts and updates will find it. Run the reverse correction, which writes another 323,195 new versions:

```sql
UPDATE orders
SET shipped_at = shipped_at - interval '1 hour'
WHERE created_at < '2024-01-01' AND status = 'delivered';
SELECT pg_size_pretty(pg_relation_size('orders')) AS table_size;
```

```text
UPDATE 323195
 table_size
------------
 86 MB
(1 row)
```

This time the table hardly grew: the new versions went into the space `VACUUM` had freed. That is the normal steady
state of a busy table: some free space inside it, constantly reused, and a size that stays flat. Clean up the new dead
tuples:

```sql
VACUUM orders;
```

```text
VACUUM
```

To actually shrink a table, you need **`VACUUM FULL`**, which writes a compact new copy of the table and its indexes
and deletes the old files:

```sql
VACUUM FULL orders;
SELECT pg_size_pretty(pg_relation_size('orders')) AS table_size,
       pg_size_pretty(pg_indexes_size('orders')) AS indexes_size;
```

```text
VACUUM
 table_size | indexes_size
------------+--------------
 65 MB      | 30 MB
(1 row)
```

The table is back to its original size, and the indexes are even smaller than after the bulk load, because a freshly
built index is packed tightly (more about that under "Bloat" below). But `VACUUM FULL` holds an `ACCESS EXCLUSIVE`
lock for its whole duration (no reads, no writes; see chapter 17), and needs enough free disk space for the new copy.
On a large production table that means downtime. Use it only after a one-off event (a huge delete, say) has left a
table mostly empty, and look at the `pg_repack` extension, which does the same online, if you need it regularly.

Plain `VACUUM` takes only a `SHARE UPDATE EXCLUSIVE` lock, so reads and writes continue while it runs. Its useful
options:

| Option | Effect |
|---|---|
| `VERBOSE` | prints the report above |
| `ANALYZE` | also updates the planner statistics (chapter 16) |
| `FREEZE` | freezes all tuples it can (see "Freezing" below) |
| `PARALLEL n` | uses up to *n* workers for the index phase |
| `INDEX_CLEANUP off` | skips the indexes, for an emergency where only freezing matters |
| `SKIP_LOCKED` | skips tables it can't lock immediately, instead of waiting |

From the shell, the `vacuumdb` program runs `VACUUM` on whole databases, for example `vacuumdb --all --analyze`.

## The visibility map and index-only scans

The **visibility map** keeps two bits per page: *all-visible* (every tuple on the page is visible to every
transaction) and *all-frozen*. `VACUUM` sets the bits; any change to a page clears them. Two things rely on it:

- `VACUUM` skips all-visible pages, which makes vacuuming a mostly unchanged table cheap.
- An **index-only scan** (chapter 15) can skip the visit to the table only for rows on all-visible pages. For other
  rows it must check the table to see whether the version it found is visible.

`VACUUM FULL` writes a new table, and the visibility map starts empty. Look at the summary and at an index-only scan:

```sql
SELECT * FROM pg_visibility_map_summary('orders');

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM orders WHERE customer_id BETWEEN 1000 AND 2000;
```

```text
 all_visible | all_frozen
-------------+------------
           0 |          0
(1 row)

                                       QUERY PLAN
----------------------------------------------------------------------------------------
 Aggregate (actual rows=1.00 loops=1)
   ->  Bitmap Heap Scan on orders (actual rows=41799.00 loops=1)
         Recheck Cond: ((customer_id >= 1000) AND (customer_id <= 2000))
         Heap Blocks: exact=8274
         ->  Bitmap Index Scan on orders_customer_id_idx (actual rows=41799.00 loops=1)
               Index Cond: ((customer_id >= 1000) AND (customer_id <= 2000))
               Index Searches: 1
(7 rows)
```

No page is marked all-visible, so an index-only scan would have to visit the table for every row anyway. The planner
knows this (it reads the visibility map's statistics from `pg_class.relallvisible`) and chose a bitmap scan, which
reads thousands of table pages (`Heap Blocks`). After a `VACUUM`:

```sql
VACUUM orders;
SELECT * FROM pg_visibility_map_summary('orders');

EXPLAIN (ANALYZE, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
SELECT count(*) FROM orders WHERE customer_id BETWEEN 1000 AND 2000;
```

```text
VACUUM
 all_visible | all_frozen
-------------+------------
        8332 |       8332
(1 row)

                                         QUERY PLAN
---------------------------------------------------------------------------------------------
 Aggregate (actual rows=1.00 loops=1)
   ->  Index Only Scan using orders_customer_id_idx on orders (actual rows=41799.00 loops=1)
         Index Cond: ((customer_id >= 1000) AND (customer_id <= 2000))
         Heap Fetches: 0
         Index Searches: 1
(5 rows)
```

Every page is all-visible, so the planner switched to an index-only scan, and it touched the table zero times (`Heap
Fetches: 0`). On tables that change constantly, the
visibility map is always partly out of date, which is one reason index-only scans work best on tables that are
vacuumed often. (`VACUUM FULL` leaving the map empty is one more reason to run a plain `VACUUM` afterwards.)

## HOT updates and fillfactor

Each new row version normally needs a new entry in **every index** of the table, even indexes on columns that didn't
change, because the new version has a new `ctid`. On a table with five indexes, updating one unindexed column writes
six things. PostgreSQL avoids most of that with **heap-only tuple (HOT)** updates. An update is HOT when:

1. no column used by any index changed, and
2. the new version fits on the **same page** as the old one.

Then the indexes keep pointing to the old line pointer, and the old version points to the new one: the chain you saw in
the `notes` page earlier, where line pointer 2 became a redirect to line pointer 4. HOT updates write less, don't grow
the indexes, and their dead versions can be cleaned up by any query that visits the page (*page pruning*), without
waiting for `VACUUM`.

Condition 2 is the catch: bulk-loaded pages are full. Watch it with a correction to the country of one customer in
ten, a column that has no index:

```sql
UPDATE customers SET country = 'NO' WHERE id % 10 = 0;
SELECT pg_stat_force_next_flush();
```

```text
UPDATE 10000
 pg_stat_force_next_flush
--------------------------

(1 row)
```

```sql
SELECT n_tup_upd, n_tup_hot_upd, n_tup_newpage_upd
FROM pg_stat_user_tables
WHERE relname = 'customers';
```

```text
 n_tup_upd | n_tup_hot_upd | n_tup_newpage_upd
-----------+---------------+-------------------
     10000 |            47 |              9953
(1 row)
```

Only 47 of the 10,000 updates were HOT; the others had to put the new version on another page
(`n_tup_newpage_upd`). The **`fillfactor`** storage parameter tells PostgreSQL to leave part of each page empty when
inserting, so that updates have room. It applies to pages written from then on, so rewrite the table to apply it to the
existing rows. Then correct another one customer in ten:

```sql
ALTER TABLE customers SET (fillfactor = 80);
VACUUM FULL customers;
UPDATE customers SET country = 'NO' WHERE id % 10 = 1;
SELECT pg_stat_force_next_flush();
```

```text
ALTER TABLE
VACUUM
UPDATE 10001
 pg_stat_force_next_flush
--------------------------

(1 row)
```

```sql
SELECT n_tup_upd, n_tup_hot_upd, n_tup_newpage_upd
FROM pg_stat_user_tables
WHERE relname = 'customers';
```

```text
 n_tup_upd | n_tup_hot_upd | n_tup_newpage_upd
-----------+---------------+-------------------
     20001 |         10048 |              9953
(1 row)
```

Every update of the second batch was HOT (the counters are cumulative: 10,048 = 47 + 10,001). A `fillfactor` of 70–90 is
worth it on tables with many updates of unindexed columns (stock levels, statuses, counters, "last seen" timestamps),
at the price of a table that is 10–30% larger. For insert-only tables, leave it at the default 100. And remember
condition 1: an index on a column you update often (`updated_at`, say) makes every one of those updates non-HOT, so
weigh that index's benefit against its write cost.

## What stops VACUUM

`VACUUM` can only remove a dead tuple when **no transaction can still see it**. The oldest snapshot still in use
anywhere in the cluster sets a limit, the *xmin horizon*, and every dead tuple newer than that must stay. One forgotten
transaction holds back cleanup for every table in the database. Session B opens a repeatable read transaction and
leaves it open, like a long report or an `idle in transaction` application connection would:

```sql
-- Session B
SET application_name = 'session B';
BEGIN ISOLATION LEVEL REPEATABLE READ;
SELECT count(*) FROM products;
```

```text
SET
BEGIN
 count
-------
    14
(1 row)
```

Session A corrects the 235 orders of customer 50, and vacuums. (`orders` has no TOAST table, which keeps the report
short.)

```sql
-- Session A
UPDATE orders SET shipped_at = shipped_at WHERE customer_id = 50;
VACUUM (VERBOSE) orders;
```

```text
UPDATE 235
INFO:  vacuuming "inkwell.public.orders"
INFO:  launched 1 parallel vacuum worker for index cleanup (planned: 1)
INFO:  finished vacuuming "inkwell.public.orders": index scans: 0
pages: 0 removed, 8334 remain, 2078 scanned (24.93% of total), 0 eagerly scanned
tuples: 0 removed, 1000182 remain, 235 are dead but not yet removable
removable cutoff: 18931, which was 1 XIDs old when operation ended
new relfrozenxid: 18931, which is 4 XIDs ahead of previous value
frozen: 0 pages from table (0.00% of total) had 0 tuples frozen
visibility map: 0 pages set all-visible, 0 pages set all-frozen (0 were all-visible)
index scan not needed: 0 pages from table (0.00% of total) had 0 dead item identifiers removed
avg read rate: 0.000 MB/s, avg write rate: 0.000 MB/s
buffer usage: 4182 hits, 0 reads, 0 dirtied
WAL usage: 1 records, 0 full page images, 307 bytes, 0 buffers full
system usage: CPU: user: 0.00 s, system: 0.00 s, elapsed: 0.01 s
VACUUM
```

"235 are dead but not yet removable": session B's snapshot might still need the old versions. `pg_stat_activity` shows
which session holds the oldest snapshot, in the `backend_xmin` column; `age()` says how many transactions ago that was:

```sql
-- Session A
SELECT application_name, state, age(backend_xmin) AS xmin_age,
       now() - xact_start > interval '1 second' AS open_over_1s
FROM pg_stat_activity
WHERE backend_xmin IS NOT NULL AND pid <> pg_backend_pid()
ORDER BY age(backend_xmin) DESC;
```

```text
 application_name |        state        | xmin_age | open_over_1s
------------------+---------------------+----------+--------------
 session B        | idle in transaction |        1 | f
(1 row)
```

On a real system, `now() - xact_start` tells you how long the transaction has been open, and anything over a few
minutes deserves a look. Once session B finishes, `VACUUM` can do its work:

```sql
-- Session B
COMMIT;
```

```text
COMMIT
```

```sql
-- Session A
VACUUM (VERBOSE) orders;
```

```text
INFO:  vacuuming "inkwell.public.orders"
INFO:  launched 1 parallel vacuum worker for index vacuuming (planned: 1)
INFO:  finished vacuuming "inkwell.public.orders": index scans: 1
pages: 0 removed, 8334 remain, 2078 scanned (24.93% of total), 0 eagerly scanned
tuples: 235 removed, 999718 remain, 0 are dead but not yet removable
removable cutoff: 18932, which was 0 XIDs old when operation ended
frozen: 0 pages from table (0.00% of total) had 0 tuples frozen
visibility map: 236 pages set all-visible, 232 pages set all-frozen (0 were all-visible)
index scan needed: 232 pages from table (2.78% of total) had 234 dead item identifiers removed
index "orders_pkey": pages: 2745 in total, 0 newly deleted, 0 currently deleted, 0 reusable
index "orders_customer_id_idx": pages: 1135 in total, 0 newly deleted, 0 currently deleted, 0 reusable
avg read rate: 643.943 MB/s, avg write rate: 0.000 MB/s
buffer usage: 4718 hits, 3606 reads, 0 dirtied
WAL usage: 923 records, 0 full page images, 51555 bytes, 0 buffers full
system usage: CPU: user: 0.03 s, system: 0.00 s, elapsed: 0.04 s
VACUUM
```

The usual suspects for a stuck horizon:

- **Long-running transactions and queries**, including reports and `pg_dump` runs.
- **Sessions idle in transaction**: an application that opened a transaction and never finished it. Set
  `idle_in_transaction_session_timeout` (chapter 17).
- **Replication slots** that nobody consumes any more (chapter 23): check `pg_replication_slots` for inactive slots
  with an old `xmin` or `catalog_xmin`.
- **Prepared transactions** that were never committed: check `pg_prepared_xacts`.
- **Standby servers with `hot_standby_feedback = on`** running long queries.

## Autovacuum

The **autovacuum launcher** wakes up every `autovacuum_naptime` (1 minute), and starts workers (at most
`autovacuum_max_workers`, 3 by default) to `VACUUM` and `ANALYZE` tables that need it. A table needs a vacuum when its
number of dead tuples exceeds:

```text
autovacuum_vacuum_threshold + autovacuum_vacuum_scale_factor × number of rows
           50               +              0.2                × reltuples
```

capped at `autovacuum_vacuum_max_threshold` (100 million, PostgreSQL 18+). Similar formulas trigger a vacuum after
enough *inserts* (`autovacuum_vacuum_insert_threshold` 1000 + `autovacuum_vacuum_insert_scale_factor` 0.2, so that
insert-only tables get their visibility map set and are frozen gradually) and an `ANALYZE` after enough changes
(`autovacuum_analyze_threshold` 50 + `autovacuum_analyze_scale_factor` 0.1).

For a table of a million rows, that's 200,050 dead tuples before the first vacuum: 20% garbage is allowed. For big
tables with steady updates, that is too lax, and when autovacuum finally runs it has a lot to do. Every setting can be
overridden per table:

```sql
ALTER TABLE orders SET (autovacuum_vacuum_scale_factor = 0.02, autovacuum_analyze_scale_factor = 0.02);
SELECT reloptions FROM pg_class WHERE relname = 'orders';
```

```text
ALTER TABLE
                                            reloptions
---------------------------------------------------------------------------------------------------
 {autovacuum_enabled=off,autovacuum_vacuum_scale_factor=0.02,autovacuum_analyze_scale_factor=0.02}
(1 row)
```

Autovacuum works slowly on purpose, so that it doesn't starve the queries: it accumulates a cost for each page it
reads and dirties, and sleeps for `autovacuum_vacuum_cost_delay` (2 ms) whenever the cost reaches the limit (200 by
default). On modern SSDs, that throttle is often too strict for large, busy tables; raise
`autovacuum_vacuum_cost_limit` (to 1000 or more) before you start fighting bloat by other means. PostgreSQL 18 also
reserves `autovacuum_worker_slots` (16) at startup, so `autovacuum_max_workers` can now be raised up to that number
with a reload instead of a restart.

To see what autovacuum has been doing, `pg_stat_user_tables` has the counters and timestamps (the `total_*_time`
columns are new in 18), and `pg_stat_progress_vacuum` shows vacuums in progress:

```sql
SELECT relname, n_dead_tup, last_autovacuum, last_autoanalyze, autovacuum_count,
       total_autovacuum_time
FROM pg_stat_user_tables
ORDER BY n_dead_tup DESC
LIMIT 5;
```

The values depend on when autovacuum last ran on your machine, so no output is printed here. Turn autovacuum back on
for `orders`, and keep the lower thresholds:

```sql
ALTER TABLE orders RESET (autovacuum_enabled);
SELECT reloptions FROM pg_class WHERE relname = 'orders';
```

```text
ALTER TABLE
                                 reloptions
----------------------------------------------------------------------------
 {autovacuum_vacuum_scale_factor=0.02,autovacuum_analyze_scale_factor=0.02}
(1 row)
```

## Freezing and transaction ID wraparound

Transaction ids are 32-bit numbers, so there are about 4 billion of them, and a busy server can use that many in
months. PostgreSQL compares them on a circle: for any transaction, the 2 billion ids "behind" it are the past and the 2
billion "ahead" are the future.

```text
                       current transaction
                              │
               past  ◄────────┼────────►  future
         (2 billion ids)      │      (2 billion ids)
```

A row created by a transaction 3 billion ids ago would suddenly look as if it were created in the future, and vanish.
To prevent that, `VACUUM` **freezes** old tuples: it marks them as "visible to everyone, forever", after which their
`xmin` no longer matters. A page whose tuples are all frozen is marked *all-frozen* in the visibility map and never
needs freezing again.

`order_items` hasn't changed since it was loaded, so all its pages are all-visible, but only a few are all-frozen.
`VACUUM (FREEZE)` freezes everything it can:

```sql
SELECT * FROM pg_visibility_map_summary('order_items');
VACUUM (FREEZE) order_items;
SELECT * FROM pg_visibility_map_summary('order_items');
```

```text
 all_visible | all_frozen
-------------+------------
       18374 |        252
(1 row)

VACUUM
 all_visible | all_frozen
-------------+------------
       18374 |      18374
(1 row)
```

Normally you don't run `VACUUM (FREEZE)`; vacuums freeze tuples gradually as they get old enough
(`vacuum_freeze_min_age`, 50 million transactions). Each table records in `pg_class.relfrozenxid` the oldest
transaction id that might still be unfrozen in it, and the database-wide minimum is in `pg_database.datfrozenxid`.
Their **age** is what to watch:

```sql
SELECT datname, age(datfrozenxid) AS xid_age,
       round(100.0 * age(datfrozenxid) / 2000000000, 1) AS pct_towards_limit
FROM pg_database
ORDER BY age(datfrozenxid) DESC;

SELECT relname, age(relfrozenxid) AS xid_age
FROM pg_class
WHERE relkind IN ('r', 'm', 't')
ORDER BY age(relfrozenxid) DESC
LIMIT 5;
```

On a new installation the ages are small. Here is what happens as a table's age grows:

| Age reaches | What happens |
|---|---|
| `vacuum_freeze_table_age` (150 million) | the next vacuum scans the whole table (not just the pages that aren't all-visible) to freeze everything it can |
| `autovacuum_freeze_max_age` (200 million) | autovacuum starts an **anti-wraparound** vacuum, even if autovacuum is disabled for the table; it isn't cancelled by conflicting lock requests the way a normal autovacuum is |
| `vacuum_failsafe_age` (1.6 billion) | vacuum switches to emergency mode: no cost-based throttling, no index cleanup, just freezing |
| about 2 billion, minus 40 million | warnings in the log: "database ... must be vacuumed within ... transactions" |
| 2 billion, minus 3 million | the server refuses to start new transactions that write ("database is not accepting commands that assign new transaction IDs to avoid wraparound data loss") until someone vacuums |

The last row is a real outage, and it happens to databases where vacuum has been blocked for months (by the suspects
in "What stops VACUUM") or can't keep up. Monitor `age(datfrozenxid)` and alert when it passes, say, 500 million.
PostgreSQL 18 helps by having normal vacuums also freeze some all-visible pages eagerly
(`vacuum_max_eager_freeze_failure_rate`), spreading the work instead of leaving it all to one huge anti-wraparound
vacuum. The same scheme protects **multixact ids**, which record several transactions locking one row (chapter 17's
shared row locks); watch `mxid_age(datminmxid)` as well.

## Bloat in tables and indexes

**Bloat** is space in a table or index that holds no live data: dead tuples not yet vacuumed, plus free space beyond
what normal reuse needs. Some free space is healthy, as you saw. Bloat becomes a problem when a table is several times
larger than its data: scans read more pages, and less of the table fits in the cache. Its causes are the ones in this
chapter: a vacuum blocked by an old snapshot, an autovacuum too throttled to keep up, or a one-off mass update or
delete.

`pgstattuple` measures table bloat exactly, by reading the table. For indexes, `pgstatindex` reports the leaf page
density. The two big updates earlier left the `orders` indexes with many half-empty pages, but `VACUUM FULL` rebuilt
them. Repeat the 2023 correction to see the effect on an index:

```sql
UPDATE orders
SET shipped_at = shipped_at + interval '1 hour'
WHERE created_at < '2024-01-01' AND status = 'delivered';
VACUUM orders;

SELECT pg_size_pretty(pg_relation_size('orders_customer_id_idx')) AS size,
       avg_leaf_density, leaf_fragmentation
FROM pgstatindex('orders_customer_id_idx');
```

```text
UPDATE 323195
VACUUM
 size  | avg_leaf_density | leaf_fragmentation
-------+------------------+--------------------
 18 MB |            58.91 |              49.96
(1 row)
```

The index pages are less than 60% full on average, and the index is twice the size it needs to be. A freshly built
B-tree is about 90% full; **`REINDEX ... CONCURRENTLY`** rebuilds it without blocking writes (chapter 15):

```sql
REINDEX INDEX CONCURRENTLY orders_customer_id_idx;
SELECT pg_size_pretty(pg_relation_size('orders_customer_id_idx')) AS size,
       avg_leaf_density, leaf_fragmentation
FROM pgstatindex('orders_customer_id_idx');
```

```text
REINDEX
  size   | avg_leaf_density | leaf_fragmentation
---------+------------------+--------------------
 9072 kB |            90.42 |                  0
(1 row)
```

Index pages that become completely empty are recycled by `VACUUM`, but partly empty ones aren't merged, so indexes on
tables with heavy updates or deletes benefit from an occasional `REINDEX CONCURRENTLY`. Don't do it on a schedule for
its own sake: measure first. A rough check that needs no extension and no full scan is to compare a table's size with
its live rows; `pgstattuple_approx()` gives a faster estimate than `pgstattuple()` on big tables by skipping all-visible
pages.

The remedies, from mildest to most drastic:

1. **Make vacuum keep up**: fix whatever blocks it, lower the table's scale factor, raise the cost limit. Then the
   free space gets reused and the table stops growing.
2. **Rebuild indexes** with `REINDEX INDEX CONCURRENTLY`.
3. **Rewrite the table online** with the `pg_repack` extension, if it's available on your system.
4. **Rewrite the table with `VACUUM FULL`** (or `CLUSTER`, which also sorts it by an index), during a maintenance
   window.

## The statistics views

You have now met most of the views for watching a running server. In one place:

| View | Shows |
|---|---|
| `pg_stat_activity` | one row per session: state, current query, waits, transaction start (chapter 17) |
| `pg_stat_user_tables` | per table: scans, rows inserted/updated/deleted, HOT updates, dead tuples, vacuum and analyze times |
| `pg_stat_user_indexes` | per index: how often it was used (chapter 15) |
| `pg_statio_user_tables` | per table: cache hits and reads |
| `pg_stat_database` | per database: commits, rollbacks, deadlocks, temporary files, conflicts |
| `pg_stat_io` (16+) | I/O by process type, object and context, in operations and (18+) bytes |
| `pg_stat_wal` | WAL records, full-page images and bytes written |
| `pg_stat_checkpointer` (17+) | timed and requested checkpoints, buffers written |
| `pg_stat_progress_vacuum`, `_analyze`, `_create_index`, ... | progress of long-running maintenance commands |
| `pg_stat_statements` | per query statistics (chapter 16) |

The counters are cumulative since the last reset (`pg_stat_reset()` for the current database). To see a rate, sample
twice and subtract. Monitoring tools (chapter 23) do exactly that.

A quick health check worth keeping around, for example:

```sql
SELECT datname,
       xact_commit, xact_rollback, deadlocks, temp_files,
       pg_size_pretty(temp_bytes) AS temp_size
FROM pg_stat_database
WHERE datname = current_database();
```

A rising `deadlocks` count means lock ordering problems (chapter 17). Many `temp_files` means sorts and hashes spilling
to disk, so `work_mem` may be too small for some queries (chapter 16).

## A maintenance checklist

- **Leave autovacuum on**, everywhere. Lower `autovacuum_vacuum_scale_factor` for large tables that change steadily,
  and raise `autovacuum_vacuum_cost_limit` on fast storage.
- **Run `ANALYZE`** (or `VACUUM ANALYZE`) yourself after bulk loads and big data migrations; don't wait for autovacuum.
- **Prevent long transactions**: set `idle_in_transaction_session_timeout`, watch for old `backend_xmin` values, and
  drop replication slots that are no longer used.
- **Monitor** dead tuples, last autovacuum times, `age(datfrozenxid)`, table and index sizes, and the cache hit rate.
- **Use `fillfactor`** below 100 on tables with frequent updates of unindexed columns, and avoid indexes on columns you
  update constantly.
- **Rebuild bloated indexes** with `REINDEX CONCURRENTLY`; use `VACUUM FULL` only in a maintenance window.
- **Keep data checksums on**, and don't turn off `fsync` or `full_page_writes`.

## Key takeaways

- Tables and indexes are files of 8 kB pages. Each page holds line pointers and tuples; a `ctid` is (page, line
  pointer). Column order affects alignment padding, and so row size.
- Values over about 2 kB are compressed (`pglz`, or the faster `lz4`) and, if still too large, moved to the table's
  TOAST table. They cost nothing for queries that don't read them.
- Changes go to shared buffers and the write-ahead log; `COMMIT` waits for the WAL flush, and checkpoints write the
  data files later. PostgreSQL 18 adds asynchronous I/O and enables data checksums by default.
- Updates and deletes leave dead tuples. `VACUUM` removes them, records free space for reuse, and sets the visibility
  map, but doesn't shrink the table; `VACUUM FULL` does, with an exclusive lock.
- HOT updates (no indexed column changed, room on the same page) avoid index writes; `fillfactor` leaves room for them.
- Long transactions, idle-in-transaction sessions, unused replication slots and prepared transactions hold back the
  xmin horizon, so `VACUUM` can't remove anything newer.
- Autovacuum triggers on dead tuples, inserts and changes, scaled by table size; tune it per table.
- `VACUUM` freezes old tuples so that 32-bit transaction ids can wrap around safely. Monitor `age(datfrozenxid)`.

## Exercises

The exercises continue on the bulk data, in the state the chapter left it.

1. **Column order.** A table for page-view events has the columns `viewed bool`, `product_id bigint`, `ms smallint`,
   `viewed_at timestamptz`, `session_id integer`, `referrer text`, in that order. Use `pg_column_size(ROW(...))` with
   sample values (use `'search'` as the referrer) to find the row size, then reorder the columns to make it smaller.

<details>
<summary>Solution</summary>

```sql
SELECT pg_column_size(ROW(true, 1::bigint, 1::smallint, now(), 1::integer, 'search'::text)) AS as_designed,
       pg_column_size(ROW(1::bigint, now(), 1::integer, 1::smallint, true, 'search'::text)) AS reordered;
```

```text
 as_designed | reordered
-------------+-----------
          67 |        54
(1 row)
```

As designed, the `boolean` is followed by 7 bytes of padding so that the `bigint` starts at a multiple of 8, and the
`smallint` by 6 bytes before the `timestamptz`. Reordered from widest to narrowest, the fixed-width columns need no
padding at all (8 + 8 + 4 + 2 + 1 bytes), and the short text value at the end needs no alignment. The 13 bytes saved
are about 20% of the tuple, so the same events need about 20% fewer pages.

</details>

2. **HOT and WAL.** Compare the WAL written by updating one customer in ten, once changing `country` (no index) and
   once changing `email` (which has a unique index). Use rows that haven't been updated in this chapter (`id % 10 =
   2` and `id % 10 = 3`), and `EXPLAIN (ANALYZE, WAL)` to count the WAL records. Explain the difference.

<details>
<summary>Solution</summary>

```sql
EXPLAIN (ANALYZE, WAL, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
UPDATE customers SET country = 'NO' WHERE id % 10 = 2;

EXPLAIN (ANALYZE, WAL, COSTS OFF, TIMING OFF, SUMMARY OFF, BUFFERS OFF)
UPDATE customers SET email = 'x' || email WHERE id % 10 = 3;
```

```text
                            QUERY PLAN
-------------------------------------------------------------------
 Update on customers (actual rows=0.00 loops=1)
   WAL: records=11412 fpi=1211 bytes=8863220 buffers full=575
   ->  Seq Scan on customers (actual rows=10001.00 loops=1)
         Filter: ((id % '10'::bigint) = 2)
         Rows Removed by Filter: 90007
         WAL: records=1411 fpi=1211 bytes=8143683 buffers full=530
(6 rows)

                         QUERY PLAN
-------------------------------------------------------------
 Update on customers (actual rows=0.00 loops=1)
   WAL: records=31316 fpi=276 bytes=5170005 buffers full=126
   ->  Seq Scan on customers (actual rows=10001.00 loops=1)
         Filter: ((id % '10'::bigint) = 3)
         Rows Removed by Filter: 90007
         WAL: records=1211 bytes=105398 buffers full=2
(6 rows)
```

Count the records. The `country` updates are HOT (the table has `fillfactor` 80 now): one record per row, 10,001 in
all, plus the scan's own records. The `email` updates change an indexed column, so they can't be HOT: each needs a new
tuple *and* a new entry in both indexes (`customers_pkey` and `customers_email_key`), three records per row, 30,003
in all, plus the scan's. The scan's records (the `WAL:` line under `Seq Scan`) come from *page pruning*: while
reading the pages, it cleaned up the dead tuples left by the chapter's earlier updates.

The `fpi` (full-page images) and `bytes` figures are another story: they depend on when the last checkpoint happened,
because the first change to each page after a checkpoint logs the whole 8 kB page. Here the first statement happened
to pay for the full-page images of most of the table's pages, which is why it wrote more bytes despite writing a third
as many records. Your figures will differ, so compare records, or measure each statement right after a `CHECKPOINT`.

</details>

3. **When will autovacuum run?** Write a query that shows, for each table in the `public` schema, its number of dead
   tuples and the number at which autovacuum will vacuum it, taking the table's own `autovacuum_vacuum_scale_factor`
   into account if it has one.

<details>
<summary>Solution</summary>

```sql
SELECT c.relname,
       s.n_dead_tup,
       round(current_setting('autovacuum_vacuum_threshold')::numeric
             + coalesce((SELECT split_part(o, '=', 2)::numeric
                         FROM unnest(c.reloptions) AS o
                         WHERE o LIKE 'autovacuum_vacuum_scale_factor=%'),
                        current_setting('autovacuum_vacuum_scale_factor')::numeric)
               * greatest(c.reltuples, 0)) AS vacuum_at
FROM pg_class AS c
JOIN pg_stat_user_tables AS s ON s.relid = c.oid
WHERE c.relnamespace = 'public'::regnamespace
ORDER BY c.reltuples DESC;
```

```text
   relname   | n_dead_tup | vacuum_at
-------------+------------+-----------
 order_items |          0 |    499812
 orders      |          0 |     20044
 customers   |      20001 |     20052
 reviews     |          0 |      9241
 products    |         17 |        53
 categories  |          0 |        52
 notes       |          2 |        50
(7 rows)
```

`orders` triggers at 2% of its rows because of the per-table setting from this chapter; the others use the default
20%. Your dead tuple counts depend on whether autovacuum has already visited the tables since the chapter's updates.
(`reltuples` is -1 for a table that has never been vacuumed or analyzed, hence `greatest(..., 0)`.) The same idea
extends to the insert and analyze thresholds; monitoring tools compute all three.

</details>

4. **Delete, vacuum, reuse.** Delete all reviews with a rating of 1 or 2. Show that `VACUUM` doesn't shrink the
   `reviews` table, that new rows reuse the space, and that `VACUUM FULL` would shrink it.

<details>
<summary>Solution</summary>

```sql
SELECT pg_size_pretty(pg_relation_size('reviews')) AS before;
DELETE FROM reviews WHERE rating <= 2;
VACUUM reviews;
SELECT pg_size_pretty(pg_relation_size('reviews')) AS after_vacuum,
       (SELECT free_percent FROM pgstattuple('reviews')) AS free_percent;
```

```text
 before
---------
 3640 kB
(1 row)

DELETE 5699
VACUUM
 after_vacuum | free_percent
--------------+--------------
 3640 kB      |        12.68
(1 row)
```

About an eighth of the table is now free space, but the file is just as big. Insert the deleted reviews back (with a
fresh `id`, from the same rows of the same orders as `bulk.sql`):

```sql
INSERT INTO reviews (product_id, customer_id, rating, body, created_at)
SELECT DISTINCT ON (oi.product_id, o.customer_id)
       oi.product_id, o.customer_id,
       (ARRAY[5, 5, 5, 4, 4, 4, 3, 3, 2, 1])[1 + (o.id + oi.product_id) % 10],
       'Re-added.', o.shipped_at + interval '3 days'
FROM orders AS o
JOIN order_items AS oi ON oi.order_id = o.id
WHERE o.status = 'delivered' AND o.id > 12 AND o.id % 50 = 0
  AND (ARRAY[5, 5, 5, 4, 4, 4, 3, 3, 2, 1])[1 + (o.id + oi.product_id) % 10] <= 2
ORDER BY oi.product_id, o.customer_id, o.id
ON CONFLICT (product_id, customer_id) DO NOTHING;
SELECT pg_size_pretty(pg_relation_size('reviews')) AS after_insert;
```

```text
INSERT 0 5698
 after_insert
--------------
 3640 kB
(1 row)
```

The table didn't grow: the inserts filled the free space. (5,698 rows, not 5,699: one of the deleted reviews came from
the small sample data, not from `bulk.sql`.) Finally, delete them again and compare plain and full vacuum:

```sql
DELETE FROM reviews WHERE rating <= 2;
VACUUM FULL reviews;
SELECT pg_size_pretty(pg_relation_size('reviews')) AS after_vacuum_full;
```

```text
DELETE 5698
VACUUM
 after_vacuum_full
-------------------
 3192 kB
(1 row)
```

</details>

5. **TOAST in numbers.** Using `pg_column_size()` and `octet_length()`, list the products whose description is stored
   compressed or out of line, with the compression ratio. Then find how many TOAST chunks the out-of-line value of
   product 14 uses, by querying the TOAST table (its name comes from `pg_class.reltoastrelid`).

<details>
<summary>Solution</summary>

```sql
SELECT id, octet_length(description) AS bytes, pg_column_size(description) AS stored,
       round(pg_column_size(description)::numeric / octet_length(description), 3) AS ratio,
       pg_column_compression(description) AS compression
FROM products
WHERE pg_column_size(description) <> octet_length(description) + 1
   OR octet_length(description) > 2000
ORDER BY id;
```

```text
 id | bytes | stored | ratio | compression
----+-------+--------+-------+-------------
 11 | 11600 |     93 | 0.008 | lz4
 14 | 32999 |  32999 | 1.000 |
(2 rows)
```

A short text value is stored with a 1-byte length header, so `stored = bytes + 1` means "plain, in the row". Product
11 is compressed to well under 1% of its size; product 14 is uncompressed but large. The TOAST table's name is
different on every system, so build the query with `format()` and run it with psql's `\gexec`:

```sql
SELECT format('SELECT chunk_id, count(*) AS chunks, sum(octet_length(chunk_data)) AS bytes
               FROM %s GROUP BY chunk_id', reltoastrelid::regclass)
FROM pg_class
WHERE relname = 'products' \gexec
```

```text
 chunk_id | chunks | bytes
----------+--------+-------
    63737 |     17 | 32999
(1 row)
```

Only one value is stored out of line, in 17 chunks of up to 1,996 bytes; product 11's compressed value is small enough
to live in the row itself. Your `chunk_id` will differ.

</details>

6. **Find the horizon holder.** Write one query that reports everything that can hold back the xmin horizon: sessions
   with a `backend_xmin`, replication slots, and prepared transactions, each with its age in transactions. Test it
   while a second session holds a repeatable read snapshot.

<details>
<summary>Solution</summary>

```sql
-- Session B
BEGIN ISOLATION LEVEL REPEATABLE READ;
SELECT 1 AS holding_a_snapshot;
```

```text
BEGIN
 holding_a_snapshot
--------------------
                  1
(1 row)
```

```sql
-- Session A
SELECT 'session' AS kind, application_name AS name, age(backend_xmin) AS xmin_age
FROM pg_stat_activity
WHERE backend_xmin IS NOT NULL AND pid <> pg_backend_pid()
UNION ALL
SELECT 'replication slot', slot_name, greatest(age(xmin), age(catalog_xmin))
FROM pg_replication_slots
UNION ALL
SELECT 'prepared transaction', gid, age(transaction)
FROM pg_prepared_xacts
ORDER BY xmin_age DESC NULLS LAST;
```

```text
  kind   |   name    | xmin_age
---------+-----------+----------
 session | session B |        0
(1 row)
```

Only session B shows up here; the `xmin_age` depends on how many transactions ran since it took its snapshot. In
production, alert when the largest age passes a few million, or when a session's `xact_start` is more than, say, an
hour old. End the transaction in session B:

```sql
-- Session B
COMMIT;
```

```text
COMMIT
```

</details>

---
← Previous: [Transactions and concurrency](17-transactions-and-concurrency.md) | Next: [Functions, procedures and triggers](19-functions-procedures-and-triggers.md) →
