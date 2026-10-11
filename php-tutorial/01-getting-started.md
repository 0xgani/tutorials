# Chapter 1 — Getting started

## What you'll learn

- What PHP is, where it runs, and how its versions and support windows work
- How to install PHP 8.5 on macOS, Linux, Windows or in a container, and how to check what you have
- How to run a script, use the interactive shell, lint a file and start the built-in web server
- How a PHP file is read: PHP tags, statements, comments, and mixing PHP with HTML
- What happens between "you run a script" and "you see output" — the compile-then-execute pipeline
- The first version of **Bookshelf**, the app you will grow throughout this tutorial

## What PHP is

PHP is a general-purpose programming language designed for the web. A PHP program is a set of `.php` files that the
PHP engine reads, compiles to an internal instruction set, and executes. On a web server the engine runs once per
HTTP request: it receives the request, runs your code, sends back whatever your code printed, and throws everything
away. On the command line it runs a script and exits, like any other scripting language.

A few facts worth knowing before you start:

- **It is everywhere.** A large share of the public web runs on PHP: WordPress, Wikipedia (MediaWiki), Drupal,
  Magento, and countless applications built on the Laravel and Symfony frameworks.
- **It is not the PHP of 2005.** Modern PHP has a real type system, classes with readonly and computed properties,
  enums, first-class functions, generators, fibers, an optimizing opcode cache and a JIT compiler. Old tutorials full of
  `mysql_query()` and global variables describe a language that no longer exists. Ignore them.
- **It is open source and community-run.** The language is developed in the open at <https://github.com/php/php-src>.
  New features go through a public RFC ("request for comments") vote, documented at <https://wiki.php.net/rfc>.
- **The engine is called the Zend Engine.** You will see "Zend" in error messages and version strings. It is the name
  of the core virtual machine, not a separate product you need.

### Versions and support

PHP releases one new minor version a year, usually in late November. Each version gets **two years of active support**
(bug and security fixes) followed by **two years of security-only fixes**, then it is end-of-life.

| Version | Released | Active support until | Security fixes until |
|---|---|---|---|
| 8.2 | Dec 2022 | Dec 2024 | Dec 2026 |
| 8.3 | Nov 2023 | Dec 2025 | Dec 2027 |
| 8.4 | Nov 2024 | Dec 2026 | Dec 2028 |
| **8.5** | **Nov 2025** | **Dec 2027** | **Dec 2029** |

The current table is always at <https://www.php.net/supported-versions.php>. Running an end-of-life version in
production means running with known, unpatched security holes, so "which PHP version are we on?" is one of the first
questions to ask about any project.

Version numbers follow `major.minor.patch`. Patch releases (8.5.1, 8.5.2, …) only fix bugs and are safe to apply. Minor
releases (8.4 → 8.5) add features and deprecate old ones but rarely break working code. Major releases (7 → 8) can
remove things.

This tutorial targets **PHP 8.5**. Features added in the last two releases are marked "PHP 8.4+" or "PHP 8.5+".
Everything else works on PHP 8.3 too.

## Installing PHP

You need the **CLI** (command-line) version of PHP. It includes the built-in development web server, so you do not need
Apache or nginx to follow along. You will also want a handful of common extensions; the ones this tutorial uses are
`mbstring`, `intl`, `pdo_sqlite`, `sqlite3`, `curl`, `xml` and `zip`.

### macOS

[Homebrew](https://brew.sh) ships the latest PHP release:

```bash
brew install php
php --version
```

Homebrew's PHP comes with most common extensions compiled in. If you need several versions side by side, the
community tap `shivammathur/php` provides `php@8.3`, `php@8.4` and so on.

### Ubuntu and Debian

The PHP version in your distribution's own repository is often one or two releases behind. The widely used
repositories maintained by Ondřej Surý (a Debian PHP maintainer) carry every supported version:

```bash
# Ubuntu
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.5-cli php8.5-mbstring php8.5-intl php8.5-sqlite3 php8.5-xml php8.5-curl php8.5-zip
php --version
```

On Debian the same packages come from `https://packages.sury.org/php/`; the instructions are on that page. Package names
follow the pattern `php<version>-<extension>`, so `php8.5-pgsql` adds PostgreSQL support later.

On Fedora and RHEL-family systems, `sudo dnf install php-cli php-mbstring php-intl php-pdo php-xml` installs the
distribution's version, and the Remi repository (<https://rpms.remirepo.net>) provides newer ones.

### Windows

Download the latest "VS17 x64 Non Thread Safe" zip from <https://windows.php.net/download/>, extract it to a folder
such as `C:\php`, and add that folder to your `PATH`. Then copy `php.ini-development` to `php.ini` in the same folder
and enable the extensions you need by removing the `;` in front of lines such as `extension=mbstring`,
`extension=intl` and `extension=pdo_sqlite`. Open a new terminal and run `php --version`.

Alternatively, install a Linux distribution under WSL (Windows Subsystem for Linux) and follow the Ubuntu
instructions. Most PHP production servers run Linux, so this keeps your development environment close to production.

### In a container

If you have Docker, the official image runs PHP without installing anything on your machine:

```bash
docker run --rm -it -v "$PWD":/app -w /app php:8.5-cli php --version
```

Replace `php --version` with `php hello.php` to run a script from the current directory. Chapter 22 builds proper
images for production.

### Checking your installation

Three commands tell you almost everything about a PHP installation:

```bash
php --version        # which PHP, and whether OPcache is loaded
php -m               # the list of compiled-in and loaded extensions
php --ini            # which php.ini files were loaded
```

Typical output (yours will differ in details):

```text
PHP 8.5.10 (cli) (built: Sep 25 2026 10:12:40) (NTS)
Copyright (c) The PHP Group
Zend Engine v4.5.10, Copyright (c) Zend Technologies
    with Zend OPcache v8.5.10, Copyright (c), by Zend Technologies
```

`NTS` means "non thread safe", the normal build for the CLI and for PHP-FPM. The `ZTS` (thread-safe) build exists for
web servers that run PHP inside threads; you will rarely need it.

```text
Configuration File (php.ini) Path: /etc/php/8.5/cli
Loaded Configuration File:         /etc/php/8.5/cli/php.ini
Scan for additional .ini files in: /etc/php/8.5/cli/conf.d
Additional .ini files parsed:      /etc/php/8.5/cli/conf.d/10-opcache.ini,
/etc/php/8.5/cli/conf.d/10-pdo.ini,
...
```

`php.ini` is PHP's configuration file. Note that on Linux packages, the CLI and the web server (PHP-FPM) have
**separate** `php.ini` files, which is a common source of "it works on the command line but not in the browser"
confusion. Chapter 22 covers configuration properly.

### Settings for development

Make sure your development `php.ini` shows every error. Find these lines in the file reported by `php --ini` and set:

```ini
error_reporting = E_ALL
display_errors = On
display_startup_errors = On
```

`php.ini-development`, shipped with PHP, already has these values. **Never** use `display_errors = On` in production:
error messages leak file paths, SQL and sometimes secrets to visitors. Chapter 8 explains error handling and chapter 22
the production settings.

You can also override any setting for a single run with `-d`:

```bash
php -d display_errors=On -d error_reporting=-1 script.php
```

### An editor

Any editor works, but one that understands PHP saves hours: it flags syntax errors as you type, completes function
names and jumps to definitions. Popular choices are **PhpStorm** (commercial, very complete) and **VS Code** with a PHP
language extension such as *Intelephense* or *PHP Tools*. Chapter 20 adds a step debugger (Xdebug) and static analysis.

## Your first script

Create a file called `hello.php`:

```php
<?php

echo "Hello, world!\n";
```

Run it:

```bash
php hello.php
```

```text
Hello, world!
```

Three things are happening here:

1. **`<?php` is an opening tag.** PHP treats a file as text to be output verbatim *until* it meets `<?php`. Everything
   after the tag is PHP code. A file that contains only PHP code starts with `<?php` on the first line.
2. **`echo` prints.** It is a language construct, not a function, so it needs no parentheses, and it can take several
   comma-separated arguments: `echo "a", "b", "\n";`.
3. **Statements end with `;`.** The semicolon is not optional. Forgetting one is the most common beginner syntax error.

`"\n"` is a newline. Inside double quotes, PHP interprets escape sequences like `\n` and `\t`; inside single quotes it
does not. Chapter 4 covers strings in detail.

### Why pure PHP files have no closing tag

PHP also has a closing tag, `?>`. You might expect every file to end with it. It should not. If a file ends with `?>`
followed by a newline or a stray space, that whitespace is *output*. In a web app, output that happens before your code
sends HTTP headers (a redirect, a cookie) causes the infamous "headers already sent" warning. Leaving the closing tag
off a file that contains only PHP makes this class of bug impossible. The PSR-12 and PER coding styles that most PHP
projects follow require it to be omitted.

### Running one-liners

`php -r` runs code given on the command line, without the `<?php` tag:

```bash
php -r 'echo PHP_VERSION, " on ", PHP_OS_FAMILY, PHP_EOL;'
```

```text
8.5.10 on Linux
```

`PHP_VERSION`, `PHP_OS_FAMILY` and `PHP_EOL` (the platform's line ending) are built-in constants. Use single quotes
around the code in your shell so the shell does not try to expand PHP's `$variables`.

### The interactive shell

`php -a` starts an interactive shell (a REPL) where you can try expressions:

```bash
php -a
```

```text
Interactive shell

php > $x = 6 * 7;
php > echo $x;
42
php > var_dump(10 / 4);
float(2.5)
php > exit
```

The built-in shell is basic. Many developers use **PsySH** (<https://psysh.org>), a much nicer REPL that you install
with Composer (chapter 12). Laravel's `php artisan tinker` is PsySH under the hood.

### Checking syntax without running

`php -l` ("lint") parses a file and reports syntax errors without executing anything:

```bash
php -l hello.php
```

```text
No syntax errors detected in hello.php
```

Here is a broken file, missing a semicolon after the first `echo`:

```php
<?php
echo "x"
echo "y";
```

```text
PHP Parse error:  syntax error, unexpected token "echo", expecting "," or ";" in bad.php on line 3
Errors parsing bad.php
```

Notice the line number: PHP reports the line where it *noticed* the problem (the second `echo`), which is often the line
after the actual mistake. This is true for every parser, and it is worth remembering when an error points at a line that
looks perfectly fine.

### Other useful flags

| Command | What it does |
|---|---|
| `php file.php arg1 arg2` | Run a script; arguments arrive in `$argv` (chapter 14) |
| `php -r 'code'` | Run code from the command line |
| `php -a` | Interactive shell |
| `php -l file.php` | Syntax check only |
| `php -S host:port` | Start the built-in web server |
| `php -i` | Print the full configuration (same data as `phpinfo()`) |
| `php --ini` | Show which configuration files were loaded |
| `php -m` | List loaded extensions |
| `php -d key=value` | Override a `php.ini` setting for this run |
| `php --rf strlen` | Show a function's signature (`--rc` for a class) |

## PHP and HTML: the template heritage

PHP started life as a way to put small bits of code inside HTML pages, and that ability is still built in. Anything
outside `<?php ... ?>` tags is sent to the output untouched. `<?= expression ?>` is short for
`<?php echo expression; ?>`.

This is the very first version of **Bookshelf**. Save it as `index.php` in an empty directory:

```php
<?php
$books = ['Dune', 'Kindred', 'The Left Hand of Darkness'];
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Bookshelf</title></head>
<body>
  <h1>Bookshelf</h1>
  <p>Today is <?= date('l, j F Y') ?>.</p>
  <ul>
  <?php foreach ($books as $title): ?>
    <li><?= htmlspecialchars($title) ?></li>
  <?php endforeach; ?>
  </ul>
</body>
</html>
```

Things to notice:

- `$books` is a variable holding an array of three strings. All variable names start with `$`.
- `date('l, j F Y')` formats today's date ("Saturday, 10 October 2026"). Chapter 15 covers dates properly.
- `foreach (...):` and `endforeach;` are the *alternative syntax* for loops, designed for templates because they are
  easier to read than a lonely `}` in a sea of HTML (chapter 3).
- `htmlspecialchars()` escapes characters such as `<` and `&` so that a book title can never inject HTML into the page.
  Forgetting to escape output is the root of cross-site scripting (XSS), one of the most common web vulnerabilities.
  Chapter 18 treats it in depth. For now, the rule is: **every value you print into HTML goes through
  `htmlspecialchars()`**.

### The built-in web server

From the same directory, start PHP's development server:

```bash
php -S localhost:8000
```

```text
[Sat Oct 10 23:46:20 2026] PHP 8.5.10 Development Server (http://localhost:8000) started
```

Open <http://localhost:8000> in a browser. You should see the heading, today's date and the three titles. Each request
is logged in the terminal:

```text
[Sat Oct 10 23:46:21 2026] [::1]:37398 Accepted
[Sat Oct 10 23:46:21 2026] [::1]:37398 [200]: GET /
[Sat Oct 10 23:46:21 2026] [::1]:37398 Closing
[Sat Oct 10 23:46:21 2026] [::1]:37406 [404]: GET /favicon.ico - No such file or directory
```

Press Ctrl+C to stop it. Useful details:

- With no other options, the server serves files from the current directory. A request for `/` runs `index.php`. A
  request for an existing `.php` file runs it; any other existing file (CSS, images) is sent as-is.
- `php -S localhost:8000 -t public` uses `public/` as the document root. Real projects keep only a small entry script
  and static assets in `public/`, so the rest of the code cannot be requested directly (chapter 16).
- `php -S localhost:8000 router.php` sends **every** request through `router.php`, which is how you build an app with
  clean URLs (chapter 19).
- The server handles one request at a time by default and is **for development only**. Production uses PHP-FPM behind
  nginx or a similar server (chapter 22).

Look at the page source in the browser. The PHP tags are gone: the browser only ever receives the *output* of the
script. PHP runs entirely on the server.

## Comments

```php
<?php

// A single-line comment.
# Also a single-line comment (less common in modern code).

/*
 * A multi-line comment.
 */

/**
 * A "docblock": a multi-line comment starting with /**.
 * Tools such as IDEs and static analysers read the @tags inside it.
 *
 * @param string $title The book title.
 */
```

Docblocks matter more in PHP than comments do in many languages, because tools such as PHPStan (chapter 20) read them
to understand types that the language itself cannot express, such as "an array of `Book` objects". `#[...]` is *not* a
comment: since PHP 8.0 it is the syntax for attributes (chapter 11).

## How PHP runs your code

When you run `php hello.php`, or a web request hits `index.php`, the engine goes through these steps:

```text
  index.php (text)
       │
       ▼
  ┌──────────┐   tokens   ┌──────────┐   AST    ┌──────────┐  opcodes  ┌───────────┐
  │  Lexer   │ ─────────► │  Parser  │ ───────► │ Compiler │ ────────► │  Zend VM  │ ──► output
  └──────────┘            └──────────┘          └──────────┘           └───────────┘
                                                      │                      ▲
                                                      ▼                      │
                                               ┌────────────────────────────┐
                                               │ OPcache (shared memory):   │
                                               │ compiled opcodes are reused│
                                               │ on the next request        │
                                               └────────────────────────────┘
```

1. The **lexer** splits the text into tokens (`<?php`, `echo`, `"Hello"`, `;`).
2. The **parser** turns tokens into an abstract syntax tree. Syntax errors are detected here, before any code runs —
   which is why a missing semicolon on line 300 stops even line 1 from executing.
3. The **compiler** turns the tree into *opcodes*, a compact instruction list for the engine's virtual machine.
4. The **Zend VM** executes the opcodes.

Compiling every file on every request would be wasteful, so web servers run **OPcache**, an extension that keeps the
compiled opcodes in shared memory and reuses them until the file changes. With OPcache, a PHP request skips straight to
step 4 for most files. PHP 8.0 added a **JIT** (just-in-time compiler) on top of OPcache that can translate hot opcodes
into machine code. Chapter 21 explains all of this, including when the JIT actually helps (mostly CPU-heavy code, not
typical web requests).

### Shared nothing

The second big idea is the request model. In the classic web setup, **each request starts from a blank slate**:

```text
request 1:  start ─► load files ─► run code ─► send output ─► free ALL memory
request 2:  start ─► load files ─► run code ─► send output ─► free ALL memory
```

Variables, objects and open connections do not survive from one request to the next. This sounds wasteful, and
OPcache removes most of the cost, but it has big advantages: a memory leak or a corrupted variable in one request cannot
affect the next, a crash takes down one request instead of the whole server, and scaling out is easy because no state
lives in the PHP process. State that must survive (logged-in users, data) lives outside PHP: in the database, in a
session store, in a cache. Chapter 16 builds on this model, and chapter 22 shows the "long-running worker" alternatives
that keep the app in memory between requests.

## A quick look at modern PHP

To give you a sense of where this tutorial is going, here is a small, fully modern PHP program.

> **Just a taste — you don't need to learn or fully understand this now.** It previews strict types, enums, readonly
> classes and `match`, which chapters 7, 9 and 11 teach properly.

```php
<?php

declare(strict_types=1);

enum Genre: string
{
    case SciFi = 'sci-fi';
    case Fantasy = 'fantasy';
}

final readonly class Book
{
    public function __construct(
        public string $title,
        public string $author,
        public Genre $genre,
    ) {}

    public function label(): string
    {
        $shelf = match ($this->genre) {
            Genre::SciFi => 'Shelf A',
            Genre::Fantasy => 'Shelf B',
        };

        return "{$this->title} by {$this->author} ({$shelf})";
    }
}

$book = new Book('Dune', 'Frank Herbert', Genre::SciFi);
echo $book->label(), PHP_EOL;
```

```text
Dune by Frank Herbert (Shelf A)
```

By chapter 11 every line of this will be familiar.

## Where to find answers

- **The manual**, <https://www.php.net/manual/en/>. Each function page shows the signature, the return value, the
  changelog across versions and examples. `https://www.php.net/str_contains` jumps straight to a function.
- **The RFCs**, <https://wiki.php.net/rfc>. When you want to know *why* a feature works the way it does.
- **The migration guides** in the manual's appendix ("Migrating from PHP 8.4.x to PHP 8.5.x"), which list every new
  feature, deprecation and backward-incompatible change in a release.
- **`php --rf function_name`** for a signature without leaving the terminal.

## Key takeaways

- PHP is a compiled-to-opcodes language run by the Zend Engine. On the web it traditionally runs once per request and
  starts from a blank slate every time ("shared nothing").
- One minor version ships per year; each gets two years of bug fixes and two more of security fixes. Stay on a
  supported version. This tutorial targets PHP 8.5.
- `php file.php` runs a script, `php -a` opens a REPL, `php -l` checks syntax, `php -S localhost:8000` serves the
  current directory for development.
- Code lives inside `<?php` tags; text outside them is output verbatim. Files that contain only PHP omit the closing
  `?>` tag.
- Development `php.ini` should show all errors; production must never display them.
- Escape everything you print into HTML with `htmlspecialchars()`.

## Exercises

1. **Know your installation.** Write `info.php` that prints the PHP version, the operating system family, and whether
   the `mbstring` extension is loaded (look up `extension_loaded()` in the manual). Run it with `php info.php`.

<details>
<summary>Solution</summary>

```php
<?php

echo 'PHP version: ', PHP_VERSION, PHP_EOL;
echo 'OS family:   ', PHP_OS_FAMILY, PHP_EOL;
echo 'mbstring:    ', extension_loaded('mbstring') ? 'yes' : 'no', PHP_EOL;
```

```text
PHP version: 8.5.10
OS family:   Linux
mbstring:    yes
```

`extension_loaded()` returns `true` or `false`; the `? :` ternary operator picks one of two strings (chapter 3).

</details>

2. **Read the error message.** Create a file with this content, run `php -l` on it, and explain why the reported line
   is not the line with the mistake. Then fix it.

   ```php
   <?php
   $title = "Dune";
   echo "The title is " $title;
   echo "Done\n";
   ```

<details>
<summary>Solution</summary>

```text
PHP Parse error:  syntax error, unexpected variable "$title", expecting "," or ";" in broken.php on line 3
```

Here the error *is* reported on the right line, because the parser notices the problem as soon as it meets `$title`:
after a string, it expected either `,` (another `echo` argument), `;` or an operator. The fix is to join the two with the
concatenation operator `.` or pass them as two `echo` arguments:

```php
echo "The title is " . $title . "\n";
// or
echo "The title is ", $title, "\n";
```

Had the mistake been a missing `;` at the end of line 2, the error would point at line 3, where the next statement
starts.

</details>

3. **A second page.** Next to the Bookshelf `index.php`, add `about.php` that prints a heading and the current time
   (`date('H:i:s')`). Start `php -S localhost:8000`, visit `/about.php`, reload a few times and watch the server log.
   What do you see in the log for each reload, and what does that tell you about how PHP handles requests?

<details>
<summary>Solution</summary>

```php
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>About Bookshelf</title></head>
<body>
  <h1>About Bookshelf</h1>
  <p>Server time: <?= date('H:i:s') ?></p>
</body>
</html>
```

The file does not even need to start with `<?php`: it is mostly HTML with one PHP tag. Each reload produces an
`Accepted`, a `[200]: GET /about.php` and a `Closing` line, and the time changes every time. The script runs from
scratch for every single request; nothing is cached between them except (with OPcache) the compiled code.

</details>

4. **Configuration overrides.** Run `php -r 'echo ini_get("memory_limit"), PHP_EOL;'`, then run it again with
   `-d memory_limit=256M`. Then find which `php.ini` file your CLI loads and check its `display_errors` value with
   `php -i | grep display_errors`.

<details>
<summary>Solution</summary>

```bash
php -r 'echo ini_get("memory_limit"), PHP_EOL;'
php -d memory_limit=256M -r 'echo ini_get("memory_limit"), PHP_EOL;'
php --ini
php -i | grep display_errors
```

```text
-1
256M
...
display_errors => Off => Off
```

The CLI usually has `memory_limit = -1` (no limit) or `128M`, depending on the distribution. `ini_get()` reads a setting
at runtime; `-d` overrides it for one run. In `php -i` output, the two columns are the *local* and the *master* value;
they differ only when something changed the setting at runtime.

If you see `Off`, your package installed the *production* `php.ini` (Ubuntu and Debian do this). Command-line errors
still appear, because the CLI writes them to the error log, which defaults to standard error, but for development set
`display_errors = On` as described earlier in this chapter.

</details>

5. **Template vs. code.** Rewrite the Bookshelf `index.php` so that it uses no HTML outside PHP tags: build the whole
   page by `echo`-ing strings inside one `<?php` block. Compare the two versions. Which is easier to read and change, and
   why do you think template engines (chapter 19) exist?

<details>
<summary>Solution</summary>

```php
<?php
$books = ['Dune', 'Kindred', 'The Left Hand of Darkness'];

echo "<!doctype html>\n";
echo "<html lang=\"en\">\n";
echo "<head><meta charset=\"utf-8\"><title>Bookshelf</title></head>\n";
echo "<body>\n";
echo "  <h1>Bookshelf</h1>\n";
echo "  <p>Today is " . date('l, j F Y') . ".</p>\n";
echo "  <ul>\n";
foreach ($books as $title) {
    echo "    <li>" . htmlspecialchars($title) . "</li>\n";
}
echo "  </ul>\n";
echo "</body>\n";
echo "</html>\n";
```

The output is identical. The template version is easier to read because the HTML looks like HTML, with no escaped
quotes and no `\n` everywhere. The cost is that logic and markup are mixed in one file. Template engines and the
"render a view" pattern (chapter 19) keep the readable template style while moving data preparation and logic out of
the template.

</details>

---
← Previous: [README](README.md) | Next: [Variables, types and values](02-variables-types-and-values.md) →
