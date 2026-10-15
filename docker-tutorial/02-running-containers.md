# Chapter 2 — Running containers

## What you'll learn

- The life cycle of a container: created, running, paused, restarting, exited, removed
- How `docker run` is structured, and the flags you will use every day
- Foreground, detached and interactive containers, and how to attach and detach
- How to read logs, and why containers log to standard output
- How to run extra commands in a running container with `docker exec`
- How port publishing works, and why `-p 3000:3000` may expose your app to the whole internet
- How to pass environment variables
- How stopping works: signals, the grace period, PID 1, and what each exit code means
- Restart policies, and when to use each
- How to inspect containers and format Docker's output with Go templates
- How to copy files in and out, and how to find and clean up everything Docker leaves behind

## The container life cycle

`docker run` is really two operations: `docker create` followed by `docker start`. A container moves through these
states:

```text
                docker create              docker start
   (image) ───────────────────▶ created ───────────────▶ running ◀────────┐
                                                          │  │  ▲          │ restart policy
                                          docker pause    │  │  │ unpause  │ (process exited)
                                                          ▼  │  │          │
                                                       paused│  │     restarting
                                                             │  │          ▲
                       process exits, docker stop/kill ──────┘  │          │
                                                             ▼  │          │
                                                          exited ──────────┘
                                                             │   docker start (again)
                                            docker rm        ▼
                                                          (removed)
```

- **created**: the container exists (ID, name, writable layer, configuration) but its process has never started.
- **running**: its main process is running.
- **paused**: all its processes are frozen (cgroup freezer). They use no CPU, but keep their memory.
- **restarting**: the process exited and a restart policy is about to start it again.
- **exited**: the process ended. The container's writable layer, configuration and logs remain until you remove it.
  You can start it again; it runs the same command with the same writable layer.
- **dead**: a rare state for a container the daemon failed to remove.

The most important rule from chapter 1 again: **the container is running exactly as long as its main process is.**
A container that "exits immediately" is a container whose main process exited immediately, usually because it had
nothing to do, crashed, or was a background daemon that forked and left PID 1 with nothing to do.

You can see these states for yourself with `docker create` and `docker start`:

```bash
docker create --name later node:24-alpine echo "ran later"
docker ps -a --format '{{.Names}} {{.Status}}'
docker start -a later           # -a attaches to its output
docker rm later
```

```text
e07a5d287746b1092c48d0961cb81410a5941afac1188bff8a937015bfe23460
later Created
ran later
later
```

## The shape of `docker run`

```text
docker run [OPTIONS] IMAGE [COMMAND] [ARG...]
           └── for Docker ┘     └─ for the container ┘
```

**Everything before the image name configures the container; everything after it is the command to run inside.**
That's why this does not do what you might expect:

```bash
docker run node:24-alpine --rm node -v     # wrong: --rm is passed to the container's entrypoint
docker run --rm node:24-alpine node -v     # right
```

The flags you will use daily:

| Flag | Meaning |
|---|---|
| `--rm` | Remove the container when it exits |
| `-d`, `--detach` | Run in the background and print the container ID |
| `-i`, `--interactive` | Keep standard input open |
| `-t`, `--tty` | Allocate a pseudo-terminal (use with `-i` for shells and REPLs) |
| `--name NAME` | Give the container a name you can use instead of its ID |
| `-p HOST:CONTAINER` | Publish a container port on the host |
| `-e KEY=VALUE`, `--env-file FILE` | Set environment variables |
| `-v`, `--mount` | Attach volumes and bind mounts (chapter 8) |
| `--network NAME` | Connect to a network (chapter 9) |
| `-w DIR`, `--workdir` | Working directory for the command |
| `-u USER[:GROUP]`, `--user` | Run as this user (name or UID) |
| `--restart POLICY` | Restart policy (below) |
| `--init` | Run a tiny init process as PID 1 (below, and chapter 4) |
| `--entrypoint CMD` | Override the image's entrypoint (chapter 5) |
| `--pull always\|missing\|never` | When to pull the image (default `missing`) |
| `--platform linux/arm64` | Which platform variant of the image to use (chapter 3) |

`docker run --help` lists about a hundred more. You'll meet the important ones as the tutorial goes.

### Names

Every container has a unique name. If you don't give one, Docker invents an "adjective_scientist" pair like
`nifty_cohen`. Name containers you'll refer to again: `docker logs web` is easier than `docker logs 7a4ac75a952f`.
Names must be unique among *all* containers, including stopped ones, which is the most common reason for this error:

```text
docker: Error response from daemon: Conflict. The container name "/web" is already in use by container "7a4ac75a…".
You have to remove (or rename) that container to be able to reuse that name.
```

Remove the old one (`docker rm web`), or use `--rm` so it never lingers. Anywhere Docker wants a container, you can
also use a unique prefix of its ID: `docker logs 7a4`.

## Foreground, detached and interactive

By default `docker run` runs in the **foreground**: your terminal is attached to the container's output until it
exits. For a server, you usually want it in the **background**:

```bash
docker run -d --name ticker node:24-alpine node -e 'let n=0; setInterval(() => console.log(`tick ${++n}`), 1000)'
```

```text
41423c9a5728d73431781191b36c6f107eb0773b39447620b4e98fcde9acb545
```

`-d` prints the full container ID and returns. `docker ps` lists running containers:

```bash
docker ps
```

```text
CONTAINER ID   IMAGE            COMMAND                  CREATED         STATUS         PORTS     NAMES
41423c9a5728   node:24-alpine   "docker-entrypoint.s…"   3 seconds ago   Up 3 seconds             ticker
```

`docker ps -a` includes stopped containers, `docker ps -q` prints only IDs (handy in scripts), and `--filter`
narrows the list: `docker ps -a --filter status=exited`, `--filter name=web`, `--filter ancestor=node:24-alpine`.

The `COMMAND` column says `docker-entrypoint.s…` rather than `node -e …`: the Node image has an *entrypoint* script
that runs first and then `exec`s your command. Chapter 5 explains entrypoints.

**Interactive** containers (`-it`) attach your keyboard and terminal. You can **detach** from a running interactive
container without stopping it by pressing **Ctrl+P then Ctrl+Q**, and re-attach with `docker attach NAME`. Attaching
connects you to the main process's standard streams; pressing Ctrl+C while attached sends `SIGINT` to that process,
which usually stops the container. To get a *second* shell instead, use `docker exec` (below).

## Logs

Docker captures everything the main process writes to **standard output and standard error**:

```bash
docker logs ticker
```

```text
tick 1
tick 2
tick 3
```

Useful variations:

```bash
docker logs -f ticker               # follow, like tail -f (Ctrl+C stops following, not the container)
docker logs --tail 20 ticker        # only the last 20 lines
docker logs --since 10m ticker      # last ten minutes (also: --since 2026-10-15T08:00:00, --until)
docker logs -t --tail 2 ticker      # with timestamps added by Docker
```

```text
2026-10-15T08:45:57.256361587Z tick 2
2026-10-15T08:45:58.258414101Z tick 3
```

The consequences for how you write applications:

- **Log to stdout/stderr, not to files.** A Node app that writes `/var/log/app.log` inside the container hides its
  logs from `docker logs`, fills the writable layer, and loses everything when the container is replaced. `console.log`
  and a logger such as pino writing to stdout are exactly right. Chapter 17 covers log drivers, rotation and
  structured logs.
- **Logs belong to the container, not the image.** They survive a restart (`docker start` appends to the same log)
  and are deleted with the container.
- **By default logs are never rotated.** A chatty container can fill the host's disk. Chapter 17 shows how to cap
  them; set that up before production.

## Running commands in a running container: `docker exec`

`docker exec` starts an *additional* process inside an existing, running container, in all its namespaces:

```bash
docker exec ticker ps
```

```text
PID   USER     TIME  COMMAND
    1 root      0:00 {MainThread} node -e let n=0; setInterval(() => console.log(`tick ${++n}`), 1000)
   14 root      0:00 ps
```

```bash
docker exec -it ticker sh           # an interactive shell inside the container
docker exec -u node ticker whoami   # as another user: node
docker exec -w /tmp ticker pwd      # in another directory: /tmp
docker exec -e X=1 ticker printenv X
```

`exec` is your main debugging tool: look at files, check environment variables, test whether the container can reach
the database. It is *not* a way to change a container permanently. Anything you install or edit lives only in that
container's writable layer, invisible to your colleagues and gone on the next deploy. If you find yourself fixing a
container by hand, fix the image instead.

Two limits to know: `exec` only works on a *running* container, and it needs the tool you ask for to exist in the
image. Minimal images often have no shell at all; chapter 19 shows how to debug those.

## Publishing ports

A container has its own network namespace with its own IP address on a private Docker network. Your machine can't
reach a server inside it until you **publish** a port:

```bash
docker run -d --name web -p 8080:3000 -e GREETING=hola node:24-alpine node -e '
  require("node:http").createServer((req, res) => res.end(process.env.GREETING + "\n")).listen(3000)'
curl http://localhost:8080
```

```text
hola
```

`-p 8080:3000` means "forward connections to port 8080 on the host to port 3000 in the container". The order is always
**host first, container second**, the same as in `docker port`'s output:

```bash
docker port web
```

```text
3000/tcp -> 0.0.0.0:8080
```

(On hosts with IPv6 enabled you also see `3000/tcp -> [::]:8080`.)

| Form | Meaning |
|---|---|
| `-p 8080:3000` | Host port 8080 on **all** interfaces → container port 3000 |
| `-p 127.0.0.1:8080:3000` | Only on the host's loopback interface: reachable from this machine only |
| `-p 3000` | Container port 3000 → a random free host port (see `docker port`) |
| `-p 8080:3000/udp` | UDP instead of TCP |
| `-p 8000-8010:8000-8010` | A range |
| `-P` | Publish every port the image `EXPOSE`s, each to a random host port |

### `0.0.0.0` means the whole network

The default host address is `0.0.0.0`, *all interfaces*. On a laptop on café Wi-Fi, `-p 5432:5432` for your
development PostgreSQL makes it reachable by everyone on that network. On a cloud server it is reachable by the
internet. Worse, on Linux, Docker programs its port forwarding directly into the kernel's firewall (iptables or
nftables) in a way that **bypasses host firewall front-ends such as `ufw` and `firewalld`'s simple rules**: "I blocked
5432 in ufw" does not protect a published container port.

The rule: **publish development services on `127.0.0.1`**, and publish only the ports that must be public (usually
just your reverse proxy's 80 and 443) in production.

```bash
docker run -d --name devdb -p 127.0.0.1:5432:5432 -e POSTGRES_PASSWORD=dev postgres:18-alpine
```

You don't need to publish ports for containers to talk to *each other*; they do that over Docker networks
(chapter 9). Publish only what a human or an outside system must reach.

### The server must listen on all interfaces *inside* the container

If your app listens on `127.0.0.1` inside the container, it only accepts connections from inside that container, and
the forwarded traffic, which arrives on the container's network interface, is refused:

```js
server.listen(3000, '127.0.0.1')   // unreachable from outside the container
server.listen(3000)                // Node's default: all interfaces, works
server.listen(3000, '0.0.0.0')     // explicit IPv4 all interfaces, works
```

Many dev servers (Vite, some framework CLIs) default to `localhost`; pass `--host 0.0.0.0` when running them in a
container. Chapter 9 explains why with a diagram.

## Environment variables

Environment variables are the standard way to configure a container:

```bash
docker run --rm -e GREETING=hello -e PORT=3000 node:24-alpine node -p 'process.env.GREETING'
GREETING=from-my-shell docker run --rm -e GREETING node:24-alpine node -p 'process.env.GREETING'
docker run --rm --env-file ./app.env node:24-alpine node -p 'process.env.GREETING'
```

- `-e KEY=VALUE` sets a value.
- `-e KEY` (no `=`) copies `KEY` from your shell's environment, so the value never appears in your shell history.
- `--env-file FILE` reads `KEY=VALUE` lines. The format is simple: no `export`, no quotes processing (quotes become
  part of the value), `#` starts a comment line.

Variables you pass are added to those already set by the image (`PATH`, `NODE_VERSION`, …), overriding any with the
same name. Chapter 13 covers configuration properly, including why passwords in environment variables are visible to
anyone who can run `docker inspect`.

## Stopping containers: signals, grace periods and PID 1

How a container stops matters: it decides whether in-flight HTTP requests complete, database transactions commit and
queue jobs are released, or whether everything is cut off mid-flight.

`docker stop` does this:

1. Send the container's **stop signal** (default `SIGTERM`) to its main process (PID 1 in the container).
2. Wait for the **grace period** (default 10 seconds) for it to exit.
3. If it is still running, send `SIGKILL`, which cannot be caught: the process dies instantly.

`docker kill` skips the polite part and sends `SIGKILL` immediately (or another signal with `--signal`,
for example `docker kill --signal SIGHUP web` to ask a process to reload its configuration).

```bash
docker stop ticker                        # SIGTERM, wait up to 10 s, then SIGKILL
docker stop -t 30 ticker                  # wait up to 30 s
docker run --stop-timeout 30 …            # set a different default for this container
docker run --stop-signal SIGINT …         # send SIGINT instead of SIGTERM (or STOPSIGNAL in the image)
```

### Why `docker stop` takes ten seconds

Try it on the `ticker` container:

```bash
time docker stop ticker
docker ps -a --format 'table {{.Names}}\t{{.Status}}'
```

```text
ticker

real    0m10.219s

NAMES     STATUS
ticker    Exited (137) Less than a second ago
```

Ten seconds, then exit code 137, which means "killed by `SIGKILL`". The `SIGTERM` was ignored. Why?

On Linux, **PID 1 is special**. For every other process, a signal with no handler installed gets its *default
action*: `SIGTERM` and `SIGINT` terminate the process. For PID 1 (the "init" process of a PID namespace), the kernel
**ignores** any signal for which the process has not installed a handler. Node.js does not install handlers for
`SIGTERM` or `SIGINT` unless your code does. So `node` as PID 1 shrugs off `SIGTERM`, Docker waits ten seconds and
then kills it.

There are two fixes, and you should usually use both:

1. **Handle the signal in your application** and shut down gracefully:

   ```bash
   docker run -d --name t3 node:24-alpine node -e '
     process.on("SIGTERM", () => { console.log("SIGTERM received, shutting down"); process.exit(0) })
     setInterval(() => {}, 1000)'
   time docker stop t3        # real 0m0.217s
   docker logs t3             # SIGTERM received, shutting down
   docker inspect -f '{{.State.ExitCode}}' t3   # 0
   ```

   A real server closes its listener, finishes in-flight requests and closes database pools before exiting.
   Chapter 4 writes that handler for the Snip app; chapter 18 covers the details.

2. **Don't run your app as PID 1**: `docker run --init` (or `init: true` in Compose) starts `docker-init` (a copy of
   [tini](https://github.com/krallin/tini)) as PID 1. It forwards signals to your process, which then gets normal,
   non-PID-1 signal semantics, and it reaps zombie processes (orphaned child processes that would otherwise
   accumulate). With `--init` and no handler, `node` gets the default action and dies on `SIGTERM`, exit code 143:

   ```bash
   docker run -d --name t2 --init node:24-alpine node -e 'setInterval(() => {}, 1000)'
   time docker stop t2                           # real 0m0.181s
   docker inspect -f '{{.State.ExitCode}}' t2    # 143
   ```

### Exit codes

A container's exit code is its main process's exit code. The ones you will see:

| Code | Meaning | Typical cause |
|---|---|---|
| `0` | Success | The process finished normally (or shut down cleanly on `SIGTERM`) |
| `1` | General error | An uncaught exception in Node.js, `process.exit(1)` |
| `125` | `docker run` itself failed | Bad flag, name conflict, port already allocated: the container never started |
| `126` | Command can't be invoked | The command exists but isn't executable (permissions, wrong format) |
| `127` | Command not found | Typo in the command, or a binary missing from the image |
| `128 + n` | Killed by signal `n` | |
| `130` | `SIGINT` (2) | Ctrl+C |
| `137` | `SIGKILL` (9) | `docker kill`, `docker stop` after the grace period, or the **out-of-memory killer** |
| `143` | `SIGTERM` (15) | Stopped by `docker stop` without a handler, but not as PID 1 |

Exit code 137 is ambiguous: was the container stopped or did it run out of memory? `docker inspect` tells you:

```bash
docker inspect -f '{{.State.ExitCode}} {{.State.Status}} oom={{.State.OOMKilled}}' ticker
```

```text
137 exited oom=false
```

Chapter 17 covers memory limits and the OOM killer.

## Restart policies

A restart policy tells the daemon to restart a container when its process exits:

| Policy | Restarts when… | Use for |
|---|---|---|
| `no` (default) | Never | One-off tasks, development |
| `on-failure[:N]` | It exits with a non-zero code, at most `N` times if given | Jobs that should retry on errors but not when they succeed |
| `always` | It exits for any reason; also started when the daemon starts | Long-running services, unless you sometimes stop them by hand |
| `unless-stopped` | Like `always`, except a container you stopped with `docker stop` stays stopped after a daemon restart | Long-running services on a single host |

```bash
docker run -d --name crashy --restart on-failure:3 node:24-alpine node -e '
  console.log("starting"); setTimeout(() => { throw new Error("boom") }, 200)'
sleep 8
docker inspect -f 'restarts={{.RestartCount}} exit={{.State.ExitCode}}' crashy
docker ps -a --filter name=crashy --format '{{.Status}}'
```

```text
restarts=3 exit=1
Exited (1) 5 seconds ago
```

Details that matter:

- **Backoff.** Docker waits before each restart, doubling the delay from 100 ms each time, so a crash-looping
  container doesn't hammer the machine. The delay resets once the container stays up for at least 10 seconds. While
  waiting, `docker ps` shows `Restarting (1) …`.
- **`docker stop` wins.** A container you stop by hand is not restarted, whatever the policy. With `always`, it comes
  back on the next daemon (or machine) restart; with `unless-stopped`, it doesn't.
- **Restart policies are for a single host.** They are what Compose uses (`restart: unless-stopped`) on a server.
  Orchestrators such as Kubernetes have their own, richer mechanisms (chapter 21).
- **Restarting is not a fix.** A container that crashes every few seconds is restarted every few seconds, forever.
  Watch `RestartCount` and the logs.

## Inspecting containers

`docker inspect` returns everything Docker knows about a container as JSON: its configuration, state, mounts,
network settings, the path of its log file:

```bash
docker inspect web | head -20
```

```text
[
    {
        "Id": "7a4ac75a952fa79e0953490c25488823e4b4eaf85d1c76a9dc4f5ac54f12c1b6",
        "Created": "2026-10-15T08:46:50.872787777Z",
        "Path": "docker-entrypoint.sh",
        "Args": [
            "node",
            "-e",
            "require(\"node:http\").createServer(…).listen(3000)"
        ],
        "State": {
            "Status": "running",
            "Running": true,
            "Paused": false,
            "Restarting": false,
            "OOMKilled": false,
            "Dead": false,
            "Pid": 6426,
            "ExitCode": 0,
            "Error": "",
            "StartedAt": "2026-10-15T08:46:50.91121814Z",
...
```

Notice `"Pid": 6426`: the process's PID **on the host**, while inside the container it is PID 1.

Rather than scrolling through hundreds of lines, extract what you need with `--format` (`-f`), which takes a
**Go template**. Fields are addressed with dots, starting from the top-level object:

```bash
docker inspect -f '{{.State.Status}} since {{.State.StartedAt}}' web
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' web
docker inspect -f '{{json .Config.Cmd}}' web
docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' web
```

```text
running since 2026-10-15T08:46:50.91121814Z
172.17.0.2
["node","-e","require(\"node:http\").createServer(…).listen(3000)"]
GREETING=hola
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
NODE_VERSION=24.22.0
YARN_VERSION=1.22.22
```

The template language in one table:

| Template | Does |
|---|---|
| `{{.State.Status}}` | A field |
| `{{json .Config}}` | A field as JSON (pipe the output to `jq` for complex queries) |
| `{{range .Mounts}}{{.Source}} {{end}}` | Loop over a list or map |
| `{{index .Config.Labels "com.example.team"}}` | A map key that isn't a valid identifier |
| `{{if .State.Running}}up{{else}}down{{end}}` | Conditional |
| `{{println .}}`, `{{upper .}}`, `{{join .Config.Cmd " "}}` | Helper functions |

The same templates work for `docker ps --format`, `docker images --format` and others. A `table` prefix gives aligned
columns with headers:

```bash
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
```

```text
NAMES     STATUS          PORTS
web       Up 2 minutes    0.0.0.0:8080->3000/tcp
```

When a template gets hairy, `docker inspect web | jq '.[0].State'` is often simpler.

Other ways to look at a running container:

```bash
docker top web        # its processes, with host PIDs
docker stats          # live CPU, memory, network and I/O per container (Ctrl+C to quit; chapter 17)
docker diff web       # files added (A), changed (C) or deleted (D) in its writable layer
```

## Copying files in and out

`docker cp` copies between the host and a container, running or stopped:

```bash
echo 'console.log("hi from a copied file")' > hello.js
docker cp hello.js web:/tmp/hello.js
docker exec web node /tmp/hello.js         # hi from a copied file

docker cp web:/etc/hostname .               # container → host
docker cp -L web:/etc/os-release .          # -L follows symlinks (os-release is one on Alpine)
```

Use it to grab a heap snapshot, a core dump or a generated report from a container, or to drop in a file while
debugging. Like `exec`, it is not a deployment mechanism.

## Pausing

`docker pause web` freezes every process in the container with the cgroup freezer; `docker unpause web` thaws them.
While paused, the container keeps its memory and connections but answers nothing: a `curl` to it simply hangs.
Pausing is occasionally useful in tests ("what happens to my app when the database stops answering but doesn't
close the connection?").

## Cleaning up

Docker never deletes anything you didn't ask it to delete. Stopped containers, old images, unused volumes and the
build cache accumulate until your disk is full. Check what's using space:

```bash
docker system df
```

```text
TYPE            TOTAL     ACTIVE    SIZE      RECLAIMABLE
Images          13        0         3.296GB   3.051GB (92%)
Containers      0         0         0B        0B
Local Volumes   0         0         0B        0B
Build Cache     31        0         582.3MB   7.275MB
```

And remove things:

```bash
docker rm web                     # remove a stopped container
docker rm -f web                  # stop (SIGKILL) and remove a running one
docker rm $(docker ps -aq --filter status=exited)   # all exited containers
docker container prune            # all stopped containers (asks for confirmation; -f skips it)
docker image prune                # dangling images (untagged leftovers of rebuilds)
docker image prune -a             # every image not used by any container
docker builder prune              # the build cache
docker volume prune               # anonymous volumes not used by any container (chapter 8)
docker system prune               # stopped containers, unused networks, dangling images, build cache
```

Be careful with volumes. `docker system prune --volumes` and `docker volume prune -a` delete *data*, for example the
PostgreSQL database you've been developing against. Images and containers can always be recreated; volume contents
often cannot.

Habits that keep a machine tidy: use `--rm` for anything throwaway, name long-lived containers, and run
`docker system df` now and then.

## Key takeaways

- `docker run [OPTIONS] IMAGE [COMMAND]`: options for Docker go before the image, the command for the container
  after it.
- A container lives as long as its main process. `-d` runs it in the background, `-it` interactively, `--rm` removes
  it on exit.
- `docker logs` shows what the process wrote to stdout and stderr, so applications should log there.
- `docker exec` runs extra processes in a running container for debugging; never use it to change containers
  permanently.
- `-p HOST:CONTAINER` publishes a port on **all interfaces** by default, bypassing host firewalls such as ufw. Bind
  development services to `127.0.0.1`, and make servers inside containers listen on all interfaces.
- `docker stop` sends `SIGTERM`, waits 10 seconds, then `SIGKILL`s. PID 1 ignores signals it has no handler for, so
  handle `SIGTERM` in your app and/or use `--init`. Exit code 137 means `SIGKILL` (stopped or OOM-killed), 143 means
  `SIGTERM`.
- Restart policies (`on-failure`, `always`, `unless-stopped`) restart exited containers with exponential backoff.
- `docker inspect -f '{{…}}'` and `--format 'table …'` extract exactly the information you need.
- Docker never cleans up by itself. Use `docker system df` and the `prune` commands, and treat volumes with care.

## Exercises

1. **Run, watch, stop.** Start the official `nginx:alpine` image in the background, named `site`, with its port 80
   published on `127.0.0.1:8081`. Fetch the page with `curl`, show the last 2 lines of its access log, then stop it and
   check its exit code. Finally remove it.

<details>
<summary>Solution</summary>

```bash
docker run -d --name site -p 127.0.0.1:8081:80 nginx:alpine
curl -s http://127.0.0.1:8081 | head -4
docker logs --tail 2 site
docker stop site
docker inspect -f '{{.State.ExitCode}}' site
docker rm site
```

nginx's official image links its access log to stdout, so each `curl` shows up in `docker logs` as a line like
`172.17.0.1 - - [15/Oct/2026:09:01:12 +0000] "GET / HTTP/1.1" 200 …`. The client address is the Docker bridge's
gateway, not your machine's real IP: the request was forwarded. The nginx image sets its stop signal to `SIGQUIT`
(nginx's "graceful shutdown" signal, see `docker image inspect -f '{{.Config.StopSignal}}' nginx:alpine`), and nginx
installs a handler for it, so `docker stop` is fast and the exit code is `0`.

</details>

2. **The name conflict.** Run `docker run --name once node:24-alpine echo hi` twice. Explain the error, then fix it in
   two different ways.

<details>
<summary>Solution</summary>

The second run fails with `Conflict. The container name "/once" is already in use by container …`. The first container
exited but was not removed, and names must be unique among all containers, stopped ones included.

Fixes: remove the old container first (`docker rm once`), or add `--rm` so the container removes itself on exit
(`docker run --rm --name once node:24-alpine echo hi`). A third option, `docker start -a once`, re-runs the *existing*
container with its original command.

</details>

3. **Graceful or not.** Write a one-line Node.js server that, on `SIGTERM`, logs `bye` and exits with code 0. Run it
   detached, stop it with `docker stop`, and confirm (a) that it stopped in well under a second, (b) that `bye` is in
   its logs, (c) that its exit code is 0. Then run the same server without the handler, with and without `--init`,
   and predict the stop time and exit code of each.

<details>
<summary>Solution</summary>

```bash
docker run -d --name g1 node:24-alpine node -e '
  const s = require("node:http").createServer((q, r) => r.end("ok")).listen(3000)
  process.on("SIGTERM", () => { console.log("bye"); s.close(() => process.exit(0)) })'
time docker stop g1          # well under a second
docker logs g1               # bye
docker inspect -f '{{.State.ExitCode}}' g1   # 0
docker rm g1
```

Without a handler:

| | Stop time | Exit code | Why |
|---|---|---|---|
| no `--init` (node is PID 1) | ~10 s | 137 | PID 1 ignores `SIGTERM` without a handler; Docker sends `SIGKILL` after the grace period |
| `--init` (node is PID 2+) | instant | 143 | `docker-init` forwards `SIGTERM`; `node` gets the default action and dies from the signal |

`s.close()` stops accepting new connections and calls back once existing ones are finished. Chapter 18 adds a
timeout for keep-alive connections that never finish.

</details>

4. **Restart policies.** Run a container with `--restart unless-stopped` whose command is `sh -c 'date; exit 1'`.
   Watch it for 20 seconds with `docker ps` and `docker logs`. What happens to the delay between restarts? Stop it with
   `docker stop`; does it come back?

<details>
<summary>Solution</summary>

```bash
docker run -d --name flappy --restart unless-stopped node:24-alpine sh -c 'date; exit 1'
for i in 1 2 3 4; do docker ps -a --filter name=flappy --format '{{.Status}}'; sleep 5; done
docker logs flappy                       # one date per run; the gaps grow
docker inspect -f '{{.RestartCount}}' flappy
docker stop flappy
docker ps -a --filter name=flappy --format '{{.Status}}'   # Exited (…) and stays that way
docker rm flappy
```

The timestamps in the logs show the backoff: restarts come quickly at first, then the gap doubles each time (100 ms,
200 ms, 400 ms, …). `docker ps` shows `Restarting (1) …` while it waits. After `docker stop` the container stays
stopped; with `unless-stopped` it also stays stopped when the daemon restarts, while with `always` it would come back.

</details>

5. **Template practice.** Start two or three containers (any), then write one command each to print: (a) every
   container's name and image, including stopped ones; (b) the name and IP address of every running container;
   (c) the names of all containers that exited with a non-zero code.

<details>
<summary>Solution</summary>

```bash
# (a)
docker ps -a --format 'table {{.Names}}\t{{.Image}}'

# (b) inspect accepts many containers at once; .Name has a leading slash
docker inspect -f '{{.Name}} {{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' $(docker ps -q)

# (c) exited containers, then filter on the code
docker inspect -f '{{if ne .State.ExitCode 0}}{{.Name}} {{.State.ExitCode}}{{end}}' $(docker ps -aq --filter status=exited)
```

`ne` is the template "not equal" function (also `eq`, `lt`, `gt`, `and`, `or`, `not`). For (c), Docker also has a
built-in filter for the common cases: `docker ps -a --filter exited=137`.

</details>

6. **Find the leak.** Run `docker system df -v`. Which of your images take the most space, and which containers have
   the largest writable layers? Clean up everything from this chapter without touching any volumes.

<details>
<summary>Solution</summary>

`docker system df -v` lists each image with its size and how many containers use it, each container with the size of
its writable layer (`SIZE`), each volume, and the build cache entries. Large writable layers usually mean an app is
writing logs or temporary files inside the container instead of to stdout or a volume.

```bash
docker container prune -f       # all stopped containers
docker image prune -f           # dangling images
docker system df                # check again
```

None of these touch volumes. Only `docker volume prune` or `docker system prune --volumes` would.

</details>

---
← Previous: [Getting started](01-getting-started.md) | Next: [Images and registries](03-images-and-registries.md) →
