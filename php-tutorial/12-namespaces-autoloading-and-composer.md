# Chapter 12 — Namespaces, autoloading and Composer

## What you'll learn

- Namespaces: declaring them, fully qualified names, `use` imports for classes, functions and constants, aliases
- How PHP resolves names, including the global fallback for functions and constants (but not classes)
- Autoloading: what `spl_autoload_register()` does, and the PSR-4 convention that maps namespaces to directories
- Composer: `composer.json`, installing and updating packages, version constraints, the lock file, scripts, and
  platform requirements
- Keeping dependencies healthy: `composer audit`, `outdated`, and what to commit
- The PSR standards and why they matter
- Turning Bookshelf into a real Composer project with a `src/` directory

## Namespaces

Once a program uses libraries, names collide: your `Book` class, a PDF library's `Book` class, and a framework's
`Book` model cannot all be called `Book`. **Namespaces** group names under a prefix, like directories for files.

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

final readonly class Money
{
    public function __construct(public int $cents) {}
}
```

- `namespace Bookshelf\Domain;` must be the first statement in the file (after `declare`). Everything declared below
  it (classes, interfaces, traits, enums, functions, constants) lives in that namespace.
- The class's **fully qualified name** is `Bookshelf\Domain\Money`. The separator is a backslash.
- By convention the first segment is the vendor or project name (`Bookshelf`, `Symfony`, `GuzzleHttp`), and the rest
  mirrors the directory structure.
- Use one namespace per file. (PHP allows several with braces, but nobody does it in application code.)

### Referring to names

Inside a namespace, an unqualified name such as `Money` means "`Money` in the current namespace". There are three ways
to refer to something elsewhere:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Http;

// 1. Fully qualified, with a leading backslash: always absolute.
$a = new \Bookshelf\Domain\Money(100);

// 2. Imported with `use` (at the top of the file, after the namespace).
use Bookshelf\Domain\Money;
$b = new Money(100);

// 3. Imported with an alias.
use Bookshelf\Domain\Money as Amount;
$c = new Amount(100);
```

In practice you put all `use` statements at the top of the file, one per line, sorted, and refer to classes by their
short names. Your IDE adds them automatically. Other forms:

```php
use Bookshelf\Domain\{Book, Isbn, Member};   // group import
use function Bookshelf\Support\slugify;      // import a function
use const Bookshelf\Support\MAX_LOANS;       // import a constant
```

`use` is resolved at compile time and only creates an alias. It does not load anything; that is the autoloader's job
(next section). The `::class` constant resolves through imports too: after `use Bookshelf\Domain\Money;`,
`Money::class` is the string `'Bookshelf\Domain\Money'`.

### Global names and the fallback rule

PHP's own classes, functions and constants live in the **global namespace**. Inside a namespace, the rules differ for
classes versus functions and constants:

| Name used inside `namespace Bookshelf;` | Resolution |
|---|---|
| Class `DateTimeImmutable` | Only `Bookshelf\DateTimeImmutable`. **No fallback**: "Class "Bookshelf\DateTimeImmutable" not found" |
| Function `strlen()` | `Bookshelf\strlen()` if it exists, otherwise **falls back** to global `\strlen()` |
| Constant `PHP_EOL` | `Bookshelf\PHP_EOL` if defined, otherwise global `\PHP_EOL` |

So in namespaced code you must import or fully qualify built-in **classes**: `use DateTimeImmutable;`,
`use InvalidArgumentException;`, or write `\DateTimeImmutable`. Forgetting this is the most common namespace error.
Built-in functions work without imports thanks to the fallback.

Some projects nevertheless import or fully qualify built-in functions too (`\strlen($s)` or `use function strlen;`).
The reason is performance: with the fallback, the engine must check the namespace first at runtime; with a fully
qualified name, OPcache can resolve the call at compile time and even replace some functions (`strlen`, `count`,
`is_int`, ...) with dedicated opcodes. The gain is small and only matters in hot loops; coding-standard tools can add
the backslashes automatically.

`namespace\name` refers explicitly to the current namespace, and `__NAMESPACE__` holds its name as a string.

## Autoloading

Before autoloading, every file started with a list of `require_once` statements. With hundreds of classes this is
unmanageable. An **autoloader** is a function that PHP calls the first time an unknown class (or interface, trait or
enum) is used; its job is to find and `require` the file that defines it.

```php
<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Bookshelf\\';
    $baseDir = __DIR__ . '/src/';

    if (!str_starts_with($class, $prefix)) {
        return;   // not ours: let other registered autoloaders try
    }

    $relative = substr($class, strlen($prefix));                    // "Domain\Money"
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';  // ".../src/Domain/Money.php"

    if (is_file($file)) {
        require $file;
    }
});
```

```php
<?php

declare(strict_types=1);

require __DIR__ . '/autoload.php';

use Bookshelf\Domain\Money;

$fine = new Money(475);   // first use: PHP calls the autoloader with "Bookshelf\Domain\Money"
echo $fine->cents, "\n";  // 475
var_dump(class_exists('Bookshelf\Domain\Nope')); // bool(false): autoloader tried, no file
```

Autoloading is **lazy**: a class is loaded only when it is first used, so a request that never touches the PDF code
never loads it. Several autoloaders can be registered; PHP tries them in order until the class exists. Functions and
constants are **not** autoloaded, which is one reason PHP code favors classes and static methods over free functions
in libraries (Composer has a separate mechanism for function files, below).

### PSR-4: the convention

The autoloader above implements **PSR-4**, the community standard that maps a namespace prefix to a base directory:

```text
 namespace prefix          base directory
 Bookshelf\           ──►  src/

 Bookshelf\Domain\Money        ──►  src/Domain/Money.php
 Bookshelf\Http\Router         ──►  src/Http/Router.php
 Bookshelf\Tests\Domain\LoanTest ──► tests/Domain/LoanTest.php   (with Bookshelf\Tests\ -> tests/)
```

The rules: one class per file, the file named exactly like the class (case-sensitive on Linux, so `Money.php`, not
`money.php`), and the directories matching the namespace segments after the prefix. You will almost never write the
autoloader yourself; Composer generates it from a few lines of configuration.

## Composer

**Composer** is PHP's dependency manager. It installs libraries ("packages") from **Packagist** (<https://packagist.org>),
the public package registry, resolves their versions so that everything is compatible, and generates an autoloader
for both the libraries and your own code.

Install it from <https://getcomposer.org/download/> (or with your package manager: `brew install composer`). Check it:

```bash
composer --version
```

```text
Composer version 2.8.12 2025-09-19 13:41:59
```

Some developers prefer per-project tools installed by Composer itself; Composer is the one tool that is installed
globally.

### `composer.json`

A project's `composer.json` declares what it needs. `composer init` creates one interactively; here is Bookshelf's:

```json
{
    "name": "example/bookshelf",
    "description": "Bookshelf: a small lending-library app built in the PHP tutorial",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "ext-mbstring": "*",
        "ext-pdo": "*",
        "ext-pdo_sqlite": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^12.0",
        "phpstan/phpstan": "^2.1"
    },
    "autoload": {
        "psr-4": {
            "Bookshelf\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Bookshelf\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true
    },
    "scripts": {
        "test": "phpunit",
        "analyse": "phpstan analyse",
        "serve": [
            "Composer\\Config::disableProcessTimeout",
            "php -S localhost:8000 -t public"
        ]
    }
}
```

| Key | Meaning |
|---|---|
| `name` | `vendor/package`, required for libraries you publish |
| `type` | `project` for an application, `library` (the default) for a reusable package |
| `require` | Packages needed at runtime, plus **platform requirements**: the PHP version (`php`) and extensions (`ext-*`) |
| `require-dev` | Packages only needed for development: test runners, static analysers, debug tools |
| `autoload` | How to load your own classes: `psr-4` maps prefixes to directories (note the doubled `\\` in JSON) |
| `autoload-dev` | Same, for test classes |
| `scripts` | Named commands: `composer test` runs `phpunit` with `vendor/bin` on the `PATH` |
| `config` | Composer settings, e.g. `sort-packages`, or `platform.php` to pretend production's PHP version |

Platform requirements matter: `composer install` refuses to run on a machine whose PHP is too old or lacks a required
extension, so "it works on my machine" problems surface at install time instead of in production. Bookshelf requires
PHP 8.5 because it uses the pipe operator and clone-with.

### Installing packages

```bash
composer require monolog/monolog            # add a runtime dependency
composer require --dev phpunit/phpunit       # add a development dependency
```

`composer require` finds the latest version compatible with your PHP and other packages, adds a constraint such as
`"monolog/monolog": "^3.9"` to `composer.json`, downloads the package and its dependencies into `vendor/`, writes
`composer.lock` and regenerates the autoloader. Then a single line at the start of your program loads everything:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$log = new Logger('bookshelf');
$log->pushHandler(new StreamHandler('php://stderr'));
$log->warning('Loan overdue', ['loanId' => 42, 'days' => 4]);
```

The commands you will use most:

| Command | What it does |
|---|---|
| `composer install` | Install exactly the versions in `composer.lock` (or resolve and create it if missing) |
| `composer update` | Re-resolve within the constraints, upgrade packages, rewrite `composer.lock` |
| `composer update vendor/package` | Upgrade one package (and, with `-W`, its dependencies) |
| `composer require pkg` / `remove pkg` | Add / remove a dependency |
| `composer dump-autoload` | Regenerate the autoloader (after adding a PSR-4 mapping, for example) |
| `composer show` / `show --tree` | List installed packages / as a dependency tree |
| `composer outdated --direct` | Show direct dependencies with newer versions available |
| `composer audit` | Check installed versions against known security advisories |
| `composer validate` | Check `composer.json` for mistakes |
| `composer why vendor/pkg` | Explain which package requires another |

### Version constraints and semantic versioning

Packages use **semantic versioning**: `MAJOR.MINOR.PATCH`, where a major bump may break compatibility, a minor bump
adds features, and a patch bump only fixes bugs. Constraints in `composer.json` say which versions you accept:

| Constraint | Allows | Use |
|---|---|---|
| `^3.9` | `>=3.9.0 <4.0.0` | **The default.** Any compatible upgrade |
| `^0.4` | `>=0.4.0 <0.5.0` | Before 1.0, minor versions may break, so caret is stricter |
| `~3.9` | `>=3.9 <4.0` | Like `^` for two-part versions |
| `~3.9.2` | `>=3.9.2 <3.10.0` | Patch updates only |
| `3.9.*` | `>=3.9.0 <3.10.0` | Patch updates only |
| `3.9.2` | Exactly that version | Rarely a good idea; blocks security fixes |
| `>=3.0 <3.5` | A range | Working around a known bad release |
| `^2.0 \|\| ^3.0` | Either major | Libraries supporting two major versions of a dependency |

### The lock file

`composer.json` says what you **accept**; `composer.lock` records exactly what was **installed**: every package
(including indirect dependencies), its exact version and a content hash.

```text
composer.json  "monolog/monolog": "^3.9"          ← the rule
composer.lock  monolog/monolog 3.9.1 (+ psr/log 3.0.2, ...)  ← the snapshot
```

- **Applications commit `composer.lock`**. Every developer, CI run and deployment then installs identical versions.
  Upgrades happen deliberately, with `composer update`, in a commit you can review and revert.
- **Libraries usually do not commit it** (or it is ignored by consumers anyway): a library must work with whatever
  versions the application resolves.
- **Never commit `vendor/`.** It is reproducible from the lock file. Add it to `.gitignore`.
- On servers and in CI run `composer install`, **never** `composer update`.

For production, install without development packages and with an optimized autoloader:

```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

`--optimize-autoloader` turns the PSR-4 rules into a precomputed class-to-file map, so the autoloader does no
filesystem checks. `--classmap-authoritative` additionally tells it never to look outside that map, which is fastest,
but means classes generated at runtime cannot be autoloaded. Chapter 22 builds this into a deployment.

### Loading function files

Since functions are not autoloadable, Composer can include files on every request:

```json
"autoload": {
    "psr-4": { "Bookshelf\\": "src/" },
    "files": ["src/Support/functions.php"]
}
```

Keep such files small; everything in them is loaded whether used or not.

### `vendor/bin` and scripts

Packages that ship command-line tools put them in `vendor/bin/`: `vendor/bin/phpunit`, `vendor/bin/phpstan`. Composer
scripts run with `vendor/bin` on the `PATH`, so `composer test` and `composer analyse` work without typing paths, and
document the project's standard commands in one place. `composer run-script --list` shows them.

### Security and trust

Every package you install is code that runs with your application's privileges, and its dependencies come along too.
Habits that keep this manageable:

- Prefer well-maintained packages with many users, and check what a package pulls in (`composer show --tree`) before
  adding it.
- Run `composer audit` in CI; it fails when an installed version has a published security advisory. Composer 2.7+ also
  runs the audit automatically after `require` and `update`.
- Composer plugins and scripts in dependencies can execute code at install time. Composer asks before enabling a new
  plugin (the `allow-plugins` setting); do not approve plugins you do not recognize.
- Keep dependencies current. A lock file that has not been updated for two years is a list of known vulnerabilities.

## The PSR standards

The **PHP Framework Interop Group** (PHP-FIG, <https://www.php-fig.org>) publishes "PHP Standards Recommendations" that
let libraries from different authors work together. The ones you will meet:

| PSR | Defines |
|---|---|
| PSR-1, PSR-12, and the newer **PER Coding Style** | Code formatting: braces, spacing, naming, `declare` placement |
| **PSR-4** | Autoloading (above) |
| **PSR-3** | `LoggerInterface`: `$logger->warning('message', ['context' => ...])` (Monolog implements it) |
| **PSR-7** | Immutable HTTP request and response objects (chapter 19) |
| **PSR-11** | `ContainerInterface` for dependency-injection containers (chapter 19) |
| **PSR-15** | HTTP middleware and request handlers (chapter 19) |
| **PSR-17**, **PSR-18** | Factories for PSR-7 objects; an HTTP client interface |
| **PSR-20** | `ClockInterface` (chapter 15) |
| PSR-6, PSR-16 | Cache interfaces |
| PSR-14 | Event dispatcher |

The interfaces are small Composer packages themselves (`psr/log`, `psr/http-message`, `psr/container`, `psr/clock`).
Writing your code against them, rather than a specific library, lets you swap implementations.

## Project layout

Most PHP applications use a layout close to this:

```text
bookshelf/
├── bin/              command-line entry points (chapter 14)
├── config/           configuration files
├── public/           the web server's document root: index.php, CSS, images (chapter 16)
├── src/              your classes, PSR-4 autoloaded (Bookshelf\ -> src/)
├── templates/        HTML templates (chapter 19)
├── tests/            test classes (Bookshelf\Tests\ -> tests/, chapter 20)
├── var/              runtime files: cache, logs, the SQLite database (not committed)
├── vendor/           installed packages (not committed)
├── composer.json
├── composer.lock
└── .gitignore
```

The key security property is that the web server only serves `public/`. Everything else, including `vendor/`,
configuration and the database, cannot be requested by URL.

## Bookshelf becomes a Composer project

From this chapter on, Bookshelf lives in [`examples/bookshelf/`](examples/bookshelf/). The classes from chapters 9–11
move into the `Bookshelf\Domain` namespace, one file each:

```text
src/Domain/
├── Book.php               entity: title, author, ISBN, copies
├── BookNotAvailable.php   exception
├── BookshelfException.php base exception
├── EntityNotFound.php     exception with named constructors: book(), member(), loan()
├── Genre.php              enum
├── Isbn.php               value object
├── Loan.php               entity: dates, renewals, fines
├── LoanRuleViolation.php  exception
├── LoanStatus.php         enum
├── Member.php             entity: name, e-mail, member type
├── MemberType.php         enum with the lending rules
└── Money.php              value object
```

Each file starts with the namespace and imports the global classes it uses. Here is `src/Domain/Money.php`:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A non-negative amount of money in US cents. Immutable.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative');
        }
    }

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function times(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    public function min(Money $other): self
    {
        return $this->cents <= $other->cents ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function format(): string
    {
        return sprintf('$%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): int
    {
        return $this->cents;
    }
}
```

`JsonSerializable` controls how `json_encode()` represents the object (chapter 14). The `Loan` class has grown a few
features since chapter 11 to prepare for the database: an `id` that is `null` until the loan is saved, a `restore()`
named constructor that rebuilds a loan from stored data, and the loan length and fine rate copied from the member type
when the loan starts, so that changing the rules later does not change existing loans:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use DateTimeImmutable;
use LogicException;

final class Loan
{
    public const int MAX_RENEWALS = 2;
    public const int FINE_CAP_CENTS = 1000;

    public bool $isReturned {
        get => $this->returnedOn !== null;
    }

    private function __construct(
        public private(set) ?int $id,
        public readonly int $bookId,
        public readonly int $memberId,
        public readonly DateTimeImmutable $lentOn,
        public private(set) DateTimeImmutable $dueOn,
        public readonly int $loanDays,
        public readonly Money $finePerDay,
        public private(set) ?DateTimeImmutable $returnedOn = null,
        public private(set) int $renewals = 0,
    ) {
    }

    public static function start(Book $book, Member $member, DateTimeImmutable $today): self
    {
        if ($book->id === null || $member->id === null) {
            throw new LogicException('Only saved books and members can take part in a loan');
        }
        $lentOn = $today->setTime(0, 0);
        $days = $member->type->loanDays();

        return new self(null, $book->id, $member->id, $lentOn, $lentOn->modify("+$days days"), $days,
            $member->type->finePerDay());
    }

    // restore(), assignId(), status(), daysOverdue(), fine(), renew(), markReturned(): see the source file
}
```

The full file is [`examples/bookshelf/src/Domain/Loan.php`](examples/bookshelf/src/Domain/Loan.php). To set up the
project and try the domain classes:

```bash
cd examples/bookshelf
composer install          # installs PHPUnit and PHPStan, generates vendor/autoload.php
```

```php
<?php
// try.php, next to composer.json

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\{Book, BookshelfException, Genre, Isbn, Loan, Member, MemberType};

$dune = new Book(1, 'Dune', 'Frank Herbert', new Isbn('978-0-441-01359-3'), Genre::ScienceFiction, 1965, copies: 1);
$ada = new Member(1, 'Ada Lovelace', 'ADA@example.org', MemberType::Standard);

$dune->lendCopy();
$loan = Loan::start($dune, $ada, new DateTimeImmutable('2026-09-01'));

echo "Due: {$loan->dueOn->format('Y-m-d')}\n";
$today = new DateTimeImmutable('2026-10-11');
echo "Status: {$loan->status($today)->label()}, fine {$loan->fine($today)}\n";

try {
    $loan->renew($today);
} catch (BookshelfException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
```

```bash
php try.php
```

```text
Due: 2026-09-22
Status: Overdue, fine $4.75
Bookshelf\Domain\LoanRuleViolation: Overdue loans cannot be renewed; please return the book
```

If you have no network access, `composer dump-autoload` alone generates the autoloader for the project's own classes;
only the development tools need downloading.

## Key takeaways

- Namespaces prevent name collisions. Declare one per file, mirror it in directories, import with `use`.
- Inside a namespace, built-in **classes** must be imported or written with a leading `\`; functions and constants fall
  back to the global namespace.
- Autoloaders load classes on first use. PSR-4 maps a namespace prefix to a directory; Composer generates the
  autoloader, so `require __DIR__ . '/vendor/autoload.php';` is the only `require` most programs need.
- `composer.json` declares constraints (`^` by default) and platform requirements; `composer.lock` pins exact versions.
  Applications commit the lock file, never `vendor/`.
- `composer install` on servers and in CI; `composer update` deliberately, in its own commit. Production installs use
  `--no-dev --optimize-autoloader`.
- Run `composer audit`, keep dependencies current, and be careful about plugins.
- PSR interfaces (logging, HTTP, containers, clocks) let libraries interoperate.

## Exercises

1. **Resolve the names.** In a file with `namespace Bookshelf\Http;` and `use Bookshelf\Domain\Book;`, what fully
   qualified name does each of these refer to: `Book`, `Request`, `\Exception`, `Exception`, `strlen()`,
   `Domain\Money`, `namespace\Router`?

<details>
<summary>Solution</summary>

| Written | Resolves to |
|---|---|
| `Book` | `Bookshelf\Domain\Book` (imported) |
| `Request` | `Bookshelf\Http\Request` (current namespace) |
| `\Exception` | `Exception` (global, fully qualified) |
| `Exception` | `Bookshelf\Http\Exception`, which probably does not exist: classes have no global fallback |
| `strlen()` | `Bookshelf\Http\strlen()` if defined, otherwise the global `strlen()` |
| `Domain\Money` | `Bookshelf\Http\Domain\Money`: a qualified name is relative to the current namespace, not to the root |
| `namespace\Router` | `Bookshelf\Http\Router` |

The `Domain\Money` case catches people out: only names starting with `\` are absolute.

</details>

2. **Write a two-prefix autoloader.** Extend the PSR-4 autoloader from this chapter so that it takes an array of
   prefix-to-directory mappings, e.g. `['Bookshelf\\' => 'src/', 'Bookshelf\\Tests\\' => 'tests/']`, and picks the
   **longest** matching prefix (so `Bookshelf\Tests\LoanTest` goes to `tests/`).

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/**
 * @param array<string, string> $map namespace prefix => base directory
 */
function registerPsr4(array $map): void
{
    // Longest prefix first, so more specific mappings win.
    uksort($map, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    spl_autoload_register(function (string $class) use ($map): void {
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = rtrim($dir, '/') . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
}

registerPsr4([
    'Bookshelf\\' => __DIR__ . '/src',
    'Bookshelf\\Tests\\' => __DIR__ . '/tests',
]);
```

Composer's generated autoloader does the same thing, with a cache and with the prefixes indexed by their first letter
for speed.

</details>

3. **Read a constraint.** For each constraint, list which of these versions it accepts: `1.9.9`, `2.0.0`, `2.3.1`,
   `2.10.0`, `3.0.0-beta1`, `3.0.0`. Constraints: `^2.3`, `~2.3`, `~2.3.0`, `2.*`, `>=2.3 <3.0`.

<details>
<summary>Solution</summary>

| Constraint | Range | Accepts |
|---|---|---|
| `^2.3` | `>=2.3.0 <3.0.0` | 2.3.1, 2.10.0 |
| `~2.3` | `>=2.3 <3.0` | 2.3.1, 2.10.0 |
| `~2.3.0` | `>=2.3.0 <2.4.0` | 2.3.1 |
| `2.*` | `>=2.0.0 <3.0.0` | 2.0.0, 2.3.1, 2.10.0 |
| `>=2.3 <3.0` | as written | 2.3.1, 2.10.0 |

`3.0.0-beta1` is excluded everywhere: it is a pre-release of 3.0.0, which is outside all of these ranges, and Composer
also ignores unstable versions unless `minimum-stability` allows them. Note that versions compare numerically per
segment, so `2.10.0` is newer than `2.3.1`.

</details>

4. **Lock file reasoning.** A teammate ran `composer update` on Friday and committed only `composer.json`, not
   `composer.lock`. On Monday, production runs `composer install`. What can go wrong, and what is the correct workflow
   for upgrading one package, say `monolog/monolog`?

<details>
<summary>Solution</summary>

If the old lock file is still in the repository, `composer install` warns that it is out of date with
`composer.json` and installs the **old** locked versions, so the upgrade silently does not happen (or, if the new
constraint excludes the locked version, the install fails). If there is no lock file at all, `composer install`
resolves fresh, and production may get different versions from what anyone tested.

The correct workflow:

```bash
composer update monolog/monolog --with-dependencies   # upgrade one package (and what it needs)
composer test                                          # run the tests against the new versions
git add composer.json composer.lock
git commit -m "Upgrade monolog/monolog to 3.x.y"
```

Then every environment runs `composer install` and gets exactly the tested versions.

</details>

5. **Namespace the function library.** Take the `slugify()` and `isValidIsbn13()` functions from chapter 6 and turn
   them into static methods of a `final class Text` in the namespace `Bookshelf\Support`, saved as
   `src/Support/Text.php` in the Bookshelf project. Show how a file in `Bookshelf\Http` would use them, and explain why
   this works with PSR-4 autoloading while plain namespaced functions would need the `files` setting.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Support;

final class Text
{
    public static function slugify(string $title): string
    {
        return $title
            |> trim(...)
            |> mb_strtolower(...)
            |> (fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '-', $s))
            |> (fn (string $s): string => trim($s, '-'));
    }

    public static function isValidIsbn13(string $input): bool
    {
        return \Bookshelf\Domain\Isbn::isValid(preg_replace('/[\s-]+/', '', $input));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Http;

use Bookshelf\Support\Text;

echo Text::slugify('The Left Hand of Darkness'), "\n"; // the-left-hand-of-darkness
```

The first use of `Text` triggers the autoloader with `Bookshelf\Support\Text`, which PSR-4 maps to
`src/Support/Text.php`. A namespaced *function* `Bookshelf\Support\slugify()` would never trigger an autoloader (PHP
has no function autoloading), so its file would have to be listed under `autoload.files` to be loaded on every request.
A class with static methods is also easier to find and to mock. `isValidIsbn13()` reuses the check in the `Isbn` value
object instead of duplicating it.

</details>

---
← Previous: [Enums, attributes and modern object features](11-enums-attributes-and-modern-objects.md) | Next: [Iterators, generators and fibers](13-iterators-generators-and-fibers.md) →
