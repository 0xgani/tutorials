# Chapter 13 — Configuration and secrets

## What you'll learn

- Why one image should run unchanged in every environment, and what that means for configuration
- Build-time vs. run-time configuration
- Every way a secret leaks into an image, demonstrated, and how to avoid each
- Why environment variables are a weak place for secrets
- Compose secrets, `/run/secrets`, and the `_FILE` convention
- Compose configs for non-secret files
- Loading and validating configuration at startup, in one place, with clear errors
- Snip's database password moves into a secret file

## One image, many environments

The image you test in CI should be the exact image that runs in production, byte for byte, identified by its digest.
If production needs a different image, you're deploying something nobody tested. So everything that differs between
environments has to come from **outside** the image:

```text
                    ┌──────────────────────────┐
                    │  snip:1.6 (one digest)   │
                    └────────────┬─────────────┘
         ┌───────────────────────┼───────────────────────┐
         ▼                       ▼                       ▼
   development              CI / staging             production
   DATABASE_URL=…@db        DATABASE_URL=…@db        DATABASE_URL=…@pg-prod.internal
   BASE_URL=localhost        BASE_URL=staging…        BASE_URL=https://snip.example.com
   password: dev file       password: CI secret      password: secret store
```

This is the "config" factor of the **twelve-factor app** methodology: store configuration in the environment,
strictly separated from code. A good test of whether you've done it: could you publish the image on Docker Hub right
now without leaking a single credential or internal hostname? If yes, configuration is outside the image.

### Build time vs. run time

| | Build time | Run time |
|---|---|---|
| Mechanism | `ARG`, build contexts, build secrets | Environment variables, mounted files, command arguments |
| Changing it requires | A new image | Recreating the container |
| Visible in | The image (layers, history, labels), unless it's a build secret | The container (`docker inspect`, `/proc`), not the image |
| Right for | Versions of tools and base images, the app's version label, private registry tokens for installing packages | URLs, hosts, ports, feature flags, log levels, credentials the app uses |

Some values feel like build-time settings but aren't. `NODE_ENV=production` is fine to bake in, because it describes
how the image was built. An API endpoint isn't: bake it in and you need a different image for staging. Frontend
bundles are the classic exception, since bundlers inline environment variables at build time; the usual fix is to
serve a small runtime configuration file or endpoint instead.

## How secrets leak into images

Everything in an image is readable by anyone who can pull it: every layer, the history and the configuration.
Deleting a file in a later layer hides it from the container's filesystem, but the earlier layer still contains it.
Here's a Dockerfile that makes every classic mistake:

```dockerfile
FROM alpine:3
ARG NPM_TOKEN
ENV API_KEY=sk_live_123
RUN echo "//registry.npmjs.org/:_authToken=${NPM_TOKEN}" > /root/.npmrc && echo "pretend npm ci" && rm /root/.npmrc
COPY .env /tmp/.env
RUN rm /tmp/.env
```

```bash
docker build --build-arg NPM_TOKEN=npm_abc123 -t leaky .
```

BuildKit's build checks (chapter 5) already complain:

```text
 2 warnings found (use docker --debug to expand):
 - SecretsUsedInArgOrEnv: Do not use ARG or ENV instructions for sensitive data (ARG "NPM_TOKEN") (line 2)
 - SecretsUsedInArgOrEnv: Do not use ARG or ENV instructions for sensitive data (ENV "API_KEY") (line 3)
```

And they're right. The **build argument** is in the history of every `RUN` step that follows it:

```bash
docker history --no-trunc --format '{{.CreatedBy}}' leaky
```

```text
RUN |1 NPM_TOKEN=npm_abc123 /bin/sh -c rm /tmp/.env # buildkit
COPY .env /tmp/.env # buildkit
RUN |1 NPM_TOKEN=npm_abc123 /bin/sh -c echo "//registry.npmjs.org/:_authToken=${NPM_TOKEN}" > /root/.npmrc && echo "pretend npm ci" && rm /root/.npmrc # buildkit
ENV API_KEY=sk_live_123
ARG NPM_TOKEN=npm_abc123
CMD ["/bin/sh"]
```

The **`ENV`** is in the image configuration, and in every container started from it:

```bash
docker inspect leaky --format '{{json .Config.Env}}'
```

```text
["PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin","API_KEY=sk_live_123"]
```

And the **deleted `.env` file** is still in its layer. `docker save` writes the image as a tar archive of layers,
and any of them can be unpacked:

```bash
docker save leaky -o leaky.tar && mkdir lk && tar -xf leaky.tar -C lk
for b in lk/blobs/sha256/*; do
  tar -tzf "$b" 2>/dev/null | grep -q 'tmp/.env' && tar -xzOf "$b" tmp/.env
done
```

```text
NPM_TOKEN=npm_abc123
```

Only the `.npmrc` written and deleted within the *same* `RUN` step didn't survive as a file, because a layer stores
only the result of a step. But that step's command line, with the token, is in the history anyway.

The rules that follow:

- **Never pass secrets as `ARG` or `ENV`.** Use build secrets (`RUN --mount=type=secret`, chapter 6) for anything
  the build needs, such as a private registry token.
- **Never `COPY` a secret**, not even "temporarily". Keep `.env`, `.npmrc`, keys and `secrets/` in `.dockerignore`, so
  `COPY . .` can't pick them up by accident.
- **Treat any image that ever contained a secret as leaked**: rotate the secret, don't just rebuild.
- **Multi-stage builds don't make a secret in an early stage safe** for the build cache or for anyone who builds the
  intermediate stage; they only keep it out of the final image. Use build secrets there too.

Compose passes build secrets to the build the same way as the CLI's `--secret`:

```yaml
services:
  api:
    build:
      context: .
      secrets:
        - npmrc            # available to RUN --mount=type=secret,id=npmrc
secrets:
  npmrc:
    file: ~/.npmrc
```

## Environment variables are a weak place for secrets

Moving the password from the image to `environment:` in the Compose file is a big improvement. It's still not ideal,
because environment variables are visible in many places:

- **`docker inspect`** shows them to anyone with access to the Docker API, and Compose files with literal values end
  up in Git.
- **`/proc/<pid>/environ`** exposes them to other processes running as the same user in the container.
- **Child processes inherit them**, including tools you shell out to, which may log their environment.
- **Crash reporters and debug pages** love to dump the environment. An error-tracking service then stores your
  database password.
- **`docker compose config`** prints them, and people paste its output into issues and chat.

Files are better: a secret mounted as a file is visible only to processes that open it, never appears in `inspect`
output, and can be readable by exactly one user. Docker's convention is to mount secrets under **`/run/secrets/`**.

For many teams, environment variables from a protected source (a CI secret store, a platform's secret settings) are an
acceptable trade-off. Know the risks, keep the values out of the Compose file and out of Git, and prefer files when
the platform supports them, which Docker and every orchestrator do.

## Compose secrets

Compose declares secrets at the top level and grants them to services:

```yaml
services:
  api:
    secrets:
      - db_password                  # mounted at /run/secrets/db_password

secrets:
  db_password:
    file: ./secrets/db_password.txt  # from a file on the host
  api_token:
    environment: API_TOKEN           # from a variable in Compose's environment
```

A service sees only the secrets it lists. Two sources:

- **`file:`** bind-mounts the host file, read-only, at `/run/secrets/<name>`.
- **`environment:`** takes the value from the environment of the shell running Compose (or `.env`) and writes it into
  a file in the container. Useful in CI, where secrets arrive as environment variables.

`target:` changes the file name or path inside the container. The long syntax also has `uid`, `gid` and `mode`, but
outside Swarm mode Compose ignores them, and says so:

```text
level=warning msg="service \"t\": secrets.pw.mode: mode is not supported outside Swarm mode and will be ignored"
```

That has a practical consequence. A `file:` secret is a bind mount, so the container sees the **host file's owner
and permissions**. A file that is `-rw-------` and owned by you on a Linux host is unreadable for Snip's API, which runs
as UID 65532:

```text
cat: can't open '/run/secrets/pw': Permission denied
```

Protect secret files on the host with the *directory*, not the file: a `secrets/` directory with mode `700` that only
you can enter, containing files with mode `644`. The bind mount points straight at the file, so the container doesn't
need access to the directory. (Secrets from `environment:` are created world-readable, mode `0444`, inside the
container.) On Docker Desktop, shared files are presented to containers with permissive ownership, so the problem
mostly appears on Linux servers, which is exactly where you don't want to find it for the first time.

### The `_FILE` convention

An app has to know to read the file. Many official images support a convention: for a variable `X`, an `X_FILE`
variable names a file to read the value from. The `postgres` image reads `POSTGRES_PASSWORD_FILE`, and the `mysql`,
`mariadb` and many other images have equivalents. Use the same convention in your own apps, so that every secret can
come either from a variable (simple setups, tests) or from a file (everything else).

## Snip: configuration in one place

So far, Snip read `process.env` wherever it needed something: `PORT` in `server.js`, `DATABASE_URL` in three files,
with the password inside `DATABASE_URL`. This chapter moves all of it into one module that loads, validates and
documents the configuration. The complete project is in
[`examples/ch13-config-and-secrets/`](examples/ch13-config-and-secrets/).

`src/config.js`:

```js
import { readFileSync } from 'node:fs';

// All configuration comes from the environment (twelve-factor). Secrets can also come
// from files: for any secret NAME, NAME_FILE=/run/secrets/... wins over NAME.
// Invalid configuration stops the process at startup with every problem listed at once.
export function loadConfig(env = process.env) {
  const errors = [];

  const port = Number(env.PORT ?? 3000);
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    errors.push(`PORT must be a port number, got "${env.PORT}"`);
  }

  const config = {
    port,
    baseUrl: optionalUrl('BASE_URL') ?? `http://localhost:${port}`,
    databaseUrl: optionalUrl('DATABASE_URL'),
    valkeyUrl: optionalUrl('VALKEY_URL'),
  };

  // The database password is kept out of DATABASE_URL so that it can come from a secret file.
  const dbPassword = secret('DATABASE_PASSWORD');
  if (config.databaseUrl && dbPassword) {
    const url = new URL(config.databaseUrl);
    url.password = encodeURIComponent(dbPassword);
    config.databaseUrl = url.href;
  }

  if (errors.length > 0) {
    throw new Error(`invalid configuration:\n  - ${errors.join('\n  - ')}`);
  }
  return Object.freeze(config);

  function secret(name) {
    const file = env[`${name}_FILE`];
    if (!file) return env[name] || undefined;
    try {
      return readFileSync(file, 'utf8').trim();
    } catch (err) {
      errors.push(`${name}_FILE: cannot read ${file} (${err.code})`);
      return undefined;
    }
  }

  function optionalUrl(name) {
    if (!env[name]) return undefined;
    try {
      return new URL(env[name]).href.replace(/\/$/, '');
    } catch {
      errors.push(`${name} must be a URL, got "${env[name]}"`);
      return undefined;
    }
  }
}

// For entry points: print the problems without a stack trace and exit with 78
// (EX_CONFIG, "configuration error"), which a restart can't fix.
export function loadConfigOrExit() {
  try {
    return loadConfig();
  } catch (err) {
    console.error(err.message);
    process.exit(78);
  }
}

// What the app logs at startup: the configuration minus anything secret.
export function describeConfig(config) {
  const redact = (value) => {
    if (!value) return value;
    const url = new URL(value);
    if (url.password) url.password = '***';
    return url.href;
  };
  return { ...config, databaseUrl: redact(config.databaseUrl), valkeyUrl: redact(config.valkeyUrl) };
}
```

The design points, each of which matters more in containers than on a laptop:

- **Validate everything at startup and fail fast.** A typo in `DATABASE_URL` should stop the container immediately with
  a clear message, not surface as a timeout on the first request an hour later.
- **Report all problems at once.** Each fix-and-redeploy cycle is slow; listing every error saves rounds.
- **Read secret files once, at startup.** `.trim()` removes the trailing newline that `echo` and most editors add,
  a classic cause of "password authentication failed" with a password that looks right.
- **Encode the password into the URL.** Generated passwords often contain `/`, `+`, `@` or `=`, which break a URL
  unless they're percent-encoded.
- **Log the effective configuration, redacted.** The first line of every container's log answers "what is this
  container actually configured with?" without exposing the password.
- **A distinct exit code.** 78 tells whoever reads `docker ps` that this is a configuration problem.
- **A pure function of `env`.** `loadConfig({ PORT: 'abc' })` is trivial to unit test; `test/config.test.js` does.

The entry points (`server.js`, `worker.js`, `migrate.js`) now start with:

```js
import { loadConfigOrExit, describeConfig } from './config.js';

const config = loadConfigOrExit();
console.log('configuration:', JSON.stringify(describeConfig(config)));
```

and use `config.databaseUrl`, `config.valkeyUrl`, `config.port` and `config.baseUrl` instead of `process.env`.

### The Compose file

The password moves from `.env` into a secret file:

```yaml
x-snip: &snip
  build: .
  image: snip:1.6
  restart: unless-stopped
  environment: &snip-env
    DATABASE_URL: postgres://snip@db:5432/snip
    DATABASE_PASSWORD_FILE: /run/secrets/db_password
    VALKEY_URL: redis://valkey:6379
  secrets:
    - db_password

services:
  # api, worker and migrate as before (they all get the secret through x-snip)

  db:
    image: postgres:18-alpine
    environment:
      POSTGRES_USER: snip
      POSTGRES_PASSWORD_FILE: /run/secrets/db_password
      POSTGRES_DB: snip
    secrets:
      - db_password
    # volumes, healthcheck as before

  psql:
    image: postgres:18-alpine
    profiles: [tools]
    # psql can't read a password file directly, so a tiny shell wrapper reads the secret.
    entrypoint: ["sh", "-c", "PGPASSWORD=$$(cat /run/secrets/db_password) exec psql \"$$@\"", "psql"]
    environment:
      PGHOST: db
      PGUSER: snip
      PGDATABASE: snip
    secrets:
      - db_password
    # depends_on as before

secrets:
  db_password:
    file: ./secrets/db_password.txt   # created by ./create-secrets.sh; never committed
```

The `psql` wrapper shows two details: `$$` produces a literal `$` for the container's shell (chapter 11), and the
`"psql"` after the script becomes `$0`, so the arguments of `docker compose run --rm psql -c '…'` arrive in `"$@"`.

A small script creates the secret on each machine:

```bash
#!/bin/sh
# Generates the secrets that compose.yaml expects. Run once per machine; never commit the results.
set -eu
cd "$(dirname "$0")"
mkdir -p secrets
chmod 700 secrets                 # only you can list or open the directory on the host…
if [ ! -f secrets/db_password.txt ]; then
  openssl rand -hex 24 > secrets/db_password.txt
  echo "created secrets/db_password.txt"
fi
chmod 644 secrets/db_password.txt # …but the file must be readable by the containers' users (UID 65532, 70)
```

`secrets/` goes into `.gitignore` and `.dockerignore`. `.env` keeps only non-secret settings (`SNIP_PORT`).

Start it, as production would, with only the base file:

```bash
./create-secrets.sh
docker compose -f compose.yaml up -d --build --wait
docker compose logs --no-log-prefix api | head -2
```

```text
configuration: {"port":3000,"baseUrl":"http://localhost:3000","databaseUrl":"postgres://snip:***@db:5432/snip","valkeyUrl":"redis://valkey:6379"}
snip listening on port 3000 (node v24.22.0, pid 1)
```

The password appears nowhere in the container's configuration:

```bash
docker inspect snip-api-1 --format '{{range .Config.Env}}{{println .}}{{end}}'
```

```text
DATABASE_URL=postgres://snip@db:5432/snip
DATABASE_PASSWORD_FILE=/run/secrets/db_password
VALKEY_URL=redis://valkey:6379
BASE_URL=http://localhost:3000
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt
NODE_ENV=production
APP_VERSION=dev
```

```bash
docker inspect snip-api-1 --format '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}}{{println}}{{end}}'
```

```text
bind /home/you/snip/secrets/db_password.txt -> /run/secrets/db_password
```

### What failure looks like

A missing secret file stops `up` before anything runs with a half-configured service:

```text
Error response from daemon: invalid mount config for type "bind": bind source path does not exist: /home/you/snip/secrets/db_password.txt
```

Invalid configuration stops the app with every problem listed:

```bash
docker compose -f compose.yaml run --rm --no-deps -e PORT=abc -e DATABASE_PASSWORD_FILE=/run/secrets/nope api
```

```text
invalid configuration:
  - PORT must be a port number, got "abc"
  - DATABASE_PASSWORD_FILE: cannot read /run/secrets/nope (ENOENT)
```

With `restart: unless-stopped`, though, Docker restarts a container that exits with an error, configuration error or
not, with an increasing delay between attempts:

```text
snip-api-1	Restarting (78) 1 second ago
```

`docker compose ps` shows the exit code, and `docker inspect snip-api-1 --format '{{.RestartCount}}'` how often it
happened. A restart loop with exit code 78 means "read the log, fix the configuration"; chapter 19 has more on restart
loops.

## Compose configs

Not every file is a secret. **Configs** mount configuration files the same way, for things like an nginx
configuration, and can even define the content inline:

```yaml
services:
  web:
    image: nginx:alpine
    configs:
      - source: site
        target: /etc/nginx/conf.d/default.conf

configs:
  site:
    content: |
      server {
        listen 80;
        location / { return 200 "hello from a Compose config\n"; }
      }
```

```bash
docker compose exec web ls -l /etc/nginx/conf.d/
```

```text
-r--r--r--    1 root     root            85 Oct 15 09:59 default.conf
```

Configs can also come from `file:` or `environment:`. Compared with a plain bind mount, a config states its intent
("this is configuration"), is read-only, and maps directly to the configs of Swarm and the ConfigMaps of Kubernetes
(chapter 21). Chapter 18 uses one for the nginx reverse proxy.

## Beyond files on disk

A secret file on the server's disk, readable by root and Docker, is a reasonable baseline for a single server. Larger
setups use a **secret manager** (HashiCorp Vault or OpenBao, AWS Secrets Manager, Google Secret Manager, Azure Key
Vault, 1Password, Doppler, Infisical…) that adds access control per service, audit logs, rotation and short-lived
credentials. Integration patterns, from simplest to most involved:

- **Render to files at deploy time.** The deployment script fetches secrets and writes them where Compose expects
  them. Nothing in the app changes.
- **Inject as environment or files at start.** A wrapper or the platform (Kubernetes, ECS, Cloud Run) does it.
- **The app fetches secrets itself** at startup with the provider's SDK. The most flexible, and it ties the app to a
  provider.

Whatever the mechanism, the `_FILE` convention and validation at startup keep the app independent of it.

**Rotation** deserves a plan before you need it: generate a new secret, configure the consumer (PostgreSQL:
`ALTER USER … PASSWORD …`) to accept it, update the secret file, recreate the containers that read it
(`docker compose up -d --force-recreate api worker`), then retire the old one. Snip reads secrets at startup, so a
restart is required, which a graceful shutdown (chapter 18) makes harmless.

### Node.js and `.env` files

When you run Snip directly on your host (chapter 12's approach 1), Node.js can load an env file itself, no `dotenv`
package needed:

```bash
node --env-file=.env.local src/server.js        # Node.js 20.6+
node --env-file-if-exists=.env.local src/server.js   # Node.js 22.9+: no error if the file is missing
```

Don't use this inside the container image: the container's environment comes from Docker, and an env file in the
image would be configuration baked into the image.

## Key takeaways

- One image for every environment; everything that differs comes from outside, at run time.
- Build time is for how the image is made; run time is for where and with what it runs.
- `ARG`, `ENV` and `COPY`-then-delete all leave secrets readable in the image. Use build secrets for the build,
  `.dockerignore` for safety, and rotate anything that ever leaked.
- Environment variables are visible in `inspect`, `/proc` and crash reports; mounted files are better for secrets.
- Compose secrets mount at `/run/secrets/<name>`. File secrets are bind mounts with the host file's permissions:
  protect them with a `700` directory and make the file readable for the container's user.
- Support `X_FILE` alongside `X` in your apps, as official images do.
- Load configuration in one module: validate everything at startup, report all errors at once, log it redacted, and
  exit with a distinctive code.
- Use configs for non-secret configuration files; inline `content:` keeps small ones in the Compose file.

## Exercises

1. **Find the leak.** Someone gives you an image built from a Dockerfile you can't see. List three commands that can
   reveal secrets baked into it, and what each one looks at.

<details>
<summary>Solution</summary>

- `docker history --no-trunc <image>`: the command of every step, including `ARG` values substituted into `RUN`
  steps, `ENV` lines and `LABEL`s.
- `docker inspect <image> --format '{{json .Config}}'`: the image configuration, with `Env`, `Labels`, `Cmd` and
  `Entrypoint`.
- `docker save <image> -o img.tar` and unpacking the layers: every file that was ever added, including files deleted
  in later layers. Tools such as `dive` show layer contents interactively, and secret scanners (Trivy's secret
  scanning, for example, in chapter 16) automate the search.

`docker run --rm <image> env` and looking around with a shell in the container show only the final filesystem, which
misses deleted files.

</details>

2. **The trailing newline.** Create the password file with `echo "s3cret" > secrets/db_password.txt` and start a fresh
   stack. Then remove `.trim()` from `config.js`, rebuild, and explain the result. Which side would still work, and
   why?

<details>
<summary>Solution</summary>

`echo` writes `s3cret\n`. The `postgres` image's `_FILE` support reads the file with shell command substitution,
`$(< file)`, which strips trailing newlines, so the database password is `s3cret`. With `.trim()`, Snip sends
`s3cret` too, and everything works.

Without `.trim()`, Snip sends `s3cret\n` (percent-encoded as `s3cret%0A` in the URL), and the migration job fails
with `password authentication failed for user "snip"`, although the file "obviously" contains the right password.
Compare with `od -c secrets/db_password.txt` to see the `\n`. Use `printf '%s' "$pw" >` or `openssl rand -hex 24 >`
(whose trailing newline `.trim()` removes) and always trim in the app.

</details>

3. **CI-style secrets.** In CI, the database password arrives as an environment variable `DB_PASSWORD`, and writing it
   to a file is awkward. Change the Compose file so that the `db_password` secret comes from that variable instead of
   the file, without changing the services.

<details>
<summary>Solution</summary>

Only the top-level definition changes, ideally in a CI override file so that developers keep the file-based secret:

```yaml
# compose.ci.yaml
secrets:
  db_password: !override
    environment: DB_PASSWORD
```

```bash
DB_PASSWORD=$(openssl rand -hex 24) docker compose -f compose.yaml -f compose.ci.yaml up -d --wait
```

`!override` replaces the whole definition; a plain merge would leave both `file:` and `environment:` set. Services
still see `/run/secrets/db_password`, so nothing else changes. Secrets from `environment:` are created inside the
container (mode `0444`), so there are no host file permissions to worry about.

</details>

4. **A second secret.** Add an optional admin token to Snip: when `ADMIN_TOKEN` (or `ADMIN_TOKEN_FILE`) is set, the
   configuration includes `adminToken`; it must be at least 32 characters. Make sure `describeConfig` never prints it.
   Write the `config.js` changes and a test.

<details>
<summary>Solution</summary>

In `loadConfig`, after the password:

```js
  const adminToken = secret('ADMIN_TOKEN');
  if (adminToken !== undefined && adminToken.length < 32) {
    errors.push('ADMIN_TOKEN must be at least 32 characters');
  }
  config.adminToken = adminToken;
```

`config` must be built before `Object.freeze`, which it is, since freezing happens at the end. In `describeConfig`:

```js
  return {
    ...config,
    databaseUrl: redact(config.databaseUrl),
    valkeyUrl: redact(config.valkeyUrl),
    adminToken: config.adminToken ? '***' : undefined,
  };
```

A test in `test/config.test.js`:

```js
test('the admin token is validated and never described', () => {
  assert.throws(() => loadConfig({ ADMIN_TOKEN: 'short' }), /at least 32/);
  const config = loadConfig({ ADMIN_TOKEN: 'x'.repeat(32) });
  assert.equal(config.adminToken, 'x'.repeat(32));
  assert.equal(describeConfig(config).adminToken, '***');
});
```

Note the safer design for `describeConfig` in a bigger app: list the fields that may be printed (an allowlist), rather
than redacting the ones that may not, so a newly added secret is hidden by default.

</details>

5. **Rotate the database password.** Change Snip's database password on a running stack without losing data and with
   the shortest possible error window. Write down the steps and the commands.

<details>
<summary>Solution</summary>

```bash
new=$(openssl rand -hex 24)
# 1. Change it in PostgreSQL (the old one stops working for *new* connections only).
docker compose run --rm psql -c "ALTER USER snip PASSWORD '$new'"
# 2. Update the secret file the containers read.
printf '%s\n' "$new" > secrets/db_password.txt
# 3. Restart the services that read it at startup.
docker compose up -d --force-recreate --wait api worker
```

Between steps 1 and 3, existing pooled connections keep working (PostgreSQL checks the password only when a connection
is opened), but any new connection fails. Snip's pools rarely open new connections, so the window is short; a
zero-downtime rotation needs two valid credentials at once, for example a second database user: create `snip_b`,
switch the app to it, then retire `snip`. `db` itself doesn't need a restart: `POSTGRES_PASSWORD_FILE` is only read
when the data directory is first initialized (chapter 10), and `pg_isready` doesn't use a password.

A detail about step 2: `printf … >` writes *into* the existing file (same inode), so running containers would even
see the new content at once. Editors and tools that save by writing a new file and renaming it over the old one
create a new inode, and a bind mount of a single *file* stays attached to the old one until the container is
recreated. Recreating the containers, as in step 3, is the reliable way either way.

</details>

---
← Previous: [Developing with containers](12-developing-with-containers.md) | Next: [CI pipelines and multi-platform builds](14-ci-pipelines-and-multi-platform-builds.md) →
