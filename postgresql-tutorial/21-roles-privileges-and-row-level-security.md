# Chapter 21 — Roles, privileges and row-level security

## What you'll learn

- Roles: how PostgreSQL models users and groups with one concept, role attributes, and membership options
- Ownership, and why the role that owns your tables should not be the role your application logs in as
- Privileges on databases, schemas, tables, columns, sequences and functions; reading access control lists
- Default privileges, so that tables created next month are as well protected as the ones you have today
- Schemas and `search_path` as a security concern, and the temporary-table trick against `SECURITY DEFINER` functions
- Views as a security boundary: leaky views, `security_barrier` and `LEAKPROOF`
- Row-level security: policies per role and per command, permissive and restrictive policies, who bypasses them,
  `security_invoker` views, performance and pitfalls
- Client authentication with `pg_hba.conf`, SCRAM passwords, and TLS with `sslmode=verify-full`

Up to now every example ran as `postgres`, a superuser that may do anything. That's convenient on your own machine
and dangerous everywhere else: an application that connects as a superuser turns every SQL injection bug into "the
attacker owns the server", and every bug in a `DELETE` into a disaster nothing stops. This chapter gives Inkwell a
proper set of roles, each allowed exactly what it needs, and then goes one level deeper with row-level security, where
the database itself decides *which rows* each user may see.

## Setting up

Start from the small Inkwell database:

```bash
psql -X -v ON_ERROR_STOP=1 -d postgres -f sql/reset.sql
```

Unlike tables, **roles don't live inside a database**: they belong to the whole cluster, so `reset.sql` (which drops
and recreates the `inkwell` database) doesn't remove them. If you are repeating this chapter, drop the roles it
creates first. On a first run you'll see a notice for each role that doesn't exist yet:

```sql
DROP ROLE IF EXISTS inkwell_app, inkwell_report, inkwell_portal, inkwell_partner, inkwell_migrate,
    inkwell_support, inkwell_tenant_app, inkwell_readwrite, inkwell_readonly, inkwell_owner;
```

## Roles

PostgreSQL has a single concept for "users" and "groups": the **role**. A role that may log in is what other systems
call a user; a role that others are members of acts as a group; and a role can be both. Roles are cluster-wide (they
are stored in the shared catalog `pg_authid`, visible in every database through the `pg_roles` view), while privileges
are granted on objects inside a particular database.

### Creating roles

```sql
CREATE ROLE inkwell_owner NOLOGIN;
CREATE ROLE inkwell_app LOGIN PASSWORD 'app-dev-password' CONNECTION LIMIT 20;
\du inkwell*
```

```text
CREATE ROLE
CREATE ROLE
         List of roles
   Role name   |   Attributes
---------------+----------------
 inkwell_app   | 20 connections
 inkwell_owner | Cannot login
```

`inkwell_owner` will own Inkwell's tables, and nobody logs in as it directly. `inkwell_app` is what the web
application logs in as. `CREATE USER` is the same as `CREATE ROLE ... LOGIN`; the rest of this chapter uses
`CREATE ROLE` and says `LOGIN` explicitly.

The words after the role name are **attributes**:

| Attribute (default first) | Meaning |
|---|---|
| `NOLOGIN` / `LOGIN` | whether the role can connect at all |
| `NOSUPERUSER` / `SUPERUSER` | a superuser bypasses every permission check. Have as few as possible, and never let an application use one |
| `NOCREATEDB` / `CREATEDB` | may create databases |
| `NOCREATEROLE` / `CREATEROLE` | may create roles, and manage the roles it has `ADMIN` rights on (16+: only those) |
| `NOREPLICATION` / `REPLICATION` | may start streaming replication and backups (chapter 23) |
| `NOBYPASSRLS` / `BYPASSRLS` | ignores row-level security policies (later in this chapter) |
| `CONNECTION LIMIT n` | at most *n* concurrent connections for this role (`-1`, the default, means no limit) |
| `PASSWORD '...'` | a password for password authentication; `PASSWORD NULL` removes it |
| `VALID UNTIL 'timestamp'` | the password stops working after this time (the role itself doesn't expire) |
| `INHERIT` / `NOINHERIT` | the default for whether this role uses the privileges of roles it's a member of (see below) |

Change them later with `ALTER ROLE inkwell_app CONNECTION LIMIT 50;`, rename with `ALTER ROLE ... RENAME TO`, and
remove a role with `DROP ROLE`, which refuses while the role still owns objects or holds privileges anywhere in the
cluster (`REASSIGN OWNED` and `DROP OWNED` clean that up, database by database).

Typing a password into SQL, as above, is fine for a development password. A real one ends up in your shell history,
in `psql`'s history file and possibly in the server log. In `psql`, use **`\password inkwell_app`** instead: it asks
for the password twice, hashes it on the client and sends only the hash. The server never stores the password itself,
only a SCRAM verifier (more in [Authentication](#authentication)).

### Membership: roles as groups

Granting privileges to individual login roles doesn't scale. The usual design is a few **group roles** that carry the
privileges, and login roles that are members of them:

```sql
CREATE ROLE inkwell_readonly NOLOGIN;
CREATE ROLE inkwell_readwrite NOLOGIN;
CREATE ROLE inkwell_report LOGIN PASSWORD 'report-dev-password';

GRANT inkwell_readonly TO inkwell_readwrite;   -- read-write includes read
GRANT inkwell_readwrite TO inkwell_app;
GRANT inkwell_readonly TO inkwell_report;
\drg inkwell*
```

```text
CREATE ROLE
CREATE ROLE
CREATE ROLE
GRANT ROLE
GRANT ROLE
GRANT ROLE
                       List of role grants
     Role name     |     Member of     |   Options    | Grantor
-------------------+-------------------+--------------+----------
 inkwell_app       | inkwell_readwrite | INHERIT, SET | postgres
 inkwell_readwrite | inkwell_readonly  | INHERIT, SET | postgres
 inkwell_report    | inkwell_readonly  | INHERIT, SET | postgres
(3 rows)
```

`\drg` ("describe role grants", psql 16+) lists memberships. The result is a small hierarchy:

```text
inkwell_readonly            (may read)
   ├── inkwell_report       (the reporting tool logs in as this)
   └── inkwell_readwrite    (may also write)
          └── inkwell_app   (the web application logs in as this)
```

Each membership has three options (PostgreSQL 16+; before 16, `INHERIT` came from the member role's attribute, and
`ADMIN` was the only per-grant option):

| Option | Default | Meaning |
|---|---|---|
| `INHERIT` | `TRUE` (from the member's `INHERIT` attribute) | the member automatically has the group's privileges |
| `SET` | `TRUE` | the member may switch to the group with `SET ROLE` |
| `ADMIN` | `FALSE` | the member may grant and revoke membership in the group to others |

Privileges flow through `INHERIT` memberships transitively: `inkwell_app` has whatever `inkwell_readwrite` and
`inkwell_readonly` have. Role **attributes** never flow: membership in a superuser role doesn't make you a superuser,
and `LOGIN` isn't inherited either.

Migrations are a good use of `INHERIT FALSE`. The migration tool should act as the owner, but only on purpose:

```sql
CREATE ROLE inkwell_migrate LOGIN PASSWORD 'migrate-dev-password';
GRANT inkwell_owner TO inkwell_migrate WITH INHERIT FALSE, SET TRUE;
ALTER ROLE inkwell_migrate SET role = 'inkwell_owner';
```

```text
CREATE ROLE
GRANT ROLE
ALTER ROLE
```

`ALTER ROLE ... SET` stores a setting that's applied whenever that role logs in. Here the setting is `role` itself,
so every session of `inkwell_migrate` starts by switching to `inkwell_owner`, and everything the migrations create is
owned by `inkwell_owner`, not by whichever person or tool happened to run them.

### SET ROLE: acting as another role

A session has two identities. **`session_user`** is the role that logged in. **`current_user`** is the role whose
privileges are checked right now. They are the same until you switch with `SET ROLE`, which is allowed to a member
with the `SET` option, and to superusers for any role. That last point makes `SET ROLE` the easiest way to test what
a role may do, and this chapter uses it throughout:

```sql
SET ROLE inkwell_app;
SELECT session_user, current_user;
SELECT count(*) FROM orders;
RESET ROLE;
```

```text
SET
 session_user | current_user
--------------+--------------
 postgres     | inkwell_app
(1 row)

ERROR:  permission denied for table orders
RESET
```

`inkwell_app` can't read `orders` yet, because nobody has granted it anything. `RESET ROLE` switches back. Inside a
transaction, `SET LOCAL ROLE` switches until the transaction ends, which is the safest form: you can't forget to
switch back.

### Predefined roles

PostgreSQL comes with built-in roles that carry specific powers, so that you can hand out a capability without
handing out superuser:

| Role | Allows |
|---|---|
| `pg_read_all_data`, `pg_write_all_data` | read (write) every table, view and sequence in every database, as if granted everywhere, ignoring schema and table privileges (but not RLS) |
| `pg_monitor` | read all monitoring views and settings (includes `pg_read_all_settings`, `pg_read_all_stats`, `pg_stat_scan_tables`); use it for monitoring agents |
| `pg_signal_backend` | cancel queries and terminate sessions of non-superusers |
| `pg_checkpoint` (15+) | run `CHECKPOINT` |
| `pg_use_reserved_connections` (16+) | use the connection slots kept free by `reserved_connections` |
| `pg_create_subscription` (16+) | create logical replication subscriptions (chapter 23) |
| `pg_maintain` (17+) | run `VACUUM`, `ANALYZE`, `REINDEX`, `REFRESH MATERIALIZED VIEW`, `CLUSTER` and `LOCK TABLE` on everything |
| `pg_signal_autovacuum_worker` (18+) | cancel or terminate autovacuum workers |
| `pg_read_server_files`, `pg_write_server_files`, `pg_execute_server_program` | read and write files as the server's OS user, and run programs (`COPY ... PROGRAM`). These amount to superuser; treat them that way |
| `pg_database_owner` | not granted by hand: its only member is always the owner of the current database. It owns the `public` schema |

A monitoring agent gets `GRANT pg_monitor TO monitoring_agent;` and nothing else.

## Ownership

Every object has an **owner**, initially the role that created it. The owner has all privileges on the object, and
only the owner (or a member of the owning role, or a superuser) can `ALTER` or `DROP` it, or grant privileges on it.
Ownership can't be revoked, only transferred.

Right now everything belongs to `postgres`, because `reset.sql` ran as `postgres`. Hand Inkwell over to the owner role:

```sql
ALTER DATABASE inkwell OWNER TO inkwell_owner;
ALTER TABLE customers OWNER TO inkwell_owner;
ALTER TABLE categories OWNER TO inkwell_owner;
ALTER TABLE products OWNER TO inkwell_owner;
ALTER TABLE orders OWNER TO inkwell_owner;
ALTER TABLE order_items OWNER TO inkwell_owner;
ALTER TABLE reviews OWNER TO inkwell_owner;
ALTER TYPE order_status OWNER TO inkwell_owner;
\dt
```

```text
ALTER DATABASE
ALTER TABLE
ALTER TABLE
ALTER TABLE
ALTER TABLE
ALTER TABLE
ALTER TABLE
ALTER TYPE
                List of tables
 Schema |    Name     | Type  |     Owner
--------+-------------+-------+---------------
 public | categories  | table | inkwell_owner
 public | customers   | table | inkwell_owner
 public | order_items | table | inkwell_owner
 public | orders      | table | inkwell_owner
 public | products    | table | inkwell_owner
 public | reviews     | table | inkwell_owner
(6 rows)
```

Identity sequences and indexes follow their table's owner automatically. (`REASSIGN OWNED BY old_role TO new_role`
moves everything at once, but it refuses to touch objects of the bootstrap superuser `postgres`, because some of
those are system objects.)

Why not let the application own the tables? Because the owner can do things no privilege can take away: `DROP TABLE`,
`ALTER TABLE`, `TRUNCATE`, disabling triggers, and, as you'll see, ignoring row-level security. An application that
connects as the owner gives all of that to anyone who finds a SQL injection bug in it. The rule:

- **One owner role** (`NOLOGIN`) owns the schema. Migrations act as it (`inkwell_migrate`).
- **Application roles** get only the data privileges they need, through group roles.
- **People** get their own login roles, members of the groups that match their job, so that the server log and
  `pg_stat_activity` show who did what.

## Privileges

A **privilege** is a right to do one kind of thing to one object. `GRANT` gives it, `REVOKE` takes it away:

```sql
GRANT SELECT, INSERT ON orders TO inkwell_report;
REVOKE SELECT, INSERT ON orders FROM inkwell_report;
```

```text
GRANT
REVOKE
```

The privileges, by kind of object, and the letter each one has in an **access control list** (ACL):

| Object | Privileges (ACL letter) |
|---|---|
| Table, view, materialized view | `SELECT` (r), `INSERT` (a), `UPDATE` (w), `DELETE` (d), `TRUNCATE` (D), `REFERENCES` (x), `TRIGGER` (t), `MAINTAIN` (m, 17+) |
| Column | `SELECT`, `INSERT`, `UPDATE`, `REFERENCES` |
| Sequence | `USAGE` (U: `nextval`, `currval`), `SELECT` (r: `currval`, read the sequence), `UPDATE` (w: `nextval`, `setval`) |
| Function, procedure | `EXECUTE` (X) |
| Schema | `USAGE` (U: look up objects in it), `CREATE` (C: create objects in it) |
| Database | `CONNECT` (c), `CREATE` (C: create schemas), `TEMPORARY` (T: create temporary tables) |
| Type, domain, language | `USAGE` (U) |
| Parameter (15+) | `SET` (s), `ALTER SYSTEM` (A) |

Access to a table needs privileges at **every level** on the way: `CONNECT` on the database, `USAGE` on the schema,
and the privilege on the table itself. `ALL PRIVILEGES` means every privilege that applies to the object.

### PUBLIC and the defaults

`PUBLIC` is a pseudo-role that every role is a member of. Some privileges are granted to it by default, which matters
for security:

| Object | Granted to `PUBLIC` by default |
|---|---|
| Database | `CONNECT`, `TEMPORARY` |
| Function, procedure | `EXECUTE` |
| Language, type, domain | `USAGE` |
| `public` schema | `USAGE` (and, before PostgreSQL 15, `CREATE`) |
| Tables, sequences, other schemas | nothing |

So any role in the cluster may connect to any database by default (if `pg_hba.conf` lets it in), create temporary
tables there, and execute any function. Tighten the database first:

```sql
REVOKE ALL ON DATABASE inkwell FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE inkwell TO inkwell_readonly;
GRANT CONNECT ON DATABASE inkwell TO inkwell_migrate;
SELECT datname, datacl FROM pg_database WHERE datname = 'inkwell';
```

```text
REVOKE
GRANT
GRANT
 datname |                                               datacl
---------+-----------------------------------------------------------------------------------------------------
 inkwell | {inkwell_owner=CTc/inkwell_owner,inkwell_readonly=Tc/inkwell_owner,inkwell_migrate=c/inkwell_owner}
(1 row)
```

Read an ACL entry as `grantee=privileges/grantor`. An empty grantee means `PUBLIC`. `inkwell_owner=CTc/inkwell_owner`
is the owner's own entry, written out once the defaults have been changed.

Why does `inkwell_migrate` need its own `CONNECT`? Its membership in `inkwell_owner` has `INHERIT FALSE`, so it
doesn't use the owner's privileges until the session switches role, and the `CONNECT` check happens before that.
Logging in as it now shows the automatic switch (`-h localhost` connects over TCP, where a default installation asks
for the password, `migrate-dev-password`):

```bash
psql -h localhost -d inkwell -U inkwell_migrate -c 'SELECT session_user, current_user'
```

```text
  session_user   | current_user
-----------------+---------------
 inkwell_migrate | inkwell_owner
(1 row)
```

A role without `CONNECT` is turned away after authenticating, with an error that ends like this:

```text
FATAL:  permission denied for database "inkwell"
DETAIL:  User does not have CONNECT privilege.
```

### Table privileges

Now give the groups their privileges. `ON ALL TABLES IN SCHEMA` grants on every table (and view) that exists in the
schema right now:

```sql
GRANT SELECT ON ALL TABLES IN SCHEMA public TO inkwell_readonly;
GRANT INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO inkwell_readwrite;
\dp orders
```

```text
GRANT
GRANT
                                       Access privileges
 Schema |  Name  | Type  |          Access privileges           | Column privileges | Policies
--------+--------+-------+--------------------------------------+-------------------+----------
 public | orders | table | inkwell_owner=arwdDxtm/inkwell_owner+|                   |
        |        |       | inkwell_readonly=r/inkwell_owner    +|                   |
        |        |       | inkwell_readwrite=awd/inkwell_owner  |                   |
(1 row)
```

`inkwell_readonly=r/inkwell_owner` reads "`inkwell_readonly` may `SELECT` (r), granted by `inkwell_owner`". The owner
has every table privilege (`arwdDxtm`). Notice that `inkwell_readwrite` has only `awd`: it gets `SELECT` through its
membership in `inkwell_readonly`, which doesn't show up in this list. (The grants were made by `postgres`, a
superuser; a superuser's `GRANT` is recorded as if the owner had made it.)

Try the application role:

```sql
SET ROLE inkwell_app;
INSERT INTO customers (email, full_name, country)
VALUES ('ivan@example.com', 'Ivan Petrov', 'BG')
RETURNING id, full_name;
TRUNCATE reviews;
RESET ROLE;
```

```text
SET
 id |  full_name
----+-------------
  9 | Ivan Petrov
(1 row)

INSERT 0 1
ERROR:  permission denied for table reviews
RESET
```

The insert works, and so does `RETURNING`, which needs `SELECT` (inherited). The identity column needs no privilege
on its sequence: identity sequences are used on the table's behalf. A `serial` column (chapter 4) is different: its
default calls `nextval()`, so inserting needs `USAGE` on the sequence too. `TRUNCATE` is its own privilege, and the
application doesn't get it: a `TRUNCATE` skips `DELETE` triggers, row-level security and the per-row work, so it
belongs to migrations and maintenance.

### Column privileges

The reporting tool shouldn't see customers' e-mail addresses. Privileges can be granted on individual columns:

```sql
REVOKE SELECT ON customers FROM inkwell_readonly;
GRANT SELECT (id, full_name, country, created_at) ON customers TO inkwell_readonly;
GRANT SELECT ON customers TO inkwell_readwrite;    -- the application does need the e-mail

SET ROLE inkwell_report;
SELECT * FROM customers;
SELECT id, full_name, country FROM customers WHERE id <= 3;
RESET ROLE;
```

```text
REVOKE
GRANT
GRANT
SET
ERROR:  permission denied for table customers
 id |  full_name   | country
----+--------------+---------
  1 | Ana Souza    | BR
  2 | Ben Carter   | US
  3 | Chloé Martin | FR
(3 rows)

RESET
```

A table-level privilege covers every column, including columns added later; a column privilege covers just that
column. `SELECT *` fails because it asks for `email` too. Column privileges are a blunt tool (every query must name
its columns, and tools that run `SELECT *` break), so for anything beyond hiding a column or two, a view (below) is
usually nicer.

`UPDATE` can be limited to columns in the same way: `GRANT UPDATE (stock) ON products TO warehouse;` lets a role
change stock levels and nothing else.

### Default privileges

Grants apply to objects that exist when you run them. Watch what happens to a table that the next migration creates:

```sql
SET ROLE inkwell_owner;
CREATE TABLE wishlist_items (
    customer_id  bigint NOT NULL REFERENCES customers (id),
    product_id   bigint NOT NULL REFERENCES products (id),
    added_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (customer_id, product_id)
);
RESET ROLE;

SET ROLE inkwell_report;
SELECT count(*) FROM wishlist_items;
RESET ROLE;
```

```text
SET
CREATE TABLE
RESET
SET
ERROR:  permission denied for table wishlist_items
RESET
```

Every new table would need its own grants, and the day a migration forgets them, a feature breaks in production.
**Default privileges** fix that: they are privileges added automatically to objects that a given role creates in the
future.

```sql
ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner
    GRANT SELECT ON TABLES TO inkwell_readonly;
ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner
    GRANT INSERT, UPDATE, DELETE ON TABLES TO inkwell_readwrite;
ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner
    GRANT USAGE, SELECT ON SEQUENCES TO inkwell_readwrite;
ALTER DEFAULT PRIVILEGES FOR ROLE inkwell_owner
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
\ddp
```

```text
ALTER DEFAULT PRIVILEGES
ALTER DEFAULT PRIVILEGES
ALTER DEFAULT PRIVILEGES
ALTER DEFAULT PRIVILEGES
                        Default access privileges
     Owner     | Schema |   Type   |          Access privileges
---------------+--------+----------+--------------------------------------
 inkwell_owner |        | function | inkwell_owner=X/inkwell_owner
 inkwell_owner |        | sequence | inkwell_owner=rwU/inkwell_owner     +
               |        |          | inkwell_readwrite=rU/inkwell_owner
 inkwell_owner |        | table    | inkwell_owner=arwdDxtm/inkwell_owner+
               |        |          | inkwell_readonly=r/inkwell_owner    +
               |        |          | inkwell_readwrite=awd/inkwell_owner
(3 rows)
```

The last one removes the default `EXECUTE` for `PUBLIC` on the owner's future functions (the `function` line lists
only the owner now), so that each function is
granted to the roles that need it on purpose. Default privileges never touch existing objects, so `wishlist_items`
still has no grants. It's new and empty, so recreate it:

```sql
SET ROLE inkwell_owner;
DROP TABLE wishlist_items;
CREATE TABLE wishlist_items (
    customer_id  bigint NOT NULL REFERENCES customers (id),
    product_id   bigint NOT NULL REFERENCES products (id),
    added_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (customer_id, product_id)
);
RESET ROLE;
\dp wishlist_items
```

```text
SET
DROP TABLE
CREATE TABLE
RESET
                                           Access privileges
 Schema |      Name      | Type  |          Access privileges           | Column privileges | Policies
--------+----------------+-------+--------------------------------------+-------------------+----------
 public | wishlist_items | table | inkwell_owner=arwdDxtm/inkwell_owner+|                   |
        |                |       | inkwell_readonly=r/inkwell_owner    +|                   |
        |                |       | inkwell_readwrite=awd/inkwell_owner  |                   |
(1 row)
```

The trap: **default privileges belong to the role that creates the objects.** `FOR ROLE inkwell_owner` means "objects
that `inkwell_owner` creates". A table created by `postgres`, or by a developer's own login role, gets nothing, and the
same "permission denied" comes back. That's the main reason for running migrations *as* the owner role (through
`inkwell_migrate`'s automatic `SET ROLE`): one creator, one set of default privileges. Leaving out `FOR ROLE` sets
defaults for your own current role; adding `IN SCHEMA reporting` limits them to one schema.

### Functions and EXECUTE

Functions created by the owner from now on are no longer executable by everyone:

```sql
SET ROLE inkwell_owner;
CREATE FUNCTION order_total(p_order_id bigint) RETURNS numeric
    LANGUAGE sql STABLE
    RETURN (SELECT sum(quantity * unit_price) FROM order_items WHERE order_id = p_order_id);
RESET ROLE;

SET ROLE inkwell_app;
SELECT order_total(1);
RESET ROLE;

GRANT EXECUTE ON FUNCTION order_total(bigint) TO inkwell_readonly;
SET ROLE inkwell_app;
SELECT order_total(1);
RESET ROLE;
```

```text
SET
CREATE FUNCTION
RESET
SET
ERROR:  permission denied for function order_total
RESET
GRANT
SET
 order_total
-------------
       80.00
(1 row)

RESET
```

`EXECUTE` alone doesn't let a role read anything: an ordinary (`SECURITY INVOKER`) function runs with the caller's
privileges, and `inkwell_app` can read `order_items` anyway. For a `SECURITY DEFINER` function, which runs with the
owner's privileges (chapter 19), `EXECUTE` is the only gate, so granting it is a real decision.

### Checking privileges

`\dp` shows ACLs; the `has_*_privilege` functions answer "may this role do this?", taking memberships into account:

```sql
SELECT has_table_privilege('inkwell_app', 'orders', 'UPDATE')                AS app_update_orders,
       has_table_privilege('inkwell_report', 'orders', 'UPDATE')             AS report_update_orders,
       has_column_privilege('inkwell_report', 'customers', 'email', 'SELECT') AS report_sees_email,
       has_function_privilege('inkwell_report', 'order_total(bigint)', 'EXECUTE') AS report_order_total;
```

```text
 app_update_orders | report_update_orders | report_sees_email | report_order_total
-------------------+----------------------+-------------------+--------------------
 t                 | f                    | f                 | t
(1 row)
```

There are versions for every kind of object (`has_schema_privilege`, `has_database_privilege`,
`has_sequence_privilege`, ...). PostgreSQL 18+ adds `pg_get_acl()`, which returns the ACL of any object given its
catalog and object id:

```sql
SELECT pg_get_acl('pg_class'::regclass, 'wishlist_items'::regclass, 0);
```

```text
                                                 pg_get_acl
-------------------------------------------------------------------------------------------------------------
 {inkwell_owner=arwdDxtm/inkwell_owner,inkwell_readonly=r/inkwell_owner,inkwell_readwrite=awd/inkwell_owner}
(1 row)
```

## Schemas and search_path safety

Schemas (chapter 14) are also a security boundary: to use an object in a schema you need `USAGE` on the schema, and to
create one you need `CREATE`. Look at `public`:

```sql
\dn+ public
```

```text
                                       List of schemas
  Name  |       Owner       |           Access privileges            |      Description
--------+-------------------+----------------------------------------+------------------------
 public | pg_database_owner | pg_database_owner=UC/pg_database_owner+| standard public schema
        |                   | =U/pg_database_owner                   |
(1 row)
```

Since **PostgreSQL 15**, `public` is owned by `pg_database_owner` (that is, by whoever owns the database: here
`inkwell_owner`), and `PUBLIC` only has `USAGE` on it. Before 15, every role could create objects in `public`. On a
database that was created on an older version and upgraded, the old ACL is kept, so check it, and if needed run:

```sql
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
```

```text
REVOKE
```

### A reporting schema for a partner

Inkwell sells through a reseller who may check stock levels and see monthly sales figures, and nothing else. A
separate schema holds what they may use:

```sql
CREATE ROLE inkwell_partner LOGIN PASSWORD 'partner-dev-password';
GRANT CONNECT, TEMPORARY ON DATABASE inkwell TO inkwell_partner;

SET ROLE inkwell_owner;
CREATE SCHEMA reporting;

CREATE VIEW reporting.monthly_revenue AS
SELECT date_trunc('month', o.created_at)::date AS month,
       count(DISTINCT o.id)                    AS orders,
       sum(oi.quantity * oi.unit_price)        AS revenue
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.status <> 'cancelled'
GROUP BY 1;
RESET ROLE;

GRANT USAGE ON SCHEMA reporting TO inkwell_partner;
GRANT SELECT ON reporting.monthly_revenue TO inkwell_partner;
```

```text
CREATE ROLE
GRANT
SET
CREATE SCHEMA
CREATE VIEW
RESET
GRANT
GRANT
```

(The partner's BI tool works with temporary tables, so the partner was given `TEMPORARY` on the database. Keep that in
mind.)

### The temporary-table trick

The partner may also look up the stock of a product. They can't read `products`, so the owner provides a
`SECURITY DEFINER` function, which runs with the owner's privileges, and sets its `search_path` as chapter 19 taught.
Or almost:

```sql
SET ROLE inkwell_owner;
CREATE FUNCTION reporting.stock_level(p_sku text) RETURNS integer
    LANGUAGE plpgsql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, public
AS $$
BEGIN
    RETURN (SELECT stock FROM products WHERE sku = p_sku);
END;
$$;
RESET ROLE;
GRANT EXECUTE ON FUNCTION reporting.stock_level(text) TO inkwell_partner;

SET ROLE inkwell_partner;
SELECT reporting.stock_level('FP-002');
```

```text
SET
CREATE FUNCTION
RESET
GRANT
SET
 stock_level
-------------
           3
(1 row)
```

Three Heron pens in stock. Now the partner creates a temporary table of their own, called `products`, and lets
everyone read it (they own it, so they may):

```sql
CREATE TEMPORARY TABLE products (sku text, stock integer);
INSERT INTO products VALUES ('FP-002', 9999);
GRANT SELECT ON products TO PUBLIC;
SELECT reporting.stock_level('FP-002');
```

```text
CREATE TABLE
INSERT 0 1
GRANT
 stock_level
-------------
        9999
(1 row)
```

The function, running with the owner's privileges, read the partner's table instead of the real one. Every session
has a schema for its temporary objects (`pg_temp_N`, alias `pg_temp`), and **when `pg_temp` isn't named in
`search_path`, it's searched first** for tables, views and types (never for functions and operators). (Without the
`GRANT`, the call fails with "permission denied for table products", which is how you'd notice that the function no
longer reads what you think it reads.) Reading fake stock is harmless; a function that *writes* through an unqualified
name, or checks permissions by reading a table, is not. The fix is to name `pg_temp` explicitly, last:

```sql
RESET ROLE;
ALTER FUNCTION reporting.stock_level(text) SET search_path = pg_catalog, public, pg_temp;
SET ROLE inkwell_partner;
SELECT reporting.stock_level('FP-002');
DROP TABLE products;
RESET ROLE;
```

```text
RESET
ALTER FUNCTION
SET
 stock_level
-------------
           3
(1 row)

DROP TABLE
RESET
```

Functions with an SQL-standard body (`RETURN ...` or `BEGIN ATOMIC`, chapter 19) are immune to this trick: their
names are resolved once, when the function is created, not at every call. For `SECURITY DEFINER` functions, that's one
more reason to prefer them when the body is plain SQL.

### Rules for schemas and search_path

- **Nobody untrusted may create objects in a schema that's on someone else's `search_path`.** With PostgreSQL 15+
  defaults, `public` is safe; keep any schema you add the same way (only the owner role has `CREATE`).
- **Every `SECURITY DEFINER` function sets `search_path`**, with `pg_temp` last, or schema-qualifies every name.
- **Revoke `EXECUTE` from `PUBLIC`** on `SECURITY DEFINER` functions (or by default, as above).
- **Don't put user-writable schemas in an application role's path.** Set it per role if needed:
  `ALTER ROLE inkwell_app SET search_path = public;`.
- **Revoke `TEMPORARY`** from roles that don't need it. It's harmless for most roles, but as you've seen, it's also the
  one place every role can create objects by default.

## Views as a security boundary

A view runs with the privileges of its **owner**, not of the role querying it (unless it's a `security_invoker` view,
below). That's what lets you give a role access to a view but not to the tables behind it, as promised in chapter 20:

```sql
SET ROLE inkwell_partner;
SELECT * FROM reporting.monthly_revenue ORDER BY month;
SELECT count(*) FROM orders;
RESET ROLE;
```

```text
SET
   month    | orders | revenue
------------+--------+---------
 2026-01-01 |      2 |  119.00
 2026-02-01 |      2 |  187.40
 2026-03-01 |      3 |  192.75
 2026-04-01 |      4 |  231.70
(4 rows)

ERROR:  permission denied for table orders
RESET
```

The partner sees monthly totals, but no orders, customers or prices.

### Leaky views and security_barrier

Views can hide **rows** too. The European distribution partner should see EU customers only:

```sql
SET ROLE inkwell_owner;
CREATE VIEW reporting.eu_customers AS
SELECT id, full_name, country
FROM customers
WHERE country IN ('AT', 'BE', 'BG', 'DE', 'ES', 'FR', 'IT', 'NL', 'SE');
RESET ROLE;
GRANT SELECT ON reporting.eu_customers TO inkwell_partner;

SET ROLE inkwell_partner;
SELECT * FROM reporting.eu_customers ORDER BY id;
```

```text
SET
CREATE VIEW
RESET
GRANT
SET
 id |   full_name   | country
----+---------------+---------
  3 | Chloé Martin  | FR
  5 | Emma Schulz   | DE
  7 | Gus Lindqvist | SE
  9 | Ivan Petrov   | BG
(4 rows)
```

Now the partner uses their `TEMPORARY` privilege to create a function in `pg_temp` that prints its argument, declares
it extremely cheap, and filters the view with it:

```sql
CREATE FUNCTION pg_temp.peek(text) RETURNS boolean
    LANGUAGE plpgsql COST 0.0000001
AS $$
BEGIN
    RAISE NOTICE 'peek: %', $1;
    RETURN true;
END;
$$;

SELECT * FROM reporting.eu_customers WHERE pg_temp.peek(full_name);
```

```text
CREATE FUNCTION
NOTICE:  peek: Ana Souza
NOTICE:  peek: Ben Carter
NOTICE:  peek: Chloé Martin
NOTICE:  peek: Dev Patel
NOTICE:  peek: Emma Schulz
NOTICE:  peek: Farah Haddad
NOTICE:  peek: Gus Lindqvist
NOTICE:  peek: Hana Kobayashi
NOTICE:  peek: Ivan Petrov
 id |   full_name   | country
----+---------------+---------
  3 | Chloé Martin  | FR
  5 | Emma Schulz   | DE
  7 | Gus Lindqvist | SE
  9 | Ivan Petrov   | BG
(4 rows)
```

Every customer's name was printed, including those the view hides. A view is expanded into the query (chapter 20),
and the planner is free to evaluate the conditions in whatever order is cheapest. The partner's function claimed to be
cheaper than the view's own `WHERE`, so it ran first, on every row. Errors leak the same way: a condition that fails
with an error message containing a value can reveal rows it was never supposed to see.

The fix is a **security barrier view**: its own conditions are always evaluated before any condition from the
outside query that could leak.

```sql
RESET ROLE;
ALTER VIEW reporting.eu_customers SET (security_barrier = true);
SET ROLE inkwell_partner;
SELECT * FROM reporting.eu_customers WHERE pg_temp.peek(full_name);
EXPLAIN (COSTS OFF) SELECT * FROM reporting.eu_customers WHERE pg_temp.peek(full_name);
RESET ROLE;
```

```text
RESET
ALTER VIEW
SET
NOTICE:  peek: Chloé Martin
NOTICE:  peek: Emma Schulz
NOTICE:  peek: Gus Lindqvist
NOTICE:  peek: Ivan Petrov
 id |   full_name   | country
----+---------------+---------
  3 | Chloé Martin  | FR
  5 | Emma Schulz   | DE
  7 | Gus Lindqvist | SE
  9 | Ivan Petrov   | BG
(4 rows)

                                QUERY PLAN
--------------------------------------------------------------------------
 Subquery Scan on eu_customers
   Filter: pg_temp.peek(eu_customers.full_name)
   ->  Seq Scan on customers
         Filter: (country = ANY ('{AT,BE,BG,DE,ES,FR,IT,NL,SE}'::text[]))
(4 rows)

RESET
```

Only the visible rows reach `peek`. The plan shows how: the view became a `Subquery Scan` that the outer filter can't
be pushed into.

Doesn't that ruin performance? Not entirely: conditions using **`LEAKPROOF`** functions and operators may still be
pushed down into the view, and use its indexes. A leakproof function reveals nothing about its arguments except
through its result: no errors that depend on the value, no notices. Most comparison operators on built-in types are
leakproof; functions that can fail on some input are not:

```sql
SELECT p.oid::regprocedure AS function, p.proleakproof
FROM pg_proc p
WHERE p.oid IN ('texteq(text,text)'::regprocedure, 'int8lt(bigint,bigint)'::regprocedure,
                'textlike(text,text)'::regprocedure, 'int4in(cstring)'::regprocedure);
```

```text
       function        | proleakproof
-----------------------+--------------
 int4in(cstring)       | f
 texteq(text,text)     | t
 int8lt(bigint,bigint) | t
 textlike(text,text)   | f
(4 rows)
```

Only superusers can mark a function `LEAKPROOF`, because the database has to trust the promise. Use security barrier
views (or `security_invoker` views over row-level security, next) whenever a view hides rows from someone who can run
their own queries against it. A view that only hides columns, like `monthly_revenue`, doesn't need it.

Now, revoke what the partner never needed:

```sql
REVOKE TEMPORARY ON DATABASE inkwell FROM inkwell_partner;
```

```text
REVOKE
```

## Row-level security

Privileges decide which **tables and columns** a role may use. **Row-level security** (RLS) decides which **rows**:
once it's enabled on a table, every query by an affected role gets extra conditions, called **policies**, added by the
rewriter (chapter 16), as if the role could only see a filtered version of the table.

Inkwell's use case is a **customer portal**: customers log in to the website, and the portal's backend connects as a
role `inkwell_portal`. A bug in any query of the portal (a forgotten `WHERE customer_id = ...`) would show one
customer's orders to another. With RLS, the database adds that condition itself.

### Enabling RLS

Create the portal role. It reads the catalogue and its own customer data, and writes only reviews:

```sql
CREATE ROLE inkwell_portal LOGIN PASSWORD 'portal-dev-password';
GRANT CONNECT ON DATABASE inkwell TO inkwell_portal;
GRANT SELECT ON categories, products, customers, orders, order_items, reviews TO inkwell_portal;
GRANT UPDATE (full_name) ON customers TO inkwell_portal;
GRANT INSERT, DELETE, UPDATE (rating, body) ON reviews TO inkwell_portal;
```

```text
CREATE ROLE
GRANT
GRANT
GRANT
GRANT
```

Now enable RLS on `orders` and look at it as two different roles:

```sql
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;

SET ROLE inkwell_portal;
SELECT count(*) FROM orders;
RESET ROLE;
SET ROLE inkwell_app;
SELECT count(*) FROM orders;
RESET ROLE;
```

```text
ALTER TABLE
SET
 count
-------
     0
(1 row)

RESET
SET
 count
-------
     0
(1 row)

RESET
```

Neither role sees a single order: **with RLS enabled and no policy, nothing is visible** to roles that are subject to
RLS (default deny). That includes the application and the reporting tool, which are not supposed to be restricted. So
the first policy restores full access for staff roles:

```sql
CREATE POLICY orders_staff ON orders
    TO inkwell_readonly
    USING (true);
```

```text
CREATE POLICY
```

A policy applies to the roles named in `TO` **and their members**, so this covers `inkwell_readonly`,
`inkwell_readwrite`, `inkwell_app` and `inkwell_report`. Without `FOR`, a policy applies to all commands (`FOR ALL`).
Policies only filter: they never grant privileges. `inkwell_report` still can't `UPDATE` orders, because it has no
`UPDATE` privilege; the policy only means that *if* it could, it could reach every row.

### Who is the current customer?

The portal connects as one role for all customers, so the policy needs to know which customer the current request is
for. The usual way is a **custom setting**: any parameter whose name contains a dot (like `app.customer_id`) can be set
and read freely. The backend sets it at the start of each transaction with `SET LOCAL` (or
`set_config('app.customer_id', '42', true)`, the function form, which accepts a bind parameter), so it can't leak into
the next request that reuses the same pooled connection (chapter 22).

A small function reads it:

```sql
SET ROLE inkwell_owner;
CREATE FUNCTION current_customer_id() RETURNS bigint
    LANGUAGE sql STABLE
    RETURN nullif(current_setting('app.customer_id', true), '')::bigint;
RESET ROLE;
GRANT EXECUTE ON FUNCTION current_customer_id() TO inkwell_portal;

CREATE POLICY orders_customer ON orders
    FOR SELECT
    TO inkwell_portal
    USING (customer_id = current_customer_id());
```

```text
SET
CREATE FUNCTION
RESET
GRANT
CREATE POLICY
```

`current_setting(name, true)` returns `NULL` instead of an error when the setting has never been set. Once it has been
set in a session (even with `SET LOCAL` in a transaction that has ended), it returns an empty string instead, hence
`nullif(..., '')`. Either way, the policy compares `customer_id` with `NULL`, which is never true: **no customer id
means no rows**. A policy should always fail closed like this. Policy expressions run with the privileges of the
querying role, so it needs `EXECUTE` on the function.

Ana Souza is customer 1:

```sql
BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
SELECT id, status, created_at FROM orders ORDER BY id;
SELECT count(*) FROM orders WHERE customer_id = 2;
COMMIT;

SET ROLE inkwell_portal;
SELECT count(*) FROM orders;
RESET ROLE;
```

```text
BEGIN
SET
SET
 id |  status   |       created_at
----+-----------+------------------------
  1 | delivered | 2026-01-06 10:00:00+00
  4 | delivered | 2026-02-10 08:45:00+00
 11 | paid      | 2026-04-09 20:05:00+00
(3 rows)

 count
-------
     0
(1 row)

COMMIT
SET
 count
-------
     0
(1 row)

RESET
```

Ana sees her three orders. Asking for Ben's orders explicitly finds nothing: the policy's condition is combined with
the query's own with `AND`. And after the transaction, without a customer id, the portal role sees nothing at all.

Order lines need the same protection. Their policy looks up the order:

```sql
ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY order_items_staff ON order_items TO inkwell_readonly USING (true);
CREATE POLICY order_items_customer ON order_items
    FOR SELECT
    TO inkwell_portal
    USING (EXISTS (SELECT FROM orders o
                   WHERE o.id = order_items.order_id
                     AND o.customer_id = current_customer_id()));
```

```text
ALTER TABLE
CREATE POLICY
CREATE POLICY
```

A subquery inside a policy runs as the querying role too, so it's itself filtered by the policies on `orders`. Here the
explicit `o.customer_id = current_customer_id()` makes the intent clear without relying on that. (A policy whose
subquery reads the policy's own table loops forever and fails with an "infinite recursion detected in policy" error;
a `SECURITY DEFINER` function is the usual way out.)

### Policies per command: USING and WITH CHECK

A policy has up to two expressions:

- **`USING`** filters **existing** rows: the rows a `SELECT` returns, and the rows an `UPDATE` or `DELETE` may touch.
  Rows that fail it are silently skipped.
- **`WITH CHECK`** tests **new** rows: the rows an `INSERT` adds and the new versions an `UPDATE` produces. A row that
  fails it raises an error.

| Command | `USING` | `WITH CHECK` |
|---|---|---|
| `SELECT` | yes | — |
| `INSERT` | — | yes |
| `UPDATE` | yes (old row) | yes (new row; defaults to the `USING` expression) |
| `DELETE` | yes | — |
| `ALL` | yes | yes (defaults to the `USING` expression) |

Reviews are public, but each customer may write only their own:

```sql
ALTER TABLE reviews ENABLE ROW LEVEL SECURITY;
CREATE POLICY reviews_staff ON reviews TO inkwell_readonly USING (true);
CREATE POLICY reviews_read ON reviews FOR SELECT TO inkwell_portal
    USING (true);
CREATE POLICY reviews_insert ON reviews FOR INSERT TO inkwell_portal
    WITH CHECK (customer_id = current_customer_id());
CREATE POLICY reviews_update ON reviews FOR UPDATE TO inkwell_portal
    USING (customer_id = current_customer_id());
CREATE POLICY reviews_delete ON reviews FOR DELETE TO inkwell_portal
    USING (customer_id = current_customer_id());
```

```text
ALTER TABLE
CREATE POLICY
CREATE POLICY
CREATE POLICY
CREATE POLICY
CREATE POLICY
```

Ana writes a review, then tries to change and delete reviews of Ben's (customer 2: reviews 3 and 4):

```sql
BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
INSERT INTO reviews (product_id, customer_id, rating, body)
VALUES (7, 1, 4, 'Smooth paper, no bleed-through.')
RETURNING id, product_id, customer_id, rating;
UPDATE reviews SET rating = 1 WHERE id = 3;
DELETE FROM reviews WHERE customer_id = 2;
UPDATE reviews SET body = 'Smooth paper, no bleed-through. Love it.' WHERE product_id = 7 AND customer_id = 1;
COMMIT;
```

```text
BEGIN
SET
SET
 id | product_id | customer_id | rating
----+------------+-------------+--------
 10 |          7 |           1 |      4
(1 row)

INSERT 0 1
UPDATE 0
DELETE 0
UPDATE 1
COMMIT
```

Her own insert and update work. The update and delete of Ben's reviews affect zero rows, without an error: as far as
those commands are concerned, Ben's rows don't exist. Writing a review in Ben's name fails loudly instead:

```sql
BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
INSERT INTO reviews (product_id, customer_id, rating, body)
VALUES (8, 2, 1, 'Terrible.');
ROLLBACK;
```

```text
BEGIN
SET
SET
ERROR:  new row violates row-level security policy for table "reviews"
ROLLBACK
```

Column privileges and policies work together. The portal role may only update `rating` and `body`, so even without
the update policy's check, Ana couldn't move her review to someone else by changing `customer_id`.

### Permissive and restrictive policies

The policies so far are **permissive** (the default): when several apply to the same command, a row is visible if
**any** of them allows it (they're combined with `OR`). A **restrictive** policy must **also** pass (combined with
`AND`), whatever the permissive ones say. Restrictive policies add a rule that must hold everywhere, without touching
the others.

Inkwell starts moderating reviews. A hidden review must disappear from the portal, except for its author:

```sql
ALTER TABLE reviews ADD COLUMN hidden boolean NOT NULL DEFAULT false;
UPDATE reviews SET hidden = true WHERE id = 8;       -- Emma's review of the A4 notebook

CREATE POLICY reviews_not_hidden ON reviews
    AS RESTRICTIVE
    FOR SELECT
    TO inkwell_portal
    USING (NOT hidden OR customer_id = current_customer_id());

BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
SELECT id, customer_id, rating, hidden FROM reviews WHERE product_id = 8;
SET LOCAL app.customer_id = '5';
SELECT id, customer_id, rating, hidden FROM reviews WHERE product_id = 8;
COMMIT;
```

```text
ALTER TABLE
UPDATE 1
CREATE POLICY
BEGIN
SET
SET
 id | customer_id | rating | hidden
----+-------------+--------+--------
(0 rows)

SET
 id | customer_id | rating | hidden
----+-------------+--------+--------
  8 |           5 |      2 | t
(1 row)

COMMIT
```

Ana doesn't see the hidden review; Emma, its author, does. The same pattern hides **soft-deleted rows** (chapter 14):
a restrictive policy `USING (deleted_at IS NULL)` for the application roles makes deleted rows vanish from every query,
so no query can forget the condition. A restrictive policy alone allows nothing: a role needs at least one permissive
policy for the command, or it sees no rows at all.

`\dp` lists a table's policies, and the `pg_policies` view shows them in a queryable form:

```sql
SELECT policyname, permissive, roles, cmd, qual, with_check
FROM pg_policies
WHERE tablename = 'reviews'
ORDER BY policyname;
```

```text
     policyname     | permissive  |       roles        |  cmd   |                          qual                           |              with_check
--------------------+-------------+--------------------+--------+---------------------------------------------------------+---------------------------------------
 reviews_delete     | PERMISSIVE  | {inkwell_portal}   | DELETE | (customer_id = current_customer_id())                   |
 reviews_insert     | PERMISSIVE  | {inkwell_portal}   | INSERT |                                                         | (customer_id = current_customer_id())
 reviews_not_hidden | RESTRICTIVE | {inkwell_portal}   | SELECT | ((NOT hidden) OR (customer_id = current_customer_id())) |
 reviews_read       | PERMISSIVE  | {inkwell_portal}   | SELECT | true                                                    |
 reviews_staff      | PERMISSIVE  | {inkwell_readonly} | ALL    | true                                                    |
 reviews_update     | PERMISSIVE  | {inkwell_portal}   | UPDATE | (customer_id = current_customer_id())                   |
(6 rows)
```

### Who bypasses RLS

RLS doesn't apply to everyone:

- **Superusers** and roles with the **`BYPASSRLS`** attribute ignore all policies.
- **The table's owner** ignores them too, unless the table has `FORCE ROW LEVEL SECURITY`.

```sql
SET ROLE inkwell_owner;
SELECT count(*) FROM orders;
RESET ROLE;
```

```text
SET
 count
-------
    12
(1 row)

RESET
```

The owner sees all 12 orders with no policy for it. That's one more reason the application must not connect as the
owner. `ALTER TABLE orders FORCE ROW LEVEL SECURITY` makes the owner subject to the policies too, which matters when an
application has to own its tables (the multi-tenant exercise below uses it); superusers and `BYPASSRLS` still bypass.

Backups need every row. A role that is subject to RLS can set **`row_security = off`**, which doesn't turn RLS off, but
makes any query that policies would filter fail instead of silently returning fewer rows:

```sql
SET ROLE inkwell_portal;
SET row_security = off;
SELECT count(*) FROM orders;
RESET row_security;
RESET ROLE;
```

```text
SET
SET
ERROR:  query would be affected by row-level security policy for table "orders"
RESET
RESET
```

`pg_dump` sets `row_security = off` by default, so a dump made by a role subject to RLS fails rather than produce an
incomplete backup. Run backups as a superuser, the owner, or a role with `BYPASSRLS` (chapter 23).

### Views over RLS tables: security_invoker

Because a view uses its **owner's** privileges, it also uses the owner's view of RLS. A view owned by the table owner
skips RLS entirely:

```sql
SET ROLE inkwell_owner;
CREATE VIEW my_orders AS
SELECT id, status, created_at FROM orders;
RESET ROLE;
GRANT SELECT ON my_orders TO inkwell_portal;

BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
SELECT count(*) FROM my_orders;
COMMIT;
```

```text
SET
CREATE VIEW
RESET
GRANT
BEGIN
SET
SET
 count
-------
    12
(1 row)

COMMIT
```

Ana sees all 12 orders through the view. **Security invoker views** (PostgreSQL 15+) check privileges and policies as
the *querying* role instead:

```sql
ALTER VIEW my_orders SET (security_invoker = true);

BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
SELECT count(*) FROM my_orders;
COMMIT;
```

```text
ALTER VIEW
BEGIN
SET
SET
 count
-------
     3
(1 row)

COMMIT
```

The rule: **views over tables with RLS should be `security_invoker`** (on PostgreSQL 14, owned by a role that is
subject to the policies). The flip side is that the querying role then needs privileges on the underlying tables
too, so a security invoker view can't be used to hide a table the way `monthly_revenue` does.

### Performance

Policies become ordinary conditions in the plan:

```sql
BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
EXPLAIN (COSTS OFF) SELECT id FROM orders WHERE status = 'shipped';
COMMIT;
```

```text
BEGIN
SET
SET
                                                                  QUERY PLAN
-----------------------------------------------------------------------------------------------------------------------------------------------
 Seq Scan on orders
   Filter: ((customer_id = (NULLIF(current_setting('app.customer_id'::text, true), ''::text))::bigint) AND (status = 'shipped'::order_status))
(2 rows)

COMMIT
```

`current_customer_id()` was inlined and appears as a filter next to the query's own condition. On a large table,
**index the columns your policies use** (`orders_customer_id_idx` already exists); the policy condition can then be an
index condition like any other. With the bulk data from chapter 15 loaded, the same query becomes a
`Bitmap Index Scan on orders_customer_id_idx` with the policy's expression as its `Index Cond`. Other things to know:

- Keep policy expressions simple and their functions `STABLE` (never `VOLATILE`), so that the planner can use them in
  index conditions, where they're evaluated once per scan rather than once per row. A per-row subquery in a policy
  (like the one on `order_items`) costs what it would cost in a `WHERE` clause.
- The query's own conditions are evaluated **after** the policies unless they're leakproof, for the same reason as
  with security barrier views. Built-in comparisons are leakproof, so `WHERE status = 'shipped'` is not a problem,
  but a condition with a non-leakproof function (such as `LIKE`, as you saw) can't be used inside an index scan
  together with the policy.
- Test with realistic data and `EXPLAIN ANALYZE` while subject to the policy, with `SET ROLE` as above, not as a
  superuser that bypasses it.

### Pitfalls

- **RLS trusts the setting.** Any role that can run SQL can also run `SET app.customer_id = '2'`. The policy protects
  against bugs in the portal's queries, not against an attacker who can send arbitrary SQL as the portal role. For
  that, each end user would need their own database role (some platforms do this, with roles switched per request) or
  the setting would have to be verified, for example by a `SECURITY DEFINER` function that checks a signed token.
- **Set the context per transaction**, with `SET LOCAL` or `set_config(..., true)`. A plain `SET` survives the
  transaction and leaks into the next request on a pooled connection. With a pooler in transaction mode, only
  transaction-scoped settings are safe at all (chapter 22).
- **Constraints see all rows.** Unique and foreign key checks aren't filtered by RLS, so their errors can reveal that a
  row exists. Inserting a customer with an e-mail address that's already registered fails, which tells the caller
  that the address exists, policy or not.
- **`TRUNCATE` and `REFERENCES` aren't subject to RLS.** Don't grant them to roles that are.
- **Check coverage.** Enabling RLS on a table with no matching policy hides everything (safe, but a production outage);
  forgetting to enable it on a new table leaks everything. Exercise 5 builds a query that audits both.

The same tools build **multi-tenant** applications on shared tables (chapter 14): a `tenant_id` column on every table,
the tenant id in a setting such as `app.tenant_id`, and one `FOR ALL` policy per table with
`USING (tenant_id = current_tenant_id())`, which also checks inserted and updated rows. Exercise 4 builds one.

## Authentication

Privileges decide what a role may do once connected. **Client authentication** decides who may connect as which role
in the first place, and it's configured in a file, not in SQL.

```text
client connects ──► which pg_hba.conf line matches?          (connection type, database, role, address)
                         │ first match only
                         ▼
                    that line's method: reject / scram-sha-256 / peer / cert / ...
                         │ success
                         ▼
                    role has LOGIN?  CONNECT on the database?  connection limits?
                         │
                         ▼
                    session starts (role settings applied, e.g. inkwell_migrate's SET ROLE)
```

### pg_hba.conf

`pg_hba.conf` ("host-based authentication") lives in the data directory on most installations, or in
`/etc/postgresql/18/main/` on Debian and Ubuntu. Ask the server where it is:

```sql
SHOW hba_file;
```

Each line has the form `TYPE DATABASE USER [ADDRESS] METHOD [OPTIONS]`. A production configuration for Inkwell:

```text
# TYPE    DATABASE   USER               ADDRESS         METHOD
local     all        postgres                           peer
local     inkwell    +inkwell_readonly                  scram-sha-256
hostssl   inkwell    inkwell_app        10.0.1.0/24     scram-sha-256
hostssl   inkwell    +inkwell_readonly  10.0.2.0/24     scram-sha-256
host      all        all                0.0.0.0/0       reject
host      all        all                ::/0            reject
```

| Column | Values |
|---|---|
| `TYPE` | `local` (Unix-domain socket), `host` (TCP, with or without TLS), `hostssl` (TCP with TLS only), `hostnossl` (TCP without TLS), `hostgssenc` / `hostnogssenc` (GSSAPI encryption) |
| `DATABASE` | a database name, `all`, `sameuser`, `replication` (for replication connections), or a comma-separated list |
| `USER` | a role name, `all`, `+group` (members of the role `group`, directly or indirectly), or a list |
| `ADDRESS` | for `host*` lines: a CIDR range like `10.0.1.0/24`, a single address `10.0.1.5/32`, or a host name |
| `METHOD` | how to authenticate (below) |

The rules are evaluated **top to bottom, and the first line that matches the connection's type, database, user and
address decides**. There's no fall-through: if that line's method fails (wrong password), the connection is refused,
even if a later line would have let it in. If no line matches, the connection is refused too. Put specific lines
first and broad ones last.

The file above lets `postgres` in only from the server itself, as the operating-system user `postgres`; lets the
read-only group (and therefore the application and reporting roles) connect locally with a password; requires TLS
and a password for the application servers' network (`10.0.1.0/24`) and for the reporting network; and explicitly
rejects everything else, which makes the intent visible (no match would also mean "refused").

The authentication methods:

| Method | How it works | Use it for |
|---|---|---|
| `scram-sha-256` | challenge-response password check; the password never crosses the network, and the server stores only a verifier | passwords, everywhere |
| `md5` | older password hashing scheme. **Deprecated in PostgreSQL 18**. An `md5` line automatically uses SCRAM for roles whose stored password is a SCRAM verifier | migrating old installations only |
| `password` | sends the password in clear text | never (except over TLS, and even then prefer SCRAM) |
| `peer` | `local` only: the operating-system user name must equal the role name (or be mapped in `pg_ident.conf`) | administrators on the server itself |
| `cert` | the client presents a TLS certificate whose common name is the role name | service-to-service connections without passwords |
| `gss`, `sspi`, `ldap`, `radius`, `pam` | delegate to Kerberos, Windows, an LDAP directory, RADIUS or PAM | integrating with company directories |
| `oauth` (18+) | validates an OAuth 2.0 bearer token with a validator library | single sign-on; requires `oauth_validator_libraries` |
| `trust` | lets anyone in as any role, no questions asked | throwaway local development only |
| `reject` | refuses the connection | ending the file, and blocking ranges explicitly |

A fresh installation often uses `peer` for local connections and `scram-sha-256` for TCP, which is why chapter 2 used
`-h localhost` to get a password prompt.

### Checking and reloading

Changes to `pg_hba.conf` take effect on **reload**, not restart, and only if the whole file is valid: if any line has
an error, the server logs it and keeps the old rules. Check the file *before* reloading with the
`pg_hba_file_rules` view, which parses the file as it is on disk right now:

```sql
SELECT line_number, error FROM pg_hba_file_rules WHERE error IS NOT NULL;
```

```text
 line_number | error
-------------+-------
(0 rows)
```

No errors. Here's what the same query reports for a file with two typos:

```text
 line_number |                     error
-------------+-----------------------------------------------
           2 | invalid CIDR mask in address "10.0.1.0/33"
           3 | invalid authentication method "scram-sha-265"
(2 rows)
```

And this is how the server reads the production file above:

```bash
psql -c "SELECT line_number, type, database, user_name, address, auth_method FROM pg_hba_file_rules"
```

```text
 line_number |  type   | database  |      user_name      | address  |  auth_method
-------------+---------+-----------+---------------------+----------+---------------
           2 | local   | {all}     | {postgres}          |          | peer
           3 | local   | {inkwell} | {+inkwell_readonly} |          | scram-sha-256
           4 | hostssl | {inkwell} | {inkwell_app}       | 10.0.1.0 | scram-sha-256
           5 | hostssl | {inkwell} | {+inkwell_readonly} | 10.0.2.0 | scram-sha-256
           6 | host    | {all}     | {all}               | 0.0.0.0  | reject
           7 | host    | {all}     | {all}               | ::       | reject
(6 rows)
```

Then reload, either with `pg_ctl reload`, `systemctl reload postgresql`, or from SQL:

```sql
SELECT pg_reload_conf();
```

```text
 pg_reload_conf
----------------
 t
(1 row)
```

`pg_reload_conf()` returns true when the signal was sent, even if the new file is then rejected: check the server log
(`pg_hba.conf was not reloaded`) or the view first. Existing connections are never affected; the rules are checked
only when a connection starts.

When a connection is refused, the error tells you which part failed. From a test server configured like the file
above, but with `127.0.0.1/32` as the application network:

```text
FATAL:  password authentication failed for user "inkwell_app"
FATAL:  pg_hba.conf rejects connection for host "127.0.0.1", user "inkwell_app", database "inkwell", no encryption
FATAL:  pg_hba.conf rejects connection for host "127.0.0.1", user "postgres", database "inkwell", SSL encryption
```

The first is a wrong password. The second is a connection without TLS: it doesn't match the `hostssl` lines, so it
falls to `reject`. The third is `postgres` over the network, which only the final `reject` lines match. (A missing
match says `no pg_hba.conf entry for host ...` instead.) For security, the client sees as little as possible; the
server log has more detail.

### Passwords

The server stores a **SCRAM verifier** (a salted, iterated hash), never the password:

```sql
SHOW password_encryption;
SELECT rolname, left(rolpassword, 14) AS starts_with
FROM pg_authid
WHERE rolname LIKE 'inkwell%' AND rolpassword IS NOT NULL
ORDER BY rolname;
```

```text
 password_encryption
---------------------
 scram-sha-256
(1 row)

     rolname     |  starts_with
-----------------+----------------
 inkwell_app     | SCRAM-SHA-256$
 inkwell_migrate | SCRAM-SHA-256$
 inkwell_partner | SCRAM-SHA-256$
 inkwell_portal  | SCRAM-SHA-256$
 inkwell_report  | SCRAM-SHA-256$
(5 rows)
```

(`pg_authid` is readable only by superusers; `pg_roles` shows `********` instead.) MD5 password hashes are
**deprecated in PostgreSQL 18**: setting one now warns.

```sql
SET password_encryption = 'md5';
ALTER ROLE inkwell_report PASSWORD 'report-dev-password';
RESET password_encryption;
ALTER ROLE inkwell_report PASSWORD 'report-dev-password';
```

```text
SET
WARNING:  setting an MD5-encrypted password
DETAIL:  MD5 password support is deprecated and will be removed in a future release of PostgreSQL.
HINT:  Refer to the PostgreSQL documentation for details about migrating to another password type.
ALTER ROLE
RESET
ALTER ROLE
```

If you upgraded from an old version, find the remaining MD5 passwords with
`SELECT rolname FROM pg_authid WHERE rolpassword LIKE 'md5%';`, make sure `password_encryption` is
`scram-sha-256`, and have each user set their password again (`\password`). Lines in `pg_hba.conf` that say `md5`
already accept SCRAM verifiers, so switch them to `scram-sha-256` once every password has been converted.

Good practice for passwords:

- **Applications get long random passwords** from a secrets manager or environment variables, never in the
  repository. Rotate them by creating the new password, deploying it, then dropping the old one. (Each role has only
  one password, so a seamless rotation alternates between two login roles that are members of the same group.)
- **People** use `\password`, or better, single sign-on through LDAP, Kerberos or OAuth.
- **`VALID UNTIL`** gives temporary access an end date.

### Per-role settings

`ALTER ROLE ... SET` (you used it for `inkwell_migrate`) is also how to give each role safe limits:

```sql
ALTER ROLE inkwell_app SET statement_timeout = '5s';
ALTER ROLE inkwell_app SET idle_in_transaction_session_timeout = '30s';
ALTER ROLE inkwell_report SET default_transaction_read_only = on;
ALTER ROLE inkwell_report SET statement_timeout = '2min';
ALTER ROLE inkwell_report IN DATABASE inkwell SET work_mem = '64MB';
\drds inkwell*
```

```text
ALTER ROLE
ALTER ROLE
ALTER ROLE
ALTER ROLE
ALTER ROLE
                           List of settings
      Role       | Database |                Settings
-----------------+----------+-----------------------------------------
 inkwell_app     |          | statement_timeout=5s                   +
                 |          | idle_in_transaction_session_timeout=30s
 inkwell_migrate |          | role=inkwell_owner
 inkwell_report  | inkwell  | work_mem=64MB
 inkwell_report  |          | default_transaction_read_only=on       +
                 |          | statement_timeout=2min
(4 rows)
```

They are applied when the role logs in (not on `SET ROLE`), and a session can still override them, so they are
safety nets, not security. `default_transaction_read_only` protects the reporting tool from its own mistakes, while
the missing privileges are what actually stop it from writing.

## TLS

Without encryption, everything between client and server, including query results and (for the `password` method)
passwords, crosses the network in clear text, and an attacker in the middle can impersonate the server. TLS fixes
both, but only if the client **verifies the server's certificate**.

### The server side

The server needs a certificate and its private key. In `postgresql.conf`:

```text
ssl = on
ssl_cert_file = 'server.crt'          # relative to the data directory, or an absolute path
ssl_key_file = 'server.key'           # must be readable only by the server's OS user (chmod 600)
ssl_min_protocol_version = 'TLSv1.2'  # the default; 'TLSv1.3' if all clients support it
```

Turning `ssl` on needs only a reload. In production, the certificate comes from your organization's certificate
authority (CA) or a public one; for testing, a private CA takes a few commands:

```bash
# a private certificate authority
openssl req -new -x509 -days 3650 -nodes -subj "/CN=Inkwell dev CA" -keyout ca.key -out ca.crt
# the server's key and certificate, signed by that CA, valid for two host names
openssl req -new -nodes -subj "/CN=db.inkwell.test" -keyout server.key -out server.csr
printf "subjectAltName=DNS:localhost,DNS:db.inkwell.test\n" > san.ext
openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 365 -extfile san.ext -out server.crt
chmod 600 server.key
```

The **subject alternative names** list the host names clients will use to connect; the client checks that the name it
connected to is on the list. Clients get `ca.crt` (never `ca.key`).

`hostssl` lines in `pg_hba.conf` then *require* TLS for those connections, as in the file above.

### The client side: sslmode

What the client does is controlled by its `sslmode` connection parameter:

| `sslmode` | Encrypted? | Server's certificate checked? | Protects against |
|---|---|---|---|
| `disable` | no | no | nothing |
| `allow`, `prefer` (the default) | if the server wants / if possible | no | passive eavesdropping, and only if TLS is available |
| `require` | yes | no | passive eavesdropping |
| `verify-ca` | yes | signed by a trusted CA | eavesdropping and impersonation by anyone without a certificate from that CA |
| `verify-full` | yes | signed by a trusted CA **and** issued for the host name you connected to | eavesdropping and impersonation |

The default, `prefer`, silently falls back to an unencrypted connection, and even with `require` the client accepts
any certificate, so a machine in the middle can present its own. **Use `verify-full` across any network you don't
fully control.** From the test server:

```bash
psql "host=localhost port=5432 dbname=inkwell user=inkwell_app sslmode=verify-full sslrootcert=ca.crt" \
     -c "SELECT ssl, version, cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()"
```

```text
 ssl | version |         cipher
-----+---------+------------------------
 t   | TLSv1.3 | TLS_AES_256_GCM_SHA384
(1 row)
```

`sslrootcert` names the CA certificate to trust (the default is `~/.postgresql/root.crt`). The checks in action:

```text
$ psql "host=127.0.0.1 ... sslmode=verify-full sslrootcert=ca.crt"
psql: error: connection to server at "127.0.0.1", port 5432 failed: server certificate for "localhost" (and 2 other names) does not match host name "127.0.0.1"

$ psql "host=localhost ... sslmode=verify-full"
psql: error: connection to server at "localhost" (127.0.0.1), port 5432 failed: root certificate file "/home/you/.postgresql/root.crt" does not exist
Either provide the file, use the system's trusted roots with sslrootcert=system, or change sslmode to disable server certificate verification.
```

The first connected by IP address, which isn't on the certificate. The second had no CA to check against. With
`sslmode=require`, both would have connected without complaint, which is exactly the problem.

If the server's certificate comes from a public CA (as with most managed database services), use
**`sslrootcert=system`** (libpq 16+) to trust the operating system's CA store. It makes `verify-full` the default and
refuses weaker modes, because anyone can get a publicly trusted certificate for a host name they control.

The same parameters work in connection URIs
(`postgresql://inkwell_app@db.inkwell.test/inkwell?sslmode=verify-full&sslrootcert=ca.crt`) and in every libpq-based
client, including PHP's PDO. Node.js's `pg` package takes its TLS options as an object instead; chapter 22 shows both.

To check who is connected how:

```sql
SELECT a.usename, a.client_addr, s.ssl, s.version
FROM pg_stat_activity a
JOIN pg_stat_ssl s USING (pid)
WHERE a.backend_type = 'client backend';
```

Connections through the Unix socket show `ssl = f`; they never leave the machine.

**Client certificates** can replace or supplement passwords. The server needs `ssl_ca_file` pointing to the CA that
signs client certificates, and then:

```text
# TYPE    DATABASE   USER            ADDRESS         METHOD          OPTIONS
hostssl   inkwell    inkwell_app     10.0.1.0/24     scram-sha-256   clientcert=verify-full
hostssl   inkwell    inkwell_batch   10.0.3.0/24     cert
```

The first line requires a valid client certificate issued for the role name *and* the password; the second accepts
the certificate alone. The client passes `sslcert` and `sslkey`.

> **Just a taste — you don't need to learn or fully understand this now.** OAuth single sign-on (PostgreSQL 18+) needs
> a validator library from your identity provider, configured in `oauth_validator_libraries`.

```text
hostssl   all        all             0.0.0.0/0       oauth   issuer="https://auth.example.com" scope="openid"
```

## A security checklist

| Area | Do |
|---|---|
| Superusers | Only for administrators, ideally reachable only via `peer` on the server itself. Never for applications |
| Ownership | One `NOLOGIN` owner role; migrations `SET ROLE` to it; applications never own tables |
| Groups | Privileges go to group roles (`readonly`, `readwrite`, ...); login roles are members |
| Defaults | `REVOKE ALL ON DATABASE ... FROM PUBLIC`; `ALTER DEFAULT PRIVILEGES FOR ROLE <owner>` for tables, sequences and functions |
| Schemas | No `CREATE` for untrusted roles anywhere on someone's `search_path`; check `public` on databases upgraded from 14 or older |
| Functions | `SECURITY DEFINER` only when needed, with `SET search_path = ..., pg_temp` and `EXECUTE` granted on purpose |
| Views | Views that hide rows are `security_barrier`; views over RLS tables are `security_invoker` |
| RLS | Enabled on every table with per-user data; fail closed; context set per transaction; coverage audited |
| Authentication | `scram-sha-256` everywhere, no `trust` or `password`, explicit `reject` at the end of `pg_hba.conf` |
| Network | `listen_addresses` only on the interfaces you need, a firewall in front, `hostssl` lines, `sslmode=verify-full` in clients |
| Limits | Per-role `statement_timeout`, `idle_in_transaction_session_timeout`, `CONNECTION LIMIT` |
| Auditing | `log_connections`, `log_disconnections` and, for DDL, `log_statement = 'ddl'`; the `pgaudit` extension for detailed audit logs |

## Key takeaways

- A role is both user and group. Login roles are members of group roles that carry the privileges. Roles are
  cluster-wide; privileges are per object. Attributes such as `SUPERUSER` and `BYPASSRLS` are never inherited.
- The owner can do everything to an object, including dropping it and bypassing RLS. Use a `NOLOGIN` owner role,
  migrate as it, and give applications only data privileges.
- Access needs privileges at every level: `CONNECT` on the database, `USAGE` on the schema, then the table, column or
  function privilege. `PUBLIC` gets `CONNECT` and `TEMPORARY` on databases and `EXECUTE` on functions by default;
  revoke what you don't want.
- `GRANT ... ON ALL TABLES` covers existing objects; `ALTER DEFAULT PRIVILEGES FOR ROLE <creator>` covers future ones,
  but only those created by that role.
- `SECURITY DEFINER` functions must set `search_path` with `pg_temp` last, or temporary tables can hijack them.
  SQL-standard function bodies resolve names at creation time.
- Views run with their owner's privileges. Views that hide rows need `security_barrier`, or a cheap function can leak
  the hidden rows; only `LEAKPROOF` conditions are pushed into them.
- RLS adds policies to every query: `USING` filters existing rows, `WITH CHECK` validates new ones; permissive
  policies are combined with `OR`, restrictive ones with `AND`. No policy means no rows. Owners, superusers and
  `BYPASSRLS` roles bypass it (`FORCE` covers the owner).
- Pass the application's user context with `SET LOCAL` or `set_config(..., true)`, make policies fail closed, index
  policy columns, and use `security_invoker` views over RLS tables.
- `pg_hba.conf` is first-match: check it with `pg_hba_file_rules` before reloading. Use `scram-sha-256` (MD5 is
  deprecated in 18), and `sslmode=verify-full` with the right CA certificate, since `require` doesn't stop
  impersonation.

## Exercises

The exercises continue in the same database, in the state the chapter left it.

1. **Who may do what?** Without running anything, predict the answers to these questions, then check them with the
   `has_*_privilege` functions: may `inkwell_app` delete reviews? May `inkwell_report` execute `current_customer_id()`?
   May `inkwell_portal` update the `rating` of a review, and its `product_id`? May `inkwell_partner` read
   `reporting.eu_customers`, and `customers`?

<details>
<summary>Solution</summary>

```sql
SELECT has_table_privilege('inkwell_app', 'reviews', 'DELETE')                      AS app_delete_reviews,
       has_function_privilege('inkwell_report', 'current_customer_id()', 'EXECUTE')  AS report_ccid,
       has_column_privilege('inkwell_portal', 'reviews', 'rating', 'UPDATE')          AS portal_rating,
       has_column_privilege('inkwell_portal', 'reviews', 'product_id', 'UPDATE')      AS portal_product,
       has_table_privilege('inkwell_partner', 'reporting.eu_customers', 'SELECT')     AS partner_eu,
       has_table_privilege('inkwell_partner', 'customers', 'SELECT')                  AS partner_customers;
```

```text
 app_delete_reviews | report_ccid | portal_rating | portal_product | partner_eu | partner_customers
--------------------+-------------+---------------+----------------+------------+-------------------
 t                  | f           | t             | f              | t          | f
(1 row)
```

`inkwell_app` deletes reviews through `inkwell_readwrite`. `current_customer_id()` was created after the default
privileges revoked `EXECUTE` from `PUBLIC`, and only the portal got it. The portal may update `rating` and `body` only.
The partner reads the view, which reads `customers` with the owner's privileges, but has no privilege on the table
itself. Note that these functions answer the privilege question only: whether a row is visible is up to RLS.

</details>

2. **A support role.** Create a login role `inkwell_support` for the customer support team. Support may read
   everything the read-only group reads, read customers' e-mail addresses, correct customers' names and e-mail
   addresses (but no other column), and change an order's status. Test that a support agent can fix Ivan's name to
   "Ivan Petrov-Ivanov" and that changing his country fails.

<details>
<summary>Solution</summary>

```sql
CREATE ROLE inkwell_support LOGIN PASSWORD 'support-dev-password';
GRANT inkwell_readonly TO inkwell_support;
GRANT SELECT (email), UPDATE (full_name, email) ON customers TO inkwell_support;
GRANT UPDATE (status) ON orders TO inkwell_support;

SET ROLE inkwell_support;
UPDATE customers SET full_name = 'Ivan Petrov-Ivanov' WHERE email = 'ivan@example.com';
UPDATE customers SET country = 'RO' WHERE email = 'ivan@example.com';
SELECT count(*) FROM orders;
RESET ROLE;
```

```text
CREATE ROLE
GRANT ROLE
GRANT
GRANT
SET
UPDATE 1
ERROR:  permission denied for table customers
 count
-------
    12
(1 row)

RESET
```

The `WHERE email = ...` needs `SELECT` on `email`, which the read-only group doesn't have, hence the column grant. The
order count shows that RLS doesn't get in the way: support is a member of `inkwell_readonly`, so the `orders_staff`
policy applies to it, and that policy is `FOR ALL`, so it allows the status update too. Membership in
`inkwell_readonly` also gives `CONNECT` on the database.

</details>

3. **Wishlists.** Protect `wishlist_items` with RLS: staff roles see everything, and the portal may read, add and
   remove only the current customer's items (grant the portal what it needs). Test as Ana: add the Heron pen
   (product 2) to her wishlist, try to add one to Ben's, and list her wishlist.

<details>
<summary>Solution</summary>

```sql
GRANT SELECT, INSERT, DELETE ON wishlist_items TO inkwell_portal;
ALTER TABLE wishlist_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY wishlist_staff ON wishlist_items TO inkwell_readonly USING (true);
CREATE POLICY wishlist_customer ON wishlist_items
    FOR ALL
    TO inkwell_portal
    USING (customer_id = current_customer_id());

BEGIN;
SET LOCAL ROLE inkwell_portal;
SET LOCAL app.customer_id = '1';
INSERT INTO wishlist_items (customer_id, product_id) VALUES (1, 2);
SAVEPOINT before_ben;
INSERT INTO wishlist_items (customer_id, product_id) VALUES (2, 2);
ROLLBACK TO SAVEPOINT before_ben;
SELECT customer_id, product_id FROM wishlist_items;
COMMIT;
```

```text
GRANT
ALTER TABLE
CREATE POLICY
CREATE POLICY
BEGIN
SET
SET
INSERT 0 1
SAVEPOINT
ERROR:  new row violates row-level security policy for table "wishlist_items"
ROLLBACK
 customer_id | product_id
-------------+------------
           1 |          2
(1 row)

COMMIT
```

One `FOR ALL` policy is enough: its `USING` expression filters what the portal can read and delete, and because
there's no separate `WITH CHECK`, the same expression checks inserted rows. The savepoint lets the transaction carry on
after the expected error.

</details>

4. **Multi-tenant notes.** Inkwell's parent company wants a shared notes table for several shops. As `inkwell_owner`,
   create `tenant_notes (tenant_id integer NOT NULL, id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, body text NOT
   NULL)`, a function `current_tenant_id()` reading `app.tenant_id`, and a login role `inkwell_tenant_app` that may
   read and write the table. Use RLS so that each tenant sees and writes only its own notes, *including when the table
   is accessed by its owner*. Insert two notes for tenant 1 and one for tenant 2, then check what tenant 1 sees, what
   the owner sees without a tenant id, and what a superuser sees.

<details>
<summary>Solution</summary>

```sql
CREATE ROLE inkwell_tenant_app LOGIN PASSWORD 'tenant-dev-password';
GRANT CONNECT ON DATABASE inkwell TO inkwell_tenant_app;

SET ROLE inkwell_owner;
CREATE TABLE tenant_notes (
    tenant_id  integer NOT NULL,
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    body       text NOT NULL
);
CREATE INDEX tenant_notes_tenant_id_idx ON tenant_notes (tenant_id);
CREATE FUNCTION current_tenant_id() RETURNS integer
    LANGUAGE sql STABLE
    RETURN nullif(current_setting('app.tenant_id', true), '')::integer;
GRANT EXECUTE ON FUNCTION current_tenant_id() TO inkwell_tenant_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_notes TO inkwell_tenant_app;

ALTER TABLE tenant_notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE tenant_notes FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON tenant_notes
    USING (tenant_id = current_tenant_id());
RESET ROLE;

BEGIN;
SET LOCAL ROLE inkwell_tenant_app;
SET LOCAL app.tenant_id = '1';
INSERT INTO tenant_notes (tenant_id, body) VALUES (1, 'Reorder sepia ink'), (1, 'Call the paper mill');
SET LOCAL app.tenant_id = '2';
INSERT INTO tenant_notes (tenant_id, body) VALUES (2, 'Inventory on Friday');
INSERT INTO tenant_notes (tenant_id, body) VALUES (1, 'Sneaky note for tenant 1');
ROLLBACK;
```

```text
CREATE ROLE
GRANT
SET
CREATE TABLE
CREATE INDEX
CREATE FUNCTION
GRANT
GRANT
ALTER TABLE
ALTER TABLE
CREATE POLICY
RESET
BEGIN
SET
SET
INSERT 0 2
SET
INSERT 0 1
ERROR:  new row violates row-level security policy for table "tenant_notes"
ROLLBACK
```

The last insert, made while acting as tenant 2, fails, and since it ran in the same transaction, everything is rolled
back. Do the valid inserts in their own transactions and check the reads:

```sql
BEGIN;
SET LOCAL ROLE inkwell_tenant_app;
SET LOCAL app.tenant_id = '1';
INSERT INTO tenant_notes (tenant_id, body) VALUES (1, 'Reorder sepia ink'), (1, 'Call the paper mill');
COMMIT;
BEGIN;
SET LOCAL ROLE inkwell_tenant_app;
SET LOCAL app.tenant_id = '2';
INSERT INTO tenant_notes (tenant_id, body) VALUES (2, 'Inventory on Friday');
COMMIT;

BEGIN;
SET LOCAL ROLE inkwell_tenant_app;
SET LOCAL app.tenant_id = '1';
SELECT tenant_id, body FROM tenant_notes ORDER BY id;
COMMIT;

SET ROLE inkwell_owner;
SELECT count(*) AS owner_without_tenant FROM tenant_notes;
RESET ROLE;
SELECT count(*) AS superuser FROM tenant_notes;
```

```text
BEGIN
SET
SET
INSERT 0 2
COMMIT
BEGIN
SET
SET
INSERT 0 1
COMMIT
BEGIN
SET
SET
 tenant_id |        body
-----------+---------------------
         1 | Reorder sepia ink
         1 | Call the paper mill
(2 rows)

COMMIT
SET
 owner_without_tenant
----------------------
                    0
(1 row)

RESET
 superuser
-----------
         3
(1 row)
```

The policy has no `TO`, so it applies to everyone subject to RLS (`PUBLIC`). `FORCE ROW LEVEL SECURITY` makes the
owner one of them: without a tenant id, the owner sees nothing. Only the superuser sees all three notes. In a real
multi-tenant schema every table gets the same policy, and every index starts with `tenant_id`.

</details>

5. **Audit RLS coverage.** Write a query that lists every table in the `public` schema with whether RLS is enabled,
   whether it's forced, and how many policies it has, and flags two problems: RLS enabled with no policies (nobody
   subject to RLS can see anything), and tables with a `customer_id` column but no RLS (possible per-customer data
   without protection).

<details>
<summary>Solution</summary>

```sql
SELECT c.relname AS table_name,
       c.relrowsecurity AS rls,
       c.relforcerowsecurity AS forced,
       count(p.polname) AS policies,
       CASE
           WHEN c.relrowsecurity AND count(p.polname) = 0 THEN 'RLS without policies'
           WHEN NOT c.relrowsecurity AND EXISTS (
                    SELECT FROM pg_attribute a
                    WHERE a.attrelid = c.oid AND a.attname = 'customer_id' AND NOT a.attisdropped)
               THEN 'customer data without RLS'
       END AS problem
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_policy p ON p.polrelid = c.oid
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
GROUP BY c.oid, c.relname, c.relrowsecurity, c.relforcerowsecurity
ORDER BY c.relname;
```

```text
   table_name   | rls | forced | policies | problem
----------------+-----+--------+----------+---------
 categories     | f   | f      |        0 |
 customers      | f   | f      |        0 |
 order_items    | t   | f      |        2 |
 orders         | t   | f      |        2 |
 products       | f   | f      |        0 |
 reviews        | t   | f      |        6 |
 tenant_notes   | t   | t      |        1 |
 wishlist_items | t   | f      |        2 |
(8 rows)
```

`relrowsecurity` and `relforcerowsecurity` in `pg_class` hold the two switches, and `pg_policy` the policies (the
`pg_policies` view is the friendlier version). No problems here, because exercise 3 protected the wishlist. Run a
query like this in your test suite or monitoring, so that a new table can't ship without a decision about RLS.

</details>

6. **Spot the holes.** A colleague wrote this function so that the partner can count EU customers per country.
   Create it, grant it to the partner, and find three security problems. Demonstrate one of them as the partner, then
   write a fixed version.

```sql
SET ROLE inkwell_owner;
CREATE FUNCTION reporting.customers_in(p_country text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
AS $$
DECLARE
    result bigint;
BEGIN
    EXECUTE 'SELECT count(*) FROM customers WHERE country = ''' || p_country || '''' INTO result;
    RETURN result;
END;
$$;
RESET ROLE;
GRANT EXECUTE ON FUNCTION reporting.customers_in(text) TO inkwell_partner;
```

<details>
<summary>Solution</summary>

The problems:

1. **SQL injection.** The argument is pasted into the query string. A partner can pass a value that closes the quote
   and adds conditions of their own, and the query runs with the owner's privileges.
2. **No `search_path`.** As a `SECURITY DEFINER` function, it uses the caller's `search_path`. (The partner no
   longer has `TEMPORARY`, but a role that does could shadow `customers` with a temporary table.)
3. **No EU restriction.** The function counts customers of any country, revealing exactly what `eu_customers` hides.

Injection in action. The partner learns whether a customer with a given e-mail address exists, one guess at a time:

```sql
SET ROLE inkwell_partner;
SELECT reporting.customers_in('FR');
SELECT reporting.customers_in($$x' OR email = 'hana@example.com$$);
SELECT reporting.customers_in($$x' OR email = 'zoe@example.com$$);
RESET ROLE;
```

```text
SET
 customers_in
--------------
            1
(1 row)

 customers_in
--------------
            1
(1 row)

 customers_in
--------------
            0
(1 row)

RESET
```

The resulting query is `... WHERE country = 'x' OR email = 'hana@example.com'`: a yes/no oracle on a column the
partner can't read (Hana is a customer, Zoe isn't). Dollar quoting (`$$...$$`) just saves escaping the quotes in the
argument. The fixed version needs no dynamic SQL at all, uses an SQL-standard body (names resolved at creation time),
and sets `search_path` anyway:

```sql
SET ROLE inkwell_owner;
CREATE OR REPLACE FUNCTION reporting.customers_in(p_country text) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path = pg_catalog, public, pg_temp
BEGIN ATOMIC
    SELECT count(*) FROM reporting.eu_customers WHERE country = p_country;
END;
RESET ROLE;

SET ROLE inkwell_partner;
SELECT reporting.customers_in('FR');
SELECT reporting.customers_in('US');
SELECT reporting.customers_in($$x' OR email = 'hana@example.com$$);
RESET ROLE;
```

```text
SET
CREATE FUNCTION
RESET
SET
 customers_in
--------------
            1
(1 row)

 customers_in
--------------
            0
(1 row)

 customers_in
--------------
            0
(1 row)

RESET
```

The parameter is now a value, never SQL, so the injection attempt just counts customers in a country with a very odd
name. Counting through `eu_customers` keeps the function within what the partner may know. When dynamic SQL is
unavoidable, pass values with `USING` and identifiers with `format('%I')` (chapter 19). `CREATE OR REPLACE` kept the
grant to the partner.

</details>

---
← Previous: [Views, materialized views and partitioning](20-views-materialized-views-and-partitioning.md) | Next: [Using PostgreSQL from Node.js and PHP](22-using-postgresql-from-node-and-php.md) →
