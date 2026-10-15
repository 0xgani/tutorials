# Chapter 20 — How Docker works inside

## What you'll learn

- What a container is to the Linux kernel: an ordinary process with namespaces, cgroups, capabilities and a seccomp
  filter
- Building a container by hand with `unshare` and `chroot`, to see which part does what
- How the overlay filesystem stacks image layers, and what a deleted file looks like in a layer
- The OCI image format: index, manifest, config and layers, read straight from `docker save`
- The OCI runtime specification Docker generates for every container
- The chain from the `docker` CLI to your process: the API, `dockerd`, containerd, the shim and runc
- What Docker keeps on disk, and where
- How Docker Desktop fits in on macOS and Windows

## A container is a process

Start a container and look for it *on the host*:

```bash
docker run -d --name inside node:24-alpine node -e "setInterval(() => {}, 1000)"
pid=$(docker inspect -f '{{.State.Pid}}' inside)
ps -o pid,ppid,user,args -p "$(ps -o ppid= -p $pid)",$pid
```

```text
  PID  PPID USER     COMMAND
 4035     1 root     /usr/bin/containerd-shim-runc-v2 -namespace moby -id 93634fc87dc0… -address …
 4060  4035 root     node -e setInterval(()=>{},1000)
```

There it is, PID 4060, an ordinary Linux process in the host's process table. Its parent is a small "shim" process
(more on that below). Inside the container, the same process believes it's PID 1 on a machine called `93634fc87dc0`:

```bash
docker exec inside sh -c 'echo "pid: $(pidof node)"; hostname'
```

```text
pid: 1
93634fc87dc0
```

Nothing in the kernel is called a "container". What makes this process one is a combination of kernel features,
applied when it starts:

| Feature | Controls | Covered in |
|---|---|---|
| **Namespaces** | What the process can *see*: processes, network, mounts, hostname, IPC, users | This chapter, chapter 9 |
| **cgroups** | How much it can *use*: memory, CPU, PIDs, I/O | This chapter, chapter 17 |
| **Capabilities** | Which root privileges it keeps | Chapter 16 |
| **seccomp** | Which system calls it may make | Chapter 16, this chapter |
| **AppArmor / SELinux** | Which files and operations the security policy allows | Chapter 16 |
| **A root filesystem** | Which files it sees as `/`: the image, through an overlay | This chapter |

## Namespaces

A namespace gives a process its own *view* of one global resource. Each process's namespaces are listed in
`/proc/<pid>/ns`, as inode numbers; two processes in the same namespace show the same number:

```bash
ls -l /proc/$pid/ns     # the container's
ls -l /proc/self/ns     # the host shell's
```

```text
container                       host
cgroup -> cgroup:[4026531835]   cgroup -> cgroup:[4026531835]
ipc    -> ipc:[4026532266]      ipc    -> ipc:[4026531839]
mnt    -> mnt:[4026532264]      mnt    -> mnt:[4026531832]
net    -> net:[4026532268]      net    -> net:[4026531833]
pid    -> pid:[4026532267]      pid    -> pid:[4026531836]
time   -> time:[4026532319]     time   -> time:[4026531834]
user   -> user:[4026531837]     user   -> user:[4026531837]
uts    -> uts:[4026532265]      uts    -> uts:[4026531838]
```

| Namespace | Isolates | What it means for a container |
|---|---|---|
| `pid` | Process IDs | Sees only its own processes; the first one is PID 1 (chapter 2) |
| `net` | Interfaces, IPs, routes, ports, firewall rules | Its own `eth0` and `localhost` (chapter 9) |
| `mnt` | Mount points | Its own `/`, with the image's files, volumes and `/proc` |
| `uts` | Hostname | `hostname` is the container ID or `--hostname` |
| `ipc` | Shared memory, semaphores | Can't touch other containers' shared memory |
| `user` | User and group IDs | Shared with the host here (same number), so UID 0 inside is UID 0 outside; with user namespace remapping or rootless Docker it's separate (chapter 16) |
| `cgroup` | The view of the cgroup tree | Private on cgroup v2 hosts; shared here because this test machine uses cgroup v1 |
| `time` | Boot and monotonic clocks | Rarely used; the wall clock is always the host's |

Docker's flags that *share* a namespace instead of creating one map directly onto this table:
`--network host`, `--pid host`, `--ipc host`, `--uts host`, and the `container:<name>` forms that chapter 19 used to
join a debug container to another container's namespaces. With `--network host --pid host`, the `net` and `pid`
numbers are the host's:

```text
n1 (default):             net:[4026532268] pid:[4026532267]
n2 (--network host --pid host): net:[4026531833] pid:[4026531836]   ← same as the host
```

### A container by hand

The `unshare` command creates namespaces, and `chroot` changes the root directory. Together with an image's files,
they make a crude container in one line. First, get Alpine's root filesystem out of an image:

```bash
mkdir -p mini/rootfs
docker export "$(docker create alpine:3)" | tar -x -C mini/rootfs
ls mini/rootfs
```

```text
bin dev etc home lib media mnt opt proc root run sbin srv sys tmp usr var
```

Then start a shell in new mount, UTS, IPC, network and PID namespaces, with that directory as `/` (as root on a
Linux host):

```bash
sudo unshare --mount --uts --ipc --net --pid --fork chroot mini/rootfs /bin/sh -c '
  mount -t proc proc /proc
  hostname mini
  echo "hostname: $(hostname)"; ps; ip link'
```

```text
hostname: mini
PID   USER     TIME  COMMAND
    1 root      0:00 /bin/sh -c mount -t proc proc /proc …
    5 root      0:00 ps
1: lo: <LOOPBACK> mtu 65536 qdisc noop state DOWN qlen 1000
    link/loopback 00:00:00:00:00:00 brd 00:00:00:00:00:00
```

PID 1, its own hostname, and a network namespace with nothing but a loopback interface that's down. Compared with
what Docker does, this "container" is missing:

- **cgroups**: no limits at all.
- **Capability dropping and seccomp**: it's full root, able to load kernel modules or reboot the host.
- **A proper root filesystem**: `chroot` can be escaped by root, while runtimes use `pivot_root` in a mount
  namespace; and changes go straight into `mini/rootfs` instead of a disposable layer.
- **Networking**: no `veth` pair, bridge, IP address or NAT (chapter 9).
- **`/dev`, `/sys`, masked paths**, a user, environment variables, log collection, restart policies, and an API.

That list is roughly what runc, containerd and `dockerd` add. None of it is magic; all of it is necessary.

## cgroups

A **control group** is a set of processes whose resource use the kernel meters and limits. Docker creates one per
container and writes the limits from `-m`, `--cpus` and `--pids-limit` into its files. On a cgroup v2 host with the
systemd driver, a container's cgroup is a directory like:

```text
/sys/fs/cgroup/system.slice/docker-<container-id>.scope/
  memory.max        268435456       ← -m 256m
  memory.current    the memory in use right now
  memory.events     … oom_kill 1    ← counts OOM kills
  cpu.max           50000 100000    ← --cpus 0.5
  cpu.stat          … nr_throttled …
  pids.max          100             ← --pids-limit 100
  cgroup.procs      the PIDs in the group
```

On the cgroup v1 machine these examples were tested on, each controller has its own tree instead, but the idea is
the same:

```bash
docker run -d --name lim -m 128m alpine:3 sleep 60
id=$(docker inspect -f '{{.Id}}' lim)
cat /sys/fs/cgroup/memory/docker/$id/memory.limit_in_bytes
```

```text
134217728
```

Every process the container starts lands in the same cgroup automatically, so `docker exec` sessions count against
the container's limits too. The kernel enforces the limits; Docker only writes the files. That's also why
`docker update` (chapter 17) can change limits live: it rewrites them.

## Capabilities and seccomp, seen from inside

Chapter 16 listed Docker's default capabilities. The kernel shows the effective set of any process as a bit mask:

```bash
docker exec inside grep -E '^Cap(Eff|Bnd)|^NoNewPrivs|^Seccomp:' /proc/1/status
```

```text
CapEff:	00000000a80425fb
CapBnd:	00000000a80425fb
NoNewPrivs:	0
Seccomp:	2
```

Each bit is a capability. Decoding `a80425fb` in Node.js:

```js
const names = ['CHOWN', 'DAC_OVERRIDE', 'DAC_READ_SEARCH', 'FOWNER', 'FSETID', 'KILL', 'SETGID', 'SETUID',
  'SETPCAP', 'LINUX_IMMUTABLE', 'NET_BIND_SERVICE', 'NET_BROADCAST', 'NET_ADMIN', 'NET_RAW', 'IPC_LOCK',
  'IPC_OWNER', 'SYS_MODULE', 'SYS_RAWIO', 'SYS_CHROOT', 'SYS_PTRACE', 'SYS_PACCT', 'SYS_ADMIN', 'SYS_BOOT',
  'SYS_NICE', 'SYS_RESOURCE', 'SYS_TIME', 'SYS_TTY_CONFIG', 'MKNOD', 'LEASE', 'AUDIT_WRITE', 'AUDIT_CONTROL',
  'SETFCAP'];
const mask = 0xa80425fb;
console.log(names.filter((_, bit) => (mask >>> bit) & 1).join(' '));
```

```text
CHOWN DAC_OVERRIDE FOWNER FSETID KILL SETGID SETUID SETPCAP NET_BIND_SERVICE NET_RAW SYS_CHROOT MKNOD AUDIT_WRITE SETFCAP
```

The same 14 as in chapter 16. With `--cap-drop ALL` the mask is `0000000000000000`; with `--privileged` it's
`000001fffeffffff`, every capability the host's root has.

`Seccomp: 2` means a **seccomp filter** is active: a small program in the kernel that checks every system call
the process makes. Docker's default profile is an allowlist; calls that aren't on it fail with an error. Some
entries depend on capabilities, which makes two of the cases below interesting:

```bash
docker run --rm alpine:3 unshare -U true                                        # a new user namespace
docker run --rm --security-opt seccomp=unconfined alpine:3 unshare -U true
docker run --rm alpine:3 unshare -m true                                        # a new mount namespace
docker run --rm --cap-add SYS_ADMIN alpine:3 unshare -m true
```

```text
unshare: unshare(0x10000000): Operation not permitted       ← blocked by seccomp
                                                            ← allowed without seccomp (exit 0)
unshare: unshare(0x20000): Operation not permitted          ← blocked: needs SYS_ADMIN
                                                            ← allowed with SYS_ADMIN (exit 0)
```

Creating a user namespace needs no privilege in the kernel, so the first block is purely seccomp's doing: user
namespaces have been a frequent source of kernel exploits, and a container has no business creating them. The
mount namespace needs `SYS_ADMIN`; when you add that capability, Docker's profile also allows the system calls that
go with it. Both layers, capabilities and seccomp, have to agree.

## The overlay filesystem

An image is a stack of read-only layers; a container adds one writable layer on top (chapter 3). The kernel's
**overlay filesystem** merges them into one directory tree. You can see the mount on the host:

```bash
mount | grep "$(docker inspect -f '{{.Id}}' inside)"
```

```text
overlay on /var/lib/docker/rootfs/overlayfs/93634fc87dc0… type overlay (rw,relatime,
  lowerdir=…/snapshots/2177/fs:…/snapshots/125/fs:…,
  upperdir=…/snapshots/2178/fs,
  workdir=…/snapshots/2178/work)
```

```text
the container's /  =  merged view of, from top to bottom:
  upperdir     writable, belongs to this container: every change lands here
  lowerdir 1   Docker's per-container layer: /etc/hosts, /etc/hostname, /etc/resolv.conf, /dev, /.dockerenv
  lowerdir 2-7 the image's 6 layers, read-only and shared with every container of this image
```

Seven lower directories: the image's six layers plus one that Docker adds with placeholder files it bind-mounts over
at start. Reading a file finds it in the topmost layer that has it. Writing a file from a lower layer first copies it
up into `upperdir` ("copy-up", which is why the first write to a large file is slow). And deleting a file from a
lower layer can't touch the read-only layer, so it creates a **whiteout**:

```bash
docker exec inside sh -c 'echo hi > /tmp/new.txt; rm /etc/motd; rm /usr/local/bin/yarn'
docker diff inside
ls -l <upperdir>/etc/motd <upperdir>/usr/local/bin/yarn
```

```text
C /etc
D /etc/motd
C /usr
C /usr/local
C /usr/local/bin
D /usr/local/bin/yarn
C /tmp
A /tmp/new.txt

c--------- 3 root root 0, 0 Oct 15 13:24 …/snapshots/2178/fs/etc/motd
c--------- 3 root root 0, 0 Oct 15 13:24 …/snapshots/2178/fs/usr/local/bin/yarn
```

A whiteout is a character device with device number 0/0: "this file is gone". `docker diff` is just a listing of
`upperdir` (`A` added, `C` changed, `D` deleted). When `docker commit` or a build turns changes into an image layer,
whiteouts become files named `.wh.<name>` inside the layer's tar archive, which is the format the OCI image
specification defines.

That explains chapter 7's rule that deleting files in a later `RUN` doesn't make an image smaller:

```dockerfile
FROM alpine:3
RUN head -c 20000000 /dev/urandom > /big.bin
RUN rm /big.bin
```

```text
$ docker history whiteout --format '{{.Size}}\t{{.CreatedBy}}'
4.1kB	RUN /bin/sh -c rm /big.bin # buildkit
20MB	RUN /bin/sh -c head -c 20000000 /dev/urandom…
```

The second layer still carries all 20 MB; the third adds a 77-byte archive containing only `.wh.big.bin`.

## The image format

Since 2017, container images follow the **OCI Image Specification** (Open Container Initiative), which grew out of
Docker's own format. `docker save` writes an image in the standard OCI layout, so you can read every part of it:

```bash
docker save --platform linux/amd64 snip:1.10 -o snip.tar
tar -xf snip.tar
cat oci-layout index.json
```

```text
{"imageLayoutVersion":"1.0.0"}
{
 "schemaVersion": 2,
 "mediaType": "application/vnd.oci.image.index.v1+json",
 "manifests": [
  {
   "mediaType": "application/vnd.oci.image.manifest.v1+json",
   "digest": "sha256:dd1f3a72388978a9de9f995b7e50bf23ba8cca1f442adc59613edb77d6a639d0",
   "size": 5411,
   "annotations": { "io.containerd.image.name": "docker.io/library/snip:1.10", … },
   "platform": { "architecture": "amd64", "os": "linux" }
  }
 ]
}
```

Everything else is in `blobs/sha256/`, each file named after the SHA-256 digest of its own content. Following the
references:

```text
index.json (an image index: one manifest per platform; chapter 14's multi-platform images have several)
  └─ manifest  sha256:dd1f3a72…   application/vnd.oci.image.manifest.v1+json
       ├─ config  sha256:256882fa…  application/vnd.oci.image.config.v1+json   7084 bytes
       └─ layers  27 × application/vnd.oci.image.layer.v1.tar+gzip
            sha256:2cc7ee28…  124525 bytes   (the first distroless layer)
            …
            sha256:651168dd…  293 bytes      (COPY migrations: app/migrations/001-create-links.sql)
```

The **config** holds everything that isn't files: the defaults a container starts with, and the history.

```text
Env         ["PATH=/usr/local/sbin:…","SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt","NODE_ENV=production","APP_VERSION=dev"]
Entrypoint  ["/nodejs/bin/node"]   Cmd ["src/server.js"]   User 65532   WorkingDir /app
rootfs.diff_ids  27   (the digests of the *uncompressed* layers)
history          35   (one entry per Dockerfile instruction; EXPOSE, CMD, … have "empty_layer": true)
```

Each **layer** is an ordinary gzipped tar archive of the files that step added or changed:

```bash
sha256sum blobs/sha256/651168dd55bb…      # equals its own file name
tar -tzf blobs/sha256/651168dd55bb…
```

```text
651168dd55bb71c62f26…
app/
app/migrations/
app/migrations/001-create-links.sql
```

This content addressing is what everything in chapters 3, 6, 14 and 15 rests on:

- A **digest** (`snip@sha256:dd1f…`) names exactly one manifest, which names exactly one config and set of layers.
  Nothing can change without changing the digest; that's why pinning by digest is safe, and what signatures sign.
- Layers are **shared** between images and only downloaded once: a registry and a local store keep each blob once.
- A **tag** is just a mutable pointer to a manifest or index digest.
- Attestations (SBOMs, provenance; chapter 15) are extra manifests in the same index, pointing at the image's
  digest.

Registries speak the **OCI Distribution Specification**: an HTTP API to fetch and push manifests and blobs by digest.
`docker pull` is a few HTTP requests plus unpacking each layer into a snapshot directory.

## The runtime: from `docker run` to your process

```text
docker CLI ──HTTP over /var/run/docker.sock──▶ dockerd
                                                 │  images, networks, volumes, builds (BuildKit), Compose's API calls
                                                 ▼  gRPC
                                             containerd
                                                 │  image store, snapshots (overlay layers), container lifecycle
                                                 ▼  starts one per container
                                       containerd-shim-runc-v2
                                                 │  stays as the container's parent; holds its stdio and exit code
                                                 ▼  executes, then exits
                                               runc
                                                 │  creates namespaces, cgroups, mounts, drops capabilities,
                                                 ▼  installs seccomp, then exec()s the process
                                         node src/server.js   (PID 1 in the container)
```

- **The API.** The CLI is only a client. Everything it does is an HTTP request to the daemon's REST API, usually
  over a Unix socket. You can make those requests yourself (exercise 5):

  ```bash
  curl -s --unix-socket /var/run/docker.sock http://localhost/version
  ```

  ```text
  {"Platform":{"Name":"Docker Engine - Community"},"Version":"29.8.2","ApiVersion":"1.56","MinAPIVersion":"1.40",…}
  ```

  That's how Docker contexts work over SSH (chapter 18), how Compose and Testcontainers drive Docker, and why access
  to this socket is access to the whole host (chapter 16).

- **dockerd** owns the higher-level concepts: networks and their firewall rules, volumes, the build service
  (BuildKit), logging drivers, restart policies, healthchecks, and the API.

- **containerd** manages images and containers at a lower level and is used directly by Kubernetes too. Since Docker
  Engine 29, Docker's own image store on fresh installs is containerd's (`docker info` shows `driver-type
  io.containerd.snapshotter.v1`), which is what makes features like multi-platform images and attestations in the
  local store possible.

- **The shim** is why containers survive a daemon restart with `live-restore` (chapter 18): the container's parent
  process is the shim, not containerd or `dockerd`.

- **runc** is the reference implementation of the **OCI Runtime Specification**. It reads a bundle (a root
  filesystem plus a `config.json`), sets everything up, starts the process and exits. Alternatives can replace it:
  `crun` (faster, in C), gVisor's `runsc` (a user-space kernel for stronger isolation), Kata Containers (each
  container in a lightweight virtual machine).

### The OCI runtime spec Docker generated

Every running container has its bundle on disk. With a separately installed containerd (the usual package setup)
it's under `/run/containerd/io.containerd.runtime.v2.task/moby/<id>/`; when `dockerd` runs its own containerd it's
under `/run/docker/containerd/daemon/io.containerd.runtime.v2.task/moby/<id>/`. Its `config.json` is every
`docker run` flag translated into the runtime's terms:

```text
ociVersion  1.3.0
process.args        ["docker-entrypoint.sh","node","-e","setInterval(()=>{},1000)"]
process.user        {"uid":0,"gid":0,…}
process.capabilities.effective
                    CAP_CHOWN CAP_DAC_OVERRIDE CAP_FSETID CAP_FOWNER CAP_MKNOD CAP_NET_RAW CAP_SETGID CAP_SETUID
                    CAP_SETFCAP CAP_SETPCAP CAP_NET_BIND_SERVICE CAP_SYS_CHROOT CAP_KILL CAP_AUDIT_WRITE
linux.namespaces    mount network time uts pid ipc
root.path           /var/lib/docker/rootfs/overlayfs/93634fc87dc0…     (the overlay mount above)
mounts              /proc /dev /dev/pts /sys /sys/fs/cgroup /dev/mqueue /dev/shm /etc/hostname /etc/hosts /etc/resolv.conf
linux.seccomp       defaultAction SCMP_ACT_ERRNO, plus the allowed system calls
linux.maskedPaths   /proc/acpi /proc/asound /proc/interrupts /proc/kcore …
linux.resources     devices, memory, cpu, pids …
```

`process.args` shows the image's `ENTRYPOINT` (`docker-entrypoint.sh` in the Node.js images) followed by the command.
The masked paths are host information that `/proc` and `/sys` would otherwise leak into the container.
`/etc/hosts`, `/etc/hostname` and `/etc/resolv.conf` are bind mounts that Docker writes and manages, which is why
editing them inside a container doesn't survive a restart.

## What's on disk

| Path | Holds |
|---|---|
| `/var/lib/docker/containers/<id>/` | Each container's configuration and its `json-file` logs (chapter 17) |
| `/var/lib/docker/volumes/<name>/_data` | Named volumes' data (chapter 8) |
| `/var/lib/docker/network/` | Network configuration |
| `/var/lib/docker/buildkit/` | Build cache metadata |
| `/var/lib/containerd/` (or `/var/lib/docker/containerd/daemon/`) | Image blobs (`io.containerd.content.v1.content`) and unpacked layers (`io.containerd.snapshotter.v1.overlayfs`) |
| `/var/lib/docker/image/`, `/var/lib/docker/overlay2/` | The older image store and layers, on installations that haven't switched to containerd's |
| `/var/run/docker.sock` | The API socket, owned by `root:docker` with mode 660 |
| `/etc/docker/daemon.json` | The daemon's configuration |

On the test machine, unpacked snapshots took 4.5 GB and compressed blobs 1.3 GB: an image is stored both ways,
once to share and push, once to run. `docker system df` (chapter 19) is the supported way to see and reclaim it; don't
delete anything under `/var/lib/docker` by hand, because the daemon's databases would no longer match the files.

## Docker Desktop

Namespaces and cgroups are Linux kernel features. On macOS and Windows, Docker Desktop runs a small Linux virtual
machine, and `dockerd` with all of the above runs inside it. The `docker` CLI on your Mac talks to it through a
socket that Docker Desktop forwards. Consequences you meet in practice:

- **Bind mounts cross a VM boundary**, through a file-sharing layer. That's why they're slower on macOS than on Linux,
  and why file-change events sometimes need help (chapter 12).
- **Published ports** are forwarded from the VM to your Mac's `localhost` by Docker Desktop.
- **Resources** are the VM's: the memory and CPUs set in Docker Desktop's settings are the ceiling for all containers.
- **`/var/lib/docker` and `nsenter` are inside the VM**, not on your Mac, which is why chapter 19's host-level
  techniques are for Linux servers.
- **Architecture**: on Apple Silicon the VM is arm64; amd64 images run through emulation (chapter 14).

Windows can also run *Windows containers*, which use the Windows kernel's own isolation and Windows base images. They
are a separate world; everything in this tutorial is about Linux containers.

## Key takeaways

- A container is an ordinary process, configured at start by runc with namespaces (what it sees), cgroups (what it
  uses), capabilities and seccomp (what it may do), and an overlay root filesystem.
- `/proc/<pid>/ns`, `/proc/<pid>/status` and the cgroup files show all of it from the host; `--network host` and
  `container:<name>` work by sharing namespaces instead of creating them.
- The overlay filesystem stacks read-only image layers under one writable layer. Changes copy up; deletions are
  whiteouts, so deleting in a later layer never shrinks an image.
- An image is a content-addressed graph: index → manifest → config + layers, each named by its SHA-256 digest. Tags
  point at digests; digests can't lie.
- `docker` → REST API → `dockerd` → containerd → shim → runc → your process. Each piece is replaceable, and
  containerd is also what Kubernetes uses (chapter 21).
- Docker Desktop runs all of this in a Linux VM, which explains its bind mount performance and resource limits.

## Exercises

1. **Shared or not?** Start three containers: one plain, one with `--network host`, and one with
   `--pid container:<first>`. For each, compare the `net` and `pid` namespace numbers with the first container's and
   the host's, and predict what `ps` and `ip addr` would show inside each.

<details>
<summary>Solution</summary>

```bash
docker run -d --name a alpine:3 sleep 300
docker run -d --name b --network host alpine:3 sleep 300
docker run -d --name c --pid container:a alpine:3 sleep 300
for x in a b c; do
  p=$(docker inspect -f '{{.State.Pid}}' $x)
  echo "$x: $(sudo readlink /proc/$p/ns/net) $(sudo readlink /proc/$p/ns/pid)"
done
echo "host: $(readlink /proc/self/ns/net) $(readlink /proc/self/ns/pid)"
docker rm -f a b c
```

`a` has its own `net` and `pid`. `b` has the host's `net` (so `ip addr` lists the host's interfaces) and its own
`pid`. `c` has its own `net` but `a`'s `pid` number, so `ps` in `c` shows `a`'s `sleep` as PID 1 next to its own.
Namespaces are independent of each other; Docker's flags pick which to share, one by one.

</details>

2. **What's missing?** Run the hand-made container from this chapter, then show three things it can do that a
   default Docker container can't, and name the Docker feature that prevents each.

<details>
<summary>Solution</summary>

Inside `sudo unshare --mount --uts --ipc --net --pid --fork chroot mini/rootfs /bin/sh`, after
`mount -t proc proc /proc`:

```text
/ # grep CapEff /proc/1/status
CapEff:	000001fffeffffff                  ← every capability, the same as --privileged
/ # mount -t tmpfs none /mnt && echo mount-ok
mount-ok
/ # hostname changed-host && echo hostname-ok
hostname-ok
```

The same commands in `docker run --rm alpine:3 sh`:

```text
mount: permission denied (are you root?)
hostname: sethostname: Operation not permitted
```

- **Capabilities**: the hand-made shell keeps all of root's capabilities, so it can mount filesystems (and, with
  device nodes from `mknod`, the host's disks). Docker drops all but 14; `mount` and `sethostname` need
  `SYS_ADMIN`, which isn't among them.
- **seccomp**: nothing filters the hand-made shell's system calls. Docker's profile would block, for example,
  creating user namespaces and loading kernel modules even if a capability slipped through.
- **cgroups**: an endless memory allocation is limited by nothing but the host's memory. Docker puts every container
  in a cgroup with the limits you set.

And `echo hi > /file` writes straight into `mini/rootfs` on the host: no overlay, so nothing is disposable. `chroot`
itself is also escapable by root, which is why runtimes use `pivot_root` inside a mount namespace.

</details>

3. **Read an image by hand.** Save `node:24-alpine` with `docker save`, and answer from the files alone: how many
   layers it has, which layer is the largest, what its `Entrypoint` and `Cmd` are, and which file the first layer
   contains that tells you the Alpine version.

<details>
<summary>Solution</summary>

```bash
mkdir img && cd img
docker save --platform linux/amd64 node:24-alpine -o node.tar && tar -xf node.tar
node -e '
  const fs = require("fs");
  const blob = (d) => JSON.parse(fs.readFileSync("blobs/sha256/" + d.split(":")[1]));
  const manifest = blob(JSON.parse(fs.readFileSync("index.json")).manifests[0].digest);
  const config = blob(manifest.config.digest);
  console.log("layers:", manifest.layers.length);
  console.log("largest:", manifest.layers.reduce((a, b) => (a.size > b.size ? a : b)));
  console.log(config.config.Entrypoint, config.config.Cmd);
  console.log(manifest.layers[0].digest);'
tar -xzOf blobs/sha256/<first-layer-digest-hex> etc/alpine-release
```

If `index.json` points at another index rather than a manifest (some images saved with all their platforms do),
follow one more level: its `manifests` list one manifest per platform. The largest layer is the one with Node.js
itself. The entrypoint is `docker-entrypoint.sh` and the command `node`. `etc/alpine-release` in the first layer
holds the Alpine version.

</details>

4. **Whiteouts.** Build the three-line `whiteout` image from this chapter, save it, and find the whiteout file in
   its last layer. Then rewrite the Dockerfile so the image doesn't carry the 20 MB, and confirm with `docker
   history`.

<details>
<summary>Solution</summary>

```bash
docker build -t whiteout .
docker save --platform linux/amd64 whiteout -o w.tar && mkdir x && tar -xf w.tar -C x
for b in x/blobs/sha256/*; do
  tar -tzf "$b" >/dev/null 2>&1 && echo "$(stat -c %s "$b") $(tar -tzf "$b" | head -3 | tr '\n' ' ')"
done | sort -n
```

```text
77 .wh.big.bin
3849738 bin/ bin/arch bin/ash
20006284 big.bin
```

The fix is to create and delete the file in the *same* `RUN`, so no layer ever contains it:

```dockerfile
FROM alpine:3
RUN head -c 20000000 /dev/urandom > /big.bin && \
    rm /big.bin
```

Or, for files that are only needed during the build, a multi-stage build or a `RUN --mount=type=cache` / `tmpfs`
mount (chapters 6 and 7), which never become part of a layer at all.

</details>

5. **Docker without the CLI.** Using only `curl` and the API socket, create a container from `alpine:3` that runs
   `echo hello from the API`, start it, wait for it, read its logs, and delete it. Explain the bytes before the log
   text.

<details>
<summary>Solution</summary>

```bash
S=/var/run/docker.sock
V=$(docker version --format '{{.Server.APIVersion}}')
curl -s --unix-socket $S -H 'content-type: application/json' \
  -d '{"Image":"alpine:3","Cmd":["echo","hello from the API"]}' \
  "http://localhost/v$V/containers/create?name=apitest"
curl -s --unix-socket $S -X POST "http://localhost/v$V/containers/apitest/start"
curl -s --unix-socket $S -X POST "http://localhost/v$V/containers/apitest/wait"
curl -s --unix-socket $S "http://localhost/v$V/containers/apitest/logs?stdout=1" | od -c | head -3
curl -s --unix-socket $S -X DELETE "http://localhost/v$V/containers/apitest"
```

```text
{"Id":"d2e1d272d630…","Warnings":[]}
{"StatusCode":0}
0000000 001  \0  \0  \0  \0  \0  \0 023   h   e   l   l   o       f   r
0000020   o   m       t   h   e       A   P   I  \n
```

`docker run` is exactly these calls (plus a pull if needed, and an attach to stream output). The logs come as a
**multiplexed stream**: each chunk starts with an 8-byte header, where the first byte is the stream (1 = stdout,
2 = stderr) and the last four are the length (`023` octal = 19 bytes). That's how one connection carries stdout and
stderr separately. Containers started with a TTY (`-t`) skip the header, because a terminal merges both streams.

</details>

---
← Previous: [Troubleshooting and debugging](19-troubleshooting-and-debugging.md) | Next: [Beyond a single host](21-beyond-a-single-host.md) →
