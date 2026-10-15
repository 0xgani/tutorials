# Chapter 6 — The build cache and BuildKit

## What you'll learn

- How BuildKit turns a Dockerfile into a graph and runs it
- Exactly when a step is reused from the cache and when it isn't, including the build-argument trap
- How to force fresh builds, and why a cached `RUN` can hide stale data
- Cache mounts, so that `npm ci` stays fast even when the lockfile changes
- Bind, secret, SSH and tmpfs mounts for `RUN`
- Build contexts: local, remote, standard input and named contexts
- Builders and drivers, and where the cache lives
- Exporting and importing the cache, which CI needs (chapter 14)
- Build outputs other than images
- How to see what a build is doing, and how to debug one

## How BuildKit builds

When you run `docker build`, the Docker CLI hands the job to **BuildKit**, the build engine inside the Engine (or in
a separate builder, see below). BuildKit doesn't execute your Dockerfile line by line like a shell script. The
Dockerfile frontend first converts it into a **graph** of operations (called LLB, low-level build definition), where
each operation knows its inputs. Then BuildKit:

- computes a **cache key** for every operation from its definition and its inputs,
- skips operations whose key it has seen before and whose result it still has,
- runs independent operations **in parallel** (stages that don't depend on each other, chapter 7),
- **skips stages** that the requested target doesn't need,
- requests only the **files from the build context** that `COPY` or `ADD` actually use, and on later builds only the
  files that changed.

So a build is a function from inputs to an image, and the cache is memoization. To make builds fast, you design the
Dockerfile so that cheap-to-check inputs change often and expensive steps depend only on inputs that change rarely.

## When is a step cached?

Chapter 4 gave the short rule: a step is reused if its instruction, its parent layer and (for `COPY`) its files are
unchanged; the first miss rebuilds everything after it. In more detail:

| Step | Its cache key depends on |
|---|---|
| `FROM image:tag` | The digest the tag resolves to. BuildKit checks the registry for a newer digest only if you pass `--pull` (or the image isn't local). |
| `COPY` / `ADD` (local files) | Checksums of the files' contents and their permission bits and ownership. **Not** modification times. |
| `ADD <url>` | The downloaded content (BuildKit uses HTTP caching headers to avoid re-downloading unchanged files) |
| `RUN` | The command **text**, the environment (`ENV` and build arguments in scope), the user, the working directory, and the contents of any `--mount` sources. **Not** what the command does or downloads. |
| `ENV`, `WORKDIR`, `USER`, `LABEL`, … | Their values; changing them changes the key of every later step |

Three consequences surprise almost everyone.

### 1. `RUN` doesn't notice the outside world

`RUN apt-get update && apt-get install -y …` or `RUN git clone https://…` are cached after the first build, forever,
because the *command text* doesn't change. Your image keeps the package versions from the day the cache entry was
created. Ways to refresh:

```bash
docker build --pull -t snip .                 # check for a newer base image
docker build --no-cache -t snip .             # ignore the cache entirely
docker build --no-cache-filter deps -t snip . # ignore it only for the stage named "deps" (chapter 7)
```

In CI, a periodic (say weekly) `--pull --no-cache` build picks up security fixes in OS packages and base images even
when your code doesn't change. Chapter 14 sets that up.

### 2. Changing a build argument invalidates every later `RUN`

Build arguments are part of the environment of every `RUN` after the `ARG` line, *whether or not the command uses
them*:

```dockerfile
FROM node:24-alpine
RUN echo step-a
ARG APP_VERSION=dev
RUN echo step-b
RUN echo "step-c $APP_VERSION"
```

Build with `--build-arg APP_VERSION=1`, then with `--build-arg APP_VERSION=2`:

```text
#5 [2/4] RUN echo step-a
#5 CACHED
#6 [3/4] RUN echo step-b
#7 [4/4] RUN echo "step-c 2"
```

`step-b` ran again although it never mentions `APP_VERSION`. Now imagine `ARG GIT_SHA` declared at the top of the
Dockerfile, with a different value on every commit: `npm ci` would run on every single build. **Declare build
arguments as late as possible**, right before the step that needs them. That's why chapter 5's Snip Dockerfile
declares `APP_VERSION` and `GIT_SHA` after `npm ci` and `COPY src`.

### 3. The context decides what `COPY` sees

`COPY . .` makes the step depend on *every* file in the context, including ones the app doesn't need (a README, test
fixtures, a log file your editor writes). Copy only what you need, and keep `.dockerignore` tidy.

## Cache mounts: fast installs even when dependencies change

Layer caching makes `npm ci` free when `package-lock.json` is unchanged. When it *does* change (you add one
package), `npm ci` starts from zero and downloads every package again, because npm's download cache
(`~/.npm/_cacache`) was in a temporary container and is gone.

A **cache mount** gives a `RUN` step a directory that persists *between builds*, managed by BuildKit and never
stored in the image:

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-slim
ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev
COPY src ./src
USER node
CMD ["node", "src/server.js"]
```

Now, when the lockfile changes, `npm ci` still reinstalls (it must), but packages already in the cache are copied
from disk instead of downloaded. On a real project with hundreds of packages that turns a multi-minute step into
seconds; on a slow or metered connection the difference is even larger. The cache directory isn't part of any layer,
so you no longer need `npm cache clean --force`.

The options:

| Option | Meaning |
|---|---|
| `target=/root/.npm` | Where the cache appears in the build container (npm's cache directory for root) |
| `id=npm` | Name of the cache; defaults to the target path. Steps with the same `id` share it |
| `sharing=shared\|private\|locked` | Concurrent builds: share freely (default), give each its own copy, or wait for each other. `locked` is safest for npm |
| `uid=1000,gid=1000`, `mode=0755` | Owner and mode of the cache directory, needed when the step runs as a non-root `USER` |
| `from=stage`, `source=path` | Seed the cache from another stage |
| `readonly` / `ro` | Mount read-only |

The cache directory for other tools: pnpm uses `/root/.local/share/pnpm/store` (or set `store-dir`), Yarn 4 uses
`/root/.yarn/berry/cache`, apt uses `/var/cache/apt` and `/var/lib/apt` (with `sharing=locked`; Debian images also
need their `docker-clean` apt hook disabled for apt's cache to be kept).

Cache mounts live in the **builder's** cache. They survive between builds on the same machine, are removed by
`docker builder prune`, and are *not* exported with `--cache-to` (below). In CI, where each job may start with an
empty builder, they help less; layer caching from a registry helps more.

## Other `RUN --mount` types

### Bind mounts

`type=bind` makes files from the build context (or another stage) visible to a single `RUN` without copying them
into a layer:

```dockerfile
RUN --mount=type=bind,source=package.json,target=package.json \
    --mount=type=bind,source=package-lock.json,target=package-lock.json \
    --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev
```

This is the pattern in Docker's official Node.js guide: the manifests are used for the install but don't become a
layer. The step's cache key still includes their contents, so caching works as before. The catch: `package.json` is
then **not** in the final image. If your app reads it at runtime (`require('./package.json').version`, or tools that
look for it to detect ESM with `"type": "module"`), copy it in later or keep the plain `COPY`. Snip relies on
`"type": "module"` in `package.json`, so its Dockerfile keeps the `COPY`.

Bind mounts are read-only by default; with `rw`, writes are allowed but discarded after the step.

### Secret mounts

Some builds need credentials: a token for a private npm registry, a key to download a licensed package. Chapter 5
showed that `ARG` and `ENV` leak them into the image. A **secret mount** exposes a secret to one `RUN` step only, as
a file or (Dockerfile 1.10+) an environment variable, and it never touches a layer or the build history:

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-slim
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=secret,id=npmrc,target=/root/.npmrc,required=true \
    --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev
```

```bash
docker build --secret id=npmrc,src=$HOME/.npmrc -t snip .
```

The secret file appears at `/root/.npmrc` while `npm ci` runs, so npm authenticates with the registry, and vanishes
afterwards. You can pass secrets from files or from environment variables:

```bash
docker build --secret id=npmrc,src=./npmrc.secret …         # from a file
API_KEY=… docker build --secret id=api_key,env=API_KEY …     # from your shell's environment
```

and consume them as a file or an environment variable:

```dockerfile
RUN --mount=type=secret,id=npmrc,target=/root/.npmrc   npm ci              # as a file
RUN --mount=type=secret,id=api_key,env=API_KEY         ./download.sh       # as $API_KEY
```

A test that proves the point:

```dockerfile
FROM node:24-alpine
WORKDIR /app
RUN --mount=type=secret,id=npmrc,target=/root/.npmrc \
    echo "token file present: $(test -f /root/.npmrc && echo yes)" && wc -c < /root/.npmrc
RUN --mount=type=secret,id=api_key,env=API_KEY \
    echo "API_KEY has ${#API_KEY} characters"
RUN ls -la /root/.npmrc 2>&1 || true
```

```text
#6 0.195 token file present: yes
#6 0.196 52
#7 0.227 API_KEY has 12 characters
#8 0.261 ls: /root/.npmrc: No such file or directory
```

The file existed during its step and is gone in the next, and `docker history --no-trunc` contains no trace of the
values. Note **`required=true`**: without it, a build where you forgot `--secret` doesn't fail at the mount; the
file is simply missing, and you get a confusing error from the tool (or worse, a silent anonymous install).

Secrets don't affect the cache key. Changing a token's value doesn't rebuild the step, which is usually what you
want.

### SSH mounts

To install dependencies from private Git repositories over SSH (`"my-lib": "git+ssh://git@github.com/acme/my-lib.git"`
in `package.json`), forward your SSH agent instead of copying a key into the build:

```dockerfile
RUN --mount=type=ssh \
    mkdir -p -m 0700 ~/.ssh && ssh-keyscan github.com >> ~/.ssh/known_hosts \
 && npm ci --omit=dev
```

```bash
docker build --ssh default -t snip .        # uses $SSH_AUTH_SOCK
```

The key never leaves your agent; the build only gets a socket for the duration of that step. (The image needs
`git` and an SSH client, so this usually happens in a build stage based on the full `node:24` image, chapter 7.)

### tmpfs mounts

`--mount=type=tmpfs,target=/tmp` gives a step an in-memory scratch directory that's never written to a layer, useful
for tools that write large temporary files.

## Build contexts

The last argument of `docker build` is the context, and it doesn't have to be a local directory:

```bash
docker build .                                                   # local directory
docker build -f docker/Dockerfile.prod .                         # Dockerfile elsewhere, context still "."
docker build -f- . < Dockerfile                                  # Dockerfile from standard input
docker build - < Dockerfile                                      # no context at all (COPY impossible)
docker build https://github.com/acme/snip.git#v1.2.0             # a Git repository at a tag
docker build https://github.com/acme/snip.git#main:services/api  # a branch and subdirectory
docker build https://example.com/context.tar.gz                  # a remote tarball
```

Building from a Git URL is handy for reproducing exactly what's in a release tag without touching your working copy.

### Named contexts

A Dockerfile can use **additional** named contexts besides the main one. Declare them on the command line and refer
to them with `COPY --from` or `FROM`:

```bash
docker build --build-context shared=../shared-lib -t snip .
docker build --build-context node=docker-image://node:24-slim@sha256:a5f4… -t snip .
```

```dockerfile
COPY --from=shared /src ./vendor/shared-lib     # files from ../shared-lib, outside the main context
FROM node AS base                               # "node" now resolves to the pinned image above
```

This solves two classic problems without making the whole parent directory the context: sharing code between
services in a monorepo, and overriding a base image (for testing a new Node version, or pointing at an internal
mirror) without editing the Dockerfile.

## Builders and drivers

BuildKit runs inside a **builder**. `docker buildx ls` lists yours:

```text
NAME/NODE     DRIVER/ENDPOINT   STATUS    BUILDKIT   PLATFORMS
default*      docker
 \_ default    \_ default       running   v0.33.1    linux/amd64 (+4)
```

| Driver | What it is | When |
|---|---|---|
| `docker` (default) | BuildKit built into the Docker Engine | Everyday local builds; results land directly in the image store |
| `docker-container` | BuildKit in a container that buildx starts for you | Newer BuildKit versions, multi-platform builds with the classic image store, all cache backends, custom configuration |
| `kubernetes` | BuildKit pods in a cluster | Shared, scalable build capacity |
| `remote` | A BuildKit daemon you run yourself | Dedicated build machines |
| `cloud` | Docker Build Cloud | Managed remote builders with a shared cache (a paid service) |

```bash
docker buildx create --name ctr --driver docker-container --use   # create and select a builder
docker buildx inspect --bootstrap                                  # start it, show its BuildKit version and platforms
docker buildx ls
docker buildx use default                                          # switch back
docker buildx rm ctr
```

With a `docker-container` builder, results stay in the builder's own cache until you export them: add `--load` to put
the image into Docker's image store, or `--push` to send it to a registry. Plain `docker build` uses whichever builder
is selected, so it's worth knowing which one that is when an image "disappears".

### Where the cache lives, and how to clean it

The build cache lives in the builder. Inspect and prune it:

```bash
docker buildx du                         # disk used by the cache (add --verbose for every entry)
docker builder prune                     # remove cache not used by any image… and cache mounts
docker builder prune --filter until=72h  # only entries unused for three days
docker builder prune --max-used-space 10GB   # trim to at most 10 GB
```

BuildKit garbage-collects automatically when the cache exceeds its limits (configurable in
`/etc/docker/daemon.json` under `"builder": {"gc": {…}}`, or in Docker Desktop's settings), so on a developer machine
you rarely need to prune by hand, except to free space now.

## Exporting and importing the cache

The local cache is perfect on your laptop. In CI, every job may start on a fresh machine with an empty cache, and
every build is a full build. The fix is to **export** the cache to somewhere durable and **import** it in the next
build:

```bash
docker build \
  --cache-to   type=registry,ref=ghcr.io/acme/snip:buildcache,mode=max \
  --cache-from type=registry,ref=ghcr.io/acme/snip:buildcache \
  -t ghcr.io/acme/snip:1.2.0 --push .
```

| Backend | Stores the cache in | Notes |
|---|---|---|
| `inline` | The image itself (metadata in the pushed image) | Simplest; `mode=min` only |
| `registry` | A separate image (tag) in a registry | The usual choice; supports `mode=max` |
| `local` | A directory | For CI systems with a cache directory feature |
| `gha` | GitHub Actions' cache service | Convenient in GitHub Actions (chapter 14) |
| `s3`, `azblob` | Object storage | Self-hosted CI on AWS or Azure |

**`mode=min`** (the default) exports only the layers of the final image. **`mode=max`** also exports the layers of
intermediate stages, which is what makes multi-stage builds (chapter 7) fast in CI: the stage that runs `npm ci` with
all dev dependencies isn't part of the final image, so with `mode=min` it would be rebuilt every time.

The `docker` driver with the containerd image store handles `inline`, `local` and `registry`; the `docker-container`
driver supports all of them. Here's a local export and its OCI layout on disk:

```bash
docker build --cache-to type=local,dest=/tmp/bkcache,mode=max -t snip .
ls /tmp/bkcache                       # blobs  index.json  ingest  oci-layout
docker build --cache-from type=local,src=/tmp/bkcache -t snip .
```

## Build outputs

An image isn't the only thing a build can produce. `--output` (`-o`) chooses the exporter:

```bash
docker build -t snip .                                    # image in the local store (default)
docker build -t ghcr.io/acme/snip:1.2.0 --push .          # image pushed to a registry
docker build --output type=local,dest=out .               # the final stage's files, into ./out
docker build --output - . | tar t                         # the same as a tar stream on stdout
docker build --output type=oci,dest=snip.tar -t snip .    # an OCI image archive
```

The local exporter turns BuildKit into a general build tool: a stage can compile, test or generate something, and you
extract the result. For example, a Dockerfile that counts the packages in the lockfile and exports only a text file:

```dockerfile
FROM node:24-alpine AS report
WORKDIR /work
COPY package-lock.json .
RUN node -e 'const l=require("./package-lock.json"); console.log(Object.keys(l.packages).length-1, "packages")' > deps.txt
FROM scratch
COPY --from=report /work/deps.txt /
```

```bash
docker build --output type=local,dest=out -f Dockerfile.report .
cat out/deps.txt
```

```text
78 packages
```

`FROM scratch` is an empty image, so the export contains nothing but the file. Chapter 14 uses this to extract test
reports from a build.

## Seeing and debugging what a build does

### Progress output

In a terminal, BuildKit shows a compact, live view and collapses finished steps. To see all output, including
everything a `RUN` prints:

```bash
docker build --progress=plain -t snip .
BUILDKIT_PROGRESS=plain docker build -t snip .   # same, via the environment (handy in CI)
```

In plain output, every line is prefixed with the step number and the elapsed seconds (`#7 0.195 …`), and cached steps
say `CACHED`. When a step fails, BuildKit prints the last lines of its output and the failing instruction in context:

```text
 > [4/5] RUN npm ci --omit=dev:
1.105 npm error code EUSAGE
1.105 npm error The `npm ci` command can only install with an existing package-lock.json …
------
Dockerfile:9
--------------------
   8 |     COPY package.json ./
   9 | >>> RUN npm ci --omit=dev
--------------------
ERROR: failed to build: failed to solve: process "/bin/sh -c npm ci --omit=dev" did not complete successfully: exit code: 1
```

### Inspecting a Dockerfile without building

```bash
docker build --check .            # lint (chapter 5)
docker build --call=outline .     # build arguments, with defaults and descriptions
docker build --call=targets .     # stages you can build with --target
```

Descriptions come from comments directly above an `ARG` or `FROM … AS` line, starting with its name:

```dockerfile
# APP_VERSION is the release version stamped into labels
ARG APP_VERSION=dev
```

```text
BUILD ARG     VALUE   DESCRIPTION
APP_VERSION   dev     is the release version stamped into labels
```

### Build history

BuildKit keeps a record of recent builds, including logs and timing for every step:

```bash
docker buildx history ls                 # recent builds, their status and duration
docker buildx history logs <build-id>    # the full log of one
docker buildx history inspect <build-id> # which steps were cached, how long each took, errors
```

```text
BUILD ID                    NAME                 STATUS      CREATED AT        DURATION
ra8yuyh7tvn7ple0lvsul64ta   snip/Dockerfile      Completed   8 seconds ago     0.1s
mcsw07zs9iayvtedizfc9za13   snip/Dockerfile      Error       17 seconds ago    0.3s
```

That's useful when a build failed in a terminal you've since closed, or to find out which step slowed down.

### Debugging a failing step

The quickest technique: build up to the stage before the failure, then explore interactively:

```bash
docker build --target deps -t snip:debug .       # stop at a named stage (chapter 7)
docker run --rm -it snip:debug sh                # look around, run the failing command by hand
```

> **Just a taste — you don't need to learn or fully understand this now.** Buildx also has an experimental
> interactive debugger that drops you into a shell at the failing step.
>
> ```bash
> BUILDX_EXPERIMENTAL=1 docker buildx debug --on=error build .
> ```

## Key takeaways

- BuildKit builds a dependency graph, runs independent work in parallel, skips unneeded stages and transfers only the
  context files it needs.
- `COPY` is cached by file *content*; `RUN` by command *text* and environment. A cached `RUN apt-get update` or
  `git clone` never sees new data: use `--pull` and periodic `--no-cache` builds.
- A changed build argument invalidates every `RUN` after its declaration, even ones that don't use it. Declare `ARG`s
  as late as possible.
- `RUN --mount=type=cache,target=/root/.npm` keeps npm's download cache between builds, so dependency changes don't
  mean downloading everything again.
- Secret mounts (`--secret` + `--mount=type=secret,required=true`) and SSH mounts give a single step credentials
  without leaving a trace in the image.
- Builders (`docker`, `docker-container`, remote, cloud) hold the cache; `docker buildx du` and `docker builder prune`
  manage it.
- In CI, export the cache (`--cache-to type=registry|gha,mode=max`) and import it (`--cache-from`).
- `--output` exports files instead of an image; `--progress=plain`, `--call=outline`, `docker buildx history` and
  `--target` help you understand and debug builds.

## Exercises

1. **Predict the cache.** Given this Dockerfile, which steps run again when you (a) edit `src/app.js`; (b) add a
   dependency; (c) build with a different `--build-arg GIT_SHA=…`; (d) build with `--pull` after a new
   `node:24-slim` was published?

   ```dockerfile
   FROM node:24-slim
   ARG GIT_SHA=unknown
   WORKDIR /app
   COPY package.json package-lock.json ./
   RUN npm ci --omit=dev
   COPY src ./src
   LABEL org.opencontainers.image.revision=$GIT_SHA
   CMD ["node", "src/server.js"]
   ```

<details>
<summary>Solution</summary>

(a) Only `COPY src ./src` (and the metadata-only steps after it).

(b) `COPY package.json package-lock.json ./`, `RUN npm ci`, `COPY src`.

(c) `RUN npm ci --omit=dev` and everything after it. `WORKDIR` and `COPY` don't depend on build arguments, but `RUN`
does, because `GIT_SHA` is in its environment. Since `GIT_SHA` changes on every commit, `npm ci` effectively never
caches. Fix: move `ARG GIT_SHA` down to just before the `LABEL`.

(d) Everything: `FROM` resolves to a new digest, so every step's parent changes.

</details>

2. **Measure a cache mount.** Take the chapter 4 project. Build it, then add a dependency (`npm install pino`, which
   updates both manifests) and rebuild, timing the `npm ci` step with `--progress=plain`. Add a cache mount for
   `/root/.npm`, build twice more (adding another dependency, for example `zod`, before the second), and compare.
   Then run `docker run --rm <image> ls -a /root` and explain why there's no npm cache in the image.

<details>
<summary>Solution</summary>

Without the cache mount, every lockfile change downloads every package. With the mount, the first build fills the
cache and later builds copy cached packages from local disk; only new packages are downloaded. Snip's dependencies are
small, so the difference is a second or two; on projects with hundreds of dependencies it's typically minutes, and
CI machines with slow egress benefit most.

```dockerfile
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev
```

`/root/.npm` doesn't exist in the image (or exists without the cache): a cache mount is mounted over the target
directory for the duration of the step and its contents live in the builder, not in a layer. (If the image runs as
`USER node`, `ls /root` fails with "Permission denied" anyway; use `docker run --rm -u root …`.)

</details>

3. **Private registry token.** Write a Dockerfile step that installs dependencies using an `.npmrc` with an auth
   token, such that (a) the build fails clearly if the secret isn't provided, (b) the token appears nowhere in the
   image or its history. Show the commands that prove (b).

<details>
<summary>Solution</summary>

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-slim
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=secret,id=npmrc,target=/root/.npmrc,required=true \
    --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev
```

```bash
docker build -t private .                                   # ERROR: … secret npmrc: not found
docker build --secret id=npmrc,src=$HOME/.npmrc -t private .
docker history --no-trunc private | grep -c _authToken      # 0
docker run --rm private sh -c 'cat /root/.npmrc; grep -r _authToken / 2>/dev/null | head -1'   # nothing
```

With `required=true`, the missing secret is an error at the mount instead of an obscure 401 from npm halfway through
the install.

</details>

4. **A build that produces a file.** Write a Dockerfile whose output (with `--output`) is a single file
   `licenses.txt` listing the license of every production dependency of Snip (hint: `npm ls --omit=dev --all --json`,
   or read each `node_modules/*/package.json`).

<details>
<summary>Solution</summary>

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-slim AS licenses
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
RUN node <<'EOF'
const fs = require('node:fs');
const path = require('node:path');
const lines = [];
const walk = (dir) => {
  for (const name of fs.readdirSync(dir)) {
    if (name.startsWith('.')) continue;
    const p = path.join(dir, name);
    if (name.startsWith('@')) { walk(p); continue; }
    const pkg = JSON.parse(fs.readFileSync(path.join(p, 'package.json'), 'utf8'));
    lines.push(`${pkg.name}@${pkg.version}: ${pkg.license ?? 'UNKNOWN'}`);
  }
};
walk('node_modules');
fs.writeFileSync('/licenses.txt', lines.sort().join('\n') + '\n');
EOF

FROM scratch
COPY --from=licenses /licenses.txt /
```

```bash
docker build --output type=local,dest=out -f Dockerfile.licenses .
head -3 out/licenses.txt
```

```text
accepts@2.0.0: MIT
body-parser@2.3.0: MIT
bytes@3.1.2: MIT
```

`RUN node <<'EOF'` feeds the heredoc to `node` on standard input: a readable way to run a multi-line script at build
time. (A multi-line string inside `RUN node -e '…'` doesn't work, because the Dockerfile parser ends the instruction at
the first line break without a trailing backslash.)

</details>

5. **Named context.** Suppose Snip should include a `shared/` directory that lives *next to* the project, not inside
   it (`../shared`). Without changing the build context to the parent directory, make the Dockerfile copy it to
   `/app/shared`. Then override the base image with a named context pointing to `node:26-slim` without editing the
   Dockerfile.

<details>
<summary>Solution</summary>

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-slim AS base
FROM base
WORKDIR /app
COPY --from=shared . ./shared
# ... the rest as before
```

```bash
docker build --build-context shared=../shared -t snip .
docker build --build-context shared=../shared \
             --build-context node:24-slim=docker-image://node:26-slim -t snip:node26 .
```

A named context whose name matches an image reference used in `FROM` replaces that image. That's a powerful way to
test a new base image across many Dockerfiles from CI without editing any of them.

</details>

---
← Previous: [Dockerfile instructions in depth](05-dockerfile-instructions-in-depth.md) | Next: [Multi-stage builds and small, secure images](07-multi-stage-builds-and-small-images.md) →
