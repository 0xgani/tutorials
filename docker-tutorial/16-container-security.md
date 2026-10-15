# Chapter 16 — Container security

## What you'll learn

- A threat model for containers: what isolation gives you, and where it ends
- Running as a non-root user, and what "root in a container" can still do
- Linux capabilities: the default set, dropping all of them, and adding back only what a service needs
- `no-new-privileges`, read-only root filesystems, seccomp, AppArmor and SELinux
- Why `--privileged` and the Docker socket are equivalent to root on the host
- User namespaces and rootless Docker
- Scanning images for vulnerabilities, and comparing base images by what they contain
- The software supply chain: pinning, minimal images, SBOMs, signatures and npm install scripts
- Snip with every service hardened, including PostgreSQL and Valkey

## The threat model

Containers isolate processes with kernel features (namespaces, cgroups, capabilities, seccomp; chapter 20). That's
strong isolation for everyday purposes, but it's not a virtual machine: every container shares the host's kernel. The
questions to ask:

| Threat | Example | Main defenses |
|---|---|---|
| A compromised app | A remote code execution bug in a dependency | Non-root user, no capabilities, read-only filesystem, network segmentation, minimal image (fewer tools for an attacker) |
| Escape to the host | A kernel bug, a dangerous mount, a privileged container | Seccomp, no capabilities, no `--privileged`, no Docker socket, user namespaces, a patched kernel |
| A malicious or vulnerable image | A typo-squatted base image, an outdated OS package | Official or verified images, pinning, scanning, signatures |
| Leaked secrets | A token in a layer or an environment variable | Chapter 13 |
| Resource exhaustion | A fork bomb, a memory leak taking down the host | Limits (chapter 17) |

The guiding principle is **least privilege**: a process gets exactly the permissions it needs, so that when (not if)
something in it is compromised, the attacker gets as little as possible. Docker's defaults are a reasonable middle
ground for running arbitrary images. For your own services, which you know, you can go much further.

## Users: root in a container is still root

By default a container's process runs as `root` (UID 0). It's not *full* root: it's confined by namespaces, has a
reduced set of capabilities and is filtered by seccomp. But UID 0 inside is UID 0 outside (unless user namespaces are
enabled, below), so every isolation bug turns into root on the host, and every file it can reach through a mount is
treated as root's.

Chapter 7 made Snip run as UID 65532 (`nonroot` in distroless) and chapter 12's dev image as `node` (UID 1000). For
images you don't build yourself, `--user` (or `user:` in Compose) overrides the image's user, as long as the image
works without root. Images that start as root and then drop privileges in their entrypoint, like `postgres` and
`valkey`, are covered below.

A detail that removes one common reason for root: inside containers, Docker sets
`net.ipv4.ip_unprivileged_port_start` to `0`, so non-root processes can listen on ports below 1024:

```bash
docker run --rm --user 1000 node:24-alpine node -e \
  'require("http").createServer().listen(80, () => { console.log("listening on 80"); process.exit(0) })'
```

```text
listening on 80
```

## Capabilities

Linux splits root's powers into about forty **capabilities**: `CHOWN` (change file owners), `NET_ADMIN` (configure
networks), `SYS_ADMIN` (mount filesystems and much more), `SYS_PTRACE` (debug other processes), and so on. Docker gives
containers a reduced default set. You can see it as a bitmask in `/proc`:

```bash
docker run --rm alpine:3 grep CapEff /proc/1/status
```

```text
CapEff:	00000000a80425fb
```

Decoded, that's 14 capabilities:

```text
CHOWN DAC_OVERRIDE FOWNER FSETID KILL SETGID SETUID SETPCAP NET_BIND_SERVICE NET_RAW SYS_CHROOT MKNOD AUDIT_WRITE SETFCAP
```

Notably absent: `SYS_ADMIN`, `NET_ADMIN`, `SYS_PTRACE`, `SYS_MODULE`, `DAC_READ_SEARCH`. A Node.js web service needs
**none** of the 14. Drop them all:

```bash
docker run --rm --cap-drop ALL alpine:3 sh -c 'grep CapEff /proc/1/status; touch /f; chown 1000 /f || echo "chown failed"'
```

```text
CapEff:	0000000000000000
chown: /f: Operation not permitted
chown failed
```

Even as root, that container can't change file ownership, bypass file permissions on files it doesn't own, send
signals to processes of other users, create device nodes or open raw sockets. Add back individual capabilities with
`--cap-add` when a service genuinely needs them.

What capabilities *don't* cover is ordinary ownership. Root owns most system files, and owning a file needs no
capability. That shows up in the next section.

## `no-new-privileges`

Setuid programs (like `su`, `sudo`, `passwd`, `ping` on older systems) run with their *owner's* identity, usually
root, whoever starts them. In a container that runs as non-root, a setuid-root binary is a ready-made way back to
root. Here's a deliberately dangerous image with a setuid copy of `cat`:

```dockerfile
FROM node:24-slim
# A deliberately dangerous image: a setuid-root copy of "cat".
RUN cp /bin/cat /usr/local/bin/rootcat && chmod u+s /usr/local/bin/rootcat
USER node
```

```bash
docker run --rm nnp-demo sh -c 'id -u; cat /etc/shadow | head -1; rootcat /etc/shadow | head -1'
```

```text
1000
cat: /etc/shadow: Permission denied
root:*:20731:0:99999:7:::
```

User 1000 just read the password hashes. Dropping all capabilities doesn't help here:

```bash
docker run --rm --cap-drop ALL nnp-demo rootcat /etc/shadow | head -1
```

```text
root:*:20731:0:99999:7:::
```

The setuid binary runs with effective UID 0, and UID 0 *owns* `/etc/shadow`, so it may read it without any
capability. `no-new-privileges` closes the hole: it tells the kernel that this process and its children can never
gain privileges through `execve`, so setuid bits and file capabilities are ignored:

```bash
docker run --rm --security-opt no-new-privileges nnp-demo rootcat /etc/shadow
```

```text
rootcat: /etc/shadow: Permission denied
```

There's no downside for normal services, so set it everywhere. Images that switch users in their entrypoint with
`gosu`, `su-exec` or `setpriv` keep working, because those tools use the `setuid()` system call (which needs the
`SETUID` capability), not a setuid binary.

## Read-only root filesystem

Chapter 8 introduced `--read-only`. For security it means an attacker can't drop a binary, modify your code, or
plant a cron job in the container. Give the process `tmpfs` mounts for the few places that need writes (`/tmp`, a
cache directory) and volumes for real data.

## Seccomp, AppArmor and SELinux

**Seccomp** filters system calls. Docker's default profile blocks around 40 of the 300+ system calls, the ones rarely
needed by applications and often used in kernel exploits: `mount`, `reboot`, `kexec_load`, `bpf`, `unshare` and
`clone` with new namespaces (for unprivileged containers), `keyctl` and more. Docker reports which mechanisms are
active:

```bash
docker info --format '{{json .SecurityOptions}}'
docker run --rm alpine:3 unshare -U true
```

```text
["name=seccomp,profile=builtin"]
unshare: unshare(0x10000000): Operation not permitted
```

The same command with `--security-opt seccomp=unconfined` succeeds. Never disable seccomp to "fix" a container; find
the one system call that's missing and write a custom profile (`--security-opt seccomp=profile.json`) if you really
must.

**AppArmor** (Ubuntu, Debian, SUSE) and **SELinux** (Fedora, RHEL, CentOS Stream) are mandatory access control
systems; Docker applies a default policy (`docker-default` for AppArmor; the `container_t` type for SELinux) when the
host has one enabled, which `docker info` shows. On SELinux hosts, bind mounts need a label to be readable from
containers: `-v ./data:/data:z` (shared) or `:Z` (private to this container). A `Permission denied` on a bind mount
on Fedora or RHEL is usually this.

## The two things never to do

### `--privileged`

```bash
docker run --rm --privileged alpine:3 sh -c 'grep CapEff /proc/1/status; ls /dev | wc -l'
docker run --rm alpine:3 sh -c 'grep CapEff /proc/1/status; ls /dev | wc -l'
```

```text
CapEff:	000001fffeffffff
111
CapEff:	00000000a80425fb
14
```

A privileged container gets every capability, every host device, no seccomp filter and no AppArmor profile. It can
mount the host's disks, load kernel modules and trivially take over the host. It exists for things like running
Docker inside Docker or system tools that manage the host; an application never needs it. When a guide says "add
`--privileged` to make it work", find out which capability or device is really missing.

### Mounting the Docker socket

`/var/run/docker.sock` is the Docker Engine's API. Whoever can talk to it can start a container with the host's root
filesystem mounted:

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock docker:cli \
  docker run --rm -v /:/host alpine:3 sh -c 'head -1 /host/etc/shadow; hostname'
```

```text
root:*:20713:0:99999:7:::
0fdf0213f4f6
```

That's the *host's* password file, read from a container that was started from a container. Mounting the socket into
a container is giving that container root on the host. The same goes for membership in the `docker` group on the host
(`srw-rw---- root docker /var/run/docker.sock`): it's root-equivalent.

Some tools legitimately need the API (CI runners, reverse proxies that discover containers, monitoring agents).
Options, from better to worse: a tool that doesn't need it (static configuration instead of discovery); a filtering
proxy that allows only the read-only API calls the tool needs (`tecnativa/docker-socket-proxy` is a popular one);
the socket mounted read-only, which **doesn't help** (`:ro` only stops replacing the socket file, not using it).

## User namespaces and rootless Docker

**User namespaces** map UIDs in the container to different UIDs on the host: root (0) inside becomes, say, 100000
outside, an unprivileged user that owns nothing on the host. Even a full escape then lands as a nobody.

- **`userns-remap`** in the daemon configuration enables this for all containers of a normal (rootful) daemon. It has
  side effects: volume and bind-mount ownership must match the remapped UIDs, and some features (`--privileged`,
  host networking for some uses) don't combine with it.
- **Rootless Docker** runs the whole daemon as an ordinary user, inside a user namespace. The daemon itself isn't
  root, so neither a daemon bug nor the API is root on the host. Limits: no ports below 1024 on the host by default,
  networking through a user-mode network stack (slower), and some storage and cgroup features need extra setup.
  `dockerd-rootless-setuptool.sh install` sets it up; Docker's documentation covers the prerequisites per
  distribution.

Rootless mode is a good default for shared machines and CI runners. Docker Desktop already runs the engine inside a
VM, which isolates it from your Mac or Windows host.

## Scanning images for vulnerabilities

An image contains hundreds of packages, OS and npm, and some of them have known vulnerabilities (CVEs). A **scanner**
lists the packages (or reads the SBOM from chapter 14) and compares them with vulnerability databases. Common
scanners: **Trivy** (open source), **Grype** (open source), **Docker Scout** (built into Docker Desktop and the CLI as
`docker scout cves`, requires a Docker login), and the scanners built into registries (ECR, Artifact Registry, GHCR
via Dependabot, Harbor).

With Trivy:

```bash
trivy image --severity HIGH,CRITICAL snip:1.7
```

```text
Report Summary

┌───────────────────────────────────────────────────────┬──────────┬─────────────────┬─────────┐
│                        Target                         │   Type   │ Vulnerabilities │ Secrets │
├───────────────────────────────────────────────────────┼──────────┼─────────────────┼─────────┤
│ snip:1.7 (debian 13.7)                                │  debian  │        0        │    -    │
├───────────────────────────────────────────────────────┼──────────┼─────────────────┼─────────┤
│ app/node_modules/@redis/bloom/package.json            │ node-pkg │        0        │    -    │
…
```

The more interesting result is comparing images. Scanned on the same day (October 15, 2026) with the same database,
the three kinds of Snip image from chapters 4 and 7:

| Image | Base | Critical | High | Medium | Low |
|---|---|---|---|---|---|
| `snip:single-24` (chapter 4's first try) | `node:24` (Debian 12, full) | 20 | 487 | 2868 | 1839 |
| `snip:1.2-slim` | `node:24-slim` (Debian 12) | 4 | 56 | 108 | 84 |
| `snip:1.7` (distroless) | `distroless/nodejs24-debian13` | 0 | 0 | 23 | 8 |

Your numbers will be different (databases and images change daily), but the proportions are typical: most findings
are in OS packages the app never uses (compilers, Perl, ImageMagick libraries in the full image). Fewer packages
mean fewer vulnerabilities, fewer alerts to triage and fewer tools for an attacker.

### Scanning in CI

```bash
trivy image --exit-code 1 --severity CRITICAL --ignore-unfixed snip:1.7     # exit 0: nothing to block
trivy image --exit-code 1 --severity CRITICAL --ignore-unfixed snip:1.2-slim # exit 1: fixable criticals
```

A practical policy:

- **Fail the build** on critical (maybe high) vulnerabilities that have a fix (`--ignore-unfixed`): those you can act
  on now by rebuilding or upgrading.
- **Report, don't block,** everything else, and review it regularly. Blocking on unfixable findings only teaches
  people to disable the scanner.
- **Rebuild regularly** even when your code doesn't change, for example weekly, to pick up patched base images. An
  image built six months ago is six months of unpatched CVEs, however clean it was on day one.
- **Record exceptions** (`.trivyignore`, VEX documents) with a reason and an expiry date, for findings that don't
  apply (a vulnerable function you never call).

## The supply chain

Everything in your image came from somewhere: the base image, OS packages, npm packages, the build tools. Each is a
way in for an attacker. The defenses, most of which earlier chapters already introduced:

- **Trusted base images.** Docker Official Images, Verified Publishers, distroless, or Docker Hardened Images
  (minimal, continuously patched images with SBOMs and provenance, free to use since December 2025 at `dhi.io`).
  Avoid random images with a plausible name: typo-squatting exists on Docker Hub as on npm.
- **Pin by digest** (`FROM node:24-slim@sha256:…`) so that a build uses exactly the base you tested, and let a bot
  (Dependabot or Renovate both understand Dockerfiles) open pull requests when the digest behind the tag changes.
  You get reproducible builds *and* timely updates, with a review step in between.
- **Lockfiles and `npm ci`.** Exact dependency versions, verified against the lockfile's integrity hashes.
- **Install scripts.** npm packages can run arbitrary code on `npm install` through `preinstall`/`postinstall`
  scripts, which is how most npm supply-chain attacks execute. `npm ci --ignore-scripts` skips them; packages with
  native code then need an explicit `npm rebuild <package>`. Running the install in a build stage, without secrets and
  without network access to anything internal, limits the damage of a script that does run.
- **SBOM and provenance** (chapter 14) to know what's inside and where it came from, **signatures** (chapter 15) to
  know who published it, and **verification at deploy time**.
- **Least privilege at runtime** (this chapter), so that a compromised dependency finds nothing useful.

## Snip, hardened

The complete project is in [`examples/ch16-hardened/`](examples/ch16-hardened/). The services that run Snip's image
need nothing: no capabilities, no writes outside their volumes, no setuid binaries. One block in the shared extension
field covers the API, the worker and the migration job:

```yaml
x-snip: &snip
  build: .
  image: snip:1.8
  # … environment, secrets as before …
  # Hardening (chapter 16). Snip needs no Linux capabilities at all, never writes outside
  # its volumes, and has no setuid programs it should be able to use.
  read_only: true
  cap_drop: [ALL]
  security_opt: [no-new-privileges:true]
```

The databases are more interesting. Their images start as root, fix the ownership of the data directory, and then
switch to an unprivileged user. Dropping every capability breaks that, and the error messages say exactly what's
missing:

```bash
docker run --rm --cap-drop ALL -e POSTGRES_PASSWORD=x postgres:18-alpine
docker run --rm --cap-drop ALL valkey/valkey:9-alpine
```

```text
chmod: /var/run/postgresql: Operation not permitted
chown: /var/lib/postgresql/18/docker: Operation not permitted
setpriv: setresuid failed: Operation not permitted
```

So drop all and add back the few they need:

```yaml
  db:
    image: postgres:18-alpine
    # The entrypoint starts as root to fix ownership of the data directory, then switches to the
    # postgres user: that needs these capabilities, and nothing else.
    cap_drop: [ALL]
    cap_add: [CHOWN, DAC_OVERRIDE, FOWNER, SETGID, SETUID]
    security_opt: [no-new-privileges:true]
    read_only: true
    tmpfs:
      - /var/run/postgresql
      - /tmp
    # … environment, secrets, volumes, healthcheck as before …

  valkey:
    image: valkey/valkey:9-alpine
    # The entrypoint fixes ownership of /data as root (CHOWN, and DAC_OVERRIDE to look inside the
    # valkey-owned appendonlydir on restarts), then switches to the valkey user (SETGID, SETUID).
    # tini, running as root, forwards docker stop's SIGTERM to the valkey user's process (KILL).
    cap_drop: [ALL]
    cap_add: [CHOWN, DAC_OVERRIDE, KILL, SETGID, SETUID]
    security_opt: [no-new-privileges:true]
    read_only: true
    # … command, volumes, healthcheck as before …
```

`read_only` works for PostgreSQL once its socket directory and `/tmp` are `tmpfs`; its data is in the volume.

**Test restarts, not just the first start.** The first version of the Valkey settings had only `CHOWN`, `SETGID` and
`SETUID`, and it started perfectly, on an empty volume. On the second start, with data present, it failed:

```text
valkey-1   | find: ./appendonlydir: Permission denied
```

The entrypoint runs `find` as root over `/data` to fix ownership, and `appendonlydir` (created by Valkey on the first
run) belongs to the `valkey` user with mode `700`. Without `DAC_OVERRIDE`, root can't look inside a directory it
doesn't own. `DAC_READ_SEARCH` would also work, but it's deliberately left out of Docker's default set because it
allows `open_by_handle_at`, a system call used in a well-known container escape; `DAC_OVERRIDE` is the more common
and better understood choice.

**Test stops, too.** With those four capabilities Valkey ran fine, but `docker compose stop` left it with exit code 1:

```text
[FATAL tini (1)] Unexpected error when forwarding signal: 'Operation not permitted'
```

The image's entrypoint is `tini`, a small init (chapter 2) that runs as root and forwards signals to Valkey. Valkey
runs as the `valkey` user, and sending a signal to another user's process needs `KILL`. Without it, tini gave up and
exited, the kernel killed Valkey on the spot, and Valkey never wrote its final snapshot. Nothing *looked* broken; it
just lost the last moments of data on every stop. With `KILL` added, the log ends the way it should:

```text
* Saving the final RDB snapshot before exiting.
* DB saved on disk
# Valkey is now ready to exit, bye bye...
```

PostgreSQL doesn't need `KILL`: its entrypoint uses `exec` to *become* the server process, so the server is PID 1 and
receives the signal directly. Bringing the stack up, stopping it, and starting it again, then reading the exit codes
and the last log lines, is now a standard check for every hardening change.

The development override needs one exception: Compose Watch copies files into the container, which a read-only root
filesystem forbids, so `compose.override.yaml` sets `read_only: false` for the `dev` services.

Check the result:

```bash
docker inspect snip-api-1 --format '{{.HostConfig.ReadonlyRootfs}} {{.HostConfig.CapDrop}} {{.HostConfig.SecurityOpt}}'
```

```text
true [ALL] [no-new-privileges:true]
```

## A checklist

| | Setting | Where |
|---|---|---|
| ☐ | Non-root user | `USER` in the Dockerfile (chapter 7) |
| ☐ | No capabilities, or only the needed ones | `cap_drop: [ALL]`, `cap_add` |
| ☐ | No privilege escalation | `security_opt: [no-new-privileges:true]` |
| ☐ | Read-only root filesystem | `read_only: true`, `tmpfs` for scratch space |
| ☐ | Never `privileged`, never the Docker socket | |
| ☐ | Seccomp and AppArmor/SELinux left on | the defaults |
| ☐ | Only needed ports published, on the right interface | chapter 9 |
| ☐ | Internal networks for internal services | chapter 9 |
| ☐ | Secrets as files, not in the image or environment | chapter 13 |
| ☐ | Minimal, pinned, regularly rebuilt base image | chapter 7, this chapter |
| ☐ | Scanned in CI; fixable criticals block | this chapter |
| ☐ | Signed, verified at deploy time | chapter 15 |
| ☐ | Resource limits | chapter 17 |

## Key takeaways

- Containers share the host kernel; defense in depth means limiting what a compromised process can do.
- Run as non-root. Non-root processes can still listen on low ports inside containers.
- Drop all capabilities and add back only what a service needs. Most Node.js services need none.
- `no-new-privileges` stops setuid binaries; capabilities alone don't, because root owns root's files.
- Keep seccomp and AppArmor/SELinux on; use read-only root filesystems with `tmpfs` for scratch space.
- `--privileged` and the Docker socket (or the `docker` group) are root on the host.
- User namespaces and rootless Docker make even container root unprivileged on the host.
- Scan images in CI, block on fixable criticals, rebuild regularly. Smaller base images mean far fewer findings.
- Secure the supply chain: trusted, pinned bases, lockfiles, careful install scripts, SBOMs and signatures.
- After any hardening change, test a restart with existing data, not just a fresh start.

## Exercises

1. **Which capability?** A container running as root needs to listen on port 80 and to `chown` files in a volume at
   startup; nothing else. Write the `docker run` flags with the minimum set of capabilities. Then check whether you
   even need the first one.

<details>
<summary>Solution</summary>

```bash
docker run --cap-drop ALL --cap-add CHOWN --security-opt no-new-privileges …
```

`NET_BIND_SERVICE` is the capability for ports below 1024, but Docker sets `net.ipv4.ip_unprivileged_port_start=0`
inside containers, so any process may bind port 80 there:

```bash
docker run --rm alpine:3 cat /proc/sys/net/ipv4/ip_unprivileged_port_start     # 0
```

Only `CHOWN` remains. If the files to `chown` belong to another user and sit in directories the root process can't
search, add `DAC_OVERRIDE` too, as Valkey needed.

</details>

2. **Find the capability by experiment.** Start `nginx:alpine` with `--cap-drop ALL` and read its log. Add capabilities
   one at a time until it serves a page. Which ones does it need, and why?

<details>
<summary>Solution</summary>

```bash
docker run --rm --cap-drop ALL nginx:alpine
```

The log names the first missing capability. The master process starts as root and `chown`s its cache directories,
then starts worker processes that switch to the `nginx` user. Each step fails in turn:

```text
[emerg] 1#1: chown("/var/cache/nginx/client_temp", 101) failed (1: Operation not permitted)    # add CHOWN
[emerg] 23#23: setgid(101) failed (1: Operation not permitted)                                # add SETGID
[emerg] 23#23: setuid(101) failed (1: Operation not permitted)                                # add SETUID
```

With those three it serves pages, stops with exit code 0 and starts again cleanly:

```bash
docker run --rm -p 127.0.0.1:8080:80 --cap-drop ALL \
  --cap-add CHOWN --cap-add SETGID --cap-add SETUID nginx:alpine
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/     # 200
```

Notice that the failed `setgid` didn't stop the container: the master stayed up while its workers died, so the
container showed `Up` while every request hung. A healthcheck that makes a real request (chapter 11) catches that.

The alternative is `nginxinc/nginx-unprivileged`, which runs as a
non-root user on port 8080 from the start and needs no capabilities at all.

</details>

3. **The socket, read-only.** A colleague argues that `-v /var/run/docker.sock:/var/run/docker.sock:ro` is safe
   because it's read-only. Prove them wrong.

<details>
<summary>Solution</summary>

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock:ro docker:cli \
  docker run --rm -v /:/host alpine:3 head -1 /host/etc/shadow
```

It still prints the host's `/etc/shadow`. `:ro` makes the mount read-only, which only prevents deleting or replacing
the socket *file*. Talking to a Unix socket isn't a write to the file; the full API, including creating privileged
containers, is still available. If a tool needs the Docker API, put a filtering proxy in between that allows only the
specific read-only endpoints it uses.

</details>

4. **Scan and compare.** Scan `node:24`, `node:24-slim`, `node:24-alpine` and `gcr.io/distroless/nodejs24-debian13`
   with Trivy (or Docker Scout), counting critical and high findings. Then explain why "Alpine has fewer
   vulnerabilities" isn't the whole story.

<details>
<summary>Solution</summary>

```bash
for img in node:24 node:24-slim node:24-alpine gcr.io/distroless/nodejs24-debian13; do
  echo "== $img"
  trivy image --quiet --severity HIGH,CRITICAL --format json "$img" \
    | node -e 'const r = JSON.parse(require("fs").readFileSync(0)); const c = {};
        for (const x of r.Results ?? []) for (const v of x.Vulnerabilities ?? []) c[v.Severity] = (c[v.Severity] ?? 0) + 1;
        console.log(c)'
done
```

Expect the full `node:24` image to have by far the most findings, `slim` far fewer, and Alpine and distroless very
few. But:

- Alpine's database (secdb) only lists vulnerabilities that Alpine has fixed, so "no findings" partly means "nothing
  tracked", while Debian's tracker also lists unfixed ones.
- Alpine uses musl instead of glibc; some native npm modules have no prebuilt musl binaries, and some behaviors
  (DNS resolution, performance) differ (chapter 7).
- Distroless (and Docker Hardened Images) get you the small package count *and* glibc, at the price of no shell.

Compare the number of packages too (`trivy image --list-all-pkgs` or the SBOM), not just the number of CVEs.

</details>

5. **Harden a stranger's Compose file.** Here's a service from a real-world style Compose file. List everything you'd
   change, and why.

   ```yaml
   services:
     app:
       image: someone/app:latest
       privileged: true
       user: root
       ports:
         - "3000:3000"
         - "9229:9229"
       volumes:
         - /var/run/docker.sock:/var/run/docker.sock
         - ./:/app
       environment:
         DB_PASSWORD: hunter2
   ```

<details>
<summary>Solution</summary>

- `image: someone/app:latest`: an unknown publisher and a moving tag. Use a trusted or your own image, pinned by
  digest (or at least a version), ideally signed and scanned.
- `privileged: true`: remove. Find out which capability or device is really needed, if any.
- `user: root`: remove, and run as the image's non-root user (or a numeric UID).
- `"3000:3000"`: publishes on all interfaces; behind a reverse proxy, publish nothing or bind to `127.0.0.1`.
- `"9229:9229"`: the Node.js inspector on all interfaces is remote code execution for anyone who can connect. Never in
  production; in development, `127.0.0.1` only.
- The Docker socket: root on the host. Remove, or use a filtering proxy if a feature really needs the API.
- `./:/app`: the source directory mounted writable into production, so the container can change its own code. Ship
  the code in the image.
- `DB_PASSWORD: hunter2`: a secret in the Compose file, so in Git and in `docker inspect`. Use a secret file and
  `DB_PASSWORD_FILE` (chapter 13).
- Missing: `cap_drop: [ALL]`, `security_opt: [no-new-privileges:true]`, `read_only: true`, resource limits
  (chapter 17), a healthcheck, and a restart policy.

</details>

---
← Previous: [Registries and distribution](15-registries-and-distribution.md) | Next: [Resources, logging and monitoring](17-resources-logging-and-monitoring.md) →
