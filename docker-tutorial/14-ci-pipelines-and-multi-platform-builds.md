# Chapter 14 — CI pipelines and multi-platform builds

## What you'll learn

- What a container CI pipeline should do, and in which order
- Checking Dockerfiles and running tests as part of the build
- A complete GitHub Actions workflow with Buildx: tests, integration tests, tags, push
- Cache backends for CI runners that start empty
- Tagging strategies that tell you exactly what is running
- Multi-platform images: emulation, native builders, and cross-building with `$BUILDPLATFORM`
- Bake: build definitions as code
- SBOM and provenance attestations

## What CI does with containers

Every push should end in one of two states: "this commit is broken" or "this exact image is tested and published".
In between, the pipeline does the following:

```text
  checkout ─▶ check ─▶ unit tests ─▶ build ─▶ integration tests ─▶ scan ─▶ push + attest
              Dockerfile  (test stage)  image    (Compose stack)      (ch. 16)  (by tag and digest)
```

| Step | Why | How |
|---|---|---|
| Check | Catch Dockerfile mistakes before they cost a build | `docker build --check` (BuildKit's build checks) |
| Unit tests | The code works | The Dockerfile's `test` stage (chapter 7) |
| Build | The image that may ship | `docker buildx build` with a cache backend |
| Integration tests | The image works with its real dependencies | The Compose stack, `up --wait`, tests from a container (chapter 12) |
| Scan | No known critical vulnerabilities | Chapter 16 |
| Push and attest | Others can pull it and verify where it came from | Tags, SBOM, provenance |

The rule that ties it together: **build once, promote the same artifact.** The image that passed the tests is the
image that gets pushed, tagged and later deployed by digest. Rebuilding "the same commit" for production gives you a
different image (newer base image, newer packages) that nobody tested.

### Checking the Dockerfile

`docker build --check` runs the build checks from chapter 5 without building anything, and exits non-zero if there
are warnings:

```bash
docker build --check .
```

```text
Check complete, 2 warnings have been found!
WARNING: SecretsUsedInArgOrEnv - https://docs.docker.com/go/dockerfile/rule/secrets-used-in-arg-or-env/
WARNING: SecretsUsedInArgOrEnv - https://docs.docker.com/go/dockerfile/rule/secrets-used-in-arg-or-env/
```

(That's chapter 13's leaky Dockerfile. Snip's says `Check complete, no warnings found.`) Snip's Dockerfile has
`# check=error=true`, so a real build fails on any warning too.

### Tests inside the build

Chapter 7 put the unit tests into a `test` stage. In CI, building that stage *is* running the tests:

```bash
docker build --target test .
```

The tests run with the same Node.js version, the same OS and the same `node_modules` as the image that ships, so
"passes in CI but fails in production" because of a different runtime becomes impossible. Because the stage shares
the `deps` stage with the runtime image, the dependencies are installed once and the second build reuses them from
the cache.

## The pipeline in GitHub Actions

Snip's pipeline is in [`examples/ch14-ci/.github/workflows/ci.yml`](examples/ch14-ci/.github/workflows/ci.yml). The
complete project for this chapter is [`examples/ch14-ci/`](examples/ch14-ci/). It has two jobs: `test` runs on every
push and pull request; `publish` runs after it, only for pushes to `main` and version tags.

```yaml
name: ci

on:
  push:
    branches: [main]
    tags: ["v*.*.*"]
  pull_request:

permissions:
  contents: read

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7

      - uses: docker/setup-buildx-action@v4

      - name: Unit tests (the Dockerfile's test stage)
        uses: docker/build-push-action@v7
        with:
          context: .
          target: test
          outputs: type=cacheonly
          cache-from: type=gha,scope=snip
          cache-to: type=gha,scope=snip,mode=max

      - name: Start the stack
        run: |
          ./create-secrets.sh
          cp .env.example .env
          docker compose -f compose.yaml up -d --build --wait

      - name: Integration tests
        run: |
          docker run --rm --network snip_default -v "$PWD/test:/test:ro" \
            -e SNIP_URL=http://api:3000 node:24-alpine node --test /test/integration.test.js

      - name: Show logs
        if: failure()
        run: docker compose -f compose.yaml logs
```

Step by step:

- **`actions/checkout`** gets the code. The Docker actions can also build directly from the Git URL, but then they
  ignore everything earlier steps did to the files; `context: .` builds from the checked-out directory.
- **`docker/setup-buildx-action`** creates a Buildx builder with the `docker-container` driver: a BuildKit instance
  in a container, with all features (multi-platform output, every cache backend), independent of the runner's Docker
  Engine configuration.
- **`docker/build-push-action`** with `target: test` runs the unit tests. `outputs: type=cacheonly` says "build it,
  keep nothing": the stage is only interesting for whether it succeeds.
- **The integration tests** run exactly as in chapter 12: the production stack (`-f compose.yaml`, no development
  overrides), a fresh secret, `--wait`, then the test from a throwaway container on the project network. GitHub's
  runners are disposable, so there is no cleanup step.
- **`if: failure()`** prints the logs when anything failed, the only chance to see them before the runner disappears.

The second job publishes:

```yaml
  publish:
    needs: test
    if: github.event_name == 'push'
    runs-on: ubuntu-latest
    permissions:
      contents: read
      packages: write
    steps:
      - uses: actions/checkout@v7

      - uses: docker/setup-buildx-action@v4

      - uses: docker/login-action@v4
        with:
          registry: ghcr.io
          username: ${{ github.actor }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: Compute tags and labels
        id: meta
        uses: docker/metadata-action@v6
        with:
          images: ghcr.io/${{ github.repository_owner }}/snip
          tags: |
            type=ref,event=branch
            type=semver,pattern={{version}}
            type=semver,pattern={{major}}.{{minor}}
            type=sha

      - name: Build and push
        uses: docker/build-push-action@v7
        with:
          context: .
          platforms: linux/amd64,linux/arm64
          push: true
          tags: ${{ steps.meta.outputs.tags }}
          labels: ${{ steps.meta.outputs.labels }}
          build-args: |
            APP_VERSION=${{ steps.meta.outputs.version }}
            GIT_SHA=${{ github.sha }}
          sbom: true
          provenance: mode=max
          cache-from: type=gha,scope=snip
          cache-to: type=gha,scope=snip,mode=max
```

- **`permissions: packages: write`** lets the job's automatic `GITHUB_TOKEN` push to the GitHub Container Registry
  (GHCR). No personal access token, nothing to rotate. Chapter 15 covers registries and logins in detail.
- **`docker/metadata-action`** turns the Git event into tags and OCI labels (next section).
- **The build** is multi-platform, attested and cached; each is explained below.

This workflow is checked with `actionlint`, but whether it runs depends on your repository's settings; treat it as a
template. On other CI systems (GitLab CI, Buildkite, Jenkins, CircleCI), the same steps are plain
`docker buildx` commands, and Bake (below) keeps them short.

## Caching in CI

CI runners usually start empty: no images, no build cache. Without an exported cache, every build reinstalls every
dependency. BuildKit can export its cache to a **cache backend** and import it on the next run:

| Backend | Stored in | Good for |
|---|---|---|
| `type=gha` | The GitHub Actions cache service | GitHub Actions (10 GB per repository, evicted when unused) |
| `type=registry,ref=…` | A separate image tag in a registry, e.g. `snip:buildcache` | Any CI with a registry; shared between CI systems and developers |
| `type=inline` | Inside the image itself | Simple setups; only `mode=min` |
| `type=local,dest=…` | A directory | CI systems with persistent or restorable directories |
| `type=s3`, `type=azblob` | Object storage | Self-hosted runners on AWS or Azure |

**`mode=min`** (the default) exports only the layers of the final image; **`mode=max`** exports the layers of every
stage, including `deps` and `test`. For multi-stage builds, `mode=max` is what makes the second run fast, because the
expensive step (`npm ci`) is in an intermediate stage.

**`scope`** (for `gha`) names the cache entry. Builds of different images in one repository need different scopes,
or they overwrite each other's cache.

The registry backend, tried against a local registry:

```bash
docker buildx build --cache-to type=registry,ref=localhost:5000/snip:buildcache,mode=max -t snip:1.7 .
docker buildx prune -af                              # forget everything locally
docker buildx build --cache-from type=registry,ref=localhost:5000/snip:buildcache -t snip:1.7 --progress plain .
```

```text
#8 importing cache manifest from localhost:5000/snip:buildcache
#9 CACHED
#10 CACHED
…
```

Since Engine 29 uses the containerd image store by default, the default `docker` builder can export and import these
cache types too. Before, they needed a `docker-container` builder.

Two limits to know:

- **Cache mounts are not exported.** `RUN --mount=type=cache,target=/root/.npm` speeds up repeated builds on one
  machine, but its contents don't travel with `--cache-to`. On a fresh runner the npm cache is empty. The *layer*
  that `npm ci` produced is exported, though, and as long as `package-lock.json` is unchanged that layer is reused and
  `npm ci` doesn't run at all.
- **A cache is an optimization, never a dependency.** Builds must work, just slower, when the cache is missing or
  evicted.

## Tags that mean something

A tag is a mutable pointer. What you want from tags in CI is that anyone can answer "which code is this image?" and
"which image is production running?" without guessing. `docker/metadata-action` with the tag rules above produces,
for each kind of event:

| Event | Tags |
|---|---|
| Push to `main` | `main`, `sha-3f2a1c9` |
| Push of tag `v1.7.0` | `1.7.0`, `1.7`, `sha-3f2a1c9` |
| Pull request | none (the job doesn't run) |

Each kind has a purpose:

- **`sha-…`** is unique per commit and never moves: the precise reference for debugging and for rollbacks.
- **`1.7.0`** is the release. Treat release tags as immutable; never push a different image under an existing
  version. Some registries can enforce this ("immutable tags").
- **`1.7`** and **`main`** move on purpose: "the latest patch of 1.7", "the latest build of main". Good for staging
  environments and for people trying things out; not for production deployments.
- **`latest`** is just a tag named `latest`. `metadata-action` adds it for semver tags by default (`flavor`
  controls that). Never deploy `latest`: it doesn't say what it is, and it changes under you.

The tag is for humans; the **digest** is what you deploy. `docker/build-push-action` returns it as the output
`digest`, and chapter 15 shows deploying by digest.

The action also produces OCI labels (`org.opencontainers.image.source`, `revision`, `created`, `version`…), which
link the image back to the repository and commit. GHCR uses the `source` label to connect the package to the
repository.

## Multi-platform images

Laptops with Apple silicon, AWS Graviton and other ARM servers, Raspberry Pis: `linux/arm64` is everywhere next to
`linux/amd64`. An image is built for one platform, but a **multi-platform image** is an index (chapter 3) that points
to one image per platform, and every `docker pull` picks the right one automatically.

```bash
docker buildx build --platform linux/amd64,linux/arm64 -t localhost:5000/snip:1.7 --push .
docker buildx imagetools inspect localhost:5000/snip:1.7
```

```text
Name:      localhost:5000/snip:1.7
MediaType: application/vnd.oci.image.index.v1+json
Digest:    sha256:89df76f4aaa85b0830ce790d414cf1c1b91cddb509c9e6e18e5eb2b5dd05cdc4

Manifests:
  Name:        localhost:5000/snip:1.7@sha256:5dbacbeb1114…
  MediaType:   application/vnd.oci.image.manifest.v1+json
  Platform:    linux/amd64

  Name:        localhost:5000/snip:1.7@sha256:bd93dbb2070a…
  MediaType:   application/vnd.oci.image.manifest.v1+json
  Platform:    linux/arm64

  Name:        localhost:5000/snip:1.7@sha256:124619f3030b…
  MediaType:   application/vnd.oci.image.manifest.v1+json
  Platform:    unknown/unknown
  Annotations:
    vnd.docker.reference.digest: sha256:5dbacbeb1114…
    vnd.docker.reference.type:   attestation-manifest
  …
```

Two images and, for each, an attestation manifest (the provenance BuildKit adds by default; see below). With the
containerd image store, `docker build --platform …` can also keep multi-platform images locally, without pushing.

The hard part is the build itself. A `RUN` step executes programs from the base image, and an `arm64` program doesn't
run on an `amd64` CPU:

```text
#5 [2/2] RUN node -e "console.log(process.arch)"
#5 0.307 exec /bin/sh: exec format error
```

There are three ways around that.

### 1. Emulation with QEMU

Register QEMU user-mode emulators in the kernel, and the `arm64` binaries run on `amd64`, slowly:

```bash
docker run --privileged --rm tonistiigi/binfmt --install all    # once per machine (or after a reboot)
```

In GitHub Actions, `docker/setup-qemu-action@v4` does the same. Docker Desktop has emulation built in. Emulation is
the easiest option and works for any Dockerfile, but CPU-heavy steps (`npm ci` with native modules compiled by
`node-gyp`, TypeScript compilation, bundling) can be ten or more times slower than native.

### 2. Native builders

Build each platform on a machine of that architecture. Buildx can combine several nodes into one builder:

```bash
docker buildx create --name multi --node amd64 --platform linux/amd64 ssh://build-amd64
docker buildx create --name multi --append --node arm64 --platform linux/arm64 ssh://build-arm64
docker buildx build --builder multi --platform linux/amd64,linux/arm64 -t … --push .
```

In GitHub Actions, the usual pattern is a matrix job, one per platform, on `ubuntu-latest` and an ARM runner (such as
`ubuntu-24.04-arm`), each pushing its image by digest, and a final job that combines them into one index with
`docker buildx imagetools create`. Fast and exact, at the price of a more complex workflow.

### 3. Cross-building with `$BUILDPLATFORM`

Often, most of the work doesn't depend on the target platform at all. Installing pure-JavaScript dependencies
produces the same files on any CPU. So run those stages on the **build machine's** platform, and only assemble the
final image for the target. BuildKit sets these automatic build arguments in every stage:

| Argument | Example | Meaning |
|---|---|---|
| `BUILDPLATFORM` | `linux/amd64` | The platform of the machine running the build |
| `TARGETPLATFORM` | `linux/arm64` | The platform of the image being built |
| `TARGETOS`, `TARGETARCH`, `TARGETVARIANT` | `linux`, `arm64`, `` | Its parts |

Snip's chapter 14 Dockerfile runs `deps` and `test` with `--platform=$BUILDPLATFORM`:

```dockerfile
# deps: production dependencies. It runs on the build machine's own platform ($BUILDPLATFORM),
# even when the image is for another platform: Snip's dependencies are pure JavaScript, so the
# installed node_modules work everywhere, and no emulation is needed.
FROM --platform=$BUILDPLATFORM node:${NODE_VERSION}-slim AS deps
WORKDIR /app
ENV NODE_ENV=production
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --omit=dev

# test: the unit tests, also on the build platform. The build fails if a test fails.
FROM deps AS test
COPY src ./src
COPY test ./test
RUN node --test

# base: the target platform's Node.js image, for the stages that end up in an image
FROM node:${NODE_VERSION}-slim AS base
WORKDIR /app
ENV NODE_ENV=production

# … dev and runtime-slim stages as before, based on "base" …

# runtime: the default target. Distroless, non-root (UID 65532). Only COPY instructions,
# so building it for another platform needs no emulation.
FROM gcr.io/distroless/nodejs${NODE_VERSION}-debian13:nonroot AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY src ./src
COPY migrations ./migrations
# … labels, healthcheck and CMD as before …
```

The `runtime` stage has no `RUN` step, so nothing ever executes on the target platform during the build. The build
log shows the effect: one `deps` stage (for the build platform) and one `runtime` stage per target:

```text
#7  [linux/amd64 deps 1/4] FROM docker.io/library/node:24-slim@sha256:4ca1608ff2dc…
#14 [linux/amd64 deps 4/4] RUN --mount=type=cache,target=/root/.npm,sharing=locked     npm ci --omit=dev
#15 [linux/amd64 runtime 3/6] COPY --from=deps /app/node_modules ./node_modules
#16 [linux/arm64 runtime 3/6] COPY --from=deps /app/node_modules ./node_modules
```

On a machine *without* any emulation, this built and pushed both platforms in under ten seconds. Running the `arm64`
image there fails, of course, and that's the honest test that it really is an `arm64` image:

```bash
docker run --rm --platform linux/arm64 localhost:5000/snip:1.7
```

```text
exec /nodejs/bin/node: exec format error
```

The limit of this trick is **native code**. A package like `bcrypt`, `sharp` or `better-sqlite3` installs a compiled
binary for the platform `npm ci` runs on, and an `amd64` binary in an `arm64` image fails at runtime. For those:

- Many such packages publish **prebuilt binaries per platform** as optional dependencies, and npm (10+) can be told to
  install the ones for another platform: `npm ci --omit=dev --os=linux --cpu=$TARGETARCH` in the `deps` stage
  (with `ARG TARGETARCH` declared). Check that every native dependency supports this.
- Otherwise, run the `deps` stage on the target platform (drop `--platform=$BUILDPLATFORM`) with emulation or a
  native builder.

## Bake: builds as code

The `docker buildx build` commands grow long: targets, tags, platforms, caches, attestations, build arguments. **Bake**
moves them into a file, `docker-bake.hcl`, which can define several builds and run them in parallel:

```hcl
variable "REGISTRY" {
  default = "localhost:5000"
}

variable "TAG" {
  default = "dev-local"
}

variable "GIT_SHA" {
  default = "unknown"
}

group "default" {
  targets = ["test", "image"]
}

# Settings every target shares.
target "_common" {
  context    = "."
  dockerfile = "Dockerfile"
  args = {
    NODE_VERSION = "24"
  }
}

# The unit tests: builds the "test" stage, exports nothing.
target "test" {
  inherits = ["_common"]
  target   = "test"
  output   = ["type=cacheonly"]
}

# The production image for this machine, loaded into the local image store.
target "image" {
  inherits = ["_common"]
  target   = "runtime"
  tags     = ["snip:${TAG}"]
  args = {
    APP_VERSION = TAG
    GIT_SHA     = GIT_SHA
  }
}

# The release: both platforms, SBOM and full provenance, pushed to the registry.
target "release" {
  inherits   = ["image"]
  platforms  = ["linux/amd64", "linux/arm64"]
  tags       = ["${REGISTRY}/snip:${TAG}"]
  attest     = ["type=sbom", "type=provenance,mode=max"]
  output     = ["type=registry"]
  cache-from = ["type=registry,ref=${REGISTRY}/snip:buildcache"]
  cache-to   = ["type=registry,ref=${REGISTRY}/snip:buildcache,mode=max"]
}
```

```bash
docker buildx bake                                    # the default group: test and image, in parallel
docker buildx bake --print release                    # the resolved configuration, as JSON
TAG=1.7.0 GIT_SHA=$(git rev-parse --short HEAD) docker buildx bake release
```

- **Variables** come from the environment (`TAG=1.7.0`), with defaults in the file.
- **`inherits`** composes targets; `_common` is a convention for a target that's only inherited.
- **Groups** build several targets at once; shared stages (`deps`) are built only once.
- `--print` shows what would be built, which is handy in code review and when debugging a pipeline.

After the release build, both platforms carry the version and commit from the variables:

```bash
docker buildx imagetools inspect localhost:5000/snip:1.7.0 --format '{{json .Image}}'
```

```text
linux/amd64  org.opencontainers.image.version=1.7.0  org.opencontainers.image.revision=abc1234
linux/arm64  org.opencontainers.image.version=1.7.0  org.opencontainers.image.revision=abc1234
```

(Output condensed from the JSON.) Bake also reads `compose.yaml` if it's in the same directory, and turns each
service with a `build:` section into a target, so `docker buildx bake api` works too. The CI workflow can call Bake
with `docker/bake-action@v7`, and `docker/metadata-action` can write its tags into a Bake file for it. The pipeline
then lives in `docker-bake.hcl`, where developers can run exactly the same builds locally.

## Attestations: SBOM and provenance

An **attestation** is signed-off metadata attached to an image in the registry, next to it in the same index. BuildKit
can generate two kinds:

- **SBOM** (software bill of materials): a list of every package in the image, OS packages and npm packages alike,
  with versions and licenses. It answers "are we affected by the vulnerability in package X?" for every image you've
  ever shipped, without pulling and scanning them all.
- **Provenance** (SLSA provenance): how the image was built: the base images by digest, the build arguments, the Git
  source and commit, the builder. `mode=min` (the default, added to every pushed image) records the essentials;
  `mode=max` adds the full build definition, including all build arguments, so never pass secrets as arguments
  (chapter 13 explains why that's a bad idea anyway).

```bash
docker buildx build --platform linux/amd64,linux/arm64 --sbom=true --provenance=mode=max \
  -t localhost:5000/snip:1.7 --push .
```

```text
#25 [linux/arm64] generating sbom using docker.io/docker/buildkit-syft-scanner:stable-1
#26 [linux/amd64] generating sbom using docker.io/docker/buildkit-syft-scanner:stable-1
```

The SBOM is generated by a scanner image (Syft) during the build. Reading it back:

```bash
docker buildx imagetools inspect localhost:5000/snip:1.7 --format '{{json .SBOM}}' > sbom.json
```

In it, under `linux/amd64` → `SPDX` → `packages`, are 103 packages for Snip, including `express@5.3.0`,
`pg@8.23.1` and `redis@6.3.0` next to the Debian packages of the distroless base. The provenance is read the same way
with `{{json .Provenance}}`; its `resolvedDependencies` list the exact base images used, such as
`pkg:docker/node@24-slim?platform=linux%2Famd64` and the distroless image.

Attestations only exist in a registry (or an OCI export), not in the classic local image store, which is why the
examples push. Chapter 15 adds signatures, which prove *who* published an image, and chapter 16 uses the SBOM for
vulnerability scanning.

## Key takeaways

- A container pipeline checks the Dockerfile, runs unit tests in the build, builds once, runs integration tests
  against the real image, scans, and then pushes and attests that same image.
- `docker build --check` lints a Dockerfile without building; a `test` stage turns the build into the test run.
- In GitHub Actions, `setup-buildx-action`, `login-action`, `metadata-action` and `build-push-action` cover the whole
  build; `GITHUB_TOKEN` with `packages: write` pushes to GHCR.
- Export the build cache (`type=gha` or `type=registry`) with `mode=max`, or every CI build starts from zero. Cache
  mounts aren't exported.
- Tag with something unique and immutable (`sha-…`, `1.7.0`), use moving tags only for convenience, and deploy by
  digest.
- Multi-platform builds: emulation is easy and slow, native builders are fast and complex, and `$BUILDPLATFORM`
  stages avoid both when the work is platform-independent. Watch out for native modules.
- Bake puts build definitions in a file that CI and developers share.
- SBOM and provenance attestations record what is in an image and how it was built.

## Exercises

1. **A failing test fails the pipeline.** Break a unit test in Snip, run `docker build --target test .` and look at
   the output and exit code. Then build the default target. Does the broken test stop the production image from being
   built? How would you make sure it does?

<details>
<summary>Solution</summary>

`docker build --target test .` fails at `RUN node --test`, prints the test output, and exits with code 1. But
`docker build .` builds the `runtime` stage, which doesn't depend on `test`, and BuildKit skips stages the target
doesn't need: the production image builds fine.

Two fixes:

- **In the pipeline** (what Snip's workflow does): run the `test` target first, as its own step; a failure stops the
  job before anything is built or pushed. Bake's `default` group builds `test` and `image` together and fails as a
  whole if either fails.
- **In the Dockerfile**: make the runtime depend on the test stage, for example
  `COPY --from=test /app/package.json /tmp/tested` in the `runtime` stage. Now every build of the image runs the
  tests. It's simple and foolproof, at the cost of running tests in builds where you don't want them.

</details>

2. **Tags for a release.** For each event, list the tags `metadata-action` produces with Snip's rules: a push to
   `main` at commit `3f2a1c9…`, a pull request, and pushing the Git tag `v2.0.0` at the same commit. Which tag would
   you put in a production deployment file?

<details>
<summary>Solution</summary>

- Push to `main`: `ghcr.io/<owner>/snip:main` and `…:sha-3f2a1c9`.
- Pull request: the `publish` job doesn't run (`if: github.event_name == 'push'`). With the job enabled, `type=ref,
  event=pr` would give `pr-<number>`.
- Tag `v2.0.0`: `…:2.0.0`, `…:2.0` and `…:sha-3f2a1c9`, plus `latest` by default (the `flavor` input controls it).
  The image itself is identical to the one built for `main` at the same commit, but it's built again by this run:
  better still is to *promote* the existing image by adding tags to it, which chapter 15 shows with
  `docker buildx imagetools create`.

For production: none of the tags. Deploy by digest (`ghcr.io/<owner>/snip@sha256:…`), which can't change. If a tag
is required, use the immutable `2.0.0`, never `2.0`, `main` or `latest`.

</details>

3. **Cross-build Snip.** Build Snip for `linux/arm64` on an `amd64` machine (or the reverse on Apple silicon) using
   chapter 14's Dockerfile, without enabling emulation. Then try the same with chapter 13's Dockerfile. Explain both
   results.

<details>
<summary>Solution</summary>

```bash
cd ch14-ci
docker build --platform linux/arm64 -t snip:arm64 .               # works
cd ../ch13-config-and-secrets
docker build --platform linux/arm64 -t snip:arm64 .               # fails at npm ci
```

Chapter 14's `deps` stage runs on `$BUILDPLATFORM` (your machine's), so `npm ci` runs natively, and the `runtime`
stage only copies files into the `arm64` distroless image. Nothing executes for `arm64`.

Chapter 13's `deps` stage is based on `node:24-slim` for the *target* platform, so `npm ci` (and with it
`/bin/sh`) must execute `arm64` binaries: `exec /bin/sh: exec format error`, unless emulation is installed (on Docker
Desktop it is, so the build succeeds there, just slower). On a typical Linux CI runner, it fails until you add
`docker/setup-qemu-action`.

</details>

4. **Bake for development.** Add a Bake target `dev` that builds the `dev` stage as `snip:dev` for the local machine,
   and a group `all` that builds `test`, `image` and `dev`. Show the command that prints what `all` would build.

<details>
<summary>Solution</summary>

```hcl
target "dev" {
  inherits = ["_common"]
  target   = "dev"
  tags     = ["snip:dev"]
}

group "all" {
  targets = ["test", "image", "dev"]
}
```

```bash
docker buildx bake --print all
docker buildx bake all
```

The three targets build in parallel and share the `deps` stage where they use it (`test` and `image` do; `dev` has
its own `npm ci` with devDependencies). Target names share one namespace with the services Bake reads from
`compose.yaml` (`api`, `worker`, `migrate`), so pick names that don't collide with them.

</details>

5. **Find a package in an SBOM.** Push a Snip image with `--sbom=true` to a registry (a local `registry:3` container
   on port 5000 works) and find out, from the registry alone, which version of `pg` and which Debian version of
   `libssl` (or `openssl`) the `linux/amd64` image contains.

<details>
<summary>Solution</summary>

```bash
docker run -d --name reg -p 127.0.0.1:5000:5000 registry:3
docker buildx build --sbom=true -t localhost:5000/snip:sbom --push .
docker buildx imagetools inspect localhost:5000/snip:sbom --format '{{json .SBOM}}' > sbom.json
node -e '
  const sbom = JSON.parse(require("fs").readFileSync("sbom.json"));
  const spdx = sbom.SPDX ?? sbom["linux/amd64"].SPDX;
  for (const p of spdx.packages)
    if (/^(pg|libssl.*|openssl)$/.test(p.name)) console.log(p.name, p.versionInfo);'
```

For a single-platform push, the JSON has `SPDX` at the top; for a multi-platform image it's keyed by platform. The
same question, asked across all your images, is what SBOM-aware tools (Docker Scout, Trivy, Grype, Dependency-Track)
answer when a new vulnerability is announced. Remove the registry with `docker rm -f reg` when you're done.

</details>

---
← Previous: [Configuration and secrets](13-configuration-and-secrets.md) | Next: [Registries and distribution](15-registries-and-distribution.md) →
