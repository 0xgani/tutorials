# Chapter 11 — Compose in depth

## What you'll learn

- Healthchecks in Compose files, and how to write good ones for PostgreSQL and Valkey
- `depends_on` conditions: waiting for a healthy service and for a job that completed successfully
- One-off jobs such as database migrations, as part of the stack
- Variable interpolation, the `.env` file, and the difference between `.env`, `env_file:` and `environment:`
- Profiles for optional services
- Several Compose files: overrides, merging rules, `!reset`, `include` and `extends`
- Extension fields and YAML anchors to remove repetition
- Scaling a service, and what stops a service from scaling
- Snip grows a Valkey cache, a background worker and a migration job

## Snip's new architecture

Every redirect in chapter 10 cost two database round trips: a `SELECT` to find the URL and an `UPDATE` to count the
click. For a URL shortener, redirects are almost the entire traffic, so this chapter takes them off the database:

```text
                     ┌────────────── project "snip", network snip_default ───────────────┐
  curl ──▶ :3000 ──▶ │ api ──── GET url:<code> / LPUSH clicks ────▶ valkey (cache + queue)│
                     │  │                                              ▲                  │
                     │  │ INSERT / SELECT                     BLMPOP   │                  │
                     │  ▼                                              │                  │
                     │  db (PostgreSQL) ◀──── UPDATE … clicks + n ──── worker             │
                     │  ▲                                                                 │
                     │  └── migrate (runs once, applies migrations/*.sql, exits)          │
                     └────────────────────────────────────────────────────────────────────┘
```

- **valkey** is Valkey, the open-source fork of Redis (same protocol, same clients, BSD licensed). It caches
  `code → URL` for an hour and holds a list of clicks waiting to be counted.
- **api** answers redirects from the cache and pushes each click onto the list.
- **worker** is a second process from the *same image*: it pops clicks off the list in batches and adds them to
  PostgreSQL with one `UPDATE` per batch.
- **migrate** is a job: it creates or updates the database schema, then exits. The API starts only after it succeeded.

Five services, one image for three of them, and a startup order that now really matters. That's what this chapter's
Compose features are for. The complete project is in
[`examples/ch11-compose-in-depth/`](examples/ch11-compose-in-depth/).

### The code changes

Install the Valkey client. Valkey speaks the Redis protocol, so the standard `redis` package is the client:

```bash
npm install redis
```

`src/valkey.js` connects:

```js
import { createClient } from 'redis';

// Connects to Valkey (or Redis: same protocol). VALKEY_URL looks like redis://valkey:6379.
// The client reconnects on its own if the connection drops later.
export async function connectValkey(url) {
  const client = createClient({ url });
  client.on('error', (err) => console.error(`valkey: ${err.message}`));
  await client.connect();
  return client;
}
```

The redirect route now asks the store for just the URL, through a new `resolve(code)` method (the memory and
PostgreSQL stores implement it with a lookup and a `SELECT url …`):

```js
  app.get('/:code', async (req, res) => {
    const url = await store.resolve(req.params.code);
    if (!url) return res.status(404).type('text').send('not found\n');
    await store.recordClick(req.params.code);
    res.redirect(302, url);
  });
```

`src/cached-store.js` wraps the PostgreSQL store:

```js
// Wraps a store with Valkey: redirects are answered from a cache, and clicks are
// queued on a list for the worker instead of updating PostgreSQL on every request.
export function withValkey(store, valkey, { ttlSeconds = 3600 } = {}) {
  return {
    create: (link) => store.create(link),
    get: (code) => store.get(code),

    async resolve(code) {
      const key = `url:${code}`;
      const cached = await valkey.get(key);
      if (cached) return cached;
      const url = await store.resolve(code);
      if (url) await valkey.set(key, url, { EX: ttlSeconds });
      return url;
    },

    async recordClick(code) {
      await valkey.lPush('clicks', code);
    },

    async close() {
      await valkey.close();
      await store.close?.();
    },
  };
}
```

and `server.js` uses it when `VALKEY_URL` is set:

```js
let store = process.env.DATABASE_URL
  ? await createPgStore(process.env.DATABASE_URL)
  : createMemoryStore();
if (process.env.VALKEY_URL) {
  store = withValkey(store, await connectValkey(process.env.VALKEY_URL));
}
```

`src/worker.js` is the second entry point:

```js
import pg from 'pg';
import { connectValkey } from './valkey.js';

// Moves queued clicks from Valkey into PostgreSQL in batches:
// one UPDATE per link per batch instead of one per redirect.
const pool = new pg.Pool({ connectionString: process.env.DATABASE_URL, max: 2 });
pool.on('error', (err) => console.error(`database: ${err.message}`));
const valkey = await connectValkey(process.env.VALKEY_URL);
let running = true;

// Finish the current batch, then exit. The blocking pop returns within 2 s,
// well inside Docker's 10 s stop timeout.
function stop(signal) {
  console.log(`${signal} received, stopping after the current batch`);
  running = false;
}
process.on('SIGTERM', stop);
process.on('SIGINT', stop);

console.log(`worker started (pid ${process.pid})`);
while (running) {
  // Wait up to 2 s for clicks, then take up to 500 at once.
  const reply = await valkey.blmPop(2, 'clicks', 'RIGHT', { COUNT: 500 });
  if (!reply) continue;
  const counts = new Map();
  for (const code of reply[1]) counts.set(code, (counts.get(code) ?? 0) + 1);
  await pool.query(
    `UPDATE links SET clicks = links.clicks + batch.n
       FROM unnest($1::text[], $2::int[]) AS batch(code, n)
      WHERE links.code = batch.code`,
    [[...counts.keys()], [...counts.values()]],
  );
  console.log(`recorded ${reply[1].length} clicks for ${counts.size} links`);
}

await valkey.close();
await pool.end();
console.log('worker stopped');
```

The shutdown design is the container-relevant part. Docker sends SIGTERM and waits 10 seconds before SIGKILL. The
worker never blocks for more than 2 seconds, so it always notices the signal, finishes the batch it's holding, and
exits cleanly. A worker that blocked forever (`timeout 0`) would be killed mid-batch every time it stops.
`BLMPOP` (Valkey and Redis 7+) pops atomically, so several workers can share one list without counting a click twice.

### Migrations as a job

In chapter 10, `createPgStore` ran `CREATE TABLE IF NOT EXISTS` at startup. That works for one table and one
instance. It stops working when the schema changes over time (`ALTER TABLE`s that must run exactly once, in order) and
when several API containers start at once and race to change the schema.

The standard answer is a **migration step that runs before the app**: numbered SQL files, applied in order, with a
table that records which ones already ran. Real projects use a library (node-pg-migrate, Knex, Prisma Migrate,
Drizzle); Snip's 40-line version shows what they all do. `migrations/001-create-links.sql`:

```sql
CREATE TABLE links (
  code       text PRIMARY KEY,
  url        text NOT NULL,
  clicks     bigint NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now()
);
```

`src/migrate.js`:

```js
import pg from 'pg';
import { readdir, readFile } from 'node:fs/promises';

// Applies every migrations/*.sql file that hasn't been applied yet, in name order,
// each in its own transaction. Runs as a one-off container before the API starts.
const dir = new URL('../migrations/', import.meta.url);
const client = new pg.Client({ connectionString: process.env.DATABASE_URL });
await client.connect();

try {
  // Two migrate containers started at once must not both apply the same file.
  await client.query('SELECT pg_advisory_lock(20261015)');
  await client.query(`CREATE TABLE IF NOT EXISTS schema_migrations (
    name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())`);
  const { rows } = await client.query('SELECT name FROM schema_migrations');
  const applied = new Set(rows.map((row) => row.name));
  const files = (await readdir(dir)).filter((name) => name.endsWith('.sql')).sort();

  for (const name of files.filter((file) => !applied.has(file))) {
    const sql = await readFile(new URL(name, dir), 'utf8');
    await client.query('BEGIN');
    try {
      await client.query(sql);
      await client.query('INSERT INTO schema_migrations (name) VALUES ($1)', [name]);
      await client.query('COMMIT');
      console.log(`applied ${name}`);
    } catch (err) {
      await client.query('ROLLBACK');
      throw new Error(`migration ${name} failed: ${err.message}`);
    }
  }
  console.log(`database is up to date (${files.length} migrations)`);
} finally {
  await client.end();
}
```

The script exits with code 0 on success and non-zero on failure (an uncaught error). Compose uses exactly that exit
code to decide whether the API may start. The migrations ship **inside the image**, next to the code that expects
them, so image `snip:1.4` always carries the schema it needs. The Dockerfile gets one more line in each runtime stage:

```dockerfile
COPY src ./src
COPY migrations ./migrations
```

## Healthchecks in Compose

A healthcheck is a command Docker runs inside the container at an interval; exit code 0 means healthy (chapter 5).
Images can define one with `HEALTHCHECK`, and a Compose service can define, override or disable it:

```yaml
  db:
    image: postgres:18-alpine
    healthcheck:
      test: ["CMD", "pg_isready", "-h", "127.0.0.1", "-U", "snip", "-d", "snip"]
      interval: 10s          # time between checks once running
      timeout: 3s            # a check that takes longer counts as failed
      retries: 5             # consecutive failures before "unhealthy"
      start_period: 30s      # failures during this period don't count…
      start_interval: 1s     # …and checks run this often during it (Engine 25+)
```

The `test` forms:

| Form | Runs | Use |
|---|---|---|
| `["CMD", "prog", "arg"]` | `prog` directly | The default choice; no shell needed |
| `["CMD-SHELL", "prog arg \|\| exit 1"]` | `/bin/sh -c "…"` | When you need a pipe, `\|\|` or a variable |
| `"prog arg"` (a plain string) | Same as `CMD-SHELL` | |
| `["NONE"]` or `disable: true` | Nothing | Turn off a healthcheck the image defines |

`start_interval` is what makes startup fast: during `start_period` Docker checks every second, so a database that is
ready after 3 seconds is reported healthy after 3 seconds, not after the first 10-second `interval`. Once the
container has been healthy once, or the start period is over, the normal `interval` applies.

### Writing a good healthcheck

A healthcheck answers one question: **can this container do its job right now?** Good ones are:

- **Cheap.** It runs every few seconds for the container's whole life.
- **Local.** Check *this* container. If the API's healthcheck also checks the database, a database blip marks every
  API container unhealthy at once, and anything that acts on health (a load balancer, an orchestrator) makes the
  outage worse.
- **Using tools the image has.** Distroless images have no `curl`; that's why Snip's image runs a small Node script
  (chapter 7). Alpine-based images usually have `wget` but not `curl`.

For the two databases:

- **PostgreSQL:** `pg_isready` asks the server whether it accepts connections, without needing a password. The
  `-h 127.0.0.1` is deliberate. On first start, the `postgres` image's entrypoint runs a *temporary* server to create
  the user and database, and that server listens only on the Unix socket. A plain `pg_isready` connects through the
  socket and can report "ready" during initialization, moments before the temporary server shuts down and the real
  one starts. Checking over TCP waits for the real server.
- **Valkey:** `valkey-cli ping` answers `PONG` (and exits 0) once the server accepts commands.

```yaml
  valkey:
    image: valkey/valkey:9-alpine
    command: ["valkey-server", "--appendonly", "yes"]
    volumes:
      - valkeydata:/data
    healthcheck:
      test: ["CMD", "valkey-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5
      start_period: 10s
      start_interval: 1s
```

`--appendonly yes` makes Valkey write every change to disk, in the `valkeydata` volume. The cache could be thrown
away, but the click queue shouldn't be.

### Inherited healthchecks

The worker and the migration job run the Snip image, and the image has a `HEALTHCHECK` that requests
`http://127.0.0.1:3000/healthz`. Nothing listens on port 3000 in the worker, so without intervention the worker
would be marked `unhealthy` 30 seconds after it starts. A service that reuses an image for a different role must
override or disable the image's healthcheck:

```yaml
  worker:
    healthcheck:
      disable: true
```

## `depends_on` conditions

The long form of `depends_on` names a condition per dependency:

```yaml
  api:
    depends_on:
      migrate:
        condition: service_completed_successfully
      valkey:
        condition: service_healthy

  migrate:
    depends_on:
      db:
        condition: service_healthy
        restart: true
```

| Condition | Compose waits until the dependency… |
|---|---|
| `service_started` (the short form's meaning) | has been started |
| `service_healthy` | reports `healthy` (it must have a healthcheck) |
| `service_completed_successfully` | has run to completion with exit code 0 |

Two more keys:

- **`restart: true`**: when Compose restarts or recreates the dependency (`docker compose restart db`, or `up`
  recreating `db` after a config change), it restarts this service afterwards too. For `migrate` that means "if the
  database was replaced, check the schema again".
- **`required: false`**: the dependency is optional. If it isn't part of the project (for example, it sits in a
  profile that isn't active), Compose starts the service anyway instead of failing.

The result is a real startup sequence:

```text
  db ──healthy──▶ migrate ──exit 0──▶ api
                                ╰───▶ worker
  valkey ──healthy─────────────────▶ api, worker
```

```bash
cp .env.example .env          # explained below
docker compose up -d --build
```

```text
[+] up 9/9
 ✔ Image snip:1.4            Built
 ✔ Volume snip_pgdata        Created
 ✔ Volume snip_valkeydata    Created
 ✔ Network snip_default      Created
 ✔ Container snip-valkey-1   Healthy
 ✔ Container snip-db-1       Healthy
 ✔ Container snip-migrate-1  Exited
 ✔ Container snip-worker-1   Started
 ✔ Container snip-api-1      Started
```

```bash
docker compose ps -a
```

```text
NAME             IMAGE                    COMMAND                  SERVICE   CREATED          STATUS                     PORTS
snip-api-1       snip:1.4                 "/nodejs/bin/node sr…"   api       11 seconds ago   Up 6 seconds (healthy)     127.0.0.1:3000->3000/tcp
snip-db-1        postgres:18-alpine       "docker-entrypoint.s…"   db        11 seconds ago   Up 10 seconds (healthy)    127.0.0.1:5432->5432/tcp
snip-migrate-1   snip:1.4                 "/nodejs/bin/node sr…"   migrate   11 seconds ago   Exited (0) 7 seconds ago
snip-valkey-1    valkey/valkey:9-alpine   "tini -- docker-entr…"   valkey    11 seconds ago   Up 10 seconds (healthy)    127.0.0.1:6379->6379/tcp
snip-worker-1    snip:1.4                 "/nodejs/bin/node sr…"   worker    11 seconds ago   Up 6 seconds               3000/tcp
```

(`-a` shows the exited `migrate` container too. The `5432` and `6379` ports come from an override file, explained
below.) No more retries in the API's log: by the time it starts, the database is healthy and migrated.

```bash
docker compose logs migrate worker api
```

```text
migrate-1  | applied 001-create-links.sql
migrate-1  | database is up to date (1 migrations)
worker-1   | worker started (pid 1)
api-1      | snip listening on port 3000 (node v24.22.0, pid 1)
```

Click a link five times and watch the counts arrive through the queue:

```bash
code=$(curl -s -X POST localhost:3000/api/links -H 'content-type: application/json' \
  -d '{"url":"https://valkey.io/"}' | node -pe 'JSON.parse(require("fs").readFileSync(0)).code')
for i in 1 2 3 4 5; do curl -s -o /dev/null -w '%{http_code} ' localhost:3000/$code; done; echo
sleep 3; curl -s localhost:3000/api/links/$code
docker compose exec valkey valkey-cli ttl url:$code
```

```text
302 302 302 302 302
{"code":"mRV73VQ","url":"https://valkey.io/","clicks":5,"createdAt":"2026-10-15T09:36:11.617Z"}
3596
```

The cached URL expires in just under an hour (`ttl` is in seconds).

### When a dependency fails

If a dependency never becomes healthy, or a job exits non-zero, `up` stops waiting and fails, leaving the dependent
services created but not started:

```text
 ✘ Container snip-db-1       Error dependency db failed to start
dependency failed to start: container snip-db-1 is unhealthy
```

```text
service "migrate" didn't complete successfully: exit 1
```

Both are better than an API that starts against a missing schema. Read the dependency's logs
(`docker compose logs migrate`) and, for healthchecks, the last check's output:

```bash
docker inspect snip-db-1 --format '{{json .State.Health.Log}}'
```

### Upgrading from chapter 10: a familiar surprise

If you still have chapter 10's `snip_pgdata` volume, the first `up` of this chapter fails exactly like that, and
`docker compose logs migrate` explains why:

```text
error: password authentication failed for user "snip"
```

The volume holds a database initialized with chapter 10's password (`snip`), and `POSTGRES_PASSWORD` only matters
when the image initializes an *empty* data directory. Changing the variable later changes nothing inside the
database. In development, start over with `docker compose down -v`; with data you want to keep, change the password
inside PostgreSQL (`ALTER USER snip PASSWORD '…'`) to match.

### `--wait`

`docker compose up -d --wait` returns only when every service is running and healthy (and jobs have completed), and
exits non-zero if something fails. It's the right command in scripts and CI, before running tests against the stack:

```bash
docker compose up -d --wait && npm run test:integration
```

### A restart is a good test

`restart: true` and the restart policy are easy to verify, and worth it. Restart the database under a running stack:

```bash
docker compose restart db
docker compose logs --since 30s api worker migrate
```

```text
migrate-1  | database is up to date (1 migrations)
api-1      | database: terminating connection due to administrator command
worker-1   | database: terminating connection due to administrator command
```

`migrate` ran again because of `restart: true`. The API and the worker logged that PostgreSQL closed their idle
connections and kept running; the next query opens a new connection. That's the `pool.on('error', …)` line from
chapter 10 at work: without it, the API would crash on this restart and only come back thanks to
`restart: unless-stopped`.

## Variables: interpolation and `.env`

Hard-coding the password and the port in `compose.yaml` makes the file useless for a second machine. Compose
**interpolates** `${…}` variables in the file before doing anything else:

```yaml
x-snip: &snip
  environment: &snip-env
    DATABASE_URL: postgres://snip:${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD in .env}@db:5432/snip

services:
  api:
    ports:
      - "127.0.0.1:${SNIP_PORT:-3000}:3000"
```

The syntax follows the shell's:

| Syntax | Result |
|---|---|
| `${VAR}` or `$VAR` | The value of `VAR`, or an empty string (with a warning) if unset |
| `${VAR:-default}` | `default` if `VAR` is unset **or empty** |
| `${VAR-default}` | `default` only if `VAR` is unset |
| `${VAR:?message}` | Error with `message` if `VAR` is unset or empty |
| `${VAR?message}` | Error if `VAR` is unset |
| `${VAR:+other}` | `other` if `VAR` is set and not empty, else empty |
| `$$` | A literal `$` (for a `$` that the *container* should see, e.g. in a shell command) |

`:?` is the one to use for anything without a sensible default, such as a password: the file refuses to load rather
than starting a database with an empty password.

```text
error while interpolating services.api.environment.DATABASE_URL: required variable POSTGRES_PASSWORD is missing a value: set POSTGRES_PASSWORD in .env
```

### Where the values come from

Compose reads variables for interpolation from, in order of precedence:

1. the environment of the shell that runs `docker compose`;
2. the `.env` file in the project directory (or the files named with `--env-file`).

So `.env` holds the defaults for a machine, and the shell overrides them for one command:

```bash
SNIP_PORT=3001 docker compose config | grep published
```

```text
        published: "3001"
        published: "5432"
        published: "6379"
```

Snip's `.env.example` is committed to Git; each developer copies it to `.env`, which is ignored by Git:

```bash
# Copy to .env and adjust: cp .env.example .env. Compose reads .env for ${...} interpolation.
# .env holds real values and stays out of Git (and out of the image: see .dockerignore).
POSTGRES_PASSWORD=snip-dev-password
SNIP_PORT=3000
```

### `.env`, `env_file:` and `environment:` are three different things

This is the most common Compose confusion, so precisely:

| | What it is | Who sees the values |
|---|---|---|
| `.env` (project directory) | Variables for **interpolating the Compose file** | Compose itself. Containers only get a value if the file passes it on, like `${POSTGRES_PASSWORD}` above |
| `env_file: [api.env]` (service key) | A file of `KEY=value` lines to set **in the container** | The container's processes |
| `environment:` (service key) | Variables to set **in the container** | The container's processes; wins over `env_file` |

A variable in `.env` does not appear in any container unless the Compose file references it. And `${…}` inside an
`env_file` is not interpolated from `.env`; these files are read independently. When in doubt, `docker compose
config` shows the final values.

`env_file` entries are required by default; the long form makes one optional (Compose 2.24+):

```yaml
    env_file:
      - path: ./api.local.env
        required: false
```

## Profiles

Some services are only needed sometimes: an admin tool, a mail catcher for development, a load generator. Give them
a **profile**, and Compose ignores them unless the profile is active:

```yaml
  psql:
    image: postgres:18-alpine
    profiles: [tools]
    entrypoint: ["psql"]
    environment:
      PGHOST: db
      PGUSER: snip
      PGDATABASE: snip
      PGPASSWORD: ${POSTGRES_PASSWORD}
    depends_on:
      db:
        condition: service_healthy
```

```bash
docker compose config --services                    # psql is not listed
docker compose --profile tools config --services    # now it is
docker compose run --rm psql -c 'SELECT name FROM schema_migrations'
```

```text
         name
----------------------
 001-create-links.sql
(1 row)
```

Naming a profiled service explicitly (`run psql`, `up psql`) activates it, so the `run` above works without
`--profile`. `COMPOSE_PROFILES=tools,debug` in the environment or in `.env` activates profiles for every command.
Services without `profiles:` are always active.

## Several Compose files

### The override file

When you run `docker compose` without `-f`, it reads `compose.yaml` **and then `compose.override.yaml`** if that
exists, merging the second into the first. Snip uses it for development-only settings:

```yaml
# Development additions, merged automatically by `docker compose` when this file exists.
# Production runs with an explicit -f compose.yaml -f compose.prod.yaml instead.
services:
  db:
    ports:
      - "127.0.0.1:5432:5432"   # for a database GUI on your machine
  valkey:
    ports:
      - "127.0.0.1:6379:6379"
```

With explicit `-f` flags, Compose reads exactly those files, in order, and the override file is *not* added
automatically:

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

```yaml
# Production differences: docker compose -f compose.yaml -f compose.prod.yaml up -d
services:
  api:
    environment:
      BASE_URL: https://snip.example.com
      NODE_OPTIONS: --max-old-space-size=192
  worker:
    deploy:
      replicas: 2
```

`COMPOSE_FILE=compose.yaml:compose.prod.yaml` in the server's environment (or its `.env`) saves typing the flags.

### Merge rules

Later files win, but "win" depends on the kind of value:

| Kind of key | Merge behavior | Examples |
|---|---|---|
| Single values | Replaced | `image`, `restart`, `user` |
| Commands | Replaced as a whole, even though they are lists | `command`, `entrypoint`, `healthcheck.test` |
| Maps | Merged key by key | `environment`, `labels`, `healthcheck`, `deploy` |
| Mounts and ports | Appended; an entry with the same key replaces the earlier one | `volumes` (key: target path), `ports` (key: IP, target, published port, protocol) |
| Other lists | Appended | `dns`, `cap_add`, `expose` |

So the production file adds `BASE_URL` and `NODE_OPTIONS` to the API's environment while keeping `DATABASE_URL` and
`VALKEY_URL`, and the override file *adds* ports to `db`. To *remove* something in a later file, use the `!reset` YAML
tag (Compose 2.24+); to replace a whole list or map instead of merging, use `!override` (Compose 2.24.4+):

```yaml
services:
  db:
    ports: !reset []              # no published ports, whatever earlier files said
  api:
    environment: !override        # exactly these variables, nothing merged
      NODE_ENV: production
```

Always check the merged result with `docker compose -f … -f … config`.

### `include` and `extends`

`include` pulls in another Compose file as a whole, with its own relative paths resolved against its own directory.
It's the way to split a large system, or to share a stack of supporting services between projects:

```yaml
include:
  - tools/compose.yaml          # its services join this project
services:
  app:
    ...
```

`extends` makes one service start from another service's definition, in the same file or another one, and then add
or change keys:

```yaml
services:
  app:
    extends:
      file: base.yaml
      service: node-base        # image, init, environment… copied from there
    environment:
      GREETING: hi              # merged with node-base's environment
```

Both work, and both are less common than plain override files and YAML anchors. Reach for `include` when a file grows
past a few hundred lines or another team owns part of it.

## Removing repetition: extension fields and anchors

Snip's API, worker and migration job share the image, the build, the restart policy and two environment variables.
Compose ignores any top-level key that starts with `x-` (an **extension field**), and YAML **anchors** (`&name`) and
**aliases** (`*name`) let you define something once and reuse it. Together, with the YAML merge key `<<`:

```yaml
x-snip: &snip
  build: .
  image: snip:1.4
  restart: unless-stopped
  environment: &snip-env
    DATABASE_URL: postgres://snip:${POSTGRES_PASSWORD:?set POSTGRES_PASSWORD in .env}@db:5432/snip
    VALKEY_URL: redis://valkey:6379

services:
  api:
    <<: *snip                   # everything from x-snip…
    ports:
      - "127.0.0.1:${SNIP_PORT:-3000}:3000"
    environment:
      <<: *snip-env             # …and the shared variables, plus one more
      BASE_URL: ${BASE_URL:-http://localhost:3000}

  worker:
    <<: *snip
    command: ["src/worker.js"]
    healthcheck:
      disable: true

  migrate:
    <<: *snip
    command: ["src/migrate.js"]
    restart: "no"               # a job: run once, exit
    healthcheck:
      disable: true
```

Two details:

- `<<:` merges only one level deep. `api` defines its own `environment:`, which would *replace* the anchored one, so
  it merges `*snip-env` into it explicitly.
- All three services have `build: .` and `image: snip:1.4`. Compose notices that they produce the same image and
  builds it once. If only `api` had `build:`, Compose would first try to *pull* `snip:1.4` from Docker Hub for the
  worker, print a `not found` error, and then build it anyway. That's noisy at best; at worst, someone has published
  an image with that name and you run theirs.

`restart: "no"` is quoted because a bare `no` is a boolean in YAML 1.1. Anchors are resolved by the YAML parser before
Compose sees the file, so `docker compose config` shows each service fully expanded.

## Scaling a service

```bash
docker compose up -d --scale worker=3
docker compose ps --format '{{.Name}}'
```

```text
snip-api-1
snip-db-1
snip-valkey-1
snip-worker-1
snip-worker-2
snip-worker-3
```

The `-1` suffix finally makes sense: a service can have several identical containers (`deploy.replicas: 3` sets the
default in the file). The workers share the click list safely because `BLMPOP` is atomic. The service name `worker`
now resolves to three addresses on the project network (round-robin DNS, chapter 9).

Try the same with the API:

```bash
docker compose up -d --scale api=2
```

```text
Error response from daemon: failed to set up container networking: driver failed programming external connectivity on endpoint snip-api-2 (1785…): Bind for 127.0.0.1:3000 failed: port is already allocated
```

Two containers can't both publish host port 3000. A scalable web service publishes no host port at all and sits
behind a reverse proxy that spreads requests across the replicas; chapter 18 adds one. (A port *range*,
`"3000-3005:3000"`, also works, but then every replica has a different address.) The other things that block scaling
are the same as in any distributed system: `container_name:`, state in the container's filesystem, and in-memory state
like chapter 4's map.

## Key takeaways

- Give every stateful service a **healthcheck** and wait for it with `condition: service_healthy`. Make checks cheap,
  local and runnable with the image's own tools; disable or override a healthcheck that a reused image brings along.
- Run schema migrations as a **job** that the app depends on with `condition: service_completed_successfully`, using
  migrations shipped inside the image.
- `up --wait` blocks until the stack is healthy; use it in scripts and CI.
- Interpolate `${VAR}` from the shell and `.env`; use `:?` for required values and `:-` for defaults. Commit a
  `.env.example`, not `.env`.
- `.env` feeds the Compose file; `env_file:` and `environment:` feed the container.
- Profiles hold optional services. Override files and `-f` layer environment-specific changes; check the merge with
  `config`.
- `x-` extension fields with YAML anchors remove repetition.
- Only services without a fixed host port and without local state can be scaled with `--scale`.
- Restart dependencies on purpose (`docker compose restart db`) to prove the app survives it.

## Exercises

1. **A second migration.** Add `migrations/002-index-created-at.sql` that creates an index on `links (created_at)`.
   Apply it to the running stack and show that it ran exactly once.

<details>
<summary>Solution</summary>

```bash
echo 'CREATE INDEX links_created_at_idx ON links (created_at);' > migrations/002-index-created-at.sql
docker compose up -d --build
docker compose logs --no-log-prefix migrate | tail -2
docker compose run --rm psql -c 'SELECT name FROM schema_migrations'
```

```text
applied 002-index-created-at.sql
database is up to date (2 migrations)
```

The migrations live in the image, so `--build` is needed. The new image ID changes the configuration of `api`,
`worker` and `migrate`, so all three are recreated, and the API waits for the new `migrate` to finish. `db` and
`valkey` keep running. Running `up` again without changes applies nothing: `schema_migrations` already lists both
files.

</details>

2. **Watch a dependency fail.** Break the database healthcheck in a scratch override file (point `pg_isready` at port
   5433, with `interval: 2s`, `retries: 3`, `start_period: 0s`) and run `docker compose -f compose.yaml -f broken.yaml
   up -d`. What does `up` report, which containers exist, and how do you find out why?

<details>
<summary>Solution</summary>

```yaml
# broken.yaml
services:
  db:
    healthcheck:
      test: ["CMD", "pg_isready", "-h", "127.0.0.1", "-p", "5433"]
      interval: 2s
      retries: 3
      start_period: 0s
```

```text
dependency failed to start: container snip-db-1 is unhealthy
```

`docker compose ps -a` shows `db` running but `(unhealthy)`, `valkey` healthy, and `migrate`, `worker` and `api`
in state `Created`: created, never started. The last check's output says what failed:

```bash
docker inspect snip-db-1 --format '{{json .State.Health.Log}}'
```

```text
… "Output":"127.0.0.1:5433 - no response\n" …
```

The `healthcheck` map from `broken.yaml` was merged into the original one, replacing `test`, `interval`, `retries`
and `start_period` and keeping `timeout` and `start_interval`. Clean up with `docker compose down`.

</details>

3. **Watch the queue.** Stop the worker, click a link five times, and show the queue's length with `valkey-cli`. Start
   the worker again and show what happens. Why is it fine for the API to keep working while the worker is down?

<details>
<summary>Solution</summary>

```bash
docker compose stop worker
for i in 1 2 3 4 5; do curl -s -o /dev/null localhost:3000/$code; done
docker compose exec valkey valkey-cli llen clicks          # 5
docker compose start worker
sleep 2
docker compose exec valkey valkey-cli llen clicks          # 0
docker compose logs --no-log-prefix worker | tail -1       # recorded 5 clicks for 1 links
```

The queue decouples the two: redirects only need the cache (or the database) and a `LPUSH`, so they keep working,
and the counts catch up when the worker returns. Because Valkey runs with `--appendonly yes` on a volume, queued
clicks also survive a Valkey restart. This is the main reason to put work behind a queue: a slow or broken consumer
doesn't slow down or break the request path.

</details>

4. **A second copy for testing.** Start a second, independent copy of the stack as project `snip-test`, with the
   API on port 3001, while the first copy keeps running. The obvious command fails; why, and what's the fix?

<details>
<summary>Solution</summary>

```bash
SNIP_PORT=3001 docker compose -p snip-test up -d
```

```text
Error response from daemon: … Bind for 127.0.0.1:6379 failed: port is already allocated
```

`SNIP_PORT` moved the API, but `compose.override.yaml` is merged automatically and publishes PostgreSQL and Valkey on
fixed ports that the first copy already uses. Name the files explicitly, so the override isn't read:

```bash
docker compose -p snip-test down
SNIP_PORT=3001 docker compose -p snip-test -f compose.yaml up -d --wait
curl -s localhost:3001/healthz                      # {"status":"ok"}
docker compose -p snip-test down -v
```

This is a common CI pattern: a uniquely named project per test run, only the base file, and `down -v` at the end.

</details>

5. **Why not `container_name`?** A teammate adds `container_name: snip-worker` to the worker "to make logs easier to
   find". Which features from this chapter and the previous one break? What would you suggest instead?

<details>
<summary>Solution</summary>

- `--scale worker=3` and `deploy.replicas`: three containers can't share one name, so Compose refuses to scale the
  service.
- A second copy of the project (`-p snip-test`): both projects would create a container named `snip-worker`, and the
  second `up` fails with a name conflict.

What they want is already there: `docker compose logs worker` addresses the service by name in any project, and
`docker logs snip-worker-1` works for the generated name. For tools outside Compose, filter by label:
`docker ps --filter label=com.docker.compose.service=worker`.

</details>

---
← Previous: [Docker Compose basics](10-docker-compose-basics.md) | Next: [Developing with containers](12-developing-with-containers.md) →
