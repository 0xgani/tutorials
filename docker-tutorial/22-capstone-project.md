# Chapter 22 — Capstone project: Snip

## What you'll learn

- How everything from the previous chapters fits together in one project you could run in production
- The architecture: services, networks, volumes, and the path of a request and of a click
- How to run the project for development, test it the way CI does, and deploy it to a server
- What load-testing the deployment revealed, and where zero-downtime with Compose reaches its limits
- How to operate it: deploys, rollbacks, backups, secret rotation and incidents
- A production-readiness checklist, what's deliberately missing, and where to go next

The complete project is in [`examples/snip/`](examples/snip/), with its own
[README and operations runbook](examples/snip/README.md). This chapter is the guided tour: it doesn't repeat what
earlier chapters explained, but points to them, and it reports what happened when the whole system was tested
together, which found two bugs that the chapter-by-chapter tests had missed.

## What you're building

Snip is the URL shortener you've been containerizing since chapter 4: `POST /api/links` with a long URL returns a
short code, and `GET /<code>` redirects and counts the click. In its final form it's six services:

```text
             :443 (:8443 in development)
                     │
               ┌─────▼─────┐   edge network
               │   nginx   │   TLS, HTTP/2, request IDs, JSON access log
               └─────┬─────┘
           ┌─────────┴─────────┐
     ┌─────▼─────┐       ┌─────▼─────┐
     │   api #1  │       │   api #2  │   Express, distroless, read-only, no capabilities
     └──┬─────┬──┘       └──┬─────┬──┘
        │     └──────┬──────┘     │       default network (no published ports)
        │      ┌─────▼─────┐      │
        │      │  valkey   │◄─────┼──── worker: moves queued clicks into PostgreSQL
        │      └───────────┘      │
     ┌──▼─────────────────────────▼──┐
     │          PostgreSQL           │◄── migrate: a one-off job before every start and deploy
     └───────────────────────────────┘
```

| Service | Image | Networks | Published | Purpose |
|---|---|---|---|---|
| `nginx` | `nginx:1.31-alpine` | edge | 80, 443 | TLS termination, HTTP/2, load balancing, retries |
| `api` (×2) | `snip` (distroless) | default, edge | — | The HTTP API |
| `worker` | `snip` | default | — | Moves clicks from Valkey to PostgreSQL in batches |
| `migrate` | `snip` | default | — | Applies SQL migrations, then exits |
| `db` | `postgres:18-alpine` | default | — | Links and click counts, in the `pgdata` volume |
| `valkey` | `valkey/valkey:9-alpine` | default | — | Redirect cache and the click queue, in `valkeydata` |
| `psql` | `postgres:18-alpine` | default | — | A `psql` shell; profile `tools`, never started by `up` |

**A request**: the client connects to nginx on 443. nginx terminates TLS, adds an `X-Request-Id`, and proxies to one
of the API containers it found by resolving `api` through Docker's DNS (chapter 9). The API answers a redirect from
the Valkey cache when it can, from PostgreSQL otherwise, and logs one JSON line carrying the same request ID as
nginx's access log line (chapter 17).

**A click**: the API pushes the short code onto the `clicks` list in Valkey and redirects at once. The worker takes up
to 500 codes at a time and adds them to PostgreSQL in one `UPDATE` (chapter 11). A slow database never slows down a
redirect; a crashed worker only delays the counts.

Two networks keep the blast radius small (chapter 9): nginx sits on `edge` with the API containers and can't reach
PostgreSQL or Valkey at all. Only nginx publishes ports.

## Getting it running

From `examples/snip/`, the development setup:

```bash
cp .env.example .env
./create-secrets.sh
./create-dev-cert.sh
docker compose up --watch
```

<https://localhost:8443> goes through nginx with a self-signed certificate; <http://localhost:3000> reaches the single
development API container directly. Saving a file in `src/` restarts the API and the worker; changing
`package-lock.json` rebuilds the image.

To run what production runs, start `compose.yaml` alone, without the development override (or set `COMPOSE_FILE` as
the server does, below):

```bash
docker compose -f compose.yaml up -d --build --wait
docker compose -f compose.yaml ps --format '{{.Name}} {{.Status}}'
```

```text
snip-api-1 Up 3 seconds (healthy)
snip-api-2 Up 3 seconds (healthy)
snip-db-1 Up 9 seconds (healthy)
snip-nginx-1 Up 1 second (healthy)
snip-valkey-1 Up 9 seconds (healthy)
snip-worker-1 Up 3 seconds
```

`--wait` returns only when every service is healthy, in dependency order: `db` and `valkey`, then the `migrate` job
must *complete successfully*, then the API, then nginx (chapter 11).

## A tour of the project

### Where each chapter shows up

| Part of the project | What it does | Chapters |
|---|---|---|
| `src/server.js` | Graceful shutdown on SIGTERM; logs memory and heap limits at start | 2, 4, 17, 18 |
| `Dockerfile` | Multi-stage: `deps`, `test`, `dev`, `runtime-slim`, `runtime` (distroless, non-root) | 4–7, 12, 14 |
| `.dockerignore` | Small context, no secrets, no Compose files in the image | 5, 6, 13 |
| `compose.yaml` | Services, healthchecks, dependency conditions, volumes, networks, a profile | 10, 11 |
| `x-snip` block | Shared settings for the three services that run the Snip image | 11 |
| `secrets:`, `src/config.js` | The database password as a file; validated configuration | 13 |
| `read_only`, `cap_drop`, `security_opt` | Hardening of every service | 16 |
| `deploy.resources`, `x-logging`, `/metrics` | Limits, rotated logs, structured logs, metrics | 17 |
| `nginx/`, `deploy.sh`, `backup.sh` | Reverse proxy, zero-downtime deploys, backups | 18 |
| `compose.override.yaml` | Development: `dev` image, Compose Watch, the inspector | 12 |
| `.github/workflows/ci.yml`, `docker-bake.hcl` | Tests, scan, multi-platform build, SBOM, provenance, signing | 14, 15 |
| `verify-backup.sh`, `README.md` | Restore checks and the operations runbook | 18, 19 |

### The image

The `Dockerfile` is chapter 14's cross-building multi-stage file, unchanged except for its tags. Its stages, and the
sizes measured for this chapter:

| Target | Used for | Content (compressed) | On disk | User |
|---|---|---|---|---|
| `test` | `docker build --target test .`: unit tests, nothing exported | — | — | root |
| `dev` | Development with Compose Watch | 83.7 MB | 360 MB | `node` (1000) |
| `runtime-slim` | Production when you need a shell | 83.1 MB | 357 MB | `node` (1000) |
| `runtime` (default) | Production | **57.5 MB** | **241 MB** | `nonroot` (65532) |

```bash
docker image inspect snip:2.0 --format '{{len .RootFS.Layers}} layers, user {{.Config.User}}'
```

```text
27 layers, user 65532
```

`deps` and `test` run on the build machine's own platform (`--platform=$BUILDPLATFORM`), and the runtime stages
contain only `COPY` instructions, so building for arm64 on an amd64 runner needs no emulation (chapter 14).

### Three Compose files

| File | Role | Used by |
|---|---|---|
| `compose.yaml` | The production definition: hardened, limited, two API replicas, nothing published but nginx | Everyone |
| `compose.override.yaml` | Development: `dev` image, Compose Watch, inspector on `127.0.0.1:9229`, database ports on `127.0.0.1` | `docker compose` on your machine, merged automatically |
| `compose.prod.yaml` | The production differences: the public `BASE_URL`, two workers | The server, through `COMPOSE_FILE` |

`compose.yaml` *is* production, so CI tests exactly what the server runs. The override only loosens things for
development, and the server never sees it because its `.env` says:

```bash
COMPOSE_FILE=compose.yaml:compose.prod.yaml
```

That one line prevents the worst accident in chapter 18: a development override merged into a production command,
recreating the database in the middle of a deploy. `deploy.sh` refuses to run without it.

### The pipeline

```text
pull request ──► test
push to main ──► test ──► publish
tag v2.0.1   ──► test ──► publish ──► deploy
```

- **test** builds the `test` stage (unit tests), starts the stack from `compose.yaml` with a self-signed certificate,
  runs the integration test from a container on the stack's network, smoke-tests nginx (`/healthz` must answer 200,
  `/metrics` 404), and fails on fixable critical vulnerabilities in the image (chapter 14).
- **publish** builds for `linux/amd64` and `linux/arm64`, pushes to GitHub's registry with an SBOM and provenance
  attestation, and signs the pushed digest with the workflow's own identity, without any key to manage (chapter 15).
- **deploy**, for version tags only, connects to the server over SSH and runs `deploy.sh` with the image *by digest*,
  so the server runs exactly the bytes that were tested and signed:

```yaml
      - name: Deploy the signed digest over SSH
        env:
          SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}
          KNOWN_HOSTS: ${{ secrets.DEPLOY_KNOWN_HOSTS }}
          HOST: ${{ vars.DEPLOY_HOST }}
          OWNER: ${{ github.repository_owner }}
          DIGEST: ${{ needs.publish.outputs.digest }}
        run: |
          mkdir -p ~/.ssh && chmod 700 ~/.ssh
          printf '%s\n' "$SSH_KEY" > ~/.ssh/id_ed25519 && chmod 600 ~/.ssh/id_ed25519
          printf '%s\n' "$KNOWN_HOSTS" > ~/.ssh/known_hosts
          image="ghcr.io/${OWNER,,}/snip@${DIGEST}"
          # $image is expanded here, on the runner, on purpose: the server gets the final ref
          # shellcheck disable=SC2029
          ssh "deploy@$HOST" "cd /srv/snip && git pull --ff-only && ./deploy.sh $image"
```

The job uses a GitHub *environment* called `production`, so the SSH key is only available to this job and you can
require a manual approval before it runs. `concurrency: production` makes sure two deploys never overlap.
`known_hosts` comes from a secret too: never let a deploy accept whatever host key the network presents.

Registry names must be lowercase, and GitHub user names often aren't: `${OWNER,,}` is bash for "lowercase". The
`shellcheck disable` comment silences actionlint's warning that `$image` expands on the client side, which here is
exactly the point.

On the server, `deploy.sh` checks the signature before anything else when `.env` sets `SIGNER`:

```sh
signer=$(sed -n 's/^SIGNER=//p' .env 2>/dev/null || true)
if [ -n "$signer" ]; then
  cosign verify "$image" --certificate-identity-regexp "$signer" \
    --certificate-oidc-issuer https://token.actions.githubusercontent.com >/dev/null ||
    { echo "$image has no valid signature from $signer; not deploying" >&2; exit 1; }
  echo "signature verified"
fi
```

It fails closed: if `cosign` can't verify for *any* reason (no signature, the wrong workflow, no network), nothing is
deployed. Tested with an unsigned local image:

```text
snip:2.0.13 has no valid signature from ^https://github\.com/you/snip/\.github/workflows/ci\.yml@refs/tags/v; not deploying
```

Running the test job's steps by hand gave the same results CI would check:

```text
unit tests:         ℹ tests 8  ℹ pass 7  ℹ fail 0  ℹ skipped 1   (the integration test skips without SNIP_URL)
integration test:   ✔ a short link redirects and its clicks are counted
smoke test:         healthz=200 metrics=404
vulnerability scan: no fixable critical vulnerabilities in snip:2.0
```

## What testing the whole system found

Every chapter tested its own piece. Running the finished system for this chapter, for longer and under load, found two
bugs that those tests missed. Both are now fixed in the earlier chapters, and both are worth knowing about, because
they're the kind that pass every quick check.

### Bug 1: only the first save restarted the app

In development, saving `src/app.js` restarted the API. Saving it again synced the file into the container, but
nothing restarted. The cause: Compose Watch's `sync` unpacks a new copy over the old file, which *replaces* it, and
`node --watch` watches each loaded file. After the first replacement it was still watching the deleted original.
Measured with three saves:

```text
node --watch src/server.js                  3 syncs, 1 restart
node --watch-path=src src/server.js         3 syncs, 3 restarts
```

The fix, now in chapter 12 and every example, is `--watch-path=src`, which watches the directory. The quick test in
chapter 12 saved a file once, and that worked; only the second save shows the bug.

### Bug 2: zero-downtime, except when it wasn't

Chapter 18's deploy script starts new API containers next to the old ones, waits until they're healthy, then stops
the old ones. A single test deploy under load lost nothing. For this chapter, the same test ran many times: a load
generator (four loops of `POST /api/links` plus the redirect, through nginx, about 800 requests per second) during
each `./deploy.sh`.

| `deploy.sh` stops the old API containers… | Deploys | Requests | Failed |
|---|---|---|---|
| …both at once (the first version) | 8 | 255,369 | 21,365, of which 5,054 and 16,303 in two deploys |
| …one at a time, 6 seconds apart (now) | 8 | 243,653 | 7 |

Six of the eight deploys with the first version lost between zero and six requests. The other two lost thousands, in
a burst of three or four seconds of *every* request failing. nginx's error log for the worst one:

```text
14:27:57 [error] connect() failed (111: Connection refused) while connecting to upstream ... 172.20.0.5:3000
14:27:57 [warn] upstream server temporarily disabled while connecting to upstream ... 172.20.0.5:3000
14:27:57 [error] connect() failed (111: Connection refused) while connecting to upstream ... 172.20.0.6:3000
14:27:57 [warn] upstream server temporarily disabled while connecting to upstream ... 172.20.0.6:3000
14:28:04 [error] connect() failed (111: Connection refused) while connecting to upstream ... 172.20.0.3:3000
14:28:04 [warn] upstream server temporarily disabled while connecting to upstream ... 172.20.0.3:3000
14:28:04 [error] connect() failed (111: Connection refused) while connecting to upstream ... 172.20.0.2:3000
14:28:04 [warn] upstream server temporarily disabled while connecting to upstream ... 172.20.0.2:3000
14:28:04 [error] no live upstreams while connecting to upstream ...
   ... 16,303 times until 14:28:08
```

Read it in order:

1. **14:27:57.** The new containers (`.5`, `.6`) have just started. Docker's DNS lists a container as soon as it runs,
   and nginx re-resolves `api` every 5 seconds, so nginx found them a moment *before* Node.js was listening. Two
   connections were refused. nginx retried those requests on the old containers (no harm done), and **disabled** both
   new containers for `fail_timeout`, 10 seconds by default, because with `max_fails=1` (the default) one failure is
   enough.
2. **14:28:04.** `deploy.sh` saw the new containers healthy, waited 6 seconds, and stopped both old containers
   (`.2`, `.3`) together. They refused new connections and were disabled too.
3. **14:28:04 to 14:28:08.** All four servers were disabled. nginx had no server it was willing to try, and failed
   every request at once with 502 until the new containers' 10 seconds were over.

Whether it happens depends on timing: whether nginx's re-resolution lands in the second or so before the new
containers listen, and whether the old ones stop inside the 10-second window. That's why a single test passed. Stopping
the old containers **one at a time, 6 seconds apart** means there's always one container that nginx hasn't disabled:
the second old one while the first stops, and the new ones (their 10 seconds long over) when the second stops:

```sh
echo "stopping the old api containers"
# One at a time, 6 s apart, so that nginx always has a container it's willing to use.
for id in $old; do
  docker stop "$id" >/dev/null # SIGTERM: it finishes its requests and exits (chapter 2)
  docker rm "$id" >/dev/null
  sleep 6
done
```

Two tempting alternatives made it *worse*, which is worth knowing before you reach for them:

- **`max_fails=0`** ("never disable a server"): 33 failures in 4 deploys. nginx then keeps trying the address of a
  container that's already gone, waits the full 2-second connect timeout each time, and some of those requests run
  out of servers to retry.
- **`fail_timeout=2s`**: 6 failures in 6 deploys, no better than the default: a gone container comes back into
  rotation every 2 seconds, until nginx's next DNS refresh removes it.

### The last few failures

Seven failures in a quarter of a million requests isn't zero. nginx's error log shows two kinds, all on POSTs:

- `upstream prematurely closed connection` or `Connection reset by peer` *while reading the response*: nginx had
  already sent the request on a kept-alive connection when the stopping container closed it.
- `upstream timed out while connecting`: nginx still had the address of a container that was gone (it re-resolves
  every 5 seconds).

nginx retries a failed request on another server only if it's safe. A GET is idempotent, so it's retried, and none of
the redirects failed in the final runs. A POST that may already have reached the app is *not* retried, because
retrying could create the link twice. That's the right call, and it leaves three ways to close the gap:

| Approach | How | Trade-off |
|---|---|---|
| Make POSTs safe to retry | The client sends an `Idempotency-Key` header; the API stores it with the result and answers a repeat with the stored result. Then `proxy_next_upstream … non_idempotent` is safe | Application work, and every client must send the key |
| Take a container out of rotation *before* stopping it | Generate nginx's upstream list from `deploy.sh` and `nginx -s reload` (a reload drains old workers gracefully), or use a proxy with an API | More moving parts; it's what orchestrators do for you (chapter 21) |
| Accept it | Clients retry on 502/503 with backoff, which well-behaved clients do anyway | A few failed requests per deploy, at high load |

Two more ideas made no measurable difference at this scale, so they're not in the project: a 3-second "drain" in
`server.js` that answered every request with `Connection: close` after SIGTERM, plus an upstream `keepalive_timeout`
shorter than Node.js's: 1 failure in 4 deploys (111,616 requests). The lesson isn't the specific numbers. It's that a
deployment claim like "zero-downtime" deserves a load test that runs **many times**, and an error log read line by
line.

## Operating it

The project's [README](examples/snip/README.md) is the runbook: server setup, deploys, rollbacks, backups, restores,
secret rotation, certificate renewal, and what to check when something is wrong. Every procedure in it was run against
the production setup for this chapter:

| Procedure | Result |
|---|---|
| `./deploy.sh <image>` under load | 7 failed requests in 8 deploys (above) |
| Rollback: `./deploy.sh <previous image>` | Same script, same behavior |
| A deploy with a new migration, then a rollback to the old image | Migration applied once, old image works with the new column (exercise 3) |
| `./backup.sh`, then `./verify-backup.sh` | `snip-20261015T140540Z.dump: 154346 links, newest 2026-10-15 14:04:14.065938+00` |
| Restore with `pg_restore --clean --if-exists` | A deleted row came back; API healthy after `start` |
| Rotating the database password under load | `{ 'POST 201': 12851, 'GET 302': 12851 }`: no failures |
| A deploy with `SIGNER` set and an unsigned image | Refused, nothing changed |

The password rotation in the runbook improves on chapter 13's: instead of `up -d --force-recreate api` (which replaces
both API containers at once, with downtime), it runs `deploy.sh` with the *current* image. New containers start, read
the new password, and take over gracefully:

```bash
new=$(openssl rand -hex 24)
docker compose run --rm psql -c "ALTER USER snip PASSWORD '$new'"
printf '%s\n' "$new" > secrets/db_password.txt
./deploy.sh "$(sed -n 's/^SNIP_IMAGE=//p' .env)"
docker compose up -d --no-deps --force-recreate worker
```

The worker needs `--force-recreate`: Compose recreates a container when its *configuration* changes, and a secret
file's content isn't part of the configuration.

For incidents, the runbook follows chapter 19's method (state, logs, details, history, configuration, then
reproduce it small), with commands that work on distroless containers, such as reading the metrics without `curl`:

```bash
docker compose exec api /nodejs/bin/node -e \
  "fetch('http://localhost:3000/metrics').then(r => r.text()).then(console.log)"
```

```text
http_requests_total{method="GET",route="/healthz",status="200"} 2
http_requests_total{method="GET",route="/metrics",status="200"} 1
nodejs_heap_used_bytes 25814712
nodejs_heap_size_limit_bytes 163577856
nodejs_external_memory_bytes 3149780
nodejs_eventloop_delay_p99_seconds 0.021741567
```

## A production-readiness checklist

Use it for your own projects. Each line points to the chapter that explains it.

| Area | Check | Chapter |
|---|---|---|
| Image | Multi-stage; no build tools, devDependencies or secrets in the final image | 7, 13 |
| Image | Runs as a non-root user; minimal base (distroless or slim) | 7, 16 |
| Image | Base images pinned to a major version or digest, rebuilt regularly | 3, 15 |
| Image | Scanned in CI; fails on fixable critical vulnerabilities | 14, 16 |
| Image | Immutable version tags; SBOM and provenance; signed; deployed by digest | 15 |
| Runtime | Healthchecks on every long-running service; `depends_on` with conditions | 11 |
| Runtime | Graceful shutdown on SIGTERM within the stop timeout | 2, 18 |
| Runtime | `read_only`, `cap_drop: [ALL]` plus only what's needed, `no-new-privileges` | 16 |
| Runtime | CPU, memory and PID limits; the heap sized for the memory limit | 17 |
| Runtime | `restart: unless-stopped`; Docker enabled at boot | 18 |
| Network | Only the proxy publishes ports; internal services on separate networks | 9, 18 |
| Network | TLS with automatic renewal; HSTS; internal endpoints (`/metrics`) not public | 18 |
| Data | Named volumes for state; nothing important in a container's writable layer | 8 |
| Data | Backups off the server, restores *tested* automatically | 18 |
| Delivery | CI runs unit and integration tests against the production Compose file | 12, 14 |
| Delivery | Migrations are backward compatible with the previous release | 18 |
| Delivery | Deploys start new before stopping old; rollback is a deploy of the previous version | 18, 22 |
| Operations | JSON logs with request IDs, rotated; metrics; alerts on restarts, OOM kills, disk | 17 |
| Operations | A runbook that someone other than its author has followed | 22 |

## What's deliberately missing

A real deployment of Snip would grow in some of these directions. Each was left out to keep the project readable,
not because it's unimportant:

- **Monitoring and alerting**: a Prometheus server, alert rules and dashboards (exercise 2 adds Prometheus).
- **Central logging**: shipping logs off the server (Loki, Elasticsearch, a cloud service) so they survive the host.
- **A managed database**: on most clouds, a managed PostgreSQL with point-in-time recovery beats running your own
  (chapter 21). Snip only needs `DATABASE_URL` to point elsewhere.
- **More than one server**: Swarm or Kubernetes, when availability needs it (chapter 21).
- **Rate limiting and abuse protection**: chapter 18's exercise 5 limits link creation; a public shortener would also
  check URLs against blocklists.

## Where to go next

- **The official documentation**, now that you know what to look for: the
  [Dockerfile reference](https://docs.docker.com/reference/dockerfile/), the
  [Compose file reference](https://docs.docker.com/reference/compose-file/), and the
  [Engine security docs](https://docs.docker.com/engine/security/).
- **Kubernetes**, if you need more than one server: chapter 21's Compose-to-Kubernetes table is your starting map.
- **Databases in depth**: the [PostgreSQL tutorial](../postgresql-tutorial/README.md) in this collection covers what
  Snip's `db` service only scratches.
- **Your own project**: take an app you know, and walk it through the checklist above. The places where it doesn't
  fit are where you'll learn the most.

## Key takeaways

- One `compose.yaml` that *is* production, a development override that loosens it, and a production file that only
  adds differences: CI tests what the server runs, and `COMPOSE_FILE` keeps the override off the server.
- Deploy images by digest, after CI has tested, scanned and signed exactly those bytes; verify the signature on the
  server and fail closed.
- Test the finished system, not only its parts, and repeat load tests: both bugs in this chapter passed a single run.
- With DNS-based discovery, nginx learns about containers before they listen and disables a server for 10 seconds
  after one failure. Stop old containers one at a time so that a usable server always remains.
- A proxy can't safely retry a POST that may have reached the app. True zero failures needs retry-safe requests
  (idempotency keys) or taking a container out of rotation before stopping it.
- A runbook is only real once every procedure in it has been run, including restores and secret rotation.

## Exercises

1. **Be the CI.** Run the `test` job of `.github/workflows/ci.yml` by hand on your machine, step by step, against a
   fresh stack (`docker compose -f compose.yaml …`), and confirm each step's result. Then tear everything down,
   including the volumes.

<details>
<summary>Solution</summary>

From `examples/snip/`, with no `COMPOSE_FILE` in `.env` (so `-f compose.yaml` decides):

```bash
docker build --target test .                       # unit tests: the build fails if one fails
./create-secrets.sh && ./create-dev-cert.sh && cp .env.example .env
docker compose -f compose.yaml up -d --build --wait
docker run --rm --network snip_default -v "$PWD/test:/test:ro" \
  -e SNIP_URL=http://api:3000 node:24-alpine node --test /test/integration.test.js
test "$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:8443/healthz)" = 200 && echo healthz ok
test "$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:8443/metrics)" = 404 && echo metrics hidden
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.75.0 \
  image --exit-code 1 --severity CRITICAL --ignore-unfixed snip:2.0; echo "scan exit code: $?"
docker compose -f compose.yaml down --volumes
```

Each `test … && echo` prints only on success, like the CI step that fails on a non-zero exit code. Mounting the Docker
socket into Trivy gives it full control of your Docker Engine (chapter 16): fine for a well-known scanner image pinned
to a version, in CI or on your machine, never for an image you don't trust. `down --volumes` deletes the database: do
it only in a throwaway setup like this one.

</details>

2. **Add Prometheus.** Add a `prometheus` service to `compose.yaml` in a `monitoring` profile that scrapes **both**
   API containers every 15 seconds. Harden it like the other services, keep its data in a volume, and make its web UI
   reachable only from the server itself. Why doesn't `targets: ['api:3000']` work well here?

<details>
<summary>Solution</summary>

`prometheus/prometheus.yml`:

```yaml
global:
  scrape_interval: 15s
scrape_configs:
  - job_name: snip-api
    # One target per API container: Docker's DNS returns every container's address for "api".
    dns_sd_configs:
      - names: [api]
        type: A
        port: 3000
        refresh_interval: 10s
```

In `compose.yaml`, under `services:` (and `promdata:` under `volumes:`):

```yaml
  # docker compose --profile monitoring up -d
  prometheus:
    image: prom/prometheus:v3.15.0
    profiles: [monitoring]
    restart: unless-stopped
    logging: *logging
    volumes:
      - ./prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
      - promdata:/prometheus
    ports:
      - "127.0.0.1:9090:9090"   # reach it through an SSH tunnel, never publicly
    read_only: true
    cap_drop: [ALL]
    security_opt: [no-new-privileges:true]
    deploy:
      resources:
        limits:
          cpus: "0.5"
          memory: 512M
```

It joins the `default` network, where the API containers are. After `docker compose --profile monitoring up -d`:

```bash
curl -s localhost:9090/api/v1/targets |
  python3 -c "import json,sys; [print(t['labels']['instance'], t['health']) for t in json.load(sys.stdin)['data']['activeTargets']]"
```

```text
172.18.0.7:3000 up
172.18.0.6:3000 up
```

With a static target `api:3000`, Prometheus resolves the name and connects to *one* of the containers, a different
one from scrape to scrape. Counters from two processes would be mixed into one series that jumps up and down, which
breaks `rate()`. DNS service discovery turns each address into its own target, and a deploy's new containers appear
within `refresh_interval`. The image runs as `nobody` and needs no capabilities; its only writes go to the volume, so
`read_only` works. From your laptop: `ssh -L 9090:127.0.0.1:9090 deploy@server`, then open <http://localhost:9090>.

</details>

3. **An expand migration, deployed and rolled back.** Snip will get link expiry in release 2.1. Write the migration
   that release 2.1 needs, deploy an image that contains it under load, then roll back to 2.0. What makes the
   rollback safe, and what would have made it unsafe?

<details>
<summary>Solution</summary>

`migrations/002-add-expires-at.sql`:

```sql
-- Release 2.1 (expand): a nullable column, no default. Adding it is instant (no table
-- rewrite), and release 2.0, still running during the deploy, simply ignores it.
ALTER TABLE links ADD COLUMN expires_at timestamptz;
```

```bash
docker build -t snip:2.1 .
./deploy.sh snip:2.1          # the migrate job applies 002 once, then the overlap starts
./deploy.sh snip:2.0          # rollback: no migration runs; 2.0 works with the extra column
```

```text
{"level":"info",...,"migration":"002-add-expires-at.sql","msg":"applied migration"}
deployed snip:2.1
deployed snip:2.0
```

The rollback is safe because 2.0 never mentions `expires_at`: its `INSERT` names its columns, the new column is
nullable, and a `SELECT *` just returns one more field that the code ignores. Migrations only move forward; a rollback
deploys old *code* against the new schema, which is why every migration must work with the previous release.

Unsafe versions: `ADD COLUMN expires_at timestamptz NOT NULL` (2.0's inserts would fail), renaming or dropping a
column 2.0 reads, or a default that forces a full table rewrite while holding a lock (a volatile default such as
`clock_timestamp()`; a constant default is instant since PostgreSQL 11).

</details>

4. **Rename a column without downtime.** Snip's `url` column should become `target_url`. Plan it as a sequence of
   releases where every deploy overlaps old and new code, and every rollback stays safe. Write the SQL for each
   release.

<details>
<summary>Solution</summary>

| Release | Migration | Code |
|---|---|---|
| N (expand) | `ALTER TABLE links ADD COLUMN target_url text;` | Writes both columns; reads `url` |
| N+1 (backfill) | `UPDATE links SET target_url = url WHERE target_url IS NULL;` then `ALTER TABLE links ALTER COLUMN target_url SET NOT NULL;` | Writes both; reads `target_url` |
| N+2 (contract) | `ALTER TABLE links DROP COLUMN url;` | Uses only `target_url` |

Check each overlap: during N's deploy, N−1 writes only `url`, so rows created then have no `target_url`, which is why
the backfill runs in N+1, when every running version writes both. During N+2's deploy, N+1 still runs but no longer
reads `url`. Rolling back from N+2 to N+1 is safe; rolling back to N is not (N reads `url`), so wait until N+2 has
proven itself before running its migration, or ship the `DROP` one release later still.

On a large table, `SET NOT NULL` scans every row while holding a lock that blocks writes. The usual way around it:
`ADD CONSTRAINT target_url_not_null CHECK (target_url IS NOT NULL) NOT VALID`, then `VALIDATE CONSTRAINT` (which
doesn't block writes), after which `SET NOT NULL` uses the validated constraint instead of scanning. The statements
above were checked on the project's PostgreSQL 18 inside a transaction that was rolled back.

</details>

5. **Explain a failure from the logs.** A teammate changes nginx's upstream line to
   `server api:3000 resolve max_fails=0;` "so that nginx never gives up on a server", and deploys under load now fail
   more often than before. Using only nginx's error log, explain why. Then propose a change that would remove the
   remaining failures entirely, and say what it costs.

<details>
<summary>Solution</summary>

The error log during such a deploy shows lines like these, many times:

```text
[error] upstream timed out (110: Operation timed out) while connecting to upstream ... 172.20.0.3:3000
[error] no live upstreams while connecting to upstream ... snip_api
```

`172.20.0.3` is an old API container that has already been removed. nginx still has its address for up to 5 seconds,
until the next DNS refresh. With the default `max_fails=1`, the first timed-out request disables that address for 10
seconds, so only one request pays the 2-second connect timeout. With `max_fails=0`, *every* request that round robin
sends there waits 2 seconds before moving on, and some run out of servers to retry. In this chapter's runs: 33
failures in 4 deploys with `max_fails=0`, versus 7 in 8 with the defaults.

To remove the remaining failures, stop sending requests to a container *before* it stops, instead of relying on DNS
noticing afterwards: for example, have `deploy.sh` write an `upstream` block listing the new containers' addresses,
run `docker compose exec nginx nginx -s reload` (a reload lets old workers finish their requests), and only then stop
the old containers. The cost is a generated config file and a deploy script that must keep it correct, which is
essentially what an orchestrator or a proxy with service discovery does for you. The other route is making POSTs
retry-safe with idempotency keys, which costs application work but also protects against every other kind of
network failure.

</details>

---
← Previous: [Beyond a single host](21-beyond-a-single-host.md) | Next: [Back to the start](README.md) →
