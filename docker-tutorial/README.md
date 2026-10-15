# The Docker Tutorial — from first container to production

A long-form, text-only tutorial that takes you from "what is a container?" to designing, building, debugging, securing
and shipping real containerized applications. It covers the everyday tools in depth (`docker run`, Dockerfiles,
BuildKit, volumes, networks, Docker Compose), how containers work inside (namespaces, cgroups, layered filesystems,
the OCI image format, containerd and runc), and everything around them in production: small and secure images,
configuration and secrets, CI pipelines, multi-platform builds, registries, resource limits, logging, graceful
shutdown, deployment, troubleshooting and what lies beyond a single host. It closes with a capstone project that puts
everything together.

The tutorial targets **Docker Engine 29**, **Docker Compose v5** and **Buildx/BuildKit** as they ship in October 2026
(every example was run against Engine 29.8, Compose 5.6 and Buildx 0.37). Features that arrived in recent releases are
labelled with the version that introduced them, for example "Engine 29+" or "Compose 2.22+", so you know what works
on an older installation. All application examples use **Node.js 24**, the current Active LTS line.

## Who it's for

- You know JavaScript and Node.js: you can write an HTTP server, you know what `package.json`, `npm ci` and
  `node_modules` are, and you have deployed (or tried to deploy) a Node app somewhere.
- You have heard of Docker, maybe copied a Dockerfile from a blog post, but you want to understand it: why a build is
  slow, why an image is 1.6 GB, why `localhost` doesn't work inside a container, why Ctrl+C takes ten seconds, why the
  database lost its data.
- You want to become *skilled*: able to containerize any Node.js application properly, run multi-service systems with
  Compose, build images in CI, ship them securely and fix them when they break.

The tutorial does not teach Node.js. Where a Node detail matters for containers (signal handling, memory limits,
`npm ci`, native modules, `--watch`), it is explained briefly in place.

## Prerequisites

| You need | Why | Check |
|---|---|---|
| Docker Engine 29 (Linux) or Docker Desktop (macOS, Windows, Linux) | Running every example | `docker version` |
| Docker Compose v5 (the `docker compose` plugin) | Part II onwards | `docker compose version` |
| Docker Buildx (bundled with both) | Building images (BuildKit) | `docker buildx version` |
| A terminal with `curl` | Talking to the containers you start | `curl --version` |
| A text editor | Writing Dockerfiles, compose files and JavaScript | |
| Optional: Node.js 24 | Running the example app outside a container for comparison | `node --version` |
| Optional: a GitHub account | Chapter 14 (CI) and chapter 15 (publishing to a registry) | |

Chapter 1 shows how to install Docker on macOS, Windows and Linux. You do **not** need Node.js installed to follow
along: one of the points of Docker is that the Node.js runtime comes inside the image.

## How to use this tutorial

- **Read in order.** Each chapter builds on the previous ones, and the running example grows as you go.
- **Type the commands and run them.** Where output matters, it is shown in a `text` block right after the command.
  Container IDs, image IDs, digests, timestamps, sizes and download progress will differ on your machine; the chapter
  says what to look at.
- **Clean up as you go.** Docker happily keeps every stopped container, unused image and orphaned volume forever.
  Chapter 2 shows how to find and remove them, and each chapter's examples use `--rm` or names you can delete.
- **Do the exercises.** Every chapter ends with 3–6 exercises from easy to hard. Solutions are hidden in collapsible
  "Solution" sections. Try first, then compare.
- **Keep the reference open.** The official documentation at <https://docs.docker.com/> is good and complete, in
  particular the [Dockerfile reference](https://docs.docker.com/reference/dockerfile/) and the
  [Compose file reference](https://docs.docker.com/reference/compose-file/). This tutorial links to it where it matters.

### The running example: Snip

Throughout the tutorial you containerize **Snip**, a small URL shortener written in Node.js with Express. You `POST` a
long URL, get back a short code, and `GET /<code>` redirects you while Snip counts the clicks. It starts in chapter 4
as a single file with an in-memory map, gets a proper multi-stage, non-root production image in chapter 7, moves its
data into PostgreSQL with Docker Compose in chapter 10, gains a Valkey cache, a background worker that aggregates
clicks and health-checked startup ordering in chapter 11, a hot-reloading development setup in chapter 12, real
configuration and secrets in chapter 13, a CI pipeline in chapter 14, and is hardened, limited, logged and deployed in
Part III. The capstone (chapter 22) assembles the complete system: API, worker, migrations, PostgreSQL, Valkey and an
nginx reverse proxy, with separate development and production setups. Snapshots of the app at key chapters are in
[`examples/`](examples/), and the finished project is in [`examples/snip/`](examples/snip/).

## Markers

One marker appears throughout. It always uses the same wording, so you can search for it.

> **Just a taste — you don't need to learn or fully understand this now.** This marks a snippet that only shows that a
> feature exists. A later chapter (named in the note) teaches it properly, or the feature is niche enough that knowing
> it exists is all you need.

Snippets without a marker are meant to be learned.

## Table of contents

### Part I — Containers and images

1. [Getting started](01-getting-started.md) — what containers are and are not, containers vs. virtual machines, the
   pieces of Docker (CLI, Engine, containerd, BuildKit, Compose, Desktop, registries), installing Docker, versions,
   your first containers, and what happens when you type `docker run`.
2. [Running containers](02-running-containers.md) — the container lifecycle, `docker run` and its essential flags,
   detached and interactive containers, ports, environment variables, logs, `exec`, signals and exit codes, restart
   policies, `inspect` and Go templates, copying files, and cleaning up.
3. [Images and registries](03-images-and-registries.md) — what an image really is, layers, image references, tags
   and digests, Docker Hub and official images, choosing a Node.js base image, multi-platform images, inspecting
   images, the containerd image store (default in Engine 29+), and pruning.
4. [Your first Dockerfile](04-your-first-dockerfile.md) — the Snip app, `FROM`/`WORKDIR`/`COPY`/`RUN`/`CMD`, the
   build context and `.dockerignore`, `npm ci` and layer order, exec form vs. shell form, PID 1 and graceful shutdown in
   Node.js, tagging and running your image.
5. [Dockerfile instructions in depth](05-dockerfile-instructions-in-depth.md) — `ARG` and `ENV`, `ENTRYPOINT` vs.
   `CMD`, `USER`, `EXPOSE`, `LABEL`, `HEALTHCHECK`, `STOPSIGNAL`, `SHELL`, `ADD` vs. `COPY`, `COPY --chown`/`--chmod`/
   `--link`/`--exclude`/`--parents`, heredocs, parser directives and build checks.
6. [The build cache and BuildKit](06-build-cache-and-buildkit.md) — how the cache decides, ordering for speed, cache
   mounts, secret and SSH mounts, build arguments, named build contexts, builders and drivers, exporting the cache,
   build output and debugging a build.
7. [Multi-stage builds and small, secure images](07-multi-stage-builds-and-small-images.md) — stages and targets,
   production-only dependencies, native modules, Alpine vs. Debian slim vs. distroless, measuring image size, running
   as non-root, and the Snip production image.

### Part II — Data, networks and Compose

8. [Storage: volumes, bind mounts and tmpfs](08-storage-volumes-bind-mounts-and-tmpfs.md) — the writable layer,
   named volumes, bind mounts, `--mount` vs. `-v`, file ownership and UIDs, read-only containers, tmpfs, image mounts,
   and backing up a volume.
9. [Networking](09-networking.md) — the default bridge vs. user-defined networks, DNS-based service discovery,
   publishing ports and what `0.0.0.0` means, host and none networks, internal networks, reaching the host, IPv6, and
   how it works underneath (veth pairs, bridges, NAT).
10. [Docker Compose basics](10-docker-compose-basics.md) — `compose.yaml`, services, builds, ports, environment,
    volumes and networks, the project model, `up`/`down`/`logs`/`exec`/`run`, and Snip with PostgreSQL.
11. [Compose in depth](11-compose-in-depth.md) — healthchecks and `depends_on` conditions, one-off migration jobs,
    variable interpolation and `.env`, profiles, override files and merging, `include` and `extends`, scaling, YAML
    fragments, and Snip with Valkey and a worker.
12. [Developing with containers](12-developing-with-containers.md) — bind-mounted source and `node --watch`,
    `node_modules` in containers, Compose Watch, debugging Node.js in a container, running tests in containers, and
    dev containers.
13. [Configuration and secrets](13-configuration-and-secrets.md) — twelve-factor configuration, build time vs. run
    time, why secrets never belong in images, build secrets, Compose secrets and `/run/secrets`, the `_FILE` convention,
    and validating configuration at startup.

### Part III — Shipping and running in production

14. [CI pipelines and multi-platform builds](14-ci-pipelines-and-multi-platform-builds.md) — testing inside the
    build, a GitHub Actions pipeline with Buildx, cache backends for CI, tagging strategies, multi-platform images with
    emulation and native builders, Bake, SBOM and provenance attestations.
15. [Registries and distribution](15-registries-and-distribution.md) — pushing to Docker Hub and GitHub Container
    Registry, authentication and credential helpers, running your own registry, deploying by digest, retention, pull
    rate limits and mirrors, and signing images.
16. [Container security](16-container-security.md) — the threat model, non-root users, capabilities, `no-new-privileges`,
    read-only filesystems, seccomp and AppArmor, user namespaces and rootless Docker, the Docker socket, vulnerability
    scanning, and the supply chain.
17. [Resources, logging and monitoring](17-resources-logging-and-monitoring.md) — cgroups and resource limits, Node.js
    heap sizing in containers, OOM kills, CPU limits, PIDs and ulimits, logging drivers and rotation, structured logs,
    `docker stats` and `docker events`, health status, and metrics.
18. [Running in production](18-running-in-production.md) — graceful shutdown end to end, init processes, restart
    policies, Compose on a single server, reverse proxies and TLS, deploying with Docker contexts, zero-downtime
    updates and rollbacks, backups, and a production checklist.
19. [Troubleshooting and debugging](19-troubleshooting-and-debugging.md) — a method, exit codes, containers that won't
    start or keep restarting, debugging distroless images, sharing namespaces with a debug container, network
    debugging, slow or failing builds, disk space, and a catalogue of common errors.
20. [How Docker works inside](20-how-docker-works-inside.md) — namespaces, cgroups, capabilities and seccomp,
    overlay filesystems, the OCI image and runtime specifications, the daemon, containerd, shims and runc, and what is
    on disk.
21. [Beyond a single host](21-beyond-a-single-host.md) — when one server is not enough, Swarm mode, Kubernetes concepts
    mapped from Compose, managed container platforms, and choosing between them.
22. [Capstone project: Snip](22-capstone-project.md) — the complete system with an API, a worker, migrations,
    PostgreSQL, Valkey and nginx; development and production Compose setups, a hardened multi-stage image, a CI
    workflow, backups, and an operations runbook.

## Repository layout

```text
docker-tutorial/
  README.md              # this file
  01-...md … 22-...md    # the chapters
  examples/              # the Snip app at key chapters (chNN-.../) and the finished capstone (snip/)
```

## Conventions

- Code blocks are tagged with their language: `bash` for shell commands, `dockerfile` for Dockerfiles, `yaml` for
  Compose files, `js` for Node.js, `text` for output, and so on.
- Shell commands start without a prompt character, so you can paste them directly. Commands that run *inside* a
  container are introduced as such in the text.
- Commands are written for a Linux or macOS shell (bash or zsh). On Windows, use WSL 2 (chapter 1 explains why); in
  PowerShell, replace `$(pwd)` with `${PWD}` and line continuations `\` with backticks.
- Output is shown as Docker prints it. Long IDs and digests are shortened with `…` where the exact value does not
  matter.
- `docker container run` and `docker run` are the same command: Docker has a long "management command" form
  (`docker container ls`, `docker image rm`) and short aliases (`docker ps`, `docker rmi`). The tutorial uses the short
  forms you will see in the wild for common commands and the long forms where they are clearer.
