# The PHP Tutorial — from first script to production

A long-form, text-only tutorial that takes you from "what is PHP?" to designing, building, testing, securing and
deploying real PHP applications. It covers the language in depth (types, functions, objects, enums, generators,
errors), how PHP actually runs (the request lifecycle, the engine, OPcache), and the work around the code (Composer,
databases, security, testing, deployment). It closes with a tour of the major frameworks and a capstone project.

The tutorial targets **PHP 8.5**, the current stable release (first released November 2025). Features that arrived
recently are labelled with the version that introduced them, for example "PHP 8.4+" or "PHP 8.5+", so you know what
works on an older server.

## Who it's for

- You can already program in at least one language. You know what a variable, a loop, a function and an object are.
- You want to become *skilled* in PHP, not just able to read it: able to structure a codebase, choose the right tool,
  find bugs quickly, avoid the classic security holes and run PHP in production.

No previous PHP experience is needed. Every chapter explains PHP on its own terms. Readers who know JavaScript and
Node.js will find a few short optional notes (see "Markers" below) that connect an idea to something they already know,
but the main text never depends on them.

## Prerequisites

| You need | Why | Check |
|---|---|---|
| PHP 8.5 (8.3+ works for most chapters) | Running every example | `php --version` |
| A terminal | All examples run from the command line or the built-in web server | |
| A text editor with PHP support | VS Code with a PHP extension, PhpStorm, or similar | |
| Composer 2 (from chapter 12) | Dependency manager and autoloader | `composer --version` |
| SQLite support in PHP (chapter 17) | The database chapters use SQLite, so no server is needed | `php -m \| grep -i sqlite` |
| Optional: PostgreSQL | One section of chapter 17 shows the same code against PostgreSQL | `psql --version` |
| Optional: Docker | Chapter 22 packages the app as containers | `docker --version` |

Chapter 1 shows how to install PHP on macOS, Linux and Windows.

## How to use this tutorial

- **Read in order.** Each chapter builds on the previous ones, and the running example grows as you go.
- **Type the examples and run them.** Most snippets are complete scripts: save them as a `.php` file and run
  `php file.php`. Where output matters, it is shown in a `text` block right after the code.
- **Do the exercises.** Every chapter ends with 3–6 exercises from easy to hard. Solutions are hidden in
  collapsible "Solution" sections. Try first, then compare.
- **Keep the manual open.** The official manual at <https://www.php.net/manual/en/> is excellent. Every function in
  this tutorial has a page there (`https://www.php.net/<function_name>` jumps straight to it).

### The running example: Bookshelf

Throughout the tutorial you build **Bookshelf**, a small app for a community lending library: members borrow books,
loans fall due, overdue loans cost a fine. It starts as a few arrays in a script, becomes a set of classes and enums,
turns into a Composer project with a CLI importer, gets a web interface and a SQLite database, gains logins and tests,
and is finally deployed. The capstone (chapter 24) assembles the complete app; its full source is in
[`examples/bookshelf/`](examples/bookshelf/).

## Markers

Two markers appear throughout. They always use the same wording, so you can search for them.

> **Just a taste — you don't need to learn or fully understand this now.** This marks a snippet that only shows that a
> feature exists. A later chapter (named in the note) teaches it properly.

> **If you know Node.js:** This marks an optional one-to-three-sentence note for readers who know JavaScript and
> Node.js. Skip it freely; nothing else depends on it.

Snippets without a marker are meant to be learned.

## Table of contents

### Part I — The language

1. [Getting started](01-getting-started.md) — what PHP is, installing it, running scripts, the REPL and the built-in
   web server, how a PHP file is parsed.
2. [Variables, types and values](02-variables-types-and-values.md) — scalar types, `null`, `var_dump`, type juggling,
   casting, constants, references to the engine's value model.
3. [Operators and control flow](03-operators-and-control-flow.md) — comparison (`==` vs `===`), null-safe and
   null-coalescing operators, `if`, `match`, `switch`, loops, alternative syntax.
4. [Strings and text](04-strings-and-text.md) — quoting rules, interpolation, heredoc/nowdoc, formatting, byte strings
   vs UTF-8, the `mbstring` functions, regular expressions with PCRE.
5. [Arrays](05-arrays.md) — PHP's ordered hash map, lists vs maps, copy-on-write, destructuring, spread, the array
   function toolkit, sorting, `array_find()` (8.4+) and `array_first()` (8.5+).
6. [Functions and closures](06-functions-and-closures.md) — parameters, defaults, named arguments, variadics,
   by-reference parameters, scope, closures, arrow functions, first-class callables, the pipe operator (8.5+).
7. [The type system and strict types](07-the-type-system-and-strict-types.md) — declarations, `strict_types`, union,
   intersection and DNF types, `mixed`, `never`, `void`, coercion rules and how to design with types.
8. [Errors and exceptions](08-errors-and-exceptions.md) — the `Throwable` hierarchy, `try`/`catch`/`finally`, custom
   exceptions, warnings and notices, error handlers, logging, and how errors behave in production.

### Part II — Objects and program structure

9. [Classes and objects](09-classes-and-objects.md) — properties, methods, constructors and promotion, visibility,
   static members, class constants, `readonly`, object handles, cloning, value objects.
10. [Inheritance, interfaces and traits](10-inheritance-interfaces-and-traits.md) — `extends`, abstract classes,
    interfaces, traits, `final`, `#[\Override]`, late static binding, composition over inheritance.
11. [Enums, attributes and modern object features](11-enums-attributes-and-modern-objects.md) — enums, readonly classes,
    property hooks and asymmetric visibility (8.4+), clone-with (8.5+), attributes, magic methods.
12. [Namespaces, autoloading and Composer](12-namespaces-autoloading-and-composer.md) — namespaces and `use`, PSR-4
    autoloading, `composer.json`, versions and the lock file, scripts, the PSR standards.
13. [Iterators, generators and fibers](13-iterators-generators-and-fibers.md) — `foreach` over objects, the `Iterator`
    interfaces, generators and `yield`, lazy pipelines, the SPL data structures, fibers.

### Part III — Working with the outside world

14. [Files, streams and the command line](14-files-streams-and-the-cli.md) — reading and writing files safely, streams
    and wrappers, JSON and CSV, writing CLI tools (arguments, STDIN/STDOUT, exit codes).
15. [Dates, times and internationalization](15-dates-times-and-internationalization.md) — `DateTimeImmutable`, time
    zones, intervals and periods, clocks you can test, the `intl` extension.
16. [PHP on the web](16-php-on-the-web.md) — SAPIs and the request lifecycle, the shared-nothing model, superglobals,
    headers and status codes, forms, cookies and sessions, uploads, the URI extension (8.5+).
17. [Databases with PDO](17-databases-with-pdo.md) — connecting, prepared statements, fetching, transactions,
    migrations, the repository pattern, SQLite and PostgreSQL.
18. [Security](18-security.md) — SQL injection, XSS and output escaping, CSRF, password hashing, sessions and
    authentication, file uploads, secrets, cryptography with `random_bytes()` and sodium.

### Part IV — Building and shipping real applications

19. [Building an app without a framework](19-building-an-app-without-a-framework.md) — front controller, routing,
    request/response objects, middleware, a dependency-injection container, templates, the PSR-7/15/11 interfaces.
20. [Testing and static analysis](20-testing-and-static-analysis.md) — PHPUnit, test doubles, data providers, testing
    code that touches time and databases, PHPStan, coding standards, Xdebug.
21. [Performance and internals](21-performance-and-internals.md) — how the engine compiles and runs code, zvals and
    reference counting, copy-on-write, garbage collection, OPcache, preloading, the JIT, profiling.
22. [Deployment and production](22-deployment-and-production.md) — `php.ini` for production, PHP-FPM and nginx,
    containers, configuration and secrets, logs, background workers, zero-downtime releases, upgrading PHP.
23. [A tour of the major frameworks](23-a-tour-of-the-major-frameworks.md) — Laravel, Symfony, Slim and others: what
    they give you, how they map onto what you built by hand, and how to choose.
24. [Capstone project: Bookshelf](24-capstone-project.md) — the complete app: web UI, JSON API, CLI, database,
    authentication, tests and deployment files.

## Conventions

- Code blocks are tagged with their language: `php` for PHP, `bash` for shell commands, `text` for program output,
  `sql`, `ini`, `json`, `nginx`, `dockerfile`, `yaml` and so on.
- Shell commands start without a prompt character, so you can paste them directly.
- PHP files start with `<?php` and, from chapter 7 onwards, usually with `declare(strict_types=1);`. Short snippets that
  are clearly fragments of a larger file leave both out.
- When output contains a file path, it is shortened to the file name, e.g. `in /path/to/demo.php on line 3`.
