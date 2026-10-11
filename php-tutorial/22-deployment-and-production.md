# Chapter 22 — Deployment and production

## What you'll learn

- The ways to run PHP in production, and why nginx with PHP-FPM is the common default
- `php.ini` for production: the settings that matter, and how to see what's actually in effect
- PHP-FPM: process management, sizing the worker pool, timeouts, slow logs and status pages
- nginx in front of PHP-FPM: the front-controller configuration, static files, and the client's real address
- Container images for a PHP application: a multi-stage Dockerfile, an nginx image, and Compose
- Configuration and secrets, logs, health checks, and scheduled jobs and background workers
- Releasing without downtime, database migrations that don't break running code, and upgrading PHP

Up to now Bookshelf has run under PHP's built-in server (`php -S`), which is made for development: one process,
one request at a time, no tuning. This chapter takes it to production. The principles are the same wherever you
deploy: a virtual machine, containers on a single host, Kubernetes, or a platform that hides most of it. What changes
is who writes which configuration file.

## How PHP runs in production

Chapter 16 described SAPIs, the interfaces between PHP and whatever starts it. In production, these are the common
setups:

| Setup | How it works | Notes |
|---|---|---|
| **nginx + PHP-FPM** | nginx handles HTTP and static files, and passes PHP requests over FastCGI to a pool of PHP worker processes | The most common setup; each part scales and restarts independently |
| **Apache + PHP-FPM** | Same idea with Apache's `mod_proxy_fcgi` | Common on shared hosting; `.htaccess` support |
| **Apache + mod_php** | PHP runs inside each Apache process | Older; ties PHP to Apache's process model and memory use |
| **Caddy + PHP-FPM** | Caddy's `php_fastcgi` directive does the front-controller setup in one line | Automatic HTTPS certificates |
| **FrankenPHP** | An application server built on Caddy with PHP embedded; optional *worker mode* keeps the app loaded between requests | One binary, HTTP/2 and HTTP/3, early hints |
| **RoadRunner, Swoole/OpenSwoole** | Long-running PHP processes that serve many requests | Highest throughput; the app must be safe to keep running (chapter 21's leaks) |

This chapter uses nginx and PHP-FPM. They're widely documented, every hosting provider supports them, and the
concepts (a worker pool, a front controller, configuration through environment variables) carry over to the others.

```text
                       ┌──────────────── host or container network ──────────────────┐
 browser ── HTTPS ──▶  │ load balancer ──HTTP──▶ nginx ──FastCGI :9000──▶ PHP-FPM     │
                       │  (TLS ends here)        │ static files          master       │
                       │                         │ from public/          ├─ worker 1  │
                       │                         ▼                       ├─ worker 2  │
                       │                     style.css                   └─ worker N  │
                       │                                                   │          │
                       │                                         SQLite / PostgreSQL  │
                       └──────────────────────────────────────────────────────────────┘
```

## php.ini for production

PHP ships two templates: `php.ini-development` and `php.ini-production`. Start from the production one, then override
what your application needs in a separate file in the `conf.d` directory, so that upgrading PHP doesn't mean merging
your edits into a new template. Bookshelf's overrides, `docker/php/production.ini`:

```ini
; Bookshelf's production settings, loaded after php.ini-production.

; Errors: log everything, show nothing. With PHP-FPM, errors go to the FPM log, which the official image
; sends to the container's standard error.
error_reporting = E_ALL
display_errors = Off
display_startup_errors = Off
log_errors = On

; Don't advertise the PHP version in an X-Powered-By header.
expose_php = Off

; Limits. Uploads aren't used, so they're kept small.
memory_limit = 128M
max_execution_time = 30
post_max_size = 2M
upload_max_filesize = 1M

; Sessions (chapter 18): reject session ids the server didn't create.
session.use_strict_mode = 1

; OPcache (chapter 21): never check files for changes. A new release is a new image, so the cache is always fresh.
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
opcache.preload = /srv/bookshelf/config/preload.php
```

The settings, grouped by why they matter:

| Group | Setting | Production value | Why |
|---|---|---|---|
| Errors | `display_errors` | `Off` | Error messages reveal paths, queries and sometimes data to visitors |
| | `log_errors`, `error_reporting` | `On`, `E_ALL` | Every warning in production is a bug you want to hear about |
| | `error_log` | unset (FPM log) or a file | Where log lines go; in containers, standard error |
| Exposure | `expose_php` | `Off` | Hides `X-Powered-By: PHP/8.5.x`; less help for attackers scanning for versions |
| Limits | `memory_limit`, `max_execution_time` | `128M`, `30` | Stop runaway requests before they take the server with them |
| | `post_max_size`, `upload_max_filesize` | As small as the app allows | Large bodies cost memory and disk |
| Sessions | `session.use_strict_mode` | `1` | Refuses session ids the server didn't issue (session fixation, chapter 18) |
| | `session.cookie_secure`, `session.cookie_httponly`, `session.cookie_samesite` | `1`, `1`, `Lax` | Unless the application sets them, as Bookshelf's `Session` class does |
| Performance | `opcache.*` | See chapter 21 | The single biggest performance setting |
| Assertions | `zend.assertions` | `-1` | Compiles `assert()` calls away entirely; `php.ini-production` already sets it |

`zend.assertions` can only be changed at startup, and `-1` is what `php.ini-production` uses. The development
template uses `1`, so that `assert()` runs during development and tests.

### What is actually in effect?

Configuration comes from several files, command-line `-d` flags, FPM pool settings (`php_admin_value`) and
`ini_set()` calls, so the question "what is `memory_limit` right now?" isn't always obvious:

```bash
php --ini                           # which php.ini and conf.d files the CLI loads
php -i | grep memory_limit          # the effective value for the CLI
php --ini=diff                      # PHP 8.5+: every setting that differs from PHP's built-in default
```

For example, with two settings changed on the command line (other lines left out):

```bash
php -d display_errors=0 -d memory_limit=256M --ini=diff
```

```text
Non-default INI settings:
display_errors: "1" -> "0"
memory_limit: "128M" -> "256M"
```

Note that PHP's *built-in* default for `display_errors` is on; only `php.ini-production` turns it off. A server running
without any `php.ini` displays errors.

Remember that the CLI and FPM usually load **different** configuration directories (on Debian, `/etc/php/8.5/cli/`
and `/etc/php/8.5/fpm/`). Checking a setting with `php -i` tells you nothing about the web server's PHP. For FPM,
use a page that calls `ini_get()` from an internal-only endpoint, or `php-fpm -i` with the same configuration.

## PHP-FPM

PHP-FPM (FastCGI Process Manager) runs a **master process** and a **pool of worker processes**. The master reads the
configuration, starts workers, restarts them when they die, and accepts FastCGI connections. Each worker handles **one
request at a time**: while a request waits for the database, its worker waits too. So the size of the pool is the
number of requests the server can serve in parallel.

Bookshelf's pool settings, `docker/php/fpm-pool.conf`:

```ini
; Bookshelf's PHP-FPM pool settings, loaded after the official image's www.conf and docker.conf.
[www]

; A fixed number of worker processes. Each handles one request at a time, so this is the number of requests
; served in parallel. Size it from memory: (container memory - overhead) / memory per worker.
pm = static
pm.max_children = 8

; Replace each worker after 1000 requests, so a slow leak in any code can't grow forever.
pm.max_requests = 1000

; Pass the container's environment variables (BOOKSHELF_*) to PHP. FPM clears them by default.
clear_env = no

; Requests taking longer than 5 s get a stack trace in the log; after 60 s the worker is killed.
request_slowlog_timeout = 5s
slowlog = /proc/self/fd/2
request_terminate_timeout = 60s

; Status and liveness pages for monitoring. nginx never routes these paths, so they're reachable only from
; inside the network, through FastCGI.
pm.status_path = /fpm-status
ping.path = /fpm-ping
```

### Process management modes

| `pm =` | Behavior | Good for |
|---|---|---|
| `static` | Always exactly `pm.max_children` workers | Containers and dedicated servers with a known memory budget |
| `dynamic` | Between `pm.min_spare_servers` and `pm.max_spare_servers` idle workers, at most `pm.max_children` in total | Servers that share memory with other services |
| `ondemand` | Starts workers per request, stops them after `pm.process_idle_timeout` | Many low-traffic sites on one server |

### Sizing the pool

The limit is memory. Measure what one worker uses under real traffic (the resident memory of the `php-fpm: pool www`
processes in `top` or `ps`, after they've served many requests), then:

```text
max_children = (memory available to PHP) / (memory per worker)

Example: a 1 GB container, 100 MB kept free for the master and OPcache's shared memory,
         workers at about 40 MB each  →  900 / 40 ≈ 22 workers
```

Too many workers and the server swaps or the container is killed for exceeding its memory limit, which is much worse
than queueing. Too few and requests queue while the CPU idles. When every worker is busy, new connections wait in the
socket's listen queue; nginx gives up after `fastcgi_read_timeout` (60 s by default) and returns `504 Gateway Timeout`,
or `502 Bad Gateway` if FPM isn't reachable at all. The FPM log warns when the pool is full:

```text
WARNING: [pool www] server reached pm.max_children setting (8), consider raising it
```

That warning is the signal to look at the slow log first: more workers don't help if each request waits five seconds
on a slow query.

### Environment variables

FPM starts workers with an empty environment by default (`clear_env = yes`), so `getenv('BOOKSHELF_DATABASE')`
returns `false` under FPM even though the container has the variable. Either set `clear_env = no`, as Bookshelf does,
or pass variables one by one with `env[BOOKSHELF_DATABASE] = $BOOKSHELF_DATABASE` in the pool file. Forgetting this is
the classic "it works on the CLI but not on the web" problem.

### Signals

The FPM master reacts to signals, which is how service managers and deployment scripts control it:

| Signal | Effect |
|---|---|
| `SIGQUIT` | Graceful stop: workers finish their current request, then exit |
| `SIGTERM`, `SIGINT` | Immediate stop |
| `SIGUSR2` | Graceful reload: re-read configuration, replace workers, reset OPcache |
| `SIGUSR1` | Reopen log files (after log rotation) |

`systemctl reload php8.5-fpm` sends `SIGUSR2` on Debian-family systems. The official Docker image sets the stop signal to
`SIGQUIT`, so `docker stop` lets requests in flight finish.

## nginx in front of PHP-FPM

nginx serves files that exist in `public/`, and sends everything else to the front controller. Bookshelf's
`docker/nginx/default.conf`:

```nginx
# nginx in front of PHP-FPM. nginx serves the files in public/ itself and sends every other request to the
# front controller, public/index.php, which runs in the "app" container.

server {
    listen 80;
    server_name _;
    root /srv/bookshelf/public;

    server_tokens off;
    client_max_body_size 2m;

    gzip on;
    gzip_types text/css application/json;

    # If nginx runs behind a load balancer or reverse proxy, uncomment and set the proxy's address range,
    # so that PHP sees the visitor's address in REMOTE_ADDR instead of the proxy's (the login throttle
    # counts failures per address). Only trust addresses you control: the header is easy to fake.
    # set_real_ip_from 10.0.0.0/8;
    # real_ip_header X-Forwarded-For;
    # real_ip_recursive on;

    # A real file in public/ (style.css): serve it. Anything else: the front controller.
    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        # Only reachable through the try_files above, so /index.php/... URLs don't exist.
        internal;

        fastcgi_pass app:9000;
        include fastcgi_params;
        # The path of the script inside the app container.
        fastcgi_param SCRIPT_FILENAME /srv/bookshelf/public/index.php;
        fastcgi_param DOCUMENT_ROOT /srv/bookshelf/public;
    }

    # No other PHP file is an entry point; never send one to PHP or serve its source.
    location ~ \.php$ {
        return 404;
    }

    # Dotfiles (.env, .git, .htaccess) should never be in public/, but if one is, don't serve it.
    location ~ /\. {
        deny all;
    }

    location ~* \.(css|js|svg|png|jpg|woff2)$ {
        expires 1d;
        access_log off;
    }
}
```

How a request flows through it:

1. `GET /style.css` matches the regex location for static files (regex locations beat the plain `/` prefix), and
   nginx serves `public/style.css` itself, with an `Expires` header one day ahead.
2. `GET /books/3` matches `location /`. `try_files` checks for a file `public/books/3`, finds none, and makes an
   internal redirect to `/index.php?…`, keeping the query string.
3. The redirected request matches `location = /index.php` (an exact match has the highest priority). nginx connects to
   `app:9000` and sends the FastCGI parameters: `REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`, headers as `HTTP_*`
   (all from the standard `fastcgi_params` file), plus `SCRIPT_FILENAME`, which tells FPM which script to run.
4. A direct request for `/index.php` also matches that exact location, but `internal` rejects it with 404. Every page
   has exactly one URL.
5. `GET /config/container.php` or `GET /shell.php` can't reach PHP: there's no such file in `public/`, so the request
   goes to the front controller, which answers 404. And even if someone managed to upload a `.php` file into `public/`,
   the `\.php$` location returns 404 instead of running it.

That last point is the key security property of the setup: **only one PHP file can ever be executed**, and
everything outside `public/` (source, configuration, the database) is unreachable over HTTP. Old configurations that
pass any `*.php` URL to FPM have caused many breaches, for example when FPM would run an uploaded image whose URL
was given a `.php` suffix.

### The visitor's address behind a proxy

When a load balancer sits in front of nginx, every request arrives from the load balancer's address. Bookshelf's login
throttle (chapter 18) counts failures per address, so all visitors would share one counter, and 20 failed logins
anywhere would lock out everyone. The load balancer passes the original address in an `X-Forwarded-For` header, and
nginx's `real_ip` module (the commented-out block) replaces `REMOTE_ADDR` with it, **but only for requests from the
trusted range**. Never trust the header unconditionally in PHP code: any client can send `X-Forwarded-For: 1.2.3.4`.

The same applies to HTTPS. When TLS ends at the load balancer, nginx and PHP see plain HTTP, and Bookshelf would
not mark its cookies `Secure` or send HSTS. Bookshelf reads an explicit setting for this:

```php
    // Behind a proxy that terminates TLS, PHP sees plain HTTP; BOOKSHELF_HTTPS=1 says the site is HTTPS anyway.
    'https' => getenv('BOOKSHELF_HTTPS') === '1' || ($_SERVER['HTTPS'] ?? 'off') !== 'off',
```

An explicit setting is safer than guessing from an `X-Forwarded-Proto` header, for the same reason.

## Containers

A container image packages the application with the exact PHP version, extensions and configuration it was tested
with, so "it works on my machine" becomes "it works in this image". Bookshelf gets two images from one
`Dockerfile`: `app` runs PHP-FPM with the code, and `web` runs nginx with the static files.

```dockerfile
# syntax=docker/dockerfile:1

# Bookshelf's production images, built from one file:
#   docker build --target app -t bookshelf-app .    PHP-FPM running the application
#   docker build --target web -t bookshelf-web .    nginx serving static files and passing the rest to app
# compose.yaml builds both.

# ---- base: PHP-FPM with the extensions Bookshelf needs ----------------------------------------------------------
FROM php:8.5-fpm-trixie AS base

# pdo_sqlite, mbstring and OPcache are part of the official image; intl needs the ICU library first.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install -j"$(nproc)" intl \
    && rm -rf /var/lib/apt/lists/*

# Start from PHP's recommended production settings, then apply Bookshelf's own.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/production.ini "$PHP_INI_DIR/conf.d/zz-bookshelf.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-bookshelf.conf

WORKDIR /srv/bookshelf

# ---- vendor: Composer dependencies, without development tools -------------------------------------------------
FROM base AS vendor

# Composer unpacks downloaded packages with unzip; git is its fallback for packages without an archive.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Dependencies change less often than code: installing them in their own layer lets Docker reuse it.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress
COPY src/ src/
RUN composer dump-autoload --no-dev --classmap-authoritative

# ---- app: the image that runs Bookshelf ---------------------------------------------------------------------------
FROM base AS app

COPY --from=vendor /srv/bookshelf/vendor/ vendor/
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
COPY templates/ templates/

# var/ holds the SQLite database; compose.yaml mounts a volume there. Only this directory is writable by PHP.
RUN mkdir var && chown www-data:www-data var
ENV BOOKSHELF_DATABASE=/srv/bookshelf/var/bookshelf.sqlite

# PHP-FPM's master process runs as www-data too, so nothing in this container runs as root.
USER www-data

# ---- web: nginx with the static files -----------------------------------------------------------------------------
FROM nginx:1.30-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/ /srv/bookshelf/public/
```

The decisions in it:

- **The official `php` image.** `php:8.5-fpm-trixie` is PHP 8.5 with FPM on Debian 13 ("trixie"); `-alpine` variants
  are smaller but use the musl C library, which occasionally behaves differently. The image provides
  `docker-php-ext-install` to compile bundled extensions such as `intl`, `pdo_pgsql` or `bcmath`. It doesn't
  install the system libraries they need, which is why `libicu-dev` comes first. The community script
  `install-php-extensions` (from the `mlocati/php-extension-installer` image) handles those dependencies
  automatically and can also install PECL extensions like `redis` or `apcu`.
- **A pinned major.minor version.** `8.5` gets security patches (`8.5.10`, `8.5.11`, …) when you rebuild, and
  never a new minor version by surprise. Pin the exact patch version, or the image digest, if you need fully
  reproducible builds.
- **Multi-stage build.** The `vendor` stage has Composer, git and unzip; the final `app` stage copies only the
  resulting `vendor/` directory, so build tools never ship. Installing from `composer.lock` with `--no-dev` gives exactly
  the tested versions, without PHPUnit and friends. (Bookshelf has no runtime dependencies yet, so this installs only
  the autoloader; the stage is ready for when it does.)
- **Layer order.** Docker caches each instruction's result and reuses it while its inputs are unchanged. Copying
  `composer.json` and `composer.lock` before the source means a code change doesn't reinstall dependencies.
- **The authoritative class map** (chapter 21), built once at build time.
- **No secrets and no state in the image.** The image is the same for every environment; configuration arrives through
  environment variables, and the database lives in a volume. `.dockerignore` keeps `vendor/`, `var/`, `.git` and
  `.env` files out of the build context:

  ```text
  # Keep the build context small, and keep local state and secrets out of images.
  .git
  .env
  vendor/
  var/
  .phpunit.cache/
  .php-cs-fixer.cache
  ```

- **Not root.** The official FPM image normally starts the master as root and drops privileges for the workers.
  Running the whole container as `www-data` means even a compromised master can't write outside `var/`. FPM logs a
  notice that the pool's `user` directive is ignored, which is expected.
- **Logs to standard error.** The official image configures FPM to write its log and PHP's errors to the container's
  output, where `docker logs` and log collectors pick them up. Nothing logs to files inside the container.
- **The tests don't run in the image.** CI runs them (chapter 20) before building. A production image with PHPUnit in it
  is bigger and has more code an attacker could use.

### Compose

`compose.yaml` describes the two containers, the network between them and the data volume:

```yaml
# Bookshelf in production-like containers:
#   docker compose up -d --build
#   docker compose run --rm app php bin/bookshelf db:migrate
# then open http://localhost:8080
name: bookshelf

services:
  app:
    build:
      context: .
      target: app
    environment:
      BOOKSHELF_TIMEZONE: Europe/Berlin
      # Set to "1" when the site is served over HTTPS by a proxy in front of nginx.
      BOOKSHELF_HTTPS: "0"
    volumes:
      - data:/srv/bookshelf/var
    restart: unless-stopped

  web:
    build:
      context: .
      target: web
    ports:
      - "8080:80"
    depends_on:
      - app
    healthcheck:
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://127.0.0.1/health"]
      interval: 30s
      timeout: 5s
      retries: 3
    restart: unless-stopped

volumes:
  data:
```

- The services reach each other by name on Compose's default network: nginx's `fastcgi_pass app:9000` resolves `app`
  to the PHP container. Only `web` publishes a port; FPM's port 9000 is never exposed to the host.
- The **named volume** `data` survives container replacement, so the SQLite database outlives each release. When the
  volume is first created, Docker copies the image's `var/` directory into it, including its `www-data` ownership.
- **Migrations run as a separate one-off command**, not automatically at container start. If several containers start
  at once, they'd all try to migrate; and a failed migration should stop the release, not leave containers
  restarting in a loop.
- `docker compose config` validates the file and prints it with all defaults filled in. It's a quick check to run in CI.

For local development, a `compose.override.yaml` (merged automatically) typically mounts the source code into the
container, uses the development `php.ini` and enables Xdebug. The production file stays free of development settings.

A single host with Compose runs many small applications well. Beyond that, the same images run unchanged on
Kubernetes, Amazon ECS, or similar platforms, where the pieces of `compose.yaml` become their equivalents: a
Deployment with replicas, a Service, a persistent volume, liveness and readiness probes, and a Job for migrations.

## Configuration and secrets

The rule from the "twelve-factor app" methodology is simple: **configuration that varies between environments lives in
the environment, not in the code.** Bookshelf reads its settings with `getenv()`:

| Variable | Meaning | Default |
|---|---|---|
| `BOOKSHELF_DATABASE` | Path to the SQLite file | `var/bookshelf.sqlite` |
| `BOOKSHELF_TIMEZONE` | The library's time zone | `Europe/Berlin` |
| `BOOKSHELF_DEBUG` | `1` shows error details | off |
| `BOOKSHELF_HTTPS` | `1` when served over HTTPS through a proxy | off |

For **secrets** (database passwords, API keys, the key for signed cookies) environment variables are convenient but
leaky. They show up in `docker inspect`, in process listings of the same user, in crash reports that dump the
environment, and in `phpinfo()` output. Container platforms can mount secrets as files instead (Docker and Compose
secrets in `/run/secrets/`, Kubernetes secret volumes). A common convention is to accept both, with `NAME_FILE`
pointing at a file:

```php
<?php

declare(strict_types=1);

/**
 * Reads a secret from NAME_FILE (a file mounted by Docker or Kubernetes) if set, otherwise from NAME.
 * Returns null when neither is set.
 */
function secret(string $name): ?string
{
    $file = getenv("{$name}_FILE");
    if ($file !== false && $file !== '') {
        $value = @file_get_contents($file);
        if ($value === false) {
            throw new RuntimeException("Cannot read the file named by {$name}_FILE");
        }

        return rtrim($value, "\r\n");     // files usually end with a newline that isn't part of the secret
    }

    $value = getenv($name);

    return $value === false ? null : $value;
}

file_put_contents(__DIR__ . '/smtp-password.txt', "s3cr3t-from-a-file\n");

putenv('SMTP_PASSWORD=s3cr3t-from-the-environment');
var_dump(secret('SMTP_PASSWORD'));

putenv('SMTP_PASSWORD_FILE=' . __DIR__ . '/smtp-password.txt');
var_dump(secret('SMTP_PASSWORD'));

var_dump(secret('API_TOKEN'));
```

```text
string(27) "s3cr3t-from-the-environment"
string(18) "s3cr3t-from-a-file"
NULL
```

Three more habits:

- **Fail at startup on missing required settings**, with a message naming the variable, instead of failing on the
  first request that needs it.
- **Never commit secrets.** `.env` files are a convenience for local development (libraries such as `vlucas/phpdotenv`
  and `symfony/dotenv` load them into the environment); keep them out of git and out of images.
- **Mark sensitive parameters** with `#[\SensitiveParameter]` (chapter 18), so a stack trace in the log doesn't
  contain the password that was being checked.

## Logs

In containers, an application logs to **standard output or standard error**, one event per line, and the platform
collects the lines. Bookshelf's error handler uses `error_log()`, which under FPM ends up in FPM's log and from there
on the container's standard error. That's enough for errors. For anything you'll search or alert on, **structured
logs** are better: one JSON object per line, with fixed fields that a log system can index.

```php
<?php

declare(strict_types=1);

/** Writes one JSON log line to standard error. Under PHP-FPM, STDERR isn't defined, so open the stream by name. */
function logEvent(string $level, string $message, array $context = []): void
{
    static $stream = null;
    $stream ??= fopen('php://stderr', 'w');
    $time = new DateTimeImmutable()->format(DATE_RFC3339_EXTENDED);       // with milliseconds
    $line = ['time' => $time, 'level' => $level, 'message' => $message] + $context;
    fwrite($stream, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
}

logEvent('warning', 'Login throttled', ['email' => 'ada@example.org', 'address' => '203.0.113.7']);
```

```text
{"time":"2026-10-11T03:36:09.624+00:00","level":"warning","message":"Login throttled","email":"ada@example.org","address":"203.0.113.7"}
```

Under FPM, `php://stderr` is the worker's standard error, which FPM forwards to its own log because the official
image sets `catch_workers_output = yes`. Real applications use a logging library implementing PSR-3
(`Psr\Log\LoggerInterface`), most often **Monolog**, which adds handlers, formatters and log levels. Whatever the tool:

- **Log events, not noise.** Errors, security events (failed logins, CSRF failures, throttling), and the start and
  end of background jobs. Don't log every successful request from PHP; nginx's access log already does.
- **Never log secrets or full personal data.** Passwords, tokens, session ids, full card numbers. Decide deliberately
  whether email addresses belong in logs; often a member id is enough.
- **Add a request id.** Pass an id from nginx (`$request_id`) to PHP as a FastCGI parameter or header and include it in
  every log line, so all lines of one request can be found together.

## Health checks

A load balancer or orchestrator needs to know whether an instance can serve traffic. Bookshelf has a health endpoint,
`GET /health`:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use PDO;
use PDOException;

/**
 * GET /health, for load balancers, container orchestrators and uptime monitors: 200 when the application can
 * reach its database, 503 when it can't. Anything other than a 2xx counts as unhealthy.
 */
final readonly class HealthController
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    #[Route('GET', '/health')]
    public function check(): Response
    {
        try {
            $this->pdo->query('SELECT 1');
            $response = Response::json(['status' => 'ok']);
        } catch (PDOException $e) {
            error_log('Health check failed: ' . $e->getMessage());
            $response = Response::json(['status' => 'unavailable'], 503);
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }
}
```

It's registered in `config/container.php` with `$router->addController(Controller\HealthController::class)`, and the
functional tests check it:

```php
    public function testTheHealthCheckReportsOk(): void
    {
        $response = $this->get('/health');

        self::assertSame(200, $response->status);
        self::assertSame('{"status":"ok"}', $response->body);
        self::assertSame('no-store', $response->header('Cache-Control'));
    }
```

The request passes through nginx, FPM, the kernel, and a database query, so a 200 means the whole chain works. Two
kinds of checks are worth distinguishing on platforms that support both:

- **Liveness**: "is the process stuck?" Failing it restarts the container. Keep it cheap and independent of other
  services, or one database outage restarts every container in a loop. FPM's `ping.path` is a liveness check for
  FPM itself.
- **Readiness**: "should this instance receive traffic?" Failing it only takes the instance out of rotation. Checking
  the database belongs here.

Health endpoints should reveal nothing sensitive. "ok" or "unavailable" is enough; details go to the log.

## Scheduled jobs and background workers

Not everything belongs in a web request. Some work runs **on a schedule**, and some is **queued** to run soon after a
request, in a separate long-running process.

### Scheduled jobs

Bookshelf's daily overdue report is a CLI command, `loans:overdue`:

```bash
php bin/bookshelf loans:overdue
```

```text
Loan  Due         Days  Fine   Title    Member
----  ----------  ----  -----  -------  -----------------
1     2026-09-22  19    $4.75  Dune     ada@example.org
2     2026-10-01  10    $2.50  Emma     ada@example.org
3     2026-10-01  10    $2.50  Kindred  grace@example.org
```

```bash
php bin/bookshelf loans:overdue --json
```

```text
[
    {
        "loan": 1,
        "due": "2026-09-22",
        "days": 19,
        "fine": "$4.75",
        "title": "Dune",
        "member": "ada@example.org"
    },
    ...
]
```

Its core:

```php
    public function run(Input $input, Output $output): ExitCode
    {
        $today = $this->clock->now();
        $overdue = $this->loans->overdue($today);
        $books = $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $overdue));

        /** @var array<int, Member> $members */
        $members = [];
        $rows = [];
        foreach ($overdue as $loan) {
            $member = $members[$loan->memberId] ??= $this->members->get($loan->memberId);
            $rows[] = [
                'loan' => $loan->id,
                'due' => $loan->dueOn->format('Y-m-d'),
                'days' => $loan->daysOverdue($today),
                'fine' => $loan->fine($today)->format(),
                'title' => $books[$loan->bookId]->title ?? "(book {$loan->bookId})",
                'member' => $member->email,
            ];
        }

        if ($input->flag('json')) {
            $output->json($rows);
        } elseif ($rows === []) {
            $output->success('No overdue loans');
        } else {
            $output->table(
                ['Loan', 'Due', 'Days', 'Fine', 'Title', 'Member'],
                array_map(fn (array $row): array => array_map(strval(...), array_values($row)), $rows),
            );
        }

        return ExitCode::Success;
    }
```

The books for all loans come from one `findByIds()` query (no N+1, chapter 21), and members are cached per id within
the run. `tests/Integration/OverdueCommandTest.php` runs it against an in-memory database with a frozen clock, writing
to `php://memory` streams instead of the terminal.

Something has to start it every morning. The options, from simplest to most managed:

```text
# crontab on a server: every day at 07:00; flock skips a run if the previous one is still going
0 7 * * *  flock -n /tmp/bookshelf-overdue.lock php /srv/bookshelf/current/bin/bookshelf loans:overdue >> /var/log/bookshelf/overdue.log 2>&1

# with Compose, from the host's crontab
0 7 * * *  docker compose -f /srv/bookshelf/compose.yaml run --rm app php bin/bookshelf loans:overdue
```

systemd timers, Kubernetes `CronJob`s and cloud schedulers do the same with better logging and retry options. Whatever
runs it, a scheduled command should: exit with a non-zero code on failure (so the scheduler can alert), be safe to run
twice (overlaps and retries happen), and log what it did.

### Background workers

Work that is slow or unreliable, like sending email, resizing images or calling third-party APIs, shouldn't make a visitor
wait. The request puts a **job** on a queue (a database table, Redis, RabbitMQ, Amazon SQS) and returns; a **worker**
process takes jobs off the queue and runs them. Frameworks provide this (Symfony Messenger, Laravel Queues), but the
shape of a worker is always the same:

```php
<?php

declare(strict_types=1);

// The shape of a long-running worker: take a job, do it, repeat; stop cleanly when asked.

pcntl_async_signals(true);              // handle signals as soon as they arrive, not only at pcntl_signal_dispatch()

$stopping = false;
$stop = function (int $signal) use (&$stopping): void {
    fwrite(STDERR, "received signal $signal, finishing the current job\n");
    $stopping = true;
};
pcntl_signal(SIGTERM, $stop);           // sent by docker stop, systemd, Kubernetes
pcntl_signal(SIGINT, $stop);            // Ctrl+C

$maxJobs = 500;                         // then exit and let the supervisor start a fresh process
$memoryLimit = 64 * 1024 * 1024;

/** Stands in for "fetch the next job from a queue"; returns null when there is nothing to do. */
function nextJob(int $n): ?string
{
    return $n % 3 === 0 ? null : "job $n";
}

for ($n = 1; !$stopping && $n <= $maxJobs && memory_get_usage() < $memoryLimit; $n++) {
    $job = nextJob($n);
    if ($job === null) {
        sleep(1);                       // idle; a signal interrupts the sleep early
        continue;
    }
    fwrite(STDERR, "processing $job\n");
    usleep(300_000);                    // the work itself
}

fwrite(STDERR, sprintf("worker exiting after %d iterations\n", $n - 1));
exit(0);
```

Started, then stopped with `kill -TERM` after two seconds:

```text
processing job 1
processing job 2
processing job 4
processing job 5
received signal 15, finishing the current job
worker exiting after 5 iterations
```

The essentials:

- **Graceful shutdown.** On `SIGTERM`, finish the current job, then exit. A worker killed mid-job leaves it half done.
  The `pcntl` extension is CLI-only and not available on Windows; container images for workers need it installed
  (`docker-php-ext-install pcntl`).
- **Bounded lifetime.** Exit after a number of jobs or above a memory threshold, and let a **supervisor** (systemd,
  Supervisor, a container's restart policy, Kubernetes) start a new process. This contains the leaks of chapter 21.
- **Idempotent jobs.** Queues deliver *at least once*: a job may run twice if a worker dies after doing the work but
  before acknowledging it. "Send the overdue reminder for loan 42 for 2026-10-11" should check whether that reminder
  was already sent.
- **Fresh state per job.** A worker keeps objects across jobs. Reset per-job state, and reconnect to the database when
  the connection drops (long idle connections are closed by servers).
- **Same code, same image.** A worker runs the application's own CLI entry point from the same image as the web
  containers, with a different command.

## Releasing without downtime

A release replaces running code with new code. Done naively, some requests fail: they arrive while files are
half-copied, or while the old process has stopped and the new one hasn't started.

### With containers

Start new containers from the new image, wait until their health checks pass, shift traffic to them, then stop the old
ones gracefully (FPM finishes in-flight requests on `SIGQUIT`). Orchestrators call this a **rolling update**. On a
single Compose host, `docker compose up -d` replaces containers one by one but has a short gap; a reverse proxy in
front of two copies of the app gets you to zero.

### On a server: release directories and a symlink

Without containers, the classic pattern (used by tools like Deployer) keeps each release in its own directory and
switches a symlink:

```text
/srv/bookshelf/
├── releases/
│   ├── 20261010T091500/        previous release, kept for rollback
│   └── 20261011T093000/        new release: code, vendor/, built assets
├── shared/
│   └── var/                    the database and other state, symlinked into each release
└── current -> releases/20261011T093000
```

1. Upload the new release to a new directory, run `composer install --no-dev --classmap-authoritative` there, and link
   `shared/var` into it.
2. Run database migrations (see below).
3. Switch `current` to the new directory in one atomic step: create the new link under a temporary name, then rename
   it over the old one (`ln -sfn releases/… current.tmp && mv -T current.tmp current`).
4. Reload PHP-FPM gracefully (`systemctl reload php8.5-fpm`), which resets OPcache. With
   `opcache.validate_timestamps=0`, PHP would otherwise keep running the old code.
5. Keep the last few releases. Rolling back means pointing `current` at the previous one and reloading again.

One detail makes this work: nginx and PHP must resolve the symlink when a request starts, so that a request runs
entirely in the old release or entirely in the new one. In nginx, pass the resolved path:

```nginx
    root /srv/bookshelf/current/public;

    location = /index.php {
        internal;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }
```

`$realpath_root` is the document root with symlinks resolved, so PHP sees
`/srv/bookshelf/releases/20261011T093000/public/index.php`, and OPcache and the realpath cache key the new files
separately from the old ones.

### Migrations that don't break running code

During any rolling release, old and new code run at the same time against the same database. So a migration must work
with **both** versions. The pattern is **expand, then contract**, spread over several releases. Renaming the column
`members.name` to `full_name`, for example:

| Release | Database change | Code |
|---|---|---|
| 1 (expand) | Add `full_name`; copy `name` into it | Writes both columns, reads `name` |
| 2 | (none) | Reads and writes `full_name`; still writes `name` for release 1 instances during rollout |
| 3 (contract) | Drop `name` | Uses only `full_name` |

The same applies to dropping tables, adding `NOT NULL` columns (add with a default first), and changing types. Two
more rules: migrations run *before* the new code receives traffic, and they're never edited after they've run anywhere.
Bookshelf's `Migrator` records applied files, and the `migrations/` directory only ever grows.

## Upgrading PHP

Each PHP minor version is supported for four years from its release: two years of bug fixes, then two of security fixes
only. The state on 2026-10-11 (from php.net's supported versions page):

| Version | Released | Bug fixes until | Security fixes until |
|---|---|---|---|
| 8.2 | 2022-12-08 | 2024-12-31 | 2026-12-31 |
| 8.3 | 2023-11-23 | 2025-12-31 | 2027-12-31 |
| 8.4 | 2024-11-21 | 2026-12-31 | 2028-12-31 |
| 8.5 | 2025-11-20 | 2027-12-31 | 2029-12-31 |

PHP 8.6 is in its release-candidate phase as of this writing, and new versions are usually released in late November.
Running an unsupported version means running with known, unfixed security holes, so plan upgrades as routine work,
roughly once a year:

1. **Keep the current version clean.** With `failOnDeprecation` in PHPUnit (chapter 20) and logs watched for
   `E_DEPRECATED`, today's deprecations are fixed long before the version that removes the feature.
2. **Read the migration guide** for the new version in the PHP manual (`Appendices → Migrating from PHP 8.5.x to PHP
   8.6.x`) and the `UPGRADING` file in the release.
3. **Check your dependencies.** `composer why-not php 8.6.0` lists the packages whose constraints don't allow it yet.
   Update them first, on the current PHP.
4. **Analyse for the new version.** Set `phpVersion` in PHPStan to the new version and fix what it reports. Rector's
   PHP-version rule sets can automate many changes.
5. **Run the tests on both versions in CI** (a matrix of PHP versions) during the transition.
6. **Change `composer.json`** (`"php": ">=8.6"` and `config.platform.php`), the base image tag, and the servers'
   packages, then deploy as usual.

Bookshelf's `composer.json` sets `config.platform.php` to `8.5.0`:

```json
    "config": {
        "sort-packages": true,
        "platform": {
            "php": "8.5.0"
        }
    },
```

This makes Composer resolve dependencies as if it ran on PHP 8.5.0, whatever PHP the developer has installed, so
`composer update` never locks a package version that production's PHP can't run. Bookshelf now also commits its
`composer.lock`, which the Dockerfile installs from. An application commits its lock file; a library doesn't.

## Key takeaways

- nginx with PHP-FPM is the common production setup: nginx serves static files and passes only the front controller
  to FPM. Other options (Caddy, FrankenPHP, RoadRunner, Swoole) use the same ideas.
- Start from `php.ini-production`, override in a `conf.d` file: errors logged but never displayed, `expose_php=Off`,
  tight limits, strict sessions, OPcache tuned with `validate_timestamps=0`. `php --ini=diff` (8.5+) shows what
  differs from the defaults.
- An FPM worker handles one request at a time. Size `pm.max_children` from memory per worker, recycle workers with
  `pm.max_requests`, use the slow log, and remember `clear_env` hides environment variables by default.
- Only `public/index.php` may ever run as PHP. Pass the visitor's real address with nginx's `real_ip` module from
  trusted proxies only, and configure HTTPS explicitly when TLS ends at a proxy.
- Build images in stages: extensions and configuration in a base, dependencies from `composer.lock` with `--no-dev`
  and an authoritative class map, no tests, no secrets, no root. Keep state in volumes and logs on standard error.
- Configuration comes from the environment; secrets preferably from mounted files (`NAME_FILE`). Never commit them.
- Log structured events, never secrets. Health endpoints check what an instance needs to serve traffic.
- Scheduled commands must report failures through exit codes and be safe to run twice. Workers stop gracefully on
  `SIGTERM`, exit after a bounded number of jobs, and treat jobs as idempotent.
- Zero-downtime releases: rolling container updates, or release directories with an atomic symlink switch,
  `$realpath_root` and a graceful FPM reload. Database changes follow expand-then-contract.
- Upgrade PHP yearly: fix deprecations continuously, check dependencies with `composer why-not`, analyse and test on
  both versions, and pin the target with `config.platform.php`.

## Exercises

1. **Audit a configuration.** A page on a colleague's production server (behind a login, on an internal address)
   prints these values with `ini_get()`, so they're what PHP-FPM really uses. List what you'd change and why, in order
   of importance.

   ```text
   display_errors           1
   error_reporting          22527
   max_execution_time       0
   memory_limit             -1
   opcache.enable           0
   session.use_strict_mode  0
   upload_max_filesize      512M
   ```

<details>
<summary>Solution</summary>

In order of importance:

1. **`display_errors` is on.** Visitors see error messages with file paths, SQL and possibly data. Set
   `display_errors = Off` and `log_errors = On`. This is what you get when PHP runs without a `php.ini`, since the
   built-in default is on.
2. **`memory_limit = -1` and `max_execution_time = 0`.** One runaway request (an accidental query returning a million
   rows, an infinite loop) can take all the server's memory or hold a worker forever. Restore `128M` and `30`, and
   raise them only for specific scripts that need it.
3. **OPcache is disabled.** Every request recompiles every file. Enable it and tune it (chapter 21).
4. **`error_reporting = 22527`** is `E_ALL & ~E_DEPRECATED & ~E_STRICT` (32767 − 8192 − 2048). Deprecation warnings
   are exactly what tells you what the next PHP upgrade will break. Use `E_ALL`, and log them.
5. **`session.use_strict_mode = 0`** accepts session ids invented by the client, which enables session fixation. Set
   it to `1`.
6. **`upload_max_filesize = 512M`.** Unless the application accepts large uploads, this only invites abuse. Set it to
   what the application needs, together with `post_max_size` and nginx's `client_max_body_size`.

Then find out *how* the server got this way (no `php.ini` at all, or the development template), and make the
configuration part of the deployment so it can't drift again.

</details>

2. **Size the pool.** Bookshelf will run in a container with a 512 MB memory limit. Under load, the FPM workers'
   resident memory settles at 30–35 MB each, and OPcache's shared memory is 128 MB (counted once, not per worker).
   Choose `pm` and `pm.max_children`, and explain what visitors experience at 3× the normal traffic if every request
   takes 50 ms of PHP time and 150 ms waiting for the database.

<details>
<summary>Solution</summary>

Shared memory counts once: 512 − 128 (OPcache) − ~30 (master, nginx isn't in this container, some headroom) ≈ 350 MB
for workers. At the high end of the measurement, 35 MB: 350 / 35 = 10 workers. Use `pm = static` with
`pm.max_children = 10`, since a container has a fixed budget and nothing else to share it with. (Some memory measured
per worker is shared OPcache memory counted again in each process's resident size; measuring the container's total
under load is the more reliable check.)

Each request occupies a worker for 200 ms (50 ms PHP + 150 ms waiting), so 10 workers serve 10 / 0.2 = 50 requests per
second at most. If normal traffic is 15 requests per second, 3× is 45: the pool is at 90% capacity, and response times
start to grow as requests wait for a free worker. Beyond 50 per second, the queue grows without bound until nginx's
timeout returns 504s. Note that the CPU is mostly idle in this situation (75% of each request is waiting), so the
better fix is usually to reduce the waiting (a faster query, caching) rather than adding workers, which costs memory.
Adding a second container doubles capacity if the database can handle it.

</details>

3. **Use the secret helper.** Bookshelf is going to send overdue reminders by email through an SMTP server. Write a
   function `smtpSettings(): array` that returns host, port, user and password, reading the password with `secret()`
   and the others with `getenv()`, failing with a clear message when the host or password is missing. Show how
   `compose.yaml` would pass the password as a Compose secret.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function secret(string $name): ?string
{
    $file = getenv("{$name}_FILE");
    if ($file !== false && $file !== '') {
        $value = @file_get_contents($file);
        if ($value === false) {
            throw new RuntimeException("Cannot read the file named by {$name}_FILE");
        }

        return rtrim($value, "\r\n");
    }

    $value = getenv($name);

    return $value === false ? null : $value;
}

/** @return array{host: string, port: int, user: string, password: string} */
function smtpSettings(): array
{
    $host = getenv('BOOKSHELF_SMTP_HOST') ?: throw new RuntimeException('Set BOOKSHELF_SMTP_HOST');
    $password = secret('BOOKSHELF_SMTP_PASSWORD')
        ?? throw new RuntimeException('Set BOOKSHELF_SMTP_PASSWORD or BOOKSHELF_SMTP_PASSWORD_FILE');

    return [
        'host' => $host,
        'port' => (int) (getenv('BOOKSHELF_SMTP_PORT') ?: 587),
        'user' => getenv('BOOKSHELF_SMTP_USER') ?: 'bookshelf',
        'password' => $password,
    ];
}

try {
    smtpSettings();
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}

putenv('BOOKSHELF_SMTP_HOST=smtp.example.org');
file_put_contents(__DIR__ . '/smtp_password', "correct horse\n");
putenv('BOOKSHELF_SMTP_PASSWORD_FILE=' . __DIR__ . '/smtp_password');
print_r(smtpSettings());
```

```text
Set BOOKSHELF_SMTP_HOST
Array
(
    [host] => smtp.example.org
    [port] => 587
    [user] => bookshelf
    [password] => correct horse
)
```

In `compose.yaml`, a secret is declared at the top level and granted to a service; Compose mounts it at
`/run/secrets/<name>`:

```yaml
services:
  app:
    environment:
      BOOKSHELF_SMTP_HOST: smtp.example.org
      BOOKSHELF_SMTP_PASSWORD_FILE: /run/secrets/smtp_password
    secrets:
      - smtp_password

secrets:
  smtp_password:
    file: ./secrets/smtp_password.txt     # on the host, outside git; or `environment:` to take it from a variable
```

The password never appears in `docker inspect`'s environment list, and the application code doesn't care which way it
was provided. In real code, call `smtpSettings()` once at startup (in the container configuration) so a missing
setting fails the deployment, not the first reminder.

</details>

4. **Schedule the report safely.** Write a crontab entry and a small wrapper script that runs `loans:overdue --json`
   every day at 07:00, keeps the output in a dated file, never runs twice at the same time, and makes cron send an
   email only when the command fails. Explain what happens on a day when the command crashes.

<details>
<summary>Solution</summary>

```bash
#!/bin/sh
# /srv/bookshelf/bin-ops/overdue-report.sh
set -eu
out="/var/log/bookshelf/overdue-$(date +%F).json"
# --json output goes to the file; diagnostics (STDERR) go to cron, which mails them.
php /srv/bookshelf/current/bin/bookshelf loans:overdue --json > "$out.tmp"
mv "$out.tmp" "$out"
```

```text
MAILTO=library-ops@example.org
0 7 * * *  flock -n /run/lock/bookshelf-overdue.lock /srv/bookshelf/bin-ops/overdue-report.sh
```

- `flock -n` takes a lock or exits at once, so a run that hangs doesn't pile up copies the next day.
- Cron mails whatever a job prints. The JSON goes to a file, and the command only writes to standard error (where
  Bookshelf's `Output` sends messages) when something is wrong, so a quiet day sends no mail.
- `set -e` stops the script at the first failing command. Writing to `.tmp` first and renaming means a crash never
  leaves a half-written report under the final name.

On a crash, `bin/bookshelf` catches the exception, prints `Error: …` to standard error and exits with code 1
(`ExitCode::Failure`). The script stops before the `mv`, cron mails the error text to `MAILTO`, and the previous day's
report is untouched. On a platform scheduler (a Kubernetes `CronJob`, a systemd timer), the non-zero exit code marks
the run as failed, which is what alerts are configured on.

</details>

5. **Rename a column without downtime.** The column `books.year` should be called `published_year`. SQLite has
   `ALTER TABLE … RENAME COLUMN` (3.25+), so why can't a single migration do it during a rolling release? Write the
   sequence of releases, with the SQL for each migration and what the code does in each, such that every release can
   run alongside the previous one.

<details>
<summary>Solution</summary>

A single `RENAME COLUMN` breaks the instances still running the old code: from the moment the migration runs, every
query they send that mentions `year` fails, until the rollout finishes. So the change is spread over four releases.

**Release 1 (expand).** `migrations/004_add_books_published_year.sql`:

```sql
-- Release 1 (expand): the new column, filled from the old one. Both stay until release 4.
ALTER TABLE books ADD COLUMN published_year INTEGER;

UPDATE books SET published_year = year;
```

Code: `PdoBookRepository::save()` writes both `year` and `published_year`; reads still use `year`.

**Release 2: backfill, switch reads.** While release 1 was rolling out, release 0 instances kept inserting books with
only `year`. `migrations/005_backfill_books_published_year.sql` fixes them:

```sql
-- Release 2: books added by release 0 instances while release 1 was rolling out have only "year".
UPDATE books SET published_year = year
WHERE published_year IS NULL AND year IS NOT NULL;
```

Code: reads use `published_year`; writes still fill both, because release 1 instances (still reading `year`) run
alongside during this rollout.

**Release 3: stop writing the old column.** No migration. The code no longer mentions `year`. That's safe because the
column is nullable and release 2 instances don't read it.

**Release 4 (contract).** `migrations/006_drop_books_year.sql`:

```sql
-- Release 4 (contract): no running code uses "year" any more.
ALTER TABLE books DROP COLUMN year;
```

Running the migrations in this order against a database where a "release 0" insert happens between 004 and 005
shows the backfill doing its job (output condensed):

```text
after 004:  Dune 1965/1965   Emma 1815/(null)   Ubik 1969/1969
after 005:  Dune 1965/1965   Emma 1815/1815     Ubik 1969/1969
after 006:  Dune 1965        Emma 1815          Ubik 1969
```

Two SQLite details: `DROP COLUMN` (3.35+) refuses columns that are part of an index, a `UNIQUE` or `CHECK`
constraint, a foreign key, or a view. `year` has none of these. A constrained column needs a table rebuild instead
(create a new table, copy, drop, rename), which must run with foreign keys switched off, *outside* a transaction; it
doesn't fit Bookshelf's one-transaction-per-file `Migrator`, which is a good reason to keep columns you might drop free
of constraints, or to accept a short maintenance window for that one step.

Each step is safe because, at any moment, every running version finds the columns it uses. Rolling back one release is
also safe, which is the real test of such a plan.

</details>

6. **Review a Dockerfile.** Find at least six problems in this Dockerfile for a PHP application and explain how
   Bookshelf's avoids each.

   ```dockerfile
   FROM php:latest
   COPY . /var/www/html
   WORKDIR /var/www/html
   RUN curl -sS https://getcomposer.org/installer | php && php composer.phar install
   ENV DB_PASSWORD=hunter2
   RUN chmod -R 777 /var/www/html
   CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]
   ```

<details>
<summary>Solution</summary>

1. **`php:latest`** changes meaning over time: a rebuild might jump to a new PHP version, or to a different variant.
   Bookshelf pins `php:8.5-fpm-trixie`.
2. **The built-in server in production.** `php -S` serves one request at a time and isn't hardened. Bookshelf runs
   PHP-FPM behind nginx.
3. **`COPY .` before installing dependencies** invalidates the dependency layer on every code change, and copies
   whatever is in the directory, including `.git`, `.env` and local `vendor/`. Bookshelf copies `composer.json` and
   `composer.lock` first, the code later, and has a `.dockerignore`.
4. **Composer installed and run in the final image, with dev dependencies.** The image ships Composer, PHPUnit and
   friends, and the installer is fetched without checking its signature. Bookshelf uses a separate `vendor` stage with
   the official `composer` image, `--no-dev`, and an authoritative class map.
5. **A secret in an `ENV` instruction** is baked into the image's layers and metadata, visible to anyone who can pull
   it, and identical in every environment. Bookshelf's image contains no secrets; they come from the environment or
   mounted files at run time.
6. **`chmod -R 777`** makes the application's code writable by any process, so an attacker who can write a file can
   change the code. Bookshelf makes only `var/` writable, by `www-data`.
7. **Running as root** (no `USER`): any compromise of the PHP process is root in the container. Bookshelf sets
   `USER www-data`.
8. **No production `php.ini`**: the image's PHP runs without a `php.ini`, so it uses PHP's built-in defaults, which
   display errors. Bookshelf copies `php.ini-production` and adds its own overrides.

</details>

---
← Previous: [Performance and internals](21-performance-and-internals.md) | Next: [A tour of the major frameworks](23-a-tour-of-the-major-frameworks.md) →
