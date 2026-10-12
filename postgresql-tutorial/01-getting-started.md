# Chapter 1 — Getting started

## What you'll learn

- What PostgreSQL is, what a relational database is, and why PostgreSQL is such a common choice
- How PostgreSQL versions, minor releases and support windows work
- How to install PostgreSQL 18 on macOS, Linux or Windows, or run it in a container
- The vocabulary you need from day one: cluster, data directory, database, schema, role, connection
- How to connect with `psql`, run your first queries and create your first table
- The basic rules of SQL syntax: statements, keywords, identifiers, literals and comments
- What happens inside the server between "you press Enter" and "you see rows"
- The first version of **Inkwell**, the shop database you will grow throughout this tutorial

## What PostgreSQL is

PostgreSQL (say "post-gress-Q-L", or just "Postgres") is a **relational database management system**: a server
program that stores data in tables, lets many clients read and change that data at the same time, and guarantees that
the data stays consistent even when the power fails halfway through a write.

A few facts worth knowing before you start:

- **It is open source and community-run.** PostgreSQL is developed by the PostgreSQL Global Development Group, a
  community of companies and individuals, and released under the PostgreSQL License, a short permissive license
  similar to MIT. No single company owns it, and nobody can take it away from you or change its license.
- **It is old in the good way.** It descends from the POSTGRES research project at the University of California,
  Berkeley (1986). SQL support arrived in 1995, and the name PostgreSQL in 1996. Three decades of production use mean
  the core is very reliable and the documentation is unusually good.
- **It is strict about correctness.** PostgreSQL rejects invalid dates, refuses to silently truncate strings that are
  too long, enforces every constraint you declare, and has full transactional DDL (you can roll back a `CREATE TABLE`).
  It would rather give you an error than wrong data.
- **It is extensible.** You can add data types, functions, operators, index types and even procedural languages.
  Many well-known systems are PostgreSQL extensions: PostGIS (geographic data), TimescaleDB (time series), pgvector
  (vector similarity search for AI applications), Citus (sharding).
- **It is everywhere.** Every major cloud offers managed PostgreSQL (Amazon RDS and Aurora, Google Cloud SQL and
  AlloyDB, Azure Database for PostgreSQL), and many platforms (Supabase, Neon, Heroku, Render, Fly.io) are built on
  it. Skills you learn here transfer to all of them.

### The relational model in one minute

A relational database stores data in **tables**. A table has a fixed set of named, typed **columns** and any number
of **rows**. Here is a table of products:

```text
 id |          name          | price
----+------------------------+-------
  1 | Kestrel Fountain Pen   | 48.00
  2 | Dot Grid Notebook A5   | 14.00
  3 | Midnight Blue Ink 50ml | 16.00
```

Tables are connected by **values**, not by pointers: an order row stores the `id` of the customer who placed it, and
the database can match the two up when you ask. You talk to the database in **SQL** (Structured Query Language), a
*declarative* language: you describe the result you want ("the names of products cheaper than 20, sorted by price")
and the database works out how to get it. That split, *what* from you and *how* from the database, is what makes SQL
both powerful and occasionally surprising. Much of this tutorial is about understanding the *how* well enough to write
good *what*.

### Client and server

PostgreSQL is a **client/server** system. The server is a long-running program (`postgres`) that owns the data files
on disk. Everything else is a client that connects to it over the network or a local Unix-domain socket and sends SQL:
the `psql` command-line tool, a graphical tool such as pgAdmin or DBeaver, your Node.js or PHP application. Clients
never touch the data files directly. That is what lets the server enforce rules and coordinate many users at once.

```text
  psql ──────────┐
  Node.js app ───┼──── TCP port 5432 or Unix socket ───▶  postgres server ───▶ data directory on disk
  PHP app ───────┘                                         (one per cluster)
```

## Versions and support

PostgreSQL ships one **major version** a year, usually in September or October. Each major version is supported for
**five years**. During that time it receives **minor releases** at least once every three months (in practice in
February, May, August and November, plus extra releases for urgent security fixes). After five years it gets one final
minor release and is end-of-life.

| Version | First released | Final release (end of life) |
|---|---|---|
| 14 | Sep 2021 | Nov 2026 |
| 15 | Oct 2022 | Nov 2027 |
| 16 | Sep 2023 | Nov 2028 |
| 17 | Sep 2024 | Nov 2029 |
| **18** | **Sep 2025** | **Nov 2030** |

The current table is always at <https://www.postgresql.org/support/versioning/>. PostgreSQL 19 is in beta at the time
of writing and is expected late in 2026.

Version numbers have two parts since PostgreSQL 10: `18.6` means major version 18, minor release 6.

- **Minor releases** (18.5 → 18.6) contain only bug and security fixes. They never change the on-disk format, so you
  upgrade by installing the new binaries and restarting the server. Always run the latest minor release of your major
  version; the release notes occasionally ask you to do something extra, such as rebuilding a particular kind of index.
- **Major releases** (17 → 18) add features and may change the on-disk format, so upgrading needs a tool such as
  `pg_upgrade` or a dump and restore (chapter 23).

This tutorial targets **PostgreSQL 18**. Features added in 15, 16, 17 or 18 are labelled, for example "PostgreSQL 18+".

## Installing PostgreSQL

You need two things: the **server** and the **client tools** (`psql`, `pg_dump`, `createdb`, and friends). Install
version 18 if you can. Older clients mostly work with newer servers, but `psql` knows how to describe the newest
features only when it is at least as new as the server.

### macOS

The two easiest options:

- **Postgres.app** (<https://postgresapp.com>): download, drag it to Applications, open it, click *Initialize*. It
  runs a server in the menu bar and includes the client tools; add them to your `PATH` as its documentation describes.
- **Homebrew**:

  ```bash
  brew install postgresql@18
  brew services start postgresql@18
  echo 'export PATH="$(brew --prefix postgresql@18)/bin:$PATH"' >> ~/.zshrc   # postgresql@18 is "keg-only"
  ```

Both create a cluster in which **your macOS user name is a superuser** and a database with your user name exists, so
`psql` with no arguments connects straight away.

### Ubuntu and Debian

The version in your distribution's own repository is often one or two major versions behind. The PostgreSQL project
runs its own APT repository (PGDG, the PostgreSQL Global Development Group) with every supported version:

```bash
sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh   # adds the PGDG repository
sudo apt install -y postgresql-18
```

The package creates a cluster called `18/main`, starts it, and enables it at boot. The server runs as the operating
system user `postgres`, and the only database superuser is also called `postgres`. To connect as that user, and to
create a database role for yourself so you don't need `sudo` every time:

```bash
sudo -u postgres psql -c "SELECT version();"      # connect as the postgres OS user
sudo -u postgres createuser --superuser "$USER"   # a database superuser with your login name
createdb "$USER"                                  # a default database for psql to connect to
psql                                              # now works without sudo
```

A superuser account is fine on your own development machine. Chapter 21 shows how to set up properly limited roles
for applications.

Debian-based systems also give you `pg_lsclusters` (list clusters and their ports and status) and
`sudo systemctl status postgresql@18-main` to inspect the service.

On Fedora, RHEL, Rocky Linux and other RPM-based systems, use the PGDG YUM repository: <https://www.postgresql.org/download/linux/redhat/>
gives the exact commands for your distribution. After installing `postgresql18-server`, initialize and start it with
`sudo /usr/pgsql-18/bin/postgresql-18-setup initdb` and `sudo systemctl enable --now postgresql-18`.

### Windows

Download the installer from <https://www.postgresql.org/download/windows/> (it is built by EDB). It installs the
server as a Windows service, the client tools, and pgAdmin. During installation you choose a password for the
`postgres` superuser; remember it. Then open "SQL Shell (psql)" from the Start menu, or add
`C:\Program Files\PostgreSQL\18\bin` to your `PATH` and run `psql -U postgres` in a terminal.

Alternatively, install a Linux distribution under WSL and follow the Ubuntu instructions. Most production PostgreSQL
servers run on Linux, so that keeps your environment close to production.

### In a container

With Docker you can run the official image without installing anything else:

```bash
docker run --name inkwell-db \
  -e POSTGRES_PASSWORD=devpassword \
  -p 5432:5432 \
  -v inkwell-pgdata:/var/lib/postgresql \
  -d postgres:18

docker exec -it inkwell-db psql -U postgres     # psql inside the container
```

- `POSTGRES_PASSWORD` sets the password of the `postgres` superuser. The image refuses to start without it (unless you
  explicitly opt into no password, which you should not).
- `-v inkwell-pgdata:/var/lib/postgresql` keeps the data in a named volume, so it survives removing the container.
  The mount point changed in the PostgreSQL 18 images: from 18 on, mount the volume at `/var/lib/postgresql`; for 17
  and older images it was `/var/lib/postgresql/data`. Mounting at the wrong place silently stores the data in the
  container, where it disappears with it.
- `-p 5432:5432` publishes the port, so the client tools on your machine can connect with
  `psql -h localhost -U postgres`.

### Checking your installation

```bash
psql --version          # the client
pg_config --version     # the version your tools were built for (not always installed)
```

```text
psql (PostgreSQL) 18.6
PostgreSQL 18.6
```

Then connect and ask the server itself:

```bash
psql -c "SHOW server_version;"
```

```text
 server_version
----------------
 18.6
(1 row)
```

If `psql` says something like `connection to server on socket "/var/run/postgresql/.s.PGSQL.5432" failed: No such
file or directory`, the server is not running (or listens somewhere else). If it says
`FATAL:  role "alice" does not exist`, the server is running but has no role with your login name: connect as
`postgres` as shown above. If it says `FATAL:  database "alice" does not exist`, add `-d postgres` to connect to the
`postgres` database, which always exists.

## The vocabulary: clusters, databases, schemas, roles

Some PostgreSQL words mean something slightly different from what you might expect. Get them straight now and the
rest of the documentation becomes much easier to read.

```text
 one server process (postgres) = one CLUSTER = one data directory = one port (default 5432)
 │
 ├── ROLES (users and groups) ............ shared by the whole cluster
 │
 ├── DATABASE postgres ................... default database, always exists
 ├── DATABASE template0 / template1 ...... templates that CREATE DATABASE copies
 └── DATABASE inkwell
      ├── SCHEMA public
      │    ├── TABLE products
      │    ├── TABLE orders
      │    └── VIEW, FUNCTION, SEQUENCE, ...
      └── SCHEMA reporting
           └── ...
```

- A **cluster** (also called an *instance*) is one running server with one **data directory** on disk. "Cluster"
  here does *not* mean several machines; it is the historical term for "a collection of databases managed by one
  server". You can run several clusters on one machine on different ports (useful for testing upgrades).
- A cluster holds many **databases**. A connection is always to exactly one database, and a normal query cannot read
  tables in another database. Separate databases are the right tool for separate applications sharing one server.
- A database holds **schemas**, which are namespaces for tables, views, functions and other objects. Every database
  has a schema called `public`, and that is where your tables go unless you say otherwise. Unlike databases, schemas
  in the same database *can* be queried together. Chapter 14 covers using them.
- **Roles** are users and groups. They belong to the whole cluster, not to one database. A role that is allowed to log
  in is what other systems call a user. Chapter 21 covers roles and privileges.

The data directory contains everything: the table files, the write-ahead log, and configuration files such as
`postgresql.conf` and `pg_hba.conf`. Ask the server where it is (you need superuser rights for this one):

```sql
SHOW data_directory;
```

```text
       data_directory
-----------------------------
 /var/lib/postgresql/18/main
(1 row)
```

The path depends on how you installed PostgreSQL: Homebrew uses something like `/opt/homebrew/var/postgresql@18`,
the Docker image `/var/lib/postgresql/18/docker`. Never edit or delete files in the data directory by hand while the
server is running. The configuration files are the one exception, and even those take effect only after a reload.

## Your first session

Start `psql`. With no arguments it connects to the local server, as a role named after your login, to a database with
the same name. Use `-U` and `-d` to choose a role and a database, and `-h` and `-p` for a host and a port:

```bash
psql                                  # defaults
psql -U postgres -d postgres          # role postgres, database postgres
psql -h localhost -p 5432 -U postgres # over TCP, e.g. to the Docker container
```

```text
psql (18.6)
Type "help" for help.

postgres=#
```

The prompt shows the database you are connected to. `=#` means you are a superuser; an ordinary role sees `=>`.

Type a query and end it with a semicolon:

```sql
SELECT 1 + 1 AS answer;
```

```text
 answer
--------
      2
(1 row)
```

`psql` sends the text to the server only when it sees the `;`. If you press Enter without one, the prompt changes to
`postgres-#` to say "I'm still collecting your statement". Type the `;` on the new line and press Enter again. If you
end up in a muddle, `\r` clears the query buffer and `Ctrl+C` cancels.

Lines that start with a backslash are **meta-commands** for `psql` itself, not SQL. They don't need a semicolon. The
first ones to learn:

| Command | What it does |
|---|---|
| `\q` | quit (so does `Ctrl+D`) |
| `\?` | list all meta-commands |
| `\h` | SQL help: `\h CREATE TABLE` shows the syntax of a statement |
| `\l` | list databases |
| `\c dbname` | connect to another database |
| `\dt` | list tables |
| `\d name` | describe a table (columns, types, indexes, constraints) |
| `\x` | toggle expanded output (one column per line), great for wide rows |

Chapter 2 is all about `psql`. For now, those are enough.

Try a few things that show what kind of server you are talking to:

```sql
SELECT current_user, current_database(), now()::date AS today;
```

```text
 current_user | current_database |   today
--------------+------------------+------------
 postgres     | postgres         | 2026-10-11
(1 row)
```

Your user and date will differ. `current_user` and `current_database()` are built-in functions, and `now()` returns
the current timestamp; the `::date` turns it into a plain date (a *cast*, chapter 3).

## Creating the Inkwell database

Inkwell is the online stationery shop whose database you will build in this tutorial. Create a database for it and
connect to it:

```sql
CREATE DATABASE inkwell;
```

```text
CREATE DATABASE
```

```sql
\c inkwell
```

```text
You are now connected to database "inkwell" as user "postgres".
```

The prompt is now `inkwell=#`. From the shell, `createdb inkwell` does the same as the `CREATE DATABASE` statement,
and `psql -d inkwell` (or just `psql inkwell`) connects straight to it.

`CREATE DATABASE` works by copying the template database `template1`. Anything you put in `template1` (an extension,
a table) appears in every database created afterwards. `template0` is a pristine copy that you never modify; you use
it when you need a database with different encoding or locale settings (chapter 11).

### The first table

A shop needs products. Create a table for them:

```sql
CREATE TABLE products (
    id    integer PRIMARY KEY,
    name  text NOT NULL,
    price numeric(10, 2) NOT NULL
);
```

```text
CREATE TABLE
```

Reading it line by line:

- `CREATE TABLE products ( ... )` creates a table named `products` in the current database, in the `public` schema.
- Each line inside the parentheses defines a column: a **name**, a **data type**, and optional **constraints**.
- `id integer PRIMARY KEY`: a whole number that identifies the row. `PRIMARY KEY` means it must be present and unique,
  and PostgreSQL creates an index to enforce that.
- `name text NOT NULL`: a string of any length. `NOT NULL` means every product must have a name.
- `price numeric(10, 2) NOT NULL`: an exact decimal with up to 10 digits, 2 of them after the decimal point. Money is
  never stored as a floating-point number (chapter 3 explains why).

Ask `psql` to describe the table:

```sql
\d products
```

```text
                 Table "public.products"
 Column |     Type      | Collation | Nullable | Default
--------+---------------+-----------+----------+---------
 id     | integer       |           | not null |
 name   | text          |           | not null |
 price  | numeric(10,2) |           | not null |
Indexes:
    "products_pkey" PRIMARY KEY, btree (id)
```

The primary key created an index called `products_pkey`. PostgreSQL chose the name because you didn't; chapter 4 shows
how to name constraints yourself.

### Adding, reading, changing and removing rows

`INSERT` adds rows. You can insert several at once:

```sql
INSERT INTO products (id, name, price)
VALUES (1, 'Kestrel Fountain Pen', 48.00),
       (2, 'Dot Grid Notebook A5', 14.00),
       (3, 'Midnight Blue Ink 50ml', 16.00);
```

```text
INSERT 0 3
```

The reply `INSERT 0 3` is a **command tag**: three rows were inserted. (The `0` is a leftover from a feature that was
removed long ago; it is always `0`.)

`SELECT` reads rows. `*` means "all columns":

```sql
SELECT * FROM products;
```

```text
 id |          name          | price
----+------------------------+-------
  1 | Kestrel Fountain Pen   | 48.00
  2 | Dot Grid Notebook A5   | 14.00
  3 | Midnight Blue Ink 50ml | 16.00
(3 rows)
```

Choose columns, filter rows with `WHERE`, and sort with `ORDER BY`:

```sql
SELECT name, price
FROM products
WHERE price < 20
ORDER BY price DESC;
```

```text
          name          | price
------------------------+-------
 Midnight Blue Ink 50ml | 16.00
 Dot Grid Notebook A5   | 14.00
(2 rows)
```

You can compute values too. Each output column gets a name, either the one you give with `AS` or one PostgreSQL makes
up:

```sql
SELECT name, price, price * 1.20 AS price_with_vat
FROM products
ORDER BY id;
```

```text
          name          | price | price_with_vat
------------------------+-------+----------------
 Kestrel Fountain Pen   | 48.00 |        57.6000
 Dot Grid Notebook A5   | 14.00 |        16.8000
 Midnight Blue Ink 50ml | 16.00 |        19.2000
(3 rows)
```

Multiplying two exact decimals gives an exact decimal with as many fractional digits as both inputs together, which is
why you see four decimals. `round(price * 1.20, 2)` would give two.

**Without `ORDER BY`, row order is not defined.** The three rows came back in insertion order above only because the
table is tiny and freshly written. On a real table, after updates and deletes, or when the planner picks a different
strategy, the order changes. Whenever order matters, say so with `ORDER BY`.

`UPDATE` changes existing rows. The `WHERE` clause decides which ones:

```sql
UPDATE products SET price = 15.00 WHERE id = 2;
```

```text
UPDATE 1
```

`DELETE` removes rows:

```sql
DELETE FROM products WHERE id = 3;
```

```text
DELETE 1
```

```sql
SELECT * FROM products ORDER BY id;
```

```text
 id |         name         | price
----+----------------------+-------
  1 | Kestrel Fountain Pen | 48.00
  2 | Dot Grid Notebook A5 | 15.00
(2 rows)
```

An `UPDATE` or `DELETE` **without a `WHERE` clause changes every row in the table**. There is no confirmation
prompt. The habit that saves you: write the `WHERE` clause first, try it as a `SELECT`, and only then turn it into an
`UPDATE` or `DELETE`. Chapter 5 adds a second safety net, running risky changes inside a transaction you can roll back.

### Constraints protect you

The table rejects data that breaks its rules. Try a duplicate id and a missing name:

```sql
INSERT INTO products (id, name, price) VALUES (1, 'Sepia Ink 50ml', 16.00);
```

```text
ERROR:  duplicate key value violates unique constraint "products_pkey"
DETAIL:  Key (id)=(1) already exists.
```

```sql
INSERT INTO products (id, price) VALUES (3, 16.00);
```

```text
ERROR:  null value in column "name" of relation "products" violates not-null constraint
DETAIL:  Failing row contains (3, null, 16.00).
```

Each failed statement changed nothing at all. The database is the last line of defence for your data: application
code has bugs, scripts get run twice, people use the database directly. Declaring rules in the schema means they hold
no matter who writes the data. Chapter 4 is devoted to constraints.

## SQL syntax basics

A few rules apply to everything you will write.

- **Statements end with a semicolon.** Line breaks and indentation don't matter to the server; use them for
  readability.
- **Keywords and unquoted names are case-insensitive.** `select * from PRODUCTS` works. PostgreSQL folds unquoted names
  to *lower case*, so `PRODUCTS`, `Products` and `products` are the same table. This tutorial writes keywords in upper
  case and names in lower case, a common convention.
- **Double quotes are for identifiers** (names), and make them case-sensitive and allow any characters: `"Order Items"`
  is a different name from `order_items`. Avoid quoted identifiers in your own schemas; you would have to quote them
  forever. Choose lower-case names with underscores.
- **Single quotes are for strings.** `'Kestrel'` is a string; `"Kestrel"` is a column name. Writing a string in double
  quotes is the classic beginner error:

  ```sql
  SELECT * FROM products WHERE name = "Kestrel Fountain Pen";
  ```

  ```text
  ERROR:  column "Kestrel Fountain Pen" does not exist
  LINE 1: SELECT * FROM products WHERE name = "Kestrel Fountain Pen";
                                              ^
  ```

  The `LINE 1:` and the caret point at where the problem is. Read them.

- **A quote inside a string is doubled:** `'Gus''s order'`. (Chapter 3 shows dollar quoting, which avoids this.)
- **Comments** are `-- to the end of the line` or `/* a block */`.
- **Numbers** are written plainly: `42`, `3.14`, `1e6`, and since PostgreSQL 16 `1_000_000` (underscores as digit
  separators).

## What happens when you run a query

Knowing roughly what the server does will help you in every later chapter, from performance tuning to understanding
why two sessions see different data.

### One process per connection

When the server starts, a supervisor process (called the *postmaster*, though the program is `postgres`) opens the
port and starts a set of helper processes. Each time a client connects, the postmaster starts a new **backend
process** dedicated to that connection, which lives until the client disconnects. On Linux, `ps` shows them:

```text
postgres -D /var/lib/postgresql/18/main     ← the postmaster
postgres: io worker 0                        ← asynchronous I/O workers (18+)
postgres: checkpointer                       ← writes changed pages to disk periodically
postgres: background writer                  ← trickles changed pages out between checkpoints
postgres: walwriter                          ← flushes the write-ahead log
postgres: autovacuum launcher                ← schedules cleanup of dead row versions
postgres: logical replication launcher
postgres: postgres inkwell [local] idle      ← YOUR backend: role, database, client, state
```

Your own backend's process ID is one function call away:

```sql
SELECT pg_backend_pid();
```

```text
 pg_backend_pid
----------------
          48213
(1 row)
```

A process per connection is robust (one crashing backend can't corrupt another's memory) but not free: each
connection costs memory and a process start. That is why applications use **connection pools** and why a server
configured for 100 connections (`max_connections`, the default) should not be hit by 1,000 clients at once (chapter
22).

### From SQL text to rows

```text
  "SELECT name FROM products WHERE price < 20"
        │
        ▼
  Parser ─────────▶ syntax tree; checks the grammar, looks up tables and columns
        │
        ▼
  Rewriter ───────▶ expands views and applies rules
        │
        ▼
  Planner ────────▶ considers ways to run it (scan the table? use an index? which join order?),
        │            estimates the cost of each from table statistics, and picks the cheapest
        ▼
  Executor ───────▶ runs the plan, reading pages through shared memory, and streams rows to the client
```

You can see the plan the planner picked by putting `EXPLAIN` in front of a query:

```sql
EXPLAIN SELECT name FROM products WHERE price < 20;
```

```text
                         QUERY PLAN
------------------------------------------------------------
 Seq Scan on products  (cost=0.00..22.75 rows=340 width=32)
   Filter: (price < '20'::numeric)
(2 rows)
```

"Seq Scan" means "read the whole table". The numbers are estimates, and on a two-row table with no statistics yet they
are wild guesses. Chapter 16 teaches you to read plans properly; for now, just know that the planner exists and that
the same SQL can be run in very different ways.

### Shared memory and durability

The data lives in files in the data directory, organized into 8 kB **pages**. All backends share a cache of pages in
memory called **shared buffers**. A query reads pages into the cache if they aren't already there, and changes are
made to the cached pages.

So how can a change survive a crash if it is only in memory? Through the **write-ahead log (WAL)**. Before a change is
considered committed, a description of it is appended to the WAL and flushed to disk. The changed table pages can be
written out lazily later, by the checkpointer and background writer. If the server crashes, it replays the WAL from the
last checkpoint on restart and gets every committed change back. That is the "D" (durability) in ACID, and chapter 18
looks at it in detail. The WAL is also the basis of backups and replication (chapter 23).

### Every statement is a transaction

Each statement you ran above was automatically wrapped in its own **transaction**: either it completed fully, or (like
the failed inserts) it had no effect at all. You can also group several statements into one transaction with `BEGIN`
and `COMMIT`, so they succeed or fail together. You'll use that from chapter 5 on, and chapter 17 explains how
PostgreSQL keeps concurrent transactions from stepping on each other.

## Graphical tools

`psql` is the tool this tutorial uses, because it is always available, it is what you will have on a production server,
and it is genuinely powerful. Graphical clients are pleasant for browsing data and schemas; popular ones are
**pgAdmin** (the official one, free), **DBeaver** (free, many databases), **DataGrip** (commercial, JetBrains), and
**TablePlus** or **Postico** on macOS. Most editors and IDEs also have database plugins. They all send the same SQL
you are learning here.

## Key takeaways

- PostgreSQL is an open-source, strict, extensible relational database server. Clients (such as `psql` and your
  applications) send it SQL over a connection; they never touch the data files.
- A major version ships every year and is supported for five years. Always run the latest minor release of your major
  version. This tutorial targets PostgreSQL 18.
- A **cluster** is one server with one data directory. It holds many **databases**, each with **schemas** that hold
  tables. **Roles** are shared by the whole cluster. A connection is always to one database.
- `psql` sends SQL when it sees a `;`. Backslash commands (`\d`, `\dt`, `\l`, `\c`, `\x`, `\q`) are for `psql`
  itself.
- Unquoted names are folded to lower case. Single quotes delimit strings; double quotes delimit identifiers.
- Without `ORDER BY`, row order is undefined. An `UPDATE` or `DELETE` without `WHERE` hits every row.
- Constraints (`PRIMARY KEY`, `NOT NULL`, …) make the database reject bad data whoever sends it.
- Each connection is a server process. Queries go through parser, rewriter, planner and executor. Durability comes from
  the write-ahead log, not from writing table pages immediately.

## Exercises

1. **Explore your installation.** Connect with `psql` and find out: the server version, the name of the database you
   are connected to, the data directory, and the port the server listens on (`SHOW port;`). Then list all databases
   with `\l`. Which three databases exist in every new cluster?

<details>
<summary>Solution</summary>

```sql
SHOW server_version;
SELECT current_database();
SHOW data_directory;
SHOW port;
\l
```

Every new cluster has `postgres` (a default database to connect to), `template1` (the template that
`CREATE DATABASE` copies by default) and `template0` (a pristine template that is never changed). Package installs
on macOS may also create a database named after your user. `SHOW port` prints `5432` unless you or your installer
chose another port.

</details>

2. **More products.** Add two more products to the Inkwell `products` table: `Sepia Ink 50ml` at 16.00 with id 3 and
   `Graphite Pencil HB` at 1.20 with id 4. Then list all products whose price is between 10 and 20, cheapest first.

<details>
<summary>Solution</summary>

```sql
INSERT INTO products (id, name, price)
VALUES (3, 'Sepia Ink 50ml', 16.00),
       (4, 'Graphite Pencil HB', 1.20);

SELECT name, price
FROM products
WHERE price >= 10 AND price <= 20
ORDER BY price;
```

```text
         name         | price
----------------------+-------
 Dot Grid Notebook A5 | 15.00
 Sepia Ink 50ml       | 16.00
(2 rows)
```

`WHERE price BETWEEN 10 AND 20` means the same thing (both ends included); chapter 6 covers it.

</details>

3. **Read the errors.** Predict what each statement does, then run it and read the error message carefully.

   ```sql
   INSERT INTO products VALUES (5, 'Brass Pen Clip');
   INSERT INTO products (id, name, price) VALUES (5, 'Brass Pen Clip', 'five');
   SELECT nmae FROM products;
   ```

<details>
<summary>Solution</summary>

```text
ERROR:  null value in column "price" of relation "products" violates not-null constraint
DETAIL:  Failing row contains (5, Brass Pen Clip, null).
```

```text
ERROR:  invalid input syntax for type numeric: "five"
LINE 1: ...ucts (id, name, price) VALUES (5, 'Brass Pen Clip', 'five');
                                                               ^
```

```text
ERROR:  column "nmae" does not exist
LINE 1: SELECT nmae FROM products;
               ^
HINT:  Perhaps you meant to reference the column "products.name".
```

The first statement has no column list, so the values fill the columns in order and `price` is left empty, which
violates `NOT NULL`. Leaving out the column list is legal but fragile: it breaks or, worse, silently fills the wrong
columns when someone adds or reorders columns. Always list the columns in `INSERT`. The second statement fails because
`'five'` cannot be converted to a number; PostgreSQL never guesses. The third shows that PostgreSQL even suggests a
fix for typos in column names. Long statements are shortened with `...` in the `LINE` part of a message so that the
caret still points at the problem.

</details>

4. **A safer delete.** You want to delete every product cheaper than 2.00. Write the `SELECT` that shows which rows
   would be affected, then the `DELETE`. How many rows did it remove?

<details>
<summary>Solution</summary>

```sql
SELECT * FROM products WHERE price < 2.00;
```

```text
 id |        name        | price
----+--------------------+-------
  4 | Graphite Pencil HB |  1.20
(1 row)
```

```sql
DELETE FROM products WHERE price < 2.00;
```

```text
DELETE 1
```

Writing the `WHERE` clause once as a `SELECT` and then reusing it for the `DELETE` is a habit worth keeping for the
rest of your career.

</details>

5. **Watch your own backend.** Open two terminals and connect to `inkwell` with `psql` in both. In the first, run
   `SELECT pg_backend_pid();`. In the second, run:

   ```sql
   SELECT pid, usename, datname, state, query
   FROM pg_stat_activity
   WHERE datname = 'inkwell';
   ```

   What do you see, and what does it tell you about connections?

<details>
<summary>Solution</summary>

You see one row per connection to `inkwell`, so two rows, each with its own `pid`. One of them has the same `pid` that
the first terminal printed, and its `query` column shows the last statement that session ran
(`SELECT pg_backend_pid();`) with `state` = `idle`. The other row is the session running the query, with
`state` = `active`.

`pg_stat_activity` is a built-in view with one row per server process. It confirms the process-per-connection model:
every client gets its own backend with its own process ID. It is also the first place to look when the database is
slow or a query seems stuck (chapter 23).

</details>

---
← Previous: [README](README.md) | Next: [psql and the command-line tools](02-psql-and-the-command-line-tools.md) →
