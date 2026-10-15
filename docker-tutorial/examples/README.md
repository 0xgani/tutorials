# Snip examples

Snapshots of Snip, the URL shortener built throughout the Docker tutorial. Each directory is a complete,
self-contained project as it stands at the end of the chapter named in its directory.

| Directory | Chapter | What it adds |
|---|---|---|
| [`ch04-first-image/`](ch04-first-image/) | 4 | The app (Express, in-memory store, graceful shutdown) and a first single-stage Dockerfile |
| [`ch07-production-image/`](ch07-production-image/) | 7 | Health check, tests, and a multi-stage Dockerfile with a test stage and a distroless runtime |
| [`ch10-compose-postgres/`](ch10-compose-postgres/) | 10 | A PostgreSQL store and a `compose.yaml` for the API and the database |
| [`ch11-compose-in-depth/`](ch11-compose-in-depth/) | 11 | Valkey cache and click queue, a worker, a migration job, healthchecks, `.env`, profiles and override files (run `cp .env.example .env` first) |
| [`ch12-development/`](ch12-development/) | 12 | A `dev` stage, Compose Watch, the Node.js inspector, an integration test and a VS Code attach configuration |
| [`ch13-config-and-secrets/`](ch13-config-and-secrets/) | 13 | Validated configuration in `src/config.js`, the database password as a Compose secret (run `./create-secrets.sh` first) |
| [`ch14-ci/`](ch14-ci/) | 14 | A cross-building multi-platform Dockerfile, `docker-bake.hcl`, and a GitHub Actions workflow in `.github/workflows/ci.yml` |
| [`ch16-hardened/`](ch16-hardened/) | 16 | Every service hardened: no capabilities beyond what each needs, no-new-privileges, read-only root filesystems |
| [`ch17-observability/`](ch17-observability/) | 17 | Resource limits on every service, rotated logs, pino structured logging with request IDs, a Prometheus `/metrics` endpoint, a Valkey memory cap |
| [`ch18-production/`](ch18-production/) | 18 | nginx with TLS in front of two API containers, separate networks, `deploy.sh` for zero-downtime updates and rollbacks, `backup.sh` |
| [`ch21-swarm/`](ch21-swarm/) | 21 | Snip as a Docker Swarm stack: overlay networks, a migration job, rolling updates with automatic rollback, secrets and configs |
| [`snip/`](snip/) | 22 | The finished capstone: everything above, a signed-image check in `deploy.sh`, `verify-backup.sh`, a complete CI/CD workflow, and an operations runbook in its README |

Build and run any of them from inside its directory, for example:

```bash
cd ch07-production-image
docker build -t snip:1.2 .
docker run --rm -p 3000:3000 snip:1.2
```

The finished capstone project lives in [`snip/`](snip/) (chapter 22).
