# Chapter 8 — Errors and exceptions

## What you'll learn

- PHP's two error mechanisms: diagnostics (warnings, notices, deprecations, fatal errors) and exceptions
- The `Throwable` hierarchy: `Error` versus `Exception`, and the standard exception classes
- `throw`, `try`, `catch` (including multi-catch), `finally`, and `throw` as an expression
- Writing your own exception classes, chaining exceptions and choosing what to put in a message
- Turning warnings into exceptions with an error handler, and handling uncaught exceptions and fatal errors
- The settings that control what users see and what gets logged, in development and in production
- When to throw, when to return, and where to catch

## Two mechanisms

PHP grew up with **diagnostics**: messages the engine emits ("Warning: Undefined variable $x") while the script keeps
running, unless the problem is fatal. Later it added **exceptions**: objects that are thrown, unwind the call stack and
must be caught, or the script stops. Modern PHP has moved most hard failures to exceptions, but both systems exist, and
you need to understand both.

```text
                         ┌────────────────────────────────────────────┐
  Diagnostics            │ E_DEPRECATED  "this will stop working"      │  script continues
  (error levels)         │ E_NOTICE      "probably harmless"           │  script continues
                         │ E_WARNING     "something went wrong"        │  script continues
                         │ E_ERROR       fatal (out of memory, ...)    │  script stops, cannot be caught
                         └────────────────────────────────────────────┘
                         ┌────────────────────────────────────────────┐
  Exceptions             │ Error      thrown by the engine (TypeError,│  stop unless caught
  (Throwable objects)    │            DivisionByZeroError, ...)        │
                         │ Exception  thrown by code (yours and PHP's) │
                         └────────────────────────────────────────────┘
```

## Diagnostics: error levels

Each diagnostic has a level, a bit flag constant:

| Level | Meaning | Example |
|---|---|---|
| `E_ERROR` | Fatal runtime error | Memory limit exceeded, maximum execution time exceeded |
| `E_PARSE` | Syntax error | Missing semicolon |
| `E_WARNING` | Something failed, execution continues | Undefined variable or array key, `fopen()` on a missing file |
| `E_NOTICE` | Minor issue | Rare since PHP 8; most notices became warnings |
| `E_DEPRECATED` | Feature will be removed in a future version | Implicitly nullable parameter (8.4), `(integer)` cast (8.5) |
| `E_USER_ERROR`, `E_USER_WARNING`, `E_USER_NOTICE`, `E_USER_DEPRECATED` | The same, raised by your code with `trigger_error()` | |
| `E_ALL` | All of the above | |

(`E_STRICT` was removed in PHP 8.4; using the constant is itself deprecated.)

Three `php.ini` settings decide what happens to a diagnostic:

| Setting | Development | Production |
|---|---|---|
| `error_reporting` | `E_ALL` | `E_ALL` |
| `display_errors` | `On`: print into the output | **`Off`** |
| `log_errors` | `On` | `On` |
| `error_log` | (unset: the web server's log, or stderr for the CLI) | A file or `syslog` |

Note that production should **report** everything (`E_ALL`) but **display** nothing. Displayed errors leak paths,
queries and sometimes secrets, and break your HTML or JSON output. Logged errors are how you find out that something
is wrong. Never lower `error_reporting` to "get rid of" warnings; fix them.

**Deprecations deserve attention.** They are the advance notice of what the next PHP version will break. A test suite
that runs without deprecation output is what makes upgrading PHP a non-event (chapter 22).

## Exceptions

An exception is an object describing a failure. `throw` raises it; execution jumps to the nearest enclosing `catch`
block that accepts its type, unwinding every function call in between:

```php
<?php

declare(strict_types=1);

function lend(string $title, int $copies): int
{
    if ($copies === 0) {
        throw new RuntimeException("No copies of '$title' available");
    }

    return $copies - 1;
}

try {
    $left = lend('Dune', 2);
    echo "Lent Dune, $left left\n";
    $left = lend('Kindred', 0);
    echo "This line never runs\n";
} catch (RuntimeException $e) {
    echo 'Could not lend: ', $e->getMessage(), "\n";
}

echo "The program continues\n";
```

```text
Lent Dune, 1 left
Could not lend: No copies of 'Kindred' available
The program continues
```

### The `Throwable` hierarchy

Everything that can be thrown implements the `Throwable` interface, through one of two base classes:

```text
Throwable
├── Error                         problems detected by the engine; usually bugs
│   ├── TypeError
│   │   └── ArgumentCountError
│   ├── ValueError                (PHP 8.0+) right type, invalid value: str_repeat('x', -1)
│   ├── ArithmeticError
│   │   └── DivisionByZeroError
│   ├── UnhandledMatchError
│   ├── AssertionError
│   └── CompileError
│       └── ParseError
└── Exception                     conditions a program may anticipate and handle
    ├── ErrorException            a diagnostic converted to an exception (below)
    ├── JsonException
    ├── LogicException            a bug in the calling code
    │   ├── BadFunctionCallException
    │   │   └── BadMethodCallException
    │   ├── DomainException
    │   ├── InvalidArgumentException
    │   ├── LengthException
    │   └── OutOfRangeException
    └── RuntimeException          something went wrong that only shows at runtime
        ├── OutOfBoundsException
        ├── OverflowException
        ├── RangeException
        ├── UnderflowException
        └── UnexpectedValueException
    (plus extension exceptions such as PDOException, Random\RandomException, ...)
```

- **`Error`** subclasses mean "the code is wrong": a type mismatch, a call to an undefined function, division by zero.
  You rarely catch them, except at the very top of the program to log them.
- **`Exception`** subclasses mean "something did not work out": a file is missing, input is invalid, a book is not
  available. These are the ones you throw and catch.
- Among the standard exceptions, the two you will use most are `InvalidArgumentException` (the caller passed a bad
  value) and `RuntimeException` (the operation failed for reasons outside the caller's control). Your own exceptions
  usually extend one of these.

`catch (Exception $e)` does **not** catch a `TypeError`. To catch everything, use `catch (Throwable $e)`.

In PHP 8, many built-in functions throw instead of returning `false` and warning, especially for invalid arguments:

```php
<?php

try {
    str_repeat('x', -1);
} catch (ValueError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
```

```text
ValueError: str_repeat(): Argument #2 ($times) must be greater than or equal to 0
```

### `catch`, multi-catch and `finally`

```php
<?php

declare(strict_types=1);

function process(string $json): array
{
    try {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return ['ok' => true, 'title' => $data['title'] ?? throw new UnexpectedValueException('no title')];
    } catch (JsonException | UnexpectedValueException $e) {   // either type
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally {
        echo "processed one record\n";                         // always runs
    }
}

print_r(process('{"title": "Dune"}'));
print_r(process('{bad json'));
print_r(process('{"year": 1965}'));
```

```text
processed one record
Array
(
    [ok] => 1
    [title] => Dune
)
processed one record
Array
(
    [ok] =>
    [error] => Syntax error
)
processed one record
Array
(
    [ok] =>
    [error] => no title
)
```

The rules:

- `catch` blocks are tested **in order**; the first whose type matches the exception (or a parent class of it) wins.
  Put specific types before general ones.
- `catch (A | B $e)` catches either type (PHP 7.1+).
- The variable is optional since PHP 8.0: `catch (JsonException)` when you do not need the object.
- `finally` runs whether the `try` block finished normally, threw, or **returned**. Use it for cleanup that must happen:
  closing a file, releasing a lock, restoring a setting.
- A `return` inside `finally` overrides any other return value and **swallows** a thrown exception. Never return from
  `finally`.

`json_decode()` shows a common PHP pattern: by default it returns `null` on invalid input and you must check
`json_last_error()`, but with the `JSON_THROW_ON_ERROR` flag it throws a `JsonException`. Always pass the flag
(chapter 14).

### `throw` is an expression (PHP 8.0+)

`throw` can appear where a value is expected, which makes guard clauses compact:

```php
$title = $data['title'] ?? throw new UnexpectedValueException('Missing title');
$days = $days > 0 ? $days : throw new InvalidArgumentException('days must be positive');
$fn = fn (string $s) => $s !== '' ? $s : throw new LengthException('empty');
```

### What an exception carries

| Method | Returns |
|---|---|
| `getMessage()` | The message string |
| `getCode()` | An integer code (default `0`); mostly used by extensions, e.g. `JsonException` code 4 = syntax error |
| `getPrevious()` | The exception that caused this one, or `null` (see chaining) |
| `getFile()`, `getLine()` | Where the exception object was **created** |
| `getTrace()`, `getTraceAsString()` | The call stack at that point |

`echo $e` (or `(string) $e`) prints the class, message, location and trace together, which is useful for logs.

## Custom exceptions

Define your own exception classes so that callers can catch **your** failures specifically, without catching unrelated
ones. An exception class is usually tiny:

```php
<?php

declare(strict_types=1);

class BookshelfException extends RuntimeException
{
}

final class BookNotAvailable extends BookshelfException
{
}

final class LoanLimitReached extends BookshelfException
{
}
```

`class X extends Y {}` declares a class that inherits everything from `Y`; chapters 9 and 10 explain classes and
inheritance. Here you only need to know that a `BookNotAvailable` *is a* `BookshelfException`, which *is a*
`RuntimeException`, so `catch (BookshelfException $e)` catches both of the specific types.

Guidelines for exception design:

- **Name the problem, not the place**: `BookNotAvailable`, not `LendingServiceException`.
- **One base class (or interface) per library or module**, so callers can catch everything from your code at once.
- **Messages are for developers and logs.** Include the relevant identifiers (`"Book 42 has no available copies"`), but
  never secrets such as passwords or tokens. What you show to *users* is a separate decision, made where the exception
  is caught.
- **Do not use exceptions for normal control flow.** "No search results" is a normal outcome (return an empty array);
  "the database is unreachable" is exceptional.

### Chaining: keep the cause

When you catch a low-level exception and throw a higher-level one, pass the original as `$previous`, the third
constructor argument. The cause stays available for debugging:

```php
<?php

declare(strict_types=1);

final class CatalogUnavailable extends RuntimeException
{
}

function loadCatalog(string $path): array
{
    try {
        $json = file_get_contents($path)
            ?: throw new RuntimeException("Cannot read $path");
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (RuntimeException | JsonException $e) {
        throw new CatalogUnavailable('The catalog could not be loaded', previous: $e);
    }
}

file_put_contents(__DIR__ . '/catalog.json', '{"books": [');   // a truncated file

try {
    loadCatalog(__DIR__ . '/catalog.json');
} catch (CatalogUnavailable $e) {
    echo $e->getMessage(), "\n";
    echo '  caused by ', get_class($e->getPrevious()), ': ', $e->getPrevious()->getMessage(), "\n";
}
```

```text
The catalog could not be loaded
  caused by JsonException: Syntax error
```

When printed with `echo $e`, PHP includes the whole chain, each with its own trace ("Next CatalogUnavailable: ...").

### Hiding sensitive arguments (PHP 8.2+)

Stack traces include function arguments. Mark parameters holding secrets with the `#[\SensitiveParameter]` attribute
so they never show up in a trace or a log:

```php
<?php

declare(strict_types=1);

function login(string $user, #[\SensitiveParameter] string $password): never
{
    throw new RuntimeException('Login failed');
}

try {
    login('ada', 'hunter2');
} catch (RuntimeException $e) {
    echo $e->getTraceAsString(), "\n";
}
```

```text
#0 demo.php(11): login('ada', Object(SensitiveParameterValue))
#1 {main}
```

## Turning warnings into exceptions

Warnings are easy to miss: the script continues with a `null` or `false` where a real value should be. Most modern
applications and frameworks install an **error handler** that converts every diagnostic into an `ErrorException`, so
that a warning stops the current operation as loudly as an exception would:

```php
<?php

declare(strict_types=1);

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;   // this level is not reported (or the @ operator was used): let PHP handle it
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $books = ['Dune'];
    echo $books[5];
} catch (ErrorException $e) {
    echo 'Caught: ', $e->getMessage(), "\n";
}

try {
    $json = file_get_contents('/nonexistent/catalog.json');
} catch (ErrorException $e) {
    echo 'Caught: ', $e->getMessage(), "\n";
}
```

```text
Caught: Undefined array key 5
Caught: file_get_contents(/nonexistent/catalog.json): Failed to open stream: No such file or directory
```

The handler receives the level, message, file and line. Returning `false` hands the diagnostic back to PHP's standard
handling. The `error_reporting() & $severity` check respects the configured level and the `@` operator (inside an
`@`-silenced expression, `error_reporting()` returns only the fatal levels).

Should deprecations become exceptions too? In development and tests, yes, so you fix them early. In production, a
deprecation is not a failure; log it instead of throwing. PHP 8.5 added `get_error_handler()` and
`get_exception_handler()` to inspect the currently installed handlers.

## Uncaught exceptions and fatal errors

An exception that no `catch` block handles ends the script with a fatal error:

```text
PHP Fatal error:  Uncaught LogicException: not handled in demo.php:10
Stack trace:
#0 {main}
  thrown in demo.php on line 10
```

`set_exception_handler()` installs a last-resort handler for uncaught exceptions. It is where an application logs the
error and shows a friendly page instead of a stack trace; the script still ends afterwards:

```php
<?php

declare(strict_types=1);

set_exception_handler(function (Throwable $e): void {
    error_log(sprintf('Uncaught %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);   // on the web; harmless on the CLI
    echo "Sorry, something went wrong.\n";
});

throw new LogicException('not handled');
```

```text
Uncaught LogicException: not handled in /path/to/demo.php:11
Sorry, something went wrong.
```

(On the CLI, `error_log()` writes to standard error, which is why the log line appears in the terminal.) `$e::class`
gives the class name of an object, like `get_class($e)`.

**Real fatal errors cannot be caught**: running out of memory, exceeding `max_execution_time`, or a compile error in an
included file. The engine stops immediately. A function registered with `register_shutdown_function()` still runs
afterwards and can inspect `error_get_last()` to log what happened. **PHP 8.5+** prints a stack trace for fatal errors
too (controlled by the `fatal_error_backtraces` setting, on by default), which makes "Allowed memory size exhausted"
much easier to track down:

```text
PHP Fatal error:  Allowed memory size of 16777216 bytes exhausted (tried to allocate 1052672 bytes) in demo.php on line 3
Stack trace:
#0 demo.php(2): b()
#1 demo.php(5): a()
#2 {main}
```

## Assertions

`assert($condition, $description)` checks a condition that should **always** be true if the code is correct:

```php
assert($copies >= 0, 'copy count went negative');
```

Assertions are controlled by the `zend.assertions` setting: `1` in development (a failing assertion throws an
`AssertionError`) and `-1` in production, where the assertion code is not even compiled, so it costs nothing. Because
they can be switched off, assertions are for catching bugs during development, **never** for validating input or
anything with side effects.

## When to throw, when to return, where to catch

**Throw** when a function cannot do what its name promises: `lend()` cannot lend, `loadCatalog()` cannot load. The
caller should not be able to ignore it by accident.

**Return** a value for expected outcomes: `findBook()` returning `null` when there is no such book, `search()`
returning `[]`. Use `?T` return types for "maybe".

**Catch** only where you can do something useful:

- *Recover*: retry, use a fallback, skip a bad record and continue with the next.
- *Translate*: wrap a low-level exception in one that makes sense at this level, with `previous:`.
- *Report*: at the outermost layer (the front controller of a web app, the main function of a CLI tool), log it and
  show an appropriate message or HTTP status.

Do **not** catch an exception just to log it and throw it again at every layer (you get the same error logged ten
times), and never swallow exceptions with an empty `catch`. A silent failure is the hardest bug to find.

```php
// Bad: the failure disappears.
try {
    sendReminderEmail($loan);
} catch (Exception $e) {
}

// Better: decide, and leave a trace.
try {
    sendReminderEmail($loan);
} catch (MailTransportException $e) {
    error_log("Reminder for loan {$loan['id']} not sent: {$e->getMessage()}");
    // reminders are retried by the nightly job, so continuing is safe
}
```

## Bookshelf: lending with exceptions

The lending rules now raise specific exceptions, and a small "front controller" catches them at the top:

```php
<?php

declare(strict_types=1);

class BookshelfException extends RuntimeException
{
}

final class BookNotFound extends BookshelfException
{
}

final class BookNotAvailable extends BookshelfException
{
}

final class LoanLimitReached extends BookshelfException
{
}

const MAX_LOANS = 2;

/**
 * Lend a book and return the updated catalog.
 *
 * @param array<int, array{title: string, copies: int}> $catalog keyed by book id
 * @param list<int> $memberLoans ids of books the member already has
 * @return array<int, array{title: string, copies: int}>
 */
function lend(array $catalog, int $bookId, array $memberLoans): array
{
    $book = $catalog[$bookId] ?? throw new BookNotFound("No book with id $bookId");

    if (count($memberLoans) >= MAX_LOANS) {
        throw new LoanLimitReached(sprintf('Member already has %d of %d loans', count($memberLoans), MAX_LOANS));
    }
    if ($book['copies'] === 0) {
        throw new BookNotAvailable("No copies of '{$book['title']}' are available");
    }

    $catalog[$bookId]['copies']--;
    return $catalog;
}

$catalog = [
    1 => ['title' => 'Dune', 'copies' => 1],
    2 => ['title' => 'Kindred', 'copies' => 0],
];

$requests = [[1, []], [2, []], [7, []], [1, [3, 4]], [1, []]];

foreach ($requests as [$bookId, $memberLoans]) {
    try {
        $catalog = lend($catalog, $bookId, $memberLoans);
        echo "Lent book $bookId\n";
    } catch (BookNotFound $e) {
        echo "404: {$e->getMessage()}\n";
    } catch (BookshelfException $e) {
        echo "Refused: {$e->getMessage()}\n";
    }
}
```

```text
Lent book 1
Refused: No copies of 'Kindred' are available
404: No book with id 7
Refused: Member already has 2 of 2 loans
Refused: No copies of 'Dune' are available
```

The function does not know or care how failures are presented. The loop at the top maps them to outcomes, the same way
a web controller will map them to HTTP status codes in chapter 19 (404 for `BookNotFound`, 409 or 422 for the business
rule violations). Note that `lend()` returns a new catalog rather than modifying a global one, so a failed attempt
leaves the catalog unchanged.

## Key takeaways

- PHP has diagnostics (warnings, deprecations; execution continues) and exceptions (`Throwable`; stop unless caught).
  Fatal errors such as memory exhaustion cannot be caught.
- `Error` means the code is wrong; `Exception` means an operation failed. `catch (Exception)` does not catch `Error`;
  `catch (Throwable)` catches both.
- `catch` blocks match in order; use multi-catch `A | B`; `finally` always runs; never `return` from `finally`.
- Create small exception classes named after the problem, with a common base per module. Chain causes with
  `previous:`. Mark secret parameters `#[\SensitiveParameter]`.
- Convert warnings to `ErrorException` with `set_error_handler()`; use `set_exception_handler()` as the last resort.
- Production: `error_reporting=E_ALL`, `display_errors=Off`, `log_errors=On`. Watch deprecations.
- Throw when a function cannot keep its promise, return for expected outcomes, catch only where you can recover,
  translate or report.

## Exercises

1. **Order of output.** Predict exactly what this prints, then run it.

   ```php
   <?php
   function test(): string {
       try {
           echo "a";
           throw new RuntimeException("b");
       } catch (LogicException $e) {
           echo "c";
       } catch (RuntimeException $e) {
           echo $e->getMessage();
           return "d";
       } finally {
           echo "e";
       }
       return "f";
   }
   echo test(), "\n";
   ```

<details>
<summary>Solution</summary>

```text
abed
```

`a` is printed, the `RuntimeException` skips the `LogicException` block and is caught by the second `catch`, which
prints `b` and prepares to return `"d"`. Before the function actually returns, `finally` prints `e`. Then `echo`
prints the return value `d`. The final `return "f"` is never reached.

</details>

2. **Exception hierarchy for Bookshelf members.** Create a `MemberException` base class and two subclasses,
   `MemberNotFound` and `MembershipExpired`. Write `checkMember(array $members, int $id, string $today): void` that
   throws the right one (each member has an `expires` date as `'YYYY-MM-DD'`; string comparison works for that format).
   Catch them in one place and print different messages for each.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

class MemberException extends RuntimeException
{
}

final class MemberNotFound extends MemberException
{
}

final class MembershipExpired extends MemberException
{
}

/**
 * @param array<int, array{name: string, expires: string}> $members
 */
function checkMember(array $members, int $id, string $today): void
{
    $member = $members[$id] ?? throw new MemberNotFound("No member $id");

    if ($member['expires'] < $today) {
        throw new MembershipExpired("{$member['name']}'s membership expired on {$member['expires']}");
    }
}

$members = [1 => ['name' => 'Ada', 'expires' => '2027-01-31'], 2 => ['name' => 'Alan', 'expires' => '2026-03-01']];

foreach ([1, 2, 3] as $id) {
    try {
        checkMember($members, $id, '2026-10-11');
        echo "Member $id OK\n";
    } catch (MemberNotFound $e) {
        echo "Unknown member: {$e->getMessage()}\n";
    } catch (MembershipExpired $e) {
        echo "Please renew: {$e->getMessage()}\n";
    }
}
```

```text
Member 1 OK
Please renew: Alan's membership expired on 2026-03-01
Unknown member: No member 3
```

ISO dates (`YYYY-MM-DD`) sort correctly as strings. Chapter 15 replaces this with real date objects.

</details>

3. **Safe JSON loading.** Write `readJsonFile(string $path): array` that throws a `RuntimeException` with a clear
   message when the file does not exist, when it cannot be read, or when it does not contain a JSON object or array,
   chaining the original `JsonException` as `previous`. Test all three cases.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function readJsonFile(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("File not found: $path");
    }

    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException("Could not read $path");
    }

    try {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid JSON in $path: {$e->getMessage()}", previous: $e);
    }

    if (!is_array($data)) {
        throw new RuntimeException("Expected a JSON object or array in $path, got " . get_debug_type($data));
    }

    return $data;
}

$dir = sys_get_temp_dir();
file_put_contents("$dir/good.json", '{"books": 2}');
file_put_contents("$dir/bad.json", '{"books": ');
file_put_contents("$dir/scalar.json", '42');

foreach (['good.json', 'bad.json', 'scalar.json', 'missing.json'] as $name) {
    try {
        $data = readJsonFile("$dir/$name");
        echo $name, ': ', json_encode($data), "\n";
    } catch (RuntimeException $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
```

```text
good.json: {"books":2}
bad.json: Invalid JSON in /tmp/bad.json: Syntax error
scalar.json: Expected a JSON object or array in /tmp/scalar.json, got int
missing.json: File not found: /tmp/missing.json
```

Checking `is_file()` first gives a better message than the warning `file_get_contents()` would emit. The
`$json === false` check still matters for files that exist but cannot be read (permissions).

</details>

4. **Strict error handler.** Write `enableStrictErrors(): void` that installs an error handler converting every
   reported diagnostic into an `ErrorException`, except `E_DEPRECATED` and `E_USER_DEPRECATED`, which it writes to
   `error_log()` instead. Show that an undefined variable throws but `trigger_error('old', E_USER_DEPRECATED)` does not.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function enableStrictErrors(): void
{
    set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            error_log("Deprecated: $message in $file:$line");
            return true;   // handled: PHP will not print it
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

enableStrictErrors();

trigger_error('old', E_USER_DEPRECATED);
echo "still running\n";

try {
    echo $undefined;
} catch (ErrorException $e) {
    echo 'Caught: ', $e->getMessage(), "\n";
}
```

```text
Deprecated: old in /path/to/strict.php:21
still running
Caught: Undefined variable $undefined
```

Returning `true` tells PHP the diagnostic was handled, so its own output is suppressed.

</details>

5. **Retry with backoff.** Write `retry(callable $operation, int $attempts, int $delayMs): mixed` that calls
   `$operation()`, and if it throws a `RuntimeException`, waits and tries again, doubling the delay each time, up to
   `$attempts` tries. If all attempts fail, rethrow the last exception. Other exception types must not be retried. Test
   it with a closure that fails twice and then succeeds.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function retry(callable $operation, int $attempts, int $delayMs): mixed
{
    if ($attempts < 1) {
        throw new InvalidArgumentException('attempts must be at least 1');
    }

    for ($try = 1; ; $try++) {
        try {
            return $operation();
        } catch (RuntimeException $e) {
            if ($try >= $attempts) {
                throw $e;
            }
            echo "attempt $try failed ({$e->getMessage()}), retrying in {$delayMs} ms\n";
            usleep($delayMs * 1000);
            $delayMs *= 2;
        }
    }
}

$calls = 0;
$result = retry(function () use (&$calls): string {
    $calls++;
    if ($calls < 3) {
        throw new RuntimeException("service unavailable (call $calls)");
    }
    return 'catalog loaded';
}, attempts: 5, delayMs: 10);

echo $result, " after $calls calls\n";
```

```text
attempt 1 failed (service unavailable (call 1)), retrying in 10 ms
attempt 2 failed (service unavailable (call 2)), retrying in 20 ms
catalog loaded after 3 calls
```

`for ($try = 1; ; $try++)` has no condition, so the loop only ends by returning or throwing. Rethrowing with `throw $e`
keeps the original exception and its trace. A `LogicException` or `TypeError` is not caught here, so it propagates
immediately; retrying a bug never helps. In production code you would add random "jitter" to the delay so that many
clients do not retry in lockstep.

</details>

---
← Previous: [The type system and strict types](07-the-type-system-and-strict-types.md) | Next: [Classes and objects](09-classes-and-objects.md) →
