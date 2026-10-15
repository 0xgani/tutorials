# Chapter 3 — Images and registries

## What you'll learn

- What an image really is: layers, a configuration and a manifest, all addressed by content hashes
- How layers stack into one filesystem, and why layer sharing makes images cheap to store and pull
- How to read an image's history and configuration
- The anatomy of an image reference: registry, namespace, repository, tag and digest
- Why tags are moving pointers, what `latest` really means, and when to pin by digest
- Docker Hub, official images, pull rate limits and other registries
- How to choose a Node.js base image: full Debian, slim, Alpine or distroless
- How multi-platform images work, and what `exec format error` means
- The containerd image store, which Engine 29 uses by default, and what its size columns mean
- How to tag, remove, save, load and prune images

## What an image is

In chapter 1 you treated an image as "a filesystem plus some metadata". Precisely, an image is a small set of
**content-addressed** objects (each one named by the SHA-256 hash of its bytes):

```text
  index (optional)  ─── "for linux/amd64 use manifest A, for linux/arm64 use manifest B"
     │
     ▼
  manifest  ─── lists: one config + an ordered list of layers (by digest and size)
     │
     ├──▶ config (JSON): default command, entrypoint, env vars, user, working dir, exposed ports,
     │                    labels, architecture/OS, and the history of how it was built
     │
     └──▶ layers: compressed tar archives of filesystem changes, applied in order
           layer 1: Alpine Linux minimal root filesystem
           layer 2: Node.js binaries, npm, the "node" user
           layer 3: Yarn
           layer 4: docker-entrypoint.sh
```

Because every object is named by its hash:

- **Images are immutable.** Changing one byte changes the hash, so it would be a different object.
- **Identical layers are stored and downloaded once.** Ten images built `FROM node:24-alpine` share its layers on
  disk and in the registry. Pulling the eleventh downloads only its own new layers.
- **Integrity is built in.** Docker verifies every blob's hash after downloading it.

### Layers and the union filesystem

Each layer records *changes* relative to the layers below it: files added, modified or deleted (a deletion is stored
as a special "whiteout" marker). At runtime, a union filesystem (OverlayFS on Linux) stacks the read-only layers and
adds one writable layer on top for the container:

```text
   container's view of /           ┌────────────────────────────────────┐
   (one merged directory tree)     │ writable layer (this container)    │ ◀── your changes; deleted with the container
                                   ├────────────────────────────────────┤
                                   │ layer 4: docker-entrypoint.sh      │
     read-only, shared by every    │ layer 3: yarn                      │
     container from this image ──▶ │ layer 2: node, npm                 │
                                   │ layer 1: Alpine root filesystem    │
                                   └────────────────────────────────────┘
```

When a container modifies a file that lives in a lower layer, the file is first **copied up** into the writable
layer and changed there (copy-on-write). Reading is free; the first write to a large file costs a copy.

Two practical consequences you will use in chapter 4 and onwards:

1. **Deleting a file in a later layer doesn't make the image smaller.** The file still exists in the lower layer; the
   upper layer merely hides it. `RUN npm install && rm -rf ~/.npm` in *one* step keeps the cache out of the image;
   doing the `rm` in a separate step does not.
2. **Layers are the unit of caching.** When you rebuild an image, unchanged steps reuse their existing layers.
   Ordering the steps well is the main trick of fast builds (chapter 6).

### Looking inside an image

`docker image history` lists the layers and the instruction that created each one, newest first:

```bash
docker image history node:24-alpine
```

```text
IMAGE          CREATED        CREATED BY                                      SIZE      COMMENT
44fce696f38b   14 hours ago   CMD ["node"]                                    0B        buildkit.dockerfile.v0
<missing>      14 hours ago   ENTRYPOINT ["docker-entrypoint.sh"]             0B        buildkit.dockerfile.v0
<missing>      14 hours ago   COPY docker-entrypoint.sh /usr/local/bin/ # …   20.5kB    buildkit.dockerfile.v0
<missing>      14 hours ago   RUN /bin/sh -c apk add --no-cache --virtual …   5.48MB    buildkit.dockerfile.v0
<missing>      14 hours ago   ENV YARN_VERSION=1.22.22                        0B        buildkit.dockerfile.v0
<missing>      14 hours ago   RUN /bin/sh -c addgroup -g 1000 node     && …   166MB     buildkit.dockerfile.v0
<missing>      14 hours ago   ENV NODE_VERSION=24.22.0                        0B        buildkit.dockerfile.v0
<missing>      3 weeks ago    CMD ["/bin/sh"]                                 0B        buildkit.dockerfile.v0
<missing>      3 weeks ago    ADD alpine-minirootfs-3.24.2-x86_64.tar.gz /…   9.08MB    buildkit.dockerfile.v0
```

Read it bottom-up and you can reconstruct the Dockerfile: the Alpine image added a root filesystem and set
`CMD ["/bin/sh"]`; the Node image built on it, installing Node.js (166 MB uncompressed, by far the largest layer) and
Yarn, adding an entrypoint script and replacing `CMD` with `node`. Instructions such as `ENV`, `CMD` and
`ENTRYPOINT` change only the configuration and produce 0-byte history entries. `<missing>` just means that
intermediate step isn't available as an image of its own on your machine; it isn't an error. Add `--no-trunc` to see
full commands.

`docker image inspect` shows the configuration a container inherits:

```bash
docker image inspect -f '{{json .Config}}' node:24-alpine | jq
```

```text
{
  "Env": [
    "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
    "NODE_VERSION=24.22.0",
    "YARN_VERSION=1.22.22"
  ],
  "Entrypoint": [
    "docker-entrypoint.sh"
  ],
  "Cmd": [
    "node"
  ],
  "WorkingDir": "/"
}
```

and `-f '{{json .RootFS.Layers}}'` lists the layer digests. These are the uncompressed "diff IDs"; the registry uses
the digests of the compressed blobs instead, which is why the two lists of hashes never match.

## Image references

Every image is addressed by a reference with up to five parts:

```text
  ghcr.io:443 / acme / snip-api : 1.4.2 @ sha256:7d98…
  └─registry─┘  └ns─┘ └─repo──┘  └tag┘   └─digest──┘
```

Anything you leave out gets a default:

| You write | Docker reads it as |
|---|---|
| `node` | `docker.io/library/node:latest` |
| `node:24-alpine` | `docker.io/library/node:24-alpine` |
| `bitnami/redis:8.2` | `docker.io/bitnami/redis:8.2` |
| `ghcr.io/acme/snip-api:1.4.2` | registry `ghcr.io`, repository `acme/snip-api`, tag `1.4.2` |
| `localhost:5000/snip` | a registry on your own machine (chapter 15), tag `latest` |
| `node:24-alpine@sha256:44fc…` | exactly that content; the tag is only a comment |

- The **registry** is a hostname (and optional port) at the start. If the first part has no dot or colon and isn't
  `localhost`, it isn't a registry, and Docker Hub (`docker.io`) is assumed.
- On Docker Hub, single-name repositories like `node` live in the special `library` namespace: these are the
  **Docker Official Images**.
- The **tag** is a human-friendly label, up to 128 characters, defaulting to `latest`.
- The **digest** is the SHA-256 of the manifest (or index). It identifies content exactly and can never change.

### Tags move, digests don't

A tag is a mutable pointer, like a Git branch. `node:24-alpine` today points to Node 24.22.0 on Alpine 3.24; next
month it will point to 24.23.0, possibly on a newer Alpine. The Node image is republished whenever Node *or* the
base image gets an update, so the same tag points to new content every few days. The digest is like a commit hash:

```bash
docker pull node:24-alpine@sha256:44fce696f38ba8c987c4db89954051000bbd05ffd8d8659ba15ed4a8602e937f
```

pulls exactly that content, today or in two years (as long as the registry keeps it).

**`latest` is not special.** It is simply the tag used when you don't give one. It doesn't mean "newest" (a
maintainer can push an old version as `latest`, or never update it), and for most official images it means "the
newest *major* version", which will one day break your app. Never use `latest` (implicitly or explicitly) for
anything you deploy.

How specific to be is a trade-off between getting fixes automatically and getting surprises:

| Reference | You get | Good for |
|---|---|---|
| `node:24-alpine` | Every Node 24 minor and patch release, every Alpine update | Development, examples, most Dockerfiles with regular rebuilds |
| `node:24.22-alpine3.24` | Patches only, a fixed Alpine | Production Dockerfiles that want predictability |
| `node:24.22.0-alpine3.24@sha256:44fc…` | Exactly one image | Fully reproducible builds; combine with a bot that updates it |

A widely used production practice is the third form, kept up to date automatically by Dependabot or Renovate, which
open a pull request whenever a new digest appears. You get reproducible builds *and* timely security fixes, and every
change of base image goes through code review and CI.

## Registries

A **registry** stores and serves images over the OCI distribution API (plain HTTPS). Inside a registry, a
**repository** holds the versions of one image (`node`), each version identified by digest and optionally by one or
more tags.

### Docker Hub

Docker Hub (`docker.io`, browsed at <https://hub.docker.com>) is the default registry. Kinds of images you'll find:

- **Docker Official Images** (`node`, `postgres`, `nginx`, `python`, `alpine`, …): curated, documented, rebuilt
  promptly for security fixes, published for several CPU architectures. Their Dockerfiles are on GitHub; each image's
  Hub page links to them. Prefer these for base images.
- **Verified Publisher** images, published by the software vendors themselves (`grafana/grafana`, `bitnami/…`).
- **Sponsored open source** projects (for example `valkey/valkey`).
- Everything else, from anyone. Treat unknown images like unknown npm packages: read the Dockerfile, check activity,
  or don't run them.

Docker Hub **limits pulls**: anonymous users get 100 pulls per 6 hours per IP address (or IPv6 /64 subnet),
authenticated free accounts 200, paid plans are unlimited (with fair use). A pull counts per image manifest
request, not per layer. The limit rarely bites on a laptop but often does in CI, where many jobs share an IP address.
The error looks like `toomanyrequests: You have reached your unauthenticated pull rate limit`. The fixes are logging
in (`docker login`), using a pull-through cache or mirror, or pulling from another registry (chapter 15).

### Other registries you will meet

| Registry | Hostname | Notes |
|---|---|---|
| GitHub Container Registry | `ghcr.io` | Free for public images; integrates with GitHub Actions (chapter 14) |
| Amazon ECR / ECR Public | `<account>.dkr.ecr.<region>.amazonaws.com`, `public.ecr.aws` | ECR Public mirrors the official images: `public.ecr.aws/docker/library/node` |
| Google Artifact Registry | `<region>-docker.pkg.dev` | Google also hosts the distroless images at `gcr.io/distroless/…` |
| Azure Container Registry | `<name>.azurecr.io` | Microsoft's own images live at `mcr.microsoft.com` |
| Quay | `quay.io` | Red Hat's registry |
| GitLab | `registry.gitlab.com` | Built into GitLab projects |
| Your own | `registry.example.com` | The open-source `registry` image, Harbor, Nexus, Artifactory (chapter 15) |

Pulling from any of them is the same command with a full reference: `docker pull ghcr.io/acme/snip-api:1.4.2`.
Private repositories need `docker login <registry>` first.

## Choosing a Node.js base image

The official `node` image comes in several **variants**, chosen with the tag. For Node 24:

```bash
docker image ls 'node*'
```

```text
IMAGE            ID             DISK USAGE   CONTENT SIZE   EXTRA
node:24          14078204b6bb       1.66GB          428MB
node:24-alpine   44fce696f38b        244MB         62.8MB
node:24-slim     a5f4366e8346        333MB         83.6MB
```

(*Content size* is what you download, compressed; *disk usage* includes the unpacked files. More on both below.)

| Variant | Based on | Contains | Choose it when |
|---|---|---|---|
| `node:24` (also `24-bookworm`) | Debian 12, full "buildpack-deps" | Compilers, `git`, `python3`, `curl`, ImageMagick libraries, hundreds of tools | You build native modules and want everything at hand. Mostly as a *build* stage (chapter 7), rarely as the final image |
| `node:24-slim` (also `24-bookworm-slim`) | Debian 12, minimal | glibc, a shell, core utilities; no compilers | The default choice for most production images |
| `node:24-alpine` (also `24-alpine3.24`) | Alpine Linux | musl libc, BusyBox; very small | Small images, *if* your dependencies work on musl (below) |
| `node:24-trixie`, `node:24-trixie-slim` | Debian 13 | As above, on the newer Debian release | You want newer system libraries; the default tags will move to Debian 13 eventually |
| `gcr.io/distroless/nodejs24-debian13` | Debian 13, by Google | Node.js, glibc, CA certificates, time zones. **No shell, no package manager** | The final stage of a multi-stage build when you want the minimum attack surface (chapter 7) |

You can check what you get:

```bash
for i in node:24 node:24-slim node:24-alpine; do
  printf '%s: ' $i
  docker run --rm $i sh -c '. /etc/os-release; echo "$PRETTY_NAME, $(ls /usr/bin /bin | wc -l) programs in /bin and /usr/bin"'
done
```

```text
node:24: Debian GNU/Linux 12 (bookworm), 1335 programs in /bin and /usr/bin
node:24-slim: Debian GNU/Linux 12 (bookworm), 553 programs in /bin and /usr/bin
node:24-alpine: Alpine Linux v3.24, 228 programs in /bin and /usr/bin
```

Every one of those programs is something an attacker who gets code execution in your container can use, and
something a vulnerability scanner will report. Smaller is better, as long as your app still works.

### Alpine and musl: the trade-off

Alpine uses **musl** instead of glibc as its C library. For pure-JavaScript apps that makes no difference. It matters
when:

- **npm packages ship prebuilt native binaries** (for example `sharp`, `bcrypt`, `@swc/core`, Prisma's engines,
  `better-sqlite3`). They need a build for musl (`linux-x64-musl`). Popular packages provide one; others fall back to
  compiling from source (which needs Python and a compiler in the image) or fail at runtime with errors like
  `Error relocating …: symbol not found`.
- **DNS behaves slightly differently.** musl's resolver handles some `/etc/resolv.conf` options and large responses
  differently from glibc's, which occasionally surprises people in Kubernetes clusters.
- **Performance** of some workloads (heavy memory allocation, some crypto) differs between musl and glibc.

The Node.js project treats musl builds as *experimental* support, maintained by the Docker image maintainers rather
than the core team. A sound default: use **`node:24-slim`** unless you have tested your app on Alpine and want the
smaller image. This tutorial uses `node:24-alpine` for quick throwaway commands (it downloads fast) and builds Snip's
production image on Debian slim and distroless in chapter 7.

### Node.js release lines

Use an **LTS** (long-term support) release line in production: even-numbered major versions become LTS in October
of the year they are released. As of October 2026, Node 24 is the Active LTS line used throughout this tutorial,
Node 22 is in maintenance, and Node 26 (released in May 2026) becomes LTS in late October 2026. Moving to a new major
version in Docker is a one-line change of the `FROM` tag, followed by your test suite.

## Multi-platform images

An image contains machine code for one CPU architecture. To serve both Intel/AMD (`amd64`) servers and ARM (`arm64`:
Apple Silicon Macs, AWS Graviton, Raspberry Pi) machines with one name, registries store an **image index** (also
called a manifest list): one entry per platform, each pointing to a separate manifest.

```bash
docker buildx imagetools inspect node:24-alpine
```

```text
Name:      docker.io/library/node:24-alpine
MediaType: application/vnd.oci.image.index.v1+json
Digest:    sha256:44fce696f38ba8c987c4db89954051000bbd05ffd8d8659ba15ed4a8602e937f

Manifests:
  Name:        docker.io/library/node:24-alpine@sha256:429bdfbc204b4eabc6438e6d34e4e9d8dd2ca1df8289bcd78cdd3c5db387b7a5
  MediaType:   application/vnd.oci.image.manifest.v1+json
  Platform:    linux/amd64
  Annotations:
    org.opencontainers.image.base.name:       alpine:3.24
    org.opencontainers.image.created:         2026-10-14T18:28:56Z
    org.opencontainers.image.source:          https://github.com/nodejs/docker-node.git#f27125…:24/alpine3.24
    org.opencontainers.image.version:         24-alpine
    …

  Name:        docker.io/library/node:24-alpine@sha256:e21da4eb6e025eec399b5bbb63232f6d3f352f8c6215f4a40b61e6a5b844438f
  MediaType:   application/vnd.oci.image.manifest.v1+json
  Platform:    unknown/unknown
  Annotations:
    vnd.docker.reference.digest:              sha256:429bdfbc204b4eabc6438e6d34e4e9d8dd2ca1df8289bcd78cdd3c5db387b7a5
    vnd.docker.reference.type:                attestation-manifest

  Name:        docker.io/library/node:24-alpine@sha256:5b12456322560dcf9342f7339662bccf8c5665cf0732a8ec8363687aa93c5277
  MediaType:   application/vnd.oci.image.manifest.v1+json
  Platform:    linux/arm64/v8
  …
```

`imagetools inspect` reads the registry directly, without pulling anything. You can see the index's digest (the one
you pin), one manifest per platform, and `unknown/unknown` entries that aren't images at all but **attestations**
(build provenance and a software bill of materials, chapter 14). The annotations even tell you the image's base and
the exact Git commit of the Dockerfile that built it.

When you pull or run, Docker picks the manifest matching your machine. You can ask for another platform:

```bash
docker run --rm --platform linux/arm64 alpine:3 uname -m
```

On Docker Desktop this prints `aarch64`: the Desktop VM includes **QEMU** user-mode emulation, so foreign binaries
run, just several times slower. On a Linux host without emulation set up, you get:

```text
exec /bin/uname: exec format error
```

`exec format error` always means "this binary is for a different CPU architecture" (or, occasionally, a script with a
broken first line). You will meet it when an image exists only for `amd64` and you run it on an ARM machine, or when
you build on an Apple Silicon Mac and deploy to an `amd64` server without building for that platform. Chapter 14
shows how to build multi-platform images properly, and how to install emulation on Linux.

## The containerd image store

Engine 29 stores images in **containerd's image store** by default on fresh installs (older installations, and
Engine 28 and earlier, used Docker's own "graph driver" store, usually `overlay2`). `docker info` tells you which:

```text
 Storage Driver: overlayfs
  driver-type: io.containerd.snapshotter.v1
```

Visible differences:

- **Images keep their multi-platform index.** The image ID you see is the index's digest, and the store knows about
  every platform, even those it hasn't downloaded. `docker image ls --tree` shows them:

  ```bash
  docker image ls --tree node:24-alpine
  ```

  ```text
  IMAGE                   ID             DISK USAGE   CONTENT SIZE   EXTRA
  node:24-alpine          44fce696f38b        244MB         62.8MB
  ├─ linux/amd64          429bdfbc204b        243MB         62.5MB
  ├─ linux/arm64/v8       5b1245632256           0B             0B
  └─ linux/s390x          26b87499da28           0B             0B
  ```

- **Two sizes.** *Content size* is the compressed blobs, kept so that the image can be pushed or saved again exactly
  as pulled. *Disk usage* adds the unpacked snapshots that containers actually use. The containerd store therefore uses
  more disk than the old store, which kept only unpacked layers.
- **It can build and load multi-platform images** and keep attestations, which the old store could not (chapter 14).
- `docker image ls` (Engine 29+) shows a compact view and hides untagged images unless you add `--all`.

Everything else (`pull`, `run`, `build`, `push`) works the same. If you upgrade an older installation, Docker keeps
the old store until you switch in Docker Desktop's settings or with `"features": {"containerd-snapshotter": true}` in
`/etc/docker/daemon.json`; images in the old store aren't visible after switching (pull or rebuild them).

## Managing images

```bash
docker pull node:24-slim                          # download (all layers you don't have yet)
docker image ls                                   # list (also: docker images)
docker image ls --digests node                    # with digests
docker tag node:24-alpine myorg/node-base:1.0     # a second name for the same image (no copy)
docker image rm myorg/node-base:1.0               # remove a name; content is deleted when no name is left
docker image prune                                # remove dangling images (no tag, not used)
docker image prune -a                             # remove all images not used by a container
```

`docker tag` doesn't copy anything; it adds a name pointing to the same content. `docker image rm` (`docker rmi`)
removes the name, and the content only goes when nothing refers to it any more. Docker refuses to remove an image
that a container (even a stopped one) uses, unless you add `-f`.

A **dangling image** is one that has lost its tag: when you rebuild `snip:dev`, the tag moves to the new image and
the old one becomes `<none>`. They accumulate quickly during development; `docker image prune` clears them.

### Moving images without a registry

`docker save` writes images to a tar archive (in the OCI image layout: an `index.json` and a `blobs/` directory of
content-addressed files), and `docker load` reads one back in. Useful for air-gapped servers or to hand an image to a
colleague:

```bash
docker save node:24-alpine -o node.tar
tar tf node.tar | head -3        # blobs/  blobs/sha256/  blobs/sha256/09fd0d32…
docker load -i node.tar
docker save snip:1.0 | gzip | ssh server 'gunzip | docker load'
```

Don't confuse `save`/`load` (images, with layers and metadata) with `export`/`import`, which flatten a *container's*
filesystem into a single tar and lose the image configuration and history. You rarely want the latter.

## Key takeaways

- An image is a manifest pointing to a config (default command, env, user…) and an ordered list of layers, all
  content-addressed by SHA-256. Images are immutable and layers are shared between images.
- Containers see the layers merged by a union filesystem, with a private writable layer on top. Deleting a file in a
  later layer hides it but doesn't shrink the image.
- A reference is `registry/namespace/repository:tag@digest`; the defaults are `docker.io`, `library` and `latest`.
- Tags are moving pointers; digests are immutable. Never deploy `latest`. Pin precisely in production and let a bot
  update the pin.
- Prefer Docker Official Images. Docker Hub limits anonymous pulls (100 per 6 hours per IP), which matters in CI.
- For Node.js, `node:24-slim` is a safe default, `node:24-alpine` is smaller but uses musl, the full `node:24` belongs in
  build stages, and distroless is the most minimal runtime.
- Multi-platform images are an index of per-platform manifests. `exec format error` means the binary's architecture
  doesn't match the CPU.
- Engine 29 uses the containerd image store by default: it keeps compressed content and the multi-platform index,
  shown by `docker image ls --tree`.

## Exercises

1. **Decode references.** For each reference, name the registry, repository, tag and whether it's an official image:
   (a) `postgres`; (b) `postgres:18-alpine`; (c) `grafana/grafana:12.2.0`; (d) `ghcr.io/acme/snip-api`;
   (e) `localhost:5000/snip:dev`; (f) `registry.example.com:8443/team/api@sha256:4b1f…`.

<details>
<summary>Solution</summary>

| | Registry | Repository | Tag | Official? |
|---|---|---|---|---|
| (a) | `docker.io` | `library/postgres` | `latest` | Yes |
| (b) | `docker.io` | `library/postgres` | `18-alpine` | Yes |
| (c) | `docker.io` | `grafana/grafana` | `12.2.0` | No (Verified Publisher) |
| (d) | `ghcr.io` | `acme/snip-api` | `latest` | No |
| (e) | `localhost:5000` | `snip` | `dev` | No |
| (f) | `registry.example.com:8443` | `team/api` | none; pinned by digest | No |

In (e), `localhost:5000` is recognized as a registry because it contains a colon (and is `localhost`). In (f) the
digest identifies the content; a tag, if present, would be ignored for resolution.

</details>

2. **Read the history.** Run `docker image history --no-trunc postgres:18-alpine` (or any official image you like).
   Which instruction produced the largest layer? Which entries are 0 B, and why? Which environment variables does the
   image set?

<details>
<summary>Solution</summary>

The largest layer is the `RUN` step that downloads, compiles or installs PostgreSQL itself (well over 100 MB
uncompressed); the base Alpine root filesystem is around 9 MB. `ENV`, `CMD`, `ENTRYPOINT`, `EXPOSE`, `STOPSIGNAL`,
`VOLUME` and similar instructions are 0 B because they change only the image configuration, not the filesystem.

```bash
docker image inspect -f '{{range .Config.Env}}{{println .}}{{end}}' postgres:18-alpine
```

shows variables such as `PG_MAJOR=18`, `PG_VERSION=18.x`, `PGDATA=/var/lib/postgresql/18/docker` (PostgreSQL 18
images use a version-specific data directory), plus `PATH` and locale settings. The image also declares
`STOPSIGNAL SIGINT` (PostgreSQL's "fast shutdown") and a `VOLUME`; `docker image inspect -f '{{.Config.StopSignal}}
{{json .Config.Volumes}}' postgres:18-alpine` shows both.

</details>

3. **Pin by digest.** Find the index digest of `node:24-slim` (two ways: `docker image ls --digests` and
   `docker buildx imagetools inspect`). Run `node --version` from the image referenced *only* by digest. Then explain
   why `docker image ls --tree` shows the same value as the image ID.

<details>
<summary>Solution</summary>

```bash
docker image ls --digests node                       # DIGEST column for 24-slim
docker buildx imagetools inspect node:24-slim | head -3
docker run --rm node@sha256:<digest> node --version
```

A reference with only a digest (`node@sha256:…`) works and is exactly as precise as `node:24-slim@sha256:…`; adding
the tag just helps humans.

With the containerd image store, the image ID *is* the digest of the image index (the top-level object), so the first
12 hex characters of the ID match the digest. With the old store, the ID was the hash of the image's config instead,
which never matched the registry digest and confused many people.

</details>

4. **Compare base images.** Using `docker run --rm <image> …`, find out for `node:24`, `node:24-slim`,
   `node:24-alpine` and `gcr.io/distroless/nodejs24-debian13`: (a) the C library (glibc or musl), (b) whether `sh` and
   `curl` exist, (c) the user the image runs as by default. Hint: distroless has no shell; its entrypoint is `node`.

<details>
<summary>Solution</summary>

```bash
for i in node:24 node:24-slim node:24-alpine; do
  echo "== $i"
  docker run --rm $i sh -c 'ldd --version 2>&1 | head -1; command -v curl || echo "no curl"; whoami'
done

# distroless: no shell, so ask node itself
docker run --rm gcr.io/distroless/nodejs24-debian13 -e '
  const fs = require("node:fs");
  console.log(process.report.getReport().header.glibcVersionRuntime ?? "musl/unknown");
  console.log(["/bin/sh", "/usr/bin/curl"].map(f => `${f}: ${fs.existsSync(f)}`).join(", "));
  console.log("uid", process.getuid())'
```

| Image | C library | `sh` | `curl` | Default user |
|---|---|---|---|---|
| `node:24` | glibc | yes | yes | root |
| `node:24-slim` | glibc | yes | no | root |
| `node:24-alpine` | musl (`ldd` prints `musl libc`) | yes (BusyBox) | no | root |
| distroless | glibc | no | no | root (`uid 0`); use the `:nonroot` tag for UID 65532 |

All the `node` variants include a `node` user (UID 1000) but don't switch to it; chapter 7 does. `process.report`'s
`glibcVersionRuntime` is a handy way to detect glibc from Node without a shell.

</details>

5. **Platforms.** Show which platforms `node:24-alpine` and `postgres:18-alpine` are published for, without pulling
   anything. Then try running an `arm64` (or, on an ARM Mac, `amd64`) container. What happens on your machine, and why?

<details>
<summary>Solution</summary>

```bash
docker buildx imagetools inspect node:24-alpine | grep Platform
docker buildx imagetools inspect postgres:18-alpine | grep Platform
docker run --rm --platform linux/arm64 node:24-alpine node -p process.arch
```

Each `Platform:` line is one variant; `unknown/unknown` lines are attestations. On Docker Desktop the foreign-platform
container runs (prints `arm64`) through QEMU emulation, slowly. On a Linux host without binfmt/QEMU emulation
installed it fails with `exec format error`, because the kernel can't execute machine code for another architecture.
The image (the other platform's variant) was still pulled: `docker image ls --tree node:24-alpine` now shows a
non-zero size for it.

</details>

---
← Previous: [Running containers](02-running-containers.md) | Next: [Your first Dockerfile](04-your-first-dockerfile.md) →
