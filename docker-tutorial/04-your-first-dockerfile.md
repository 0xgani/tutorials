# Chapter 4 — Your first Dockerfile

## What you'll learn

- Snip, the Node.js app you will containerize and grow for the rest of the tutorial
- What a Dockerfile is and how `FROM`, `WORKDIR`, `COPY`, `RUN`, `ENV`, `EXPOSE` and `CMD` work
- What the build context is, why `.dockerignore` matters for speed *and* security
- How to read `docker build` output, and how the layer cache decides what to rebuild
- Why the order of instructions makes builds take one second or two minutes
- `npm ci` vs. `npm install`, `--omit=dev`, and `NODE_ENV=production`
- Exec form vs. shell form, and why `CMD ["npm", "start"]` is a mistake
- How to make a Node.js server shut down gracefully when Docker stops it
- How to tag, run and test your image

## Meet Snip

Snip is a URL shortener. You `POST` a long URL and get back a short code; `GET /<code>` redirects to the long URL and
counts the click. In this chapter it keeps links in memory; in chapter 10 they move into PostgreSQL. Its source is in
[`examples/ch04-first-image/`](examples/ch04-first-image/):

```text
ch04-first-image/
  package.json
  package-lock.json
  src/
    app.js            # the Express app: routes and validation
    memory-store.js   # where links are kept (a Map, for now)
    server.js         # reads configuration, starts listening, handles shutdown
  Dockerfile
  .dockerignore
```

`package.json` declares one dependency, Express 5, and uses ES modules:

```json
{
  "name": "snip",
  "version": "1.0.0",
  "description": "A tiny URL shortener, containerized step by step in the Docker tutorial",
  "private": true,
  "type": "module",
  "main": "src/server.js",
  "scripts": {
    "start": "node src/server.js"
  },
  "engines": {
    "node": ">=24"
  },
  "dependencies": {
    "express": "^5.3.0"
  }
}
```

`src/app.js` builds the Express app. The store is passed in, so that later chapters can swap the storage without
touching the routes:

```js
import express from 'express';
import { randomBytes } from 'node:crypto';

// Builds the Express app. The store is passed in so that later chapters can
// swap the in-memory map for PostgreSQL without touching the routes.
export function createApp({ store, baseUrl }) {
  const app = express();
  app.use(express.json({ limit: '10kb' }));

  app.get('/healthz', (req, res) => {
    res.json({ status: 'ok' });
  });

  app.post('/api/links', async (req, res) => {
    const url = parseUrl(req.body?.url);
    if (!url) {
      return res.status(400).json({ error: 'body must be {"url": "http(s)://..."}' });
    }
    const code = randomBytes(5).toString('base64url').slice(0, 7);
    const link = await store.create({ code, url });
    res.status(201).json({ ...link, shortUrl: `${baseUrl}/${code}` });
  });

  app.get('/api/links/:code', async (req, res) => {
    const link = await store.get(req.params.code);
    if (!link) return res.status(404).json({ error: 'not found' });
    res.json(link);
  });

  app.get('/:code', async (req, res) => {
    const link = await store.get(req.params.code);
    if (!link) return res.status(404).type('text').send('not found\n');
    await store.recordClick(link.code);
    res.redirect(302, link.url);
  });

  return app;
}

function parseUrl(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
  } catch {
    return null;
  }
}
```

`src/memory-store.js` is the simplest possible store:

```js
// Keeps links in a Map. Everything is lost when the process exits,
// which is exactly the problem chapter 10 solves with PostgreSQL.
export function createMemoryStore() {
  const links = new Map();
  return {
    async create({ code, url }) {
      const link = { code, url, clicks: 0, createdAt: new Date().toISOString() };
      links.set(code, link);
      return link;
    },
    async get(code) {
      return links.get(code) ?? null;
    },
    async recordClick(code) {
      const link = links.get(code);
      if (link) link.clicks += 1;
    },
  };
}
```

And `src/server.js` wires everything together. Notice two container-friendly habits already: configuration comes
from **environment variables** with sensible defaults, and the process **handles `SIGTERM`**:

```js
import { createApp } from './app.js';
import { createMemoryStore } from './memory-store.js';

const port = Number(process.env.PORT ?? 3000);
const baseUrl = process.env.BASE_URL ?? `http://localhost:${port}`;

const app = createApp({ store: createMemoryStore(), baseUrl });
const server = app.listen(port, () => {
  console.log(`snip listening on port ${port} (node ${process.version}, pid ${process.pid})`);
});

// Docker stops a container by sending SIGTERM to its main process, then SIGKILL
// after a grace period (10 s by default). Shut down cleanly before that happens.
function shutdown(signal) {
  console.log(`${signal} received, closing the server`);
  server.close(() => {
    console.log('all connections closed, exiting');
    process.exit(0);
  });
  server.closeIdleConnections();
  // Don't hang forever on a stuck keep-alive connection.
  setTimeout(() => {
    console.error('could not close connections in time, forcing exit');
    process.exit(1);
  }, 8000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
```

`server.close()` stops accepting new connections and calls back when the existing ones have finished.
`closeIdleConnections()` closes keep-alive connections that are open but not in the middle of a request, which would
otherwise keep `close()` waiting. The 8-second fallback stays inside Docker's 10-second grace period, so the process
always exits on its own terms. Chapter 18 refines this further.

`app.listen(port)` without a host listens on all interfaces, which is what a server in a container needs
(chapter 2).

If you have Node.js installed, you can run it directly with `npm install && npm start`. But the point of this chapter
is that you don't have to.

## A first, naive Dockerfile

A **Dockerfile** is a text file of instructions that tells BuildKit how to produce an image, step by step. Here is
the version most people write first, and that you will find in countless blog posts:

```dockerfile
FROM node:24-slim
WORKDIR /app
COPY . .
RUN npm install
CMD ["npm", "start"]
```

It works. It also has five problems, and fixing them teaches most of what matters about Dockerfiles:

1. `COPY . .` copies **everything**, including your local `node_modules` (built for your OS, possibly macOS, and
   containing your dev dependencies), `.git`, and any `.env` file with secrets.
2. Because the code is copied before `npm install`, **any change to any file reinstalls every dependency**.
3. `npm install` may **change** the lockfile and resolve different versions than the ones you tested; it also installs
   dev dependencies into a production image.
4. `CMD ["npm", "start"]` makes **npm** the main process, an extra process that doesn't reliably pass signals on to
   your app.
5. It runs as **root** (fixed in chapter 7).

Let's build the proper version and explain every line.

## The Snip Dockerfile

```dockerfile
# Snip, chapter 4: a first, single-stage image.
FROM node:24-slim

WORKDIR /app

# Dependencies first: this layer is rebuilt only when the manifests change.
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# Then the application code, which changes on almost every build.
COPY src ./src

ENV NODE_ENV=production
EXPOSE 3000
CMD ["node", "src/server.js"]
```

### `FROM`: the base image

```dockerfile
FROM node:24-slim
```

Every Dockerfile starts from a base image (or from `scratch`, an empty one). Everything in `node:24-slim` (Debian,
Node.js 24, npm, the image's environment variables, its entrypoint and default command) becomes the starting point of
your image. Chapter 3 explained how to choose; `node:24-slim` is the safe default.

### `WORKDIR`: the working directory

```dockerfile
WORKDIR /app
```

Sets the directory for every following `RUN`, `COPY`, `CMD` and `ENTRYPOINT`, and for `docker exec` sessions, and
creates it if needed. Always use `WORKDIR` instead of `RUN cd /app && …`: each `RUN` starts in a fresh shell, so a
`cd` doesn't carry over to the next instruction. Avoid putting your app in `/` directly; files there mix with the
operating system's.

### `COPY`: files from the build context

```dockerfile
COPY package.json package-lock.json ./
```

Copies files from the **build context** (your project directory, see below) into the image. With several sources,
the destination must be a directory and end with `/`. Relative destinations are relative to `WORKDIR`, so this copies
both files to `/app/`. `COPY src ./src` copies the *contents* of the `src` directory into `/app/src`.

Copied files are owned by root unless you say otherwise (`--chown`, chapter 5).

### `RUN`: execute a command at build time

```dockerfile
RUN npm ci --omit=dev
```

Runs a command in a temporary container from the image built so far and saves the resulting filesystem changes as a
new layer. Here it installs exactly the dependencies listed in `package-lock.json`, without dev dependencies, into
`/app/node_modules` *inside the image*, built on Linux for the image's platform.

`RUN` happens at **build time**, once. Don't confuse it with `CMD`, which runs every time a container starts.

### `npm ci` vs. `npm install`

| | `npm ci` | `npm install` |
|---|---|---|
| Lockfile | **Required**; installs exactly what it says | Optional; may update it |
| `package.json` and lockfile disagree | Fails with an error | Silently resolves and rewrites the lockfile |
| Existing `node_modules` | Deleted first | Updated in place |
| Use it in | Docker builds and CI | Development, when adding or updating packages |

`npm ci` is what makes builds reproducible: the image contains the same dependency tree you tested. Commit your
`package-lock.json`. (With pnpm, use `pnpm install --frozen-lockfile`; with Yarn, `yarn install --immutable`.)

`--omit=dev` skips `devDependencies` (test frameworks, linters, TypeScript), which don't belong in a production image:
they make it larger and add packages that can have vulnerabilities. If your build needs dev dependencies (to compile
TypeScript or bundle assets), you need a multi-stage build (chapter 7).

### `ENV`: environment variables in the image

```dockerfile
ENV NODE_ENV=production
```

Sets an environment variable for the rest of the build *and* for every container started from the image. You can
still override it with `docker run -e NODE_ENV=development`. Many libraries look at `NODE_ENV`: Express caches view
templates and hides stack traces from error responses in production, and npm skips dev dependencies when
`NODE_ENV=production`. Setting it after `npm ci` is deliberate: the install step states its intent with `--omit=dev`
instead of depending on an invisible variable.

### `EXPOSE`: documentation for the port

```dockerfile
EXPOSE 3000
```

Records in the image's metadata that the app listens on port 3000. It does **not** publish the port; you still need
`-p` when you run the container. It documents intent, and `docker run -P` uses it to publish all exposed ports to
random host ports.

### `CMD`: the default command

```dockerfile
CMD ["node", "src/server.js"]
```

The command a container runs when you don't give one on the `docker run` command line. Run as the main process, it is
what Docker sends `SIGTERM` to. Two things deserve attention.

**Exec form vs. shell form.** `CMD` (like `RUN` and `ENTRYPOINT`) accepts two syntaxes:

```dockerfile
CMD ["node", "src/server.js"]     # exec form: a JSON array; runs node directly
CMD node src/server.js            # shell form: runs /bin/sh -c "node src/server.js"
```

In shell form, the main process is `/bin/sh`, and `node` is its child. When Docker sends `SIGTERM`, it reaches the
shell, and `sh` does not forward it to `node`. Your graceful-shutdown handler never runs, and the container is
killed after 10 seconds. (Some shells happen to `exec` a single simple command, which hides the problem until the
command gets slightly more complicated.) **Use exec form for `CMD` and `ENTRYPOINT`.** The JSON array needs double
quotes; with single quotes Docker silently treats it as shell form.

**Not `npm start`.** `CMD ["npm", "start"]` is exec form, but it makes npm the main process, which then starts
`sh -c "node src/server.js"`, which starts `node`. That's three processes where one would do, npm uses memory for
nothing, and signal forwarding through npm and the shell has been unreliable for years. Run `node` directly. If your
start script does something non-trivial, put it in the Dockerfile or in a small entrypoint script (chapter 5).

## The build context and `.dockerignore`

```bash
docker build -t snip:1.0 .
```

The final `.` is the **build context**: the set of files the build can see. BuildKit sends the files that the build
needs from that directory to the builder (which may be a different machine, or a VM, as with Docker Desktop). `COPY`
can only copy from the context, never from elsewhere on your disk; `COPY ../secrets.json .` fails.

A **`.dockerignore`** file in the root of the context excludes files from it, with patterns like `.gitignore`:

```text
# Keep the build context small and free of secrets.
node_modules
npm-debug.log*
.git
.gitignore
.env
.env.*
*.md
Dockerfile
.dockerignore
coverage
.vscode
.idea
.DS_Store
```

It matters for three reasons:

- **Correctness.** Your local `node_modules` was built for your machine. Native modules compiled on macOS crash on
  Linux with `invalid ELF header`. Exclude it so the image always uses dependencies installed inside the build.
- **Security.** `.env` files, private keys and `.git` (which can contain credentials and your entire history) must
  never end up in an image. Once an image with a secret has been pushed, assume the secret is public. An explicit
  `COPY src ./src` protects you even without `.dockerignore`, but a later `COPY . .` would not.
- **Speed.** A smaller context transfers faster, and files that aren't in the context can't invalidate the cache.

Pattern rules worth knowing: `*` doesn't cross directories (`*.md` only matches the root), `**/*.md` matches at any
depth, and `!` re-includes something an earlier pattern excluded (`*.md` then `!README.md`). You can also keep a
Dockerfile-specific ignore file next to the Dockerfile: `Dockerfile.prod` uses `Dockerfile.prod.dockerignore` if it
exists.

## Building the image

```bash
cd examples/ch04-first-image
docker build -t snip:1.0 .
```

The first build downloads the base image and installs dependencies. BuildKit shows each step:

```text
[+] Building 6.3s (10/10) FINISHED                                   docker:default
 => [internal] load build definition from Dockerfile                       0.0s
 => => transferring dockerfile: 406B                                       0.0s
 => [internal] load metadata for docker.io/library/node:24-slim            0.8s
 => [internal] load .dockerignore                                          0.0s
 => => transferring context: 213B                                          0.0s
 => [1/5] FROM docker.io/library/node:24-slim@sha256:a5f4366e834695d5f363  2.1s
 => [internal] load build context                                          0.0s
 => => transferring context: 33.40kB                                       0.0s
 => [2/5] WORKDIR /app                                                     0.1s
 => [3/5] COPY package.json package-lock.json ./                           0.0s
 => [4/5] RUN npm ci --omit=dev                                            2.4s
 => [5/5] COPY src ./src                                                   0.0s
 => exporting to image                                                     0.6s
 => => exporting layers                                                    0.4s
 => => exporting manifest sha256:c50d3cccfcd86b8a6321b168a0b86747b1299b3a  0.0s
 => => exporting config sha256:f5cd5f65641639dace801e6b4ac3da489963d2adfa  0.0s
 => => exporting attestation manifest sha256:9b903837f95c6b7b9899c1b55ab6  0.0s
 => => exporting manifest list sha256:8e647753dac241f7c2ff960db3323f1cb83  0.0s
 => => naming to docker.io/library/snip:1.0                                0.0s
 => => unpacking to docker.io/library/snip:1.0                             0.3s
```

Read it like this:

- The `[internal]` steps load the Dockerfile, the `.dockerignore`, the base image's metadata and the build context
  (only 33 kB here, thanks to `.dockerignore`; without it, the local `node_modules` alone adds megabytes).
- `[1/5]` to `[5/5]` are your instructions. `FROM` is resolved to an exact digest: even though you wrote a tag, the
  build records precisely which base image it used.
- The export creates the image's layers, its config, an attestation manifest (build provenance, chapter 14) and a
  manifest list, then names it `snip:1.0` and unpacks it into the image store.

`ENV`, `EXPOSE` and `CMD` don't appear as numbered steps: they only change the image configuration.

`-t snip:1.0` (`--tag`) names the image. You can give several tags at once (`-t snip:1.0 -t snip:latest`); without
one, you get an image you can only refer to by ID. If you need to see the full output of `RUN` steps (for example a
failing test), add `--progress=plain`.

```bash
docker image ls snip
```

```text
IMAGE      ID             DISK USAGE   CONTENT SIZE   EXTRA
snip:1.0   9e548a73500b        342MB         83.1MB
```

83 MB to download, of which your code and dependencies are about 4 MB; the rest is Debian and Node.js. Chapter 7 gets
it smaller.

## Running and testing it

```bash
docker run -d --name snip -p 3000:3000 snip:1.0
docker logs snip
```

```text
snip listening on port 3000 (node v24.22.0, pid 1)
```

Create a short link:

```bash
curl -i -X POST http://localhost:3000/api/links \
  -H 'content-type: application/json' \
  -d '{"url": "https://docs.docker.com/reference/dockerfile/"}'
```

```text
HTTP/1.1 201 Created
X-Powered-By: Express
Content-Type: application/json; charset=utf-8
Content-Length: 165
...

{"code":"kcxQWs4","url":"https://docs.docker.com/reference/dockerfile/","clicks":0,"createdAt":"2026-10-15T08:54:41.350Z","shortUrl":"http://localhost:3000/kcxQWs4"}
```

Follow it (use your own code) and look at the statistics:

```bash
curl -i http://localhost:3000/kcxQWs4
curl http://localhost:3000/api/links/kcxQWs4
```

```text
HTTP/1.1 302 Found
X-Powered-By: Express
Location: https://docs.docker.com/reference/dockerfile/
...

{"code":"kcxQWs4","url":"https://docs.docker.com/reference/dockerfile/","clicks":1,"createdAt":"2026-10-15T08:54:41.350Z"}
```

Now stop it and watch the graceful shutdown:

```bash
time docker stop snip
docker logs snip
docker inspect -f '{{.State.ExitCode}}' snip
```

```text
snip

real    0m0.233s

snip listening on port 3000 (node v24.22.0, pid 1)
SIGTERM received, closing the server
all connections closed, exiting
0
```

A quarter of a second and exit code 0, instead of ten seconds and 137. `node` is PID 1, but it installed a `SIGTERM`
handler, so the signal is delivered.

Remove the container with `docker rm snip`. Start a new one and your links are gone: the data lived in the process's
memory. That's the problem chapter 10 solves.

Because the image's `CMD` is only a default, you can run anything else from the same image:

```bash
docker run --rm snip:1.0 node -p 'require("./package.json").version'       # 1.0.0
docker run --rm snip:1.0 ls node_modules | head -3                          # accepts  body-parser  bytes
docker run --rm -e PORT=8080 -p 8080:8080 snip:1.0                          # configuration via env
```

## The layer cache

Run the same build again:

```bash
docker build -t snip:1.0 .
```

```text
 => CACHED [2/5] WORKDIR /app                                              0.0s
 => CACHED [3/5] COPY package.json package-lock.json ./                    0.0s
 => CACHED [4/5] RUN npm ci --omit=dev                                     0.0s
 => CACHED [5/5] COPY src ./src                                            0.0s
```

Every step says `CACHED`: BuildKit reused the layers from the previous build. Now change a line in `src/app.js` and
build again:

```text
[+] Building 0.3s (10/10) FINISHED                                   docker:default
 => [1/5] FROM docker.io/library/node:24-slim@sha256:a5f4366e834695d5f363  0.0s
 => CACHED [2/5] WORKDIR /app                                              0.0s
 => CACHED [3/5] COPY package.json package-lock.json ./                    0.0s
 => CACHED [4/5] RUN npm ci --omit=dev                                     0.0s
 => [5/5] COPY src ./src                                                   0.0s
```

Only the last `COPY` ran. The rule BuildKit follows:

> **A step is reused from the cache if the instruction is the same, the layers below it are the same, and, for
> `COPY` and `ADD`, the copied files have the same content. As soon as one step misses the cache, every step after it
> runs again.**

For `COPY`, "the same" means the same *content* (BuildKit compares checksums of the files): `touch src/app.js`
doesn't invalidate the cache, editing it does. For `RUN`, only the command *text* is compared: `RUN npm ci` is never
re-run just because the registry has new packages, which is why the lockfile matters so much.

That rule is why the Dockerfile copies `package.json` and `package-lock.json` *on their own* first. Dependencies
change rarely; code changes constantly. With the naive Dockerfile (`COPY . .` before `npm install`), changing a
single line of code invalidates the `COPY`, and therefore the `npm install` after it:

```text
 => [3/4] COPY . .                                                         0.1s
 => [4/4] RUN npm install                                                  1.1s
```

Snip has one dependency, so the reinstall takes a second. A typical production app has hundreds of packages and the
reinstall takes one to several minutes, on every build, on every developer's machine and in CI. The ordering rule,
**least frequently changed things first**, is the single most effective build optimization. Chapter 6 goes further
with cache mounts, which speed up even the builds where `package-lock.json` *did* change.

## Tagging

A tag is just a name. Use meaningful ones:

```bash
docker build -t snip:1.0 -t snip:latest .     # two names for one build
docker tag snip:1.0 snip:1.0.0                 # add another name later
docker image ls snip
```

Common schemes are semantic versions (`1.4.2`, plus moving `1.4` and `1`), Git commit SHAs (`sha-3f9c2a1`) and
branch names for preview environments. Chapter 14 builds tags automatically in CI. Locally, short names like
`snip:dev` are fine.

On an Apple Silicon Mac this image is `linux/arm64`; on a typical server it would need to be `linux/amd64`. Don't
build on your laptop and push to production; build in CI for the platforms you deploy to (chapter 14).

## What's still wrong

The image works and builds fast. Before production it still needs:

- a **non-root user** (it runs as root),
- a **smaller, leaner runtime** (no npm, no shell if possible),
- a **health check** so Docker knows when the app is actually ready,
- **labels** with version and source information.

Chapter 5 covers the instructions you need for those, chapter 6 makes the build faster, and chapter 7 produces the
production image.

## Key takeaways

- A Dockerfile is a sequence of instructions; `RUN` and `COPY` create filesystem layers, while `ENV`, `EXPOSE`, `CMD`
  and similar only change the configuration.
- The build context is what `COPY` can see. A `.dockerignore` keeps out `node_modules`, `.git`, `.env` and other
  things that make builds slow, wrong or insecure.
- Order instructions from least to most frequently changed: copy `package.json` and `package-lock.json`, run
  `npm ci`, *then* copy the source.
- Use `npm ci --omit=dev` in images: reproducible, lockfile-exact, no dev dependencies.
- Use exec form (`CMD ["node", "src/server.js"]`) and run `node` directly, not through npm or a shell, so signals
  reach your process.
- Handle `SIGTERM` in your server: stop accepting connections, finish in-flight requests, exit before the grace
  period ends.
- The cache reuses a step when its instruction, its parent layers and (for `COPY`) its files' contents are unchanged;
  the first miss rebuilds everything after it.

## Exercises

1. **Configure by environment.** Run Snip on port 8080 *inside* the container, published as port 9000 on your machine,
   and make the `shortUrl` in responses start with `https://snip.example`. Verify with `curl`.

<details>
<summary>Solution</summary>

```bash
docker run -d --rm --name snip9000 -e PORT=8080 -e BASE_URL=https://snip.example -p 9000:8080 snip:1.0
curl -s -X POST localhost:9000/api/links -H 'content-type: application/json' -d '{"url":"https://example.com"}'
docker stop snip9000
```

The response contains `"shortUrl":"https://snip.example/<code>"`. The container listens on 8080 (from `PORT`), and
`-p 9000:8080` maps host port 9000 to it. `EXPOSE 3000` in the image is only documentation and doesn't get in the way,
which is exactly why it's worth keeping accurate.

</details>

2. **Break the cache on purpose.** Swap the order so that `COPY src ./src` comes *before* `RUN npm ci --omit=dev`.
   Build twice, changing `src/app.js` in between. Which steps run the second time? Restore the original order.

<details>
<summary>Solution</summary>

With the source copied first, a change in `src/app.js` invalidates the `COPY src ./src` layer and every layer after
it, so the second build shows:

```text
 => CACHED [3/5] COPY package.json package-lock.json ./
 => [4/5] COPY src ./src
 => [5/5] RUN npm ci --omit=dev
```

`npm ci` runs again although no dependency changed. With the original order, only the final `COPY` runs. Same image,
very different build times.

</details>

3. **What does `.dockerignore` protect?** Create a file `.env` containing `SECRET=hunter2` in the project directory.
   Temporarily change the Dockerfile to use `COPY . .` and remove `.env*` from `.dockerignore`. Build, then prove that
   the secret is in the image. Undo both changes, rebuild, and prove it's gone.

<details>
<summary>Solution</summary>

```bash
echo 'SECRET=hunter2' > .env
# edit: COPY . .  and remove the .env lines from .dockerignore
docker build -t snip:leaky .
docker run --rm snip:leaky cat .env            # SECRET=hunter2
# undo both edits
docker build -t snip:1.0 .
docker run --rm snip:1.0 ls -a                 # no .env
rm .env
```

Anyone who can pull `snip:leaky` can read the secret, even if a later layer deleted the file (the layer that added it
still contains it). If this happened with a real secret and the image was pushed anywhere, rotate the secret.
Defence in depth: keep `.env*` in `.dockerignore` *and* copy only what you need.

</details>

4. **Shell form, observed.** Change `CMD` to the shell form `CMD node src/server.js && echo done` and rebuild as
   `snip:shell`. Run it, then time `docker stop`. Look at the process list with `docker top`. Explain what you see.
   Restore the exec form.

<details>
<summary>Solution</summary>

```bash
docker build -t snip:shell .
docker run -d --name sh1 snip:shell
docker top sh1          # two processes: /bin/sh -c node src/server.js && echo done, and node src/server.js
time docker stop sh1    # about 10 s
docker logs sh1         # no "SIGTERM received" line
docker inspect -f '{{.State.ExitCode}}' sh1   # 137
docker rm sh1
```

The shell form runs `/bin/sh -c "…"` as PID 1. Because the command is a list (`&&`), the shell can't simply `exec`
`node`, so it starts `node` as a child and waits. `SIGTERM` goes to the shell, which (as PID 1, without a handler)
ignores it and doesn't forward it. After the grace period Docker sends `SIGKILL` to the whole container, killing both
processes without a clean shutdown. A `docker build --check` on that Dockerfile warns about this:
`JSONArgsRecommended` (chapter 5).

</details>

5. **Add a version endpoint.** Add a `GET /version` route that returns `{"version": "<APP_VERSION>"}`, reading
   `APP_VERSION` from the environment with a default of `"dev"`. Bake `APP_VERSION=1.1.0` into the image with `ENV`,
   build `snip:1.1`, and check that `docker run -e APP_VERSION=override …` wins over the baked-in value.

<details>
<summary>Solution</summary>

In `src/app.js`, inside `createApp`:

```js
app.get('/version', (req, res) => {
  res.json({ version: process.env.APP_VERSION ?? 'dev' });
});
```

In the Dockerfile, next to `NODE_ENV`:

```dockerfile
ENV NODE_ENV=production APP_VERSION=1.1.0
```

```bash
docker build -t snip:1.1 .
docker run -d --rm --name v1 -p 3001:3000 snip:1.1
curl localhost:3001/version                     # {"version":"1.1.0"}
docker stop v1
docker run -d --rm --name v2 -p 3001:3000 -e APP_VERSION=override snip:1.1
curl localhost:3001/version                     # {"version":"override"}
docker stop v2
```

`docker run -e` overrides variables set with `ENV`. Chapter 5 shows a better way to stamp the version: a build
argument (`ARG`) passed in by CI, so the Dockerfile doesn't need editing for each release.

</details>

---
← Previous: [Images and registries](03-images-and-registries.md) | Next: [Dockerfile instructions in depth](05-dockerfile-instructions-in-depth.md) →
