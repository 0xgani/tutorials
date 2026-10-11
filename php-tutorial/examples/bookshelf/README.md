# Bookshelf

A small lending-library application, built step by step in [the PHP tutorial](../../README.md) and assembled in
[chapter 24](../../24-capstone-project.md). Members browse the catalog, log in to see and renew their loans and
suggest books; staff lend and take back books at the lending desk and see what is overdue. There is a read-only JSON
API for the catalog and a command-line tool for administration.

It uses no framework, only PHP 8.5 with the `intl`, `mbstring` and `pdo_sqlite` extensions. The development tools
(PHPUnit, PHPStan, PHP-CS-Fixer) come from Composer.

## Quick start

```bash
composer install
php bin/bookshelf db:migrate
php bin/bookshelf import data/books.csv
php bin/bookshelf member:add "Grace Hopper" grace@example.org --type=staff
php bin/bookshelf member:password grace@example.org      # type a password of at least 15 characters
composer serve                                           # http://localhost:8000
```

The database is `var/bookshelf.sqlite` unless `BOOKSHELF_DATABASE` names another file.

| Variable | Default | Meaning |
|---|---|---|
| `BOOKSHELF_DATABASE` | `var/bookshelf.sqlite` | SQLite database file (`:memory:` in tests) |
| `BOOKSHELF_TIMEZONE` | `Europe/Berlin` | The library's time zone, for due dates |
| `BOOKSHELF_HTTPS` | `0` | `1` when a proxy in front serves the site over HTTPS (secure cookies, HSTS) |
| `BOOKSHELF_DEBUG` | `0` | `1` shows exception details on error pages. Never in production |

## Checks

```bash
composer test       # PHPUnit: unit, integration and functional suites
composer analyse    # PHPStan, level 8
composer cs         # coding style (composer cs:fix to fix it)
```

`.github/workflows/ci.yml` runs the same checks on GitHub once this directory is a repository of its own.

## Running in containers

```bash
docker compose up -d --build
docker compose run --rm app php bin/bookshelf db:migrate
```

Then open <http://localhost:8080>. `Dockerfile`, `compose.yaml`, `docker/nginx/default.conf` and `docker/php/` are
explained in chapter 22.

## Layout

```text
bin/bookshelf        the command-line tool
config/              service wiring (container.php) and OPcache preloading
data/books.csv       sample catalog
docker/              nginx, PHP and PHP-FPM configuration for the containers
migrations/          numbered SQL files, applied by db:migrate
public/              the web root: index.php (front controller) and style.css
src/Cli/             the command-line application and its commands
src/Container/       the dependency-injection container
src/Domain/          entities, value objects, enums and repository interfaces: the library's rules
src/Http/            request, response, router, middleware pipeline
src/Lending/         use cases that span several objects: lending, returns, renewals, the overdue report
src/Persistence/     PDO implementations of the repositories, migrations, the database connection
src/Security/        passwords, login throttling, CSRF tokens
src/Support/         clocks and small helpers
src/Web/             controllers, middleware, session and views for the web application
templates/           PHP templates
tests/               PHPUnit tests: Unit, Integration, Functional, and Support helpers
```
