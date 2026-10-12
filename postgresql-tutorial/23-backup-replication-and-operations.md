# Chapter 23 — Backup, replication and operations

## What you'll learn

- How to plan backups: recovery point and recovery time objectives, and logical vs. physical backups
- `pg_dump`, `pg_dumpall` and `pg_restore`: formats, parallel jobs, selective restores, roles, and statistics (18+)
- Physical backups with `pg_basebackup`, WAL archiving, and point-in-time recovery (PITR) after a mistake
- Incremental backups with WAL summarization (17+)
- Streaming replication: standbys, replication slots, lag, synchronous commit, failover and `pg_rewind`
- Logical replication: publications, subscriptions, replica identity, row filters, and what isn't replicated
- Upgrading: minor releases, and major versions with `pg_upgrade` (including `--swap`, 18+)
- Server configuration: the files, `ALTER SYSTEM`, setting contexts, reloads and restarts, logging, `auto_explain`
- Monitoring a running server, finding blocked sessions, and cancelling or terminating them

The earlier chapters were about what happens inside one database. This one is about keeping that database alive: the
data must survive a broken disk, a deleted table and a lost data centre; the server must be upgraded, configured and
watched. These jobs are often called "operations" or "DBA work". If you run PostgreSQL yourself, they are yours. If
you use a managed service (Amazon RDS or Aurora, Google Cloud SQL, Azure Database for PostgreSQL, Neon, Supabase, …),
the provider runs most of the machinery, but you still choose the settings, test the restores, read the monitoring
and decide what to do when something breaks. Either way, you need to know how it works.

Everything in this chapter is run for real, on small practice clusters on your own machine. The commands are written
for Linux and macOS; on Windows, run them in WSL.

## Setting up: practice clusters

Backups and replication involve several servers, so you'll create a few extra **clusters** (chapter 1: a cluster is
one data directory served by one `postgres` process). They live in `~/pgpractice`, listen only on a Unix socket in
`/tmp` (no network access at all), and use ports that don't collide with your normal server:

| Cluster | Directory | Port | Role in this chapter |
|---|---|---|---|
| primary | `~/pgpractice/primary` | 5433 | the "production" server |
| standby | `~/pgpractice/standby` | 5434 | a streaming replica |
| second | `~/pgpractice/second` | 5435 | a restore target, then a logical replication subscriber |
| pitr | `~/pgpractice/pitr` | 5436 | point-in-time recovery |

The server programs `initdb`, `pg_ctl` and `pg_basebackup` must be on your `PATH`. With Homebrew and the EDB
installers they are. On Debian and Ubuntu they aren't; add them first:

```bash
export PATH=/usr/lib/postgresql/18/bin:$PATH
```

Create the primary. `--auth=trust` lets every local user connect as anyone, which is fine for a practice cluster that
only listens on a socket in your machine, and never acceptable anywhere else (chapter 21):

```bash
mkdir -p ~/pgpractice/archive
initdb -D ~/pgpractice/primary -U postgres --auth=trust
```

```text
The files belonging to this database system will be owned by user "you".
This user must also own the server process.

The database cluster will be initialized with locale "C.UTF-8".
The default database encoding has accordingly been set to "UTF8".
The default text search configuration will be set to "english".

Data page checksums are enabled.

creating directory /home/you/pgpractice/primary ... ok
...
Success. You can now start the database server using:

    pg_ctl -D /home/you/pgpractice/primary -l logfile start
```

Now append the practice settings to its configuration file and start it. The `archive_*` and `summarize_wal` lines
are explained later in the chapter. The heredoc is unquoted (`<<EOF`) on purpose, so that the shell replaces `$HOME`
with your real home directory:

```bash
cat >> ~/pgpractice/primary/postgresql.conf <<EOF

# --- practice settings ---
port = 5433
listen_addresses = ''
unix_socket_directories = '/tmp'
archive_mode = on
archive_command = 'cmp -s %p $HOME/pgpractice/archive/%f || (test ! -f $HOME/pgpractice/archive/%f && cp %p $HOME/pgpractice/archive/%f)'
summarize_wal = on
EOF
pg_ctl -D ~/pgpractice/primary -l ~/pgpractice/primary.log start
```

```text
waiting for server to start.... done
server started
```

Point the client tools at it for the rest of the chapter, and load Inkwell with the roles and row-level security from
chapters 21 and 22. Run this from the tutorial's directory:

```bash
export PGHOST=/tmp PGPORT=5433 PGUSER=postgres
psql -X -q -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
psql -X -q -v ON_ERROR_STOP=1 -d inkwell -f examples/setup.sql
```

Every command below without `-p` now talks to the primary. Commands for the other clusters pass `-p 5434`, `-p 5435`
and so on. When you're done with the chapter, the [last section](#cleaning-up) removes everything.

## Planning backups

Two numbers drive every backup decision:

- **RPO (recovery point objective)**: how much recent data you may lose. "We can lose the last 24 hours" allows a
  nightly dump; "at most a few seconds" requires continuous WAL archiving or replication.
- **RTO (recovery time objective)**: how long the restore may take. Restoring a 2 TB dump with its index builds can
  take many hours; promoting a standby takes seconds.

And three kinds of disaster, each needing something different:

| What goes wrong | Example | What saves you |
|---|---|---|
| Hardware or server failure | disk dies, VM is lost | a replica you can promote, or a physical backup plus WAL |
| Human or application error | `DROP TABLE`, an `UPDATE` without `WHERE`, a buggy migration | point-in-time recovery, or a recent logical dump. **A replica does not help: it copies the mistake within milliseconds** |
| Site loss, ransomware, a compromised account | the whole region or account is gone | backups stored elsewhere: another region, another account, ideally immutable |

PostgreSQL offers two families of backups:

| | Logical (`pg_dump`) | Physical (`pg_basebackup` + WAL) |
|---|---|---|
| What it contains | SQL definitions and data of one database | a byte copy of the whole cluster's data directory |
| Granularity of restore | one database, schema or table | the whole cluster |
| Point in time | the moment the dump started | any moment covered by the archived WAL |
| Portable across major versions and platforms | yes | no: same major version, same platform |
| Restore speed for big databases | slow (rebuilds every index) | fast (copies files, replays WAL) |
| Typical use | small databases, moving data, upgrades, extra safety copies | the main backup of any important database |

Most production setups use both: continuous physical backups for recovery, plus occasional dumps for portability and
for pulling out single tables. Three rules apply to all of them:

1. **A backup you haven't restored is not a backup.** Restore regularly, automatically if you can, and check the
   result (row counts, a smoke test). Most backup disasters are discovered during the first real restore.
2. **Keep copies away from the server**, following the classic 3-2-1 rule: three copies, on two kinds of storage, one
   off-site. A backup on the same disk or in the same cloud account as the database shares its fate.
3. **Protect backups like the database.** They contain every customer's data and every password hash.

## Logical backups with pg_dump

`pg_dump` connects like any client and writes out one database as SQL definitions plus data. It runs in a single
`REPEATABLE READ` transaction (chapter 17), so the dump is a consistent snapshot of one moment, while the application
keeps reading and writing. It doesn't block writes. It does take `ACCESS SHARE` locks on every table it dumps, so DDL
that needs a stronger lock (`ALTER TABLE`, `DROP TABLE`, `TRUNCATE`) waits until the dump finishes, and a long dump
holds back `VACUUM` like any long transaction (chapter 18).

### Formats

| Format | Option | Output | Restore with | Parallel dump / restore | Selective restore |
|---|---|---|---|---|---|
| plain | `-Fp` (default) | one SQL script | `psql` | no / no | only by editing the file |
| custom | `-Fc` | one compressed archive file | `pg_restore` | no / yes | yes |
| directory | `-Fd` | a directory, one file per table | `pg_restore` | yes / yes | yes |
| tar | `-Ft` | a tar file of the directory format | `pg_restore` | no / no | yes |

The **custom format** is the everyday choice: compressed, one file, and `pg_restore` can pick parts of it and restore
in parallel. The **directory format** additionally dumps in parallel with `-j`, which is the fastest way to dump a
large database. The plain format is handy when you want to read or edit the SQL.

```bash
cd ~/pgpractice
pg_dump -Fc -f inkwell.dump inkwell           # custom format
pg_dump -Fd -j 4 -f inkwell.dir inkwell       # directory format, 4 parallel jobs
pg_dump -f inkwell.sql inkwell                # plain SQL
```

`pg_restore --list` shows what's in an archive, as a table of contents (TOC):

```bash
pg_restore --list inkwell.dump | head -24
```

```text
;
; Archive created at 2026-10-11 20:17:46 UTC
;     dbname: inkwell
;     TOC Entries: 75
;     Compression: gzip
;     Dump Version: 1.16-0
;     Format: CUSTOM
;     Integer: 4 bytes
;     Offset: 8 bytes
;     Dumped from database version: 18.6
;     Dumped by pg_dump version: 18.6
;
;
; Selected TOC Entries:
;
883; 1247 16386 TYPE public order_status inkwell_owner
243; 1255 16575 FUNCTION public current_customer_id() inkwell_owner
3584; 0 0 ACL public FUNCTION current_customer_id() inkwell_owner
242; 1255 16574 FUNCTION public place_order(bigint, jsonb) inkwell_owner
3585; 0 0 ACL public FUNCTION place_order(p_customer_id bigint, p_items jsonb) inkwell_owner
224; 1259 16436 TABLE public products inkwell_owner
3586; 0 0 COMMENT public COLUMN products.attributes inkwell_owner
3587; 0 0 ACL public TABLE products inkwell_owner
241; 1255 16572 FUNCTION public search_products(text, integer) inkwell_owner
```

Every object is an entry: types, functions, tables, `TABLE DATA`, constraints, indexes, `ACL` (the grants), `POLICY`,
and so on. `pg_restore` restores them in dependency order: tables, then data, then indexes and constraints (building
an index once over all the data is far faster than updating it row by row), then foreign keys.

A plain dump is readable SQL. Its start shows the settings `pg_dump` uses to make the script safe to replay:

```text
--
-- PostgreSQL database dump
--

\restrict elb3VaSa6DREqWaex8N1KzflbvomqgFBgHnhKK4WZ73rhcXTpdigE6700QIjofv

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;
```

Note `search_path` set to empty (every name in the dump is schema-qualified, chapter 21) and `row_security = off`.
The `\restrict` line, paired with an `\unrestrict` at the end, was added by recent security releases of every
supported version: between the two, `psql` refuses to run backslash commands, so a tampered dump can't run shell
commands through `psql`. Restore plain dumps with a `psql` at least as new as the `pg_dump` that wrote them.

### What a dump does not contain

`pg_dump` dumps **one database**. Cluster-wide objects aren't part of any database, so they aren't in the dump:

- **roles** and their passwords, memberships and per-role settings (chapter 21),
- **tablespaces**,
- the configuration files (`postgresql.conf`, `pg_hba.conf`).

Owners and grants inside the dump refer to roles by name. Restore the dump into a cluster that lacks those roles and
you get a stream of errors. Try it: create the second practice cluster, and restore into a new database there.

```bash
initdb -D ~/pgpractice/second -U postgres --auth=trust > /dev/null
cat >> ~/pgpractice/second/postgresql.conf <<'EOF'
port = 5435
listen_addresses = ''
unix_socket_directories = '/tmp'
EOF
pg_ctl -D ~/pgpractice/second -l ~/pgpractice/second.log start
createdb -p 5435 inkwell
pg_restore -p 5435 -d inkwell inkwell.dump
```

```text
pg_restore: error: could not execute query: ERROR:  role "inkwell_owner" does not exist
Command was: ALTER TYPE public.order_status OWNER TO inkwell_owner;

pg_restore: error: could not execute query: ERROR:  role "inkwell_owner" does not exist
Command was: ALTER FUNCTION public.current_customer_id() OWNER TO inkwell_owner;
...
pg_restore: error: could not execute query: ERROR:  role "inkwell_portal" does not exist
Command was: CREATE POLICY order_items_customer ON public.order_items FOR SELECT TO inkwell_portal USING ((EXISTS ( SELECT
   FROM public.orders o
  WHERE ((o.id = order_items.order_id) AND (o.customer_id = public.current_customer_id())))));
...
pg_restore: warning: errors ignored on restore: 26
```

The tables and data arrived, but every object now belongs to `postgres`, and the policies and grants are missing: the
security model of chapter 21 is silently gone. `pg_restore` continues after errors by default; in scripts, pass
`--exit-on-error` (or `-1`/`--single-transaction`, which also rolls everything back on the first error).

There are two correct ways to fix it, depending on what you want:

- **Move the database with its security model**: restore the roles first. `pg_dumpall --globals-only` dumps the
  cluster-wide objects of the source cluster.
- **Just get the data** into a database you own, for a developer's laptop or a quick look: `pg_restore --no-owner
  --no-privileges` (`-O -x`) skips ownership and grants, so everything belongs to the restoring user. Add
  `--no-policies` (18+) to leave out row-level security policies too.

The first way, properly. Drop the half-restored database, restore the globals, then let `pg_restore` create the
database itself with `--create` (`-C`). With `--create`, the `-d` database is only used to connect, and the dump's own
`CREATE DATABASE` runs, with the original owner, locale settings and per-database settings
(`ALTER DATABASE ... SET`), which otherwise aren't restored:

```bash
dropdb -p 5435 inkwell
pg_dumpall --globals-only -f globals.sql
psql -X -q -p 5435 -f globals.sql
pg_restore -p 5435 --create -d postgres inkwell.dump
```

```text
psql:globals.sql:28: ERROR:  role "postgres" already exists
```

That one error is expected and harmless: every cluster has the bootstrap superuser already. `globals.sql` is plain
SQL, and worth a look, because it shows what a role really is:

```text
CREATE ROLE inkwell_app;
ALTER ROLE inkwell_app WITH NOSUPERUSER INHERIT NOCREATEROLE NOCREATEDB LOGIN NOREPLICATION NOBYPASSRLS PASSWORD 'SCRAM-SHA-256$4096:PW5wKxrqIUgI/0XI51NGTA==$BFH6jFOhBu+KtP9F6VNtGSh0pVDjarwg9R15zQT0DiY=:cVT2GzWeYHfALbbAjMgXS9eEHCH1TZDSC56ZH53lgYM=';
...
ALTER ROLE inkwell_migrate SET role TO 'inkwell_owner';
GRANT inkwell_owner TO inkwell_migrate WITH INHERIT FALSE GRANTED BY postgres;
GRANT inkwell_portal TO inkwell_app WITH INHERIT FALSE GRANTED BY postgres;
GRANT inkwell_readonly TO inkwell_readwrite WITH INHERIT TRUE GRANTED BY postgres;
GRANT inkwell_readwrite TO inkwell_app WITH INHERIT TRUE GRANTED BY postgres;
```

The password hashes are included, so the application can log in to the new cluster with its old password. Treat
`globals.sql` as a secret. (`pg_dumpall --no-role-passwords` leaves the hashes out.)

```bash
psql -X -p 5435 -d inkwell -c '\l inkwell' -c '\drds'
```

```text
                                                         List of databases
  Name   |     Owner     | Encoding | Locale Provider | Collate |  Ctype  | Locale | ICU Rules |         Access privileges
---------+---------------+----------+-----------------+---------+---------+--------+-----------+-----------------------------------
 inkwell | inkwell_owner | UTF8     | icu             | C.UTF-8 | C.UTF-8 | en     |           | inkwell_owner=CTc/inkwell_owner  +
         |               |          |                 |         |         |        |           | inkwell_readonly=Tc/inkwell_owner+
         |               |          |                 |         |         |        |           | inkwell_migrate=c/inkwell_owner
(1 row)

                List of settings
      Role       | Database |      Settings
-----------------+----------+--------------------
 inkwell_migrate |          | role=inkwell_owner
                 | inkwell  | TimeZone=UTC
(2 rows)
```

Owner, ICU locale, database privileges and the per-database time zone all came across. The rule: **to move a
database to a new cluster, restore the globals first, then the database with `--create`**.

### Who should run pg_dump

`pg_dump` needs to read everything it dumps. Run it as a superuser, as the owner of all the objects, or as a role with
`BYPASSRLS`: as chapter 21 showed, a role subject to row-level security makes `pg_dump` fail rather than silently
write an incomplete dump. The predefined role `pg_read_all_data` grants read access to every table, but on its own it
doesn't bypass row-level security, so give a dedicated backup role both:

```sql
CREATE ROLE backup LOGIN BYPASSRLS PASSWORD '...';
GRANT pg_read_all_data TO backup;
```

### Restoring selectively

`pg_restore` can restore parts of an archive. A common emergency: someone ran `DELETE FROM reviews` without a `WHERE`.
The table and its constraints are still there, only the rows are gone, and you have last night's dump. Restore just
that table's data:

```bash
psql -X -d inkwell -c 'DELETE FROM reviews'
pg_restore -d inkwell --data-only -t reviews inkwell.dump
psql -X -d inkwell -c 'SELECT count(*) FROM reviews'
```

```text
DELETE 9
 count
-------
     9
(1 row)
```

Of course, every review written since the dump is lost; point-in-time recovery (below) does better. Useful options:

| Option | Effect |
|---|---|
| `-t table`, `-n schema`, `-I index`, `-P 'function(args)'` | restore only the named objects (`-t` alone does not bring the table's indexes and constraints) |
| `--data-only` / `--schema-only` | only the data, or only the definitions |
| `-c --if-exists` (`--clean`) | drop objects before recreating them |
| `-j N` | restore with N parallel jobs (custom and directory formats) |
| `-1` (`--single-transaction`) | all or nothing; can't be combined with `-j` |
| `-O -x` (`--no-owner --no-privileges`) | ignore ownership and grants |
| `-f file` | don't restore, write the SQL to a file (`-f -` for standard output) |

For anything more selective, edit the table of contents. `pg_restore -l` writes it to a file; comment out lines with a
leading `;`, and pass the file back with `-L`:

```bash
pg_restore -l inkwell.dump > toc.txt
grep reviews toc.txt
```

```text
229; 1259 16510 TABLE public reviews inkwell_owner
3594; 0 0 ACL public TABLE reviews inkwell_owner
228; 1259 16509 SEQUENCE public reviews_id_seq inkwell_owner
3575; 0 16510 TABLE DATA public reviews inkwell_owner
3599; 0 0 SEQUENCE SET public reviews_id_seq inkwell_owner
3402; 2606 16523 CONSTRAINT public reviews reviews_pkey inkwell_owner
3404; 2606 16525 CONSTRAINT public reviews reviews_product_id_customer_id_key inkwell_owner
3400; 1259 16540 INDEX public reviews_customer_id_idx inkwell_owner
3410; 2606 16531 FK CONSTRAINT public reviews reviews_customer_id_fkey inkwell_owner
3411; 2606 16526 FK CONSTRAINT public reviews reviews_product_id_fkey inkwell_owner
```

```bash
pg_restore -d some_database -L toc.txt inkwell.dump
```

### Statistics in dumps (PostgreSQL 18+)

A freshly restored database has no planner statistics until `ANALYZE` runs (chapter 16), so the first queries after a
restore can get terrible plans. Since PostgreSQL 18, `pg_dump --statistics` also dumps the statistics, and restoring
them takes a fraction of the time `ANALYZE` needs on a big database:

```bash
pg_dump -Fc --statistics -f stats.dump inkwell
pg_restore -l stats.dump | grep -c 'STATISTICS DATA'
```

```text
21
```

They're restored as calls to `pg_restore_relation_stats()` and `pg_restore_attribute_stats()`. In 18, `pg_dump` and
`pg_restore` leave statistics out unless you ask (`--statistics`, `--statistics-only`, `--no-statistics`), while
`pg_upgrade` carries them over by default. Without them, run `ANALYZE` (or `vacuumdb --analyze-only`) after every
restore.

### pg_dump pitfalls

- **Version rules**: a dump made by an older `pg_dump` restores into a newer server, never reliably the other way. To
  move to a newer major version, run the *newer* `pg_dump` against the old server.
- **Large databases**: a single dump of a multi-terabyte database can take many hours, holding back `VACUUM` and
  blocking DDL the whole time. That's when physical backups take over.
- **Check the exit status** in backup scripts. A dump that failed halfway still leaves a file behind.
- **Restoring isn't instant**: building indexes and validating foreign keys often takes longer than loading the data.
  Raise `maintenance_work_mem` for the restore and use `-j`.

## Physical backups with pg_basebackup

A **physical backup** is a copy of the cluster's data directory. You can't just `cp -r` a running data directory:
files change while you copy them, so the copy is inconsistent. What makes it work is the WAL (chapter 18). The backup
starts at a checkpoint, copies the files, and keeps all the WAL written meanwhile. Restoring means copying the files
back and replaying that WAL, which repairs every page that changed during the copy, exactly like crash recovery.

`pg_basebackup` does all of this over a replication connection. The connecting role needs the `REPLICATION` attribute
(the `postgres` superuser has it) and a `replication` line in `pg_hba.conf` (chapter 21; `initdb` adds one for local
connections):

```bash
cd ~/pgpractice
pg_basebackup -D base -c fast -P
```

```text
waiting for checkpoint
32300/32300 kB (100%), 1/1 tablespace
```

`-c fast` asks for an immediate checkpoint instead of waiting for the next scheduled one; `-P` shows progress. By
default the WAL needed for consistency is streamed alongside (`-X stream`), so the backup is self-contained. `-Ft -z`
would write compressed tar files instead of a directory. The result looks like a data directory, plus two extra files:

```bash
cat base/backup_label
```

```text
START WAL LOCATION: 0/2000028 (file 000000010000000000000002)
CHECKPOINT LOCATION: 0/2000080
BACKUP METHOD: streamed
BACKUP FROM: primary
START TIME: 2026-10-11 20:18:52 UTC
LABEL: pg_basebackup base backup
START TIMELINE: 1
```

`backup_label` tells the restored server where to start replaying WAL. `backup_manifest` lists every file with a
checksum, so you can check a backup without restoring it:

```bash
pg_verifybackup base
```

```text
backup successfully verified
```

A base backup on its own restores the cluster to the moment the backup finished. That's like a dump, only faster to
restore. The real power comes from adding the WAL written *after* the backup.

## WAL archiving and point-in-time recovery

The server writes WAL in 16 MB **segment** files in `pg_wal/`, and recycles them after checkpoints. With
**archiving** on, it first hands every completed segment to an **archive command** that copies it somewhere safe.
A base backup plus an unbroken chain of archived segments can then be replayed to *any* moment after the backup: that's
**point-in-time recovery (PITR)**.

```text
  base backup            archived WAL segments                           now
      │  ──────┬──────────┬──────────┬──────────┬──────────┬──────────┬───►
      ▼        000…02     000…03     000…04     000…05     000…06
  restore files, then replay WAL up to any chosen point ──────►│
                                                    "just before the DROP"
```

Your primary has archiving on from the setup:

| Setting | Meaning |
|---|---|
| `archive_mode = on` | run the archiver (changing it needs a restart) |
| `archive_command` | a shell command run for each completed segment: `%p` is the file's path, `%f` its name. It must exit with 0 only if the file is safely stored; on failure the server keeps the segment and retries |
| `archive_timeout` | force a segment switch after this long, so that a quiet server still archives regularly (it bounds your RPO); `0`, the default, means never |

The command used here is a slightly hardened version of the example in the PostgreSQL manual:

```text
cmp -s %p ARCHIVE/%f || (test ! -f ARCHIVE/%f && cp %p ARCHIVE/%f)
```

It refuses to overwrite a different file that's already in the archive (`test ! -f`), and it succeeds when an
identical copy is already there (`cmp -s`). The second part matters more than it seems: after a failover (later in
this chapter) a server may try to archive a segment that another server already archived. With the manual's plain
`test ! -f ... && cp ...`, archiving then fails forever, WAL piles up in `pg_wal/` until the disk is full, and the
server stops.

`cp` is fine for learning, but it doesn't `fsync` the copy and it doesn't leave the machine. In production, use a
tool built for this job, such as **pgBackRest**, **Barman** or **WAL-G**. They archive to object storage or another
server, compress, encrypt, run in parallel, manage retention, verify backups, and drive the restore for you. They all
do what this section does by hand.

`pg_stat_archiver` shows whether archiving works. A growing `failed_count` is an emergency: archiving is broken, and
`pg_wal/` is growing.

```sql
SELECT archived_count, last_archived_wal, last_archived_time, failed_count FROM pg_stat_archiver;
```

### A point-in-time recovery, step by step

You took the base backup above. Now some normal work happens: a customer writes a review. A few seconds later, someone
drops the table in the wrong terminal.

```bash
psql -X -d inkwell -c "INSERT INTO reviews (product_id, customer_id, rating, body)
                       VALUES (2, 1, 5, 'Smooth and wet, my new daily ink.') RETURNING id, created_at"
psql -X -d inkwell -c 'DROP TABLE reviews'
```

```text
 id |          created_at
----+-------------------------------
 10 | 2026-10-11 20:19:00.611079+00
(1 row)

INSERT 0 1
DROP TABLE
```

The new review isn't in any dump, so a dump can't bring it back. The plan: restore the base backup into a new
directory, replay the archived WAL up to a moment between the `INSERT` and the `DROP`, check the result, and copy the
table back to the primary.

The `DROP` sits in the segment currently being written, which isn't archived yet. On a real server you'd also copy
the unarchived files from the damaged server's `pg_wal/`. Here, force a segment switch so it gets archived:

```bash
psql -X -c 'SELECT pg_switch_wal()'
```

Now restore. A restored server is configured with three things: `restore_command` (the opposite of
`archive_command`: copy segment `%f` from the archive to `%p`), a **recovery target**, and an empty file named
`recovery.signal` in the data directory, which tells the server to start in recovery mode:

```bash
cd ~/pgpractice
cp -a base pitr
cat >> pitr/postgresql.conf <<EOF

# --- point-in-time recovery ---
port = 5436
archive_mode = off
restore_command = 'cp $HOME/pgpractice/archive/%f %p'
recovery_target_time = '2026-10-11 20:19:02 UTC'
EOF
touch pitr/recovery.signal
pg_ctl -D pitr -l pitr.log start
```

(Use a time from your own run, between your `INSERT` and your `DROP`.) The copy also inherited the primary's
`archive_*` settings, so `archive_mode = off` stops it from writing into the same archive while you experiment. The log
tells the story:

```text
LOG:  starting backup recovery with redo LSN 0/2000028, checkpoint LSN 0/2000080, on timeline ID 1
LOG:  restored log file "000000010000000000000002" from archive
LOG:  starting point-in-time recovery to 2026-10-11 20:19:02+00
LOG:  redo starts at 0/2000028
LOG:  restored log file "000000010000000000000003" from archive
LOG:  completed backup recovery with redo LSN 0/2000028 and end LSN 0/2000120
LOG:  consistent recovery state reached at 0/2000120
LOG:  database system is ready to accept read-only connections
LOG:  recovery stopping before commit of transaction 916, time 2026-10-11 20:19:03.194512+00
LOG:  pausing at the end of recovery
HINT:  Execute pg_wal_replay_resume() to promote.
```

(The log also shows a few `cp: cannot stat ...` lines: the server asks the archive for files that may or may not
exist, such as timeline history files. They're normal.) Recovery stopped just before the transaction that dropped
the table, and **paused**, because the default `recovery_target_action` is `pause`. The server is read-only, and you
can check whether this is the moment you wanted:

```bash
psql -X -p 5436 -d inkwell -c 'SELECT id, product_id, customer_id, rating FROM reviews ORDER BY id DESC LIMIT 3'
```

```text
 id | product_id | customer_id | rating
----+------------+-------------+--------
 10 |          2 |           1 |      5
  9 |         14 |           6 |      5
  8 |          8 |           5 |      2
(3 rows)
```

The table is back, including review 10. If you had picked a time that was too early, you could stop the server, set a
later target and start again. Recovery only goes forward, so a target that's too late means starting over from the
base backup. Now end recovery:

```bash
psql -X -p 5436 -c 'SELECT pg_wal_replay_resume()'
```

```text
LOG:  redo done at 0/3014C68 system usage: CPU: user: 0.00 s, system: 0.00 s, elapsed: 12.83 s
LOG:  last completed transaction was at log time 2026-10-11 20:19:00.613387+00
LOG:  selected new timeline ID: 2
LOG:  archive recovery complete
LOG:  database system is ready to accept connections
```

**Timelines.** The recovered server is now a writable server with its own history, which diverged from the primary's
at 20:19:02. To keep the two histories apart, it switched to **timeline 2**: its new WAL files are named
`00000002…` instead of `00000001…`, and a small history file `00000002.history` records where the branch happened.
Timelines are what make it safe to recover several times from the same archive, and to fail over between servers.

Finally, copy the table back to the primary and stop the recovered server:

```bash
pg_dump -p 5436 -d inkwell -t reviews | psql -X -q -v ON_ERROR_STOP=1 -d inkwell
pg_ctl -D ~/pgpractice/pitr stop
rm -rf ~/pgpractice/pitr
```

The restored `reviews` comes back with its indexes, constraints and grants. Its identity sequence restarts at 42, not
at 11: sequences are written to the WAL in steps of 32 values ahead of use, and recovery restores the logged value.
Gaps in sequences are normal (chapter 4).

Other ways to choose the stopping point:

| Setting | Stops at |
|---|---|
| `recovery_target_time` | a timestamp |
| `recovery_target_xid` | a transaction ID |
| `recovery_target_lsn` | a WAL position |
| `recovery_target_name` | a named restore point created with `SELECT pg_create_restore_point('before_migration')` |
| `recovery_target = 'immediate'` | the earliest consistent moment: just restore the backup |
| *(none)* | the end of the available WAL: restore everything |

`recovery_target_inclusive` (default `on`) decides whether the target transaction itself is included, and
`recovery_target_action` decides what happens at the target: `pause` (the default), `promote` or `shutdown`. Creating a
named restore point before every risky migration costs nothing and makes the recovery target obvious (exercise 4).

## Incremental backups (PostgreSQL 17+)

A full base backup copies every file, every time. For a large database, that's slow and expensive. Since
PostgreSQL 17, `pg_basebackup` can take **incremental backups**: only the blocks that changed since an earlier backup.
It knows which blocks changed because the **WAL summarizer** process, enabled by `summarize_wal = on`, records the
modified blocks of every WAL range in `pg_wal/summaries/`:

```sql
SELECT * FROM pg_available_wal_summaries() ORDER BY start_lsn;
```

An incremental backup is based on the manifest of the previous backup:

```bash
cd ~/pgpractice
psql -X -d inkwell -c 'UPDATE products SET stock = stock + 10 WHERE id = 1'
pg_basebackup -D incr1 --incremental=base/backup_manifest -c fast
du -sh base/base incr1/base
```

```text
31M	base/base
7.9M	incr1/base
```

Even for this tiny database, the data files shrank to a quarter; on a big database with a small daily change rate, an
incremental backup can be a few percent of a full one. An incremental backup can't be started directly. Before a
restore, `pg_combinebackup` merges a full backup and its chain of incrementals (oldest first) into a normal, complete
backup:

```bash
pg_combinebackup base incr1 -o combined
pg_verifybackup combined
```

```text
backup successfully verified
```

A typical schedule: a full backup weekly, an incremental daily, plus continuous WAL archiving for everything in
between. Backup tools such as pgBackRest have had their own incremental backups for years; the built-in ones make the
technique available with just the core tools.

## Streaming replication

A **standby** (or replica) is a server that continuously replays the primary's WAL. Instead of waiting for archived
segments, it connects to the primary and receives WAL as it's written: **streaming replication**.

```text
  primary                                       standby
  ┌──────────────┐  WAL records, continuously  ┌──────────────┐
  │ backends     │ ──► walsender ════════════► │ walreceiver  │
  │ write WAL    │                             │ startup proc │──► replays into its own copy
  └──────────────┘ ◄─── feedback: positions ── └──────────────┘
                                               read-only queries (hot standby)
```

A standby is a byte-for-byte copy of the whole cluster: same databases, same roles, same everything. It's useful for:

- **high availability**: if the primary dies, promote the standby and carry on, losing at most the last few
  transactions (none with synchronous replication);
- **read scaling**: send reports and read-only traffic to standbys (hot standby);
- **base backups** without loading the primary (`pg_basebackup` works against a standby too).

It's **not a backup**: a `DROP TABLE` reaches the standby within milliseconds.

### Creating a standby

Create a role for replication; never use a superuser for it in production:

```sql
CREATE ROLE replicator WITH REPLICATION LOGIN PASSWORD 'repl-dev-password';
```

On a real network, the primary's `pg_hba.conf` needs a line for it, such as
`hostssl replication replicator 10.0.0.0/24 scram-sha-256`; the practice cluster's trust line for local replication
connections already covers it. Then one command creates the standby:

```bash
cd ~/pgpractice
pg_basebackup -D standby -U replicator -R -C -S standby1 -c fast
```

- `-R` writes the replication configuration into the new directory: `primary_conninfo` in `postgresql.auto.conf`,
  and an empty `standby.signal` file, which tells the server to start as a standby;
- `-C -S standby1` creates a **replication slot** named `standby1` on the primary (explained below) and configures the
  standby to use it.

```bash
cat standby/postgresql.auto.conf
```

```text
# Do not edit this file manually!
# It will be overwritten by the ALTER SYSTEM command.
primary_conninfo = 'user=replicator passfile=''/home/you/.pgpass'' channel_binding=prefer host=''/tmp'' port=5433 sslmode=prefer sslnegotiation=postgres sslcompression=0 sslcertmode=allow sslsni=1 ssl_min_protocol_version=TLSv1.2 gssencmode=prefer krbsrvname=postgres gssdelegation=0 target_session_attrs=any load_balance_hosts=disable'
primary_slot_name = 'standby1'
```

The standby inherited the primary's `postgresql.conf`, including `port = 5433`. Give it its own port and a name (it
shows up as `application_name` on the primary). Also give it a `restore_command`: if it ever falls too far behind
for streaming, it can fetch old WAL from the archive. (It also inherited `archive_mode = on`, which does nothing on
a standby; only `archive_mode = always` archives there.)

```bash
cat >> standby/postgresql.conf <<EOF
port = 5434
cluster_name = 'standby1'
restore_command = 'cp $HOME/pgpractice/archive/%f %p'
EOF
pg_ctl -D standby -l standby.log start
tail -2 standby.log
```

```text
LOG:  database system is ready to accept read-only connections
LOG:  started streaming WAL from primary at 0/8000000 on timeline 1
```

### Watching replication

On the primary, `pg_stat_replication` has one row per connected standby:

```sql
SELECT application_name, state, sent_lsn, write_lsn, flush_lsn, replay_lsn,
       write_lag, flush_lag, replay_lag, sync_state
FROM pg_stat_replication \gx
```

```text
-[ RECORD 1 ]----+----------------
application_name | standby1
state            | streaming
sent_lsn         | 0/8000060
write_lsn        | 0/8000060
flush_lsn        | 0/8000060
replay_lsn       | 0/8000060
write_lag        | 00:00:00.090112
flush_lag        | 00:00:00.090418
replay_lag       | 00:00:00.090486
sync_state       | async
```

The four LSNs follow each change through the standby: sent over the network, written to the standby's WAL file,
flushed to its disk, replayed into its data files. `*_lag` columns measure how long that took for recent changes.
On the standby, `pg_stat_wal_receiver` shows the other end, and `pg_is_in_recovery()` returns `true`.

For monitoring, two measurements matter:

```sql
-- on the primary: how far behind is each standby, in bytes and time?
SELECT application_name,
       pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) AS bytes_behind,
       replay_lag
FROM pg_stat_replication;
```

```text
 application_name | bytes_behind |   replay_lag
------------------+--------------+-----------------
 standby1         |            0 | 00:00:00.000049
(1 row)
```

```sql
-- on the standby: when was the last replayed transaction committed?
SELECT now() - pg_last_xact_replay_timestamp() AS replay_delay;
```

```text
  replay_delay
-----------------
 00:00:19.014537
(1 row)
```

The second one is a classic trap: it shows 19 seconds although the standby is fully caught up. It measures the age of
the last replayed *transaction*, and on a quiet primary there simply wasn't one for 19 seconds. Alert on
`bytes_behind` or `replay_lag` from the primary, or compare against a heartbeat table that the primary updates every
few seconds.

The standby is read-only:

```bash
psql -X -p 5434 -d inkwell -c 'UPDATE products SET stock = 0 WHERE id = 1'
```

```text
ERROR:  cannot execute UPDATE in a read-only transaction
```

### Replication slots

Without a slot, the primary deletes WAL after checkpoints, whether or not a standby has received it. A standby that
was down for a while then finds that the WAL it needs is gone, and must be rebuilt from a new base backup. A
**replication slot** makes the primary keep all WAL that the slot's consumer hasn't confirmed yet.

That protection has a price: **a slot whose consumer is gone retains WAL forever**, until `pg_wal/` fills the disk and
the primary stops. Abandoned slots are one of the most common causes of PostgreSQL outages. They also hold back
`VACUUM` (chapter 18). Watch them:

```sql
SELECT slot_name, slot_type, active, restart_lsn, wal_status, safe_wal_size, inactive_since
FROM pg_replication_slots;
```

```text
 slot_name | slot_type | active | restart_lsn | wal_status | safe_wal_size | inactive_since
-----------+-----------+--------+-------------+------------+---------------+----------------
 standby1  | physical  | t      | 0/8000060   | reserved   |               |
(1 row)
```

Two settings put a limit on the damage:

- **`max_slot_wal_keep_size`** caps the WAL a slot may retain. A slot that falls further behind is **invalidated**
  (`wal_status = 'lost'`), and its consumer must be rebuilt, but the primary survives.
- **`idle_replication_slot_timeout`** (18+) invalidates slots that have been inactive for longer than the given time,
  at the next checkpoint.

See it happen. Stop the standby, cap the slots at 100 MB, and write a lot of WAL:

```bash
pg_ctl -D ~/pgpractice/standby stop
psql -X -c "ALTER SYSTEM SET max_slot_wal_keep_size = '100MB'" -c 'SELECT pg_reload_conf()'
for i in 1 2 3; do
  psql -X -q -d inkwell -c 'CREATE TABLE junk AS SELECT g, md5(g::text) FROM generate_series(1, 500000) g' \
       -c 'DROP TABLE junk' -c 'CHECKPOINT'
  psql -X -c 'SELECT slot_name, active, wal_status, pg_size_pretty(safe_wal_size) AS safe_wal_size FROM pg_replication_slots'
done
```

```text
 slot_name | active | wal_status | safe_wal_size
-----------+--------+------------+---------------
 standby1  | f      | reserved   | 66 MB
(1 row)

 slot_name | active | wal_status | safe_wal_size
-----------+--------+------------+---------------
 standby1  | f      | reserved   | 20 MB
(1 row)

 slot_name | active | wal_status | safe_wal_size
-----------+--------+------------+---------------
 standby1  | f      | lost       |
(1 row)
```

`safe_wal_size` counts down how much more WAL can be written before the slot is lost. Start the standby again, and its
log shows that streaming fails:

```bash
pg_ctl -D ~/pgpractice/standby -l ~/pgpractice/standby.log start
```

```text
FATAL:  could not start WAL streaming: ERROR:  can no longer access replication slot "standby1"
	DETAIL:  This replication slot has been invalidated due to "wal_removed".
LOG:  waiting for WAL to become available at 0/90001F8
```

Thanks to its `restore_command`, the standby can still replay the missing WAL from the archive. Drop the lost slot and
create a new one with the same name, and it switches back to streaming:

```sql
SELECT pg_drop_replication_slot('standby1');
SELECT pg_create_physical_replication_slot('standby1');
```

Without an archive to fall back on, the standby would have to be rebuilt with a new `pg_basebackup`. In production,
set `max_slot_wal_keep_size` to something your disk can hold, alert on inactive slots and on `wal_status` other than
`reserved`, and drop slots you no longer need.

### Hot standby and query conflicts

Read-only queries on a standby can conflict with the WAL it replays. A long report on the standby may need old row
versions that `VACUUM` on the primary has already removed; when that cleanup is replayed, the standby must either
wait or cancel the query. It waits up to `max_standby_streaming_delay` (30 s by default), then cancels:

```text
FATAL:  terminating connection due to conflict with recovery
DETAIL:  User query might have needed to see row versions that must be removed.
HINT:  In a moment you should be able to reconnect to the database and repeat your command.
```

(A running query gets `ERROR: canceling statement due to conflict with recovery`; a session idle in a transaction is
terminated, as here.) `pg_stat_database_conflicts` on the standby counts these. Your options:

| Setting (on the standby) | Effect | Cost |
|---|---|---|
| `max_standby_streaming_delay` | allow replay to wait longer for queries | the standby falls behind while it waits |
| `hot_standby_feedback = on` | the standby tells the primary which row versions it still needs, so `VACUUM` keeps them | long standby queries cause bloat on the primary, exactly like long transactions there |

A common setup: a standby for high availability with the defaults (it must stay current), and a separate reporting
standby with a long delay or feedback on.

One more operational rule, promised in chapter 11: a physical standby uses the primary's data files as they are, and
that includes the order of entries in text indexes. **Run primary and standbys on the same operating system and C
library (or ICU) version.** A standby on a different glibc may sort strings differently and silently return wrong
results from those indexes.

### Synchronous replication

By default, replication is **asynchronous**: `COMMIT` returns as soon as the primary has the WAL on its own disk. If
the primary dies, the standby may be missing the last few transactions. **Synchronous** replication makes `COMMIT`
wait until a standby confirms:

```sql
ALTER SYSTEM SET synchronous_standby_names = 'standby1';
SELECT pg_reload_conf();
SELECT application_name, state, sync_state FROM pg_stat_replication;
```

```text
 application_name |   state   | sync_state
------------------+-----------+------------
 standby1         | streaming | sync
(1 row)
```

`synchronous_standby_names` lists the `application_name`s of the standbys that may confirm; `FIRST 1 (a, b)` and
`ANY 2 (a, b, c)` choose priority or quorum among several. How much the standby must have done before it confirms is
set by `synchronous_commit`, per server, per role, or even per transaction:

| `synchronous_commit` | `COMMIT` returns when | Survives |
|---|---|---|
| `off` | WAL is in the primary's memory | nothing for the last moments (no corruption, though: chapter 18) |
| `local` | WAL is on the primary's disk | primary crash |
| `remote_write` | the standby has written it (not yet flushed) | primary loss, unless the standby's OS crashes too |
| `on` (default) | the standby has flushed it to disk | loss of the primary |
| `remote_apply` | the standby has replayed it | loss of the primary, and reads on the standby see the change at once |

(Without a synchronous standby, `on`, `remote_write` and `remote_apply` all behave like `local`.)

The catch: **if no synchronous standby is available, commits wait forever.** Stop the standby and try to write:

```bash
pg_ctl -D ~/pgpractice/standby stop
psql -X -d inkwell -c "SET statement_timeout = '3s'" -c 'UPDATE products SET stock = stock + 1 WHERE id = 1'
```

The `UPDATE` hangs, and `statement_timeout` doesn't help. In another terminal, the session is visible waiting for
`SyncRep`, and can be cancelled:

```sql
SELECT pid, wait_event_type, wait_event, query FROM pg_stat_activity WHERE wait_event = 'SyncRep';
SELECT pg_cancel_backend(pid) FROM pg_stat_activity WHERE wait_event = 'SyncRep';
```

```text
  pid  | wait_event_type | wait_event |                       query
-------+-----------------+------------+----------------------------------------------------
 10978 | IPC             | SyncRep    | UPDATE products SET stock = stock + 1 WHERE id = 1
(1 row)
```

The first terminal then shows:

```text
WARNING:  canceling wait for synchronous replication due to user request
DETAIL:  The transaction has already committed locally, but might not have been replicated to the standby.
UPDATE 1
```

Read the `DETAIL` carefully: the transaction is **committed** on the primary. Cancelling only stops the waiting. So
synchronous replication needs at least two standbys that can confirm (`ANY 1 (s1, s2)`), and it costs one network
round trip per commit. Many systems use it only for the transactions that need it, with
`SET LOCAL synchronous_commit = on` against a default of `local`. Switch back to asynchronous for the rest of the
chapter, and start the standby again:

```bash
psql -X -c 'ALTER SYSTEM RESET synchronous_standby_names' -c 'SELECT pg_reload_conf()'
pg_ctl -D ~/pgpractice/standby -l ~/pgpractice/standby.log start
```

### Failover, split brain and pg_rewind

When the primary fails, you **promote** a standby: it finishes replaying what it has, switches to a new timeline and
starts accepting writes.

```bash
psql -X -p 5434 -c 'SELECT pg_promote()'      # or: pg_ctl -D ~/pgpractice/standby promote
```

```text
 pg_promote
------------
 t
(1 row)
```

```text
LOG:  selected new timeline ID: 2
LOG:  archive recovery complete
LOG:  database system is ready to accept connections
```

The hard part of failover is not this command. It's making sure that **the old primary never accepts writes again**,
and that every client switches to the new one. Here, the "failed" primary is actually still running, and nothing
stops an application from writing to both:

```bash
psql -X -p 5434 -d inkwell -c 'UPDATE products SET price = 9.99 WHERE id = 3'    # new primary
psql -X -p 5433 -d inkwell -c 'UPDATE products SET price = 19.99 WHERE id = 3'   # old primary
```

Both succeed. That's **split brain**: two servers with diverging histories, and no way to merge them. One side's
writes will be thrown away. Production setups prevent it with a cluster manager (**Patroni** is the most common;
others are pg_auto_failover and repmgr) that uses a consensus store or a witness to elect exactly one primary, and
fences the old one: it stops it, or blocks its network, before promoting a standby.

To turn the old primary into a standby of the new one, its history must first be rewound to the point where the
timelines split. **`pg_rewind`** does that by copying back only the blocks that changed since the split, which is much
faster than a new base backup. It needs data checksums (on by default since 18, chapter 18) or `wal_log_hints = on`.
Stop the old primary cleanly first:

```bash
cd ~/pgpractice
pg_ctl -D primary stop
pg_rewind --target-pgdata=primary --source-server='host=/tmp port=5434 user=postgres dbname=postgres' \
          --write-recovery-conf --progress
```

```text
pg_rewind: connected to server
pg_rewind: servers diverged at WAL location 0/8001790 on timeline 1
pg_rewind: rewinding from last common checkpoint at 0/7000080 on timeline 1
pg_rewind: reading source file list
pg_rewind: reading target file list
pg_rewind: reading WAL in target
pg_rewind: need to copy 52 MB (total source directory size is 79 MB)
54123/54123 kB (100%) copied
pg_rewind: creating backup label and updating control file
pg_rewind: syncing target data directory
pg_rewind: Done!
```

**Check the configuration before you start it.** `pg_rewind` copies the configuration files from the source too, so
the old primary now has the new primary's `port = 5434`, `cluster_name = 'standby1'` and `primary_slot_name`.
`--write-recovery-conf` added `standby.signal` and a `primary_conninfo` pointing at port 5434. Fix the rest, create a
slot for it on the new primary, and start it:

```bash
cat >> primary/postgresql.conf <<'EOF'
port = 5433
cluster_name = 'old_primary'
EOF
sed -i.bak "s/^primary_slot_name = .*/primary_slot_name = 'old_primary'/" primary/postgresql.auto.conf
psql -X -p 5434 -c "SELECT pg_create_physical_replication_slot('old_primary')"
pg_ctl -D primary -l primary.log start
psql -X -p 5434 -c 'SELECT application_name, state, sync_state FROM pg_stat_replication'
psql -X -p 5433 -d inkwell -c 'SELECT price FROM products WHERE id = 3'
```

```text
 application_name |   state   | sync_state
------------------+-----------+------------
 old_primary      | streaming | async
(1 row)

 price
-------
  9.99
(1 row)
```

The roles are reversed, and the split-brain write of 19.99 is gone for good. In a real incident, you'd now have to
find out what those lost writes were.

**Switchover** is a planned failover, for maintenance or to move the primary back. It loses nothing and needs no
`pg_rewind`, because a clean shutdown of the primary waits until its standbys have received all of its WAL:

```bash
pg_ctl -D ~/pgpractice/standby stop                 # 1. stop the current primary cleanly
pg_ctl -D ~/pgpractice/primary promote              # 2. promote its standby
psql -X -c "SELECT pg_create_physical_replication_slot('standby1')"
touch ~/pgpractice/standby/standby.signal           # 3. turn the old primary into a standby
cat >> ~/pgpractice/standby/postgresql.auto.conf <<'EOF'
primary_conninfo = 'host=/tmp port=5433 user=replicator'
primary_slot_name = 'standby1'
EOF
pg_ctl -D ~/pgpractice/standby -l ~/pgpractice/standby.log start
```

```text
LOG:  new target timeline is 3
LOG:  restarted WAL streaming at 0/9000000 on timeline 3
```

The practice clusters are back where they started: the primary on port 5433 (now on timeline 3), streaming to the
standby on 5434. One leftover needs cleaning up: the `old_primary` slot that you created on port 5434 while it was the
primary. Nothing uses it any more, but an unused slot retains WAL on a standby too:

```bash
psql -X -p 5434 -c "SELECT pg_drop_replication_slot('old_primary')"
```

(Appending to `postgresql.auto.conf` by hand is acceptable for a stopped server, as here; the last
line for a setting wins. On a running server, use `ALTER SYSTEM`.)

**How clients find the primary**: libpq-based clients, which includes `psql`, PHP's PDO and many others, accept several
hosts and a required role:
`postgresql://db1,db2/inkwell?target_session_attrs=read-write` tries each host until it finds one that accepts writes.
Node's `pg` doesn't support multiple hosts, so Node applications usually connect through a virtual IP, a DNS name the
cluster manager updates, or a proxy such as HAProxy or PgBouncer (chapter 22).

## Logical replication

Physical replication copies the whole cluster, byte for byte, to a server of the same major version. **Logical
replication** sends *row changes* (this row was inserted, that one updated) for chosen tables, decoded from the WAL.
That makes things possible that physical replication can't do:

- replicate some tables, some rows or some columns, not the whole cluster;
- replicate between different major versions, which allows **upgrades with almost no downtime**;
- feed a reporting database that has its own extra tables and indexes, and accepts writes;
- consolidate several databases into one.

The publisher declares a **publication** (which tables, which operations); the subscriber creates a **subscription**
to it. The subscriber first copies the existing data, then applies changes as they happen, through a logical
replication slot on the publisher.

### Setting it up

Logical decoding needs more information in the WAL, so the publisher needs `wal_level = logical`. Changing it needs a
restart, as `pending_restart` points out:

```sql
ALTER SYSTEM SET wal_level = logical;
SELECT pg_reload_conf();
SELECT name, setting, pending_restart FROM pg_settings WHERE name = 'wal_level';
```

```text
   name    | setting | pending_restart
-----------+---------+-----------------
 wal_level | replica | t
(1 row)
```

```bash
pg_ctl -D ~/pgpractice/primary -l ~/pgpractice/primary.log restart
```

Now publish the catalog: the reporting team wants products and categories in their own database. The replication role
needs `SELECT` on the published tables for the initial copy, and `CONNECT` on the database, because chapter 21's
setup revoked it from `PUBLIC`:

```sql
-- on the primary, in inkwell
CREATE PUBLICATION catalog FOR TABLE categories, products;
GRANT SELECT ON categories, products TO replicator;
GRANT CONNECT ON DATABASE inkwell TO replicator;
SELECT * FROM pg_publication_tables;
```

```text
 pubname | schemaname | tablename  |                                      attnames                                       | rowfilter
---------+------------+------------+-------------------------------------------------------------------------------------+-----------
 catalog | public     | categories | {id,parent_id,name,slug}                                                            |
 catalog | public     | products   | {id,sku,name,description,category_id,price,stock,attributes,tags,active,created_at} |
(2 rows)
```

Notice that `products.search`, the generated column from chapter 13, is missing from `attnames`. Generated columns
aren't published by default; the subscriber computes its own.

**The schema isn't replicated**, only rows. Create the tables on the subscriber first; a schema-only dump is the
easiest way. `--no-privileges` leaves out grants to roles the reporting database doesn't need:

```bash
createdb -p 5435 reporting
pg_dump -d inkwell --schema-only --no-privileges -t categories -t products | psql -X -q -p 5435 -d reporting
```

Then subscribe. Creating a subscription requires superuser or membership in `pg_create_subscription` (16+), plus
`CREATE` on the database:

```sql
-- on the second cluster (port 5435), in reporting
CREATE SUBSCRIPTION catalog_sub
    CONNECTION 'host=/tmp port=5433 dbname=inkwell user=replicator'
    PUBLICATION catalog;
```

```text
NOTICE:  created replication slot "catalog_sub" on publisher
CREATE SUBSCRIPTION
```

A moment later, both tables are copied (`r` = ready) and changes flow:

```sql
SELECT srrelid::regclass, srsubstate FROM pg_subscription_rel;
SELECT subname, worker_type, received_lsn, latest_end_lsn, last_msg_receipt_time FROM pg_stat_subscription;
```

```text
  srrelid   | srsubstate
------------+------------
 categories | r
 products   | r
(2 rows)

   subname   | worker_type | received_lsn | latest_end_lsn |     last_msg_receipt_time
-------------+-------------+--------------+----------------+-------------------------------
 catalog_sub | apply       | 0/1201C838   | 0/1201C838     | 2026-10-11 20:25:52.556348+00
(1 row)
```

```bash
psql -X -d inkwell -c 'UPDATE products SET price = 24.50 WHERE id = 3'
psql -X -p 5435 -d reporting -c 'SELECT id, name, price FROM products WHERE id = 3'
```

```text
UPDATE 1
 id |        name        | price
----+--------------------+-------
  3 | Everyday Ballpoint | 24.50
(1 row)
```

On the publisher, the subscription shows up in `pg_replication_slots` as a slot with `slot_type = 'logical'` and
`plugin = 'pgoutput'`, and in `pg_stat_replication` as a walsender named `catalog_sub`. Everything about slots above
applies: a subscriber that's gone for good leaves a slot that retains WAL. `DROP SUBSCRIPTION` on the subscriber
drops the slot on the publisher too.

### What goes wrong

**Schema changes.** DDL isn't replicated. Add a column on the publisher and use it:

```bash
psql -X -d inkwell -c 'ALTER TABLE products ADD COLUMN discontinued_on date' \
                   -c "UPDATE products SET discontinued_on = '2026-12-31' WHERE id = 3"
```

The subscriber's log now repeats, every few seconds:

```text
ERROR:  logical replication target relation "public.products" is missing replicated column: "discontinued_on"
LOG:  logical replication apply worker for subscription "catalog_sub" has started
```

Replication is stuck, and changes pile up on the publisher's slot. Add the column on the subscriber and it continues
where it stopped. The safe order for schema changes is: **additive changes (new tables, new columns) on the subscriber
first**, then on the publisher; removals the other way round.

**Conflicts.** The subscriber's tables are ordinary tables, and nothing stops someone from writing to them. Insert a
row there with an `id` the publisher uses later, and the next replicated insert fails:

```text
ERROR:  conflict detected on relation "public.products": conflict=insert_exists
DETAIL:  Key already exists in unique index "products_pkey", modified in transaction 982.
```

The apply worker retries forever, and `pg_stat_subscription_stats` counts the errors and, since PostgreSQL 18, the
conflicts by type (`confl_insert_exists`, `confl_update_missing`, …). Fix the data on the subscriber (here: delete the
local row) and replication resumes. As a last resort, `ALTER SUBSCRIPTION ... SKIP (lsn = ...)` skips the failing
transaction, losing it on the subscriber. Better: don't let anyone write to replicated tables.

**Replica identity.** To apply an `UPDATE` or `DELETE`, the subscriber must find the row, so the publisher sends its
**replica identity**: by default the primary key. A table without a primary key can't publish updates or deletes, and
the error appears **on the publisher**, for the application:

```sql
CREATE TABLE price_history (product_id bigint, price numeric(10, 2), changed_at timestamptz DEFAULT now());
ALTER PUBLICATION catalog ADD TABLE price_history;
INSERT INTO price_history (product_id, price) VALUES (3, 24.50);
UPDATE price_history SET price = 23.50;
```

```text
CREATE TABLE
ALTER PUBLICATION
INSERT 0 1
ERROR:  cannot update table "price_history" because it does not have a replica identity and publishes updates
HINT:  To enable updating the table, set REPLICA IDENTITY using ALTER TABLE.
```

Add a primary key, or use `REPLICA IDENTITY USING INDEX` (a unique index on `NOT NULL` columns), or as a last resort
`REPLICA IDENTITY FULL`, which logs the whole old row and makes the subscriber search for it, which is slow without a
suitable index there.

The `INSERT` did go out, and the subscriber is stuck on it, because the table doesn't exist there:

```text
ERROR:  logical replication target relation "public.price_history" does not exist
```

Adding a table to a publication takes four steps: give it a replica identity, grant the replication role `SELECT` on
it (for the initial copy), create it on the subscriber, and refresh the subscription so that it starts copying:

```bash
psql -X -d inkwell -c 'ALTER TABLE price_history ADD COLUMN id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY' \
                   -c 'GRANT SELECT ON price_history TO replicator'
pg_dump -d inkwell --schema-only --no-privileges -t price_history | psql -X -q -p 5435 -d reporting
psql -X -p 5435 -d reporting -c 'ALTER SUBSCRIPTION catalog_sub REFRESH PUBLICATION'
```

A moment later `pg_subscription_rel` lists `price_history` as ready (`r`), and its rows have arrived. Doing the
subscriber side first avoids the stuck apply worker altogether.

**What logical replication doesn't copy** (as of PostgreSQL 18):

- **DDL**: schema changes, as above.
- **Sequence values**: rows with identity values arrive, but the subscriber's sequences stay where they were. Before
  you switch applications over to a subscriber, set them (for example from `pg_dump --data-only` of the sequences, or
  with `setval(..., max(id))`).
- **Large objects**, views and materialized views (only tables, including partitioned tables).
- `TRUNCATE` is replicated, but fails if the subscriber's table is referenced by a foreign key from a table outside
  the subscription.

### Filtering rows and columns (15+)

A publication can send only some columns, and only rows that match a `WHERE` condition:

```sql
CREATE PUBLICATION storefront FOR TABLE products (id, sku, name, price, active) WHERE (active);
```

Row filters have a sharp edge for publications that publish updates or deletes: the filter's columns must be part of
the replica identity, or **updates on the publisher fail**. Exercise 6 runs into it and fixes it.

PostgreSQL 18 can also publish stored generated columns, with `publish_generated_columns = stored`, for subscribers
whose column is a plain one:

> **Just a taste — you don't need to learn or fully understand this now.** Publishing generated columns (18+) matters only when the subscriber can't compute them itself.

```sql
CREATE PUBLICATION search_pub FOR TABLE products (id, name, search) WITH (publish_generated_columns = stored);
```

### Building a logical replica from a physical standby (17+)

For a big database, the initial copy of `CREATE SUBSCRIPTION` can take days. `pg_createsubscriber` (17+) instead turns
a stopped physical standby into a logical subscriber: it creates the publications and slots, promotes the standby at
the right point, and sets up the subscriptions, without copying any data.

> **Just a taste — you don't need to learn or fully understand this now.** The usual tool for near-zero-downtime major upgrades of large databases; read its manual page before you use it.

```bash
pg_createsubscriber -D ~/pgpractice/standby -P 'host=/tmp port=5433 dbname=inkwell' -d inkwell --dry-run
```

## Upgrading PostgreSQL

**Minor releases** (18.5 → 18.6) only fix bugs and security issues, and keep the on-disk format (chapter 1). Install
the new packages and restart; on a replicated setup, update the standbys first, then switch over and update the old
primary. Read the release notes anyway: occasionally a fix requires an extra step, such as reindexing a certain kind
of index. Staying on the latest minor release is the single cheapest security measure you have.

**Major releases** (17 → 18) may change the on-disk format, so the new server can't simply open the old data
directory. Three ways to upgrade:

| Method | Downtime | Notes |
|---|---|---|
| dump and restore | long: the full dump plus the full restore | simplest, also changes platform or architecture; use the *new* version's `pg_dump` |
| `pg_upgrade` | minutes, mostly independent of size (with `--link`, `--clone` or `--swap`) | the standard method |
| logical replication | seconds: replicate into a new-version cluster, then switch over | most work; mind sequences and DDL; `pg_createsubscriber` helps |

### pg_upgrade

`pg_upgrade` creates the system catalogs for the new version (by dumping and restoring only the *schema*), then moves
the user data files over unchanged. You need both versions' binaries installed and an empty new cluster created
with `initdb` and the same locale and checksum settings. Its transfer modes:

| Mode | How data files reach the new cluster | Old cluster afterwards |
|---|---|---|
| `--copy` (default) | copied | untouched, still usable; needs double the disk space |
| `--link` | hard links: seconds | unusable as soon as the new cluster has started |
| `--clone` | reflink copies (Btrfs, XFS, APFS): fast, no extra space | untouched |
| `--copy-file-range` | `copy_file_range()`: lets the file system share blocks | untouched |
| `--swap` (18+) | the data directories are moved over: fastest with many tables | unusable once the transfer has started |

Always run it with `--check` first; that works while the old server is still running. You can try it on the practice
clusters: PostgreSQL 18 can "upgrade" an 18 cluster, which is enough to see every step. Create a new empty cluster for
the second practice server:

```bash
cd ~/pgpractice
initdb -D second-18 -U postgres --auth=trust > /dev/null
BIN=$(pg_config --bindir)
pg_upgrade -b "$BIN" -B "$BIN" -d second -D second-18 -p 5435 -P 5437 -s /tmp --check
```

```text
Performing Consistency Checks on Old Live Server
------------------------------------------------
Checking cluster versions                                     ok
Checking database connection settings                         ok
Checking database user is the install user                    ok
Checking for prepared transactions                            ok
Checking for contrib/isn with bigint-passing mismatch         ok
Checking for valid logical replication slots                  ok
Checking for subscription state                               ok
Checking data type usage                                      ok
Checking for objects affected by Unicode update               ok
Checking for not-null constraint inconsistencies              ok
Checking for presence of required libraries                   ok
Checking database user is the install user                    ok
Checking for prepared transactions                            ok
Checking for new cluster tablespace directories               ok
Checking for new cluster configuration for subscriptions      ok

*Clusters are compatible*
```

(`-b`/`-B` are the old and new binary directories, `-d`/`-D` the data directories, `-p`/`-P` the ports `pg_upgrade`
starts the servers on, and `-s` the socket directory.) A real 17 → 18 upgrade looks the same, with two different
binary directories. Now the upgrade itself, with the old server stopped:

```bash
pg_ctl -D second stop
pg_upgrade -b "$BIN" -B "$BIN" -d second -D second-18 -p 5435 -P 5437 -s /tmp --swap
```

```text
Performing Consistency Checks
-----------------------------
Checking cluster versions                                     ok
...
Performing Upgrade
------------------
Setting locale and encoding for new cluster                   ok
Analyzing all rows in the new cluster                         ok
Freezing all rows in the new cluster                          ok
...
Restoring global objects in the new cluster                   ok
Restoring database schemas in the new cluster                 ok
Adding ".old" suffix to old "global/pg_control"               ok

Because "swap" mode was used, the old cluster can no longer be
safely started.
Swapping data directories                                     ok
...
Upgrade Complete
----------------
Some statistics are not transferred by pg_upgrade.
Once you start the new server, consider running these two commands:
    /usr/lib/postgresql/18/bin/vacuumdb --all --analyze-in-stages --missing-stats-only
    /usr/lib/postgresql/18/bin/vacuumdb --all --analyze-only
Running this script will delete the old cluster's data files:
    ./delete_old_cluster.sh
```

The steps that remain are yours:

1. **Configuration**: `pg_upgrade` doesn't touch configuration files. The new cluster has the fresh `postgresql.conf`
   and `pg_hba.conf` from `initdb` (here even port 5432). Carry over your settings, then start it:

   ```bash
   cat >> second-18/postgresql.conf <<'EOF'
   port = 5435
   listen_addresses = ''
   unix_socket_directories = '/tmp'
   EOF
   pg_ctl -D second-18 -l second.log start
   ```

2. **Statistics**: since PostgreSQL 18, `pg_upgrade` transfers most planner statistics, so the new server plans well
   from the first query. Extended statistics (`CREATE STATISTICS`, chapter 16) and the cumulative activity counters
   aren't transferred; the first command quickly fills in what's missing, the second refreshes the counters that
   drive autovacuum:

   ```bash
   vacuumdb -p 5435 --all --analyze-in-stages --missing-stats-only
   vacuumdb -p 5435 --all --analyze-only
   ```

   Before 18, nothing was transferred, and the first hours after an upgrade often had bad plans until `ANALYZE`
   finished.
3. **Extensions**: update the extensions in each database (`ALTER EXTENSION name UPDATE`); `pg_upgrade` writes a
   script for it when needed.
4. **Standbys** must be rebuilt (or upgraded with the `rsync` procedure in the manual), because they are physical
   copies of the old version.
5. Once you're sure, delete the old cluster with `delete_old_cluster.sh`.

`pg_upgrade` also kept the second cluster's subscription and its state (since 17), so after the upgrade `reporting`
keeps receiving changes from the primary. Rehearse every major upgrade on a copy of production first, including the
application's test suite: new versions occasionally change behaviour, and the release notes' "Migration" section says
how.

## Configuration

### Where settings come from

The server reads its settings, in increasing priority, from:

1. built-in defaults;
2. `postgresql.conf` in the data directory (Debian and Ubuntu keep it in `/etc/postgresql/18/main/`), plus any files
   it pulls in with `include`, `include_if_exists` or `include_dir`;
3. `postgresql.auto.conf`, written by `ALTER SYSTEM`;
4. command-line options of `postgres` (`-c name=value`);
5. per-database and per-role settings (`ALTER DATABASE ... SET`, `ALTER ROLE ... SET`, chapter 21), applied at login;
6. the session's own `SET` and `SET LOCAL`.

Within a file, the last line for a setting wins. `SHOW` tells you where a file is:

```sql
SHOW config_file;
```

```text
                 config_file
----------------------------------------------
 /home/you/pgpractice/primary/postgresql.conf
(1 row)
```

`SHOW hba_file` and `SHOW data_directory` work the same way.

### pg_settings: contexts, reloads and restarts

`pg_settings` has a row for every setting with its current value, unit, where it came from, and its **context**:
when the setting can be changed.

```sql
SELECT context, count(*) FROM pg_settings GROUP BY context ORDER BY count(*) DESC;
```

```text
      context      | count
-------------------+-------
 user              |   151
 sighup            |   104
 postmaster        |    69
 superuser         |    49
 internal          |    20
 superuser-backend |     4
 backend           |     2
(7 rows)
```

| Context | Changed by | Examples |
|---|---|---|
| `internal` | never; fixed when the server was built or the cluster created | `block_size`, `data_checksums` |
| `postmaster` | a **restart** | `shared_buffers`, `max_connections`, `wal_level`, `archive_mode`, `port` |
| `sighup` | a **reload**: `SELECT pg_reload_conf()` or `pg_ctl reload` | `archive_command`, `max_wal_size`, `log_*` settings, `pg_hba.conf` |
| `superuser-backend`, `backend` | a reload, but only for new sessions | `log_connections` |
| `superuser` | `SET` by a superuser (or a role granted `SET` on it) | `log_min_duration_statement`, `track_io_timing` |
| `user` | `SET` by anyone, in any session | `work_mem`, `statement_timeout`, `search_path` |

```sql
SELECT name, setting, unit, context, source
FROM pg_settings
WHERE name IN ('shared_buffers', 'work_mem', 'max_wal_size', 'archive_mode', 'wal_level', 'log_min_duration_statement');
```

```text
            name            | setting | unit |  context   |       source
----------------------------+---------+------+------------+--------------------
 archive_mode               | on      |      | postmaster | configuration file
 log_min_duration_statement | -1      | ms   | superuser  | default
 max_wal_size               | 1024    | MB   | sighup     | configuration file
 shared_buffers             | 16384   | 8kB  | postmaster | configuration file
 wal_level                  | logical |      | postmaster | configuration file
 work_mem                   | 4096    | kB   | user       | default
(6 rows)
```

Note the units: `shared_buffers` is 16384 units of 8 kB, which is 128 MB. `SHOW shared_buffers` prints it in a
readable unit. A setting that needs a restart and has been changed in a file shows `pending_restart = true` after a
reload, as `wal_level` did earlier.

**A reload never fails loudly.** If a file contains an error, the server logs it and keeps the old value:

```bash
echo "work_mem = 64MBB" >> ~/pgpractice/primary/postgresql.conf
psql -X -c 'SELECT pg_reload_conf()'
tail -3 ~/pgpractice/primary.log
```

```text
LOG:  invalid value for parameter "work_mem": "64MBB"
HINT:  Valid units for this parameter are "B", "kB", "MB", "GB", and "TB".
LOG:  configuration file "/home/you/pgpractice/primary/postgresql.conf" contains errors; unaffected changes were applied
```

`pg_file_settings` reads the files the way the server would, and shows such errors without digging through the log.
Check it after every edit, before reloading:

```sql
SELECT sourcefile, sourceline, name, setting, applied, error
FROM pg_file_settings
WHERE error IS NOT NULL;
```

```text
                  sourcefile                  | sourceline |   name   | setting | applied |            error
----------------------------------------------+------------+----------+---------+---------+------------------------------
 /home/you/pgpractice/primary/postgresql.conf |        898 | work_mem | 64MBB   | f       | setting could not be applied
(1 row)
```

Remove the broken line again (for example with `sed -i.bak '$d' ~/pgpractice/primary/postgresql.conf`) and reload.

### ALTER SYSTEM

`ALTER SYSTEM` changes a setting from SQL. It writes `postgresql.auto.conf`, which overrides `postgresql.conf`, and
validates the value first, so a typo can't get into the file:

```sql
ALTER SYSTEM SET work_mem = '16MB';
SELECT pg_reload_conf();
SELECT name, setting, unit, source, sourcefile FROM pg_settings WHERE name = 'work_mem';
```

```text
   name   | setting | unit |       source       |                    sourcefile
----------+---------+------+--------------------+---------------------------------------------------
 work_mem | 16384   | kB   | configuration file | /home/you/pgpractice/primary/postgresql.auto.conf
(1 row)
```

```sql
ALTER SYSTEM SET work_mem = '64MBB';
ALTER SYSTEM RESET work_mem;          -- removes the line from postgresql.auto.conf again
```

```text
ERROR:  invalid value for parameter "work_mem": "64MBB"
HINT:  Valid units for this parameter are "B", "kB", "MB", "GB", and "TB".
ALTER SYSTEM
```

Like any file change, `ALTER SYSTEM` takes effect only after a reload (or a restart, for `postmaster` settings). If you
manage configuration with a tool such as Ansible, or your provider does, two sources of truth are one too many. Since
PostgreSQL 17, `allow_alter_system = off` in `postgresql.conf` disables the command:

```text
ERROR:  ALTER SYSTEM is not allowed in this environment
```

(It's a convenience for configuration management, not a security boundary: a superuser can still change files in
other ways.)

### The settings that matter most

Most of the 400 settings are best left alone. These are the ones worth thinking about on every server; the numbers
are typical starting points for a dedicated database server, to be checked against your workload:

| Setting | Default | Starting point | What it does |
|---|---|---|---|
| `max_connections` | 100 | as low as possible; use a pooler (chapter 22) | each connection is a process with its own memory |
| `shared_buffers` | 128MB | ~25% of RAM | PostgreSQL's own page cache (chapter 18); restart needed |
| `effective_cache_size` | 4GB | ~50–75% of RAM | only a hint to the planner about the OS cache; allocates nothing |
| `work_mem` | 4MB | 16–64MB, more per session for reports | memory per sort or hash *operation*; a query can use several, times parallel workers |
| `maintenance_work_mem` | 64MB | 512MB–2GB | `VACUUM`, `CREATE INDEX`, restores |
| `max_wal_size` | 1GB | 4–16GB on busy servers | fewer forced checkpoints, less full-page WAL (chapter 18) |
| `random_page_cost` | 4 | 1.1 on SSDs | makes index scans look as cheap as they are on SSD |
| `effective_io_concurrency` | 16 | 16–200 on SSDs | read-ahead for bitmap heap scans and more |
| `io_method` (18+) | `worker` | `io_uring` on Linux, if built with it | asynchronous I/O (chapter 18); restart needed |
| `wal_compression` | off | `lz4` or `zstd` | smaller WAL for a little CPU |
| `huge_pages` | try | `on` on Linux with huge pages configured | less memory overhead for large `shared_buffers` |

### Logging

The server log is where you find out *after the fact* what went wrong. Useful settings:

| Setting | Suggested | Logs |
|---|---|---|
| `log_line_prefix` | `'%m [%p] %q%u@%d '` | time, process ID, user and database on every line |
| `log_min_duration_statement` | `'250ms'` to `'1s'` | every statement slower than that, with its duration |
| `log_lock_waits` | `on` | waits longer than `deadlock_timeout` (1 s) for a lock, with who blocks whom |
| `log_temp_files` | `0` | every temporary file a sort or hash spilled to disk |
| `log_autovacuum_min_duration` | `'10min'` (the default) or lower | slow autovacuum runs |
| `log_checkpoints` | `on` (the default) | each checkpoint and its cause |
| `log_connections`, `log_disconnections` | as needed | connection attempts and session ends |

`log_connections` became a list in PostgreSQL 18: `receipt`, `authentication`, `authorization`, `setup_durations`, or
`all` (the old `on` still means the first three). `setup_durations` shows where the time goes when connecting is slow:

```sql
ALTER SYSTEM SET log_connections = 'all';
SELECT pg_reload_conf();
```

```text
LOG:  connection received: host=[local]
LOG:  connection authenticated: user="inkwell_app" method=trust (/home/you/pgpractice/primary/pg_hba.conf:117)
LOG:  connection authorized: user=inkwell_app database=inkwell application_name=psql
LOG:  connection ready: setup total=3.379 ms, fork=0.482 ms, authentication=0.237 ms
```

The `authenticated` line names the `pg_hba.conf` line that matched, which is exactly what you need when a login
unexpectedly succeeds or fails. Run `ALTER SYSTEM RESET log_connections` afterwards.

By default the log goes to standard error, which `pg_ctl -l` sends to a file and systemd sends to the journal.
`logging_collector = on` makes the server write and rotate its own files in `log/`; `log_destination = 'jsonlog'`
(15+) writes structured JSON for log shippers. **pgBadger** turns log files into reports of the slowest and most
frequent queries.

### auto_explain

Chapter 16 promised this: the `auto_explain` module logs the execution plan of every statement slower than a
threshold, with the real parameter values, at the moment it was slow. Plans you capture later with `EXPLAIN` may
differ, because the data, the statistics or the parameters changed. Try it for one session:

```sql
LOAD 'auto_explain';
SET auto_explain.log_min_duration = 0;
SET auto_explain.log_analyze = on;
SELECT count(*) FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.status = 'delivered';
```

The server log now contains the plan:

```text
LOG:  duration: 0.048 ms  plan:
	Query Text: SELECT count(*) FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.status = 'delivered';
	Aggregate  (cost=2.54..2.55 rows=1 width=8) (actual time=0.043..0.044 rows=1.00 loops=1)
	  ->  Hash Join  (cost=1.22..2.51 rows=10 width=0) (actual time=0.037..0.041 rows=13.00 loops=1)
	        Hash Cond: (i.order_id = o.id)
	        ->  Seq Scan on order_items i  (cost=0.00..1.21 rows=21 width=8) (actual time=0.005..0.006 rows=21.00 loops=1)
	        ->  Hash  (cost=1.15..1.15 rows=6 width=8) (actual time=0.011..0.011 rows=6.00 loops=1)
	              Buckets: 1024  Batches: 1  Memory Usage: 9kB
	              ->  Seq Scan on orders o  (cost=0.00..1.15 rows=6 width=8) (actual time=0.005..0.006 rows=6.00 loops=1)
	                    Filter: (status = 'delivered'::order_status)
	                    Rows Removed by Filter: 6
```

For the whole server, load it in every session through the configuration, with a realistic threshold:

```text
session_preload_libraries = 'auto_explain'
auto_explain.log_min_duration = '500ms'
auto_explain.log_analyze = on
auto_explain.log_timing = off
```

`session_preload_libraries` loads the module into each new session (a reload is enough). `log_analyze` adds actual row
counts, at a small cost on every statement; `log_timing = off` avoids the larger cost of timing every plan node.

## Monitoring

Monitoring answers two questions: *is the server healthy right now*, and *what changed* when something got slower.
Chapter 18 listed the statistics views. Here are the queries you'll run most, and what to alert on.

### What is running: pg_stat_activity

The examples in this section were captured with three other sessions open on the primary: one ran
`BEGIN; UPDATE products SET stock = stock - 1 WHERE id = 5; SELECT pg_sleep(8);`, one tried to update the same row,
and one ran a `SELECT` inside a transaction and then did nothing. Open three `psql` windows and do the same.

```sql
SELECT pid, usename, application_name, state, wait_event_type, wait_event,
       now() - xact_start AS xact_age, left(query, 40) AS query
FROM pg_stat_activity
WHERE backend_type = 'client backend' AND pid <> pg_backend_pid()
ORDER BY xact_start;
```

```text
  pid  | usename  | application_name |        state        | wait_event_type |  wait_event   |    xact_age     |                  query
-------+----------+------------------+---------------------+-----------------+---------------+-----------------+------------------------------------------
 12183 | postgres | psql             | active              | Timeout         | PgSleep       | 00:00:02.505631 | SELECT pg_sleep(8)
 12188 | postgres | psql             | idle in transaction | Client          | ClientRead    | 00:00:02.001765 | SELECT count(*) FROM orders
 12187 | postgres | psql             | active              | Lock            | transactionid | 00:00:02.001537 | UPDATE products SET stock = stock - 2 WH
(3 rows)
```

How to read it:

- `state`: `active` (running a statement), `idle` (waiting for the next one), `idle in transaction` (inside an open
  transaction, waiting for the client). For an idle session, `query` is the *last* statement it ran.
- `wait_event_type` / `wait_event`: what an active session is waiting for. `Lock` means a lock held by another
  session; `IO` means disk; `Client` means the network or the application; `LWLock` means internal contention; `NULL`
  means it's running on the CPU.
- `xact_start`, `query_start`, `state_change`: how long the transaction, the statement and the current state have
  lasted.

The `idle in transaction` session is the one to worry about: it holds its locks and its snapshot, blocking `VACUUM`
(chapter 18) and possibly other sessions, while doing nothing. `idle_in_transaction_session_timeout` ends such
sessions automatically:

```text
FATAL:  terminating connection due to idle-in-transaction timeout
```

### Who blocks whom

`pg_blocking_pids(pid)` returns the sessions that hold the locks a session waits for. Joined back to
`pg_stat_activity`, it shows the chain:

```sql
SELECT blocked.pid AS blocked_pid,
       left(blocked.query, 45) AS blocked_query,
       blocking.pid AS blocking_pid,
       blocking.state AS blocking_state,
       left(blocking.query, 45) AS blocking_query
FROM pg_stat_activity AS blocked
JOIN LATERAL unnest(pg_blocking_pids(blocked.pid)) AS b(pid) ON true
JOIN pg_stat_activity AS blocking ON blocking.pid = b.pid;
```

```text
 blocked_pid |                 blocked_query                 | blocking_pid | blocking_state |   blocking_query
-------------+-----------------------------------------------+--------------+----------------+--------------------
       12187 | UPDATE products SET stock = stock - 2 WHERE i |        12183 | active         | SELECT pg_sleep(8)
(1 row)
```

The blocking session's `query` is only its *latest* statement, not necessarily the one that took the lock: here, the
`UPDATE` that locked the row ran earlier in the same transaction. When a chain of sessions waits behind one head
blocker, the head is the session that appears as `blocking_pid` but never as `blocked_pid`.

### Cancelling and terminating

| Function | Effect | The client sees |
|---|---|---|
| `pg_cancel_backend(pid)` | cancels the running statement; the session stays | `ERROR:  canceling statement due to user request` |
| `pg_terminate_backend(pid)` | ends the session; its transaction rolls back | `FATAL:  terminating connection due to administrator command` |

Cancel first; terminate if the session is idle in a transaction or doesn't react. Superusers can signal any session;
other roles can signal their own sessions, and members of `pg_signal_backend` can signal those of non-superusers.
Never `kill -9` a backend process from the shell: the postmaster then assumes shared memory may be corrupted, and
restarts the whole server, disconnecting everyone. A plain `kill` (SIGTERM) on a backend is the same as
`pg_terminate_backend`.

### Database-wide health

```sql
SELECT datname, numbackends, xact_commit, xact_rollback,
       round(100.0 * blks_hit / nullif(blks_hit + blks_read, 0), 2) AS cache_hit_pct,
       temp_files, pg_size_pretty(temp_bytes) AS temp_size, deadlocks, conflicts,
       pg_size_pretty(pg_database_size(datname)) AS size
FROM pg_stat_database
WHERE datname IS NOT NULL
ORDER BY pg_database_size(datname) DESC;
```

```text
  datname  | numbackends | xact_commit | xact_rollback | cache_hit_pct | temp_files | temp_size | deadlocks | conflicts |  size
-----------+-------------+-------------+---------------+---------------+------------+-----------+-----------+-----------+---------
 inkwell   |           2 |         200 |            78 |         98.26 |          3 | 20 MB     |         0 |         0 | 8686 kB
 postgres  |           0 |         117 |             5 |         95.92 |          0 | 0 bytes   |         0 |         0 | 7678 kB
 template1 |           0 |           0 |             0 |               |          0 | 0 bytes   |         0 |         0 | 7593 kB
 template0 |           0 |           0 |             0 |               |          0 | 0 bytes   |         0 |         0 | 7521 kB
(4 rows)
```

On a busy production database, a cache hit rate in the high 90s is normal; a sudden drop means the working set no
longer fits in memory. Rollbacks, temporary files and deadlocks should be rare; watch their *rate*, not the totals.

For I/O, PostgreSQL 16 added `pg_stat_io`, one row per kind of process, kind of object and context. Since 18 it also
covers WAL (`object = 'wal'`), and `pg_stat_wal` was slimmed down to record counts and bytes:

```sql
SELECT backend_type, object, context, reads, writes, extends, hits, evictions, fsyncs
FROM pg_stat_io
WHERE backend_type IN ('client backend', 'checkpointer', 'autovacuum worker', 'walwriter')
  AND (reads > 0 OR writes > 0 OR hits > 0)
ORDER BY backend_type, object, context;
```

```text
   backend_type    |  object  |  context  | reads | writes | extends | hits  | evictions | fsyncs
-------------------+----------+-----------+-------+--------+---------+-------+-----------+--------
 autovacuum worker | relation | normal    |     4 |      0 |       0 |   521 |         0 |      0
 checkpointer      | relation | normal    |       |     72 |         |       |           |     62
 checkpointer      | wal      | init      |       |      2 |         |       |           |      2
 checkpointer      | wal      | normal    |     0 |    246 |         |       |           |      6
 client backend    | relation | bulkwrite |     0 |   6528 |     216 | 12285 |         0 |
 client backend    | relation | normal    |   897 |      0 |      10 | 40827 |         0 |      0
 client backend    | wal      | init      |       |      4 |         |       |           |      4
 client backend    | wal      | normal    |     0 |  13331 |         |       |           |     35
 walwriter         | wal      | init      |       |      1 |         |       |           |      1
 walwriter         | wal      | normal    |       |     25 |         |       |           |     25
(10 rows)
```

A healthy pattern: the checkpointer and background writer do most of the data file writes. Many `writes` by `client
backend` in the `normal` context mean backends must evict dirty pages themselves, a sign that `shared_buffers` is too
small or checkpoints are too far apart. (`bulkwrite` here is the `CREATE TABLE AS` of the slot demo, which uses a
small ring buffer on purpose.)

### Sizes

```sql
SELECT relname,
       pg_size_pretty(pg_total_relation_size(relid)) AS total,
       pg_size_pretty(pg_relation_size(relid)) AS heap,
       pg_size_pretty(pg_indexes_size(relid)) AS indexes
FROM pg_stat_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 5;
```

On the million-order data set from chapter 15:

```text
   relname   |  total  |    heap    | indexes
-------------+---------+------------+---------
 order_items | 234 MB  | 144 MB     | 91 MB
 orders      | 129 MB  | 65 MB      | 64 MB
 customers   | 19 MB   | 8584 kB    | 11 MB
 reviews     | 7048 kB | 3640 kB    | 3368 kB
 categories  | 96 kB   | 8192 bytes | 48 kB
(5 rows)
```

`pg_total_relation_size` includes indexes and TOAST. Track sizes over time; growth that doesn't match the business is
usually bloat (chapter 18).

### What to alert on

Counters are cumulative, so a monitoring system samples them regularly and graphs the differences. Use one: the
Prometheus `postgres_exporter` with Grafana, pgwatch, Datadog, pganalyze, or your cloud provider's dashboards. Whatever
you use, these deserve alerts:

| Alert | Query or source |
|---|---|
| server down, or can't connect | a connection check from outside |
| connections near `max_connections` | `count(*)` from `pg_stat_activity` |
| long-running transactions, idle in transaction | `now() - xact_start` in `pg_stat_activity` (exercise 3) |
| replication lag | `pg_stat_replication` on the primary |
| inactive or lost replication slots, retained WAL | `pg_replication_slots` (exercise 5) |
| archiving failures | `failed_count` growing in `pg_stat_archiver` |
| disk space, for data and for `pg_wal` | the operating system |
| transaction ID age | `age(datfrozenxid)` in `pg_database` (chapter 18) |
| last successful backup, last successful restore test | your backup tool |
| errors in the log: `FATAL`, `PANIC`, deadlocks, "checkpoints are occurring too frequently" | a log shipper or pgBadger |

## A production checklist

- **Backups**: continuous WAL archiving plus regular base backups with a dedicated tool, stored off the server; RPO and
  RTO written down; restores tested regularly, automatically if possible; a named restore point before risky changes.
- **Logical dumps** of important databases as a second, portable safety net, with `pg_dumpall --globals-only`.
- **High availability**: at least one streaming standby, with a cluster manager such as Patroni that fences the old
  primary; slots with `max_slot_wal_keep_size` set; standbys on the same OS and library versions.
- **Upgrades**: the latest minor release always; a major upgrade rehearsed on a copy, with `pg_upgrade --check` first.
- **Configuration** in version control or configuration management, checked with `pg_file_settings` before each
  reload; memory, WAL and logging settings reviewed for the hardware.
- **Monitoring and alerts** as above, `pg_stat_statements` and `auto_explain` for slow queries, and a person who looks
  at them.
- **Security** from chapter 21: TLS, SCRAM passwords, least-privilege roles, `pg_hba.conf` as narrow as possible, and
  backups protected like the database.

## Cleaning up

Stop every practice cluster and remove the directory. The second cluster now lives in `second-18`; the old `second`
directory can no longer be started after the swap upgrade:

```bash
pg_ctl -D ~/pgpractice/standby stop
pg_ctl -D ~/pgpractice/second-18 stop
pg_ctl -D ~/pgpractice/primary stop
rm -rf ~/pgpractice
unset PGHOST PGPORT PGUSER
```

(If you're doing the exercises, do them first: they use the clusters.) Your normal server on port 5432 was never
touched.

## Key takeaways

- Decide your RPO and RTO first. Replicas protect against hardware failure, not against mistakes; only backups with
  point-in-time recovery protect against both. A backup is proven only by a restore.
- `pg_dump` makes a consistent, portable copy of one database. Use the custom or directory format, restore with
  `pg_restore` (`-j`, `-t`, `-L`), and restore the roles from `pg_dumpall --globals-only` first. Since 18, statistics
  can be dumped too (`--statistics`).
- Physical backups (`pg_basebackup`) plus archived WAL allow recovery to any moment: restore the files, set
  `restore_command` and a recovery target, create `recovery.signal`. Recovery pauses at the target so you can check,
  and ends on a new timeline.
- Incremental backups (17+) copy only changed blocks, using WAL summaries; `pg_combinebackup` rebuilds a full backup
  before a restore.
- Streaming replication keeps standbys a fraction of a second behind. Slots stop the primary from removing WAL a
  standby needs, and can fill its disk: set `max_slot_wal_keep_size` and watch `pg_replication_slots`.
- Synchronous replication trades commit latency for zero data loss, and blocks commits when no synchronous standby
  is available. Failover is easy; preventing split brain is the hard part, so use a cluster manager. `pg_rewind`
  turns an old primary into a standby, but copies the configuration files too.
- Logical replication sends row changes for chosen tables, across major versions. It doesn't replicate DDL or sequence
  values, needs a replica identity for updates and deletes, and stops on conflicts until you fix the data.
- Upgrade minor versions promptly. For major versions use `pg_upgrade` (`--check` first; `--link` or `--swap` for
  speed), carry over the configuration, and since 18 most statistics come along.
- Settings have contexts: some need a restart, some a reload, some can be set per session. Check `pg_file_settings`
  before reloading, use `ALTER SYSTEM` or configuration management (not both), and log slow statements and lock waits.
- `pg_stat_activity` and `pg_blocking_pids()` explain most "the database is stuck" moments; cancel before you
  terminate, and never `kill -9` a backend.

## Exercises

The exercises use the practice clusters from this chapter, in the state the chapter left them: the primary on
port 5433 (the default through `PGPORT`) and the second cluster, now `second-18`, on port 5435.

1. **A table for a developer.** A developer wants the `customers` table, with its data, in a scratch database
   `scratch` on the primary, owned by whoever restores it. Dump only that table in custom format and restore it.
   Which of the table's grants and policies come along?

<details>
<summary>Solution</summary>

```bash
cd ~/pgpractice
pg_dump -Fc -t customers -f customers.dump inkwell
createdb scratch
pg_restore -d scratch --no-owner --no-privileges customers.dump
psql -X -d scratch -c 'SELECT count(*) FROM customers'
```

```text
 count
-------
     8
(1 row)
```

`\d customers` in `scratch` shows the primary key, the unique constraint on `email` and the check constraints:
`pg_dump -t` includes a table's own indexes and constraints. Foreign keys *to* the table would be missing, since the
referencing tables weren't dumped. `--no-owner` makes the restoring role (`postgres`) the owner, and
`--no-privileges` drops the `GRANT`s to the `inkwell_*` roles. `customers` has no row-level security policies; for a
table that has them, add `--no-policies` (18+) to leave them out. Clean up with `dropdb scratch`.

</details>

2. **Configuration audit.** Write a query that lists every setting whose value doesn't come from the built-in default,
   with the file and line it comes from and its context, and one that lists the settings waiting for a restart.

<details>
<summary>Solution</summary>

```sql
SELECT name, left(setting, 30) AS setting, unit, context, source, sourcefile, sourceline
FROM pg_settings
WHERE source NOT IN ('default', 'override', 'client')
ORDER BY context, name;

SELECT name, setting, unit FROM pg_settings WHERE pending_restart;
```

`source` is `configuration file` for values from `postgresql.conf` or `postgresql.auto.conf` (`sourcefile` says
which), `database` or `user` for `ALTER DATABASE` / `ALTER ROLE` settings, and `session` for `SET`. `override` covers
values the server computes itself, and `client` those the client sent at connect time, such as `client_encoding`.

On the practice primary, the first query shows the lines `initdb` wrote (`max_connections`, `shared_buffers`, the
locale and time zone settings), the practice settings from the chapter, and `wal_level`, `max_slot_wal_keep_size` and
the left-over `primary_conninfo` from `postgresql.auto.conf`. (`primary_conninfo` has no effect on a server without
`standby.signal`; `ALTER SYSTEM RESET primary_conninfo` tidies it up.) The second query returns no rows unless you
changed a `postmaster` setting without restarting.

</details>

3. **Long transactions.** Write a monitoring query that lists the sessions whose transaction has been open for more
   than five minutes, with how long they've been in their current state and the age of their snapshot
   (`backend_xmin`). Then write the statement a scheduled job could run to terminate sessions that have been idle in a
   transaction for more than 30 minutes. Why would the server-side setting be better than the job?

<details>
<summary>Solution</summary>

```sql
SELECT pid, usename, datname, state,
       now() - xact_start AS xact_age,
       now() - state_change AS in_state_for,
       age(backend_xmin) AS xmin_age,
       left(query, 40) AS last_query
FROM pg_stat_activity
WHERE xact_start < now() - interval '5 minutes'
ORDER BY xact_start;
```

```sql
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE state = 'idle in transaction'
  AND state_change < now() - interval '30 minutes';
```

`xmin_age` is the number of transactions since the session's snapshot was taken: this is what holds back `VACUUM`.
It's `NULL` for a session in `READ COMMITTED` between statements, which has no snapshot; a `REPEATABLE READ`
transaction keeps one for its whole duration.

`idle_in_transaction_session_timeout = '30min'` (in `postgresql.conf`, or per role or database) does the same inside
the server: it can't be forgotten when the job's scheduler breaks, applies the moment the limit is reached, and can be
set differently per role, for example shorter for the application and unlimited for an administrator.

</details>

4. **Undo a migration with a restore point.** Take a fresh base backup of the primary. Create a named restore point
   `before_price_change`, then run a "migration" that doubles every price. Recover a copy of the cluster on port 5436
   to the restore point, with recovery promoting automatically, and check a price on both servers.

<details>
<summary>Solution</summary>

```bash
cd ~/pgpractice
pg_basebackup -D base2 -c fast
psql -X -d inkwell -c 'SELECT price FROM products WHERE id = 1'
psql -X -c "SELECT pg_create_restore_point('before_price_change')"
psql -X -d inkwell -c 'UPDATE products SET price = price * 2'
psql -X -c 'SELECT pg_switch_wal()'

cp -a base2 pitr
rm -f pitr/standby.signal
cat >> pitr/postgresql.conf <<EOF
port = 5436
archive_mode = off
restore_command = 'cp $HOME/pgpractice/archive/%f %p'
recovery_target_name = 'before_price_change'
recovery_target_action = 'promote'
EOF
touch pitr/recovery.signal
pg_ctl -D pitr -l pitr.log start
grep -E 'starting point-in-time|recovery stopping|new timeline' pitr.log
psql -X -p 5436 -d inkwell -c 'SELECT pg_is_in_recovery(), (SELECT price FROM products WHERE id = 1)'
psql -X -d inkwell -c 'SELECT price FROM products WHERE id = 1'
```

```text
LOG:  starting point-in-time recovery to "before_price_change"
LOG:  recovery stopping at restore point "before_price_change", time 2026-10-11 20:31:03.832335+00
LOG:  selected new timeline ID: 4
```

The recovered server has the old price, the primary the doubled one (your prices depend on the chapter's updates).
The new timeline is 4, because timelines 2 and 3 already exist in the archive's history. Removing `standby.signal`
matters only if the backup carried one; with both signal files present, the server would start as a standby instead.
Clean up with `pg_ctl -D pitr stop` and `rm -rf pitr base2`.

In a real migration, you would rather run it in a transaction and roll back (chapter 17), but a restore point also
covers migrations that can't run in one transaction, and mistakes discovered hours later.

</details>

5. **Replication health in two queries.** Write the two queries a monitoring system should run on the primary: one
   for each connected standby or subscriber, with its replay lag in bytes (human-readable) and time; and one for every
   replication slot, with the WAL it retains, sorted by the largest first. Which values would you alert on?

<details>
<summary>Solution</summary>

```sql
SELECT application_name, client_addr, state, sync_state,
       pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn)) AS replay_behind,
       replay_lag
FROM pg_stat_replication;

SELECT slot_name, slot_type, active, wal_status,
       pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)) AS retained_wal,
       inactive_since
FROM pg_replication_slots
ORDER BY pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn) DESC NULLS LAST;
```

```text
 application_name | client_addr |   state   | sync_state | replay_behind |   replay_lag
------------------+-------------+-----------+------------+---------------+-----------------
 catalog_sub      |             | streaming | async      | 0 bytes       |
 standby1         |             | streaming | async      | 0 bytes       | 00:00:00.005565
(2 rows)

  slot_name  | slot_type | active | wal_status | retained_wal | inactive_since
-------------+-----------+--------+------------+--------------+----------------
 catalog_sub | logical   | t      | reserved   | 4429 kB      |
 standby1    | physical  | t      | reserved   | 0 bytes      |
(2 rows)
```

(`client_addr` is empty for Unix-socket connections.) Alert when an expected standby or subscriber is missing from
the first query, when its lag exceeds what your RPO allows, when a slot is inactive for more than a few minutes, when
`wal_status` isn't `reserved`, and when retained WAL approaches `max_slot_wal_keep_size` or the free disk space. A
logical slot retains a little WAL even when everything is fine: its `restart_lsn` only advances when the subscriber
confirms, and decoding has to restart from a point before the oldest running transaction.

</details>

6. **A storefront feed.** Create a publication `storefront` on the primary that sends only *active* products, and
   only the columns `id`, `sku`, `name`, `price` and `active`. Then try to update any product's stock on the primary.
   What happens, and why? Fix it without giving up the filter, subscribe a new database `storefront` on the second
   cluster, and show that a product moves in or out of the subscriber's table when its `active` flag changes.

<details>
<summary>Solution</summary>

```sql
CREATE PUBLICATION storefront FOR TABLE products (id, sku, name, price, active) WHERE (active);
UPDATE products SET stock = stock + 1 WHERE id = 1;
```

```text
CREATE PUBLICATION
ERROR:  cannot update table "products"
DETAIL:  Column used in the publication WHERE expression is not part of the replica identity.
```

Every `UPDATE` of `products` now fails, for the whole application. To decide whether an update moves a row into or out
of the filter, the publisher must send the filter's columns of the *old* row, and only replica identity columns are
sent for the old row. `active` isn't part of the primary key. Options:

- filter only on replica identity columns;
- publish only inserts (`WITH (publish = 'insert')`), which is useless here;
- make `active` part of the replica identity, with a unique index that contains the key and the filter column.

The third keeps everything working:

```sql
CREATE UNIQUE INDEX products_id_active_key ON products (id, active);
ALTER TABLE products REPLICA IDENTITY USING INDEX products_id_active_key;
UPDATE products SET stock = stock + 1 WHERE id = 1;
```

```text
CREATE INDEX
ALTER TABLE
UPDATE 1
```

(The index is unique because `id` alone is; it costs some write work, which is the price of the filter.) A column
list must contain the replica identity columns, which `id` and `active` are. On the subscriber:

```bash
createdb -p 5435 storefront
psql -X -p 5435 -d storefront <<'SQL'
CREATE TABLE products (
    id     bigint PRIMARY KEY,
    sku    text NOT NULL,
    name   text NOT NULL,
    price  numeric(10, 2) NOT NULL,
    active boolean NOT NULL
);
CREATE SUBSCRIPTION storefront_sub
    CONNECTION 'host=/tmp port=5433 dbname=inkwell user=replicator'
    PUBLICATION storefront;
SQL
```

Product 14 (the leather pen case) is the inactive one in the sample data. Swap it with product 2:

```bash
psql -X -d inkwell -c 'UPDATE products SET active = false WHERE id = 2' \
                   -c 'UPDATE products SET active = true WHERE id = 14'
psql -X -p 5435 -d storefront -c 'SELECT id, name, active FROM products WHERE id IN (2, 14)'
```

```text
 id |       name       | active
----+------------------+--------
 14 | Leather Pen Case | t
(1 row)
```

An update that makes a row stop matching the filter is sent as a `DELETE`, and one that makes it start matching as an
`INSERT`, so the subscriber always holds exactly the active products. Product 2 is gone, product 14 arrived.

</details>

---
← Previous: [Using PostgreSQL from Node.js and PHP](22-using-postgresql-from-node-and-php.md) | Next: [Capstone project: Inkwell](24-capstone-project.md) →
