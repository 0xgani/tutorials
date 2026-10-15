# Chapter 5 — Dockerfile instructions in depth

## What you'll learn

- Parser directives: `# syntax=` and `# check=`, and why every Dockerfile in this tutorial starts with them
- `ARG` vs. `ENV`: build-time and run-time variables, their scopes, and the predefined platform arguments
- Variable substitution in Dockerfiles
- `RUN` in depth: shells, `pipefail`, package managers, and heredocs
- `COPY` and its options (`--chown`, `--chmod`, `--link`, `--exclude`, `--parents`), and when `ADD` is the right tool
- `USER`: running as non-root, and who owns the files
- `ENTRYPOINT` and `CMD`: how they combine, how the Node image's entrypoint works, and how to write your own
- `HEALTHCHECK`, `LABEL`, `STOPSIGNAL`, `SHELL`, `VOLUME`, `ONBUILD`
- Build checks with `docker build --check`
- An improved Snip Dockerfile with labels, a non-root user and a health check

## The instruction set at a glance

| Instruction | Effect | Creates a layer? |
|---|---|---|
| `FROM` | Start a build stage from a base image | — |
| `RUN` | Run a command at build time | Yes |
| `COPY` / `ADD` | Add files to the image | Yes |
| `WORKDIR` | Set (and create) the working directory | Yes (if it creates the directory) |
| `ARG` | Declare a build-time variable | No |
| `ENV` | Set an environment variable (build and run time) | No |
| `USER` | Set the user for later `RUN` steps and for containers | No |
| `ENTRYPOINT` / `CMD` | The command containers run | No |
| `EXPOSE` | Document a listening port | No |
| `LABEL` | Add key/value metadata | No |
| `HEALTHCHECK` | Tell Docker how to test that the container works | No |
| `STOPSIGNAL` | The signal `docker stop` sends | No |
| `SHELL` | The shell used by shell-form instructions | No |
| `VOLUME` | Declare a mount point for anonymous volumes | No |
| `ONBUILD` | Defer an instruction to images built *from* this one | No |

Instructions are case-insensitive, but the convention (and a build check) is upper case. The full reference is at
<https://docs.docker.com/reference/dockerfile/>.

## Parser directives

Special comments at the very top of a Dockerfile, before any instruction or other comment, configure the parser:

```dockerfile
# syntax=docker/dockerfile:1
# check=error=true
```

**`# syntax=`** chooses the **Dockerfile frontend**, the component that turns your Dockerfile into a build graph.
BuildKit has one built in, but it's tied to your Docker version. With `docker/dockerfile:1`, BuildKit pulls the latest
stable 1.x frontend image (it's cached, so this costs nothing after the first time) and you get the newest Dockerfile
features on any Docker version that runs BuildKit. Pin it more tightly (`docker/dockerfile:1.19`) if you want builds
never to change behaviour without you noticing. Every Dockerfile in this tutorial starts with this line.

**`# check=`** configures build checks (below): `# check=error=true` makes any check violation fail the build, and
`# check=skip=JSONArgsRecommended` turns off specific rules.

(There is also `# escape=`, which changes the escape character from `\` to `` ` ``; it exists for Windows paths.)

## `ARG` and `ENV`

Both define variables, but they live at different times:

| | `ARG` | `ENV` |
|---|---|---|
| Available during the build | Yes | Yes |
| Available in running containers | **No** | Yes |
| Set from outside | `docker build --build-arg NAME=value` | `docker run -e NAME=value` |
| Stored in the image | In the history of `RUN` steps that use it | In the image config |
| Typical use | Versions to install, the app version to stamp | Runtime configuration defaults |

```dockerfile
ARG NODE_VERSION=24
FROM node:${NODE_VERSION}-slim

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}
```

```bash
docker build --build-arg NODE_VERSION=26 --build-arg APP_VERSION=1.2.0 -t snip:1.2.0 .
```

### Scope rules that trip people up

- An `ARG` declared **before the first `FROM`** can only be used in `FROM` lines. To use its value inside the stage,
  declare it again (without a value) after the `FROM`.
- An `ARG` is in scope from the line that declares it to the end of the **stage** (each `FROM` starts a new stage,
  chapter 7). Before its declaration, `${NAME}` is empty.
- `ENV` persists into every later instruction of the stage, into stages that build `FROM` it, and into containers.

This experiment shows all three:

```dockerfile
# syntax=docker/dockerfile:1
ARG NODE_MAJOR=24
FROM node:${NODE_MAJOR}-alpine
ARG APP_VERSION=dev
ARG NODE_MAJOR
ENV GREETING="hello from ${APP_VERSION}"
RUN echo "building ${APP_VERSION} on node ${NODE_MAJOR:-unknown} for ${TARGETPLATFORM:-not-declared}"
ARG TARGETPLATFORM
ARG TARGETARCH
ARG BUILDPLATFORM
RUN echo "target=$TARGETPLATFORM arch=$TARGETARCH build=$BUILDPLATFORM"
CMD ["node", "-p", "process.env.GREETING + ' / APP_VERSION=' + process.env.APP_VERSION"]
```

```bash
docker build --progress=plain --build-arg APP_VERSION=1.2.0 -t argtest .
docker run --rm argtest
```

```text
#7 0.178 building 1.2.0 on node 24 for not-declared
#8 0.300 target=linux/amd64 arch=amd64 build=linux/amd64
hello from 1.2.0 / APP_VERSION=undefined
```

`NODE_MAJOR` was usable in the stage only because it was re-declared. `TARGETPLATFORM` was empty before its `ARG`
line. `APP_VERSION` was baked into `GREETING` (an `ENV`), but is itself `undefined` at runtime, because an `ARG` never
reaches the container.

### Predefined platform arguments

BuildKit sets these automatically; declare them with a bare `ARG` to use them inside a stage:

| Argument | Example | Meaning |
|---|---|---|
| `TARGETPLATFORM` | `linux/arm64` | The platform being built *for* |
| `TARGETOS`, `TARGETARCH`, `TARGETVARIANT` | `linux`, `arm64`, `v8` | Its parts |
| `BUILDPLATFORM`, `BUILDOS`, `BUILDARCH` | `linux/amd64` | The platform of the machine doing the build |

They matter for multi-platform builds (chapter 14), for example to download the right binary of a tool. The proxy
variables `HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY` (and lower-case forms) are also predefined: you can pass them with
`--build-arg` without declaring them, and they are kept out of the image history.

### Never put secrets in `ARG` or `ENV`

Build arguments are recorded in the image. `docker image history` on the image above shows:

```text
RUN |5 APP_VERSION=1.2.0 NODE_MAJOR=24 TARGE…
```

Every `RUN` lists the build arguments in scope with their values, so `--build-arg NPM_TOKEN=…` leaks your token to
anyone who can pull the image. `ENV` values are visible in `docker inspect`. For secrets needed during the build
(a private npm registry token), use secret mounts (chapter 6); for runtime secrets, chapter 13.

### Variable substitution

`$NAME` and `${NAME}` are expanded in `FROM`, `RUN` (by the shell), `COPY`, `ADD`, `ENV`, `ARG`, `WORKDIR`, `USER`,
`EXPOSE`, `LABEL`, `VOLUME`, `STOPSIGNAL` and `ONBUILD`, with two modifiers:

```dockerfile
ENV PORT=${PORT:-3000}           # PORT if set, else 3000
ENV DEBUG_FLAG=${DEBUG:+--inspect}  # "--inspect" if DEBUG is set, else empty
```

**Exec-form `CMD` and `ENTRYPOINT` are not expanded**: `CMD ["node", "$APP_FILE"]` passes the literal string
`$APP_FILE`. Variables in exec form are expanded only if a shell is involved, at runtime. Read them in your
application instead (`process.env.PORT`).

## `RUN` in depth

### Shells and `pipefail`

Shell-form `RUN` runs `/bin/sh -c "<command>"`. On Debian images `/bin/sh` is `dash`; on Alpine it's BusyBox `ash`.
Two consequences:

1. **Bash syntax doesn't work in Debian's `sh`** (`[[ … ]]`, arrays, `{a,b}` brace expansion, `source`).
2. **A failing command in a pipe is ignored.** The build fails only if the *last* command of a pipeline fails:

```dockerfile
FROM alpine:3
RUN false | true && echo "pipe failure ignored by sh"
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]
RUN false | true && echo "not reached"
```

```text
#5 0.175 pipe failure ignored by sh
#6 ERROR: process "/bin/ash -eo pipefail -c false | true && echo \"not reached\"" did not complete successfully: exit code: 1
```

`curl -fsSL https://example.com/install.sh | sh` happily "succeeds" when the download fails. If you pipe, switch the
shell with `SHELL`: `["/bin/bash", "-o", "pipefail", "-c"]` on Debian images (they include `bash`),
`["/bin/ash", "-eo", "pipefail", "-c"]` on Alpine.

### Installing operating-system packages

Package installation has well-known patterns. On Debian-based images:

```dockerfile
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates \
      tini \
 && rm -rf /var/lib/apt/lists/*
```

- `update` and `install` go in the **same** `RUN`. In separate steps, the `update` layer gets cached and a later
  change to the install line installs from a stale package index (and can fail with 404s).
- `--no-install-recommends` avoids pulling in "recommended" packages you didn't ask for (often doubling the size).
- Removing `/var/lib/apt/lists/*` in the same step keeps the package index (tens of MB) out of the layer.
- Use `apt-get`, not `apt`, in scripts: `apt` warns that its command-line interface isn't stable.
- One package per line, sorted alphabetically, makes diffs readable.

On Alpine:

```dockerfile
RUN apk add --no-cache tini
```

`--no-cache` fetches the index, installs, and leaves no index behind, all in one go.

Should you pin package versions (`tini=0.19.0-r3`)? Pinning makes builds reproducible but breaks them when the
distribution removes the old version, which Alpine does quickly. A common compromise: pin the *base image* by digest
(chapter 3), which effectively pins the package repository snapshot for Debian point releases, and don't pin
individual packages.

### Combining commands

Each `RUN` creates a layer, and files deleted in a later layer still take up space in the earlier one. So
**create and clean up temporary files in the same `RUN`**:

```dockerfile
# Bad: the 50 MB archive stays in the first layer forever
RUN curl -fsSLo /tmp/tool.tgz https://example.com/tool.tgz
RUN tar -xzf /tmp/tool.tgz -C /usr/local/bin && rm /tmp/tool.tgz

# Good
RUN curl -fsSLo /tmp/tool.tgz https://example.com/tool.tgz \
 && tar -xzf /tmp/tool.tgz -C /usr/local/bin \
 && rm /tmp/tool.tgz
```

Don't take this too far: merging *unrelated* steps into one giant `RUN` hurts caching (any change reruns all of it)
and readability. Group by "things that change together". Multi-stage builds (chapter 7) make most of this
cleanup unnecessary, because temporary files simply stay in a stage you throw away.

### Heredocs

Long scripts are easier to read as heredocs (supported by the Dockerfile frontend since 1.4; with
`# syntax=docker/dockerfile:1` you always have them):

```dockerfile
RUN <<EOF
set -eu
mkdir -p /app/data
echo "built at $(date -u +%FT%TZ)" > /app/BUILD_INFO
EOF
```

You can also create files with `COPY` and a heredoc, which avoids `echo` quoting games:

```dockerfile
COPY <<'EOF' /usr/local/bin/entrypoint.sh
#!/bin/sh
set -e
exec "$@"
EOF
```

Quoting the delimiter (`<<'EOF'`) disables variable expansion inside, so `$@` is written literally.

## `COPY` and `ADD`

`COPY <src>... <dest>` copies files from the build context (or from another stage or image, chapter 7). Behaviour
worth knowing:

- Copying a **directory** copies its *contents*, not the directory itself: `COPY src /app/src` (destination named
  explicitly) is the clear way to write it.
- Wildcards work: `COPY *.json ./`. With several sources, the destination must end with `/`.
- Missing destination directories are created.
- Files are owned by `root:root` (UID 0) unless you say otherwise, and keep their permission bits from the context.
  File **timestamps don't matter** to the cache; contents do.

### `COPY` options

```dockerfile
COPY --chown=node:node src ./src           # owner and group (names or numeric IDs)
COPY --chmod=755 bin/entrypoint.sh /usr/local/bin/
COPY --exclude=*.test.js src ./src         # skip matching files
COPY --parents packages/*/package.json ./  # keep the directory structure (monorepos)
COPY --link package*.json ./               # an independent layer (see below)
COPY --from=build /app/dist ./dist         # from another stage or image (chapter 7)
```

- **`--chown`/`--chmod`** set ownership and mode in the same layer. The alternative, `RUN chown -R node:node /app`
  after a `COPY`, **duplicates every file** into a new layer just to change its metadata. Never `chown -R` a big
  directory in a separate `RUN`.
- **`--exclude`** takes patterns like `.dockerignore`.
- **`--parents`** preserves the source's relative path, which makes it practical to copy every `package.json` of
  a monorepo in one instruction, keeping the dependency layer cacheable.
- **`--link`** makes the copied files a separate layer that doesn't depend on the layers below it. If the base image
  changes, BuildKit can reuse the `COPY --link` layer instead of re-copying, and registries can reuse it across
  rebases. It's safe for copies into a fresh directory that don't rely on what's already there (no symlinks to
  follow, no existing directory permissions to inherit).

### `ADD`: the powerful one

`ADD` does everything `COPY` does, plus three things:

```dockerfile
# 1. Download a URL (always verify it)
ADD --checksum=sha256:782e17d54343aa3f6db60430015692b6540089b3ec5b78274981ada2d3c3c423 \
    https://raw.githubusercontent.com/nodejs/docker-node/main/LICENSE /licenses/docker-node.txt

# 2. Clone a Git repository (a branch, tag or commit, optionally a subdirectory)
ADD https://github.com/nodejs/docker-node.git#main:24 /docker-node-24

# 3. Auto-extract a *local* tar archive (.tar, .tar.gz, .tgz, .tar.xz, …)
ADD files.tgz /unpacked/
```

With the wrong checksum, the build stops:

```text
ERROR: failed to build: failed to solve: digest mismatch sha256:782e17d5…: sha256:00000000…
```

The rule of thumb: **use `COPY` unless you need one of `ADD`'s extra features.** `ADD`'s magic is surprising
(`ADD archive.tgz` extracts it, `COPY archive.tgz` doesn't; remote archives are *not* extracted unless you add
`--unpack=true`), and remote downloads without `--checksum` are a supply-chain risk: if the server's content changes,
your image changes silently. Downloaded files get mode `600` unless you set `--chmod`.

## `USER`: don't run as root

By default, everything runs as `root` (UID 0): `RUN` steps and the container's process. Inside a container root is
restricted (chapter 16), but it is still the same UID 0 as on the host, and container escapes through kernel or
runtime bugs are far more dangerous for a root process. For a web app there is simply no reason to be root.

The official Node images include an unprivileged user, `node` (UID 1000, GID 1000), but don't switch to it.
`USER` does:

```dockerfile
COPY src ./src        # owned by root: the app can read its code but not modify it
USER node
CMD ["node", "src/server.js"]
```

`USER` applies to every later `RUN`, `CMD` and `ENTRYPOINT`, and to `docker exec` sessions. Points to get right:

- **Who should own the code?** Usually *root*, with the app running as `node`. If the app is compromised, it can't
  rewrite its own source or dependencies. Use `--chown=node:node` only for directories the app must write to, and
  prefer volumes or `/tmp` for those.
- **Ports below 1024.** A non-root process can't bind to port 80 or 443 by default. In a container that's no problem:
  listen on 3000 and publish `-p 80:3000`.
- **Numeric IDs are more robust.** `USER 1000:1000` works even if the user doesn't exist in the image, and Kubernetes
  can only verify `runAsNonRoot` when the user is numeric. With the Node image, `USER node` and `USER 1000` are
  equivalent.
- **`WORKDIR` creates directories as root**, even after `USER`. If the app needs to write there, create and
  `chown` it explicitly (or `COPY --chown`).

```bash
docker run --rm snip:1.1 whoami                 # node
docker run --rm -u root snip:1.1 whoami         # root: -u overrides USER at run time
```

## `ENTRYPOINT` and `CMD`

Both define what a container runs. The difference:

- **`ENTRYPOINT`** is the executable. It's replaced only with `docker run --entrypoint`.
- **`CMD`** is the default *arguments*. Anything after the image name in `docker run` replaces it.

When both are in exec form, Docker runs `ENTRYPOINT` followed by `CMD`:

| Dockerfile | `docker run img` runs | `docker run img node -p 1+1` runs |
|---|---|---|
| `CMD ["node", "server.js"]` | `node server.js` | `node -p 1+1` |
| `ENTRYPOINT ["node"]` + `CMD ["server.js"]` | `node server.js` | `node node -p 1+1` (oops) |
| `ENTRYPOINT ["node"]` + `CMD ["server.js"]`, run as `docker run img -p 1+1` | | `node -p 1+1` |
| `ENTRYPOINT ["entrypoint.sh"]` + `CMD ["node", "server.js"]` | `entrypoint.sh node server.js` | `entrypoint.sh node -p 1+1` |
| `ENTRYPOINT node server.js` (shell form) | `/bin/sh -c "node server.js"` | the same; `CMD` and arguments are **ignored** |

The last row is why shell-form `ENTRYPOINT` is almost always a mistake: it ignores `CMD` and all `docker run`
arguments, and puts a shell between Docker and your process.

The two common patterns:

1. **`CMD` only** (`CMD ["node", "src/server.js"]`): simple, and `docker run img sh` gives you a shell. Best for most
   applications.
2. **`ENTRYPOINT` script + `CMD`**: the script does setup, then `exec`s the command it was given. This is what the
   Node image does.

### The Node image's entrypoint

You've seen `docker-entrypoint.sh` in `docker ps` since chapter 2. It is short:

```sh
#!/bin/sh
set -e

# Run command with node if the first argument contains a "-" or is not a system command. The last
# part inside the "{}" is a workaround for the following bug in ash/dash:
# https://bugs.debian.org/cgi-bin/bugreport.cgi?bug=874264
if [ "${1#-}" != "${1}" ] || [ -z "$(command -v "${1}")" ] || { [ -f "${1}" ] && ! [ -x "${1}" ]; }; then
  set -- node "$@"
fi

exec "$@"
```

If the first argument starts with `-`, isn't a command on the `PATH`, or is a non-executable file, it prepends `node`.
So all of these work:

```bash
docker run --rm snip:1.1 --version              # node --version → v24.22.0
docker run --rm snip:1.1 src/healthcheck.js     # node src/healthcheck.js
docker run --rm snip:1.1 ls                     # ls (it's a command)
```

Then it **`exec`s** the command, replacing the shell process. That `exec` is the crucial line: your `node` process
becomes PID 1 and receives signals directly. A script that runs `node server.js` without `exec` leaves the shell as
PID 1, and signals never reach Node.

### Writing your own entrypoint script

Use one when something must happen at container start, every time, before the main process: waiting for a
dependency, rendering a config file from environment variables, fixing permissions on a mounted volume. The pattern:

```dockerfile
COPY --chmod=755 <<'EOF' /usr/local/bin/entrypoint.sh
#!/bin/sh
set -e
echo "entrypoint: preparing (args: $*)"
# ... setup work here ...
exec "$@"
EOF
ENTRYPOINT ["entrypoint.sh"]
CMD ["node", "-e", "console.log('main process pid', process.pid)"]
```

```bash
docker run --rm eptest
docker run --rm eptest node -p '1+1'
docker run --rm --entrypoint node eptest -p '"bypassed"'
```

```text
entrypoint: preparing (args: node -e console.log('main process pid', process.pid))
main process pid 1
entrypoint: preparing (args: node -p 1+1)
2
bypassed
```

The script runs, then `exec`s the `CMD` (or whatever you passed), which ends up as PID 1. `--entrypoint` replaces the
entrypoint for debugging, and `--entrypoint ''` removes it.

Keep entrypoint scripts small and boring. Don't run database migrations in them (chapter 11 explains why and what
to do instead), and don't install anything at start-up: the container should be ready to run as built.

## `EXPOSE`

`EXPOSE 3000` (or `EXPOSE 3000/tcp 9229/tcp`) documents ports in the image metadata. It doesn't publish anything
(chapter 2), but tools read it: `docker run -P`, Docker Desktop's UI, and humans. Keep it accurate.

## `LABEL`

Labels are key/value metadata on the image. Use the standard **OCI annotation keys** so that registries, scanners and
other tools understand them:

```dockerfile
ARG APP_VERSION=dev
ARG GIT_SHA=unknown
LABEL org.opencontainers.image.title="snip" \
      org.opencontainers.image.description="A tiny URL shortener" \
      org.opencontainers.image.source="https://github.com/example/snip" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${GIT_SHA}"
```

`org.opencontainers.image.source` is particularly useful: GitHub Container Registry links the image to that
repository, and tools like Renovate use it to find release notes. Labels are inherited from the base image, so you'll
see the base image's labels too. Read them with
`docker image inspect -f '{{json .Config.Labels}}' snip:1.1`. Chapter 14 shows how CI generates these values
automatically.

## `HEALTHCHECK`

A process can be running but broken: deadlocked, stuck at 100% CPU, unable to reach its database, not yet ready.
`HEALTHCHECK` tells Docker how to check from the inside:

```dockerfile
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --start-interval=1s --retries=3 \
  CMD ["node", "src/healthcheck.js"]
```

Docker runs the command (inside the container, like `docker exec`) on a schedule. Exit code 0 means healthy, 1 means
unhealthy (don't use other codes).

| Option | Default | Meaning |
|---|---|---|
| `--interval` | 30s | Time between checks |
| `--timeout` | 30s | A check taking longer counts as failed |
| `--start-period` | 0s | Grace period for slow starts: failures don't count, the first success makes it healthy |
| `--start-interval` | 5s | Check interval *during* the start period (Engine 25+) |
| `--retries` | 3 | Consecutive failures before the status becomes `unhealthy` |

The status appears in `docker ps` and `docker inspect`:

```text
NAMES     STATUS
snip      Up Less than a second (health: starting)
...
NAMES     STATUS                   HEALTH STATUS
snip      Up 2 seconds (healthy)   healthy
```

(The `{{.HealthStatus}}` placeholder for `docker ps --format` is new in Engine 29.5.) `docker inspect -f
'{{json .State.Health}}' snip` shows the last few results with their output.

What Docker does with the status:

- **Docker Compose** can wait for a dependency to be healthy before starting the services that need it (chapter 11).
  This is the most important use.
- **Swarm** replaces unhealthy tasks and waits for health during rolling updates.
- Plain `docker run` **does nothing** when a container becomes unhealthy, apart from reporting it (and emitting a
  `health_status` event). A restart policy doesn't restart unhealthy containers; only an exit does.

Kubernetes ignores `HEALTHCHECK` entirely; it has its own liveness and readiness probes (chapter 21).

### Writing the check in Node.js

Many examples use `curl -f http://localhost:3000/healthz`. Slim and distroless images don't include `curl`, and
installing it just for this adds a tool attackers like. Node.js 18+ has `fetch` built in, so write the check in
JavaScript. Snip's `src/healthcheck.js`:

```js
// Used by the image's HEALTHCHECK: exits 0 if the server answers /healthz, 1 otherwise.
// Written in Node so the image doesn't need curl or wget.
const port = process.env.PORT ?? 3000;

try {
  const res = await fetch(`http://127.0.0.1:${port}/healthz`, { signal: AbortSignal.timeout(2000) });
  process.exit(res.ok ? 0 : 1);
} catch {
  process.exit(1);
}
```

A health endpoint should be **cheap** (it runs every 30 seconds, forever) and check what makes *this* process
unable to serve. Be careful about checking dependencies: if `/healthz` fails whenever the database is down, then
every instance turns unhealthy at once during a database blip, and an orchestrator that restarts unhealthy containers
makes things worse. Chapter 18 separates liveness from readiness.

Each check starts a new `node` process, which costs about 40 MB of memory and some CPU for a moment. That's fine
every 30 seconds; don't set `--interval=1s`.

## `STOPSIGNAL`

`STOPSIGNAL SIGINT` changes the signal `docker stop` sends (default `SIGTERM`). Use it for programs whose graceful
shutdown signal is something else: nginx uses `SIGQUIT`, PostgreSQL's images use `SIGINT` (a "fast shutdown").
For Node.js apps, handle `SIGTERM` and keep the default.

## `SHELL`

Changes the shell for shell-form `RUN`, `CMD` and `ENTRYPOINT`. You saw its main use above, `pipefail`. It also lets
Windows images use PowerShell.

## `VOLUME`

`VOLUME /var/lib/app` declares that a path holds data that should live outside the container's writable layer. At
`docker run`, Docker mounts an **anonymous volume** there unless you mount something yourself. Database images use it
(`postgres` declares `VOLUME /var/lib/postgresql`).

Avoid `VOLUME` in application images: you can't undo it in images built on yours, any `RUN` that changes the path
after `VOLUME` is silently discarded, and every `docker run` leaves an anonymous volume behind. Declare mounts at run
time instead (chapter 8).

## `ONBUILD`

> **Just a taste — you don't need to learn or fully understand this now.** `ONBUILD` registers instructions that run
> only when *another* Dockerfile uses this image in its `FROM`; it was used for "builder" base images and is rarely
> worth the surprise it causes.
>
> ```dockerfile
> FROM node:24-slim
> WORKDIR /app
> ONBUILD COPY package*.json ./
> ONBUILD RUN npm ci --omit=dev
> ONBUILD COPY . .
> ```

## Build checks

BuildKit can lint a Dockerfile without building it:

```bash
docker build --check .
```

For a Dockerfile with a few classic mistakes:

```dockerfile
FROM node:24-alpine AS base
copy ex/ /ex/
ENV FOO bar
CMD node -e "console.log(1)"
```

```text
WARNING: ConsistentInstructionCasing - https://docs.docker.com/go/dockerfile/rule/consistent-instruction-casing/
Command 'copy' should match the case of the command majority (uppercase)
Dockerfile:2
...
WARNING: LegacyKeyValueFormat - https://docs.docker.com/go/dockerfile/rule/legacy-key-value-format/
"ENV key=value" should be used instead of legacy "ENV key value" format
Dockerfile:3
...
WARNING: JSONArgsRecommended - https://docs.docker.com/go/dockerfile/rule/json-args-recommended/
JSON arguments recommended for CMD to prevent unintended behavior related to OS signals
Dockerfile:4
```

Checks also run during every normal build and are summarized at the end. Other rules catch undefined variables in
`FROM`, `ARG`s that look like secrets (`SecretsUsedInArgOrEnv`), stage names that differ only in case, duplicate
stage names, `FROM --platform` hard-coding the build platform, and more; each warning links to its explanation.
`# check=error=true` turns warnings into errors, so a sloppy Dockerfile can't be merged. Put `docker build --check .`
in CI (chapter 14).

## Snip, improved

Putting this chapter together, here is Snip's Dockerfile with a pinned frontend, build checks, a parameterized
Node version, version labels, a non-root user, a health check and a clean npm cache:

```dockerfile
# syntax=docker/dockerfile:1
# check=error=true

ARG NODE_VERSION=24
FROM node:${NODE_VERSION}-slim

ENV NODE_ENV=production
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

# Root owns the code (the app can't modify itself); node only needs to read it.
COPY src ./src

# Set by CI: docker build --build-arg APP_VERSION=1.2.0 --build-arg GIT_SHA=$(git rev-parse HEAD) .
# Declared late on purpose: a changed ARG value invalidates every RUN after its declaration (chapter 6).
ARG APP_VERSION=dev
ARG GIT_SHA=unknown

LABEL org.opencontainers.image.title="snip" \
      org.opencontainers.image.description="A tiny URL shortener" \
      org.opencontainers.image.source="https://github.com/example/snip" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${GIT_SHA}"
ENV APP_VERSION=${APP_VERSION}

USER node
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --start-interval=1s --retries=3 \
  CMD ["node", "src/healthcheck.js"]

CMD ["node", "src/server.js"]
```

Add `src/healthcheck.js` from above to the chapter 4 project, then:

```bash
docker build --build-arg APP_VERSION=1.1.0 --build-arg GIT_SHA=3f9c2a1 -t snip:1.1 .
docker run -d --name snip -p 3000:3000 snip:1.1
docker ps --format 'table {{.Names}}\t{{.Status}}'     # (health: starting), then (healthy)
docker exec snip whoami                                 # node
docker exec snip touch src/x                            # touch: cannot touch 'src/x': Permission denied
docker image inspect -f '{{json .Config.Labels}}' snip:1.1
docker rm -f snip
```

```text
{"org.opencontainers.image.description":"A tiny URL shortener","org.opencontainers.image.revision":"3f9c2a1","org.opencontainers.image.source":"https://github.com/example/snip","org.opencontainers.image.title":"snip","org.opencontainers.image.version":"1.1.0"}
```

`APP_VERSION` is promoted from `ARG` to `ENV`, so the running app can report its version, which is what the
version endpoint from chapter 4's exercise 5 needed. This image is still single-stage and still contains npm,
which the app doesn't need at runtime; chapter 7 fixes that. The complete project, with everything from this
chapter, chapter 6 and chapter 7, is in [`examples/ch07-production-image/`](examples/ch07-production-image/).

## Key takeaways

- Start Dockerfiles with `# syntax=docker/dockerfile:1` for current features and `# check=error=true` to enforce the
  build checks; run `docker build --check .` in CI.
- `ARG` is build-time only and scoped to a stage (re-declare after `FROM`); `ENV` reaches containers. Both are visible
  in the image, so never use them for secrets.
- Shell-form `RUN` uses `/bin/sh` without `pipefail`; set `SHELL` if you pipe. Install OS packages with
  `update` + `install` + cleanup in one `RUN`.
- Prefer `COPY`; use `--chown`/`--chmod` instead of a separate `chown -R`. Use `ADD` only for checksummed
  downloads, Git sources or local archives.
- Run as a non-root user (`USER node`), with the code owned by root.
- `ENTRYPOINT` is the executable, `CMD` its default arguments. Use exec form; an entrypoint script must end with
  `exec "$@"`.
- `HEALTHCHECK` lets Docker (and Compose) know when the app actually works; write it in Node with `fetch` instead of
  installing `curl`.
- Avoid `VOLUME` and `ONBUILD` in application images.

## Exercises

1. **ARG or ENV?** For each value, decide whether it should be an `ARG`, an `ENV`, both, or neither (and say why):
   (a) the Node.js major version to build on; (b) the HTTP port the app listens on; (c) the Git commit being built;
   (d) the database password; (e) an npm token for a private registry needed by `npm ci`.

<details>
<summary>Solution</summary>

(a) `ARG` (before `FROM`): it only selects the base image.

(b) `ENV PORT=3000` as a default, overridable with `docker run -e PORT=…`. It's runtime configuration. (Keep
`EXPOSE` in sync.)

(c) `ARG GIT_SHA`, used in a `LABEL`; optionally promoted to `ENV` if the app reports it. It's known only at build
time and differs per build.

(d) Neither. A password isn't known at build time and mustn't be stored in the image. Provide it at runtime, ideally
as a secret file (chapter 13).

(e) Neither. Build arguments are recorded in the image history. Use a secret mount:
`RUN --mount=type=secret,id=npmrc,target=/root/.npmrc npm ci` (chapter 6).

</details>

2. **Fix the Dockerfile.** Find at least six problems in this Dockerfile and rewrite it:

   ```dockerfile
   FROM node:latest
   RUN apt-get update
   RUN apt-get install -y imagemagick
   ADD . /
   RUN npm install
   ENV NPM_TOKEN=npm_abc123
   EXPOSE 80
   ENTRYPOINT npm start
   ```

<details>
<summary>Solution</summary>

Problems: `latest` tag (unpredictable major version); `apt-get update` in a separate, cached step; no
`--no-install-recommends` and no cleanup of the package lists; `ADD` instead of `COPY`; copying everything into `/`
(no `WORKDIR`, mixes app files with the OS) before installing dependencies (no layer caching, local `node_modules`
copied in); `npm install` instead of `npm ci` and dev dependencies included; a secret in `ENV`; runs as root;
port 80 as non-root won't work and isn't needed; shell-form `ENTRYPOINT` through npm (signals lost, `CMD` ignored).

```dockerfile
# syntax=docker/dockerfile:1
# check=error=true
FROM node:24-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends imagemagick \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=secret,id=npmrc,target=/root/.npmrc npm ci --omit=dev
COPY src ./src

ENV NODE_ENV=production PORT=3000
USER node
EXPOSE 3000
CMD ["node", "src/server.js"]
```

The secret mount (chapter 6) makes the token available to `npm ci` without it ending up in any layer. Build with
`docker build --secret id=npmrc,src=$HOME/.npmrc .`.

</details>

3. **Entrypoint pass-through.** Write an image whose entrypoint script prints `starting <command>` and the value of
   an environment variable `APP_ENV` (default `development`), then runs the command. Prove that (a) `docker run img`
   runs the default `CMD`, (b) `docker run img node -p 42` runs your command through the script, (c) the final process
   is PID 1, (d) `docker stop` is instant if the command handles `SIGTERM`.

<details>
<summary>Solution</summary>

```dockerfile
# syntax=docker/dockerfile:1
FROM node:24-alpine
COPY --chmod=755 <<'EOF' /usr/local/bin/entrypoint.sh
#!/bin/sh
set -e
echo "starting $* (APP_ENV=${APP_ENV:-development})"
exec "$@"
EOF
ENTRYPOINT ["entrypoint.sh"]
CMD ["node", "-e", "console.log('pid', process.pid); process.on('SIGTERM', () => process.exit(0)); setInterval(() => {}, 1000)"]
```

```bash
docker build -t eptest .
docker run --rm eptest node -p 42                 # starting node -p 42 (APP_ENV=development), then 42
docker run -d --name ep -e APP_ENV=prod eptest
docker logs ep                                    # starting node -e … (APP_ENV=prod), pid 1
time docker stop ep                               # well under a second
docker rm ep
```

`pid 1` proves that `exec` replaced the shell with `node`. Remove `exec` from the script and rebuild: the log now
says `pid 8` (or similar), `docker top ep` shows both `sh` and `node`, and `docker stop` takes 10 seconds.

</details>

4. **A failing health check.** Run `snip:1.1` with `-e PORT=4000 -p 4000:4000`. Is it healthy? Now run it with a
   health check overridden at run time to check port 5000 instead
   (`--health-cmd 'node -e "fetch(\"http://127.0.0.1:5000/healthz\").then(r=>process.exit(r.ok?0:1),()=>process.exit(1))"'`),
   with `--health-interval 2s --health-retries 2`. How long until it's `unhealthy`, and what does
   `docker inspect` show? Does the container restart?

<details>
<summary>Solution</summary>

With `PORT=4000` the container is healthy: both the server and `src/healthcheck.js` read `PORT`, which is why the
check reads the variable instead of hard-coding 3000.

With the overridden check, each probe fails (connection refused). Overriding `--health-cmd` keeps the image's other health
settings unless you override them too, so the image's 10-second start period still applies, and during it failures
don't count. After that, two failed checks two seconds apart make it `(unhealthy)`: about 12 seconds in total.
`docker inspect -f '{{json .State.Health}}'` shows `"Status": "unhealthy"`, `"FailingStreak"` counting up, and the
log entries with `"ExitCode": 1`.

The container does **not** restart, even with `--restart always`: restart policies react to the process exiting,
not to health. Compose's `depends_on: condition: service_healthy` and orchestrators act on health; plain Docker only
reports it.

</details>

5. **Measure the `chown` trap.** Build two images from `node:24-slim` that both put a copy of `node_modules` (any
   project's) into `/app` owned by `node`: one with `COPY --chown=node:node`, one with `COPY` followed by
   `RUN chown -R node:node /app`. Compare `docker image history` and sizes.

<details>
<summary>Solution</summary>

```dockerfile
# A
FROM node:24-slim
COPY --chown=node:node node_modules /app/node_modules

# B
FROM node:24-slim
COPY node_modules /app/node_modules
RUN chown -R node:node /app
```

In B's history there are two layers of the same size: the `COPY` and then the `RUN chown`, because changing a
file's owner is a change to the file, and copy-on-write stores a complete new copy of every file in the new layer.
With npm's own 19 MB `node_modules` as the test subject:

```text
19.6MB  RUN /bin/sh -c chown -R node:node /app # bui…
19.6MB  COPY node_modules /app/node_modules # buildk…
```

B is larger than A by the full size of `node_modules`. A does it in one layer of the original size.

</details>

---
← Previous: [Your first Dockerfile](04-your-first-dockerfile.md) | Next: [The build cache and BuildKit](06-build-cache-and-buildkit.md) →
