# Snip examples

Snapshots of Snip, the URL shortener built throughout the Docker tutorial. Each directory is a complete,
self-contained project as it stands at the end of the chapter named in its directory.

| Directory | Chapter | What it adds |
|---|---|---|
| [`ch04-first-image/`](ch04-first-image/) | 4 | The app (Express, in-memory store, graceful shutdown) and a first single-stage Dockerfile |
| [`ch07-production-image/`](ch07-production-image/) | 7 | Health check, tests, and a multi-stage Dockerfile with a test stage and a distroless runtime |
| [`ch10-compose-postgres/`](ch10-compose-postgres/) | 10 | A PostgreSQL store and a `compose.yaml` for the API and the database |
| [`ch11-compose-in-depth/`](ch11-compose-in-depth/) | 11 | Valkey cache and click queue, a worker, a migration job, healthchecks, `.env`, profiles and override files (run `cp .env.example .env` first) |

Build and run any of them from inside its directory, for example:

```bash
cd ch07-production-image
docker build -t snip:1.2 .
docker run --rm -p 3000:3000 snip:1.2
```

The finished capstone project lives in [`snip/`](snip/) (chapter 22).
