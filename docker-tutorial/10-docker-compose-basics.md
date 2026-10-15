# Chapter 10 — Docker Compose basics

## What you'll learn

- What problem Compose solves, and how it relates to the `docker` commands you already know
- The Compose file: `compose.yaml`, services, volumes and networks, and the Compose Specification
- The **project** model: how Compose names, labels and finds everything it creates
- Building images with Compose, and the difference between `image` and `build`
- Ports, environment variables, volumes, networks, restart policies and `depends_on` in a Compose file
- The everyday commands: `up`, `down`, `ps`, `logs`, `exec`, `run`, `stop`, `start`, `build`, `config`
- How `up` decides what to recreate, and why `down -v` is dangerous
- Snip with a real database: a PostgreSQL store, run with one command

## Why Compose

At the end of chapter 9, running Snip with a database took this:

```bash
docker network create snipnet
docker volume create pgdata
docker run -d --name db --network snipnet --restart unless-stopped \
  -e POSTGRES_USER=snip -e POSTGRES_PASSWORD=snip -e POSTGRES_DB=snip \
  -v pgdata:/var/lib/postgresql postgres:18-alpine
docker build -t snip:1.3 .
docker run -d --name api --network snipnet --restart unless-stopped -p 127.0.0.1:3000:3000 \
  -e DATABASE_URL=postgres://snip:snip@db:5432/snip -e BASE_URL=http://localhost:3000 snip:1.3
```

Six commands, a dozen flags, an order that matters, and nothing that records any of it. A teammate who wants the same
setup copies it from a wiki page that is already out of date. Changing one environment variable means stopping,
removing and re-running the right container with the right flags. Tearing it down means remembering every name.

**Docker Compose** replaces all that with a file that *describes* the setup, and a command that makes reality match
the file:

```yaml
services:
  api:
    build: .
    ports:
      - "127.0.0.1:3000:3000"
    environment:
      DATABASE_URL: postgres://snip:snip@db:5432/snip
  db:
    image: postgres:18-alpine
    volumes:
      - pgdata:/var/lib/postgresql
volumes:
  pgdata:
```

```bash
docker compose up -d      # create or update everything
docker compose down       # remove it again
```

Compose doesn't add new container features. Everything it does, it does through the same Docker Engine API that
`docker run`, `docker network create` and `docker build` use. It's a **declarative front end**: you write down the
desired state, and Compose works out which API calls get there. That's why everything you learned in chapters 2–9
applies unchanged; a Compose file is mostly those same flags, written as YAML.

Compose is the standard tool for:

- **Local development**: the app plus its database, cache and queue, started by anyone with `docker compose up`.
- **Tests in CI**: a throwaway copy of the whole system for integration tests.
- **Single-server production**: plenty of real services run on one machine with Compose (chapter 18).

It does *not* manage several machines. That's what orchestrators are for (chapter 21).

### Compose v1, v2 and v5

You will meet three generations in blog posts:

| Generation | Command | Status |
|---|---|---|
| Compose v1 | `docker-compose` (with a hyphen), written in Python | Dead since 2023. Don't install it. |
| Compose v2 | `docker compose` (a Docker CLI plugin), written in Go | The rewrite; all current behavior comes from it |
| Compose v5 | `docker compose` | The current line (this tutorial uses 5.6). Version 5 skipped 3 and 4 to avoid confusion with the old file format versions |

```bash
docker compose version
```

```text
Docker Compose version v5.6.0
```

Everything in this tutorial uses `docker compose` with a space. If a guide uses `docker-compose`, the commands mostly
translate one to one, but be suspicious of the rest of that guide: it is probably old.

## The Compose file

Compose looks in the current directory (and then its parents) for a file named, in order of preference,
`compose.yaml`, `compose.yml`, `docker-compose.yaml` or `docker-compose.yml`. Use **`compose.yaml`**, the
canonical name. `-f other.yaml` picks a different file.

The format is the **Compose Specification**, an open, versionless specification
(<https://compose-spec.io>, documented at <https://docs.docker.com/reference/compose-file/>). Old files start with
`version: "3.8"` or similar. That key is obsolete; Compose ignores it and warns:

```text
level=warning msg="/home/you/snip/compose.yaml: the attribute `version` is obsolete, it will be ignored, please remove it to avoid potential confusion"
```

Delete it from any file you inherit.

A Compose file has a few top-level keys:

```yaml
name: snip          # the project name (optional; see "The project model")

services:           # the containers to run: the only required section
  api: { ... }
  db: { ... }

volumes:            # named volumes the services use
  pgdata:

networks:           # networks, if the default one isn't enough
  backend:

configs: { ... }    # configuration files  (chapter 13)
secrets: { ... }    # secrets              (chapter 13)
```

A **service** is a container definition plus a name. Each service runs one or more containers (one by default) from
the same image with the same settings. Services are what you address in commands (`docker compose logs api`), and
the service name is also the container's hostname on the project's network. That is how `api` finds `db`.

## Snip with PostgreSQL

Time to give Snip a real database. The app already takes its store as a parameter (chapter 4), so the change is a new
store module and three lines in `server.js`. Add the PostgreSQL client library first:

```bash
npm install pg
```

`src/pg-store.js` implements the same three methods as the memory store, using a connection pool:

```js
import pg from 'pg';

// Stores links in PostgreSQL. DATABASE_URL looks like
// postgres://user:password@host:5432/dbname; inside Compose the host is the
// service name of the database ("db").
export async function createPgStore(connectionString) {
  const pool = new pg.Pool({ connectionString, max: 10 });
  await waitForDatabase(pool);
  // Chapter 11 moves schema changes into a separate migration job.
  await pool.query(`
    CREATE TABLE IF NOT EXISTS links (
      code       text PRIMARY KEY,
      url        text NOT NULL,
      clicks     bigint NOT NULL DEFAULT 0,
      created_at timestamptz NOT NULL DEFAULT now()
    )`);

  return {
    async create({ code, url }) {
      const { rows } = await pool.query(
        'INSERT INTO links (code, url) VALUES ($1, $2) RETURNING *', [code, url]);
      return toLink(rows[0]);
    },
    async get(code) {
      const { rows } = await pool.query('SELECT * FROM links WHERE code = $1', [code]);
      return rows[0] ? toLink(rows[0]) : null;
    },
    async recordClick(code) {
      await pool.query('UPDATE links SET clicks = clicks + 1 WHERE code = $1', [code]);
    },
    close: () => pool.end(),
  };
}

// The database container may still be starting when the app starts.
// Retry for a while instead of crashing on the first ECONNREFUSED.
async function waitForDatabase(pool, attempts = 30) {
  for (let i = 1; ; i++) {
    try {
      await pool.query('SELECT 1');
      return;
    } catch (err) {
      if (i >= attempts) throw err;
      console.log(`database not ready (${err.code ?? err.message}), retry ${i}/${attempts}`);
      await new Promise((resolve) => setTimeout(resolve, 1000));
    }
  }
}

function toLink(row) {
  return {
    code: row.code,
    url: row.url,
    clicks: Number(row.clicks),
    createdAt: row.created_at.toISOString(),
  };
}
```

`server.js` picks the store from the environment and closes the pool on shutdown:

```js
import { createApp } from './app.js';
import { createMemoryStore } from './memory-store.js';
import { createPgStore } from './pg-store.js';

const port = Number(process.env.PORT ?? 3000);
const baseUrl = process.env.BASE_URL ?? `http://localhost:${port}`;

// With DATABASE_URL set, links live in PostgreSQL; without it, in memory.
const store = process.env.DATABASE_URL
  ? await createPgStore(process.env.DATABASE_URL)
  : createMemoryStore();

const app = createApp({ store, baseUrl });
const server = app.listen(port, () => {
  console.log(`snip listening on port ${port} (node ${process.version}, pid ${process.pid})`);
});

function shutdown(signal) {
  console.log(`${signal} received, closing the server`);
  server.close(async () => {
    await store.close?.();
    console.log('all connections closed, exiting');
    process.exit(0);
  });
  server.closeIdleConnections();
  setTimeout(() => {
    console.error('could not close connections in time, forcing exit');
    process.exit(1);
  }, 8000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
```

Two details matter for containers:

- **The retry loop.** Containers start in parallel and PostgreSQL needs a second or two to initialize. Without the
  retry, the first `SELECT 1` fails with `ECONNREFUSED` and the app crashes. Chapter 11 adds a proper healthcheck so
  Compose waits for the database, but an app that tolerates a briefly missing dependency is a good idea anyway:
  databases restart, fail over and get upgraded while the app keeps running.
- **Closing the pool on SIGTERM.** `pool.end()` closes the database connections cleanly instead of leaving PostgreSQL
  to notice dead sockets.

The Dockerfile is the multi-stage, distroless one from chapter 7, unchanged; `pg` is pure JavaScript, so the
dependencies stage needs no compiler. Next to it goes `compose.yaml`:

```yaml
# Snip, chapter 10: the API and a PostgreSQL database, started together.
name: snip

services:
  api:
    build: .
    image: snip:1.3
    ports:
      - "127.0.0.1:3000:3000"
    environment:
      DATABASE_URL: postgres://snip:snip@db:5432/snip
      BASE_URL: http://localhost:3000
    depends_on:
      - db
    restart: unless-stopped

  db:
    image: postgres:18-alpine
    environment:
      POSTGRES_USER: snip
      POSTGRES_PASSWORD: snip   # a real password comes from a secret in chapter 13
      POSTGRES_DB: snip
    volumes:
      - pgdata:/var/lib/postgresql
    restart: unless-stopped

volumes:
  pgdata:
```

Read it as the `docker run` commands from the start of the chapter: every key maps to a flag you know. The complete
project is in [`examples/ch10-compose-postgres/`](examples/ch10-compose-postgres/).

> The `postgres:18` images keep their data under `/var/lib/postgresql` (in a versioned subdirectory), so that's the
> path to mount. Guides written for PostgreSQL 17 and older mount `/var/lib/postgresql/data`; with an 18 image, that
> mount makes the container refuse to start. Chapter 8 covers why.

### Up

```bash
docker compose up -d --build
```

Compose builds the image (with BuildKit, exactly as `docker build` would), then creates the resources in dependency
order. In a terminal it draws a live progress display that ends like this:

```text
[+] up 4/4
 ✔ Network snip_default  Created
 ✔ Volume snip_pgdata    Created
 ✔ Container snip-db-1   Started
 ✔ Container snip-api-1  Started
```

(Without a terminal, for example in CI, it prints one plain line per step instead.) `-d` means detached, like
`docker run -d`; without it, Compose stays in the foreground and streams the logs of every service, and Ctrl+C stops
them all.

```bash
docker compose ps
```

```text
NAME         IMAGE                COMMAND                  SERVICE   CREATED          STATUS                   PORTS
snip-api-1   snip:1.3             "/nodejs/bin/node sr…"   api       10 seconds ago   Up 9 seconds (healthy)   127.0.0.1:3000->3000/tcp
snip-db-1    postgres:18-alpine   "docker-entrypoint.s…"   db        10 seconds ago   Up 9 seconds             5432/tcp
```

The `(healthy)` comes from the `HEALTHCHECK` in Snip's image (chapter 7). `db` has no health status yet; chapter 11
adds one. Now the logs:

```bash
docker compose logs api
```

```text
api-1  | database not ready (ECONNREFUSED), retry 1/30
api-1  | database not ready (ECONNREFUSED), retry 2/30
api-1  | snip listening on port 3000 (node v24.22.0, pid 1)
```

There's the race: `depends_on` made Compose *start* `db` before `api`, but "started" means the process exists, not that
PostgreSQL accepts connections. The retry loop absorbed it. Use the app:

```bash
curl -s -X POST localhost:3000/api/links -H 'content-type: application/json' \
  -d '{"url":"https://docs.docker.com/compose/"}'
```

```text
{"code":"gTf6sSU","url":"https://docs.docker.com/compose/","clicks":0,"createdAt":"2026-10-15T09:28:19.547Z","shortUrl":"http://localhost:3000/gTf6sSU"}
```

And look inside the database, using the `psql` that ships in the `postgres` image:

```bash
docker compose exec db psql -U snip -c 'SELECT code, url, clicks FROM links'
```

```text
  code   |               url                | clicks
---------+----------------------------------+--------
 gTf6sSU | https://docs.docker.com/compose/ |      0
(1 row)
```

### Down, and up again

```bash
docker compose down
```

```text
[+] down 3/3
 ✔ Container snip-api-1  Removed
 ✔ Container snip-db-1   Removed
 ✔ Network snip_default  Removed
```

`down` stops and removes the containers and the network. It does **not** remove named volumes, so `docker compose up
-d` brings back a fresh pair of containers attached to the same `snip_pgdata` volume, and the link is still there.
Containers are disposable; the volume is the state.

`docker compose down -v` also removes the project's named volumes. That's the right command for "reset my dev
database", and a disaster when typed on the wrong machine. Treat `-v` the way you treat `rm -rf`.

## The project model

Everything Compose creates belongs to a **project**. The project name comes from, in order of precedence:

1. the `-p` / `--project-name` flag,
2. the `COMPOSE_PROJECT_NAME` environment variable,
3. the top-level `name:` in the Compose file,
4. the name of the directory containing the Compose file.

Set `name:` in the file. Relying on the directory name means that a clone into `snip-main/` and one into `snip/` are
two different projects, with two different volumes, and the second one has an empty database.

The project name prefixes every resource:

| Resource | Name pattern | Example |
|---|---|---|
| Container | `<project>-<service>-<number>` | `snip-api-1` |
| Default network | `<project>_default` | `snip_default` |
| Named volume | `<project>_<volume>` | `snip_pgdata` |
| Built image (no `image:` key) | `<project>-<service>` | `snip-api` |

The prefixes are why two projects can both have a service called `db` and a volume called `pgdata` without
colliding.

How does `docker compose down` know what to remove, when the containers were created by an earlier command? Compose
keeps no state file. It **labels** everything it creates and finds things again by label:

```bash
docker inspect snip-api-1 --format '{{json .Config.Labels}}'
```

```text
"com.docker.compose.config-hash":"20f04e6d0c2e…"
"com.docker.compose.container-number":"1"
"com.docker.compose.depends_on":"db:service_started:false"
"com.docker.compose.oneoff":"False"
"com.docker.compose.project":"snip"
"com.docker.compose.project.config_files":"/home/you/snip/compose.yaml"
"com.docker.compose.project.working_dir":"/home/you/snip"
"com.docker.compose.service":"api"
"com.docker.compose.version":"5.6.0"
```

(Output trimmed and split one label per line.) Every Compose command asks the Engine for containers labelled
`com.docker.compose.project=snip`. That has practical consequences:

- **You can manage a project from anywhere** with `-p`: `docker compose -p snip ps` or `docker compose -p snip down`
  work without the file, because the labels are enough to find the containers.
- **Plain `docker` commands see Compose containers** like any others: `docker ps`, `docker logs snip-api-1` and
  `docker ps --filter label=com.docker.compose.project=snip` all work.
- **`docker compose ls`** lists the projects that have containers:

```bash
docker compose ls
```

```text
NAME                STATUS              CONFIG FILES
snip                running(2)          /home/you/snip/compose.yaml
```

### How `up` decides what to change

`config-hash` is a hash of the service's resolved configuration. When you run `up` again, Compose compares the hash in
the file with the hash on each running container:

- **Same hash, container running**: leave it alone (`Running`).
- **Different hash** (you changed the image, a port, an environment variable, a volume…): stop and remove the old
  container and create a new one (`Recreate`).
- **Missing container**: create it.

Change `BASE_URL` in `compose.yaml` and run `docker compose up -d` again: only `snip-api-1` is recreated, and `db`
keeps running untouched. That makes `docker compose up -d` the one command for both "start" and "apply my changes".
`--force-recreate` recreates everything regardless, and `--no-recreate` never recreates.

The hash covers the *configuration*, not your source code. Edit `src/app.js` and run `docker compose up -d`, and
nothing happens: the service still points at the old `snip:1.3` image. Rebuild with **`docker compose up -d --build`**
(or `docker compose build` and then `up -d`); the new image has a new ID, the config differs, and the container is
recreated. Chapter 12 removes this rebuild step from the development loop entirely.

```text
   compose.yaml ──▶ resolved configuration ──▶ desired state
                                                   │  compare (labels, config-hash)
   Docker Engine ──▶ containers, networks, volumes ─┘
                                                   ▼
                              create / recreate / leave alone / remove orphans
```

If you rename or delete a service, its old container becomes an **orphan**: it still carries the project label but
matches no service. `up` warns about it, and `docker compose up -d --remove-orphans` (or `down --remove-orphans`)
removes it.

## Services in detail

### `image` and `build`

A service needs an image. Either name one to pull:

```yaml
services:
  db:
    image: postgres:18-alpine
```

or describe how to build it:

```yaml
services:
  api:
    build: .                       # short form: the build context
```

```yaml
services:
  api:
    build:                         # long form
      context: .
      dockerfile: Dockerfile       # relative to the context
      target: runtime              # a stage of a multi-stage Dockerfile (chapter 7)
      args:                        # --build-arg
        APP_VERSION: 1.3.0
```

With both `build` and `image`, Compose builds the image and tags it with the `image` name, so the result is a normal
image you can see in `docker image ls` and push later. Without `image`, the image is named `<project>-<service>`
(`snip-api`). Builds run through Buildx and BuildKit, so the cache, cache mounts and build checks from chapter 6 work
exactly as before.

Compose builds a service's image only when the image doesn't exist yet, or when you ask: `up --build`,
`docker compose build`, or `build --no-cache` for a clean rebuild. For pulled images, `up` pulls when the image is
missing; `docker compose pull` refreshes all of them.

### Ports

```yaml
    ports:
      - "3000:3000"              # all host interfaces, port 3000 -> container 3000
      - "127.0.0.1:3000:3000"    # loopback only: reachable from this machine, not the network
      - "3000"                   # a random host port
```

Exactly the `-p` syntax from chapter 9, including the warning: `"3000:3000"` publishes to every interface of the
machine, and published ports bypass host firewalls like `ufw`. For development, bind to `127.0.0.1`.

**Quote port mappings.** YAML 1.1 parsers read some unquoted `xx:yy` values as base-60 numbers (`22:22` becomes 1342),
and the quotes cost nothing. There's also a long form, which is clearer for anything unusual:

```yaml
    ports:
      - target: 3000
        published: "3000"
        host_ip: 127.0.0.1
        protocol: tcp
```

`docker compose config` (below) prints every port in this long form.

A service that only other containers need, like `db`, publishes **no** ports at all. `api` reaches it over the project
network; nothing on your machine or your LAN can. Publish a database port only when you need a GUI client on the
host, and then on `127.0.0.1`.

`expose:` lists ports as documentation (like `EXPOSE` in a Dockerfile). It doesn't publish anything, and containers on
the same network can reach every port anyway.

### Environment

Two equivalent syntaxes:

```yaml
    environment:                   # a map
      NODE_ENV: production
      LOG_LEVEL: info
```

```yaml
    environment:                   # a list
      - NODE_ENV=production
      - LOG_LEVEL=info
```

Prefer the map, and quote values YAML might misread: `"true"`, `"yes"`, `"no"`, `"on"`, `"0123"`. A few more forms:

```yaml
    environment:
      DEBUG:                       # no value: copied from the shell that runs `docker compose`
    env_file:
      - ./api.env                  # KEY=value lines, loaded into the container
```

Everything in `environment` is visible to `docker inspect` and to anyone who can read the Compose file, so it's the
place for configuration, not passwords. Chapter 11 adds variable *interpolation* (`${PORT}` in the file itself, from a
`.env` file), and chapter 13 does secrets properly.

### Volumes

The short syntax is `-v` from chapter 8:

```yaml
services:
  db:
    volumes:
      - pgdata:/var/lib/postgresql         # a named volume, declared below
      - ./initdb:/docker-entrypoint-initdb.d:ro   # a bind mount (path starts with . or /)
volumes:
  pgdata:
```

Named volumes must be declared under the top-level `volumes:`, even when empty, which is also where options go
(`driver`, `driver_opts`, `name`, `external: true` for a volume created outside Compose). Relative bind-mount paths
are resolved against the directory of the Compose file, not your current directory, so the file works from anywhere.

The long syntax maps to `--mount`, and is how you get the options from chapter 8:

```yaml
    volumes:
      - type: bind
        source: ./src
        target: /app/src
        read_only: true
      - type: tmpfs
        target: /tmp
        tmpfs:
          size: 16m
```

### Networks

Without a `networks:` section, Compose creates **one network per project**, `<project>_default`, and connects every
service to it. On it, each service is reachable by its **service name** (`db`), and each container also by its own
name (`snip-db-1`). It's a user-defined bridge network, so everything from chapter 9 applies: embedded DNS, isolation
from other projects, no access from the default bridge.

For most projects the default network is all you need. To segment a larger system, declare networks and attach
services to them:

```yaml
services:
  proxy:
    image: nginx:alpine
    networks: [frontend]
  api:
    build: .
    networks: [frontend, backend]
  db:
    image: postgres:18-alpine
    networks: [backend]

networks:
  frontend:
  backend:
    internal: true        # no route to the outside world
```

`proxy` can reach `api` but can't even resolve `db`; `db` has no internet access. This is exercise 2 of chapter 9,
in eight lines. Once a service lists `networks:`, it's attached only to those, not to `default`.

### `depends_on`

```yaml
    depends_on:
      - db
```

`depends_on` controls **order**: `up` creates and starts `db` before `api`, `down` and `stop` stop `api` before `db`,
and `docker compose up api` (or `run api`) starts `db` too, because `api` needs it.

It doesn't wait for `db` to be *ready*. You saw that in the logs above. Chapter 11 introduces the long form,
`condition: service_healthy`, which does wait, based on a healthcheck.

### `restart`, `command`, `entrypoint` and other `docker run` flags

```yaml
    restart: unless-stopped            # --restart (chapter 2): no | always | on-failure[:N] | unless-stopped
    command: ["src/server.js", "--trace-warnings"]   # replaces the image's CMD
    entrypoint: ["/nodejs/bin/node"]   # replaces the image's ENTRYPOINT
    working_dir: /app                  # -w
    user: "65532:65532"                # -u
    init: true                         # --init
    stop_grace_period: 20s             # docker stop -t
    read_only: true                    # --read-only
    hostname: snip-api
```

Use the exec (list) form for `command` and `entrypoint`, for the same PID 1 reasons as in a Dockerfile (chapter 4).
Most `docker run` flags have a key with the same or an obvious name; the Compose file reference lists them all.
`container_name:` also exists, and you should almost never use it: a fixed name breaks running two copies of a
project and scaling a service to more than one container.

## The everyday commands

All commands run against the project in the current directory (or the one named with `-p` / `-f`). Most take service
names to narrow them down: `docker compose logs api`, `docker compose restart api`.

| Command | What it does |
|---|---|
| `up -d` | Create or update everything, start in the background |
| `up -d --build` | Same, rebuilding images first |
| `up -d api` | Only `api` (and what it `depends_on`) |
| `down` | Stop and remove containers and networks (volumes stay) |
| `down -v` | …and the project's named volumes |
| `ps` / `ps -a` | The project's containers (`-a` includes stopped ones) |
| `logs -f --tail 50 api` | Follow a service's logs |
| `exec api sh` | Run a command in a running container |
| `run --rm api …` | Run a one-off container from a service's definition |
| `stop` / `start` / `restart` | Stop or start existing containers without removing them |
| `build` / `pull` | Build or pull the images |
| `config` | Print the fully resolved configuration |
| `top`, `images`, `ls` | Processes, images, and projects |

### `logs`

```bash
docker compose logs              # every service, interleaved, prefixed with the service name
docker compose logs -f api db    # follow two services
docker compose logs --tail 20 --timestamps api
docker compose logs --no-log-prefix api   # raw lines, for piping into jq or grep
```

Logs belong to containers, so `down` deletes them along with the containers, while `stop` and `start` keep them.
Chapter 17 is about logging properly.

### `exec` and `run`

`exec` runs a command **in the existing container** of a service, like `docker exec`:

```bash
docker compose exec db psql -U snip
docker compose exec -T db pg_dump -U snip snip > backup.sql
```

`exec` allocates a terminal by default, like `docker exec -it`; `-T` disables that. Use `-T` whenever you pipe or
redirect input or output, so that the command reads and writes plain streams and no terminal translation (such as
`\r\n` line endings) ends up in the file.

`run` creates a **new, one-off container** from a service's definition (image, environment, volumes, networks), runs a
command in it, and leaves the long-running containers alone. It's the tool for tasks: migrations, scripts, a shell in a
known environment.

```bash
docker compose run --rm -e PGPASSWORD=snip db psql -h db -U snip -tAc 'SELECT count(*) FROM links'
```

```text
2
```

Points to remember about `run`:

- It starts the service's `depends_on` services if they aren't running yet.
- It doesn't publish the service's ports, so it can't collide with the running container (`--service-ports` publishes
  them).
- Without `--rm`, every `run` leaves a stopped container behind (named like `snip-db-run-7a9a9a729abd`).
- The command replaces `CMD` and is passed to the image's `ENTRYPOINT`. Snip's distroless image has the entrypoint
  `/nodejs/bin/node`, so `docker compose run --rm api node -e '…'` actually runs `node node -e '…'` and fails with
  `Cannot find module '/app/node'`. Pass only the arguments:

```bash
docker compose run --rm api -e 'console.log(process.env.DATABASE_URL)'
```

```text
postgres://snip:snip@db:5432/snip
```

### `config`

`docker compose config` parses the file, applies defaults, merges overrides and interpolates variables (chapter 11),
and prints the result in canonical form. It's the best way to answer "what will Compose actually do?" and to catch
mistakes before running anything:

```bash
docker compose config
```

```yaml
name: snip
services:
  api:
    build:
      context: /home/you/snip
      dockerfile: Dockerfile
    depends_on:
      db:
        condition: service_started
        required: true
    environment:
      BASE_URL: http://localhost:3000
      DATABASE_URL: postgres://snip:snip@db:5432/snip
    image: snip:1.3
    networks:
      default: null
    ports:
      - mode: ingress
        host_ip: 127.0.0.1
        target: 3000
        published: "3000"
        protocol: tcp
    restart: unless-stopped
  db:
    …
networks:
  default:
    name: snip_default
volumes:
  pgdata:
    name: snip_pgdata
```

(Keys are sorted here for readability.) Notice everything that was implicit: the default network, the full volume
name, the long forms of `depends_on` and `ports`. `docker compose config -q` only validates and prints nothing unless
there's an error, which makes a good CI check. `config --services` lists the service names.

### `stop`, `start` and `restart`

`stop` stops the containers (SIGTERM, then SIGKILL after the grace period, in reverse dependency order) and keeps
them; `start` starts the same containers again. `restart` does both. None of them apply changes from the Compose file;
for that, use `up -d`. In day-to-day use, `up -d` and `down` are almost all you need.

## Common mistakes

- **`localhost` for another service.** Inside `api`, `localhost` is `api` itself (chapter 9). The database is `db`.
- **Editing code and expecting `up -d` to pick it up.** Without `--build`, the old image keeps running.
- **Forgetting that `down -v` deletes data.** Including the dev database you spent an afternoon filling.
- **No `name:`.** The project name follows the directory, and a renamed directory starts with empty volumes.
- **Publishing every port "just in case",** on all interfaces, including the database's.
- **`container_name:` everywhere.** It prevents two copies of the project on one machine and `--scale`.
- **Expecting `depends_on` to wait for readiness.** It only orders startup; see chapter 11.
- **Passwords in `environment`.** They end up in `docker inspect` output and in Git. Chapter 13.
- **Old syntax from old guides:** `version:`, `links:`, `docker-compose`, `/var/lib/postgresql/data` for PostgreSQL
  18. `links:` is a legacy way to connect containers; the project network and service names replace it.

## Key takeaways

- Compose turns a set of `docker` commands into a declarative `compose.yaml`; `docker compose up -d` makes reality
  match the file, and `docker compose down` removes it.
- A Compose file has `services`, plus `volumes`, `networks`, `configs` and `secrets`. The `version:` key is obsolete.
- Everything belongs to a **project**. Set `name:` in the file. Resources are named `<project>-<service>-N`,
  `<project>_default` and `<project>_<volume>`, and found again through `com.docker.compose.*` labels.
- `up` recreates only services whose configuration changed. Source changes need `--build`.
- Services reach each other by service name on the project's default network. Publish only what the host needs, on
  `127.0.0.1` in development.
- `down` keeps named volumes; `down -v` deletes them.
- `depends_on` orders startup but doesn't wait for readiness; make apps tolerate a dependency that is briefly
  unavailable.
- `exec` runs in the existing container, `run --rm` in a new one-off container. `config` shows what Compose will
  really do.

## Exercises

1. **From `docker run` to Compose.** Translate these commands into a `compose.yaml` with project name `notes`, start
   it, check it works, and remove everything including the volume.

   ```bash
   docker network create notesnet
   docker volume create notesdata
   docker run -d --name cache --network notesnet valkey/valkey:9-alpine valkey-server --appendonly yes
   docker run -d --name web --network notesnet -p 127.0.0.1:8080:80 -v notesdata:/usr/share/nginx/html:ro nginx:alpine
   ```

<details>
<summary>Solution</summary>

```yaml
name: notes

services:
  cache:
    image: valkey/valkey:9-alpine
    command: ["valkey-server", "--appendonly", "yes"]
  web:
    image: nginx:alpine
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - notesdata:/usr/share/nginx/html:ro

volumes:
  notesdata:
```

```bash
docker compose up -d
docker compose exec cache valkey-cli ping       # PONG
curl -sI localhost:8080 | head -1               # HTTP/1.1 200 OK
docker compose down -v
```

The network needs no declaration: the project's default network replaces `notesnet`. The service names replace the
container names. `command` holds the arguments that followed the image name in `docker run`.

Why `200 OK` from an empty volume? A new, empty named volume is first filled with whatever the image has at the mount
point (chapter 8), so `notesdata` now holds nginx's default welcome page, even though the mount is read-only.

</details>

2. **Find what Compose made.** With Snip running, list the project's containers, network and volume using only plain
   `docker` commands (no `docker compose`). Then stop the project from a different directory without the Compose
   file.

<details>
<summary>Solution</summary>

```bash
docker ps --filter label=com.docker.compose.project=snip --format '{{.Names}}'
docker network ls --filter label=com.docker.compose.project=snip
docker volume ls --filter label=com.docker.compose.project=snip

cd /tmp && docker compose -p snip stop
```

Compose labels every resource with the project name, and every Compose command finds its resources through those
labels, so `-p snip` is enough for commands that act on existing containers (`ps`, `logs`, `stop`, `start`, `down`).
`up` and `build` need the file, because they need to know what *should* exist.

</details>

3. **What gets recreated.** With Snip running, predict what each of these does, then check with
   `docker compose up -d` and `docker compose ps` (look at CREATED):
   a) change `BASE_URL` in `compose.yaml`;
   b) change a line in `src/app.js`;
   c) change `POSTGRES_PASSWORD` for `db`.

<details>
<summary>Solution</summary>

a) Only `snip-api-1` is recreated; its configuration hash changed. `db` shows `Running`.

b) Nothing changes. The configuration still says `image: snip:1.3`, and that image exists. `docker compose up -d
--build` rebuilds the image and then recreates `api`, because the image ID changed.

c) Only `snip-db-1` is recreated; `api` keeps running and its pool reconnects. (The long form of `depends_on` has
`restart: true` for services that should be restarted along with their dependency; chapter 11.) And the password
**doesn't change**: the `postgres` image only reads `POSTGRES_PASSWORD` when it
initializes an *empty* data directory, and the volume already holds a cluster with the old password. You'd change it
with `ALTER USER snip PASSWORD '…'` in `psql`, or wipe the volume with `down -v` in development. Environment variables
that only take effect on first initialization are a classic surprise with database images.

</details>

4. **Backup and restore.** Create a few links, dump the database to `backup.sql` on the host with `pg_dump`, destroy
   everything with `down -v`, start only the database, restore the dump, then start the rest and check your links are
   back.

<details>
<summary>Solution</summary>

```bash
docker compose exec -T db pg_dump -U snip snip > backup.sql
docker compose down -v
docker compose up -d db
sleep 5                                          # chapter 11 replaces this with a healthcheck
docker compose exec -T db psql -U snip -q < backup.sql
docker compose up -d
curl -s localhost:3000/api/links/<code>
```

`-T` matters in both directions: the dump must go to the file as a plain stream, and `psql` must read the
redirected file, not a terminal. Starting only `db` first means the app doesn't create an empty `links` table that the
restore would then collide with. Chapter 18 builds a proper backup routine.

</details>

5. **Two copies.** Start a second copy of the Snip project with `docker compose -p snip2 up -d`. What happens, and
   why? What would you need to change for two copies to run side by side?

<details>
<summary>Solution</summary>

```text
Error response from daemon: failed to set up container networking: driver failed programming external connectivity on endpoint snip2-api-1 (afd8…): Bind for 127.0.0.1:3000 failed: port is already allocated
```

Almost everything is separate already: `snip2-db-1`, `snip2_default` and `snip2_pgdata` don't collide with `snip`'s
resources, because they carry the project prefix. (`name: snip` in the file loses to `-p`.) The one shared resource
is the host port: both projects want `127.0.0.1:3000`. Clean up with `docker compose -p snip2 down -v`.

To run both, the host port must differ per copy. Editing the file works but defeats the purpose; chapter 11 shows
the proper way, `"127.0.0.1:${PORT:-3000}:3000"` with a variable. `container_name:` would have caused a second
collision, which is why you shouldn't use it.

</details>

---
← Previous: [Networking](09-networking.md) | Next: [Compose in depth](11-compose-in-depth.md) →
