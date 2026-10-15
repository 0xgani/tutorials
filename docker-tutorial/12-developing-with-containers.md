# Chapter 12 — Developing with containers

## What you'll learn

- The options for developing a containerized app, and when each fits
- A `dev` stage in the Dockerfile, with all dependencies and `node --watch`
- Bind-mounting source code, the `node_modules` problem, and file ownership
- Compose Watch: syncing changes into containers and rebuilding when dependencies change
- Debugging Node.js inside a container from VS Code or Chrome DevTools
- Running unit and integration tests in containers
- Dev containers, briefly
- Performance on Docker Desktop

## The development loop

Chapters 10 and 11 gave Snip a production-style stack: the distroless image, built from scratch on every
`docker compose up --build`. That's right for testing the real thing and painful for writing code. Each change to
`src/app.js` means a rebuild (a few seconds with a warm cache) and a container restart, and the distroless image has
no shell to poke around in.

What you want while developing:

- **Fast feedback:** save a file, and the app restarts within a second or two.
- **The same dependencies as everyone else:** the same PostgreSQL 18, the same Valkey, the same Node.js version.
- **Debugging and tests** that work without a long setup.

There are three common ways to get there:

| Approach | How it works | Good for |
|---|---|---|
| **1. App on the host, services in Compose** | `docker compose up -d db valkey`, then `npm run dev` on your machine with `DATABASE_URL=…@localhost:5432/…` | Simple apps; teams where everyone already has the right Node.js |
| **2. App in a container, source mounted** | A bind mount puts your working copy inside the container; `node --watch` restarts on changes | Linux hosts; the classic setup |
| **3. App in a container, Compose Watch** | Compose copies changed files into the container, and rebuilds the image when dependencies change | Any OS, the most predictable option; what this chapter's Snip uses |

Approach 1 is underrated. With the database and cache in Compose (chapter 11's override file already publishes
them on `127.0.0.1:5432` and `127.0.0.1:6379`), running the API with your local Node.js gives you the fastest
possible loop and your usual debugger, and Compose still guarantees that everyone has the same backing services. Its
weakness is that "the same Node.js" is up to each developer, and that the app never runs in the image you ship until
CI. Approaches 2 and 3 run the app in a container from the start.

The complete project for this chapter is in [`examples/ch12-development/`](examples/ch12-development/).

## A development image

The production image is the wrong image for development: it has no devDependencies, no shell and a fixed `CMD`. Add
a `dev` stage to the multi-stage Dockerfile from chapter 7:

```dockerfile
# dev: local development (chapter 12). All dependencies, including devDependencies; Compose Watch
# syncs source changes into /app and node --watch restarts the process.
FROM base AS dev
ENV NODE_ENV=development
RUN chown node:node /app
USER node
COPY --chown=node:node package.json package-lock.json ./
RUN --mount=type=cache,target=/home/node/.npm,uid=1000,gid=1000 \
    npm ci
COPY --chown=node:node . .
EXPOSE 3000 9229
CMD ["node", "--watch", "src/server.js"]
```

Line by line:

- **`FROM base`**: the same Node.js version and base image as production, so "works in dev" means something.
- **`ENV NODE_ENV=development`** before `npm ci`: with `NODE_ENV=production` (inherited from `base`), `npm ci` would
  skip devDependencies (chapter 5).
- **`chown` and `USER node`**: the app runs as the unprivileged `node` user (UID 1000), like production runs as
  non-root. `/app` must belong to that user, because Compose Watch writes files into it.
- **The cache mount** targets the `node` user's npm cache, with matching ownership.
- **`COPY . .`** copies everything the `.dockerignore` allows, tests included. Add `compose*.yaml` to `.dockerignore`
  so that editing a Compose file doesn't invalidate this layer.
- **`node --watch`** restarts the process when a file it loaded changes (built in, stable since Node.js 22). No
  `nodemon` needed.

The `dev` stage is never part of the production build: `docker build .` builds the last stage (`runtime`) and only
the stages it depends on, and `dev` isn't one of them.

The development settings go into `compose.override.yaml`, which `docker compose` merges automatically (chapter 11):

```yaml
x-dev: &dev
  build:
    target: dev
  image: snip:dev
  develop:
    watch:
      # Source and migrations: copy changed files into the container; node --watch restarts.
      - action: sync
        path: ./src
        target: /app/src
      - action: sync
        path: ./migrations
        target: /app/migrations
      # New dependencies: rebuild the image and recreate the container.
      - action: rebuild
        path: package-lock.json

services:
  api:
    <<: *dev
    command: ["node", "--watch", "--inspect=0.0.0.0:9229", "src/server.js"]
    ports:
      - "127.0.0.1:9229:9229"   # Node.js inspector, for your debugger. Never publish it beyond 127.0.0.1.
    environment:
      NODE_ENV: development

  worker:
    <<: *dev
    command: ["node", "--watch", "src/worker.js"]

  migrate:
    build:
      target: dev
    image: snip:dev

  db:
    ports:
      - "127.0.0.1:5432:5432"
  valkey:
    ports:
      - "127.0.0.1:6379:6379"
```

`build.target` is merged into the base file's `build: .`, so the services build the `dev` stage and tag it
`snip:dev`. Production keeps using `docker compose -f compose.yaml …` (or `-f compose.yaml -f compose.prod.yaml`),
which never reads the override file.

One subtlety: the base file runs the worker with `command: ["src/worker.js"]`, written for the distroless image whose
entrypoint is `node`. The `node:24-slim` image's entrypoint is a script (`docker-entrypoint.sh`) that also prepends
`node` when the first argument isn't a command, so the same `command` works with both images. Here the override
replaces it anyway, to add `--watch`.

## Approach 2: bind-mounting the source

The classic setup mounts your working copy into the container:

```yaml
services:
  api:
    build:
      target: dev
    volumes:
      - ./src:/app/src
```

Edit `src/app.js` on the host, and the container sees the change at once (it's the same file). `node --watch`
notices and restarts:

```text
SIGTERM received, closing the server
all connections closed, exiting
snip listening on port 3000 (node v24.22.0, pid 21)
```

Note that `node --watch` stops the old process with SIGTERM, so Snip's graceful shutdown from chapter 4 runs on every
restart.

### The `node_modules` problem

Mounting just `src/` works. Mounting the whole project directory, which is tempting, doesn't:

```bash
docker run --rm -v "$PWD:/app" snip:dev
```

```text
Error [ERR_MODULE_NOT_FOUND]: Cannot find package 'express' imported from /app/src/app.js
```

The bind mount *replaces* everything at `/app`, including the `node_modules` that `npm ci` installed in the image.
The container now sees your host's `node_modules`, or none at all. Even if you have one on the host, it's the wrong
one whenever a package contains native code: modules compiled for macOS or Windows don't load on Linux.

```text
Error: /app/node_modules/bcrypt/lib/binding/napi-v3/bcrypt_lib.node: invalid ELF header
```

The traditional workaround is an **anonymous volume** on top of the bind mount:

```yaml
    volumes:
      - .:/app
      - /app/node_modules        # keep the image's node_modules, hidden from the bind mount
```

```bash
docker run --rm -v "$PWD:/app" -v /app/node_modules snip:dev
```

```text
snip listening on port 3000 (node v24.22.0, pid 12)
```

The more specific mount wins, and a new anonymous volume is filled from the image's `/app/node_modules` (chapter 8).
It works, but it has sharp edges: after you add a dependency and rebuild, the *old* anonymous volume may still be
attached with the old packages (`docker compose up --renew-anon-volumes`, or `-V`, recreates it), and the volumes pile
up. Mounting only the directories you edit (`src/`, `migrations/`, `test/`) avoids the problem entirely.

### File ownership on Linux

On Docker Desktop, files in bind mounts appear with sensible ownership whatever user the container runs as. On a
Linux host, a bind mount shows the real numeric owners (chapter 8):

- The container reads your files fine if they're world-readable, which source code normally is.
- Files the container *creates* in a bind mount (a coverage report, a generated file) belong to the container's UID.
  The `node` user is UID 1000, which is also the first regular user on most Linux machines, so it usually lines up.
  If your UID isn't 1000, run the container as you: `user: "${UID:-1000}:${GID:-1000}"` in the Compose file, with
  `UID` and `GID` exported in your shell (`export UID GID=$(id -g)`; bash sets `UID` but doesn't export it).

`docker compose top` shows UIDs by the *host's* user names, which can be confusing: UID 1000 inside the container
appears under whatever name UID 1000 has on the host.

## Approach 3: Compose Watch

**Compose Watch** (Compose 2.22+) takes the bind mount out of the picture. Compose watches the paths you list on the
host and, when something changes, does one of these:

| Action | What happens | Use for |
|---|---|---|
| `sync` | Copies the changed files into the running container | Source code, with a process that reloads itself (`node --watch`) |
| `sync+restart` | Copies, then restarts the container | Config files read once at startup |
| `sync+exec` | Copies, then runs a command in the container (`exec: command: …`) | Sending a reload signal, regenerating something |
| `restart` | Restarts the container | Files read at startup that live outside the container |
| `rebuild` | Builds the image again and recreates the container | `package.json`, `package-lock.json`, the Dockerfile |

Each rule takes a `path` on the host (relative to the Compose file), a `target` in the container for the sync
actions, and optional `ignore` patterns. Files matched by `.dockerignore` are never synced.

Start the stack with watching enabled:

```bash
docker compose up --watch
```

You get the usual merged logs plus the watch events. (`docker compose watch` does the same, but shows only the watch
events, not the logs.) Change the health endpoint in `src/app.js` and save:

```text
           ⦿ Syncing service "api" after 2 changes were detected
           ⦿ Syncing service "worker" after 2 changes were detected
api-1      | Change detected in '/app/src/app.js'
api-1      | Restarting 'src/server.js'
api-1      | SIGTERM received, closing the server
api-1      | all connections closed, exiting
api-1      | Debugger listening on ws://0.0.0.0:9229/c3b87473-fd1e-4f83-82b9-b05a22f1c07a
api-1      | snip listening on port 3000 (node v24.22.0, pid 32)
```

Both containers received the file, because both watch `./src`. Only the API restarted: `node --watch` watches the
files the process actually imported, and the worker doesn't import `app.js`.

Install a package (`npm install` on the host changes `package-lock.json`), and the `rebuild` rule kicks in:

```text
           ⦿ Rebuilding service(s) ["api" "worker"] after changes were detected...
 Image snip:dev Building
 Image snip:dev Built
           ⦿ service(s) ["api" "worker"] successfully built
 Container snip-migrate-1 Recreate
 Container snip-worker-1 Recreate
 Container snip-api-1 Recreate
```

The image's `npm ci` runs inside the build, on Linux, so native modules are always compiled for the container. There's
no `node_modules` mount and no anonymous volume to go stale: `node_modules` lives only in the image.

Why this is the most predictable option:

- **No ownership issues.** Files are copied in as the container's user.
- **No platform differences.** It behaves the same on Linux, macOS and Windows, and doesn't depend on how fast or
  correct file-change notifications through a bind mount are.
- **The container is the image plus your current source,** which is very close to what CI builds.

The price is that changes flow one way: files the container writes don't appear on your host. For generated files you
need on the host, add a bind mount for that one directory.

Press Ctrl+C to stop `up --watch`; it stops the containers like a foreground `up` does.

## Debugging Node.js in a container

Node.js has a built-in debugging protocol, the **inspector**. `--inspect=host:port` turns it on; debuggers (VS Code,
Chrome DevTools, WebStorm) connect over a WebSocket. Inside a container, two details matter:

- **Listen on `0.0.0.0`.** The default `--inspect` listens on `127.0.0.1:9229`, which is the *container's* loopback
  interface, unreachable from your host (chapter 9). Hence `--inspect=0.0.0.0:9229` in the override file.
- **Publish the port on `127.0.0.1` only.** The inspector lets anyone who can connect run arbitrary code in the
  process, with no authentication. `"127.0.0.1:9229:9229"` keeps it on your machine. Never enable it in production
  images.

Check that it's reachable:

```bash
curl -s localhost:9229/json/list
```

```text
[ {
  "description": "node.js instance",
  "id": "c3b87473-fd1e-4f83-82b9-b05a22f1c07a",
  "title": "src/server.js",
  "type": "node",
  "url": "file:///app/src/server.js",
  "webSocketDebuggerUrl": "ws://localhost:9229/c3b87473-fd1e-4f83-82b9-b05a22f1c07a"
  …
} ]
```

**VS Code:** add an "attach" configuration in `.vscode/launch.json`:

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Attach to snip api (Docker)",
      "type": "node",
      "request": "attach",
      "address": "127.0.0.1",
      "port": 9229,
      "localRoot": "${workspaceFolder}",
      "remoteRoot": "/app",
      "restart": true,
      "skipFiles": ["<node_internals>/**"]
    }
  ]
}
```

- `localRoot` and `remoteRoot` map paths: a breakpoint in `src/app.js` in your editor is
  `/app/src/app.js` in the container. Without them, breakpoints stay grey ("unbound").
- `restart: true` re-attaches automatically after `node --watch` restarts the process (each restart starts a new
  inspector session, with a new ID).

**Chrome:** open `chrome://inspect`, make sure `localhost:9229` is in the target discovery list, and click
"inspect" under the Snip process.

`--inspect-brk` instead of `--inspect` pauses before the first line runs, which is how you debug startup code. Use
`--inspect-wait` (Node.js 22.2+) to wait for a debugger without pausing on the first line.

For quick questions, a debugger is often overkill. In the dev image you have a shell: `docker compose exec api sh`,
then look at files, environment variables (`env`) and the network (`node -e "fetch('http://valkey:6379')…"`). Chapter
19 covers debugging the distroless production image, which has no shell.

## Running tests in containers

Running tests in the same image and environment as the app removes a whole class of "passes on my machine" problems.
There are three useful levels.

**Unit tests in the build.** Chapter 7's `test` stage runs `node --test` during `docker build --target test .`, so an
image can't even be built from code that fails its tests. That's what CI does (chapter 14).

**Unit tests in the dev container,** for the development loop:

```bash
docker compose run --rm --no-deps api npm test
```

```text
> snip@1.0.0 test
> node --test

﹣ a short link redirects and its clicks are counted (0.839421ms) # SNIP_URL not set
ℹ pass 4
ℹ fail 0
ℹ skipped 1
```

`--no-deps` stops `run` from starting the database and the cache, which unit tests don't need. (The skipped test is
the integration test below.)

**Integration tests against the running stack.** Snip's `test/integration.test.js` runs only when `SNIP_URL` is set:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';

const base = process.env.SNIP_URL;

test('a short link redirects and its clicks are counted', { skip: !base && 'SNIP_URL not set' }, async () => {
  const created = await fetch(`${base}/api/links`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: 'https://example.com/integration' }),
  });
  assert.equal(created.status, 201);
  const { code } = await created.json();

  const redirect = await fetch(`${base}/${code}`, { redirect: 'manual' });
  assert.equal(redirect.status, 302);
  assert.equal(redirect.headers.get('location'), 'https://example.com/integration');

  // The worker counts clicks asynchronously; poll for up to 10 seconds.
  for (let i = 0; i < 20; i++) {
    const link = await (await fetch(`${base}/api/links/${code}`)).json();
    if (link.clicks === 1) return;
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  assert.fail('click was not counted within 10 seconds');
});
```

It exercises the whole path: API, PostgreSQL, Valkey cache, queue and worker. Run it from a container on the project
network, so it reaches the API by its service name:

```bash
docker compose up -d --wait
docker compose run --rm --no-deps -e SNIP_URL=http://api:3000 api node --test test/integration.test.js
```

```text
✔ a short link redirects and its clicks are counted (597.769983ms)
ℹ tests 1
ℹ pass 1
ℹ fail 0
```

The `--wait` from chapter 11 matters here: without it, the test may run before the API is up. In CI, the same two
commands run against a fresh project (`-p ci-$RUN_ID`), followed by `down -v`.

A note on test databases: integration tests that write data should run against a database that can be thrown away. A
separate Compose project per test run gives you exactly that, for free.

## Dev containers

A **dev container** goes one step further: your *editor* runs inside the container. A `.devcontainer/devcontainer.json`
file describes the image (or Compose service), the extensions and the settings, and VS Code, GitHub Codespaces and
other tools that support the open Dev Container specification build it and connect to it. Every developer gets the
same Node.js, the same CLI tools and the same editor extensions, without installing anything but Docker.

> **Just a taste — you don't need to learn or fully understand this now.** This shows the shape of a dev container
> configuration that reuses Snip's Compose stack; see <https://containers.dev> for the specification.

```json
{
  "name": "snip",
  "dockerComposeFile": ["../compose.yaml", "../compose.override.yaml"],
  "service": "api",
  "workspaceFolder": "/app",
  "customizations": {
    "vscode": { "extensions": ["dbaeumer.vscode-eslint"] }
  }
}
```

Dev containers are great for onboarding and for projects with complicated toolchains. For a Node.js service like
Snip, Compose Watch plus your normal editor is usually enough.

## Performance on Docker Desktop

On Linux, containers share the host kernel and a bind mount is just a different view of the same directory: there's
no overhead. Docker Desktop runs containers in a Linux VM, so bind mounts cross a VM boundary:

- **macOS:** Docker Desktop uses VirtioFS for file sharing, which is fast enough for most projects. Directories with
  tens of thousands of files (a big `node_modules`) are still slower than inside the VM. That's one more reason to keep
  `node_modules` in the image or in a volume, never bind-mounted.
- **Windows:** keep your projects inside the WSL 2 filesystem (`\\wsl$\…`, for example `~/code/snip` in your WSL
  distribution), not on `C:\`. Bind mounts from the Windows filesystem are much slower and file-change notifications
  don't always arrive, so watchers miss changes.
- **Compose Watch** avoids most of this, because the container reads its own filesystem, not a shared one.

If a file watcher inside a container doesn't notice changes through a bind mount, the usual fix in watch tools is a
polling mode (for example `CHOKIDAR_USEPOLLING=true` for tools built on chokidar), at the cost of CPU. With Compose
Watch, the changes arrive as real file writes inside the container, so ordinary watching works.

## Key takeaways

- Choose a development approach on purpose: app on the host with services in Compose, the app in a container with a
  bind mount, or the app in a container with Compose Watch.
- Add a `dev` stage to the Dockerfile: same base as production, all dependencies, a non-root user, `node --watch`.
  Select it with `build.target` in `compose.override.yaml`.
- Never bind-mount a host `node_modules` into a Linux container. Mount only source directories, or let Compose Watch
  sync them.
- Compose Watch (`docker compose up --watch`) syncs source changes and rebuilds the image when dependencies change.
- Debug with `--inspect=0.0.0.0:9229` and a port published on `127.0.0.1` only; map `remoteRoot` to `/app` in your
  debugger.
- Run unit tests in the build or with `docker compose run --rm --no-deps`, and integration tests from a container on
  the project network after `up --wait`.
- On Docker Desktop, keep heavy directories out of bind mounts; on Windows, keep the project in WSL 2.

## Exercises

1. **Host app, container services.** Using chapter 11's stack, start only the database and Valkey, run the migrations,
   and run the API with Node.js on your host. Which URL and environment variables does the API need?

<details>
<summary>Solution</summary>

```bash
docker compose up -d --wait db valkey
docker compose run --rm migrate
export DATABASE_URL=postgres://snip:snip-dev-password@localhost:5432/snip
export VALKEY_URL=redis://localhost:6379
npm ci && npm run dev
```

On the host, the services are reachable through the ports that `compose.override.yaml` publishes on `127.0.0.1`, so
the host name is `localhost`, not `db` or `valkey`. The password is the one from your `.env`. `run --rm migrate` runs
the migration job in a container on the project network, where `db` is the right host name, because `run` uses the
service's own `DATABASE_URL`. You could also run `node src/migrate.js` on the host with the exported `DATABASE_URL`.

</details>

2. **Prove the `node_modules` problem.** Run the dev image with the whole project directory bind-mounted, and observe
   the error. Fix it with an anonymous volume. Then add a dependency (for example `npm install ms`), rebuild the
   image, and explain why the container may still not find it.

<details>
<summary>Solution</summary>

```bash
docker run --rm -v "$PWD:/app" snip:dev                       # ERR_MODULE_NOT_FOUND: express
docker run --rm -v "$PWD:/app" -v /app/node_modules snip:dev  # works
```

With Compose, the same thing is `volumes: [".:/app", "/app/node_modules"]`. After `npm install ms` and
`docker compose build`, `docker compose up -d` recreates the container but **keeps its anonymous volumes** by default,
so `/app/node_modules` still holds the old packages from the first run, and `import 'ms'` fails.
`docker compose up -d --renew-anon-volumes` (`-V`) creates fresh anonymous volumes, filled from the new image. With
`docker run`, each run gets a new anonymous volume, so the problem appears only with Compose's container reuse. Compose
Watch's `rebuild` action has no such issue, since there's no volume involved.

</details>

3. **Break a breakpoint.** Attach VS Code (or Chrome DevTools) to the API, set a breakpoint in the `POST /api/links`
   handler, and create a link with `curl`. Then remove `remoteRoot` from `launch.json` and explain what happens.

<details>
<summary>Solution</summary>

With the configuration from this chapter, the request pauses at the breakpoint, `curl` waits, and you can inspect
`req.body`. Continue, and `curl` gets its answer.

Without `remoteRoot`, VS Code assumes the files in the container have the same paths as on your machine, for example
`/home/you/snip/src/app.js`. The process loaded `/app/src/app.js`, so no loaded script matches the breakpoint's file,
and the breakpoint stays unbound (hollow or grey). Path mapping is the most common reason why "debugging in Docker
doesn't work".

</details>

4. **A watch rule for migrations.** Snip's override already syncs `./migrations` into the containers, but nothing
   applies a new migration file. Add a `develop.watch` rule to the `migrate` service so that a new or changed
   migration is applied automatically while `up --watch` runs.

<details>
<summary>Solution</summary>

```yaml
  migrate:
    build:
      target: dev
    image: snip:dev
    develop:
      watch:
        - action: sync+restart
          path: ./migrations
          target: /app/migrations
```

`sync+restart` copies the file into the `migrate` container and restarts it, which runs `src/migrate.js` again: it
applies the new file and exits. Restarting an exited container is allowed; that's what `docker compose restart migrate`
does too. `rebuild` would also work, but rebuilding the image for an SQL file is much slower.

</details>

5. **Integration tests in an isolated project.** Write a short shell script for CI that starts a uniquely named copy
   of the stack without the development overrides, waits for it, runs the integration test against it, always cleans
   up (also when the test fails), and exits with the test's exit code.

<details>
<summary>Solution</summary>

```bash
#!/bin/sh
set -u
project="snip-ci-$$"                 # unique per run: the shell's PID (use the CI's run ID in CI)
compose() { SNIP_PORT=0 docker compose -p "$project" -f compose.yaml "$@"; }

compose up -d --build --wait || { compose logs; compose down -v; exit 1; }
docker run --rm --network "${project}_default" -v "$PWD/test:/test:ro" -e SNIP_URL=http://api:3000 \
  node:24-alpine node --test /test/integration.test.js
status=$?
[ "$status" -eq 0 ] || compose logs api worker
compose down -v
exit "$status"
```

```text
✔ a short link redirects and its clicks are counted (686.587319ms)
ℹ pass 1
ℹ fail 0
```

Points to notice:

- Only `compose.yaml` is used, so the production image is tested and the override file's fixed database ports can't
  collide with anything. `SNIP_PORT=0` gives the API a random host port, so parallel runs don't collide either.
- The production image is distroless and doesn't contain `test/`, so the test runs in a separate throwaway container
  that joins the project's network (`<project>_default`) and gets the test file through a read-only bind mount. The
  integration test needs nothing but Node.js, so any `node` image will do.
- On failure, the script prints the logs before `down -v` deletes the containers, and `down -v` removes the volumes
  so nothing accumulates on the CI machine.

</details>

---
← Previous: [Compose in depth](11-compose-in-depth.md) | Next: [Configuration and secrets](13-configuration-and-secrets.md) →
