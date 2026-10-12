# Chapter 2 — psql and the command-line tools

## What you'll learn

- How clients find and authenticate to a server: options, connection URIs, environment variables, `.pgpass` and
  service files
- How to explore a database with `psql`'s describe commands (`\d` and friends)
- How to control output: expanded mode, `NULL` display, CSV, borders, and output for scripts
- How to edit, repeat and time queries, and how `\gset`, `\gexec`, `\bind` and `\watch` work
- `psql` variables, and how to write SQL scripts that stop on the first error
- Importing and exporting CSV with `\copy`, and how it differs from the SQL `COPY` statement
- How to make `psql` comfortable with a `.psqlrc`
- The other command-line tools that come with PostgreSQL

`psql` is the one client you can count on: it is installed with every PostgreSQL server, it works over SSH on a
production machine, and it can script anything. Time spent learning it pays off for as long as you use PostgreSQL.

## Setting up for this chapter

If you did the exercises in chapter 1, your `products` table has different rows than the ones shown here. Connect to
`inkwell` (`psql inkwell`) and run this to get the same starting point:

```sql
DROP TABLE IF EXISTS products;

CREATE TABLE products (
    id    integer PRIMARY KEY,
    name  text NOT NULL,
    price numeric(10, 2) NOT NULL
);

INSERT INTO products (id, name, price)
VALUES (1, 'Kestrel Fountain Pen', 48.00),
       (2, 'Dot Grid Notebook A5', 14.00),
       (3, 'Midnight Blue Ink 50ml', 16.00),
       (4, 'Graphite Pencil HB', 1.20);
```

`DROP TABLE IF EXISTS` removes the table if it exists and does nothing (apart from a notice) if it doesn't. You'll see
the `IF EXISTS` / `IF NOT EXISTS` pattern on many statements; it makes scripts safe to run twice.

## Connecting

Every client, whether `psql`, `pg_dump` or the drivers used by your applications, is built on (or imitates) **libpq**,
PostgreSQL's C client library. So they all understand the same connection settings, in the same three ways.

### Command-line options

```bash
psql -h db.example.com -p 5432 -U inkwell_app -d inkwell
```

| Option | Meaning | Default |
|---|---|---|
| `-h` | host name, IP address, or a directory containing a Unix socket | the local Unix socket (on Windows: `localhost`) |
| `-p` | port | `5432` |
| `-U` | role name | your operating-system user name |
| `-d` | database name | the same as the role name |

When `-h` is left out on Linux and macOS, `psql` connects through a **Unix-domain socket**, a special file such as
`/var/run/postgresql/.s.PGSQL.5432` or `/tmp/.s.PGSQL.5432`. Socket connections are faster than TCP and use different
authentication rules (chapter 21). `-h localhost` forces TCP, which matters when the server only accepts passwords
over TCP, or when the server runs in a container.

### Connection strings

Instead of options you can pass one **connection string**, either as a URI or as `key=value` pairs:

```bash
psql "postgresql://inkwell_app@db.example.com:5432/inkwell?sslmode=require&application_name=report"
psql "host=db.example.com port=5432 user=inkwell_app dbname=inkwell sslmode=require"
```

URIs are what most applications, hosting providers and environment variables (`DATABASE_URL`) use. Special characters
in a password inside a URI must be percent-encoded (`@` becomes `%40`), a common source of "authentication failed"
errors. Useful parameters:

| Parameter | Purpose |
|---|---|
| `sslmode` | `disable`, `prefer` (the default: TLS if the server offers it), `require`, `verify-ca`, `verify-full`. Use `verify-full` across untrusted networks (chapter 21) |
| `application_name` | a label that shows up in `pg_stat_activity` and the server log; set it in every application |
| `connect_timeout` | seconds to wait for a connection |
| `options` | server settings for the session, e.g. `options=-c%20statement_timeout%3D5s` |
| `target_session_attrs` | with several hosts listed, pick e.g. `read-write` (the primary) |

### Environment variables

libpq reads environment variables when an option is not given: `PGHOST`, `PGPORT`, `PGUSER`, `PGDATABASE`,
`PGAPPNAME`, `PGSSLMODE`, `PGCONNECT_TIMEOUT`, and others. They are handy for a shell session against one server:

```bash
export PGHOST=localhost PGPORT=5432 PGUSER=postgres PGDATABASE=inkwell
psql                      # uses all of the above
pg_dump > inkwell.sql     # so does every other tool
```

There is also `PGPASSWORD`, but avoid it outside throwaway setups: on some systems other users can read a process's
environment, and it ends up in shell history. Use a password file instead.

### Passwords: the `.pgpass` file

When the server asks for a password, libpq looks in `~/.pgpass` (on Windows `%APPDATA%\postgresql\pgpass.conf`) for a
matching line of the form `hostname:port:database:username:password`. A `*` matches anything:

```text
localhost:5432:inkwell:inkwell_app:s3cret-dev-password
db.example.com:5432:*:inkwell_app:another-password
```

The file must not be readable by other users, or libpq ignores it:

```bash
chmod 600 ~/.pgpass
```

### Named services: `pg_service.conf`

For connections you use often, put all the settings under a name in `~/.pg_service.conf`:

```ini
[inkwell-prod]
host=db.example.com
port=5432
dbname=inkwell
user=readonly
sslmode=verify-full
```

```bash
psql service=inkwell-prod
```

Passwords still come from `.pgpass`. Service files keep host names and settings out of scripts.

### Where am I connected?

`\conninfo` shows the details of the current connection. Since PostgreSQL 18 it prints a table:

```sql
\conninfo
```

```text
          Connection Information
      Parameter       |        Value
----------------------+---------------------
 Database             | inkwell
 Client User          | postgres
 Socket Directory     | /var/run/postgresql
 Server Port          | 5432
 Options              |
 Protocol Version     | 3.0
 Password Used        | false
 GSSAPI Authenticated | false
 Backend PID          | 48213
 SSL Connection       | false
 Superuser            | on
 Hot Standby          | off
(12 rows)
```

Older versions print one sentence: `You are connected to database "inkwell" as user "postgres" via socket in
"/var/run/postgresql" at port "5432".` Either way, check it before you run anything destructive in a terminal with
several connections open.

## Exploring a database: the describe commands

The `\d` family lists and describes objects. Each has a `+` variant that shows more detail and accepts a name
**pattern** (`*` and `?` are wildcards).

| Command | Lists |
|---|---|
| `\l` | databases |
| `\dn` | schemas |
| `\dt` | tables (`\dt *.*` includes system schemas) |
| `\dv`, `\dm` | views, materialized views |
| `\di` | indexes |
| `\ds` | sequences |
| `\df` | functions (`\df+` shows their source) |
| `\dT` | data types |
| `\du` or `\dg` | roles |
| `\dp` | access privileges |
| `\dx` | installed extensions |
| `\d name` | one object in detail: columns, indexes, constraints, triggers, references |

```sql
\dt
```

```text
            List of tables
 Schema |   Name   | Type  |  Owner
--------+----------+-------+----------
 public | products | table | postgres
(1 row)
```

The `+` variants add sizes, descriptions and storage details:

```sql
\d+ products
```

```text
                                            Table "public.products"
 Column |     Type      | Collation | Nullable | Default | Storage  | Compression | Stats target | Description
--------+---------------+-----------+----------+---------+----------+-------------+--------------+-------------
 id     | integer       |           | not null |         | plain    |             |              |
 name   | text          |           | not null |         | extended |             |              |
 price  | numeric(10,2) |           | not null |         | main     |             |              |
Indexes:
    "products_pkey" PRIMARY KEY, btree (id)
Not-null constraints:
    "products_id_not_null" NOT NULL "id"
    "products_name_not_null" NOT NULL "name"
    "products_price_not_null" NOT NULL "price"
Access method: heap

```

The "Not-null constraints" section is new in PostgreSQL 18, which stores `NOT NULL` constraints with names like other
constraints (chapter 4). "Storage" and "Access method" are about how rows are stored on disk (chapter 18).

Patterns let you search, which is the fastest way to find a function whose name you half remember:

```sql
\df *backend_pid
```

```text
                                  List of functions
   Schema   |          Name           | Result data type | Argument data types | Type
------------+-------------------------+------------------+---------------------+------
 pg_catalog | pg_backend_pid          | integer          |                     | func
 pg_catalog | pg_stat_get_backend_pid | integer          | integer             | func
(2 rows)
```

Built-in objects live in the `pg_catalog` schema. Commands such as `\df` and `\dt` hide them unless your pattern names
the schema or you add `S` (`\dfS`).

### How psql does it: the system catalogs

None of this is magic. PostgreSQL stores its own metadata in ordinary tables called the **system catalogs**
(`pg_class` for tables and indexes, `pg_attribute` for columns, `pg_proc` for functions, and so on), and `\d` commands
are just queries against them. Turn on `ECHO_HIDDEN` to see the query behind a command:

```sql
\set ECHO_HIDDEN on
\dn
\set ECHO_HIDDEN off
```

```text
/******** QUERY *********/
SELECT n.nspname AS "Name",
  pg_catalog.pg_get_userbyid(n.nspowner) AS "Owner"
FROM pg_catalog.pg_namespace n
WHERE n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'
ORDER BY 1;
/************************/

      List of schemas
  Name  |       Owner
--------+-------------------
 public | pg_database_owner
(1 row)
```

That is a good way to learn the catalogs and to borrow queries for your own scripts. There is also the
`information_schema`, a set of standard SQL views (`information_schema.tables`, `information_schema.columns`, …) that
work the same way in other databases. It is portable but slower and hides PostgreSQL-specific details; the `pg_catalog`
tables are the complete picture.

### Getting help

- `\?` lists every meta-command, `\? variables` the special variables, `\? options` the command-line options.
- `\h` lists SQL statements; `\h DELETE` shows the syntax of one, with a link to the manual page:

```sql
\h DELETE
```

```text
Command:     DELETE
Description: delete rows of a table
Syntax:
[ WITH [ RECURSIVE ] with_query [, ...] ]
DELETE FROM [ ONLY ] table_name [ * ] [ [ AS ] alias ]
    [ USING from_item [, ...] ]
    [ WHERE condition | WHERE CURRENT OF cursor_name ]
    [ RETURNING [ WITH ( { OLD | NEW } AS output_alias [, ...] ) ]
                { * | output_expression [ [ AS ] output_name ] } [, ...] ]

URL: https://www.postgresql.org/docs/18/sql-delete.html

```

In the syntax, square brackets mark optional parts, braces with `|` a choice, and `[, ...]` "can be repeated".

## Controlling output

### Expanded mode

Wide rows wrap and become unreadable. **Expanded mode** prints each row as a list of column/value pairs:

```sql
\x
SELECT * FROM products WHERE id = 1;
\x
```

```text
Expanded display is on.
-[ RECORD 1 ]---------------
id    | 1
name  | Kestrel Fountain Pen
price | 48.00

Expanded display is off.
```

`\x auto` switches to expanded mode only when a result is too wide for the terminal, which is the best setting for
everyday use. To expand a single query, end it with `\gx` instead of `;`.

PostgreSQL 18 also added an `x` suffix to the list commands: `\dtx`, `\dfx`, `\lx` and so on show their list in
expanded mode.

### Making NULL visible

By default a `NULL` prints as nothing, indistinguishable from an empty string. Make it visible:

```sql
\pset null '(null)'
SELECT NULL AS nothing, '' AS empty_string, 1 AS one;
```

```text
Null display is "(null)".
 nothing | empty_string | one
---------+--------------+-----
 (null)  |              |   1
(1 row)
```

This is one of the most useful settings to put in your `.psqlrc`. The rest of this tutorial shows `NULL` as an empty
cell, like a default `psql`, and points out where it matters. Switch it back for now:

```sql
\pset null ''
```

```text
Null display is "".
```

### Formats, borders and line styles

`\pset format` chooses between `aligned` (the default), `csv`, `unaligned`, `wrapped`, `html`, `asciidoc`, `latex` and
`troff-ms`:

```sql
\pset format csv
SELECT * FROM products ORDER BY id;
\pset format aligned
```

```text
Output format is csv.
id,name,price
1,Kestrel Fountain Pen,48.00
2,Dot Grid Notebook A5,14.00
3,Midnight Blue Ink 50ml,16.00
4,Graphite Pencil HB,1.20
Output format is aligned.
```

`\pset border 2` draws a full frame, and `\pset linestyle unicode` uses box-drawing characters:

```sql
\pset border 2
SELECT * FROM products ORDER BY id;
\pset border 1
```

```text
Border style is 2.
+----+------------------------+-------+
| id |          name          | price |
+----+------------------------+-------+
|  1 | Kestrel Fountain Pen   | 48.00 |
|  2 | Dot Grid Notebook A5   | 14.00 |
|  3 | Midnight Blue Ink 50ml | 16.00 |
|  4 | Graphite Pencil HB     |  1.20 |
+----+------------------------+-------+
(4 rows)

Border style is 1.
```

`\pset` with no arguments lists every setting. `\a` toggles between aligned and unaligned, `\t` toggles "tuples only"
(no header and no row count), and `\H` toggles HTML.

### Output for scripts

When a shell script needs a value from the database, use `-A` (unaligned), `-t` (tuples only) and `-c` (run one
command and exit). Combined as `-At`:

```bash
psql -d inkwell -At -c "SELECT count(*) FROM products"
```

```text
4
```

```bash
psql -d inkwell -At -F ' ' -c "SELECT id, name FROM products ORDER BY id"
```

```text
1 Kestrel Fountain Pen
2 Dot Grid Notebook A5
3 Midnight Blue Ink 50ml
4 Graphite Pencil HB
```

`-F` sets the field separator (the default for unaligned output is `|`). For anything that might contain the separator,
use `--csv` instead, which quotes values properly.

### Sending output to a file

`\o filename` sends the results of all following queries to a file, and `\o` alone switches back to the screen. For a
single query, end it with `\g filename` instead of `;`. Both work well with `\pset format csv` for quick exports, though
`\copy` (below) is the better tool for that.

## Working with the query buffer

`psql` collects what you type into a **query buffer** and sends it when it sees `;` (or `\g`).

| Command | What it does |
|---|---|
| `\e` | open the buffer (or the last query) in your `$EDITOR`; save and quit to run it |
| `\ef name` / `\ev name` | edit a function / view definition in your editor |
| `\p` | print the buffer |
| `\r` | reset (clear) the buffer |
| `\g` | send the buffer, like `;`; with no buffer, re-run the last query |
| `\gx` | like `\g`, in expanded mode |
| `\s` | show the command history |

The arrow keys walk through history, and **Tab completes** keywords, table names, column names and even settings: type
`SELECT * FROM pro` and press Tab. History is kept in `~/.psql_history` across sessions. `Ctrl+R` searches it, as in a
shell.

`\e` deserves a special mention: for anything longer than a couple of lines, writing the query in your editor is far
more pleasant than in the terminal. Set `EDITOR` (or `PSQL_EDITOR`) in your shell profile to your editor of choice.

### Timing queries

```sql
\timing on
SELECT count(*) FROM products;
\timing off
```

```text
Timing is on.
 count
-------
     4
(1 row)

Time: 0.412 ms
Timing is off.
```

The time is measured by `psql` and includes the network round trip. Chapter 16 shows how to see where the time goes
inside the server.

### Re-running a query with `\watch`

`\watch` re-runs the query buffer every few seconds (two by default) until you press `Ctrl+C`. It is perfect for
watching a long operation progress or a queue drain:

```sql
SELECT count(*) FROM pg_stat_activity \watch 5
```

```text
Sun Oct 11 15:40:23 2026 (every 5s)

 count
-------
     7
(1 row)

Sun Oct 11 15:40:28 2026 (every 5s)
...
```

Options can be named: `\watch i=1 c=10` runs ten times at one-second intervals (PostgreSQL 16+), and
`\watch m=1` stops as soon as the query returns fewer than one row (17+).

## psql variables

`psql` has its own variables, separate from anything in the server. `\set` defines one, and `:name` substitutes it
into the query text before it is sent:

```sql
\set min_price 10
SELECT name FROM products WHERE price > :min_price ORDER BY name;
```

```text
          name
------------------------
 Dot Grid Notebook A5
 Kestrel Fountain Pen
 Midnight Blue Ink 50ml
(3 rows)
```

Substitution is plain text replacement, which is dangerous for strings. Use `:'name'` to insert the value as a
properly quoted string literal, and `:"name"` to insert it as a quoted identifier:

```sql
\set product 'Kestrel Fountain Pen'
SELECT id FROM products WHERE name = :'product';
```

```text
 id
----
  1
(1 row)
```

Variables can also be set from the command line with `-v`, which turns a `.sql` file into a parameterized script:

```bash
psql -d inkwell -v min_price=10 -f report.sql
```

### Capturing results with `\gset`

`\gset` runs a query and stores each column of its (single) result row in a variable of the same name:

```sql
SELECT max(price) AS top_price, count(*) AS n FROM products \gset
\echo The most expensive of :n products costs :top_price
```

```text
The most expensive of 4 products costs 48.00
```

`\echo` prints text; it is handy in scripts to show progress.

### Running generated SQL with `\gexec`

`\gexec` runs a query, then executes **each value of the result as a SQL statement**. It is the standard way to apply
the same command to many objects. For example, to collect fresh statistics for every table in the `public` schema:

```sql
SELECT format('ANALYZE %I', tablename)
FROM pg_tables
WHERE schemaname = 'public' \gexec
```

```text
ANALYZE
```

`format()` builds a string, and its `%I` placeholder quotes an identifier safely (chapter 11). There is one table, so
one `ANALYZE` ran.

### Special variables

`psql` sets some variables itself. A few useful ones:

| Variable | Meaning |
|---|---|
| `ROW_COUNT` | rows returned or affected by the last statement |
| `ERROR` | `true` if the last statement failed |
| `SQLSTATE` | the five-character error code of the last statement (`00000` on success) |
| `LAST_ERROR_MESSAGE` | the text of the last error |
| `DBNAME`, `USER`, `HOST`, `PORT` | the current connection |

```sql
SELECT 1 / 0;
\echo :ERROR :SQLSTATE :LAST_ERROR_MESSAGE
```

```text
ERROR:  division by zero
true 22012 division by zero
```

Error codes such as `22012` (division by zero) or `23505` (unique violation) are standard SQLSTATE codes. Applications
use them to react to specific errors (chapter 22).

And some variables change `psql`'s behaviour when you set them:

| Variable | Effect |
|---|---|
| `ON_ERROR_STOP` | stop a script at the first error (see below) |
| `ON_ERROR_ROLLBACK` | inside a transaction, a failed statement doesn't abort the whole transaction (`interactive` = only when typing) |
| `AUTOCOMMIT` | `off` starts a transaction automatically, so nothing is permanent until you `COMMIT` |
| `ECHO` | `queries` prints each statement before running it, useful for script logs |
| `ECHO_HIDDEN` | show the queries behind backslash commands |
| `HISTSIZE`, `HISTFILE` | history length and file |
| `PROMPT1` | the prompt (`%n` user, `%/` database, `%m` host, `%#` `#` or `>`, `%x` transaction status) |

### Server-side parameters with `\bind`

Substituting `psql` variables builds a new SQL string each time. Applications should instead send **parameters**
separately from the query, which makes SQL injection impossible (chapter 22). `\bind` (PostgreSQL 16+) lets you try
that from `psql`: the query uses `$1`, `$2`, … and the values follow `\bind`:

```sql
SELECT name, price FROM products WHERE price < $1 ORDER BY price \bind 10 \g
```

```text
        name        | price
--------------------+-------
 Graphite Pencil HB |  1.20
(1 row)
```

> **Just a taste — you don't need to learn or fully understand this now.** PostgreSQL 18 adds more of the protocol to
> `psql`: named prepared statements and pipelines, mainly useful for testing drivers and poolers.

```sql
SELECT name FROM products WHERE id = $1 \parse product_by_id
\bind_named product_by_id 2 \g
\close_prepared product_by_id
```

## Scripts

Anything you type at the prompt can go in a file. Run a file with `-f` from the shell, or with `\i` from inside
`psql`:

```bash
psql -d inkwell -f setup.sql
```

```sql
\i setup.sql
```

Inside a script, `\ir other.sql` includes another file **relative to the current script's directory** (where `\i`
would look relative to your current working directory). Use `\ir` in scripts that include each other, so they work
from anywhere.

### Stop on the first error

By default, `psql -f` reports an error and **carries on with the next statement**, and still exits with status 0.
Given a file `bad.sql`:

```sql
SELECT 1 / 0;
SELECT 2 AS after_error;
```

```bash
psql -d inkwell -f bad.sql; echo "exit status: $?"
```

```text
psql:bad.sql:1: ERROR:  division by zero
 after_error
-------------
           2
(1 row)

exit status: 0
```

For a deployment or migration script, that is almost never what you want: later statements run against a database in
an unexpected state, and the calling script believes everything went fine. Set `ON_ERROR_STOP`:

```bash
psql -d inkwell -v ON_ERROR_STOP=1 -f bad.sql; echo "exit status: $?"
```

```text
psql:bad.sql:1: ERROR:  division by zero
exit status: 3
```

Exit status 3 means "an error occurred in a script". Add `--single-transaction` (`-1`) to run the whole file as one
transaction, so a failure halfway leaves no half-applied changes behind:

```bash
psql -d inkwell -v ON_ERROR_STOP=1 --single-transaction -f migration.sql
```

That pair of options is the right default for every script that changes things. `psql` exit codes: `0` success, `1`
a fatal `psql` error (such as a missing file), `2` a lost or failed connection, `3` an error in a script with
`ON_ERROR_STOP` set. (A single `-c` command that fails also exits with `1`.)

### Shell heredocs

For short scripts inside a shell script, a heredoc avoids a separate file. Quote the delimiter (`'SQL'`) so the shell
doesn't expand `$` signs in the SQL:

```bash
psql -X -d inkwell -v ON_ERROR_STOP=1 <<'SQL'
\echo Price summary
SELECT count(*) AS products, min(price), max(price) FROM products;
SQL
```

## Importing and exporting data with `\copy`

`COPY` is PostgreSQL's bulk loading and unloading statement, far faster than many `INSERT`s. It comes in two flavours
that are easy to confuse:

| | `COPY` (SQL statement) | `\copy` (psql meta-command) |
|---|---|---|
| Who reads/writes the file | the **server** process, on the server's file system | `psql`, on **your** machine |
| Permissions | superuser or the `pg_read_server_files` / `pg_write_server_files` roles | any user with access to the table |
| Typical use | server-side jobs, loading from a program | everyday imports and exports |

`\copy` takes the same options as `COPY` and streams the data over the connection. Export a query result to CSV with a
header row:

```sql
\copy (SELECT id, name, price FROM products ORDER BY id) TO 'products.csv' WITH (FORMAT csv, HEADER)
```

```text
COPY 4
```

```bash
cat products.csv
```

```text
id,name,price
1,Kestrel Fountain Pen,48.00
2,Dot Grid Notebook A5,14.00
3,Midnight Blue Ink 50ml,16.00
4,Graphite Pencil HB,1.20
```

Import works the other way round. Given `new-products.csv`:

```text
id,name,price
5,Sepia Ink 50ml,16.00
6,Brass Pen Clip,5.00
```

```sql
\copy products (id, name, price) FROM 'new-products.csv' WITH (FORMAT csv, HEADER)
```

```text
COPY 2
```

Things to know:

- `\copy` must be written on **one line**; it is a meta-command, not SQL.
- `COPY` is all-or-nothing: one bad row and nothing is imported. The error message names the line, e.g.
  `CONTEXT:  COPY products, line 3, column price: "abc"`. PostgreSQL 17 added `ON_ERROR ignore` to skip bad rows
  instead, and 18 added `REJECT_LIMIT` to cap how many may be skipped (chapter 5).
- Name the columns (`products (id, name, price)`) so the file's column order doesn't have to match the table's.
- Use `FORMAT csv` for anything that came from a spreadsheet. The default `text` format is PostgreSQL's own
  tab-separated format, which is what `pg_dump` uses.

## A comfortable `.psqlrc`

`psql` runs `~/.psqlrc` at start-up (`%APPDATA%\postgresql\psqlrc.conf` on Windows). A good starting point:

```sql
-- ~/.psqlrc
\set QUIET 1
\pset null '∅'
\x auto
\set ON_ERROR_ROLLBACK interactive
\set COMP_KEYWORD_CASE upper
\set HISTSIZE 10000
\set HISTFILE ~/.psql_history- :DBNAME
\set PROMPT1 '%n@%/%R%x%# '
\timing on
\set QUIET 0
```

- `QUIET` hides the "Null display is …"-style confirmations while the file runs.
- `ON_ERROR_ROLLBACK interactive` means a typo inside a transaction you are typing by hand doesn't throw away the whole
  transaction (chapter 5 explains why it otherwise would). It is applied only in interactive sessions, never to
  scripts.
- `COMP_KEYWORD_CASE upper` makes Tab completion write keywords in upper case.
- A history file per database keeps production commands out of your development history, and vice versa.
- In the prompt, `%x` shows `*` while you are inside a transaction and `!` in a failed one, which prevents a classic
  mistake: forgetting an open transaction that holds locks.

When you run scripts, use `psql -X` to skip `.psqlrc`, so that your personal settings can't change the script's
behaviour or output. Everything in this tutorial's output assumes no `.psqlrc`.

## The other command-line tools

The client package contains more than `psql`. These are thin wrappers around SQL statements or self-contained
utilities, and they all accept the same connection options.

| Tool | What it does | Chapter |
|---|---|---|
| `createdb`, `dropdb` | create / drop a database | 1 |
| `createuser`, `dropuser` | create / drop a role | 21 |
| `pg_isready` | check whether a server accepts connections (exit code 0 if yes), handy in scripts and health checks | 23 |
| `pg_dump`, `pg_dumpall`, `pg_restore` | logical backups and restores | 23 |
| `pg_basebackup` | physical backup of a whole cluster | 23 |
| `vacuumdb`, `reindexdb`, `clusterdb` | run maintenance across databases | 18 |
| `pgbench` | a benchmarking tool | 16 |

```bash
pg_isready -h localhost -p 5432
```

```text
localhost:5432 - accepting connections
```

The server-side tools (`initdb`, `pg_ctl`, `pg_upgrade`, `pg_controldata`, …) are installed with the server and
appear in chapters 18 and 23.

## Key takeaways

- All clients understand the same connection settings: options (`-h -p -U -d`), connection URIs or `key=value`
  strings, and `PG*` environment variables. Keep passwords in `~/.pgpass` (mode 600), not in commands.
- `\d` and its relatives describe everything; add `+` for detail and use patterns to search. They are queries
  against the system catalogs, which `ECHO_HIDDEN` reveals.
- `\x auto`, `\pset null`, and `\timing` make the interactive experience much better; put them in `~/.psqlrc`.
- `psql` variables (`\set`, `:name`, `:'name'`) are text substitution. `\gset` captures results into variables, and
  `\gexec` runs generated SQL.
- Run scripts with `psql -X -v ON_ERROR_STOP=1 --single-transaction -f file.sql`. Without `ON_ERROR_STOP` a script
  keeps going after errors and exits 0.
- `\copy` reads and writes files on the client; `COPY` reads and writes files on the server. Both are much faster than
  `INSERT` for bulk data.

## Exercises

1. **Explore the catalogs.** Use `psql` commands to answer: Which schemas exist in `inkwell` (including the system
   ones)? How many functions named `length` exist in `pg_catalog`, and for which argument types?

<details>
<summary>Solution</summary>

```sql
\dnS
\dfS length
```

`\dnS` lists the system schemas too: `information_schema`, `pg_catalog`, `pg_toast` and `public`. If any session
has used temporary tables, you also see schemas such as `pg_temp_3` and `pg_toast_temp_3`, which hold them. `\dfS length`
shows several functions called `length`, for `text`, `bytea`, `bit`, `character`, `tsvector`, `lseg`, `path` and a
`(bytea, name)` variant that counts characters in an encoded byte string. PostgreSQL supports **function overloading**:
the same name with different argument types are different functions, and the argument types pick which one runs.

</details>

2. **A CSV round trip.** Export all products with a price above 10 to `expensive.csv`, with a header, sorted by price
   descending. Then create a table `products_backup` with the same columns as `products`
   (`CREATE TABLE products_backup (LIKE products);`) and import the file into it. How many rows does it hold?

<details>
<summary>Solution</summary>

```sql
\copy (SELECT * FROM products WHERE price > 10 ORDER BY price DESC) TO 'expensive.csv' WITH (FORMAT csv, HEADER)
CREATE TABLE products_backup (LIKE products);
\copy products_backup FROM 'expensive.csv' WITH (FORMAT csv, HEADER)
SELECT count(*) FROM products_backup;
```

Three rows, assuming the starting data of this chapter (Kestrel at 48.00, the ink at 16.00 and the notebook at 14.00).
`LIKE products` copies the column names, types and `NOT NULL` constraints, but not the primary key or other
constraints unless you ask for them, e.g. `LIKE products INCLUDING ALL`. Drop the table again when you are done:
`DROP TABLE products_backup;`.

</details>

3. **A parameterized script.** Write a file `price-list.sql` that prints all products cheaper than a maximum price
   given on the command line, and stops with a non-zero exit code if anything fails. Run it with a maximum of 15.

<details>
<summary>Solution</summary>

```sql
-- price-list.sql
\echo Products under :max_price
SELECT name, price
FROM products
WHERE price < :'max_price'::numeric
ORDER BY price;
```

```bash
psql -X -d inkwell -v ON_ERROR_STOP=1 -v max_price=15 -f price-list.sql
```

```text
Products under 15
         name         | price
----------------------+-------
 Graphite Pencil HB   |  1.20
 Dot Grid Notebook A5 | 14.00
(2 rows)
```

`:'max_price'` inserts the value as a quoted literal, and `::numeric` converts it to a number. That is safer than
`:max_price`, which pastes whatever the caller passed straight into the SQL text: with `-v "max_price=0 OR true"` the
unquoted version would return every row. If you forget `-v max_price=...`, `psql` doesn't substitute anything, the
literal text `:'max_price'` reaches the server, and the script fails with a syntax error and exit status 3.

</details>

4. **Generate and run statements.** Using `\gexec`, write one query that produces a `SELECT count(*)` statement for
   every table in the `public` schema and runs them all.

<details>
<summary>Solution</summary>

```sql
SELECT format('SELECT %L AS table_name, count(*) FROM %I', tablename, tablename)
FROM pg_tables
WHERE schemaname = 'public'
ORDER BY tablename \gexec
```

Each generated statement prints its own one-row result. `%L` quotes a value as a string literal (so the table name
appears in the output), `%I` quotes it as an identifier (so it is safe even for odd names). Never build SQL by gluing
raw strings together; chapter 11 covers `format()` in detail.

</details>

5. **Watch a table grow.** Open two `psql` sessions. In the first, run
   `SELECT count(*) FROM products \watch 1`. In the second, insert a few rows one at a time (use new ids). What does the
   first session show, and how do you stop it? Clean up afterwards so that the table has its four original rows again.

<details>
<summary>Solution</summary>

The first session reprints the count every second, and the number goes up immediately after each `INSERT` in the
second session commits. Each `\watch` iteration is a new statement, so it sees everything committed before it started
(chapter 17 explains exactly what a statement can see). `Ctrl+C` stops `\watch`. To clean up:

```sql
DELETE FROM products WHERE id > 4;
```

</details>

---
← Previous: [Getting started](01-getting-started.md) | Next: [Data types](03-data-types.md) →
