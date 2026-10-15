# Chapter 18 — Running in production

## What you'll learn

- What changes between a laptop and a production server, and what stays exactly the same
- Graceful shutdown from the proxy to the database, measured, and when an init process is needed
- Restart policies, reboots and daemon upgrades
- A single-server layout for Compose: what lives on the server, and what never does
- nginx as a reverse proxy: TLS, HTTP/2, request IDs, JSON access logs, keeping `/metrics` internal, and load
  balancing over several API containers
- Getting certificates from Let's Encrypt
- Deploying from your machine or CI with Docker contexts and SSH
- Zero-downtime updates and rollbacks with a small script, tested under load, and why migrations must be backward
  compatible
- Backups and restores that you've actually tested
- A production checklist

## From laptop to server

Everything in the previous chapters runs unchanged on a server; that's the point of containers. What changes is
around them:

| Concern | On your laptop | On the server |
|---|---|---|
| Image | Built locally (`build: .`) | Pulled from a registry by version (chapter 15) |
| Source code | Bind-mounted and watched (chapter 12) | Not on the server at all |
| Entry point | `localhost:3000` | nginx on ports 80 and 443, with TLS |
| Configuration | `compose.override.yaml`, `.env` | `compose.prod.yaml`, the server's own `.env` and secrets |
| Updates | `docker compose up --watch` | A deploy script: pull, migrate, replace without downtime |
| Failures | You notice | Restart policies, healthchecks, logs, metrics, alerts (chapter 17) |
| Data | Disposable | Backed up, off the server, and restore-tested |

This chapter builds the right-hand column with Compose on a single Linux server. That covers far more applications
than its reputation suggests: a modest server runs Snip's whole stack comfortably, and chapter 21 covers when you
need more than one.

The finished setup is `examples/ch18-production/`.

## Graceful shutdown, end to end

Every deploy, restart and reboot stops containers. Whether users notice depends on each service shutting down
cleanly when it gets `SIGTERM` (chapter 2): stop accepting new work, finish what's in progress, close connections,
exit.

Snip's API has done this since chapter 4:

```js
function shutdown(signal) {
  logger.info({ signal }, 'closing the server');
  server.close(async () => {             // stop accepting; call back when open requests are done
    await store.close?.();               // then close the PostgreSQL pool and Valkey connection
    logger.info('all connections closed, exiting');
    process.exit(0);
  });
  server.closeIdleConnections();         // keep-alive connections with no request in flight
  setTimeout(() => {
    logger.error('could not close connections in time, forcing exit');
    process.exit(1);
  }, 8000).unref();                      // before Docker's SIGKILL at 10 s
}
```

The other services handle it too, each in its own way:

| Service | Stop signal | What it does | Measured `docker compose stop` |
|---|---|---|---|
| nginx | `SIGQUIT` (set by the image) | Graceful: finishes open requests, then exits | 560 ms |
| api | `SIGTERM` | `server.close()`, then closes its pools | 562 ms |
| worker | `SIGTERM` | Finishes the current batch (the blocking pop waits up to 2 s) | 1770 ms |
| valkey | `SIGTERM` (through tini) | Writes a final snapshot (chapter 16's `KILL` capability) | 484 ms |
| db | `SIGTERM` | PostgreSQL's "smart" shutdown: waits for sessions, checkpoints | 442 ms |

All exited with code 0. Two settings tune the process:

- **`stop_grace_period`** (Compose) or `docker stop -t`: how long Docker waits between `SIGTERM` and `SIGKILL`.
  The default 10 s suits Snip. A service with long requests (uploads, reports) or long jobs needs more, and its own
  forced-exit timer must stay *below* the grace period, or Docker kills it in the middle of its cleanup.
- **`stop_signal`**: for programs that expect something other than `SIGTERM`. Images usually set it already
  (`STOPSIGNAL` in the Dockerfile), like nginx's `SIGQUIT`.

The order matters too. `docker compose down` and `stop` stop services in reverse dependency order: nginx before the
API, the API before the databases. So requests in flight still reach a running API, and the API's queries still reach
a running database.

### Do you need an init process?

PID 1 in a container has two jobs that normal processes don't: it receives signals without default handlers
(chapter 2), and it must **reap zombies**, the exited child processes whose parent didn't collect their exit status.
Snip's processes register signal handlers and don't start child processes, so they're fine as PID 1. Add
`init: true` (Compose) or `--init` (`docker run`) when:

- the app starts child processes (shell commands, headless browsers, `child_process` workers), or
- the app can't install signal handlers (some tools, shell scripts as entry points).

`--init` runs Docker's built-in `docker-init` (tini) as PID 1 in front of your process. It's injected from the host,
so it works even with distroless images:

```bash
docker run --rm --init --entrypoint /nodejs/bin/node snip:1.10 \
  -e "console.log(require('fs').readFileSync('/proc/1/cmdline', 'utf8').split('\0').join(' '))"
```

```text
/sbin/docker-init -- /nodejs/bin/node -e console.log(...)
```

## Restarts, reboots and daemon upgrades

Every long-running service has `restart: unless-stopped` (chapter 2): Docker restarts it if it exits, and starts it
again when the server reboots, as long as the Docker service itself starts at boot (`systemctl enable docker` on
systemd distributions; it usually is by default). `unless-stopped` respects a deliberate `docker compose stop`;
`always` would restart even those after a reboot.

Two things restart policies don't cover:

- **Upgrading the Docker daemon** stops every container, unless `"live-restore": true` is set in `daemon.json`. With
  it, containers keep running while the daemon restarts (it doesn't work with Swarm mode). Worth enabling on single
  servers.
- **A container that's running but broken.** Restart policies react to the process *exiting*. A hung process that
  fails its healthcheck stays hung; plain Docker only records `unhealthy` (chapter 17). Make the app exit on errors
  it can't recover from (Snip exits on invalid configuration, for example), and alert on unhealthy containers.

## Compose on a single server

### What lives on the server

```text
/srv/snip/
  compose.yaml             # the same files as in the repository…
  compose.prod.yaml
  nginx/nginx.conf
  deploy.sh  backup.sh
  .env                     # …plus this server's settings (not in git)
  secrets/db_password.txt  # (not in git, mode 644 in a mode 700 directory; chapter 13)
  acme/                    # Let's Encrypt challenge files (below)
  backups/                 # local copies; the real backups are elsewhere
/etc/snip/certs/           # TLS certificate and key for nginx
```

What's *not* there: the source code, `node_modules`, a `Dockerfile`, build tools. The server runs images that CI
built, tested, scanned and pushed (chapters 14 and 15). Getting the deployment files there can be as simple as a `git
clone` of the repository (only the files above are used) or an `rsync` from CI.

The server's `.env` selects the production files and the image:

```bash
COMPOSE_FILE=compose.yaml:compose.prod.yaml
SNIP_IMAGE=registry.example.com/snip:1.10
HTTP_PORT=80
HTTPS_PORT=443
CERTS_DIR=/etc/snip/certs
ACME_DIR=/srv/snip/acme
```

`COMPOSE_FILE` matters more than it looks. Without it, every `docker compose` command on the server would also merge
`compose.override.yaml`, the *development* overrides, if that file is present. It happened while testing this
chapter's deploy script: `docker compose run migrate` merged the override, saw that `db` now had a published port,
and **recreated the database container** in the middle of a deployment. `deploy.sh` now refuses to run without
`COMPOSE_FILE`.

The image comes from `.env` through the shared `x-snip` block:

```yaml
x-snip: &snip
  build: .
  # The image to run. Production sets SNIP_IMAGE in .env to a version in the registry
  # (registry.example.com/snip:1.10); a rollback is changing it back.
  image: ${SNIP_IMAGE:-snip:1.10}
```

On the server, the registry needs credentials once: `docker login registry.example.com` with a **read-only** token
(chapter 15), stored in `~/.docker/config.json` of the user that deploys. That user is in the `docker` group, which is
equivalent to root (chapter 16), so treat its SSH access accordingly.

### First start

```bash
cd /srv/snip
./create-secrets.sh                          # once
docker compose pull                          # the images, before anything stops
docker compose up -d --wait                  # everything, in dependency order
docker compose ps
```

Two firewall facts from chapter 9 apply here. Published ports bypass `ufw`'s rules, so the only ports in the
production files are nginx's 80 and 443; the API, PostgreSQL and Valkey publish nothing (the development override
publishes them on `127.0.0.1` only). And the cloud provider's firewall (security group) should allow just 22, 80 and
443.

## A reverse proxy with TLS

The API never faces the internet directly. **nginx** sits in front and does what a Node.js process shouldn't have to:

- **TLS**: certificates, protocols, HTTP/2, redirects from HTTP to HTTPS, and HSTS.
- **Load balancing** over several API containers, and retrying on another one when a container stops (below).
- **Request IDs** and an access log, independent of the app.
- **Limits**: body size, timeouts, and rate limits (exercise 5).
- **Keeping internal endpoints internal**: `/metrics` isn't reachable from outside.

### The configuration

`nginx/nginx.conf`:

```nginx
worker_processes auto;
pid /run/nginx.pid;
error_log stderr warn;

events {
  worker_connections 1024;
}

http {
  server_tokens off;
  client_max_body_size 16k;

  # One JSON object per line on stdout, like Snip's own logs. reqId matches the
  # X-Request-Id that Snip receives and logs.
  log_format json escape=json '{"time":"$time_iso8601","reqId":"$request_id",'
    '"remote":"$remote_addr","method":"$request_method","uri":"$request_uri",'
    '"status":$status,"bytes":$body_bytes_sent,"ms":$request_time,'
    '"upstream":"$upstream_addr","upstreamStatus":"$upstream_status","ua":"$http_user_agent"}';
  access_log /dev/stdout json;

  # Docker's embedded DNS. "resolve" below re-resolves "api" every 5 s, so nginx
  # notices containers being added and removed (nginx 1.27.3+).
  resolver 127.0.0.11 valid=5s ipv6=off;

  upstream snip_api {
    zone snip_api 64k;
    server api:3000 resolve;
    keepalive 16;
  }

  server {
    listen 80;

    # Let's Encrypt's HTTP-01 challenge (certbot --webroot), and the container's healthcheck.
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location = /nginx-health { access_log off; return 200 "ok\n"; }

    location / { return 301 https://$host$request_uri; }
  }

  server {
    listen 443 ssl;
    http2 on;

    ssl_certificate     /etc/nginx/certs/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:TLS:10m;
    add_header Strict-Transport-Security "max-age=31536000" always;

    # Internal endpoints stay internal: Prometheus scrapes api:3000 on the Docker network.
    location = /metrics { return 404; }

    location / {
      proxy_pass http://snip_api;
      proxy_http_version 1.1;
      proxy_set_header Connection "";          # reuse upstream connections (keepalive)
      proxy_set_header Host $host;
      proxy_set_header X-Request-Id $request_id;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto $scheme;
      proxy_connect_timeout 2s;
      # A container that's stopping refuses new connections: try the next one.
      proxy_next_upstream error timeout http_502 http_503;
    }
  }
}
```

The parts that are specific to running behind Docker:

- **`server api:3000 resolve`** with **`resolver 127.0.0.11`**. `api` is a Compose service name, which Docker's DNS
  (chapter 9) resolves to *every* container of the service. Plain `server api:3000` would resolve it once at startup
  and never notice new containers; `resolve` re-resolves it while running, which is what makes the deploy script
  below work. It needs the `zone` line.
- **`$request_id`** is a random ID nginx generates per request. It's sent to Snip as `X-Request-Id`, which Snip logs
  (chapter 17), so one ID connects the nginx line and the Snip line. A client-supplied `X-Request-Id` is overwritten,
  so clients can't inject their own.
- **`proxy_next_upstream`**: if a connection to one container fails (it's stopping, or starting and not listening
  yet), nginx tries another one. For requests that never reached a container, that includes `POST`s.
- **Logs to stdout and stderr**, like every container.

### The service

```yaml
  nginx:
    image: nginx:1.31-alpine
    restart: unless-stopped
    logging: *logging
    ports:
      - "${HTTP_PORT:-80}:80"
      - "${HTTPS_PORT:-443}:443"
    volumes:
      - ./nginx/nginx.conf:/etc/nginx/nginx.conf:ro
      - ${CERTS_DIR:-./certs}:/etc/nginx/certs:ro
      - ${ACME_DIR:-./acme}:/var/www/certbot:ro   # Let's Encrypt HTTP-01 challenge files
    networks: [edge]            # can reach the API containers, not the databases
    # The master process starts as root and switches its workers to the nginx user.
    cap_drop: [ALL]
    cap_add: [CHOWN, SETGID, SETUID]
    security_opt: [no-new-privileges:true]
    read_only: true
    tmpfs:
      - /var/cache/nginx
      - /run
      - /tmp
    deploy:
      resources:
        limits:
          cpus: "1"
          memory: 128M
    healthcheck:
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://127.0.0.1/nginx-health"]
      interval: 10s
      timeout: 3s
      retries: 3
      start_period: 10s
      start_interval: 1s
    depends_on:
      api:
        condition: service_healthy
```

The capabilities are the ones chapter 16's exercise found by experiment. The API joins both networks; nginx only
`edge`:

```yaml
  api:
    <<: *snip
    environment:
      <<: *snip-env
      BASE_URL: ${BASE_URL:-https://localhost:8443}
    networks: [default, edge]
    deploy:
      replicas: 2               # two containers, so one can be replaced while the other serves
      # … resources as in chapter 17 …

networks:
  default:                      # api, worker, migrate, db, valkey
  edge:                         # nginx and api
```

So a compromised nginx can't even resolve the database's name:

```bash
docker compose exec nginx sh -c 'nc -zw2 db 5432 && echo reachable || echo unreachable'
```

```text
nc: bad address 'db'
unreachable
```

### Trying it locally

`create-dev-cert.sh` makes a self-signed certificate for `localhost`, and `.env.example` puts nginx on ports 8080
and 8443:

```bash
./create-dev-cert.sh
docker compose -f compose.yaml up -d --build --wait
curl -si http://localhost:8080/abc | head -1                     # HTTP/1.1 301 Moved Permanently
curl -sk -o /dev/null -w '%{http_code} HTTP/%{http_version}\n' https://localhost:8443/metrics   # 404 HTTP/2
curl -sk -X POST https://localhost:8443/api/links -H 'content-type: application/json' \
  -d '{"url":"https://example.com/p"}'
```

```text
{"code":"_PEobfI","url":"https://example.com/p","clicks":0,"createdAt":"2026-10-15T10:50:10.667Z","shortUrl":"https://localhost:8443/_PEobfI"}
```

nginx's log shows the load balancing, alternating between the two API containers, and the request ID:

```text
{"time":"2026-10-15T10:50:10+00:00","reqId":"7f50b865e8502856fa6be422d09be44a",…,"status":404,…,"upstream":"172.20.0.2:3000",…}
{"time":"2026-10-15T10:50:10+00:00","reqId":"27eb23ba6a2077712fe2fccd3c68fc74",…,"status":404,…,"upstream":"172.20.0.3:3000",…}
```

```bash
docker compose logs api | grep 27eb23ba6a2077712fe2fccd3c68fc74
```

```text
api-2  | {"level":"info",…,"reqId":"27eb23ba6a2077712fe2fccd3c68fc74","method":"GET","url":"/api/links/zzzzzzz",…}
```

(`-k` skips certificate verification, for the self-signed certificate only.)

### Certificates from Let's Encrypt

Let's Encrypt issues free certificates valid for 90 days, after proving you control the domain. With the HTTP-01
challenge, it fetches a file from `http://<domain>/.well-known/acme-challenge/…`, which is why port 80 serves that
path from `ACME_DIR`. Using certbot's own image:

```bash
# The first certificate, before nginx runs (nginx needs a certificate to start): certbot answers the
# challenge itself on port 80.
docker run --rm -p 80:80 -v /etc/letsencrypt:/etc/letsencrypt certbot/certbot \
  certonly --standalone -d snip.example.com -m you@example.com --agree-tos -n

# Copy the files where nginx expects them. "live/" holds symbolic links; cp -L copies their targets.
sudo mkdir -p /etc/snip/certs
sudo cp -L /etc/letsencrypt/live/snip.example.com/{fullchain,privkey}.pem /etc/snip/certs/
```

Renewal, from a daily cron job or systemd timer, uses the webroot while nginx keeps running, then reloads nginx so it
picks up the new files without dropping connections:

```bash
docker run --rm -v /etc/letsencrypt:/etc/letsencrypt -v /srv/snip/acme:/var/www/certbot certbot/certbot \
  renew --webroot -w /var/www/certbot -q
cp -L /etc/letsencrypt/live/snip.example.com/fullchain.pem /etc/letsencrypt/live/snip.example.com/privkey.pem \
  /etc/snip/certs/
docker compose -f /srv/snip/compose.yaml -f /srv/snip/compose.prod.yaml exec nginx nginx -s reload
```

The key file is `root`-owned with mode 600, which nginx's master process can read: it runs as root, and the owner
check passes without `DAC_OVERRIDE`. Monitor the expiry date whatever renews it (exercise 4).

An alternative is a proxy that does all of this itself. **Caddy** obtains and renews certificates automatically:

> **Just a taste — you don't need to learn or fully understand this now.** An alternative to nginx plus certbot.

```text
# Caddyfile
snip.example.com {
	reverse_proxy api:3000
}
```

**Traefik** reads its routing from container labels. Both are good choices; nginx is the most widespread, which is
why it's shown here.

## Deploying from your machine or CI

**Docker contexts** point the `docker` CLI at another daemon, for example over SSH:

```bash
docker context create prod --description "Snip production" --docker "host=ssh://deploy@snip.example.com"
docker context ls
```

```text
NAME        DESCRIPTION                               DOCKER ENDPOINT                 ERROR
default *   Current DOCKER_HOST based configuration   unix:///var/run/docker.sock
prod        Snip production                           ssh://deploy@snip.example.com
```

```bash
docker --context prod ps
docker --context prod logs -f --since 10m snip-api-3
```

That's convenient for looking around. For *deploying* with Compose it has a trap: `docker --context prod compose up`
reads the Compose files on **your** machine, but bind mounts like `./nginx/nginx.conf` and `./secrets` are paths on
the **server**. They have to exist there anyway. So the simplest reliable deployment runs the commands on the server:

```bash
ssh deploy@snip.example.com 'cd /srv/snip && git pull --ff-only && ./deploy.sh registry.example.com/snip:1.11'
```

A CI job does the same as its last step, after building, testing and pushing the image (chapter 14), with an SSH key
stored as a CI secret and the server's host key pinned in `known_hosts`.

## Zero-downtime updates

### Why `docker compose up -d` isn't enough

Changing the image and running `docker compose up -d` **recreates** every container whose configuration changed:
it stops the old one, then starts the new one. Here's what that does to users, measured with a small load generator
(four clients creating links and following them over HTTPS, as fast as possible) while switching both API containers
to a new image:

```bash
SNIP_IMAGE=snip:1.10.1 docker compose up -d
```

```text
{ 'POST 201': 8536, 'GET 302': 8309, 'POST 502': 27850, 'GET 502': 227, 'POST 504': 4 }
```

For several seconds, *every* request failed: both API containers were recreated at the same time, and nginx had
nothing to send requests to. (There are so many errors because a failure returns instantly and the clients
immediately try again.) A single API container would have the same gap, between stopping the old one and the new one being ready.

### Start new, then stop old

The fix is to change the order: start the new containers *next to* the old ones, wait until they're healthy, then
stop the old ones, which finish their in-flight requests first. `deploy.sh`:

```bash
#!/bin/sh
# Zero-downtime update of Snip to another image (chapter 18). Also how you roll back.
#   ./deploy.sh registry.example.com/snip:1.11
# 1. Get the image and run its migrations (which must also work with the old code).
# 2. Start new API containers next to the old ones and wait until they're healthy.
# 3. Stop the old ones: they finish their in-flight requests, and nginx moves on.
# 4. Replace the worker, and save the image in .env so that later "up" commands use it.
set -eu
cd "$(dirname "$0")"
image=${1:?usage: ./deploy.sh <image>}
# Without COMPOSE_FILE, every command below would also merge compose.override.yaml (development).
grep -q '^COMPOSE_FILE=' .env 2>/dev/null || [ -n "${COMPOSE_FILE:-}" ] ||
  { echo "set COMPOSE_FILE in .env first (see .env.example)" >&2; exit 1; }
export SNIP_IMAGE="$image"     # overrides .env for every command below

docker image inspect "$image" >/dev/null 2>&1 || docker pull "$image"
docker compose run --rm migrate

old=$(docker compose ps -q api)
count=$(echo "$old" | grep -c . || true)
[ "$count" -gt 0 ] || { echo "no running api containers; use docker compose up -d" >&2; exit 1; }
echo "starting $count new api containers next to the $count old ones"
docker compose up -d --no-deps --no-recreate --scale api=$((count * 2)) api
new=$(docker compose ps -q api | grep -v -F "$old")

for id in $new; do
  tries=0
  until [ "$(docker inspect -f '{{.State.Health.Status}}' "$id")" = healthy ]; do
    tries=$((tries + 1))
    if [ "$tries" -gt 60 ]; then
      echo "new container $id isn't healthy after 60 s; removing the new containers" >&2
      docker logs --tail 20 "$id" >&2
      docker rm -f $new >/dev/null
      exit 1
    fi
    sleep 1
  done
done

sleep 6                        # nginx re-resolves "api" every 5 s: let it find the new containers
echo "stopping the old api containers"
# One at a time, 6 s apart, so that nginx always has a container it's willing to use.
for id in $old; do
  docker stop "$id" >/dev/null # SIGTERM: it finishes its requests and exits (chapter 2)
  docker rm "$id" >/dev/null
  sleep 6
done
docker compose up -d --no-deps worker

if grep -q '^SNIP_IMAGE=' .env 2>/dev/null; then
  sed -i "s|^SNIP_IMAGE=.*|SNIP_IMAGE=$image|" .env
else
  echo "SNIP_IMAGE=$image" >> .env
fi
echo "deployed $image"
```

The key command is `docker compose up -d --no-deps --no-recreate --scale api=4 api`: `--scale` asks for four API
containers, and `--no-recreate` keeps the two existing ones as they are, so only two new ones are created, with the
new image. Then the old ones are removed by ID. Afterward, a plain `docker compose up -d` changes nothing about the
API, because the two remaining containers match the configuration (with `SNIP_IMAGE` now in `.env`).

The same test as before, during `./deploy.sh snip:1.10.1`:

```text
starting 2 new api containers next to the 2 old ones
stopping the old api containers
deployed snip:1.10.1

{ 'POST 201': 15923, 'GET 302': 15923 }
```

Not one failed request out of 31,846. Several pieces from earlier chapters work together here: the healthcheck
(chapter 11) says when a new container is ready, graceful shutdown (above) finishes in-flight requests, Docker's DNS
(chapter 9) drops stopped containers immediately, and nginx's `resolve` and `proxy_next_upstream` cover the moments
in between.

**Why one at a time.** nginx learns about the new containers from DNS as soon as they start, a moment before Node.js
listens, so a few early attempts are refused. nginx retries those requests on another container, but it also benches
a server that failed for 10 seconds (`max_fails=1` and `fail_timeout=10s` are the defaults). If both old containers
stop inside that window, nginx has no server it's willing to use, and *every* request fails until the bench ends. A
first version of this script stopped the old containers together: two of eight test deploys lost thousands of
requests that way (16,303 in the worst). Stopping them one at a time, 6 seconds apart, always leaves a container
serving. Over six deploys with the final script, 2 requests out of about 190,000 failed: POSTs caught at the moment
an old container went away, which nginx can't safely retry. Chapter 22 looks at that last gap.

**When the new version is broken**, the script stops before touching the old containers. An image whose API can't
start:

```text
starting 2 new api containers next to the 2 old ones
new container 60de2d24ee17… isn't healthy after 60 s; removing the new containers
Error: Cannot find module '/app/src/serverr.js'
…
```

The old containers served all 38,552 requests of that test without an error. A broken *configuration* fails even
earlier: the migration container uses the same image and settings, and exits with chapter 13's configuration error.

The worker is simply recreated: queued clicks wait in Valkey for the few seconds it takes. The
[docker-rollout](https://github.com/wowu/docker-rollout) plugin packages the same scale-up, wait, scale-down idea as
a `docker rollout` command, if you'd rather not maintain a script.

### Rollbacks

A rollback is a deploy of the previous version:

```bash
./deploy.sh registry.example.com/snip:1.10
```

That only works if the old code still runs against the database after the new version's migrations, which is also
what makes the overlap above safe: for a few seconds, old and new containers run *at the same time* against the same
database. Hence the rule for migrations: **every migration must be backward compatible with the previous release**.
Changes that aren't are split over several releases ("expand and contract"):

| Change | Release N | Release N+1 | Release N+2 |
|---|---|---|---|
| Rename a column | Add the new column; write both, read the old | Backfill; read the new | Drop the old column |
| Add a `NOT NULL` column | Add it as nullable (or with a default); write it | Backfill; add the constraint | — |
| Drop a column | Stop reading and writing it | Drop it | — |

Keep image tags immutable (chapter 15) so that `snip:1.10` means the same image next month, and keep a few previous
versions in the registry.

## Backups

The data that matters is in the `pgdata` volume. The clicks queue in Valkey is short-lived, and the cache rebuilds
itself, so Valkey's append-only file only needs to survive restarts, which it does.

Copying a running PostgreSQL's data directory can produce an inconsistent copy. `pg_dump` instead reads a consistent
snapshot while the app keeps running. `backup.sh`:

```bash
#!/bin/sh
# A consistent PostgreSQL backup in ./backups, keeping the newest 14 (chapter 18).
#   ./backup.sh
# Copy the files off this server too: a backup on the same disk isn't a backup.
# Restore, replacing the current data (stop the api and worker first):
#   docker compose stop api worker
#   docker compose exec -T db pg_restore -U snip -d snip --clean --if-exists < backups/<file>.dump
#   docker compose start api worker
set -eu
cd "$(dirname "$0")"
mkdir -p backups
file="backups/snip-$(date -u +%Y%m%dT%H%M%SZ).dump"
# pg_dump reads a consistent snapshot while the app keeps running. Writing to a temporary
# name first means a failed dump never looks like a good backup.
docker compose exec -T db pg_dump -U snip -d snip --format=custom > "$file.partial"
mv "$file.partial" "$file"
echo "wrote $file ($(du -h "$file" | cut -f1))"
ls -1t backups/snip-*.dump | tail -n +15 | xargs -r rm --
```

```text
wrote backups/snip-20261015T105255Z.dump (772K)
```

`-T` disables the pseudo-terminal, which would corrupt binary output. The custom format (`--format=custom`) is
compressed and lets `pg_restore` restore selectively. A restore after deleting 1,000 rows by mistake:

```bash
docker compose stop api worker
docker compose exec -T db pg_restore -U snip -d snip --clean --if-exists < backups/snip-20261015T105255Z.dump
docker compose start api worker
docker compose exec -T db psql -U snip -d snip -tAc 'SELECT count(*) FROM links'
```

```text
34440
```

The rules that make backups real:

- **Off the server.** Copy each dump elsewhere: object storage (S3 or compatible) with a tool like `rclone` or
  `restic`, from the same cron job.
- **Automatic.** A cron job or systemd timer, with an alert when it *doesn't* run.
- **Restore-tested.** A backup you haven't restored is a hope. Exercise 3 restores into a throwaway container,
  which is easy enough to do on a schedule.
- **Know your loss window.** Daily dumps can lose up to a day. For less, PostgreSQL's continuous archiving
  (write-ahead log archiving with tools like pgBackRest or WAL-G) or a managed database service is the next step.

Volume-level backups with a throwaway container (chapter 8) suit data without its own dump tool, like uploaded
files. For databases, use the database's tool.

## A production checklist

**Images and deploys**
- Images are built, tested and scanned in CI, pushed with immutable version tags, and pulled on the server.
- Deploys start new containers before stopping old ones; a broken version never replaces a working one.
- Migrations are backward compatible with the previous release; rollback is one command and has been tried.

**Runtime**
- Every service has a restart policy, a healthcheck, memory/CPU/PID limits, and rotated logs (chapter 17).
- Every service shuts down cleanly on `SIGTERM` within its grace period.
- Services run as non-root with dropped capabilities, `no-new-privileges` and read-only filesystems where possible
  (chapter 16).
- Only the reverse proxy publishes ports; the databases are on a network the proxy can't reach.
- `live-restore` is enabled; Docker starts at boot.

**Edge**
- TLS with automatic renewal and expiry monitoring; HTTP redirects to HTTPS; HSTS.
- `/metrics` and other internal endpoints aren't reachable from outside.
- Body size limits, timeouts and rate limits on expensive endpoints.

**Data**
- Automatic backups, copied off the server, restore-tested on a schedule.
- Secrets are files with restricted permissions, never in images, Git or `docker inspect` output (chapter 13).

**Observability**
- Structured logs with request IDs, collected off the server.
- Alerts for: container restarts and OOM kills, failing healthchecks, error rate, latency, disk space, certificate
  expiry, failed backups.

**The host**
- Security updates applied automatically (or at least weekly), including the kernel, with reboots planned.
- Docker Engine kept current; old images pruned (`docker image prune -a --filter until=168h`).
- SSH with keys only; only 22, 80 and 443 open.

## Key takeaways

- On the server, run images from the registry by version; the source code isn't there. `COMPOSE_FILE` in the
  server's `.env` keeps development overrides out.
- Graceful shutdown is a chain: nginx, the API, the worker and the databases each finish their work on `SIGTERM`, in
  reverse dependency order, within `stop_grace_period`. Add `init: true` for apps that start child processes.
- nginx in front handles TLS, HTTP/2, request IDs, access logs, limits, and load balancing over all containers of a
  service; with `resolve` and Docker's DNS it follows containers as they come and go.
- `docker compose up -d` with a new image recreates containers and drops requests. Starting the new containers next
  to the old ones, waiting for health, then stopping the old ones gave zero failed requests under load.
- Old and new versions overlap during a deploy, and a rollback runs old code on a migrated database: migrations must
  be backward compatible ("expand and contract").
- Back up with `pg_dump`, copy the dumps off the server, and test restores regularly.

## Exercises

1. **Measure it yourself.** Start the production setup locally (`docker compose -f compose.yaml up -d --wait`, with
   `COMPOSE_FILE=compose.yaml` in `.env`), build a second tag of the image, and run a load generator while you update
   once with `docker compose up -d` and once with `./deploy.sh`. Count the failed requests each way.

<details>
<summary>Solution</summary>

```bash
docker build --build-arg APP_VERSION=1.10.1 -t snip:1.10.1 .
```

A load generator that only needs Node.js (`load.mjs`):

```js
import https from 'node:https';
const agent = new https.Agent({ keepAlive: true, rejectUnauthorized: false });   // self-signed certificate
const seconds = Number(process.argv[2] ?? 40);
const counts = {};
const req = (method, path, body) => new Promise((resolve) => {
  const r = https.request({ host: 'localhost', port: 8443, method, path, agent,
    headers: body ? { 'content-type': 'application/json' } : {} }, (res) => {
    let data = ''; res.on('data', (c) => (data += c)); res.on('end', () => resolve({ status: res.statusCode, data }));
  });
  r.on('error', (e) => resolve({ status: e.code }));
  if (body) r.write(body);
  r.end();
});
const end = Date.now() + seconds * 1000;
async function loop() {
  while (Date.now() < end) {
    const p = await req('POST', '/api/links', JSON.stringify({ url: 'https://example.com/' + Math.random() }));
    counts['POST ' + p.status] = (counts['POST ' + p.status] ?? 0) + 1;
    if (p.status !== 201) continue;
    const g = await req('GET', '/' + JSON.parse(p.data).code);
    counts['GET ' + g.status] = (counts['GET ' + g.status] ?? 0) + 1;
  }
}
await Promise.all([loop(), loop(), loop(), loop()]);
console.log(counts);
```

```bash
node load.mjs 30 & sleep 3; SNIP_IMAGE=snip:1.10.1 docker compose up -d; wait
node load.mjs 40 & sleep 3; ./deploy.sh snip:1.10; wait
```

Expect thousands of 502s with `docker compose up -d` and none with `deploy.sh` (this chapter's run: 28,081 failures
versus 0). If you see a handful of errors with `deploy.sh`, check that nginx's upstream uses `resolve` and that
`proxy_next_upstream` includes `error`. (An occasional single lost POST is the race described in chapter 22.)

</details>

2. **A failed deploy.** Build an image whose API can't start, deploy it, and confirm that the site keeps working and
   that `.env` still names the previous image. What would happen with a plain `docker compose up -d` instead?

<details>
<summary>Solution</summary>

```bash
printf 'FROM snip:1.10\nCMD ["src/serverr.js"]\n' | docker build -q -t snip:broken -
./deploy.sh snip:broken; echo "exit=$?"
grep SNIP_IMAGE .env
docker compose ps
```

The migrations run (the `migrate` service has its own command), the two new containers crash and restart until the
script gives up after 60 s, prints their log ("Cannot find module '/app/src/serverr.js'") and removes them. The script
exits with 1 before stopping anything, so the old containers keep serving and `.env` is unchanged. With `docker
compose up -d`, both API containers would be replaced by crashing ones, and the site would be down until someone
noticed and rolled back.

</details>

3. **Verify a backup.** Without touching the running database, prove that the newest backup can be restored and
   contains the data you expect. Make it a script you could run every night.

<details>
<summary>Solution</summary>

```bash
#!/bin/sh
# verify-backup.sh: restores the newest dump into a throwaway PostgreSQL and checks it.
set -eu
cd "$(dirname "$0")"
file=$(ls -1t backups/snip-*.dump | head -1)
docker run -d --name restore-test -e POSTGRES_PASSWORD=test postgres:18-alpine >/dev/null
trap 'docker rm -f restore-test >/dev/null' EXIT
until docker exec restore-test pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; do sleep 1; done
docker exec restore-test createdb -U postgres snip
docker exec -i restore-test pg_restore -U postgres -d snip --no-owner < "$file"
docker exec restore-test psql -U postgres -d snip -tAc 'SELECT count(*), max(created_at) FROM links'
```

```text
34440|2026-10-15 10:52:29.907495+00
```

`pg_isready -h 127.0.0.1` waits for the real server, not the temporary one that the image runs during
initialization (chapter 11). `--no-owner` skips setting ownership to the `snip` role, which doesn't exist in the
throwaway database. In a nightly job, also check that the newest row is recent (the `max(created_at)`), and alert if
the script fails.

</details>

4. **Certificate expiry.** Write a command that prints how many days the certificate served on port 8443 (or 443 in
   production) has left, and exits non-zero below 14 days, for use in monitoring.

<details>
<summary>Solution</summary>

```bash
end=$(echo | openssl s_client -connect localhost:8443 -servername localhost 2>/dev/null \
  | openssl x509 -noout -enddate | cut -d= -f2)
days=$(( ($(date -d "$end" +%s) - $(date +%s)) / 86400 ))
echo "certificate expires in $days days ($end)"
[ "$days" -ge 14 ]
```

```text
certificate expires in 29 days (Nov 14 10:49:47 2026 GMT)
```

Checking the certificate nginx actually *serves* (rather than the file on disk) also catches a renewal that worked
but was never loaded because the reload step failed. (`date -d` is GNU date, as on Linux servers.)

</details>

5. **Rate limiting.** Limit link creation (`POST /api/links`) to 10 per minute per client IP, with bursts of 5, and
   answer `429` beyond that. Redirects must stay unlimited. Test it.

<details>
<summary>Solution</summary>

In the `http` block:

```nginx
  # At most 10 new links per minute per client address, with bursts of up to 5.
  limit_req_zone $binary_remote_addr zone=create:10m rate=10r/m;
  limit_req_status 429;
```

In the HTTPS `server` block, before `location /`:

```nginx
    location = /api/links {
      limit_req zone=create burst=5 nodelay;
      proxy_pass http://snip_api;
      proxy_http_version 1.1;
      proxy_set_header Connection "";
      proxy_set_header Host $host;
      proxy_set_header X-Request-Id $request_id;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto $scheme;
    }
```

(The `proxy_*` settings are repeated because a `location` doesn't inherit them from a sibling. With more locations,
move them into a file and `include` it.) Check and reload without restarting:

```bash
docker compose exec nginx nginx -t
docker compose exec nginx nginx -s reload
for i in $(seq 10); do
  curl -sk -o /dev/null -w '%{http_code} ' -X POST https://localhost:8443/api/links \
    -H 'content-type: application/json' -d '{"url":"https://example.com/r"}'
done; echo
```

```text
201 201 201 201 201 201 429 429 429 429
```

One request plus a burst of five pass, then one more every six seconds. Behind another proxy or load balancer,
`$binary_remote_addr` would be that proxy's address for everyone; you'd configure nginx's `real_ip` module to trust
the `X-Forwarded-For` header from it first.

</details>

---
← Previous: [Resources, logging and monitoring](17-resources-logging-and-monitoring.md) | Next: [Troubleshooting and debugging](19-troubleshooting-and-debugging.md) →
