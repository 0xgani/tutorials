# Chapter 7 — Multi-stage builds and small, secure images

## What you'll learn

- What multi-stage builds are and why they're the standard way to write production Dockerfiles
- How stages, `COPY --from` and `--target` work, and how BuildKit skips and parallelizes stages
- Separating production and development dependencies, and the `NODE_ENV=production` trap
- Building TypeScript (or anything that needs a build step) in one stage and running it in another
- Native modules: compiling in a full image, running in a minimal one, and glibc compatibility
- Alpine vs. Debian slim vs. distroless, measured
- Why deleting files in a later layer doesn't help, and what does
- Running as non-root in every runtime image
- Running the test suite as part of the build
- Snip's production image: a test stage and a distroless, non-root runtime

## The problem: build-time needs vs. run-time needs

Building an application often needs much more than running it:

| Needed to build | Needed to run |
|---|---|
| Dev dependencies: TypeScript, bundlers, test frameworks, linters | Production dependencies |
| Compilers and headers for native modules (`python3`, `make`, `g++`) | The compiled `.node` binaries |
| Source files, tests, configuration for tools | The compiled output |
| npm itself, Git, curl | Node.js |
| Credentials for private registries | Nothing of the sort |

In a single-stage Dockerfile, everything from the left column ends up in the image. You can try to delete it at the
end, but chapter 3 explained why that fails: a file deleted in a later layer is still in the earlier one. Here's the
proof, deleting npm, Yarn and Corepack from `node:24-slim`:

```dockerfile
FROM node:24-slim
RUN rm -rf /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/corepack \
           /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/corepack \
           /opt/yarn-* /usr/local/bin/yarn /usr/local/bin/yarnpkg
```

```text
IMAGE               ID             DISK USAGE   CONTENT SIZE   EXTRA
slim-nonpm:latest   f63ae3aff874        331MB         81.1MB
node:24-slim        b1da7caaa09b        331MB         81.1MB
```

Not a byte smaller. **Multi-stage builds** solve this properly: you build in one image and copy only the results into
a fresh one.

## Stages

Every `FROM` starts a new **stage**. A stage can be named with `AS`, and later stages can copy files from earlier ones
with `COPY --from=<stage>`:

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build                     # produces /app/dist

FROM node:24-slim AS runtime
WORKDIR /app
COPY --from=build /app/dist ./dist    # only the build output crosses over
CMD ["node", "dist/server.js"]
```

The final image is the **last stage** (unless you choose another with `--target`). Everything else in earlier stages
(compilers, dev dependencies, source, caches) is left behind; it exists only in the build cache.

Things to know about stages:

- **`COPY --from` accepts a stage name, a stage index (`--from=0`) or any image**:
  `COPY --from=gcr.io/distroless/nodejs24-debian13 /nodejs /nodejs` copies files straight out of an image.
- **`FROM` can use an earlier stage as its base**: `FROM base AS deps` continues from everything `base` did.
- **Build arguments are per stage.** An `ARG` declared before the first `FROM` is visible in every `FROM` line; inside
  each stage, redeclare it to use it (chapter 5).
- **`--target <stage>` stops the build at that stage** and makes it the output. You can build several images from one
  Dockerfile: a test image, a development image, a production image.
- **BuildKit only builds the stages the target needs**, and builds independent stages in parallel. A `test` stage
  that the final stage doesn't depend on is skipped in a normal build and runs only with `--target test`.

```text
          base ─────────┬──────────────┐
            │           │              │
           deps        build        (others)
            │  ╲          │
          test  ╲         │             BuildKit walks the graph backwards from the target:
                 ╲        │             only stages that lead to it are built,
                  runtime ◀─────────    and independent branches run in parallel.
```

## Production dependencies vs. everything

The standard pattern for Node.js uses two dependency stages:

```dockerfile
FROM node:24-slim AS base
WORKDIR /app
ENV NODE_ENV=production

# All dependencies, for building and testing
FROM base AS dev-deps
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --include=dev

# Production dependencies only, for the runtime image
FROM base AS prod-deps
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev
```

Notice `--include=dev`. **When `NODE_ENV=production` is set, `npm ci` skips dev dependencies by default**, even
without `--omit=dev`. If a base stage sets `NODE_ENV=production` and a build stage runs a plain `npm ci`, your build
tool is missing:

```text
#13 0.507 > tsc
#13 0.513 sh: 1: tsc: not found
```

Either don't set `NODE_ENV` in stages that need dev dependencies, or be explicit with `--include=dev`. Being explicit
in both directions (`--include=dev` and `--omit=dev`) makes the Dockerfile independent of the environment.

Because both dependency stages start from the same `base` and copy the same manifests, BuildKit runs them in
parallel, and the shared cache mount means packages are downloaded only once.

## A build step: TypeScript

If your app is written in TypeScript, or needs any other build step (bundling with esbuild, compiling assets), the
build stage needs dev dependencies and the runtime doesn't need the compiler:

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-slim AS base
WORKDIR /app
ENV NODE_ENV=production

FROM base AS build
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --include=dev
COPY tsconfig.json ./
COPY src ./src
RUN npm run build                                  # tsc → /app/dist

FROM base AS prod-deps
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev

FROM gcr.io/distroless/nodejs24-debian13:nonroot
WORKDIR /app
ENV NODE_ENV=production
COPY --from=prod-deps /app/node_modules ./node_modules
COPY --from=build /app/dist ./dist
COPY package.json ./
CMD ["dist/server.js"]
```

The final image contains compiled JavaScript and production dependencies, nothing else: no TypeScript, no type
definitions, no source maps unless you copy them. (Recent Node.js versions can run many `.ts` files directly by
stripping type annotations, which is convenient in development; for production images, compiling ahead of time is
still the norm, because it catches type errors in the build and starts faster.)

## Native modules

Packages with C or C++ code (`better-sqlite3`, `bcrypt`, `sharp`, `argon2`, …) either download a prebuilt binary
for your platform during `npm ci` or compile one with `node-gyp`, which needs Python, `make` and a C++ compiler. The
full `node:24` image includes all three, which makes it a convenient **build stage**, while the runtime can be
minimal:

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24 AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --build-from-source

FROM gcr.io/distroless/nodejs24-debian13:nonroot
WORKDIR /app
COPY --from=build /app/node_modules ./node_modules
CMD ["-e", "const db = new (require('better-sqlite3'))(':memory:'); console.log(db.prepare('select sqlite_version() v').get())"]
```

```bash
docker build -t native .
docker run --rm native
```

```text
{ v: '3.53.2' }
```

The compiled module, built in a 1.6 GB image with a full toolchain, runs in a 64 MB distroless image.
(`--build-from-source` forces compilation even when a prebuilt binary exists; it's here only to prove the point.
Normally you let npm download the prebuilt binary.)

The rule that makes this work: **a native module must run against the same C library it was built for, at the same
or a newer version.**

- Build on glibc (Debian), run on glibc (Debian, distroless): works if the runtime's glibc is the same or newer. Here
  the build stage is Debian 12 (glibc 2.36) and the runtime Debian 13 (glibc 2.41): fine. The other way round
  fails with errors like `` version `GLIBC_2.38' not found ``.
- Build on musl (Alpine), run on musl: fine.
- Mixing glibc and musl: fails, with `Error loading shared library` or `symbol not found` errors.
- The CPU architecture must match too: build for `linux/arm64` to run on `linux/arm64` (chapter 14).

The safest arrangement is a build stage and a runtime stage from the **same family**: `node:24` or `node:24-slim`
with a Debian runtime, or `node:24-alpine` for both. If the runtime is distroless, use a build stage on the same or an
older Debian release. If a module needs extra system libraries at runtime (`sharp` needs none, but some modules link
against `libpq`, `libvips` or similar), the runtime must have them too, which is a reason to choose `slim` over
distroless.

## Choosing the runtime image

Here is Snip built five ways, with the same code and production dependencies:

| Runtime | How | Download (compressed) | On disk | Shell | npm | Default user |
|---|---|---|---|---|---|---|
| `node:24` | single stage | 414 MB | 1.65 GB | yes | yes | root |
| `node:24-slim` | single stage | 82 MB | 339 MB | yes | yes | root |
| `node:24-alpine` | single stage | 65 MB | 256 MB | yes | yes | root |
| `node:24-slim` | multi-stage | 82 MB | 336 MB | yes | yes | root |
| `gcr.io/distroless/nodejs24-debian13:nonroot` | multi-stage | **56 MB** | **220 MB** | no | no | nonroot (65532) |

Observations:

- The full `node:24` image is five times the size of the others. It's a build image, never a runtime image.
- **Multi-stage on the same base barely helps Snip**, because Snip has no build step, no dev dependencies and no
  native modules: there's little to leave behind. For a TypeScript app with a large dev toolchain, the difference is
  typically hundreds of megabytes. And the multi-stage version still contains npm, because npm is part of the
  `node:*-slim` image.
- Most of the remaining size is Node.js itself (about 120 MB uncompressed).
- Distroless is the smallest and contains the fewest packages: no shell, no package manager, no npm. Fewer packages
  means fewer vulnerability reports to triage and fewer tools for an attacker.

### Distroless: what you give up

Google's distroless images contain only what a program needs to run: for Node.js, the `node` binary, glibc, OpenSSL's
libraries, CA certificates and time zone data. That's the point, and it changes how you work:

- **No shell.** `docker exec -it snip sh` fails with `exec: "sh": executable file not found in $PATH`. Shell-form
  `RUN`, `CMD` and `HEALTHCHECK` don't work; only exec form. You can't `RUN` anything in a distroless stage at all, so
  all preparation happens in earlier stages.
- **`node` is at `/nodejs/bin/node`**, which isn't on the `PATH`. The image's `ENTRYPOINT` is `["/nodejs/bin/node"]`,
  so your `CMD` holds only the arguments: `CMD ["src/server.js"]`. A health check must use the full path.
- **Debugging needs other tools**: a `:debug` tag variant that adds a BusyBox shell, or attaching a debug container
  that shares the process namespace (chapter 19).
- **Tags**: `latest` runs as root; `nonroot` runs as UID 65532; `debug` and `debug-nonroot` add BusyBox.

Docker's own **Docker Hardened Images** (free since December 2025, at `dhi.io` and on Docker Hub) are another family
of minimal, non-root, regularly patched images, with Node.js variants. Chainguard publishes a third family. The
principles are the same: no shell or package manager in the runtime variant, non-root by default, a separate `-dev`
variant for build stages.

Which to choose? A reasonable default:

- **Runtime: distroless (or a hardened image)** if your app runs on plain Node.js and you're comfortable debugging
  with a debug container.
- **Runtime: `node:24-slim` with `USER node`** if you need system libraries, want a shell for operations, or your team
  is new to containers.
- **Alpine** if you've tested your dependencies on musl and value the smaller download, using Alpine for the build
  stage too.

## Running as non-root, everywhere

Whatever the runtime, the final stage must not run as root:

- `node:*` images: add `USER node` (UID 1000).
- distroless: use the `:nonroot` tag (UID 65532), or `USER 65532`.
- Hardened images run as non-root by default.

With `COPY --from` the copied files belong to root unless you add `--chown`. That's what you want for code and
dependencies: readable by everyone, writable only by root. If the app needs a writable directory, create it in a
build stage with the right owner and copy it, or (better) mount a volume or a tmpfs there at run time (chapter 8).

```dockerfile
# A writable directory owned by the runtime user, prepared in a stage that has a shell
FROM base AS dirs
RUN mkdir -p /out/data

FROM gcr.io/distroless/nodejs24-debian13:nonroot
COPY --from=dirs --chown=65532:65532 /out/data /app/data
```

The `--chown` matters even though the directory is empty. `COPY` copies a directory's *contents*; the destination
directory itself is created fresh, owned by root unless `--chown` says otherwise. A `chown` in the earlier stage
would be lost.

## Tests as a build stage

A stage can run the test suite. If a test fails, `RUN` fails and so does the build:

```dockerfile
FROM deps AS test
COPY src ./src
COPY test ./test
RUN node --test
```

Because nothing depends on the `test` stage, a normal `docker build` skips it. Run it explicitly:

```bash
docker build --target test .
```

```text
#11 [test 3/3] RUN node --test
#11 0.477 ✔ GET /healthz answers ok (48.659892ms)
#11 0.497 ✔ creates a link and redirects to it (23.157131ms)
#11 0.501 ✔ rejects URLs that are not http(s) (4.402093ms)
#11 0.504 ✔ unknown codes are 404 (3.247344ms)
#11 0.585 ℹ tests 4
#11 0.585 ℹ pass 4
#11 0.585 ℹ fail 0
```

(Like every `RUN`, the test step is cached: if neither the code nor the tests changed, it reports `CACHED` and
doesn't re-run, which is correct. Use `--no-cache-filter test` to force it.)

Tests in the build are ideal for **unit tests**, which need nothing but the code. Tests that need a real database
belong in a Compose setup (chapter 12) or in CI (chapter 14). Chapter 14 runs this stage in CI before building the
production image.

## Snip's production image

The complete project is in [`examples/ch07-production-image/`](examples/ch07-production-image/). Compared with
chapter 4, it adds `src/healthcheck.js` (chapter 5), a `test/` directory and `"test": "node --test"` in
`package.json`. The test file starts Snip on a random port with the in-memory store and talks to it with `fetch`:

```js
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createApp } from '../src/app.js';
import { createMemoryStore } from '../src/memory-store.js';

let server;
let base;

before(async () => {
  const app = createApp({ store: createMemoryStore(), baseUrl: 'http://snip.test' });
  server = app.listen(0);
  await new Promise((resolve) => server.once('listening', resolve));
  base = `http://127.0.0.1:${server.address().port}`;
});

after(() => server.close());

test('creates a link and redirects to it', async () => {
  const created = await fetch(`${base}/api/links`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: 'https://example.com/a/long/path' }),
  });
  assert.equal(created.status, 201);
  const { code, shortUrl } = await created.json();
  assert.match(code, /^[\w-]{7}$/);
  assert.equal(shortUrl, `http://snip.test/${code}`);

  const redirect = await fetch(`${base}/${code}`, { redirect: 'manual' });
  assert.equal(redirect.status, 302);
  assert.equal(redirect.headers.get('location'), 'https://example.com/a/long/path');

  const stats = await (await fetch(`${base}/api/links/${code}`)).json();
  assert.equal(stats.clicks, 1);
});

// ... plus tests for /healthz, invalid URLs and unknown codes
```

And the Dockerfile:

```dockerfile
# syntax=docker/dockerfile:1
# check=error=true

# Snip, chapter 7: a multi-stage build with a test stage and a distroless runtime.
#   docker build -t snip:1.2 .                   final image (distroless)
#   docker build --target test .                 run the tests
#   docker build --target runtime-slim -t snip:1.2-slim .   Debian slim runtime with a shell

ARG NODE_VERSION=24

# base: settings shared by the Node-based stages
FROM node:${NODE_VERSION}-slim AS base
WORKDIR /app
ENV NODE_ENV=production

# deps: production dependencies only, installed once and copied into the runtime stages
FROM base AS deps
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev

# test: production dependencies plus the test files; the build fails if a test fails
FROM deps AS test
COPY src ./src
COPY test ./test
RUN node --test

# runtime-slim: a Debian slim runtime, for when you need a shell in production
FROM base AS runtime-slim
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY src ./src
USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --start-interval=1s --retries=3 \
  CMD ["node", "src/healthcheck.js"]
CMD ["node", "src/server.js"]

# runtime: the default target. Distroless: Node.js and its runtime libraries, no shell,
# no package manager, running as the unprivileged "nonroot" user (UID 65532).
FROM gcr.io/distroless/nodejs${NODE_VERSION}-debian13:nonroot AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY src ./src

ARG APP_VERSION=dev
ARG GIT_SHA=unknown
LABEL org.opencontainers.image.title="snip" \
      org.opencontainers.image.description="A tiny URL shortener" \
      org.opencontainers.image.source="https://github.com/example/snip" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${GIT_SHA}"
ENV APP_VERSION=${APP_VERSION}

EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --start-interval=1s --retries=3 \
  CMD ["/nodejs/bin/node", "src/healthcheck.js"]
# The distroless image's entrypoint is /nodejs/bin/node, so CMD holds only its arguments.
CMD ["src/server.js"]
```

Points worth noticing:

- **`package.json` is copied into the runtime** because it carries `"type": "module"`, which tells Node.js to treat
  `src/*.js` as ES modules.
- **`ARG NODE_VERSION` before the first `FROM`** parameterizes both the build base and the distroless tag (the
  distroless naming scheme, `nodejs24`, happens to fit).
- **Build arguments for labels come last** in the runtime stage, so a new Git SHA doesn't invalidate anything else.
- **The test stage is independent**: `docker build` skips it; CI runs it with `--target test`.
- `runtime-slim` and `runtime` share the `deps` stage, so building both costs one `npm ci`.

Build and run it:

```bash
cd examples/ch07-production-image
docker build -t snip:1.2 .
docker run -d --name snip -p 3000:3000 snip:1.2
docker ps --format 'table {{.Names}}\t{{.Status}}'
```

```text
NAMES     STATUS
snip      Up 3 seconds (healthy)
```

```bash
docker exec snip sh
```

```text
OCI runtime exec failed: exec failed: unable to start container process: exec: "sh": executable file not found in $PATH
```

```bash
docker exec snip /nodejs/bin/node -p 'process.getuid() + " " + process.env.NODE_ENV'
docker image history snip:1.2 --format '{{.Size}}\t{{.CreatedBy}}' | head -14
```

```text
65532 production
0B      CMD ["src/server.js"]
0B      HEALTHCHECK {Test:[CMD /nodejs/bin/node src/…
0B      EXPOSE [3000/tcp]
0B      ENV APP_VERSION=dev
0B      LABEL org.opencontainers.image.title=snip or…
0B      ARG GIT_SHA=unknown
0B      ARG APP_VERSION=dev
28.7kB  COPY src ./src # buildkit
12.3kB  COPY package.json ./ # buildkit
4.48MB  COPY /app/node_modules ./node_modules # buil…
0B      ENV NODE_ENV=production
8.19kB  WORKDIR /app
127MB   bazel build @nodejs24_amd64//:data
156kB   bazel build @trixie//gcc-14-base/amd64:data_…
```

The history tells the story: distroless layers built with Bazel (Node.js is the 127 MB one), then exactly three
layers of yours, adding 4.5 MB in total. Nothing from the `deps` or `test` stages leaked in. `docker stop snip` still
takes a quarter of a second, because the graceful shutdown from chapter 4 is in the code, not in the image.

## Key takeaways

- Deleting files in a later layer never makes an image smaller. Multi-stage builds do: build in one stage, `COPY
  --from` only the results into a fresh runtime stage.
- The final stage is the image (or the one chosen with `--target`); BuildKit skips stages the target doesn't need and
  runs independent stages in parallel.
- Use a `prod-deps` stage with `npm ci --omit=dev` for the runtime and `npm ci --include=dev` wherever a build or tests
  need dev dependencies. `NODE_ENV=production` silently omits dev dependencies.
- Compile native modules in a full image and run them in a minimal one of the same libc family, with the same or a
  newer glibc, for the same CPU architecture.
- Distroless images are the smallest and most locked-down Node runtimes: no shell, `node` at `/nodejs/bin/node`,
  exec form only, `:nonroot` for UID 65532. Slim + `USER node` is the pragmatic alternative.
- Every runtime image runs as a non-root user; copied code stays owned by root.
- A `test` stage runs unit tests during the build and fails it when a test fails; run it with `--target test`.

## Exercises

1. **Stage arithmetic.** In Snip's Dockerfile, which stages are built by (a) `docker build .`, (b)
   `docker build --target test .`, (c) `docker build --target runtime-slim .`? Which can run in parallel?

<details>
<summary>Solution</summary>

(a) `base`, `deps`, `runtime`. The distroless base image is pulled in parallel with `base`/`deps`. `test` and
`runtime-slim` are skipped.

(b) `base`, `deps`, `test`.

(c) `base`, `deps`, `runtime-slim`.

In every case `deps` needs `base`, and the stage after it needs `deps`, so they're sequential; what runs in parallel is
pulling the distroless image and the build context transfer. In a Dockerfile with separate `dev-deps` and `prod-deps`
stages, the two `npm ci` steps would run in parallel.

</details>

2. **Find the leftovers.** Build `--target runtime-slim` and the default target. For each, list three things present in
   the image that the app never uses at runtime (hint: `docker run --rm --entrypoint sh snip:1.2-slim -c 'ls
   /usr/local/bin /usr/local/lib/node_modules'`). Why can't you list anything for the distroless image the same way?

<details>
<summary>Solution</summary>

In `runtime-slim`: `npm` and `npx` (in `/usr/local/lib/node_modules/npm`, about 18 MB), Corepack, Yarn
(`/opt/yarn-v1.22.22`), `apt`/`dpkg`, a shell and coreutils, `perl-base`. They come with `node:24-slim` and can only be
removed by not using that image as the runtime.

In the distroless image you can't run `sh`, `ls` or anything else that isn't there. You can inspect it from outside:

```bash
docker create --name peek snip:1.2
docker export peek | tar t | grep -E '^(bin|usr/bin)/' | head    # almost nothing
docker rm peek
```

or with a debug container sharing its filesystem view (chapter 19).

</details>

3. **TypeScript Snip.** Convert Snip's `src/` to TypeScript (or just rename `src/app.js` to `src/app.ts` and add a
   type annotation), add `typescript`, `@types/node` and `@types/express` as dev dependencies and a `tsconfig.json`
   that compiles to `dist/`, and write the Dockerfile: dev dependencies only in the build stage, compiled output and
   production dependencies in a distroless runtime. Verify that `typescript` isn't in the final image.

<details>
<summary>Solution</summary>

Use the TypeScript Dockerfile from this chapter, with the healthcheck and labels from Snip's. Make sure the build
stage uses `npm ci --include=dev` (the base sets `NODE_ENV=production`). Check the result:

```bash
docker build -t snip:ts .
docker run --rm snip:ts -e 'try { require.resolve("typescript"); console.log("present") } catch { console.log("absent") }'
```

The distroless entrypoint is `node`, so `-e …` runs a script: it prints `absent`. (`require` works in `-e` scripts even
though the app is ESM, because `-e` code is evaluated as CommonJS by default.)

</details>

4. **Alpine all the way.** Write a variant of Snip's Dockerfile that uses `node:24-alpine` for every stage, runs as
   `node`, and has a working health check. Compare its size with the distroless image. What would you have to check
   before switching a real project to it?

<details>
<summary>Solution</summary>

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-alpine AS base
WORKDIR /app
ENV NODE_ENV=production

FROM base AS deps
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked npm ci --omit=dev

FROM base AS runtime
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY src ./src
USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s CMD ["node", "src/healthcheck.js"]
CMD ["node", "src/server.js"]
```

It's about 65 MB compressed, a bit larger than distroless (56 MB) because it still contains npm, Yarn and BusyBox.
Before switching a real project, check that every native dependency has a musl build or compiles on Alpine, run the
full test suite in the Alpine image (not just on your laptop), and watch for DNS or performance differences in staging
(chapter 3).

</details>

5. **A writable directory in distroless.** Suppose Snip had to write temporary exports to `/app/exports`. Make that
   work in the distroless image without running as root, using (a) a directory prepared in a build stage, and (b) a
   tmpfs mount at run time. Which do you prefer, and why?

<details>
<summary>Solution</summary>

(a) In the Dockerfile:

```dockerfile
FROM base AS dirs
RUN mkdir -p /out/exports

FROM gcr.io/distroless/nodejs24-debian13:nonroot AS runtime
# ...
COPY --from=dirs --chown=65532:65532 /out/exports /app/exports
```

(b) No Dockerfile change; at run time:

```bash
docker run --tmpfs /app/exports:uid=65532,gid=65532,mode=0700 snip:1.2
```

(b) is usually better: the image stays read-only friendly (chapter 16 runs containers with `--read-only`), data in
tmpfs never touches the container's writable layer, and it's cleared on restart. If the exports must survive restarts,
use a named volume instead (chapter 8).

</details>

---
← Previous: [The build cache and BuildKit](06-build-cache-and-buildkit.md) | Next: [Storage: volumes, bind mounts and tmpfs](08-storage-volumes-bind-mounts-and-tmpfs.md) →
