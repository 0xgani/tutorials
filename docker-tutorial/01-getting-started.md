# Chapter 1 — Getting started

## What you'll learn

- What problem containers solve, and what a container actually is (a process, not a small virtual machine)
- How containers differ from virtual machines, and what that means for speed, density and isolation
- The pieces that make up "Docker": the CLI, the Engine, containerd, runc, BuildKit, Compose, Docker Desktop and
  registries, and the open standards (OCI) that tie them together
- How to install Docker on macOS, Windows and Linux, and what the `docker` group really grants
- How Docker versions work, and how to check what you have
- How to run your first containers, including Node.js without installing Node.js
- What happens, step by step, between typing `docker run` and seeing output

## The problem containers solve

Every developer has heard, or said, "but it works on my machine". A Node.js application does not run on its own. It
needs:

- a particular **Node.js version** (your code uses `fetch` and top-level `await`; the server still has Node 16),
- the right **npm packages**, some of which compile **native code** against the system's C library and OpenSSL,
- **operating system** pieces: CA certificates, time zone data, fonts for PDF generation, `git` for a build step,
- **configuration**: environment variables, files in the right places, the right user and permissions,
- **other services**: a database, a cache, a queue, each with its own version and configuration.

Traditionally you described all of that in a README ("install Node 24, then PostgreSQL 18, then…") and every
machine (yours, your colleague's Mac, CI, staging, the three production servers) drifted a little differently. Bugs
appeared that only happened in one place.

A **container image** packages your application *together with everything it needs to run*, down to the operating
system's user-space files, into one immutable artifact with a name and a version. A **container** is that image,
running. The same image runs on your laptop, in CI and in production, so "it works on my machine" becomes "it works
in this image", which is something you can actually ship.

That gives you, in practice:

| Benefit | What it means for a Node.js developer |
|---|---|
| Reproducible environments | The image pins Node 24.x, Alpine or Debian, and your exact `node_modules`. CI tests the artifact you deploy. |
| Isolation between apps | Two apps on one server can need Node 20 and Node 24, or conflicting system libraries, without interfering. |
| Fast, cheap start | A container starts in well under a second, so you can run a fresh PostgreSQL for each test run. |
| One deploy artifact | Any platform that runs containers (a VM, Kubernetes, Cloud Run, ECS, Fly.io…) can run your image. |
| Disposable dependencies | `docker run postgres:18` instead of installing a database on your laptop. Delete it when you're done. |

## What a container actually is

The single most important idea in this tutorial:

> **A container is an ordinary Linux process (or group of processes) that the kernel isolates and limits.**

There is no tiny computer inside. When you "run Node.js in a container", the `node` process runs on the host's
kernel, scheduled by the host's CPU scheduler, using the host's memory, exactly like a `node` process you start in a
terminal. What makes it a container is a set of Linux kernel features applied to that process:

- **Namespaces** change what the process can *see*. In its own PID namespace it sees itself as PID 1 and cannot see
  other processes. In its own mount namespace it sees a different root filesystem (the image's files). In its own
  network namespace it has its own network interfaces, IP address and ports. There are also UTS (hostname), IPC, user
  and cgroup namespaces.
- **Control groups (cgroups)** limit what the process can *use*: memory, CPU time, number of processes, I/O.
- **Capabilities, seccomp and LSMs (AppArmor/SELinux)** limit what the process is *allowed to do*, even as root:
  no loading kernel modules, no mounting filesystems, no rebooting the host.
- A **layered filesystem** (OverlayFS) gives each container its own writable view of the image's files without copying
  them.

Chapter 20 takes these apart one by one. For now, keep the picture:

```text
           Host (one Linux kernel)
  ┌─────────────────────────────────────────────────────────────┐
  │  sshd   dockerd   containerd                                │
  │                                                             │
  │   ┌── container "api" ───────┐   ┌── container "db" ──────┐ │
  │   │ sees: PID 1 = node       │   │ sees: PID 1 = postgres │ │
  │   │ root fs = node image     │   │ root fs = postgres img │ │
  │   │ own IP 172.18.0.3        │   │ own IP 172.18.0.2      │ │
  │   │ limit: 512 MiB, 1 CPU    │   │ limit: 1 GiB           │ │
  │   └──────────────────────────┘   └────────────────────────┘ │
  │                                                             │
  │   On the host, both are just processes: `ps` shows them.    │
  └─────────────────────────────────────────────────────────────┘
```

Two consequences follow immediately, and they explain a lot of Docker behaviour that otherwise looks strange:

1. **A container lives exactly as long as its main process.** When the process you started exits, the container
   stops. There is no "operating system" inside that keeps running.
2. **Containers share the host's kernel.** A Linux container needs a Linux kernel. That is why Docker Desktop on macOS
   and Windows runs a small Linux virtual machine behind the scenes. It is also why a container image built for
   `linux/arm64` cannot simply run on an `amd64` kernel (chapter 3).

### Images and containers

The relationship between an image and a container is the relationship between a program on disk and a running
process, or between a class and an instance:

| Image | Container |
|---|---|
| Read-only, immutable | Has a thin writable layer on top of the image |
| Has a name and tag: `node:24-alpine` | Has an ID and a name: `a3f9c2…`, `snip-api` |
| Built once (from a Dockerfile), shared via registries | Created from an image, started, stopped, deleted |
| Contains files plus metadata (default command, env vars, user, ports) | Adds runtime settings: ports, volumes, limits, networks |
| One image → many containers | Each container is independent |

You will meet a third noun in chapter 8: **volumes**, which hold data that must outlive any one container (your
database files).

## Containers vs. virtual machines

A virtual machine emulates a whole computer. A hypervisor gives each VM virtual CPUs, memory, disks and network
cards, and each VM boots its own complete operating system with its own kernel.

```text
     Virtual machines                         Containers

  ┌────────┐ ┌────────┐ ┌────────┐      ┌────────┐ ┌────────┐ ┌────────┐
  │ App A  │ │ App B  │ │ App C  │      │ App A  │ │ App B  │ │ App C  │
  │ libs   │ │ libs   │ │ libs   │      │ libs   │ │ libs   │ │ libs   │
  │ Kernel │ │ Kernel │ │ Kernel │      └────────┘ └────────┘ └────────┘
  └────────┘ └────────┘ └────────┘      ┌──────────────────────────────┐
  ┌──────────────────────────────┐      │ Container runtime (Docker)   │
  │ Hypervisor                   │      ├──────────────────────────────┤
  ├──────────────────────────────┤      │ Host kernel (shared)         │
  │ Hardware                     │      ├──────────────────────────────┤
  └──────────────────────────────┘      │ Hardware (or a VM!)          │
                                        └──────────────────────────────┘
```

| | Virtual machine | Container |
|---|---|---|
| Isolation boundary | Hardware virtualization; separate kernels | Kernel features; one shared kernel |
| Start time | Seconds to minutes (boots an OS) | Milliseconds (starts a process) |
| Size | Gigabytes (a whole OS) | Megabytes (only user-space files your app needs) |
| Overhead | Memory reserved per VM, emulated devices | Close to none; it's a normal process |
| Density | Tens per host | Hundreds or thousands per host |
| Can run a different OS kernel | Yes (Windows on Linux, any Linux kernel) | No; Linux containers need a Linux kernel |
| Security isolation | Strong: a kernel exploit inside one VM does not reach others | Weaker: a kernel exploit can escape a container |

They are not competitors. In the cloud, your containers almost always run *inside* VMs: the VM provides the strong
isolation between customers, the containers provide packaging and density within it. When you need a hard security
boundary between untrusted workloads, use VMs or a sandboxing runtime (gVisor, Kata Containers, Firecracker
microVMs); chapter 16 comes back to this.

### What a container is not

A few misconceptions cause most beginner trouble:

- **It is not a lightweight VM you log into and maintain.** You don't SSH into containers, install updates in them or
  "fix" them by hand. You change the image, build a new one and replace the container. Containers are cattle, not pets.
- **It is not a place to keep data.** A container's writable layer disappears when the container is removed, which
  happens on every deploy. Data goes into volumes or external services (chapter 8).
- **It is not automatically secure.** By default a process in a container runs as root (the same UID 0 as the host's
  root, only fenced in), shares the host kernel and can use as much memory as it likes. Docker's defaults are
  reasonable, but hardening is your job (chapter 16).
- **It is not magic portability.** An image runs on any machine with a compatible kernel and CPU architecture. An
  `amd64` image on an Apple Silicon Mac runs only through emulation (chapter 3).

## The pieces of Docker

"Docker" is a company, a product family and a set of tools. When people say "Docker" they usually mean this stack:

```text
  you ──▶ docker CLI ──REST API over a socket──▶ dockerd (Docker Engine)
                                                    │
                     ┌──────────────────────────────┼─────────────────────┐
                     ▼                              ▼                     ▼
               BuildKit (builds)          containerd (images,       networking,
                                          snapshots, containers)    volumes, logs
                                                    │
                                          containerd-shim-runc-v2  (one per container)
                                                    │
                                                  runc  ──▶ creates namespaces & cgroups,
                                                            starts your process, then exits
```

| Piece | What it is |
|---|---|
| **`docker` CLI** | The command-line client. It does almost nothing itself: it turns your command into HTTP calls to the Engine's API. |
| **Docker Engine (`dockerd`)** | The long-running daemon that manages images, containers, networks, volumes, logs and builds. On Linux it listens on the Unix socket `/var/run/docker.sock`. |
| **containerd** | A lower-level container runtime daemon (a CNCF project, also used by Kubernetes). It stores images and filesystem snapshots and supervises containers. Since Engine 29, Docker uses containerd's image store by default on fresh installs. |
| **runc** | The tool that actually creates a container: it sets up namespaces, cgroups and the root filesystem, then starts your process. It follows the OCI runtime specification. |
| **BuildKit** | The build engine behind `docker build`. Parallel, cache-efficient, supports secrets and multi-platform builds (chapters 6 and 14). `docker buildx` is the CLI plugin that exposes all of its features. |
| **Docker Compose** | A CLI plugin (`docker compose`) that runs multi-container applications described in a `compose.yaml` file (Part II). |
| **Docker Desktop** | An application for macOS, Windows and Linux that bundles all of the above with a Linux VM, a GUI and extra tools. |
| **Registries** | Servers that store and distribute images: Docker Hub (the default), GitHub Container Registry, Amazon ECR, Google Artifact Registry, your own. |

### Open standards: OCI

Docker invented the modern container image format in 2013, and in 2015 donated it to the **Open Container
Initiative (OCI)**, a Linux Foundation project. Three OCI specifications matter:

- the **image spec**: how an image is laid out (layers as tar archives, a config JSON, a manifest),
- the **runtime spec**: how to run a container from an unpacked image (what runc implements),
- the **distribution spec**: the HTTP API registries speak.

Because of these standards, an image you build with Docker runs on Podman, on Kubernetes (via containerd or CRI-O),
on AWS Fargate and everywhere else, and tools other than Docker can build images that Docker runs. Learn Docker well
and you have learned the container ecosystem.

> **Just a taste — you don't need to learn or fully understand this now.** Podman is a daemonless alternative with a
> nearly identical CLI; many commands in this tutorial work unchanged with `podman` in place of `docker`.
>
> ```bash
> podman run --rm docker.io/library/node:24-alpine node -e 'console.log("same image")'
> ```

## Installing Docker

### macOS and Windows: Docker Desktop

On macOS and Windows you install **Docker Desktop** from <https://www.docker.com/products/docker-desktop/>. It
runs a small Linux VM (using Apple's Virtualization framework on macOS, WSL 2 on Windows) and puts the `docker` CLI
on your machine, configured to talk to the Engine inside that VM. You use it exactly as if Docker ran natively.

Things worth knowing:

- **License.** Docker Desktop is free for personal use, education, non-commercial open source projects and small
  businesses (fewer than 250 employees *and* less than $10 million in annual revenue). Larger companies need a paid
  subscription. Docker Engine itself (on Linux) is open source and free for everyone.
- **Resources.** The VM has a fixed share of your CPU, memory and disk, set in *Settings → Resources*. If builds are
  slow or containers are killed for lack of memory, look there first.
- **Apple Silicon.** Macs with M-series chips are `arm64` machines. Most official images, including `node`, are
  published for both `arm64` and `amd64`, so you get a native image automatically. Chapter 3 covers the exceptions.
- **Windows.** Use the WSL 2 backend and keep your project files *inside* the WSL Linux filesystem (for example
  `~/code` in Ubuntu on WSL), not under `C:\`. Bind-mounting files across the Windows/Linux boundary is slow and breaks
  file watching (chapter 12). Run the commands in this tutorial from a WSL terminal.

Alternatives on macOS include OrbStack, Colima and Rancher Desktop. They also run a Linux VM with a Docker-compatible
Engine, and everything in this tutorial works with them.

### Linux: Docker Engine

On Linux, containers run natively, without a VM. Install Docker Engine from Docker's own package repository rather
than your distribution's package (Ubuntu's `docker.io` package, for instance, lags behind and lacks the Compose and
Buildx plugins). On Ubuntu:

```bash
# Add Docker's GPG key and APT repository
sudo apt update
sudo apt install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF

# Install the Engine, the CLI, containerd and the Buildx and Compose plugins
sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Debian, Fedora, RHEL and others have equivalent instructions at <https://docs.docker.com/engine/install/>. The
package starts the daemon and enables it at boot (`systemctl status docker` shows it).

#### Running `docker` without `sudo`, and what that costs

Out of the box, only root can talk to `/var/run/docker.sock`. The usual convenience step is:

```bash
sudo usermod -aG docker "$USER"
newgrp docker        # or log out and back in
docker run --rm hello-world
```

Understand what you just did: **membership in the `docker` group is equivalent to root on that machine.** Anyone who
can talk to the Docker socket can run `docker run -v /:/host --privileged …` and read or change any file on the host.
On your own laptop that's acceptable. On a shared server, it means "every member of the `docker` group is an
administrator". Chapter 16 covers the alternative, **rootless mode**, in which the daemon itself runs as an ordinary
user.

### Checking the installation

```bash
docker version
```

```text
Client: Docker Engine - Community
 Version:           29.8.2
 API version:       1.56
 Go version:        go1.26.8
 Git commit:        7fc2dff
 Built:             Wed Sep 30 19:32:28 2026
 OS/Arch:           linux/amd64
 Context:           default

Server: Docker Engine - Community
 Engine:
  Version:          29.8.2
  API version:      1.56 (minimum version 1.40)
  Go version:       go1.26.8
  Git commit:       8af9fe3
  Built:            Wed Sep 30 19:32:28 2026
  OS/Arch:          linux/amd64
  Experimental:     false
 containerd:
  Version:          v2.3.6
  GitCommit:        ee2735368117d2eb259779949d5e75cdafec9761
 runc:
  Version:          1.5.1
  GitCommit:        v1.5.1-0-g8f2685a4
 docker-init:
  Version:          0.19.0
  GitCommit:        de40ad0
```

The output has two halves, and that is the client/server split from the diagram above. If you see the `Client`
section followed by an error like `failed to connect to the docker API at unix:///var/run/docker.sock; check if the
path is correct and if the daemon is running`, the CLI is installed but the Engine is not running (start Docker Desktop, or
`sudo systemctl start docker`), or you lack permission to use the socket.

Two more commands you will use often:

```bash
docker compose version     # Docker Compose version v5.6.0
docker buildx version      # github.com/docker/buildx v0.37.1 …
docker info                # everything about the daemon: storage driver, cgroup version, registries, counts
```

## Versions

Docker Engine uses version numbers like `29.8.2`: the first number is a major release line, the second a feature
release, the third a patch release. Engine 29.0 shipped in November 2025 and the 29 line has received a feature release
roughly every month since. Notable changes in 29 that you will meet in this tutorial:

- the **containerd image store** is the default on fresh installs (chapter 3),
- `docker image ls` shows a new, more compact view and hides untagged images unless you pass `--all`,
- experimental **nftables** support for the firewall rules behind networking (chapter 9),
- **cgroup v1** is deprecated (supported until at least May 2029); modern distributions all use cgroup v2.

Docker only maintains the most recent release line, so stay current: on Linux, `sudo apt upgrade` brings you the
latest 29.x; Docker Desktop updates itself.

**Docker Compose** has its own versions. Compose v1 (`docker-compose`, with a hyphen, written in Python) is long dead.
Compose v2 (`docker compose`, with a space, a CLI plugin written in Go) replaced it, and in 2025 it was renumbered to
**Compose v5**, functionally the same tool, to avoid confusion with the old "version 2" and "version 3" *file formats*.
If a tutorial tells you to type `docker-compose` or put `version: "3.8"` at the top of a compose file, it is out of
date (chapter 10).

## Your first containers

### Hello, world

```bash
docker run hello-world
```

```text
Unable to find image 'hello-world:latest' locally
latest: Pulling from library/hello-world
4f55086f7dd0: Pull complete
Digest: sha256:11a3eb1597e864c51b3f56733cb2e8a90bf0c284bfc05f9444d81c558bc848aa
Status: Downloaded newer image for hello-world:latest

Hello from Docker!
This message shows that your installation appears to be working correctly.

To generate this message, Docker took the following steps:
 1. The Docker client contacted the Docker daemon.
 2. The Docker daemon pulled the "hello-world" image from the Docker Hub.
    (amd64)
 3. The Docker daemon created a new container from that image which runs the
    executable that produces the output you are currently reading.
 4. The Docker daemon streamed that output to the Docker client, which sent it
    to your terminal.
...
```

The image wasn't on your machine, so Docker **pulled** it from Docker Hub, then **created** a container from it,
**started** it (the image's default command is a tiny program called `/hello`), **streamed** its output to your
terminal, and the container **exited** when the program finished. Run the same command again and the pull step is
skipped: the image is now cached locally.

### Node.js without installing Node.js

```bash
docker run --rm node:24-alpine node -e 'console.log(`Hello from Node ${process.version} on ${process.platform}/${process.arch}, pid ${process.pid}`)'
```

```text
Hello from Node v24.22.0 on linux/x64, pid 1
```

Read the command from left to right:

- `docker run`: create and start a container,
- `--rm`: delete the container automatically when it exits (otherwise stopped containers pile up),
- `node:24-alpine`: the image, the official Node.js image, tag `24-alpine` (Node 24 on Alpine Linux),
- `node -e '…'`: the command to run inside the container, replacing the image's default command.

Look at the output: the platform is `linux` even if you're on a Mac or Windows (the container runs in Docker's Linux
VM), and the process ID is **1**. Inside its own PID namespace, `node` is the first and only process. That fact
matters a great deal for signal handling (chapter 4).

You just ran Node.js 24 without installing it. To try Node 26 or Node 22 instead, change the tag. Nothing is installed
on your machine, and nothing conflicts with the Node version you might have locally.

### An interactive container

Add `-it` to get an interactive terminal: `-i` keeps standard input open, `-t` allocates a pseudo-terminal so the
program behaves as it does in a real terminal (prompts, colours, line editing).

```bash
docker run -it --rm node:24-alpine
```

```text
Welcome to Node.js v24.22.0.
Type ".help" for more information.
>
```

The image's default command is the `node` REPL. Type `.exit` (or press Ctrl+D) to leave, and the container is gone.
To get a shell instead, ask for one:

```bash
docker run -it --rm node:24-alpine sh
```

Inside the container (the prompt changes to `/ #`), look around:

```bash
hostname                 # a random container ID, not your machine's name
whoami                   # root
cat /etc/os-release      # Alpine Linux, whatever your host runs
ps                       # only two processes: sh (PID 1) and ps
ls /                     # the image's filesystem, not yours
exit
```

```text
47c2fb242d35
root
NAME="Alpine Linux"
ID=alpine
VERSION_ID=3.24.2
...
PID   USER     TIME  COMMAND
    1 root      0:00 sh
    8 root      0:00 ps
```

Everything you saw is an effect of namespaces: a separate hostname (UTS namespace), a separate process tree (PID
namespace) and a separate root filesystem (mount namespace). Yet if you ran `ps aux` *on the host* (on Linux) while
the container was running, you would see that `sh` process in the host's process list, with a different PID. It is
just a process.

Two more experiments that build intuition:

```bash
# Changes inside a container stay in that container
docker run --rm node:24-alpine sh -c 'echo hi > /tmp/note && cat /tmp/note'   # prints: hi
docker run --rm node:24-alpine cat /tmp/note                                  # error: no such file

# Each container starts from the pristine image
docker run --rm node:24-alpine sh -c 'rm -rf /usr/local/bin/node; ls /usr/local/bin'
docker run --rm node:24-alpine node --version                                 # v24.22.0, unharmed
```

The second command in each pair runs a *new* container from the same untouched image. Deleting `node` inside one
container changed only that container's writable layer, which `--rm` threw away.

### A web server in a container

Containers have their own network namespace, so a server listening inside one is not reachable from your machine
until you **publish** a port. This one-liner starts a tiny HTTP server and maps port 3000 on your machine to port
3000 in the container:

```bash
docker run --rm -p 3000:3000 node:24-alpine node -e '
  require("node:http")
    .createServer((req, res) => res.end(`hello from ${require("node:os").hostname()}\n`))
    .listen(3000, () => console.log("listening on 3000"))'
```

```text
listening on 3000
```

In another terminal:

```bash
curl http://localhost:3000
```

```text
hello from 8c1d0e5b7a42
```

Stop the server with Ctrl+C in the first terminal. (If Ctrl+C does nothing for a while, you have met the PID 1 signal
problem; chapter 2 explains it and chapter 4 fixes it properly.) Chapter 2 covers `-p` and the other flags of
`docker run` in detail.

## What happens when you type `docker run`

It helps enormously to know the sequence, because every error message you'll ever see comes from one of these steps:

1. **The CLI parses your command** and turns it into REST API calls to the Engine, sent over
   `/var/run/docker.sock` (or a remote host; chapter 18).
2. **The Engine resolves the image reference.** `node:24-alpine` is short for
   `docker.io/library/node:24-alpine`. If no image with that name is in the local store, the Engine asks the
   registry for the image's **manifest**, picks the variant for your platform (`linux/amd64` or `linux/arm64`) and
   **pulls** the layers it doesn't have yet.
3. **The Engine creates the container**: it allocates an ID and a name, prepares a writable **snapshot** on top of
   the image's layers, and records the configuration (command, environment, ports, mounts, limits).
4. **It sets up networking**: a network namespace, a virtual Ethernet pair connecting it to the `bridge` network, an
   IP address, and firewall rules for any published ports.
5. **containerd starts a shim**, and the shim runs **runc**, which creates the namespaces and cgroups, mounts the root
   filesystem, switches to the configured user, applies the security profile, and finally `exec`s your command. runc
   then exits; the shim stays behind as the process's parent, holding its standard streams and exit status.
6. **Your process runs.** Its standard output and standard error go to the Engine's **logging driver** (by default a
   JSON file on the host) and, because you didn't pass `-d`, are also streamed back to your terminal.
7. **The process exits.** The container moves to the `exited` state with the process's exit code. With `--rm`, the
   Engine deletes it.

```text
 docker CLI ─▶ dockerd ─▶ (pull from registry if needed) ─▶ containerd ─▶ shim ─▶ runc ─▶ your process
     ▲                                                                             │
     └──────────────────────── output streamed back ◀── logging driver ◀───────────┘
```

You don't need to memorize this, but when you meet `pull access denied`, `port is already allocated`,
`exec format error` or `OCI runtime create failed`, you'll know which step failed. Chapter 19 is a catalogue of such
errors.

## Key takeaways

- A container is a normal Linux process isolated by namespaces, limited by cgroups and restricted by capabilities and
  seccomp. It is not a VM, and it lives exactly as long as its main process.
- An image is an immutable package of files plus metadata; a container is a running (or stopped) instance of an image
  with a thin writable layer.
- Containers share the host kernel. That makes them fast and dense, but a weaker security boundary than VMs, and it is
  why Docker on macOS and Windows runs a Linux VM.
- "Docker" is a stack: the CLI talks to `dockerd`, which uses containerd and runc to run containers and BuildKit to
  build images. OCI standards make images portable across tools.
- Install Docker Desktop on macOS and Windows (inside WSL 2 on Windows), and Docker Engine from Docker's own repository
  on Linux. Membership of the `docker` group is root-equivalent.
- `docker run --rm image command` runs a throwaway container; `-it` makes it interactive; `-p host:container`
  publishes a port.
- This tutorial targets Engine 29, Compose v5 (`docker compose`, never `docker-compose`) and Node.js 24.

## Exercises

1. **Check your setup.** Run `docker version`, `docker compose version` and `docker info`. From the output, find:
   the Engine version, the storage driver, the number of images you have, and the operating system and CPU
   architecture the *server* runs on.

<details>
<summary>Solution</summary>

`docker version` shows the Engine version in the `Server` section (`Version: 29.x.y`) and the server's `OS/Arch`
(for example `linux/amd64` or `linux/arm64`). Even on a Mac or Windows machine, the server's OS is `linux`, because
the Engine runs in a Linux VM.

`docker info` shows `Storage Driver` (with the containerd image store, it reports `overlayfs` with
`driver-type: io.containerd.snapshotter.v1`; with the older graph drivers, `overlay2`), the `Images:` count, and
`Operating System`, `OSType` and `Architecture` lines for the daemon's host.

`docker compose version` prints something like `Docker Compose version v5.6.0`. If that command fails but
`docker-compose version` works, you have the obsolete standalone Compose v1 and should install the plugin.

</details>

2. **Many Node versions.** Without installing anything, print the Node.js version and the V8 version
   (`process.versions.v8`) for Node 22, 24 and 26.

<details>
<summary>Solution</summary>

```bash
for v in 22 24 26; do
  docker run --rm node:$v-alpine node -p '`${process.version} v8 ${process.versions.v8}`'
done
```

`node -p` evaluates the expression and prints the result. Each iteration pulls a different image the first time,
then reuses it. You now have three Node versions side by side, isolated from each other and from your machine.
Remove them later with `docker image rm node:22-alpine node:26-alpine` (chapter 3).

</details>

3. **Prove it's a process.** On a Linux host (or inside the Docker Desktop VM, see the hint), start
   `docker run -d --rm --name sleeper node:24-alpine sleep 300` (`-d` runs it in the background), then find the
   `sleep` process in the host's process list. What PID does it have on the host, and what PID inside the container?
   Remove it afterwards with `docker stop sleeper`.

<details>
<summary>Solution</summary>

```bash
docker run -d --rm --name sleeper node:24-alpine sleep 300
ps -ef | grep 'sleep 300'           # on a Linux host
docker top sleeper                   # works everywhere: lists the container's processes with host PIDs
docker exec sleeper ps               # PID as the container sees it
docker stop sleeper
```

`docker top` shows the process with its **host** PID (some large number, for example `48213`), while `ps` inside the
container shows it as PID 1. Same process, two PID namespaces. On macOS and Windows, the host is Docker Desktop's VM,
so `ps` on your Mac won't show it, but `docker top` will.

`docker stop` may take about ten seconds here: `sleep` running as PID 1 ignores `SIGTERM`, so Docker waits and then
sends `SIGKILL`. Chapter 2 explains why.

</details>

4. **Isolation experiments.** Predict, then check: (a) if you create a file in one container, can a second container
   from the same image see it? (b) if you run `apk add curl` in a container, is `curl` available in the next
   container you start from `node:24-alpine`? (c) what is the hostname of each container?

<details>
<summary>Solution</summary>

(a) No. Each container gets its own writable layer on top of the shared read-only image.

(b) No, for the same reason. The package was installed into that one container's writable layer. To have `curl` in
every container, you build a new image that includes it (chapter 4). Installing things into running containers by
hand is an anti-pattern: the change is invisible, unrepeatable and lost on the next deploy.

(c) A random 12-character hexadecimal string: the start of the container ID. Each container has its own UTS
namespace. You can set it with `--hostname`.

```bash
docker run --rm node:24-alpine hostname
docker run --rm --hostname snip-dev node:24-alpine hostname    # snip-dev
```

</details>

5. **Read the sequence.** Explain at which step of "what happens when you type `docker run`" each of these errors
   occurs: (a) `Unable to find image 'nodee:24' locally … pull access denied for nodee, repository does not exist or
   may require 'docker login'`; (b) `Bind for 0.0.0.0:3000 failed: port is already allocated` (from
   `docker run -p 3000:3000 …`); (c) `exec: "nod": executable file not found in $PATH` (from
   `docker run --rm alpine:3 nod`).

<details>
<summary>Solution</summary>

(a) Step 2, resolving and pulling the image: there is no repository `library/nodee` on Docker Hub (or it is private
and you aren't logged in). Docker reports both possibilities with the same message.

(b) Step 4, networking: another process (or container) already listens on port 3000 on the host, so the Engine can't
publish the container's port there. Pick another host port, for example `-p 3001:3000`.

(c) Step 5, when runc tries to `exec` your command: the image has no executable called `nod` on its `PATH`. The
full message shows the chain from the diagram: `failed to create shim task: OCI runtime create failed: runc create
failed: unable to start container process: … exec: "nod": executable file not found in $PATH`. The container was
created but couldn't start, and `docker run` exits with status 127 (chapter 2 lists the exit codes).

Try the same typo with `node:24-alpine` instead of `alpine:3` and you get a *different* error: `Error: Cannot find
module '/nod'`. The Node image's entrypoint script runs `node <your arguments>` whenever the first argument isn't an
executable it can find, so `nod` became `node nod`. Chapter 5 explains entrypoints.

</details>

---
← Previous: [README](README.md) | Next: [Running containers](02-running-containers.md) →
