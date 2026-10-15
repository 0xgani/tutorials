# Snip

A small URL shortener: `POST /api/links` with a long URL returns a short code, and `GET /<code>` redirects and counts
the click. This directory is the finished project of the [Docker tutorial](../../README.md); chapter 22 explains how it
fits together.

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

| Path | What it is |
|---|---|
| `src/`, `test/`, `migrations/` | The app, its unit and integration tests, and SQL migrations |
| `Dockerfile` | Stages `deps`, `test`, `dev`, `runtime-slim` and `runtime` (distroless, the default) |
| `compose.yaml` | Every service, hardened and limited: the production definition |
| `compose.override.yaml` | Development: the `dev` image, Compose Watch, the debugger, ports on `127.0.0.1` |
| `compose.prod.yaml` | Production differences, selected with `COMPOSE_FILE` in `.env` |
| `nginx/nginx.conf` | The reverse proxy |
| `.env.example` | Settings Compose reads; copy it to `.env` |
| `create-secrets.sh`, `create-dev-cert.sh` | One-time setup: the database password, a self-signed certificate |
| `deploy.sh`, `backup.sh`, `verify-backup.sh` | Operations: zero-downtime deploy and rollback, backups, restore checks |
| `docker-bake.hcl` | Multi-platform builds with `docker buildx bake` |
| `.github/workflows/ci.yml` | Test, scan, publish and sign, deploy (copy `.github/` to the repository root) |
| `.vscode/launch.json` | Attach VS Code's debugger to the API container |

Requirements: Docker Engine 29+ (or Docker Desktop) with Compose 2.22+, and `openssl` for the setup scripts. Node.js
on your machine is optional.

## Development

```bash
cp .env.example .env
./create-secrets.sh
./create-dev-cert.sh
docker compose up --watch
```

- <https://localhost:8443> goes through nginx (accept the self-signed certificate), <http://localhost:3000> reaches
  the API directly.
- Saving a file in `src/` syncs it into the containers and restarts the API and the worker. Changing
  `package-lock.json` rebuilds the image.
- Attach a debugger to `127.0.0.1:9229` (VS Code: "Attach to snip api (Docker)").
- PostgreSQL is on `127.0.0.1:5432` and Valkey on `127.0.0.1:6379`, or use `docker compose run --rm psql`.

## Tests

```bash
docker build --target test .              # unit tests, in the build
docker compose up -d --wait               # the stack, for the integration test
docker run --rm --network snip_default -v "$PWD/test:/test:ro" \
  -e SNIP_URL=http://api:3000 node:24-alpine node --test /test/integration.test.js
```

CI runs the same steps against `compose.yaml` alone (the production settings) and adds a smoke test through nginx and
a vulnerability scan.

## Production

### Server setup (once)

On a Linux server with Docker Engine, as a user in the `docker` group (equivalent to root: guard its SSH access):

```bash
sudo mkdir -p /srv/snip && sudo chown "$USER" /srv/snip
git clone https://github.com/you/snip.git /srv/snip    # only the deployment files are used
cd /srv/snip
cp .env.example .env          # then set COMPOSE_FILE, SNIP_IMAGE, ports, CERTS_DIR, ACME_DIR, SIGNER
./create-secrets.sh
docker login ghcr.io          # a read-only token
```

Get a certificate (chapter 18, "Certificates from Let's Encrypt") into `CERTS_DIR`, open only ports 22, 80 and 443 in
the provider's firewall, then:

```bash
docker compose pull
docker compose up -d --wait
```

`restart: unless-stopped` brings everything back after a reboot, as long as the Docker service is enabled
(`systemctl is-enabled docker`).

### Deploy

CI deploys every `v*.*.*` tag by digest. By hand, on the server:

```bash
./deploy.sh ghcr.io/you/snip:2.0.1
```

It verifies the signature (when `SIGNER` is set), runs migrations, starts new API containers next to the old ones,
waits for them to be healthy, stops the old ones one at a time, replaces the worker, and records the image in `.env`.
If a new container doesn't become healthy, it removes the new containers and leaves the old ones serving.

Never use `docker compose up -d` to change the API's image: it replaces all API containers at once.

### Roll back

Deploy the previous version: `./deploy.sh ghcr.io/you/snip:2.0.0`. This only works if every migration is backward
compatible with the previous release (expand, then contract).

### Back up, restore, verify

```bash
./backup.sh                   # cron: daily, then copy backups/ off the server
./verify-backup.sh            # cron: after backup.sh; fails if the newest dump can't be restored
```

Restore, replacing the current data:

```bash
docker compose stop api worker
docker compose exec -T db pg_restore -U snip -d snip --clean --if-exists < backups/<file>.dump
docker compose start api worker
```

### Rotate the database password

```bash
new=$(openssl rand -hex 24)
docker compose run --rm psql -c "ALTER USER snip PASSWORD '$new'"
printf '%s\n' "$new" > secrets/db_password.txt
./deploy.sh "$(sed -n 's/^SNIP_IMAGE=//p' .env)"       # new API containers read the new password
docker compose up -d --no-deps --force-recreate worker
```

Existing connections keep working until they're replaced, so no request fails.

### Renew the certificate

A daily cron job renews with certbot's webroot, copies the files to `CERTS_DIR` and runs
`docker compose exec nginx nginx -s reload` (chapter 18). Monitor the expiry date separately:

```bash
echo | openssl s_client -connect snip.example.com:443 -servername snip.example.com 2>/dev/null |
  openssl x509 -noout -enddate
```

### When something is wrong

```bash
docker compose ps                             # what's running, healthy, restarting
docker compose logs --since 15m api nginx     # JSON logs; follow one request by its reqId
docker stats --no-stream                      # CPU and memory against each limit
docker compose exec api /nodejs/bin/node -e \
  "fetch('http://localhost:3000/metrics').then(r => r.text()).then(console.log)"
docker compose exec valkey valkey-cli llen clicks   # clicks waiting for the worker
docker inspect --format '{{.Name}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} restarts={{.RestartCount}}' \
  $(docker compose ps -aq api)
df -h /var/lib/docker && docker system df      # disk space
```

| Symptom | First thing to check |
|---|---|
| 502 from nginx | Are the API containers healthy? `docker compose logs api` |
| An API container restarting, exit code 137 | `OOMKilled`: a leak or a too-small limit (chapter 17) |
| Click counts not increasing | The worker's logs, and the `clicks` queue length |
| Everything slow | `docker stats`, then PostgreSQL (`docker compose run --rm psql`) |
| Disk full | Logs are rotated; check `backups/`, unused images (`docker image prune`) |

Chapter 19 of the tutorial covers troubleshooting in depth.
