# Chapter 17 — Transactions and concurrency

## What you'll learn

- What ACID means in PostgreSQL, and how multiversion concurrency control (MVCC) lets readers and writers work side by
  side
- Exactly what a statement can see under each isolation level: read committed, repeatable read and serializable
- The classic concurrency bugs (lost updates, nonrepeatable reads, write skew) and the standard ways to prevent them
- Row locks (`FOR UPDATE` and friends, `NOWAIT`, `SKIP LOCKED`), table locks, and how to see who is waiting for whom
- Deadlocks, timeouts, and which errors an application must retry
- Advisory locks, job queues with `SKIP LOCKED`, and gap-free invoice numbers
- Deferred constraint checks, and how to change the schema of a busy database without blocking it

Every example so far ran in a single session. Real databases serve many sessions at once: a web shop has customers
checking out, staff editing products and reports running, all at the same moment. PostgreSQL keeps them from
corrupting each other's work, but only within rules you need to know. Most concurrency bugs aren't crashes; they are
an order that sold a pen that wasn't in stock, or a report whose totals don't add up, and they happen only under load.

## Setting up

Start from a fresh Inkwell database (the small one, without the bulk data):

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
```

This chapter needs **two psql sessions** side by side, and sometimes a third or fourth. Open two terminals and run
`psql -d inkwell` in each. Every code block that matters for concurrency starts with a comment saying which session
runs it: `-- Session A`, `-- Session B`, and so on. Run the blocks in the order they appear, each in its own session.
Blocks without such a comment can run in any session.

Give each session a name, so that you can tell them apart in the monitoring views later:

```sql
-- Session A
SET application_name = 'session A';
```

```text
SET
```

```sql
-- Session B
SET application_name = 'session B';
```

```text
SET
```

## ACID, the PostgreSQL way

A **transaction** is a group of statements that the database treats as one unit. You met `BEGIN`, `COMMIT`,
`ROLLBACK` and savepoints in chapter 5. The guarantees a transaction gives are summed up as **ACID**:

| Property | Meaning | How PostgreSQL provides it |
|---|---|---|
| **Atomicity** | all of the transaction's changes happen, or none | every row version records which transaction created it; an aborted transaction's versions are simply never visible |
| **Consistency** | the data always satisfies the constraints | `CHECK`, `UNIQUE`, foreign keys and the rest, checked on every change (or at commit, if deferred) |
| **Isolation** | concurrent transactions don't see each other's half-done work | MVCC snapshots plus locks; the isolation level decides how strict |
| **Durability** | once `COMMIT` returns, the change survives a crash | the write-ahead log (WAL) is flushed to disk before `COMMIT` returns (chapter 18) |

Atomicity and durability are mostly invisible: they just work. Consistency is what chapter 4's constraints are for.
Isolation is where the choices are, and it is what the rest of this chapter is about.

Remember also that **every statement runs in a transaction**. Without `BEGIN`, each statement is its own transaction
that commits as soon as it finishes (this is called *autocommit*). A single `UPDATE` that changes a thousand rows
changes all of them or none, even without `BEGIN`.

## MVCC: rows have versions

PostgreSQL's concurrency model is **multiversion concurrency control**. An `UPDATE` doesn't overwrite a row in place.
It writes a *new version* of the row and marks the old one as replaced. A `DELETE` only marks the row version as
deleted. Each row version (a **tuple**) carries two hidden system columns:

- `xmin` — the id of the transaction that created this version;
- `xmax` — the id of the transaction that deleted or replaced it, or that locked it (0 if none).

Every transaction that changes data gets a transaction id from a counter. You can see the hidden columns, and the
version's physical position (`ctid`, page and slot number), by naming them:

```sql
SELECT xmin, xmax, ctid, id, name, price FROM products WHERE id = 1;
```

```text
 xmin  | xmax  | ctid  | id |         name         | price
-------+-------+-------+----+----------------------+-------
 16736 | 16739 | (0,1) |  1 | Kestrel Fountain Pen | 48.00
(1 row)
```

Your numbers will differ. You might expect `xmax` to be 0 for a row that nobody deleted, but it also records row
*locks*: when `seed.sql` inserted order items for this product, the foreign key check locked the product row (you'll
see why under "Row locks" below), and that lock left its transaction's id behind. The transaction finished long ago, so
the lock no longer counts.

Now update the row inside a transaction and look again:

```sql
BEGIN;
SELECT pg_current_xact_id();
UPDATE products SET price = 49.00 WHERE id = 1;
SELECT xmin, xmax, ctid, id, name, price FROM products WHERE id = 1;
ROLLBACK;
```

```text
BEGIN
 pg_current_xact_id
--------------------
              16789
(1 row)

UPDATE 1
 xmin  | xmax |  ctid  | id |         name         | price
-------+------+--------+----+----------------------+-------
 16789 |    0 | (0,15) |  1 | Kestrel Fountain Pen | 49.00
(1 row)

ROLLBACK
```

The pattern is what matters, not the numbers: the transaction got an id, and the
updated row is a new version, in a new position (`ctid`), whose `xmin` is that id. The old version still exists, with
its `xmax` set to the same id:

```text
 page 0
 ┌───────────────────────────────────────────────────────────────┐
 │ (0,1)   xmin=old  xmax=me   Kestrel Fountain Pen  48.00        │  ← visible to everyone else
 │ ...                                                            │
 │ (0,15)  xmin=me   xmax=0    Kestrel Fountain Pen  49.00        │  ← visible only to me (until I commit)
 └───────────────────────────────────────────────────────────────┘
```

Which version a transaction sees depends on its **snapshot**: a record, taken at a certain moment, of which
transactions had committed by then. A row version is visible if its `xmin` committed before the snapshot was taken
and its `xmax` (if any) did not. That is the whole trick, and it has big consequences:

- **Readers never block writers, and writers never block readers.** A `SELECT` reads whichever versions its snapshot
  allows, while another transaction is busy writing new ones.
- **Rollback is instant.** The new versions are left behind and become invisible, because their creator never
  committed. Nothing has to be undone.
- **Old versions pile up.** Once no snapshot can see an old version any more, it is a *dead tuple*, and something has to
  reclaim the space. That something is `VACUUM`, chapter 18's topic.
- **Only writers of the same row wait for each other.** Two transactions that update *the same row* can't both create
  the next version; the second waits until the first commits or rolls back.

Watch it with two sessions. Session A changes a price but doesn't commit yet:

```sql
-- Session A
BEGIN;
UPDATE products SET price = 50.00 WHERE id = 1;
```

```text
BEGIN
UPDATE 1
```

Session B reads the same row. It doesn't wait, and it sees the last committed version:

```sql
-- Session B
SELECT price FROM products WHERE id = 1;
```

```text
 price
-------
 48.00
(1 row)
```

Once session A commits, session B's next statement sees the new price:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

```sql
-- Session B
SELECT price FROM products WHERE id = 1;
```

```text
 price
-------
 50.00
(1 row)
```

There are no "dirty reads" in PostgreSQL: no session ever sees another session's uncommitted changes.

## Isolation levels

The SQL standard defines four isolation levels by which anomalies each one allows. PostgreSQL implements three of them
(asking for `READ UNCOMMITTED` gives you `READ COMMITTED`, which is allowed because it is stricter):

| Anomaly | Read committed (default) | Repeatable read | Serializable |
|---|---|---|---|
| Dirty read (seeing uncommitted data) | no | no | no |
| Nonrepeatable read (a row changes between two reads) | possible | no | no |
| Phantom read (new rows appear in a repeated query) | possible | no (in PostgreSQL) | no |
| Lost update (two read-modify-writes, one is lost) | possible | prevented, with an error | prevented, with an error |
| Write skew and other serialization anomalies | possible | possible | prevented, with an error |

You choose the level per transaction:

```sql
BEGIN ISOLATION LEVEL REPEATABLE READ;
SHOW transaction_isolation;
COMMIT;
```

```text
BEGIN
 transaction_isolation
-----------------------
 repeatable read
(1 row)

COMMIT
```

`SET TRANSACTION ISOLATION LEVEL ...` as the first statement after `BEGIN` does the same, and the setting
`default_transaction_isolation` changes the default for a session, a role or a database. The rest of this section
takes each level in turn, with the bugs it lets through.

### Read committed: a fresh snapshot for every statement

Under `READ COMMITTED`, each *statement* takes a new snapshot when it starts. A statement sees everything committed
before it began, and is never confused by changes committed while it runs. But two statements in the same transaction
can see different data. This is the *nonrepeatable read*:

```sql
-- Session A
BEGIN;
SELECT stock FROM products WHERE id = 3;
```

```text
BEGIN
 stock
-------
   400
(1 row)
```

```sql
-- Session B
UPDATE products SET stock = stock - 10 WHERE id = 3;
```

```text
UPDATE 1
```

```sql
-- Session A
SELECT stock FROM products WHERE id = 3;
COMMIT;
```

```text
 stock
-------
   390
(1 row)

COMMIT
```

Inside one transaction, session A read 400 and then 390. For most application code that is fine, and it is why read
committed is the default: it never makes you retry, and each statement sees fresh data. It becomes a problem when
your code reads something, makes a decision, and writes based on it, which the next sections are about.

#### Updates wait, then look again

When a read committed `UPDATE` (or `DELETE`, or `SELECT ... FOR UPDATE`) finds that its target row has been changed by
a transaction that is still running, it **waits** for that transaction to finish. If the other transaction commits,
the waiting statement takes the *new* version of the row, **checks its `WHERE` condition again** against it, and
continues with that version. The rest of the statement still uses its original snapshot.

That re-check is what makes single-statement updates safe. Chapter 4 promised that when two customers buy the last
fountain pens at the same moment, the `CHECK (stock >= 0)` constraint stops one of them. The Heron Fountain Pen
(product 2) has 3 in stock. Session A buys two:

```sql
-- Session A
BEGIN;
UPDATE products SET stock = stock - 2 WHERE id = 2 RETURNING stock;
```

```text
BEGIN
 stock
-------
     1
(1 row)

UPDATE 1
```

Before A commits, session B also tries to buy two. Its statement doesn't return; it waits for session A:

```sql
-- Session B
UPDATE products SET stock = stock - 2 WHERE id = 2 RETURNING stock;
```

When session A commits, session B wakes up, re-reads the row (stock is now 1), computes `1 - 2`, and fails the check:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

Session B's statement now finishes, with an error:

```text
ERROR:  new row for relation "products" violates check constraint "products_stock_check"
DETAIL:  Failing row contains (2, FP-002, Heron Fountain Pen, A weighty brass fountain pen with a medium gold-plated nib, bala..., 3, 129.00, -1, {"nib": "M", "color": "green", "material": "brass"}, {gift}, t, 2025-12-01 09:00:00+00).
```

The stock is 1, not −1. Without the constraint, both updates would have succeeded. An error is a blunt way to say
"sold out", though. Putting the condition in the `WHERE` clause gives a clean "no rows updated" instead, which the
application can turn into a friendly message:

```sql
-- Session A
BEGIN;
UPDATE products SET stock = stock - 1 WHERE id = 2 AND stock >= 1 RETURNING stock;
```

```text
BEGIN
 stock
-------
     0
(1 row)

UPDATE 1
```

```sql
-- Session B
UPDATE products SET stock = stock - 1 WHERE id = 2 AND stock >= 1 RETURNING stock;
```

Session B waits again. When A commits, B re-checks `stock >= 1` against the new version (stock 0), and the row no
longer qualifies:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

```text
 stock
-------
(0 rows)

UPDATE 0
```

The same pattern makes status changes safe. Chapter 9 cancelled an order with `UPDATE orders SET status =
'cancelled' WHERE id = ... AND status = 'pending'`. If two clerks click "cancel" at the same moment, the second one's
update waits, re-checks `status = 'pending'`, finds `'cancelled'`, and updates nothing, so the stock is returned only
once.

> The re-check only looks at the rows the statement was already going to change. Rows that start to match the `WHERE`
> clause because of the other transaction's commit (a product that just became active, say) are not picked up. For
> statements more complicated than "update these rows if they still qualify", use the techniques below.

Put the stock back for the following sections:

```sql
UPDATE products SET stock = 3 WHERE id = 2;
UPDATE products SET stock = 400 WHERE id = 3;
```

```text
UPDATE 1
UPDATE 1
```

### The lost update

The most common concurrency bug in applications is the **lost update**. The code reads a value, computes a new one,
and writes it back:

```text
 Session A                         Session B
 ─────────────────────────────     ─────────────────────────────
 SELECT stock ... → 12
                                   SELECT stock ... → 12
 (app computes 12 - 1 = 11)
 UPDATE ... SET stock = 11
                                   (app computes 12 - 1 = 11)
                                   UPDATE ... SET stock = 11
```

Two pens were sold, but the stock went down by one. Try it:

```sql
-- Session A
SELECT stock FROM products WHERE id = 1;
```

```text
 stock
-------
    12
(1 row)
```

```sql
-- Session B
SELECT stock FROM products WHERE id = 1;
```

```text
 stock
-------
    12
(1 row)
```

```sql
-- Session A
UPDATE products SET stock = 11 WHERE id = 1;
```

```text
UPDATE 1
```

```sql
-- Session B
UPDATE products SET stock = 11 WHERE id = 1;
SELECT stock FROM products WHERE id = 1;
```

```text
UPDATE 1
 stock
-------
    11
(1 row)
```

Nothing waited and nothing failed: each statement was correct on its own. Putting the `SELECT` and `UPDATE` in one
`BEGIN` ... `COMMIT` doesn't help under read committed either. There are four standard fixes, from simplest to most
general:

1. **Let the database do the arithmetic.** `UPDATE products SET stock = stock - 1 WHERE id = 1` reads and writes in one
   statement, and the wait-and-recheck rule above makes it safe. Prefer this whenever the new value can be expressed
   in SQL, which is more often than you'd think (`stock - $1`, `balance + $2`, `jsonb_set(...)`, `array_append(...)`).
2. **Lock the row while you think** (pessimistic locking): `SELECT ... FOR UPDATE` inside a transaction. Other
   transactions that want to lock or change the row wait until you commit.
3. **Check that nothing changed when you write** (optimistic locking): include the value you read, or a version
   number, in the `WHERE` clause. If someone else changed the row, your update matches no rows, and the application
   re-reads and tries again (or tells the user).
4. **Use repeatable read or serializable**, which detect the conflict and abort one transaction with an error that
   the application retries.

Here is fix 2. Session A locks the row as it reads it:

```sql
-- Session A
BEGIN;
SELECT stock FROM products WHERE id = 1 FOR UPDATE;
```

```text
BEGIN
 stock
-------
    11
(1 row)
```

Session B tries the same and waits, because it also asked for the lock:

```sql
-- Session B
BEGIN;
SELECT stock FROM products WHERE id = 1 FOR UPDATE;
```

Session A writes and commits, and only then does session B's `SELECT` return, with the new value:

```sql
-- Session A
UPDATE products SET stock = 10 WHERE id = 1;
COMMIT;
```

```text
UPDATE 1
COMMIT
```

```text
 stock
-------
    10
(1 row)
```

```sql
-- Session B
UPDATE products SET stock = 9 WHERE id = 1;
COMMIT;
```

```text
UPDATE 1
COMMIT
```

And fix 3, optimistic locking. The application remembers what it read (stock 9) and makes the update conditional on
it. Session A's update succeeds; session B, which read the same 9, gets `UPDATE 0` and knows it must start over:

```sql
-- Session A
UPDATE products SET stock = 8 WHERE id = 1 AND stock = 9;
```

```text
UPDATE 1
```

```sql
-- Session B
UPDATE products SET stock = 8 WHERE id = 1 AND stock = 9;
```

```text
UPDATE 0
```

Comparing a single column works when that column is all you changed. For editing a whole record (a product form in
an admin page, say), add a `version integer NOT NULL DEFAULT 1` column, include `version = <the version you read>` in
the `WHERE` clause, and set `version = version + 1` in every update. Optimistic locking holds no locks while a user
stares at a form, which makes it the right choice for anything involving a human.

Restore the stock:

```sql
UPDATE products SET stock = 12 WHERE id = 1;
```

```text
UPDATE 1
```

### Repeatable read: one snapshot for the whole transaction

Under `REPEATABLE READ`, the transaction takes **one snapshot, at its first statement** (not at `BEGIN`), and every
statement in it uses that snapshot. The transaction sees the database as it was at that moment, plus its own changes.
That gives consistent multi-query reports:

```sql
-- Session A
BEGIN ISOLATION LEVEL REPEATABLE READ;
SELECT sum(stock) AS units, count(*) AS products FROM products;
```

```text
BEGIN
 units | products
-------+----------
  1895 |       14
(1 row)
```

```sql
-- Session B
INSERT INTO products (sku, name, category_id, price, stock)
VALUES ('IN-003', 'Emerald Ink 50ml', 6, 16.00, 20);
UPDATE products SET stock = stock + 100 WHERE id = 3;
```

```text
INSERT 0 1
UPDATE 1
```

```sql
-- Session A
SELECT sum(stock) AS units, count(*) AS products FROM products;
COMMIT;
SELECT sum(stock) AS units, count(*) AS products FROM products;
```

```text
 units | products
-------+----------
  1895 |       14
(1 row)

COMMIT
 units | products
-------+----------
  2015 |       15
(1 row)
```

Inside the transaction, neither the new product (a *phantom*) nor the changed stock appeared. After `COMMIT`, the next
statement runs in a new transaction with a new snapshot and sees both. `pg_dump` uses exactly this to produce a
consistent backup of a database that keeps changing while it runs.

Now, what if a repeatable read transaction tries to *change* a row that someone else changed after its snapshot was
taken? It can't simply wait and re-read like read committed does, because that would mean using data its snapshot
can't see. Instead it fails:

```sql
-- Session A
BEGIN ISOLATION LEVEL REPEATABLE READ;
SELECT stock FROM products WHERE id = 3;
```

```text
BEGIN
 stock
-------
   500
(1 row)
```

```sql
-- Session B
UPDATE products SET stock = stock - 1 WHERE id = 3;
```

```text
UPDATE 1
```

```sql
-- Session A
UPDATE products SET stock = stock - 1 WHERE id = 3;
```

```text
ERROR:  could not serialize access due to concurrent update
```

```sql
-- Session A
ROLLBACK;
```

```text
ROLLBACK
```

This is a **serialization failure**, SQLSTATE `40001`. It isn't a bug in your SQL. It means "this transaction lost a
race; run it again from the start". When retried, the transaction gets a new snapshot that includes session B's change.
The lost update from the previous section is impossible under repeatable read, at the price that **the application
must be ready to retry**.

The same happens if session B's change is still uncommitted: session A's update waits, and fails if B commits (or goes
ahead if B rolls back).

Put the stock back:

```sql
UPDATE products SET stock = 400 WHERE id = 3;
DELETE FROM products WHERE sku = 'IN-003';
```

```text
UPDATE 1
DELETE 1
```

### Write skew, and serializable

Repeatable read stops two transactions from changing *the same row*. It doesn't stop two transactions that each read
some rows, decide based on what they read, and then change *different* rows. This is **write skew**.

Say the shop has a rule: there must always be at least one active fountain pen in the catalog. Two staff members, at
the same time, each want to deactivate a different pen, and each first checks that another one is still active. The
fountain pens are products 1 and 2 (category 3):

```sql
-- Session A
BEGIN ISOLATION LEVEL REPEATABLE READ;
SELECT count(*) FROM products WHERE category_id = 3 AND active;
```

```text
BEGIN
 count
-------
     2
(1 row)
```

```sql
-- Session B
BEGIN ISOLATION LEVEL REPEATABLE READ;
SELECT count(*) FROM products WHERE category_id = 3 AND active;
```

```text
BEGIN
 count
-------
     2
(1 row)
```

Both see two active pens, so both go ahead:

```sql
-- Session A
UPDATE products SET active = false WHERE id = 1;
COMMIT;
```

```text
UPDATE 1
COMMIT
```

```sql
-- Session B
UPDATE products SET active = false WHERE id = 2;
COMMIT;
SELECT count(*) FROM products WHERE category_id = 3 AND active;
```

```text
UPDATE 1
COMMIT
 count
-------
     0
(1 row)
```

No row was changed twice, so repeatable read saw no conflict, but the rule is broken: no fountain pens are left. Each
transaction was correct on its own; together they produced a result that no serial order of the two could have
produced (whichever ran second would have seen only one active pen and stopped).

`SERIALIZABLE` guarantees that the result of concurrent serializable transactions is the same as running them **one
at a time, in some order**. Repeat the experiment at that level:

```sql
UPDATE products SET active = true WHERE category_id = 3;
```

```text
UPDATE 2
```

```sql
-- Session A
BEGIN ISOLATION LEVEL SERIALIZABLE;
SELECT count(*) FROM products WHERE category_id = 3 AND active;
```

```text
BEGIN
 count
-------
     2
(1 row)
```

```sql
-- Session B
BEGIN ISOLATION LEVEL SERIALIZABLE;
SELECT count(*) FROM products WHERE category_id = 3 AND active;
```

```text
BEGIN
 count
-------
     2
(1 row)
```

```sql
-- Session A
UPDATE products SET active = false WHERE id = 1;
COMMIT;
```

```text
UPDATE 1
COMMIT
```

```sql
-- Session B
UPDATE products SET active = false WHERE id = 2;
```

```text
ERROR:  could not serialize access due to read/write dependencies among transactions
DETAIL:  Reason code: Canceled on identification as a pivot, during write.
HINT:  The transaction might succeed if retried.
```

```sql
-- Session B
ROLLBACK;
SELECT count(*) FROM products WHERE category_id = 3 AND active;
```

```text
ROLLBACK
 count
-------
     1
(1 row)
```

PostgreSQL implements serializable with **serializable snapshot isolation (SSI)**. Transactions run on snapshots, like
repeatable read, with no extra blocking. In addition, PostgreSQL remembers what each serializable transaction *read*
(as "predicate locks", which never block anyone) and watches for read/write dependencies between concurrent
transactions. When the dependencies form a pattern that could produce a non-serial result, it aborts one of the
transactions with SQLSTATE `40001`. Here session A changed a pen that session B had read, and then B tried to change a
pen that A had read: a cycle, so B's update was cancelled. When B retries, it sees only one active pen and doesn't
deactivate it.

Things to know before you use serializable:

- **Every transaction must be retryable.** Failures happen under normal load, sometimes even when no anomaly would have
  occurred (false positives, because PostgreSQL tracks reads at the granularity of index pages or whole tables when
  rows would be too expensive).
- **It only protects transactions that all use it.** A read committed transaction running alongside isn't checked.
  If you go serializable, set `default_transaction_isolation = 'serializable'` for the database or the application's
  role, so nothing slips through.
- **Good indexes reduce false positives.** A sequential scan reads (and "predicate-locks") the whole table, so any
  write to it looks like a conflict. An index scan locks only the index ranges it touched.
- **Keep transactions short**, and declare read-only ones `READ ONLY`, which lets PostgreSQL release their tracking
  early. For a long report, `BEGIN ISOLATION LEVEL SERIALIZABLE, READ ONLY, DEFERRABLE` waits until it can get a
  snapshot that is guaranteed safe, and then runs without any risk of failing.

### Choosing an isolation level

- **Read committed** is right for most transactions, *provided* that you write updates so that they are safe under it:
  arithmetic in SQL, conditions in the `WHERE` clause, `FOR UPDATE` where you read before you write, and constraints
  for the rules that must never break.
- **Repeatable read** is the choice for reports and exports that run several queries and need them to agree, and for
  read-modify-write logic where you'd rather retry than lock.
- **Serializable** is the choice when business rules span several rows or tables and can't be expressed as
  constraints, and you'd rather have the database find the races than reason about each one. It costs some throughput
  and requires retries everywhere.

Whatever the level, these errors mean "retry the whole transaction":

| SQLSTATE | Name | Raised by |
|---|---|---|
| `40001` | `serialization_failure` | repeatable read and serializable conflicts |
| `40P01` | `deadlock_detected` | a deadlock (below) |

Retry from `BEGIN`, re-reading everything: the earlier reads are exactly what was out of date. Retry a limited number
of times, with a short random delay. Chapter 22 shows a retry loop in Node.js and PHP.

## Row locks

`SELECT ... FOR UPDATE` was one of four **row-level lock** strengths. `UPDATE` and `DELETE` take them automatically,
and so do foreign key checks:

| Lock | Taken by | Conflicts with |
|---|---|---|
| `FOR KEY SHARE` | `SELECT ... FOR KEY SHARE`; a foreign key check on the referenced row (inserting an order item locks its product) | `FOR UPDATE` |
| `FOR SHARE` | `SELECT ... FOR SHARE` | `FOR NO KEY UPDATE`, `FOR UPDATE` |
| `FOR NO KEY UPDATE` | `UPDATE` that doesn't change a key column; `SELECT ... FOR NO KEY UPDATE` | `FOR SHARE`, `FOR NO KEY UPDATE`, `FOR UPDATE` |
| `FOR UPDATE` | `DELETE`; `UPDATE` of a column in a unique index; `SELECT ... FOR UPDATE` | all four |

The split between "key" and "no key" locks exists so that foreign keys don't cause needless waiting. Changing a
product's price doesn't block someone inserting an order item for that product, because the price isn't part of a key:

```sql
-- Session A
BEGIN;
UPDATE products SET price = 50.00 WHERE id = 1;
```

```text
BEGIN
UPDATE 1
```

```sql
-- Session B
BEGIN;
INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (12, 1, 1, 48.00);
ROLLBACK;
```

```text
BEGIN
INSERT 0 1
ROLLBACK
```

But `SELECT ... FOR UPDATE` (or a `DELETE`) on the product does block the insert, because the product might be about
to disappear:

```sql
-- Session A
ROLLBACK;
BEGIN;
SELECT id FROM products WHERE id = 1 FOR UPDATE;
```

```text
ROLLBACK
BEGIN
 id
----
  1
(1 row)
```

```sql
-- Session B
INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (12, 1, 1, 48.00);
```

Session B waits. When session A rolls back, B's insert goes through; delete the item again afterwards:

```sql
-- Session A
ROLLBACK;
```

```text
ROLLBACK
```

```text
INSERT 0 1
```

```sql
DELETE FROM order_items WHERE order_id = 12 AND product_id = 1;
```

```text
DELETE 1
```

So the lesson: lock only as strongly as you need. Use `FOR NO KEY UPDATE` rather than `FOR UPDATE` when you are going
to update non-key columns, and `FOR SHARE` when you only need the row not to change under you.

Row locks are stored in the row itself (in `xmax`), not in a lock table in memory, so you can lock millions of rows
without running out of anything. A side effect is that taking a row lock writes to the table, like an update does.

### Not waiting: NOWAIT and SKIP LOCKED

Waiting isn't always what you want. Two options change it:

- **`NOWAIT`** fails immediately if a row is locked, with SQLSTATE `55P03` (`lock_not_available`).
- **`SKIP LOCKED`** silently skips locked rows and returns the rest.

```sql
-- Session A
BEGIN;
SELECT id, name FROM products WHERE id IN (1, 2) FOR UPDATE;
```

```text
BEGIN
 id |         name
----+----------------------
  2 | Heron Fountain Pen
  1 | Kestrel Fountain Pen
(2 rows)
```

```sql
-- Session B
SELECT id, name FROM products WHERE id IN (1, 2, 3) FOR UPDATE NOWAIT;
\echo :LAST_ERROR_SQLSTATE
SELECT id, name FROM products WHERE id IN (1, 2, 3) FOR UPDATE SKIP LOCKED;
```

```text
ERROR:  could not obtain lock on row in relation "products"
55P03
 id |        name
----+--------------------
  3 | Everyday Ballpoint
(1 row)
```

```sql
-- Session A
ROLLBACK;
```

```text
ROLLBACK
```

(`LAST_ERROR_SQLSTATE` is a psql variable holding the code of the last error; applications get the same code from
their driver.) `NOWAIT` suits interactive actions where "someone else is editing this, try again later" is a better
answer than a spinning wheel. `SKIP LOCKED` is for work queues, coming up shortly. It deliberately returns an
inconsistent view of the data, so don't use it for anything else.

## Waiting, timeouts and finding the blocker

A transaction that holds locks and then does nothing (because the application is waiting for an HTTP call, or a
developer left a `BEGIN` open in psql) blocks everyone who needs those rows. Let's create that situation and look at it
from a third session. Session A updates a row and stays idle in its transaction:

```sql
-- Session A
BEGIN;
UPDATE products SET stock = stock - 1 WHERE id = 7;
```

```text
BEGIN
UPDATE 1
```

Session B wants the same row, and waits:

```sql
-- Session B
UPDATE products SET stock = stock - 1 WHERE id = 7;
```

Open a third terminal. `pg_stat_activity` shows every session's state, what it waits for, and since when;
`pg_blocking_pids()` returns the process ids blocking a given session:

```sql
-- Session C
SELECT application_name, state, wait_event_type, wait_event,
       (SELECT array_agg(b.application_name)
        FROM pg_stat_activity AS b
        WHERE b.pid = ANY (pg_blocking_pids(a.pid))) AS blocked_by,
       left(query, 45) AS query
FROM pg_stat_activity AS a
WHERE datname = 'inkwell' AND pid <> pg_backend_pid()
ORDER BY application_name;
```

```text
 application_name |        state        | wait_event_type |  wait_event   |  blocked_by   |                     query
------------------+---------------------+-----------------+---------------+---------------+-----------------------------------------------
 session A        | idle in transaction | Client          | ClientRead    |               | UPDATE products SET stock = stock - 1 WHERE i
 session B        | active              | Lock            | transactionid | {"session A"} | UPDATE products SET stock = stock - 1 WHERE i
(2 rows)
```

Read it like this:

- Session A is `idle in transaction`: it holds the lock and isn't doing anything. Its `query` column shows the *last*
  statement it ran, not a current one.
- Session B is `active`, waiting on a `Lock` of type `transactionid`: it waits for session A's transaction to end,
  and `blocked_by` says so.

In real life, the fix is usually in the application (a transaction left open across slow work). In an emergency, you
can end the blocker from SQL: `pg_cancel_backend(pid)` cancels its current statement (useless here, since it's idle),
and `pg_terminate_backend(pid)` ends the whole session, rolling back its transaction. For now, simply let session A
finish, and session B continues:

```sql
-- Session A
ROLLBACK;
```

```text
ROLLBACK
```

```text
UPDATE 1
```

`pg_locks` shows the locks themselves, one row per lock held or requested, which is handy to see what a statement
locks. Every session holds an `ExclusiveLock` on its own transaction id (`transactionid`) and virtual transaction id
(`virtualxid`) while a transaction is running; waiting for a transaction means asking for a share lock on its id. Here
are the table-level locks a simple update takes:

```sql
BEGIN;
UPDATE products SET stock = stock + 1 WHERE id = 7;
SELECT locktype, relation::regclass, mode, granted
FROM pg_locks
WHERE pid = pg_backend_pid() AND locktype = 'relation'
ORDER BY relation::regclass::text;
ROLLBACK;
```

```text
BEGIN
UPDATE 1
 locktype |         relation         |       mode       | granted
----------+--------------------------+------------------+---------
 relation | pg_locks                 | AccessShareLock  | t
 relation | products                 | RowExclusiveLock | t
 relation | products_category_id_idx | RowExclusiveLock | t
 relation | products_pkey            | RowExclusiveLock | t
 relation | products_sku_key         | RowExclusiveLock | t
(5 rows)

ROLLBACK
```

(The `pg_locks` row is the `SELECT` itself, reading the view.) The `UPDATE` took a `RowExclusiveLock` on the table and
its indexes (the table-level lock every data change takes; it conflicts only with operations like `ALTER TABLE`), plus
a row lock that isn't listed here because it lives in the row.

### Timeouts

Rather than waiting forever, put limits on how long things may take. These settings can be set per session with
`SET`, per role or database with `ALTER ROLE ... SET` / `ALTER DATABASE ... SET`, or server-wide. All are off (0) by
default:

| Setting | Limits | When exceeded | SQLSTATE |
|---|---|---|---|
| `statement_timeout` | how long one statement runs | the statement is cancelled | `57014` |
| `lock_timeout` | how long a statement waits for one lock | the statement is cancelled | `55P03` |
| `idle_in_transaction_session_timeout` | how long a session may sit idle inside a transaction | the session is disconnected | `25P03` |
| `transaction_timeout` (17+) | how long a whole transaction (or single statement outside one) lasts | the session is disconnected | `25P04` |
| `idle_session_timeout` | how long a session may sit idle *outside* a transaction | the session is disconnected | `57P05` |

The last three end the whole connection, since that's the only way to stop a session that isn't running anything (a
rolled-back transaction would otherwise leave the application confused about its state).

```sql
SET statement_timeout = '100ms';
SELECT pg_sleep(1);
RESET statement_timeout;
```

```text
SET
ERROR:  canceling statement due to statement timeout
RESET
```

A sensible starting point for a web application's role is a `statement_timeout` of a few seconds and an
`idle_in_transaction_session_timeout` of a minute or so; a reporting role gets longer limits. Timeouts are a safety
net, so set them generously enough that they only stop things that are truly stuck.

## Deadlocks

Two transactions that each hold a lock the other needs would wait forever. That is a **deadlock**:

```text
 Session A                         Session B
 ─────────────────────────────     ─────────────────────────────
 locks product 1
                                   locks product 2
 wants product 2 → waits for B
                                   wants product 1 → waits for A   ✗ deadlock
```

```sql
-- Session A
BEGIN;
UPDATE products SET stock = stock - 1 WHERE id = 1;
```

```text
BEGIN
UPDATE 1
```

```sql
-- Session B
BEGIN;
UPDATE products SET stock = stock - 1 WHERE id = 2;
```

```text
BEGIN
UPDATE 1
```

```sql
-- Session A
UPDATE products SET stock = stock - 1 WHERE id = 2;
```

Session A waits for B. Now B asks for the row A holds:

```sql
-- Session B
UPDATE products SET stock = stock - 1 WHERE id = 1;
```

```text
ERROR:  deadlock detected
DETAIL:  Process 4952 waits for ShareLock on transaction 16825; blocked by process 4950.
Process 4950 waits for ShareLock on transaction 16826; blocked by process 4952.
HINT:  See server log for query details.
CONTEXT:  while updating tuple (0,19) in relation "products"
```

Session A's update goes through at the same moment:

```text
UPDATE 1
```

When a session has been waiting for a lock for `deadlock_timeout` (1 second by default), PostgreSQL checks for a cycle
of waiting sessions. It found one, and aborted session B's transaction (SQLSTATE `40P01`) so that session A could
continue. Your process and transaction ids will differ. The server log has the full statements involved. Finish both
transactions:

```sql
-- Session A
ROLLBACK;
```

```text
ROLLBACK
```

```sql
-- Session B
ROLLBACK;
```

```text
ROLLBACK
```

Deadlocks are not a database malfunction; they are the database refusing to hang. The application treats them like a
serialization failure and retries. Better still, avoid them:

- **Lock in a consistent order.** If every transaction that updates several products does so in order of `id`,
  no cycle can form. An order with several items should update the stock of its products sorted by id, for example
  with one statement: `UPDATE products SET stock = stock - v.qty FROM (VALUES ...) AS v(id, qty) WHERE products.id =
  v.id` (a single statement can still lock rows in any order, so where it matters, lock first with `SELECT ... ORDER
  BY id FOR UPDATE`).
- **Keep transactions short**, so that they hold locks briefly and rarely overlap.
- **Lock what you need up front**, with `SELECT ... FOR UPDATE`, instead of upgrading a read to a write halfway
  through.

## Advisory locks

Sometimes the thing you need to protect isn't a row: "only one instance of the nightly report job may run", or "only
one process may run migrations". **Advisory locks** are locks on arbitrary numbers that mean whatever your application
decides. PostgreSQL never takes them by itself.

```sql
-- Session A
SELECT pg_try_advisory_lock(17001) AS got_it;
```

```text
 got_it
--------
 t
(1 row)
```

```sql
-- Session B
SELECT pg_try_advisory_lock(17001) AS got_it;
```

```text
 got_it
--------
 f
(1 row)
```

Session B didn't get it, so it would skip the job this time. Session A releases the lock when it's done:

```sql
-- Session A
SELECT pg_advisory_unlock(17001);
```

```text
 pg_advisory_unlock
--------------------
 t
(1 row)
```

The family of functions has two dimensions:

| | waits | doesn't wait (returns `true`/`false`) |
|---|---|---|
| **session-level** (held until unlocked or disconnect) | `pg_advisory_lock(key)` | `pg_try_advisory_lock(key)` |
| **transaction-level** (released at commit or rollback) | `pg_advisory_xact_lock(key)` | `pg_try_advisory_xact_lock(key)` |

The key is a `bigint` (or two `integer`s). To lock on a name, hash it: `pg_advisory_xact_lock(hashtext('nightly
report'))`. There are also `_shared` variants, which act like read locks.

Prefer the transaction-level versions: they can't leak. A session-level lock taken through a connection pool stays
with the pooled connection if your code forgets to unlock it, and with a pooler in transaction mode (chapter 22) the
unlock may even run on a different server connection than the lock.

## Job queues with SKIP LOCKED

Many applications need a background job queue: send the order confirmation email, generate the invoice PDF, call the
shipping company's API. A table plus `FOR UPDATE SKIP LOCKED` makes a reliable queue, inside the database you already
have, with jobs that are created atomically with the data they belong to.

```sql
CREATE TABLE jobs (
    id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind      text NOT NULL,
    payload   jsonb NOT NULL DEFAULT '{}',
    run_at    timestamptz NOT NULL DEFAULT now(),
    attempts  integer NOT NULL DEFAULT 0
);
CREATE INDEX jobs_run_at_idx ON jobs (run_at);

INSERT INTO jobs (kind, payload) VALUES
    ('send_email', '{"template": "order_paid", "order_id": 10}'),
    ('send_email', '{"template": "order_paid", "order_id": 11}'),
    ('make_invoice', '{"order_id": 10}');
```

```text
CREATE TABLE
CREATE INDEX
INSERT 0 3
```

A worker takes the next due job in a transaction, does the work, and deletes the job before committing:

```sql
-- Session A
BEGIN;
SELECT id, kind, payload
FROM jobs
WHERE run_at <= now()
ORDER BY run_at, id
LIMIT 1
FOR UPDATE SKIP LOCKED;
```

```text
BEGIN
 id |    kind    |                  payload
----+------------+--------------------------------------------
  1 | send_email | {"order_id": 10, "template": "order_paid"}
(1 row)
```

A second worker, running at the same time, skips the locked job and gets the next one instead of waiting:

```sql
-- Session B
BEGIN;
SELECT id, kind, payload
FROM jobs
WHERE run_at <= now()
ORDER BY run_at, id
LIMIT 1
FOR UPDATE SKIP LOCKED;
```

```text
BEGIN
 id |    kind    |                  payload
----+------------+--------------------------------------------
  2 | send_email | {"order_id": 11, "template": "order_paid"}
(1 row)
```

Worker A finishes its job and removes it. Worker B "crashes": its connection drops, which rolls back its transaction,
so job 2 is unlocked and still in the table for the next worker:

```sql
-- Session A
DELETE FROM jobs WHERE id = 1;
COMMIT;
```

```text
DELETE 1
COMMIT
```

```sql
-- Session B
ROLLBACK;
SELECT id, kind FROM jobs ORDER BY id;
```

```text
ROLLBACK
 id |     kind
----+--------------
  2 | send_email
  3 | make_invoice
(2 rows)
```

This design is simple and robust:

- A job is never processed by two workers at once (the row lock), and a crashed worker's job is never lost (the
  rollback).
- Creating the job in the same transaction as the business change (`UPDATE orders SET status = 'paid' ...; INSERT INTO
  jobs ...; COMMIT`) means a job exists if and only if the change committed. This is the **transactional outbox**
  pattern: no "order saved but email job lost" or "email sent for an order that rolled back".
- Workers can wake up instantly instead of polling, with `LISTEN`/`NOTIFY` (chapter 19).

Its limits: the transaction stays open for the whole job, holding a connection and preventing `VACUUM` from cleaning
up behind it (chapter 18), so it suits jobs that take seconds, not hours. For long jobs, use the lock only to *claim*
the job (set `locked_until = now() + interval '10 minutes'` and commit), then process it outside the transaction, and
let other workers take over jobs whose claim expired. Exercise 5 builds retries with backoff. At very high volumes
(thousands of jobs per second), the constant inserts and deletes need careful vacuuming, and a dedicated queue system
may be a better fit.

## Gap-free numbers

Chapter 4 explained that identity columns have gaps, because a sequence value handed out to a transaction that rolls
back is never reused. Most of the time that's fine. Invoice numbers are the classic exception: in many countries they
must be consecutive, with no gaps. The solution, sketched in chapter 4's exercises, is a counter row updated in the
same transaction as the invoice:

```sql
CREATE TABLE invoice_counters (
    year         integer PRIMARY KEY,
    last_number  integer NOT NULL DEFAULT 0
);

CREATE TABLE invoices (
    year       integer NOT NULL,
    number     integer NOT NULL,
    order_id   bigint NOT NULL UNIQUE REFERENCES orders (id),
    issued_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (year, number)
);

INSERT INTO invoice_counters (year) VALUES (2026);
```

```text
CREATE TABLE
CREATE TABLE
INSERT 0 1
```

One statement increments the counter and inserts the invoice, using a data-modifying CTE (chapter 9). Session A
issues an invoice for order 10, but hasn't committed yet:

```sql
-- Session A
BEGIN;
WITH next AS (
    UPDATE invoice_counters SET last_number = last_number + 1
    WHERE year = 2026
    RETURNING year, last_number
)
INSERT INTO invoices (year, number, order_id)
SELECT year, last_number, 10 FROM next
RETURNING year, number, order_id;
```

```text
BEGIN
 year | number | order_id
------+--------+----------
 2026 |      1 |       10
(1 row)

INSERT 0 1
```

Session B issues one for order 11 at the same time. It waits for the counter row:

```sql
-- Session B
WITH next AS (
    UPDATE invoice_counters SET last_number = last_number + 1
    WHERE year = 2026
    RETURNING year, last_number
)
INSERT INTO invoices (year, number, order_id)
SELECT year, last_number, 11 FROM next
RETURNING year, number, order_id;
```

Session A's transaction then fails for some reason and rolls back. The counter update rolls back with it, and session
B gets number 1:

```sql
-- Session A
ROLLBACK;
```

```text
ROLLBACK
```

```text
 year | number | order_id
------+--------+----------
 2026 |      1 |       11
(1 row)

INSERT 0 1
```

No gap. The price is in that wait: invoices are issued strictly one at a time, each waiting for the previous
transaction to finish. Keep the invoice transaction short (no PDF rendering or emailing inside it; queue a job for
that), and this comfortably handles thousands of invoices a day. Don't use a counter row for things that don't legally
need it.

## Deferred constraint checks

Constraints are normally checked as each statement finishes. A constraint declared `DEFERRABLE` can postpone its check
until `COMMIT`, which helps when a transaction must pass through an invalid state on its way to a valid one. The shop's
home page shows featured products in numbered slots, and staff want to swap two of them:

```sql
CREATE TABLE homepage_slots (
    slot        integer PRIMARY KEY,
    product_id  bigint NOT NULL REFERENCES products (id),
    CONSTRAINT homepage_slots_product_unique UNIQUE (product_id) DEFERRABLE INITIALLY IMMEDIATE
);
INSERT INTO homepage_slots VALUES (1, 2), (2, 7), (3, 11);
```

```text
CREATE TABLE
INSERT 0 3
```

Swapping slots 1 and 2 takes two updates, and after the first one, product 7 is in two slots:

```sql
BEGIN;
UPDATE homepage_slots SET product_id = 7 WHERE slot = 1;
ROLLBACK;
```

```text
BEGIN
ERROR:  duplicate key value violates unique constraint "homepage_slots_product_unique"
DETAIL:  Key (product_id)=(7) already exists.
ROLLBACK
```

`INITIALLY IMMEDIATE` means the constraint behaves normally unless a transaction asks otherwise. With `SET
CONSTRAINTS`, this transaction defers the check to `COMMIT`, by which time the data is valid again:

```sql
BEGIN;
SET CONSTRAINTS homepage_slots_product_unique DEFERRED;
UPDATE homepage_slots SET product_id = 7 WHERE slot = 1;
UPDATE homepage_slots SET product_id = 2 WHERE slot = 2;
COMMIT;
SELECT * FROM homepage_slots ORDER BY slot;
```

```text
BEGIN
SET CONSTRAINTS
UPDATE 1
UPDATE 1
COMMIT
 slot | product_id
------+------------
    1 |          7
    2 |          2
    3 |         11
(3 rows)
```

If the data were still invalid at `COMMIT`, the commit would fail and the transaction would roll back. `UNIQUE`,
primary key, foreign key and exclusion constraints can be deferrable; `CHECK` and `NOT NULL` can't. Deferrable unique
constraints are a little slower, and can't be used as the target of `ON CONFLICT` or be referenced by foreign keys, so
declare them deferrable only where you need it.

## Table locks and safe schema changes

Besides row locks, every statement takes a **table-level lock** on each table it uses. There are eight modes; the ones
you'll meet are:

| Mode | Taken by | Blocks |
|---|---|---|
| `ACCESS SHARE` | `SELECT` | only `ACCESS EXCLUSIVE` |
| `ROW SHARE` | `SELECT ... FOR UPDATE` and the other locking reads | `EXCLUSIVE`, `ACCESS EXCLUSIVE` |
| `ROW EXCLUSIVE` | `INSERT`, `UPDATE`, `DELETE`, `MERGE` | `SHARE` and stronger |
| `SHARE UPDATE EXCLUSIVE` | `VACUUM`, `ANALYZE`, `CREATE INDEX CONCURRENTLY`, `ALTER TABLE ... VALIDATE CONSTRAINT` | itself and stronger (not reads or writes) |
| `SHARE` | `CREATE INDEX` | writes (`ROW EXCLUSIVE`), and stronger |
| `SHARE ROW EXCLUSIVE` | `CREATE TRIGGER`, adding a foreign key (on both tables) | writes, and stronger |
| `ACCESS EXCLUSIVE` | `DROP TABLE`, `TRUNCATE`, `VACUUM FULL`, `CLUSTER`, `REINDEX` (without `CONCURRENTLY`), most `ALTER TABLE` forms | everything, including plain `SELECT` |

Normal reads and writes never conflict with each other at table level. The trouble is `ACCESS EXCLUSIVE`, which most
`ALTER TABLE` commands need, even ones that finish in a millisecond like adding a nullable column. And there is a trap
that has taken down many production sites: **the lock queue**.

Session A runs a long report (simulated here by a transaction that stays open after a `SELECT`):

```sql
-- Session A
BEGIN;
SELECT count(*) FROM products;
```

```text
BEGIN
 count
-------
    14
(1 row)
```

Session B runs a migration that adds a column. It needs `ACCESS EXCLUSIVE`, which conflicts with A's `ACCESS SHARE`,
so it waits:

```sql
-- Session B
ALTER TABLE products ADD COLUMN discontinued_at timestamptz;
```

Now a customer loads a product page. A plain `SELECT` doesn't conflict with A's lock, but **it queues behind B's
waiting `ACCESS EXCLUSIVE` request**, because locks are granted in order. In a fourth terminal:

```sql
-- Session D
SET application_name = 'session D';
```

```text
SET
```

```sql
-- Session D
SELECT name FROM products WHERE id = 1;
```

Session D's `SELECT` hangs. So would every other query on `products`: one slow report plus one quick migration has
blocked the whole table. Session C shows the chain:

```sql
-- Session C
SELECT application_name, state, wait_event_type, wait_event,
       (SELECT array_agg(b.application_name)
        FROM pg_stat_activity AS b
        WHERE b.pid = ANY (pg_blocking_pids(a.pid))) AS blocked_by,
       left(query, 45) AS query
FROM pg_stat_activity AS a
WHERE datname = 'inkwell' AND pid <> pg_backend_pid()
ORDER BY application_name;
```

```text
 application_name |        state        | wait_event_type | wait_event |  blocked_by   |                     query
------------------+---------------------+-----------------+------------+---------------+-----------------------------------------------
 session A        | idle in transaction | Client          | ClientRead |               | SELECT count(*) FROM products;
 session B        | active              | Lock            | relation   | {"session A"} | ALTER TABLE products ADD COLUMN discontinued_
 session D        | active              | Lock            | relation   | {"session B"} | SELECT name FROM products WHERE id = 1;
(3 rows)
```

When the report finishes, the migration runs, and then the page query:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

```text
ALTER TABLE
         name
----------------------
 Kestrel Fountain Pen
(1 row)
```

The fix is to make migrations give up quickly instead of waiting in the queue, and retry later. Set **`lock_timeout`**
before any DDL on a busy table:

```sql
-- Session A
BEGIN;
SELECT count(*) FROM products;
```

```text
BEGIN
 count
-------
    14
(1 row)
```

```sql
-- Session B
SET lock_timeout = '1s';
ALTER TABLE products DROP COLUMN discontinued_at;
\echo :LAST_ERROR_SQLSTATE
```

```text
SET
ERROR:  canceling statement due to lock timeout
55P03
```

The migration waited for one second, during which other queries queued behind it, and then stepped aside. A migration
tool would retry a few times, with pauses in between, until it catches a moment when no long transaction is running.
Finish the report, and the retry succeeds:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

```sql
-- Session B
ALTER TABLE products DROP COLUMN discontinued_at;
RESET lock_timeout;
```

```text
ALTER TABLE
RESET
```

### A checklist for changing a busy schema

Chapter 4 listed which `ALTER TABLE` forms rewrite the table; combined with the locks, the rules for production
migrations are:

1. **Always set `lock_timeout`** (a few seconds at most) for migration sessions, and retry on failure.
2. **Keep each DDL transaction short.** Don't mix a quick `ALTER TABLE` with a slow data backfill in one transaction:
   the `ACCESS EXCLUSIVE` lock is held until commit.
3. **Avoid rewrites.** Adding a column with no default or a constant default is instant; changing a column's type
   usually isn't.
4. **Build indexes with `CREATE INDEX CONCURRENTLY`** (chapter 15), which allows writes while it runs.
5. **Add constraints in two steps**: `ADD CONSTRAINT ... NOT VALID` takes a brief lock and checks only new rows; a
   later `VALIDATE CONSTRAINT` checks the existing rows under a `SHARE UPDATE EXCLUSIVE` lock, which doesn't block reads
   or writes. This works for `CHECK`, foreign keys, and, since PostgreSQL 18, `NOT NULL`.
6. **Backfill in batches**: update a few thousand rows per transaction, so that no transaction holds many row locks
   for long.
7. **Make changes backward compatible.** Code and schema are deployed at different moments, so a column is added
   before the code uses it, and dropped only after no running code reads it.

Here is step 5 for `NOT NULL`. The shop has decided that every review needs a body; old reviews without one get an
empty string first, and then the constraint is added and validated in separate transactions:

```sql
UPDATE reviews SET body = '' WHERE body IS NULL;
ALTER TABLE reviews ADD CONSTRAINT reviews_body_not_null NOT NULL body NOT VALID;
ALTER TABLE reviews VALIDATE CONSTRAINT reviews_body_not_null;
```

```text
UPDATE 1
ALTER TABLE
ALTER TABLE
```

On a large table, the `UPDATE` would run in batches, and the `NOT VALID` step makes sure no new `NULL`s arrive while
the backfill runs. Before PostgreSQL 18, the same effect needed a detour: a `CHECK (body IS NOT NULL) NOT VALID`
constraint, validated, after which `ALTER COLUMN body SET NOT NULL` can skip its full-table scan.

## Two-phase commit, briefly

A transaction that must commit in two databases at once (or in a database and a message broker) can't be made atomic
with ordinary transactions. PostgreSQL supports **two-phase commit**: `PREPARE TRANSACTION 'id'` saves a transaction
so that it survives even a crash, and a later `COMMIT PREPARED 'id'` or `ROLLBACK PREPARED 'id'`, possibly from a
different session, finishes it. It is disabled by default (`max_prepared_transactions = 0`) and meant for transaction
managers, not application code: a forgotten prepared transaction holds its locks forever. Most applications are
better served by the outbox pattern from the job queue section.

## Key takeaways

- Every statement runs in a transaction. MVCC keeps several versions of each row (`xmin`/`xmax`), so readers and
  writers don't block each other; only writers of the same row wait for each other.
- Read committed (the default) gives each statement a fresh snapshot. Updates that find a locked row wait, then
  re-check their `WHERE` clause against the new version, so single-statement updates with conditions are safe.
- The lost update is the classic bug: read, compute in the application, write. Fix it with arithmetic in SQL,
  `SELECT ... FOR UPDATE`, optimistic `WHERE version = ...` checks, or a stricter isolation level.
- Repeatable read uses one snapshot for the whole transaction, giving consistent reports, and fails with `40001` when
  it would overwrite a concurrent change. Serializable also catches write skew. Both require retrying the whole
  transaction.
- Row locks come in four strengths; foreign key checks take the weakest. `NOWAIT` fails instead of waiting,
  `SKIP LOCKED` skips locked rows and is the basis of reliable job queues.
- Find blockers with `pg_stat_activity` and `pg_blocking_pids()`. Limit damage with `lock_timeout`,
  `statement_timeout` and `idle_in_transaction_session_timeout`.
- Deadlocks are detected and one transaction is aborted with `40P01`; lock rows in a consistent order to avoid them.
- Advisory locks protect application-defined resources; prefer the transaction-level ones.
- DDL on a busy table can block everything through the lock queue. Run migrations with `lock_timeout` and retries,
  avoid rewrites, and use `CONCURRENTLY` and `NOT VALID` + `VALIDATE`.

## Exercises

Start each exercise from a freshly reset database (`psql -X -d postgres -f sql/reset.sql`), and reconnect your
sessions afterwards, since the reset drops the database. Exercises that need two sessions say so.

1. **Predict, then check.** In two sessions, run this sequence and predict what each `SELECT` in session A returns
   before you run it: A: `BEGIN ISOLATION LEVEL REPEATABLE READ;` B: `UPDATE products SET price = 2.75 WHERE id = 3;`
   A: `SELECT price FROM products WHERE id = 3;` B: `UPDATE products SET price = 3.00 WHERE id = 3;` A: `SELECT price
   FROM products WHERE id = 3; COMMIT;`

<details>
<summary>Solution</summary>

```sql
-- Session A
BEGIN ISOLATION LEVEL REPEATABLE READ;
```

```text
BEGIN
```

```sql
-- Session B
UPDATE products SET price = 2.75 WHERE id = 3;
```

```text
UPDATE 1
```

```sql
-- Session A
SELECT price FROM products WHERE id = 3;
```

```text
 price
-------
  2.75
(1 row)
```

```sql
-- Session B
UPDATE products SET price = 3.00 WHERE id = 3;
```

```text
UPDATE 1
```

```sql
-- Session A
SELECT price FROM products WHERE id = 3;
COMMIT;
```

```text
 price
-------
  2.75
(1 row)

COMMIT
```

Both `SELECT`s return 2.75. The first one surprises many people: `BEGIN` doesn't take the snapshot; the first
statement does. B's first update committed before A's first `SELECT`, so A's snapshot includes it. The second update
came after the snapshot, so A doesn't see 3.00. Under read committed, the second `SELECT` would have returned 3.00.

</details>

2. **Buy a basket safely.** Order 12 (customer 5, pending) is being paid. In one transaction, reduce the stock of every
   product in the order by its quantity, but only if *all* of them are in stock; otherwise change nothing. Write it so
   that it's safe under read committed with concurrent buyers, and so that two such transactions can't deadlock.
   Then make the test fail by setting the stock of product 7 to 2 first.

<details>
<summary>Solution</summary>

Lock the products in id order first, then update them with a condition, and compare the number of rows updated with
the number of items:

```sql
BEGIN;
SELECT p.id, p.stock, oi.quantity
FROM products AS p
JOIN order_items AS oi ON oi.product_id = p.id
WHERE oi.order_id = 12
ORDER BY p.id
FOR NO KEY UPDATE OF p;

WITH updated AS (
    UPDATE products AS p
    SET stock = p.stock - oi.quantity
    FROM order_items AS oi
    WHERE oi.order_id = 12 AND oi.product_id = p.id AND p.stock >= oi.quantity
    RETURNING p.id
)
SELECT count(*) AS updated,
       (SELECT count(*) FROM order_items WHERE order_id = 12) AS items
FROM updated;
COMMIT;
```

```text
BEGIN
 id | stock | quantity
----+-------+----------
  7 |    75 |        3
(1 row)

 updated | items
---------+-------
       1 |     1
(1 row)

COMMIT
```

`updated` equals `items`, so the application commits. The `SELECT ... FOR NO KEY UPDATE OF p` locks only the product
rows (not the order items), in id order, so two baskets that share products always lock them in the same order and
can't deadlock. After the lock, no one can change those stocks until we commit, so the check and the update agree.

Now with too little stock:

```sql
UPDATE products SET stock = 2 WHERE id = 7;
BEGIN;
SELECT p.id, p.stock, oi.quantity
FROM products AS p
JOIN order_items AS oi ON oi.product_id = p.id
WHERE oi.order_id = 12
ORDER BY p.id
FOR NO KEY UPDATE OF p;

WITH updated AS (
    UPDATE products AS p
    SET stock = p.stock - oi.quantity
    FROM order_items AS oi
    WHERE oi.order_id = 12 AND oi.product_id = p.id AND p.stock >= oi.quantity
    RETURNING p.id
)
SELECT count(*) AS updated,
       (SELECT count(*) FROM order_items WHERE order_id = 12) AS items
FROM updated;
ROLLBACK;
```

```text
UPDATE 1
BEGIN
 id | stock | quantity
----+-------+----------
  7 |     2 |        3
(1 row)

 updated | items
---------+-------
       0 |     1
(1 row)

ROLLBACK
```

Order 12 has a single item, so it's all or nothing anyway, but the same code handles baskets of any size: when
`updated` is less than `items`, the application rolls back, and none of the stocks change. (You could leave the
stock check to the `CHECK` constraint instead, which turns a short basket into an error; the explicit count tells you
that it failed without having to parse an error message.)

</details>

3. **Optimistic editing.** Add a `version` column to `products`. Two staff members open the edit form for the Sepia
   ink (product 12) at the same time. Write the update the form submits so that the second save is detected, and show
   both saves in two sessions.

<details>
<summary>Solution</summary>

```sql
ALTER TABLE products ADD COLUMN version integer NOT NULL DEFAULT 1;
```

```text
ALTER TABLE
```

Both forms loaded the product with `version = 1`. Each save sends the new values and the version it loaded:

```sql
-- Session A
UPDATE products
SET price = 17.00, version = version + 1
WHERE id = 12 AND version = 1
RETURNING price, version;
```

```text
 price | version
-------+---------
 17.00 |       2
(1 row)

UPDATE 1
```

```sql
-- Session B
UPDATE products
SET description = 'Warm brown ink with lovely shading.', version = version + 1
WHERE id = 12 AND version = 1
RETURNING price, version;
```

```text
 price | version
-------+---------
(0 rows)

UPDATE 0
```

Session B's save matched no row, because the version is now 2. The application tells the second editor that the
product was changed in the meantime and shows the current values. Without the check, B's form would have also written
back the price it had loaded (16.00) and silently undone A's change. The two updates also can't interleave badly: if
they run at the same moment, B waits for A's row lock and then re-checks `version = 1` against the new version.

</details>

4. **Write skew with orders.** The shop limits each customer to at most two open (`pending` or `paid`) orders. Customer
   1 has one (order 11). Show in two sessions that two concurrent "check, then insert" transactions under read committed
   can give customer 1 three open orders. Then fix it without changing the isolation level.

<details>
<summary>Solution</summary>

Both sessions check, see one open order, and insert:

```sql
-- Session A
BEGIN;
SELECT count(*) FROM orders WHERE customer_id = 1 AND status IN ('pending', 'paid');
```

```text
BEGIN
 count
-------
     1
(1 row)
```

```sql
-- Session B
BEGIN;
SELECT count(*) FROM orders WHERE customer_id = 1 AND status IN ('pending', 'paid');
```

```text
BEGIN
 count
-------
     1
(1 row)
```

```sql
-- Session A
INSERT INTO orders (customer_id) VALUES (1);
COMMIT;
```

```text
INSERT 0 1
COMMIT
```

```sql
-- Session B
INSERT INTO orders (customer_id) VALUES (1);
COMMIT;
SELECT count(*) FROM orders WHERE customer_id = 1 AND status IN ('pending', 'paid');
```

```text
INSERT 0 1
COMMIT
 count
-------
     3
(1 row)
```

Three open orders. Nothing locked anything: the check only read rows, and the rows each session inserted didn't exist
when the other one checked. The fix is to give the transactions something to conflict on. The customer row is the
natural choice: every transaction that creates an order for a customer locks that customer first.

```sql
DELETE FROM orders WHERE customer_id = 1 AND status = 'pending';
```

```text
DELETE 2
```

```sql
-- Session A
BEGIN;
SELECT id FROM customers WHERE id = 1 FOR NO KEY UPDATE;
SELECT count(*) FROM orders WHERE customer_id = 1 AND status IN ('pending', 'paid');
INSERT INTO orders (customer_id) VALUES (1);
```

```text
BEGIN
 id
----
  1
(1 row)

 count
-------
     1
(1 row)

INSERT 0 1
```

```sql
-- Session B
BEGIN;
SELECT id FROM customers WHERE id = 1 FOR NO KEY UPDATE;
```

Session B waits on the customer row. When A commits, B's lock goes through, and its count, a new statement with a new
snapshot under read committed, sees A's order:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

```text
 id
----
  1
(1 row)
```

```sql
-- Session B
SELECT count(*) FROM orders WHERE customer_id = 1 AND status IN ('pending', 'paid');
ROLLBACK;
```

```text
 count
-------
     2
(1 row)

ROLLBACK
```

Session B sees two open orders and doesn't insert. This "materialize the conflict" technique (lock a parent row that
represents the rule) is how you protect rules over a *set* of rows without serializable. Serializable would also have
worked, with a retry instead of a wait. A `FOR NO KEY UPDATE` lock is enough because nobody changes the customer's id.
Note that the lock doesn't block the foreign key check of the `INSERT`, which only needs `FOR KEY SHARE`.

</details>

5. **Retries with backoff.** Extend the job queue from this chapter: when a job fails, the worker should put it back
   with `attempts` increased and `run_at` pushed into the future by 2^attempts minutes (1, 2, 4, 8, ...). After 5
   failed attempts, move the job to a `failed_jobs` table instead. Write the two statements for "job 1 failed".

<details>
<summary>Solution</summary>

```sql
CREATE TABLE jobs (
    id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind      text NOT NULL,
    payload   jsonb NOT NULL DEFAULT '{}',
    run_at    timestamptz NOT NULL DEFAULT now(),
    attempts  integer NOT NULL DEFAULT 0
);
CREATE TABLE failed_jobs (LIKE jobs, failed_at timestamptz NOT NULL DEFAULT now());
INSERT INTO jobs (kind, payload) VALUES ('send_email', '{"order_id": 10}');
```

```text
CREATE TABLE
CREATE TABLE
INSERT 0 1
```

The worker took job 1 with `FOR UPDATE SKIP LOCKED` (in the same transaction, so the row is still locked) and the
work failed. Reschedule it, unless this was the last attempt:

```sql
BEGIN;
SELECT id FROM jobs WHERE id = 1 FOR UPDATE SKIP LOCKED;

UPDATE jobs
SET attempts = attempts + 1,
    run_at = now() + interval '1 minute' * 2 ^ attempts
WHERE id = 1 AND attempts + 1 < 5
RETURNING attempts, run_at - now() AS delay;
COMMIT;
```

```text
BEGIN
 id
----
  1
(1 row)

 attempts |  delay
----------+----------
        1 | 00:01:00
(1 row)

UPDATE 1
COMMIT
```

The delay is computed from the *old* `attempts` (0), because all expressions in `SET` see the row as it was before
the update. Simulate four more failures by setting `attempts = 4`, and the fifth failure moves the job:

```sql
UPDATE jobs SET attempts = 4 WHERE id = 1;

BEGIN;
WITH moved AS (
    DELETE FROM jobs WHERE id = 1 AND attempts + 1 >= 5
    RETURNING id, kind, payload, run_at, attempts + 1 AS attempts
)
INSERT INTO failed_jobs (id, kind, payload, run_at, attempts)
SELECT * FROM moved
RETURNING id, kind, attempts;
COMMIT;
SELECT count(*) AS jobs_left FROM jobs;
```

```text
UPDATE 1
BEGIN
 id |    kind    | attempts
----+------------+----------
  1 | send_email |        5
(1 row)

INSERT 0 1
COMMIT
 jobs_left
-----------
         0
(1 row)
```

In a real worker, both statements run every time and exactly one of them matches, depending on `attempts`. Note the
`LIKE jobs` copy has no identity column, so the job keeps its id in `failed_jobs`.

</details>

6. **Who is blocking whom.** Write a monitoring query that lists every waiting session together with the session
   blocking it: both application names, how long the waiting session has waited, and both sessions' queries. Test it
   with session A holding a lock on order 12 and session B trying to update the same order.

<details>
<summary>Solution</summary>

Set up the blocking situation:

```sql
-- Session A
SET application_name = 'session A';
BEGIN;
UPDATE orders SET status = 'paid' WHERE id = 12;
```

```text
SET
BEGIN
UPDATE 1
```

```sql
-- Session B
SET application_name = 'session B';
UPDATE orders SET status = 'cancelled' WHERE id = 12 AND status = 'pending';
```

Session B waits. Then, in a third session, expand `pg_blocking_pids()` with `unnest` and join back to
`pg_stat_activity`:

```sql
-- Session C
SELECT waiting.application_name AS waiting,
       blocker.application_name AS blocked_by,
       now() - waiting.query_start > interval '1 second' AS waited_over_1s,
       left(waiting.query, 40) AS waiting_query,
       blocker.state AS blocker_state,
       left(blocker.query, 40) AS blocker_query
FROM pg_stat_activity AS waiting
CROSS JOIN LATERAL unnest(pg_blocking_pids(waiting.pid)) AS b(pid)
JOIN pg_stat_activity AS blocker ON blocker.pid = b.pid
ORDER BY waiting.query_start;
```

```text
  waiting  | blocked_by | waited_over_1s |              waiting_query               |    blocker_state    |              blocker_query
-----------+------------+----------------+------------------------------------------+---------------------+------------------------------------------
 session B | session A  | t              | UPDATE orders SET status = 'cancelled' W | idle in transaction | UPDATE orders SET status = 'paid' WHERE
(1 row)
```

In practice you'd show `now() - waiting.query_start` as an interval and include both `pid`s, so that you can pass the
blocker's to `pg_terminate_backend()`; they're left out here because they differ on every machine. When session A
commits, session B re-checks its condition, finds the order is no longer pending, and updates nothing:

```sql
-- Session A
COMMIT;
```

```text
COMMIT
```

```text
UPDATE 0
```

</details>

---
← Previous: [Query planning and EXPLAIN](16-query-planning-and-explain.md) | Next: [Storage, VACUUM and maintenance](18-storage-vacuum-and-maintenance.md) →
