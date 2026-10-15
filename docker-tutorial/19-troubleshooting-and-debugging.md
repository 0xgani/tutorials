# Chapter 19 — Troubleshooting and debugging

## What you'll learn

- A repeatable method for "it doesn't work", from the container's state to the cause
- What each exit code tells you, including the ones Docker itself produces
- Containers that won't start, and containers that keep restarting
- Getting inside containers that have no shell: debug containers that share namespaces, `nsenter`, and the Node.js
  inspector and heap snapshots in a running production container
- Debugging networking: DNS, ports, listen addresses, and which network a container is on
- Debugging builds: plain progress output, missing files, cache misses, and inspecting a stage
- Finding and reclaiming disk space
- A catalogue of common errors with their exact messages, causes and fixes

## A method

Most Docker problems are found faster by following the same steps every time than by guessing. In order:

1. **State.** Is it running, restarting, or exited, and with which exit code?

   ```bash
   docker compose ps -a          # or: docker ps -a
   ```

2. **Logs.** What did the process say before it stopped? Logs from *previous* runs of a restarting container are
   included.

   ```bash
   docker compose logs --tail 100 api
   ```

3. **Details.** The exit code, whether it was OOM-killed, the restart count, the health log, and the configuration it
   actually ran with:

   ```bash
   docker inspect snip-api-1 --format '{{json .State}}'
   docker inspect snip-api-1 --format '{{json .Config.Env}} {{json .Config.Cmd}} {{json .Mounts}}'
   ```

4. **History.** What happened around it, and when?

   ```bash
   docker events --since 30m --until 0s --filter container=snip-api-1
   ```

5. **The effective configuration.** What Compose will actually do after merging files and interpolating variables:

   ```bash
   docker compose config api
   ```

6. **Reproduce it small.** Run the same image by hand with an interactive shell or a single command, one setting at
   a time, until the difference shows up.

Each step either finds the cause or narrows where to look. The rest of this chapter is what to do at each fork.

## Exit codes

| Code | Meaning | Typical cause |
|---|---|---|
| 0 | Exited normally | The command finished. For a server: it had nothing to keep it running, or it shut down on `SIGTERM` (good) |
| 1 | Generic error | An uncaught exception in Node.js; read the logs |
| 78 | Configuration error (by convention) | Snip's invalid configuration (chapter 13) |
| 125 | Docker itself failed | A bad `docker run` flag or value, before any container existed |
| 126 | The command can't be executed | Not executable, or a directory |
| 127 | The command wasn't found | A typo, or a binary missing from the image (`sh` in distroless) |
| 134 | `SIGABRT` (128 + 6) | Node.js aborted, for example on a heap out-of-memory (chapter 17) |
| 137 | `SIGKILL` (128 + 9) | OOM kill (`OOMKilled=true`), `docker kill`, or a stop that timed out |
| 139 | `SIGSEGV` (128 + 11) | A crash in native code; also a Node.js abort while it runs as PID 1 (chapter 17) |
| 143 | `SIGTERM` (128 + 15) | Stopped, and the process exited from the signal instead of handling it |
| 255 | Out of range, or the runtime failed to start the process | `exec format error`, a broken shebang (below) |

The 12x codes come from Docker and the runtime, not your app:

```bash
docker run --rm --memory 1x alpine:3 true; echo "exit=$?"
docker run --rm alpine:3 /etc/passwd; echo "exit=$?"
docker run --rm alpine:3 nosuchcmd; echo "exit=$?"
```

```text
invalid argument "1x" for "-m, --memory" flag: invalid suffix: 'x'
exit=125
docker: Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process: error during container init: exec: "/etc/passwd": permission denied
exit=126
docker: Error response from daemon: … exec: "nosuchcmd": executable file not found in $PATH
exit=127
```

The long chain in those messages is Docker's architecture (chapter 20): the daemon asked containerd, containerd's
shim asked runc, and runc failed to `exec` the command. Read such messages from the end.

## Containers that won't start

### Read the error, then check the command

If `docker run` or `docker compose up` itself reports an error, the container never ran, and the message is the
whole story. Most are about the command, a mount, a port or a name; the catalogue at the end lists the common ones.

If the container started and exited at once, the logs have the reason. Check the command the image really runs,
which is the image's `ENTRYPOINT` plus `CMD`, or whatever the Compose file overrides:

```bash
docker image inspect snip:1.10 --format 'entrypoint={{json .Config.Entrypoint}} cmd={{json .Config.Cmd}} workdir={{.Config.WorkingDir}} user={{.Config.User}}'
```

### Two errors that lie about what's missing

**A script with Windows line endings.** An entrypoint script saved with CRLF line endings fails like this:

```bash
printf '#!/bin/sh\r\necho hello\r\n' > entry.sh && chmod +x entry.sh
# Dockerfile: FROM alpine:3 / COPY entry.sh /entry.sh / ENTRYPOINT ["/entry.sh"]
docker run --rm crlf
```

```text
exec /entry.sh: no such file or directory
```

The file exists. What's missing is the interpreter: the first line asks for `/bin/sh\r`, with an invisible carriage
return. Fix the file (`dos2unix`, or your editor's line-ending setting) and add a `.gitattributes` line such as
`*.sh text eol=lf` so Git never converts it on Windows. The same message appears when a script's shebang names an
interpreter the image doesn't have (`#!/bin/bash` on Alpine).

**An image for another CPU architecture.**

```bash
docker run --rm --platform linux/arm64 alpine:3 echo hi
```

```text
exec /bin/echo: exec format error
```

The binary is for arm64 and the machine is amd64, with no emulation installed. Either the image was built for the
wrong platform (an image built on an Apple Silicon Mac and pushed as-is, typically), or the registry has only one
platform. Build for the server's platform, or for both (chapter 14), and check what an image contains with
`docker buildx imagetools inspect <image>`.

### Look around with a different command

When the main command fails too early to debug, replace it:

```bash
docker run --rm -it --entrypoint sh snip:1.2-slim             # a shell instead of the app
docker compose run --rm --entrypoint sh api                   # with the service's env, volumes, networks
docker compose run --rm api node -e 'console.log(process.env.DATABASE_URL)'
```

`docker compose run` creates a one-off container with the service's full configuration (except published ports),
so you see exactly the environment, mounts and networks the service has. For distroless images there's no `sh`; use
`--entrypoint /nodejs/bin/node` with `-e`, or the techniques below.

## Containers that keep restarting

```bash
docker ps -a --filter name=crashy --format '{{.Names}}\t{{.Status}}'
docker logs crashy
docker inspect crashy --format 'restarts={{.RestartCount}} exit={{.State.ExitCode}} started={{.State.StartedAt}}'
```

```text
crashy	Restarting (1) 1 second ago
starting
Error: DATABASE_URL is not set
starting
…
restarts=6 exit=1 started=2026-10-15T11:05:18.67929084Z
```

`Restarting (1)` means the last exit code was 1 and the restart policy is about to start it again, with growing delays
between attempts. `docker logs` shows all the runs one after another, so the error is usually right there. If the
logs scroll by too fast, stop the loop first: `docker update --restart no crashy`, or `docker compose stop` the
service and run it once in the foreground with `docker compose run --rm api`.

Restart loops have three common families of causes:

| Cause | How it looks | Fix |
|---|---|---|
| Configuration | Exits within a second, with a clear message (or exit 78) | Fix the environment, secret or file; `docker compose config` shows what it got |
| A dependency isn't ready | `ECONNREFUSED`, `ENOTFOUND`, "the database system is starting up" | `depends_on` with `condition: service_healthy`, and retries in the app (chapters 10 and 11) |
| Resources | Exit 137, `oom` events, or exit 134/139 with "heap out of memory" | Chapter 17 |

A container that runs but is **unhealthy** keeps its old process; the healthcheck's own output says why:

```bash
docker inspect hc-db-1 --format '{{json .State.Health}}'
```

```text
{"Status":"unhealthy","FailingStreak":2,"Log":[{"Start":"…","End":"…","ExitCode":1,"Output":"checking port 5432\n"},…]}
```

Run the healthcheck command yourself with `docker exec` to see it fail live. Common causes: the check uses a tool
the image doesn't have (`curl` in a slim image), checks `localhost` while the app listens elsewhere, or its timeout
is shorter than a slow start (use `start_period`).

## Inside a container without a shell

Minimal images (distroless, chapter 7) have no shell, no `ps`, nothing to debug with. That's their security
advantage, and it's no obstacle once you know that containers are just namespaces (chapter 20) that *other*
containers can join.

```bash
docker run -d --name dl --read-only --cap-drop ALL snip:1.10
docker exec dl sh
```

```text
OCI runtime exec failed: exec failed: unable to start container process: exec: "sh": executable file not found in $PATH
```

### A debug container that shares namespaces

Start a container from an image that *has* tools, joined to the target's process and network namespaces:

```bash
docker run --rm -it --pid container:dl --network container:dl alpine:3 sh
```

Inside, you see the target's processes and its network as if you were in it:

```text
/ # ps -o pid,user,args
PID   USER     COMMAND
    1 65532    {MainThread} /nodejs/bin/node src/server.js
   48 root     sh
/ # netstat -tln
Proto Recv-Q Send-Q Local Address           Foreign Address         State
tcp        0      0 0.0.0.0:3000            0.0.0.0:*               LISTEN
/ # wget -qO- http://127.0.0.1:3000/healthz
{"status":"ok"}
```

The target's *files* are visible through `/proc/1/root`, but reading another user's process needs the `SYS_PTRACE`
capability, which isn't in the default set:

```bash
docker run --rm --pid container:dl --cap-add SYS_PTRACE alpine:3 ls /proc/1/root/app
```

```text
migrations
node_modules
package.json
src
```

Any image works as the toolbox: `alpine:3` with `apk add` for more tools, `busybox`, or a dedicated debugging image
such as `nicolaka/netshoot` (tcpdump, dig, curl, iperf and much more). Nothing is installed into the target, and
nothing is left behind.

> **Just a taste — you don't need to learn or fully understand this now.** Docker Desktop packages the same idea as one
> command.

```bash
docker debug snip-api-1
```

For files only, no extra container is needed: `docker cp dl:/app/package.json .` copies out of a running or stopped
container, and `docker diff dl` lists what changed in its writable layer. (Neither sees `tmpfs` mounts, which live
only in the container's memory.)

### `nsenter`: host tools in a container's namespace

On the host itself, `nsenter` runs a host program inside a container's namespaces. For example, the host's `ss`
inside the container's network namespace:

```bash
pid=$(docker inspect -f '{{.State.Pid}}' snip-api-1)
sudo nsenter -t "$pid" -n ss -tlnp
```

`-n` enters only the network namespace, so the filesystem and the tool are the host's. This needs root on the host
and doesn't work from inside a Docker Desktop VM's host side, but on a Linux server it's the quickest way to run
`tcpdump` against one container: `sudo nsenter -t "$pid" -n tcpdump -i eth0 port 5432`.

### The Node.js inspector, in production

Node.js starts its inspector when it receives `SIGUSR1`, even if it was started without `--inspect`:

```bash
docker kill --signal USR1 dl
docker logs dl 2>&1 | tail -2
```

```text
Debugger listening on ws://127.0.0.1:9229/04efd83b-8d89-4cde-b6da-9a394322a353
For help, see: https://nodejs.org/learn/getting-started/debugging
```

It listens on `127.0.0.1` *inside the container*, which is safe, and unreachable. To connect a debugger, forward the
port from a helper container in the same network namespace. `node` itself can do the forwarding:

```bash
docker run -d --name dbg-proxy --network container:dl node:24-alpine node -e "
  const net = require('net');
  net.createServer((c) => c.pipe(net.connect(9229, '127.0.0.1')).pipe(c)).listen(9230)"
ip=$(docker inspect dl --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}')
curl -s http://$ip:9230/json/version
```

```text
{
  "Browser": "node.js/v24.22.0",
  "Protocol-Version": "1.1"
}
```

From your laptop, an SSH tunnel to that address (`ssh -L 9229:<ip>:9230 deploy@server`) lets Chrome's
`chrome://inspect` or VS Code attach. Remove the helper (`docker rm -f dbg-proxy`) when done.

> **Watch out:** the inspector can run arbitrary code in the process, so never publish it on a server's port or bind it
> to anything but `127.0.0.1`. Pausing at a breakpoint also stops the process from answering requests and
> healthchecks: on a live system, prefer logpoints and heap snapshots.

### Heap snapshots from a read-only container

For a memory leak, a heap snapshot shows what's holding memory. `--heapsnapshot-signal` writes one on a signal, and
`--diagnostic-dir` says where, which has to be writable in a read-only container:

```bash
docker run -d --name hs --read-only --tmpfs /tmp:uid=65532 --cap-drop ALL \
  -e NODE_OPTIONS="--heapsnapshot-signal=SIGUSR2 --diagnostic-dir=/tmp" snip:1.10
docker kill --signal USR2 hs
```

`docker cp` can't read `tmpfs`, so stream the file out through the container's own `node`:

```bash
docker exec hs /nodejs/bin/node -e "
  const fs = require('fs');
  const f = fs.readdirSync('/tmp').find((name) => name.endsWith('.heapsnapshot'));
  fs.createReadStream('/tmp/' + f).pipe(process.stdout)" > api.heapsnapshot
```

Open it in Chrome DevTools (Memory tab, Load). Take two snapshots some minutes apart and compare them: what grew is the
leak. Writing a snapshot pauses the process and needs about as much extra memory as the heap itself, so take them
when a short pause is acceptable, and with enough headroom below the memory limit.

## Networking

Start from the error message; each points to a different layer:

| Node.js error | Means | Look at |
|---|---|---|
| `getaddrinfo ENOTFOUND db` | The name doesn't resolve | Are both containers on the same user-defined network? Is the service name spelled right? |
| `getaddrinfo EAI_AGAIN db` | DNS didn't answer | The container's DNS setup, or no network at all |
| `connect ECONNREFUSED 127.0.0.1:5432` | Nothing listens there | `localhost` inside a container is the container itself; use the service name |
| `connect ECONNREFUSED 172.18.0.3:5432` | The host is there, the port isn't open | The other service isn't up yet, or listens on another port |
| `connect ETIMEDOUT` | Packets are dropped | A firewall, or an address on a network the container isn't on |
| curl: `Connection reset by peer` | The port is published, but nothing inside answers | The app listens on `127.0.0.1` inside the container, or on another port |
| curl: `Couldn't connect to server` | Nothing listens on the host port | The port isn't published, or published on another address |

The two "connection reset" cases are among the most common Docker questions:

```bash
# The app listens on 127.0.0.1 inside the container
docker run -d --name lo -p 127.0.0.1:3070:3000 node:24-alpine \
  node -e "require('http').createServer((q, s) => s.end('ok')).listen(3000, '127.0.0.1')"
curl -sS localhost:3070
```

```text
curl: (56) Recv failure: Connection reset by peer
```

```bash
docker exec lo netstat -tln | grep 3000
```

```text
tcp        0      0 127.0.0.1:3000          0.0.0.0:*               LISTEN
```

Docker's port forwarding arrives on the container's `eth0` address, not its loopback, so a server bound to
`127.0.0.1` inside never sees it (chapter 2). Listen on `0.0.0.0`, or leave out the host argument as Snip does. The
same message appears when `-p 3071:3001` publishes a port the app doesn't use.

The checks, in order:

```bash
docker port snip-api-1                                    # what's published, and where
docker inspect snip-api-1 --format '{{json .NetworkSettings.Networks}}'   # which networks, which IPs
docker network inspect snip_default --format '{{range .Containers}}{{.Name}} {{.IPv4Address}}{{"\n"}}{{end}}'
docker run --rm --network container:snip-api-1 alpine:3 sh -c 'nslookup db; nc -zv db 5432'
```

The last line tests DNS and the TCP connection from *exactly* the network position of the API container, which is
almost always the question.

## Builds

### See what's happening

BuildKit's default output collapses finished steps. For debugging, show everything, and skip the cache to rule it out:

```bash
docker build --progress=plain --no-cache .
```

A failing `RUN` shows its command, the Dockerfile line, and the exit code:

```text
------
Dockerfile:2
--------------------
   1 |     FROM alpine:3
   2 | >>> RUN echo building && exit 3
   3 |
--------------------
ERROR: failed to build: failed to solve: process "/bin/sh -c echo building && exit 3" did not complete successfully: exit code: 3
```

To explore the state just *before* a failing step, build the stage up to the previous step and run a shell in it.
With multi-stage builds that's `--target`; within a stage, temporarily comment out the failing line:

```bash
docker build --target deps -t debug-deps .
docker run --rm -it debug-deps sh
```

### Files that aren't there

```text
ERROR: failed to build: failed to solve: failed to compute cache key: failed to calculate checksum of ref …: "/package.json": not found
```

`COPY` looks in the **build context**, after `.dockerignore` filtering. The file is excluded by `.dockerignore`, the
build context is a different directory than you think (the last argument of `docker build`, or `context:` in
Compose), or the path is relative to the wrong place. The opposite problem, a huge context, shows up as a long
"transferring context" step: check `.dockerignore` for `node_modules`, `.git` and build output (chapter 4).

### Slow builds

Almost always the cache (chapter 6): a `COPY . .` before `npm ci`, a changing build argument early in the file, or a
CI runner that starts with an empty cache every time (chapter 14's registry cache). `--progress=plain` marks each
step `CACHED` or not; the first step that isn't cached is where to look.

## Disk space

Docker keeps everything until told otherwise: old images, stopped containers, unused volumes, build cache, and logs.

```bash
docker system df
```

```text
TYPE            TOTAL     ACTIVE    SIZE      RECLAIMABLE
Images          72        1         5.173GB   1.832GB (35%)
Containers      1         1         20.48kB   0B (0%)
Local Volumes   9         1         499.7MB   296.2MB (59%)
Build Cache     127       1         1.142GB   289.2MB
```

`docker system df -v` lists each item. Reclaiming, from safest to most drastic:

```bash
docker container prune                         # stopped containers
docker image prune                             # dangling images (untagged leftovers of rebuilds)
docker image prune -a --filter until=168h      # every unused image older than a week
docker builder prune --filter until=168h       # build cache older than a week
docker volume prune                            # anonymous volumes not used by any container
docker system prune                            # containers, networks, dangling images, build cache
```

> **Watch out:** `docker volume prune -a` (and `docker system prune --volumes -a`) also deletes *named* volumes that
> no container currently uses, such as a database volume whose stack is stopped. Never run it on a server without
> reading the list first.

Logs aren't in `docker system df`. Look at `/var/lib/docker/containers/*/*-json.log` (chapter 17), and remember that
deleting files inside a container doesn't shrink the image layers below it (chapter 3).

## A catalogue of common errors

| Message (abbreviated) | Cause | Fix |
|---|---|---|
| `Bind for 127.0.0.1:3000 failed: port is already allocated` | Another container (or a second replica) publishes that host port | `docker ps --filter publish=3000`; one container per host port, or no fixed host port |
| `address already in use` | A host process uses the port | `sudo ss -tlnp 'sport = :3000'` |
| `Conflict. The container name "/dup" is already in use` | A stopped container with that name exists | `docker rm dup`, or `--rm` for one-off containers |
| `exec /entry.sh: no such file or directory` | CRLF line endings or a missing interpreter in the shebang | `dos2unix`, `.gitattributes`, the right interpreter |
| `exec format error` | Image for another CPU architecture | Build for the right platform (chapter 14) |
| `executable file not found in $PATH` | Typo, or the tool isn't in the image | Check `CMD`/`ENTRYPOINT`; distroless has no shell |
| `EACCES: permission denied, open '/data/x'` | The container's user can't write to a mounted directory | Match ownership (chapter 8), or a named volume |
| `EROFS: read-only file system` | Writing outside volumes and `tmpfs` with `read_only: true` | A `tmpfs` or a volume for that path (chapter 16) |
| `failed to compute cache key: … not found` | `COPY` source missing from the build context | `.dockerignore`, context path |
| `failed to resolve reference "…": not found` | Wrong image name or tag, or a private image | Check the name; `docker login` |
| `toomanyrequests: …` | Docker Hub's pull rate limit | Log in; use a mirror or your own registry (chapter 15) |
| `no space left on device` | Disk full: images, build cache, volumes or logs | `docker system df`, log rotation, prune |
| `network … has active endpoints` | Removing a network that containers still use | Stop or disconnect those containers first |
| `dependency failed to start: container … is unhealthy` | A `depends_on` healthcheck failed | `docker inspect` the dependency's `.State.Health` |
| `dependency failed to start: container … has no healthcheck configured` | `condition: service_healthy` on a service without a healthcheck | Add one in the image or in Compose |
| `failed to connect to the docker API at unix:///…` | The daemon isn't running, or the CLI points at the wrong socket or context | `systemctl status docker`, `docker context ls`, `DOCKER_HOST` |
| `permission denied while trying to connect to the docker API` | Your user can't access the socket | `sudo`, or the `docker` group (root-equivalent, chapter 16) |
| `ECONNREFUSED 127.0.0.1:<port>` | Using `localhost` to reach another container | The service name |
| `getaddrinfo ENOTFOUND <name>` | Not on the same user-defined network, or a typo | `docker network inspect` |
| Exit 137 with `OOMKilled=true` | Memory limit | Chapter 17 |

Two of these came up while writing this tutorial's own examples: the port conflict in chapter 18, when a deploy
script accidentally merged the development override that publishes `127.0.0.1:3000`, and the missing healthcheck,
when the development image (which has none) became a dependency of nginx.

## Key takeaways

- Follow the same steps every time: state (`ps -a`), logs, `inspect`, `events`, `compose config`, then reproduce small.
- Exit codes narrow it down: 125–127 are Docker failing to start the command, 137 is `SIGKILL` (check
  `OOMKilled`), 143 is `SIGTERM`, 1 is an app error whose message is in the logs.
- "No such file or directory" for a file that exists means its interpreter is missing (CRLF, or the wrong shebang);
  "exec format error" means the wrong CPU architecture.
- `docker compose run --rm --entrypoint sh <service>` gives you a shell with the service's exact configuration.
- Distroless containers are debugged from the outside: a tool container joined with `--pid container:` and
  `--network container:`, `nsenter` on the host, `docker cp`, `SIGUSR1` for the Node.js inspector, and
  `--heapsnapshot-signal` for memory leaks.
- For network problems, read the error type, then test DNS and TCP from the failing container's own network
  namespace. Servers inside containers must listen on `0.0.0.0`.
- Debug builds with `--progress=plain --no-cache` and `--target`; "not found" in a `COPY` is about the build context.
- `docker system df` shows where the disk went; prune with filters, and never prune named volumes blindly.

## Exercises

1. **Three broken containers.** For each command, predict the exit code and the message, run it, and explain what
   happened.

   ```bash
   docker run --rm node:24-alpine node server.js
   docker run --rm --entrypoint /app alpine:3
   docker run --rm -m 6m node:24-alpine node -e 'console.log("hi")'
   ```

<details>
<summary>Solution</summary>

1. Exit 1, `Error: Cannot find module '/server.js'`: the image has no app, and the working directory is `/`. Node.js
   started fine, so this is an app-level error.
2. Exit 127, `exec: "/app": stat /app: no such file or directory`: the entry point doesn't exist in `alpine:3`.
   Docker couldn't start the process at all.
3. Exit 137 and nothing printed: Docker accepts 6 MB (its minimum), but Node.js needs far more just to start, so
   the kernel kills it. Run it without `--rm` and `docker inspect` shows `OOMKilled=true`.

The point of the exercise is the shape of each failure: a message from the app with exit 1, a message from the
runtime with 12x, and a silent 137 from the kernel.

</details>

2. **The invisible character.** Create an entrypoint script with Windows line endings, reproduce the
   `no such file or directory` error, prove with a tool that the carriage return is there, and fix it in the
   Dockerfile so the build is safe even if a Windows checkout converts the file again.

<details>
<summary>Solution</summary>

```bash
printf '#!/bin/sh\r\necho hello\r\n' > entry.sh && chmod +x entry.sh
head -1 entry.sh | od -c
```

```text
0000000   #   !   /   b   i   n   /   s   h  \r  \n
```

The `\r` before `\n` is the problem. A defensive Dockerfile strips it during the build:

```dockerfile
FROM alpine:3
COPY entry.sh /entry.sh
RUN sed -i 's/\r$//' /entry.sh && chmod +x /entry.sh
ENTRYPOINT ["/entry.sh"]
```

The real fix belongs in Git, so the file is never converted: a `.gitattributes` with `*.sh text eol=lf`.

</details>

3. **Debug without a shell.** Start Snip's distroless image (`snip:1.10`) with `--read-only --cap-drop ALL`. Without
   changing that container, find out: which user its process runs as, which ports it listens on, which environment
   variables it has, and whether it can resolve `example.com`.

<details>
<summary>Solution</summary>

```bash
docker run -d --name dl --read-only --cap-drop ALL snip:1.10
docker run --rm --pid container:dl --network container:dl --cap-add SYS_PTRACE alpine:3 sh -c '
  ps -o pid,user,args
  netstat -tln
  tr "\0" "\n" < /proc/1/environ
  nslookup example.com'
docker rm -f dl
```

The process runs as UID 65532 (`nonroot`), listens on `0.0.0.0:3000`, and has `NODE_ENV=production`, `PATH`,
`HOSTNAME` and the image's `ENV` values. Reading `/proc/1/environ` needs `SYS_PTRACE` for the same reason as
`/proc/1/root`. Note what this means for secrets: anyone who can run a container on the host can read every other
container's environment, which is one more reason to pass secrets as files (chapter 13). The DNS lookup runs in the
target's network namespace, so it answers whether *Snip* can resolve the name.

</details>

4. **A leak, found.** Add a deliberate leak to a copy of Snip (for example, push every request's headers into a
   module-level array), run it with `--heapsnapshot-signal=SIGUSR2 --diagnostic-dir=/tmp` and a `tmpfs` on `/tmp`,
   send traffic, take two snapshots a minute apart, copy them out, and find the leak in Chrome DevTools.

<details>
<summary>Solution</summary>

The leak, in `src/app.js`:

```js
const seen = [];                          // never cleared
app.use((req, res, next) => { seen.push({ ...req.headers }); next(); });
```

```bash
docker build -t snip:leaky .
docker run -d --name leaky -p 127.0.0.1:3000:3000 --read-only --tmpfs /tmp:uid=65532 \
  -e NODE_OPTIONS="--heapsnapshot-signal=SIGUSR2 --diagnostic-dir=/tmp" snip:leaky
for i in $(seq 5000); do curl -s -o /dev/null localhost:3000/healthz; done
docker kill --signal USR2 leaky
for i in $(seq 20000); do curl -s -o /dev/null localhost:3000/healthz; done
docker kill --signal USR2 leaky
docker exec leaky /nodejs/bin/node -e "console.log(require('fs').readdirSync('/tmp').join('\n'))"
for f in $(docker exec leaky /nodejs/bin/node -e "console.log(require('fs').readdirSync('/tmp').join(' '))"); do
  docker exec leaky /nodejs/bin/node -e "require('fs').createReadStream('/tmp/$f').pipe(process.stdout)" > "$f"
done
```

In DevTools' Memory tab, load both files, select the second, and choose "Comparison" against the first. The `Object`
constructor shows thousands of new instances, and their retainers lead to the `seen` array in `app.js`. The `tmpfs`
needs `uid=65532` so the non-root process can write to it.

</details>

5. **Where did the disk go?** On your machine, find the five largest images, the build cache size, and any volume
   not used by a container. Remove only things you can prove are unused, and explain why `docker system prune -a
   --volumes` is not the answer on a server.

<details>
<summary>Solution</summary>

```bash
docker image ls --format '{{.Size}}\t{{.Repository}}:{{.Tag}}' | sort -h | tail -5
docker system df -v | sed -n '/Build cache usage/,$p' | head -20
docker volume ls --filter dangling=true                  # volumes no container references
docker volume inspect <name> --format '{{.CreatedAt}} {{.Labels}}'
```

A volume's `com.docker.compose.project` label tells you which project created it; a `dangling` volume whose project
still exists may just belong to a stack that's stopped right now. `docker image prune -a --filter until=168h` and
`docker builder prune --filter until=168h` are safe because images and cache can be rebuilt or pulled again. `docker
system prune -a --volumes` would also delete stopped stacks' named volumes, which are often the only copy of a
database. Data is never "just cache" until you've checked.

</details>

---
← Previous: [Running in production](18-running-in-production.md) | Next: [How Docker works inside](20-how-docker-works-inside.md) →
