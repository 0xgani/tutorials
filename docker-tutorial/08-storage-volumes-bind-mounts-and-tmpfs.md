# Chapter 8 — Storage: volumes, bind mounts and tmpfs

## What you'll learn

- Why data written inside a container disappears, and where it should go instead
- Named volumes: creating, using, inspecting, sharing and removing them
- How a new volume is initialized from the image, and how that solves permission problems
- Anonymous volumes and how they pile up
- Bind mounts: mounting host directories, read-only mounts, and how a mount hides image content
- `--mount` vs. `-v`, and why `--mount` is the safer syntax
- File ownership and UIDs: the cause of most "permission denied" errors with mounts
- tmpfs mounts and read-only root filesystems
- Image mounts (Engine 28+)
- Backing up and restoring volumes, and why databases need their own tools
- Snip with a file-based store on a volume

## The writable layer is not storage

Chapter 3 showed that a container gets a thin writable layer on top of the image. Everything a process writes goes
there, unless you've mounted something at that path. The writable layer is the wrong place for data:

- **It's deleted with the container.** Deploying a new version means replacing the container. Anything in the
  writable layer is gone.
- **It's slow for heavy writes.** Copy-on-write through OverlayFS adds overhead, especially the first write to a large
  file from a lower layer.
- **It's hard to get at.** It lives somewhere under `/var/lib/docker` (or inside Docker Desktop's VM), mixed with
  image data, and you can't easily back it up or move it.

Docker offers three kinds of mounts instead:

```text
                         container filesystem
   ┌────────────────────────────────────────────────────────────┐
   │ /app (image layers + writable layer)                       │
   │                                                            │
   │ /var/lib/postgresql ◀── volume:      Docker-managed area   │  /var/lib/docker/volumes/pgdata/_data
   │ /app/src            ◀── bind mount:  any host directory    │  /home/you/snip/src
   │ /tmp                ◀── tmpfs:       host memory           │  RAM, gone when the container stops
   └────────────────────────────────────────────────────────────┘
```

| | Volume | Bind mount | tmpfs |
|---|---|---|---|
| Lives in | Docker's storage area (`/var/lib/docker/volumes`) | Any path on the host | Memory |
| Managed by | Docker (`docker volume …`) | You | — |
| Survives container removal | Yes | Yes (it's your directory) | No |
| Initialized from image content | Yes, if empty | No, it hides the image content | No |
| Works the same on every host | Yes | Depends on host paths and ownership | Yes |
| Typical use | Database files, uploads, anything the app owns | Source code in development, config files | Secrets, scratch space, read-only containers |

## Named volumes

A **named volume** is a directory that Docker creates and manages, with a name you choose:

```bash
docker volume create pgdata
docker volume ls
docker volume inspect pgdata
```

```text
pgdata
DRIVER    VOLUME NAME
local     pgdata
[
    {
        "CreatedAt": "2026-10-15T09:18:54Z",
        "Driver": "local",
        "Labels": null,
        "Mountpoint": "/var/lib/docker/volumes/pgdata/_data",
        "Name": "pgdata",
        "Options": null,
        "Scope": "local"
    }
]
```

`Mountpoint` is where the data actually lives on the Docker host (on Docker Desktop, inside its VM). You never need to
touch that path directly; you mount the volume into containers. Mounting a volume that doesn't exist yet creates it,
so `docker volume create` is optional.

### A database that survives

The classic example is a database. PostgreSQL keeps its data under `/var/lib/postgresql` in the official image:

```bash
docker run -d --name db -e POSTGRES_PASSWORD=devpass -v pgdata:/var/lib/postgresql postgres:18-alpine
docker exec db psql -U postgres -c "CREATE TABLE notes(id int primary key, body text); INSERT INTO notes VALUES (1, 'survives');"
docker rm -f db                     # the container is gone…

docker run -d --name db2 -e POSTGRES_PASSWORD=devpass -v pgdata:/var/lib/postgresql postgres:18-alpine
docker exec db2 psql -U postgres -c 'SELECT * FROM notes'
```

```text
 id |   body
----+----------
  1 | survives
(1 row)
```

The second container is a completely new container, but it mounts the same volume, so it finds the same database.
That's the model for every stateful service: **containers are disposable, volumes are not.** Upgrading PostgreSQL's
minor version means replacing the container with one from a newer image and mounting the same volume.

> **PostgreSQL 18 changed the path.** Images for PostgreSQL 17 and earlier stored data in `/var/lib/postgresql/data`,
> and countless tutorials mount a volume there. From 18 on, the image keeps data in a version-specific subdirectory
> (`PGDATA=/var/lib/postgresql/18/docker`) and declares the volume at `/var/lib/postgresql`, which makes major-version
> upgrades with `pg_upgrade` possible. Mount `/var/lib/postgresql`. If you mount the old path, an 18 image refuses to
> start with an explanation (`Error: in 18+, these Docker images are configured to store database data in a format
> which is compatible with "pg_ctlcluster"…`). For any image, check its documentation or
> `docker image inspect -f '{{json .Config.Volumes}}' <image>` for the right path.

### Sharing a volume

Several containers can mount the same volume at the same time, for example a web server and a background worker that
both read uploaded files. Docker doesn't coordinate access: if two processes write the same file, you get whatever the
filesystem gives you. Databases in particular must never share a data directory between two server processes. Mount
with `:ro` (or `readonly` with `--mount`) in containers that only read.

### How a new volume is initialized

When you mount an **empty** named volume at a path where the **image** has files, Docker copies the image's files
(with their ownership and permissions) into the volume first:

```bash
docker run --rm -v nodelibs:/usr/local/lib/node_modules node:24-alpine ls /usr/local/lib/node_modules
docker run --rm -v nodelibs:/data alpine:3 ls /data
docker volume rm nodelibs
```

```text
corepack
npm
corepack
npm
```

The volume was empty, so Docker seeded it with npm and Corepack from the Node image; the second container (from a
different image) sees that content. This copy happens only once, when the volume is empty. If the volume already has
content, it wins, and the image's files at that path are hidden. That surprises people who change files in a new
image version and wonder why containers still see the old ones: they're reading the volume.

Two practical consequences:

- **Ownership comes along.** If the image has `/app/data` owned by the app's user, a fresh volume mounted there gets
  that owner. This is the clean fix for permission problems with non-root containers (below).
- **Bind mounts and tmpfs are never initialized** from the image; they always hide what was there.

You can switch the copying off with `--mount …,volume-nocopy` (or `-v name:/path:nocopy`).

## Anonymous volumes

A volume without a name is an **anonymous volume**; Docker gives it a random 64-character ID. You get one with
`-v /path` (only a container path), and implicitly whenever an image declares `VOLUME` and you don't mount anything
there (the `postgres` and `registry` images do).

```bash
docker run --rm -v /scratchdata alpine:3 true          # removed together with the container (--rm)
docker run -v /keptdata --name anon alpine:3 true      # stays after the container is removed
docker rm anon
docker volume ls
```

```text
DRIVER    VOLUME NAME
local     0f79b08a6911919f88ba47902c62486b6127211ac070d628437c31dfeb07e2f3
local     pgdata
```

`--rm` removes a container's anonymous volumes; a plain `docker rm` doesn't (add `-v` to do so). That's how a machine
accumulates hundreds of anonymous volumes from `docker run postgres` experiments, each holding a full database. Find
and remove them:

```bash
docker volume ls -f dangling=true     # volumes not used by any container
docker volume prune                   # removes unused *anonymous* volumes
docker volume prune -a                # removes all unused volumes, named ones too. Careful.
```

Since Engine 23, `docker volume prune` spares named volumes unless you add `-a`, precisely because named volumes
usually hold data you care about.

## Bind mounts

A **bind mount** makes an existing host directory (or file) appear inside the container. The container sees the
host's files live, and changes go both ways:

```bash
mkdir -p bm && echo 'hello from the host' > bm/note.txt
docker run --rm -v "$(pwd)/bm":/data alpine:3 cat /data/note.txt
```

```text
hello from the host
```

Bind mounts are ideal for **development**: mount your source code into a container, and the container runs exactly
what's in your editor (chapter 12). They're also used to provide **configuration files** (`-v ./nginx.conf:/etc/nginx/
nginx.conf:ro`) and to give tools access to the host (`-v /var/run/docker.sock:…`, chapter 16 explains why that is
dangerous).

They're a poor fit for application data in production: they depend on paths and ownership on one particular host, and
nothing manages their lifecycle.

### Read-only mounts

Add `:ro` or `readonly` whenever the container only needs to read:

```bash
docker run --rm --mount type=bind,source="$(pwd)/bm",target=/data,readonly alpine:3 sh -c 'echo x > /data/new.txt'
```

```text
sh: can't create /data/new.txt: Read-only file system
```

### A mount hides what was there

Mounting anything at a path hides the image's content at that path for as long as the mount exists. That's how
development setups replace the code in an image with your working copy, and it's also a classic trap:

```bash
docker run --rm -v "$(pwd)/bm":/usr/local/lib/node_modules node:24-alpine sh -c 'ls /usr/local/lib/node_modules; npm --version'
```

```text
note.txt
sh: npm: not found
```

npm lives in `/usr/local/lib/node_modules`, which is now your `bm` directory. The Node.js version of this trap,
mounting your project over `/app` and thereby hiding the `node_modules` the image installed, is common enough that
chapter 12 dedicates a section to it.

## `--mount` vs. `-v`

There are two syntaxes for all of this. `-v` (`--volume`) is short; `--mount` is explicit:

```bash
# Named volume
-v pgdata:/var/lib/postgresql
--mount type=volume,source=pgdata,target=/var/lib/postgresql

# Bind mount, read-only
-v "$(pwd)/config":/app/config:ro
--mount type=bind,source="$(pwd)/config",target=/app/config,readonly

# tmpfs
--tmpfs /tmp
--mount type=tmpfs,target=/tmp,tmpfs-size=64m
```

How does `-v` know whether `pgdata` is a volume or a directory? By whether it starts with `/` or `.`: a path is a bind
mount, a plain name is a volume. A typo like `-v data:/app/data` when you meant `-v ./data:/app/data` silently creates
a volume called `data` instead of mounting your directory.

The more important difference: **`-v` creates a missing host directory, `--mount` refuses**:

```bash
docker run --rm -v "$(pwd)/does-not-exist":/data alpine:3 ls -ld /data
docker run --rm --mount type=bind,source="$(pwd)/also-missing",target=/data alpine:3 true
```

```text
drwxr-xr-x    2 root     root          4096 Oct 15 09:19 /data
docker: Error response from daemon: invalid mount config for type "bind": bind source path does not exist: /home/you/also-missing
```

With `-v`, a misspelled path gives you an empty, root-owned directory on the host and a container that starts with no
data, which is much harder to debug than an error. Prefer `--mount` in scripts and anything long-lived; it reads
better too. (Engine 29.3 added `bind-create-src` to `--mount` for when you *do* want the directory created.) In Compose
files (chapter 10) the long syntax plays the role of `--mount`.

## Ownership, UIDs and "permission denied"

Linux checks file permissions by **numeric** user and group IDs. Inside and outside a container, it's the same kernel
and the same numbers; only the names in `/etc/passwd` differ. The `node` user in the Node image is UID 1000; the
distroless `nonroot` user is UID 65532; your user on a Linux laptop is often 1000, on macOS 501.

Nearly every permission problem with mounts comes from those numbers not lining up:

```bash
mkdir -p hostdata                                   # owned by your host user, mode 755
docker run --rm -u node -v "$(pwd)/hostdata":/data node:24-alpine touch /data/a
```

```text
touch: /data/a: Permission denied
```

The container process runs as UID 1000 and the directory belongs to some other UID with no write permission for
others. The same happens with a **fresh named volume** mounted where the image has nothing: Docker creates the volume's
directory owned by root.

```bash
docker run --rm -u node -v freshvol:/data node:24-alpine sh -c 'ls -ld /data; touch /data/x'
```

```text
drwxr-xr-x    2 root     root          4096 Oct 15 09:19 /data
touch: /data/x: Permission denied
```

The fixes, from best to worst:

1. **For volumes: create the directory in the image, owned by the app user.** A fresh volume mounted there copies the
   ownership (the initialization described above):

   ```dockerfile
   RUN mkdir -p /app/data && chown node:node /app/data
   USER node
   ```

   ```text
   drwxr-xr-x    2 node     node          4096 Oct 15 09:19 /app/data
   write-ok
   ```

2. **For bind mounts in development: run the container as your own UID**, so files it creates belong to you on the
   host: `docker run -u "$(id -u):$(id -g)" …`. (The process then has no matching entry in `/etc/passwd`, which a few
   tools complain about; Node doesn't.)
3. **Change the ownership on the host** to the container's UID (`sudo chown -R 1000:1000 hostdata`). Fine on a server
   you control, awkward on a laptop.
4. **Don't** run the container as root just to make the error go away, and don't `chmod 777` your data.

**Docker Desktop is different.** On macOS and Windows, bind mounts cross from your machine into the Linux VM through a
file-sharing layer, which maps ownership: files appear owned by whichever user the container runs as, and files the
container creates appear as yours on the host. Permission errors with bind mounts are therefore mostly a Linux
problem, which is why a setup that works on a colleague's Mac can fail on the Linux CI server.

## tmpfs mounts and read-only containers

A **tmpfs** mount is a filesystem in memory. Nothing is written to disk, and it's gone when the container stops:

```bash
docker run --rm --mount type=tmpfs,target=/cache,tmpfs-size=64m,tmpfs-mode=1777 node:24-alpine df -h /cache
```

```text
tmpfs                    64.0M         0     64.0M   0% /cache
```

Set a size (`tmpfs-size` or `--tmpfs /cache:size=64m`); without one, a tmpfs can grow up to half the host's memory,
and what it holds counts against the container's memory limit (chapter 17). Use tmpfs for scratch files and for
anything sensitive that must never reach a disk.

Its most important use is together with **`--read-only`**, which makes the container's entire root filesystem
read-only. A compromised process can't modify the app, drop a binary in `/usr/local/bin` or tamper with configuration.
Most apps need a writable `/tmp`, so you give them exactly that:

```bash
docker run --rm --read-only node:24-alpine touch /tmp/x
docker run --rm --read-only --tmpfs /tmp node:24-alpine sh -c 'touch /tmp/x && echo ok'
```

```text
touch: /tmp/x: Read-only file system
ok
```

Chapter 16 runs Snip this way. A read-only root filesystem with a few explicit writable mounts also *documents* where
an app writes, which is valuable on its own.

## Image mounts

Engine 28 added a fourth type: mounting the filesystem of another **image**, read-only, into a container. It's handy
for shipping data or tools as images and using them without copying (machine learning models, static assets, a
debugging toolbox):

```bash
docker run --rm --mount type=image,source=snip:1.2,target=/snip node:24-alpine ls /snip/app
docker run --rm --mount type=image,source=snip:1.2,target=/snip,image-subpath=app/src node:24-alpine ls /snip
```

```text
node_modules
package.json
src
app.js
healthcheck.js
memory-store.js
server.js
```

## Volume drivers

> **Just a taste — you don't need to learn or fully understand this now.** The default `local` driver can also mount
> network filesystems, and plugins add cloud storage; useful on servers, rarely needed in development.
>
> ```bash
> docker volume create --driver local \
>   --opt type=nfs --opt o=addr=10.0.0.10,rw,nfsvers=4 --opt device=:/exports/uploads \
>   uploads
> ```

## Backing up and restoring volumes

Since a volume is just a directory, you can back it up with any container that mounts it plus a bind mount for the
archive:

```bash
# Back up (mount the volume read-only)
docker run --rm -v pgdata:/data:ro -v "$(pwd)/backup":/backup alpine:3 \
  tar czf /backup/pgdata.tgz -C /data .

# Restore into a new volume
docker volume create pgdata-restored
docker run --rm -v pgdata-restored:/data -v "$(pwd)/backup":/backup:ro alpine:3 \
  tar xzf /backup/pgdata.tgz -C /data
```

This works for any files: uploads, a file-based store, configuration. **For a database it's only safe while the
database is stopped**: copying the files of a running PostgreSQL produces an inconsistent snapshot that may not start,
or start with corrupted data. For a running database, use its own tools, which produce consistent backups while it
keeps serving:

```bash
docker exec db pg_dump -U postgres --format=custom postgres > postgres.dump
docker exec -i db pg_restore -U postgres -d postgres --clean < postgres.dump
```

(`-i` keeps standard input open so the dump can be piped in.) The PostgreSQL tutorial in this repository covers
`pg_dump`, physical backups and point-in-time recovery in depth; chapter 18 here covers backups for the whole Snip
deployment.

## Snip with a file store

The in-memory store loses every link on restart. Before moving to PostgreSQL in chapter 10, a file store is a good way
to practise volumes, ownership and non-root containers together. `src/file-store.js` keeps links in a JSON file:

```js
import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';

// Keeps links in a JSON file in `dir`, so they survive restarts if `dir` is a volume.
// Good enough for one process; chapter 10 replaces it with PostgreSQL.
export async function createFileStore(dir) {
  await mkdir(dir, { recursive: true });
  const file = path.join(dir, 'links.json');

  let links;
  try {
    links = new Map(Object.entries(JSON.parse(await readFile(file, 'utf8'))));
  } catch (err) {
    if (err.code !== 'ENOENT') throw err;
    links = new Map();
  }

  // Write to a temporary file and rename it, so a crash never leaves half a file behind.
  let pending = Promise.resolve();
  const save = () => {
    pending = pending.then(async () => {
      await writeFile(`${file}.tmp`, JSON.stringify(Object.fromEntries(links)));
      await rename(`${file}.tmp`, file);
    });
    return pending;
  };

  return {
    async create({ code, url }) {
      const link = { code, url, clicks: 0, createdAt: new Date().toISOString() };
      links.set(code, link);
      await save();
      return link;
    },
    async get(code) {
      return links.get(code) ?? null;
    },
    async recordClick(code) {
      const link = links.get(code);
      if (!link) return;
      link.clicks += 1;
      await save();
    },
  };
}
```

In `src/server.js`, choose the store from the environment:

```js
import { createFileStore } from './file-store.js';

// DATA_DIR set: keep links in a file there. Otherwise: in memory, lost on exit.
const store = process.env.DATA_DIR ? await createFileStore(process.env.DATA_DIR) : createMemoryStore();

const app = createApp({ store, baseUrl });
```

(Top-level `await` works because Snip is an ES module.)

First attempt: the chapter 7 distroless image, with a fresh volume at `/data`:

```bash
docker build -t snip:files .
docker run -d --name f1 -e DATA_DIR=/data -v snipdata:/data -p 3000:3000 snip:files
curl -s -X POST localhost:3000/api/links -H 'content-type: application/json' -d '{"url":"https://example.com"}'
docker logs f1
```

```text
Error: EACCES: permission denied, open '/data/links.json.tmp'
  ...
  code: 'EACCES',
  syscall: 'open',
  path: '/data/links.json.tmp'
```

The app runs as UID 65532, the new volume's directory belongs to root. Apply fix 1: give the image a data directory
owned by the runtime user. Distroless has no shell to `mkdir`, so create the directory in a stage that has one and
copy it with the right owner:

```dockerfile
# data: an empty directory owned by the distroless nonroot user, for the volume
FROM base AS data
RUN mkdir -p /out/data

FROM gcr.io/distroless/nodejs${NODE_VERSION}-debian13:nonroot AS runtime
WORKDIR /app
ENV NODE_ENV=production
COPY --from=deps /app/node_modules ./node_modules
COPY package.json ./
COPY src ./src
COPY --from=data --chown=65532:65532 /out/data /app/data
ENV DATA_DIR=/app/data
# ... labels, EXPOSE, HEALTHCHECK and CMD as in chapter 7
```

```bash
docker rm -f f1 && docker volume rm snipdata
docker build -t snip:files .
docker run -d --name f1 -v snipdata:/app/data -p 3000:3000 snip:files
# create a link and click it, then replace the container:
docker rm -f f1
docker run -d --name f2 -v snipdata:/app/data -p 3000:3000 snip:files
curl -s localhost:3000/api/links/jvPHSVs
docker run --rm -v snipdata:/data alpine:3 ls -ln /data
```

```text
{"code":"jvPHSVs","url":"https://example.com/","clicks":1,"createdAt":"2026-10-15T09:21:07.251Z"}
total 4
-rw-r--r--    1 65532    65532          109 Oct 15 09:21 links.json
```

The link and its click count survived a container replacement, the file belongs to UID 65532, and the app never ran
as root. The file store is fine for one process; as soon as you run two Snip containers (or want queries like "top
ten links"), you need a database, which is where chapter 10 picks up.

## Key takeaways

- Never keep data in a container's writable layer: it's deleted with the container. Use volumes for data, bind mounts
  for development and host files, tmpfs for scratch and secrets.
- Named volumes are created and managed by Docker and survive container removal. Containers are disposable; volumes
  are not.
- An empty named volume is initialized from the image's content at the mount point, including ownership. Any mount
  hides the image's content at that path.
- `-v` silently creates missing host directories and guesses volume vs. bind mount from the path; `--mount` is explicit
  and fails on mistakes.
- Permission errors come from numeric UIDs not matching. For volumes, create the directory in the image owned by the
  app user; for development bind mounts on Linux, run as your own UID.
- `--read-only` plus a few tmpfs mounts makes a container's filesystem immutable except where you allow writes.
- `docker volume prune` removes unused anonymous volumes; `-a` also removes named ones. Back up volumes with a tar
  container, but back up running databases with their own tools.
- PostgreSQL 18 images keep data under `/var/lib/postgresql/18/docker`; mount the volume at `/var/lib/postgresql`.

## Exercises

1. **Volume or not?** For each, choose volume, bind mount, tmpfs or "bake into the image": (a) PostgreSQL's data
   directory; (b) your app's source code during development; (c) a TLS private key the app reads at start; (d) an
   nginx configuration file in production; (e) user-uploaded images; (f) a cache of resized thumbnails that can be
   regenerated.

<details>
<summary>Solution</summary>

(a) Named volume. (b) Bind mount. (c) Not in the image and not in a regular volume: a secret, mounted as a file from a
secrets mechanism (chapter 13), which Docker implements as a read-only tmpfs-backed file. (d) Either baked into a
custom nginx image (`COPY nginx.conf …`), which makes the image self-contained and versioned, or a read-only bind mount
on a single server; baking it in is better for reproducibility. (e) Named volume on a single host; object storage
(S3 or similar) once you have several hosts. (f) tmpfs if it's small and losing it on restart is fine, otherwise a
named volume; either way it can be deleted without loss.

</details>

2. **Find the data.** Start `postgres:18-alpine` *without* any `-v` option, create a table, then remove the container
   with `docker rm -f` (not `--rm`). Is the data gone? Find it, and start a new container that uses it.

<details>
<summary>Solution</summary>

The image declares `VOLUME /var/lib/postgresql`, so Docker created an anonymous volume, and `docker rm -f` (without
`-v`) leaves it behind.

```bash
docker run -d --name tmpdb -e POSTGRES_PASSWORD=x postgres:18-alpine
docker inspect -f '{{range .Mounts}}{{.Name}}{{end}}' tmpdb      # note the volume ID
docker exec tmpdb psql -U postgres -c 'CREATE TABLE t(x int)'
docker rm -f tmpdb
docker volume ls -f dangling=true                                 # still there
docker run -d --name again -e POSTGRES_PASSWORD=x -v <volume-id>:/var/lib/postgresql postgres:18-alpine
docker exec again psql -U postgres -c '\dt'                       # table t is back
```

The lesson: unnamed data isn't gone, it's just hard to find, and it accumulates. Name the volumes you care about and
`--rm` or prune the rest.

</details>

3. **The `-v` typo.** Run `docker run --rm -v data:/srv alpine:3 sh -c 'echo hi > /srv/f'` from a directory that
   contains a `data/` subdirectory. Where did `f` go? How would `--mount` have helped?

<details>
<summary>Solution</summary>

`data` doesn't start with `/` or `.`, so `-v` treated it as a **volume name**: Docker created (or reused) a named
volume called `data`, and `f` is in there (`docker run --rm -v data:/x alpine:3 ls /x`), not in your directory. The
bind mount needs a path: `-v ./data:/srv` or `-v "$(pwd)/data":/srv`. With `--mount type=bind,source=data,…`, Docker
rejects the relative path instead of guessing, and the intent (`type=bind`) is explicit. Clean up with
`docker volume rm data`.

</details>

4. **Read-only Snip.** Run the chapter 7 `snip:1.2` image with `--read-only`. Does it work? Now run `snip:files` (this
   chapter) with `--read-only` and its volume. Does creating a link still work, and why?

<details>
<summary>Solution</summary>

```bash
docker run --rm -d --name ro1 --read-only -p 3000:3000 snip:1.2
curl -s -X POST localhost:3000/api/links -H 'content-type: application/json' -d '{"url":"https://example.com"}'
docker stop ro1
docker run --rm -d --name ro2 --read-only -v snipdata:/app/data -p 3000:3000 snip:files
curl -s -X POST localhost:3000/api/links -H 'content-type: application/json' -d '{"url":"https://example.com"}'
docker stop ro2
```

Both work. `snip:1.2` never writes to its filesystem (links are in memory, logs go to stdout). `snip:files` writes only
to `/app/data`, which is a volume mount and stays writable: `--read-only` applies to the root filesystem, not to
mounts. If the app had tried to write anywhere else, it would have failed with `EROFS: read-only file system`, which
is exactly how `--read-only` reveals undocumented writes.

</details>

5. **Back up and restore Snip's data.** With `snip:files` running and some links created, back up the `snipdata`
   volume to `snipdata.tgz`, delete the volume, restore it into a new volume called `snipdata2`, and run Snip on it.
   Is it safe to take this backup while Snip is running? What would make it safe?

<details>
<summary>Solution</summary>

```bash
docker run --rm -v snipdata:/data:ro -v "$(pwd)":/backup alpine:3 tar czf /backup/snipdata.tgz -C /data .
docker rm -f f2 && docker volume rm snipdata
docker run --rm -v snipdata2:/data -v "$(pwd)":/backup:ro alpine:3 tar xzf /backup/snipdata.tgz -C /data
docker run -d --name f3 -v snipdata2:/app/data -p 3000:3000 snip:files
```

The tar archive preserves numeric owners, and because it was created with `-C /data .`, it contains an entry for the
directory itself (`./`, owned by 65532) as well as `links.json`:

```text
drwxr-xr-x 65532/65532       0 2026-10-15 09:23 ./
-rw-r--r-- 65532/65532     109 2026-10-15 09:23 ./links.json
```

Extracting as root restores both, so the new volume's root directory ends up owned by 65532 too and Snip can write to
it. (Archive a directory's *contents* with `-C dir .`, not `dir/*`, or you lose that first entry, and with it the
directory's owner.)

Taking the backup while Snip runs is *mostly* safe, because Snip replaces `links.json` atomically with `rename`: tar
sees either the old or the new file, never half of one. But clicks recorded during the backup may be missing. For a
consistent backup, stop the container first (`docker stop`), back up, start it again, or use an application-level
export. For databases, always use the database's own backup tools.

</details>

---
← Previous: [Multi-stage builds and small, secure images](07-multi-stage-builds-and-small-images.md) | Next: [Networking](09-networking.md) →
