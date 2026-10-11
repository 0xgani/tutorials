# Chapter 6 — Functions and closures

## What you'll learn

- Defining functions, returning values, and what happens when you return nothing
- Parameters: defaults, named arguments, variadics, spreading arguments, and by-reference parameters
- Scope: why functions cannot see outside variables, and the (discouraged) ways around it
- Static variables and recursion
- Callables, anonymous functions, `use`, arrow functions and first-class callable syntax
- Higher-order functions: passing, returning and composing functions
- The pipe operator `|>` (PHP 8.5+)
- Splitting code across files with `require`, and the first Bookshelf function library

## Defining and calling functions

```php
<?php

function formatCents(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

echo formatCents(1250), "\n"; // $12.50
```

The parts:

- `function`, a name, a parameter list in parentheses, and a body in braces.
- `int $cents` is a parameter with a **type declaration**; `: string` declares the **return type**. Types are optional
  in PHP, but modern code uses them everywhere because they catch mistakes early. Chapter 7 covers them in full; in
  this chapter, read them as "this parameter must be an int".
- `return` ends the function and hands back a value.

Function names are **case-insensitive** (`FormatCents()` calls the same function), but always call a function with the
case you declared. PHP's own functions use `snake_case` (`array_map`, `str_contains`); in your own code, follow the
project's convention. Most modern code uses `camelCase` for functions and methods.

Functions declared at the top level of a file are available **before** their declaration in that file, because PHP
compiles the whole file before running it:

```php
<?php

echo early(), "\n";   // works

function early(): string
{
    return 'hoisted';
}
```

(Functions declared inside an `if` block or inside another function only exist once that code has run. You will rarely
need this; it is mostly a legacy trick.)

### Returning nothing

A function without a `return` statement, or with a bare `return;`, returns `null`. If a function never returns a useful
value, declare its return type as `void`, which documents the intent and forbids `return $something` inside it:

```php
function logLine(string $message): void
{
    echo date('H:i:s'), " $message\n";
}
```

## Parameters

### Default values

A parameter with a default is optional:

```php
<?php

function greet(string $name, string $greeting = 'Hello', string $punctuation = '!'): string
{
    return "$greeting, $name$punctuation";
}

echo greet('Ada'), "\n";             // Hello, Ada!
echo greet('Ada', 'Welcome'), "\n";  // Welcome, Ada!
```

- Defaults must be **constant expressions**: literals, constants, arrays of those, and (since PHP 8.1) `new` objects,
  e.g. `Clock $clock = new SystemClock()`. PHP 8.5 also allows static closures and first-class callables there.
- Put optional parameters **after** required ones. A required parameter after an optional one makes the default
  useless, and PHP emits a deprecation notice for it.
- Calling a function with too few arguments throws an `ArgumentCountError`. Calling a userland function with *too
  many* positional arguments is allowed; the extras are ignored (or collected by a variadic, below).

### Named arguments (PHP 8.0+)

You can pass arguments by parameter name, in any order, and skip optional ones in the middle:

```php
echo greet('Ada', punctuation: '?'), "\n";          // Hello, Ada?
echo greet(greeting: 'Hi', name: 'Grace'), "\n";    // Hi, Grace!
```

Named arguments make calls with several optional or boolean parameters readable. Compare:

```php
htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
htmlspecialchars($text, double_encode: false);
```

Two consequences to keep in mind:

- **Parameter names become part of your function's public interface.** Renaming a parameter breaks callers that use
  its name. Choose names carefully in code that others call.
- Positional arguments must come before named ones, and an unknown name is an error:

```text
PHP Fatal error:  Uncaught Error: Unknown named parameter $nme in demo.php:10
```

### Variadic parameters

`...$name` as the last parameter collects any number of remaining arguments into an array:

```php
<?php

function totalCents(int ...$amounts): int
{
    return array_sum($amounts);
}

echo totalCents(), "\n";             // 0
echo totalCents(125, 50, 300), "\n"; // 475
```

The type applies to each element: every extra argument must be an `int`.

### Spreading arguments

The same `...` at a call site unpacks an array into separate arguments. String keys become **named** arguments
(PHP 8.1+):

```php
<?php

function greet(string $name, string $greeting = 'Hello', string $punctuation = '!'): string
{
    return "$greeting, $name$punctuation";
}

$amounts = [10, 20];
echo intdiv(...[7, 2]), "\n";                    // 3

$args = ['name' => 'Alan', 'punctuation' => '.'];
echo greet(...$args), "\n";                      // Hello, Alan.
```

### By-reference parameters

Normally a function receives a copy of each argument (chapter 5's copy-on-write makes that cheap). Prefixing a
parameter with `&` passes the caller's variable itself, so the function can modify it:

```php
<?php

function addTag(array &$tags, string $tag): void
{
    $tags[] = $tag;
}

$tags = [];
addTag($tags, 'sf');
print_r($tags); // ['sf']
```

PHP's own sort functions work this way, as do `preg_match()`'s `$matches` and `str_replace()`'s `$count`. In your own
code, prefer returning a new value: `$tags = withTag($tags, 'sf')` is easier to follow and test. References do **not**
make code faster.

## Scope

A function has its own **local scope**. Variables defined outside a function are not visible inside it, and vice
versa:

```php
<?php

$limit = 5;

function checkLimit(): string
{
    return isset($limit) ? 'sees it' : 'no access';
}

echo checkLimit(), "\n"; // no access
```

This is deliberate. A function's behavior depends only on its arguments, which makes it predictable. To give a function
data, pass it as a parameter.

PHP does have ways to reach global variables from inside a function: the `global $limit;` statement, and the
`$GLOBALS['limit']` array. You will see them in old code and in WordPress. Avoid them in new code: any function that
reads globals can be broken by any other code that writes them, and it cannot be tested in isolation. Chapter 19 shows
how to pass shared things, such as a database connection, explicitly through *dependency injection*.

### Static variables

A `static` variable inside a function keeps its value between calls:

```php
<?php

function nextTicket(): int
{
    static $n = 0;
    return ++$n;
}

nextTicket();
nextTicket();
echo nextTicket(), "\n"; // 3
```

The initializer runs once, on the first call. (Since PHP 8.3 it may be any expression, not just a constant.) Static
variables are useful for caching an expensive computation inside a function, but they are hidden global state with the
same testing problems as globals. Use them sparingly.

### Recursion

A function can call itself:

```php
<?php

function factorial(int $n): int
{
    return $n <= 1 ? 1 : $n * factorial($n - 1);
}

echo factorial(20), "\n"; // 2432902008176640000
```

PHP has no tail-call optimization, and each call uses memory, so infinitely deep recursion ends in a fatal "Allowed
memory size exhausted" error (or, if `memory_limit` is `-1`, in a machine that slowly runs out of memory). Recursion
is fine for naturally recursive data such as trees and nested categories; for long linear processes, use a loop.

## Callables

Many PHP functions take another function as an argument: `array_map()`, `usort()`, `preg_replace_callback()`. A value
that can be called is a **callable**. PHP accepts several forms:

| Form | Example |
|---|---|
| A closure (anonymous function) | `function ($x) { return $x * 2; }` |
| An arrow function | `fn ($x) => $x * 2` |
| A first-class callable | `strtoupper(...)`, `$obj->method(...)`, `Book::create(...)` |
| A function name as a string | `'strtoupper'` |
| An object and method name as an array | `[$obj, 'method']` |
| A static method as a string or array | `'Book::create'`, `[Book::class, 'create']` |

The string and array forms are older and invisible to most tools (renaming the function does not update the string).
Prefer closures and the first-class callable syntax. `is_callable($x)` tests whether something can be called, and
`$callable(...$args)` calls it.

### Anonymous functions and `use`

An anonymous function (also called a *closure*) is a function without a name, used as a value:

```php
<?php

$double = function (int $x): int {
    return $x * 2;
};

echo $double(21), "\n"; // 42
print_r(array_map($double, [1, 2, 3])); // [2, 4, 6]
```

Note the semicolon after the closing brace: the whole thing is an assignment statement.

A closure, like a named function, cannot see outside variables by default. `use` imports them. Imported variables are
**copied at the moment the closure is created**:

```php
<?php

$limit = 5;
$check = function (int $n) use ($limit): bool {
    return $n <= $limit;
};

$limit = 99;               // too late: the closure captured 5
var_dump($check(10));      // bool(false)
```

To share the variable instead of copying it, import by reference with `use (&$limit)`. Then changes in either place are
visible in both. That is occasionally useful (for example, a callback that counts how often it was called) but makes
code harder to follow.

Inside a class, a closure automatically binds `$this`, so it can access the object's properties (chapter 9). A
`static function () { ... }` does not bind `$this`; marking closures `static` when they do not need the object avoids
keeping the object alive accidentally.

### Arrow functions (PHP 7.4+)

`fn (parameters) => expression` is a short closure for a single expression. It **captures outer variables
automatically**, by value, at creation time:

```php
<?php

$finePerDay = 25;
$fine = fn (int $days): int => $days * $finePerDay;  // no use() needed

echo $fine(4), "\n"; // 100
```

Arrow functions are the natural choice for callbacks to `array_map()`, `array_filter()` and `usort()`. Their limits:
the body is one expression (no statements, no loops), and they cannot modify outer variables (the capture is a copy).
For anything longer, use a full closure or a named function.

### First-class callable syntax (PHP 8.1+)

Writing a function call with `...` as the only argument creates a closure from that function **without calling it**:

```php
<?php

$len = strlen(...);
echo $len('Dune'), "\n";   // 4
echo get_class($len), "\n"; // Closure

print_r(array_map(strtoupper(...), ['a', 'b'])); // ['A', 'B']
```

It works with methods too: `$book->label(...)`, `Book::fromArray(...)`. Unlike the string `'strtoupper'`, this form is
checked by the engine and understood by tools.

## Higher-order functions

A function that takes or returns functions is a *higher-order function*. Returning a closure lets you build specialized
functions from general ones:

```php
<?php

function finePolicy(int $centsPerDay, int $capCents): Closure
{
    return fn (int $daysOverdue): int => min($daysOverdue * $centsPerDay, $capCents);
}

$standardFine = finePolicy(25, 500);
$premiumFine = finePolicy(10, 200);

echo $standardFine(4), ' ', $standardFine(40), ' ', $premiumFine(40), "\n"; // 100 500 200
```

The returned closure "remembers" `$centsPerDay` and `$capCents`. `Closure` is the class of all anonymous functions,
and the right return type here; `callable` would also accept strings and arrays.

Composing functions means feeding the output of one into the next. A small helper makes this generic:

```php
<?php

function compose(callable ...$functions): Closure
{
    return function (mixed $value) use ($functions): mixed {
        foreach ($functions as $function) {
            $value = $function($value);
        }
        return $value;
    };
}

$normalize = compose(trim(...), strtolower(...), ucwords(...));
echo $normalize('  the LEFT hand  '), "\n"; // The Left Hand
```

## The pipe operator (PHP 8.5+)

Nested calls read inside-out, and temporary variables add noise:

```php
$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($title))), '-');
```

PHP 8.5's **pipe operator** `|>` passes the value on its left as the single argument to the callable on its right, and
chains left to right:

```php
<?php

$title = '  The Left Hand of Darkness ';

$slug = $title
    |> trim(...)
    |> strtolower(...)
    |> (fn (string $s): string => preg_replace('/[^a-z0-9]+/', '-', $s))
    |> (fn (string $s): string => trim($s, '-'));

var_dump($slug); // string(25) "the-left-hand-of-darkness"
```

The rules:

- The right-hand side must be a callable that accepts **one** required argument: a first-class callable such as
  `trim(...)`, a closure in a variable, a method callable such as `$formatter->format(...)`, or a parenthesized arrow
  function.
- Arrow functions **must be in parentheses**; otherwise PHP stops with "Arrow functions on the right hand side of |>
  must be parenthesized".
- When a function needs more than the piped value (like `preg_replace()` above, or `trim()` with a character list),
  wrap it in an arrow function.
- Functions with by-reference parameters (such as `sort()`) cannot be used in a pipe.
- `|>` binds more loosely than arithmetic and `.` but more tightly than comparisons, so
  `$a . $b |> strtoupper(...)` uppercases the concatenation and `$x |> f(...) === 10` compares the result.

Use pipes when they make a transformation read like a recipe. Do not force everything into one; a well-named
intermediate variable is often clearer.

> **If you know Node.js:** `|>` does what a chain of `.then()` callbacks or a `pipe()` helper from a functional library
> does, but synchronously and built into the language.

## Splitting code across files

As a program grows, you move functions into separate files and load them with one of four constructs:

| Construct | If the file is missing | Loads twice? |
|---|---|---|
| `require 'file.php'` | Fatal error, script stops | Yes, if called twice |
| `require_once 'file.php'` | Fatal error | No, second call does nothing |
| `include 'file.php'` | Warning, script continues | Yes |
| `include_once 'file.php'` | Warning | No |

For files of functions or classes, use `require_once` (loading a function definition twice is a fatal "cannot
redeclare" error). For templates that should be rendered several times, `include` or `require` is right. Always build
the path from `__DIR__` so it does not depend on the current working directory:

```php
require_once __DIR__ . '/functions.php';
```

An included file can `return` a value, which is a common way to write configuration files:

```php
<?php
// config.php
return [
    'loanDays' => 21,
    'finePerDayCents' => 25,
];
```

```php
$config = require __DIR__ . '/config.php';
echo $config['loanDays'];
```

From chapter 12 onward, Composer's autoloader loads classes automatically, and you will rarely write `require` yourself
except for one line at the start of the program.

## Bookshelf: a function library

Here is the first proper structure for Bookshelf: a file of small, focused functions, and a script that uses them.
Each function does one thing, takes everything it needs as parameters and returns a result, which makes it easy to test
(chapter 20).

`functions.php`:

```php
<?php

/**
 * Format an amount in cents as dollars, e.g. 1250 -> "$12.50".
 */
function formatCents(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

/**
 * Turn a title into a URL slug, e.g. "The Left Hand" -> "the-left-hand".
 */
function slugify(string $title): string
{
    return $title
        |> trim(...)
        |> mb_strtolower(...)
        |> (fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '-', $s))
        |> (fn (string $s): string => trim($s, '-'));
}

/**
 * Validate an ISBN-13, ignoring spaces and dashes.
 */
function isValidIsbn13(string $input): bool
{
    $isbn = preg_replace('/[\s-]+/', '', $input);
    if (preg_match('/^\d{13}$/', $isbn) !== 1) {
        return false;
    }

    $sum = 0;
    foreach (str_split($isbn) as $i => $digit) {
        $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
    }

    return $sum % 10 === 0;
}

/**
 * Describe a loan by the number of days until it is due (negative = overdue).
 */
function loanStatus(int $daysLeft): string
{
    return match (true) {
        $daysLeft < 0 => 'overdue',
        $daysLeft === 0 => 'due today',
        $daysLeft <= 3 => 'due soon',
        default => 'on loan',
    };
}

/**
 * Build a fine calculator: so many cents per overdue day, up to a cap.
 */
function finePolicy(int $centsPerDay, int $capCents): Closure
{
    return fn (int $daysLeft): int => $daysLeft >= 0 ? 0 : min(-$daysLeft * $centsPerDay, $capCents);
}

/**
 * Return the books with at least one copy available, as a list.
 *
 * @param list<array{title: string, copies: int}> $catalog
 * @return list<array{title: string, copies: int}>
 */
function availableBooks(array $catalog): array
{
    return array_values(array_filter($catalog, fn (array $book): bool => $book['copies'] > 0));
}
```

The `@param list<array{title: string, copies: int}>` docblock describes the array shape more precisely than the
`array` type can. PHP ignores it; static analysers and IDEs use it (chapter 20). Chapter 9 replaces these arrays with
classes, which makes such comments unnecessary.

`report.php`:

```php
<?php

require_once __DIR__ . '/functions.php';

$loans = [
    ['title' => 'Dune', 'member' => 'Ada', 'daysLeft' => 5],
    ['title' => 'Emma', 'member' => 'Alan', 'daysLeft' => -4],
    ['title' => 'The Left Hand of Darkness', 'member' => 'Grace', 'daysLeft' => -30],
];

$fineFor = finePolicy(centsPerDay: 25, capCents: 500);

foreach ($loans as ['title' => $title, 'member' => $member, 'daysLeft' => $daysLeft]) {
    printf(
        "%-28s %-6s %-9s %8s  /books/%s\n",
        $title,
        $member,
        loanStatus($daysLeft),
        formatCents($fineFor($daysLeft)),
        slugify($title),
    );
}

var_dump(isValidIsbn13('978-0-441-01359-3'), isValidIsbn13('978-0-441-01359-4'));
```

```bash
php report.php
```

```text
Dune                         Ada    on loan      $0.00  /books/dune
Emma                         Alan   overdue      $1.00  /books/emma
The Left Hand of Darkness    Grace  overdue      $5.00  /books/the-left-hand-of-darkness
bool(true)
bool(false)
```

Note the trailing comma after the last `printf()` argument; PHP allows trailing commas in argument and parameter lists,
which keeps diffs clean when arguments are added. `slugify()` uses the PHP 8.5 pipe operator; on PHP 8.4 or older,
replace it with nested calls or intermediate variables.

## Designing good functions

A few habits that pay off in PHP as in any language:

- **One job per function**, with a name that says what it returns (`availableBooks()`) or does (`sendReminder()`).
- **Take what you need as parameters, return what you produce.** Avoid globals, static state and printing from deep
  inside logic. A function that returns a string can be printed, logged, tested or sent as JSON; one that echoes can
  only be printed.
- **Avoid boolean flag parameters.** `formatBook($book, true)` is unreadable; split it into two functions or use a
  named argument (`formatBook($book, withAuthor: true)`), or an enum (chapter 11).
- **Return early** to handle invalid cases first, rather than nesting the main logic inside `if` blocks.
- **Type every parameter and return value** (chapter 7).

## Key takeaways

- Functions are declared with `function name(params): returnType { ... }`. No `return` means `null`; declare `void`
  when nothing useful is returned.
- Defaults must be constant expressions and come after required parameters. Named arguments (`f(x: 1)`) skip optional
  parameters and make calls readable, but make parameter names part of your API.
- `...$rest` collects arguments; `f(...$array)` spreads them, with string keys as named arguments.
- Functions cannot see outside variables. Pass data in; avoid `global`, `$GLOBALS` and `static` state.
- Closures import variables with `use`, copied at creation; arrow functions `fn () =>` capture automatically.
- `strlen(...)` creates a closure from any function or method; prefer it over string callables.
- PHP 8.5's `|>` pipes a value through single-argument callables; parenthesize arrow functions in a pipe.
- Load function files with `require_once __DIR__ . '/file.php'`.

## Exercises

1. **Named arguments.** Write `formatBook(string $title, string $author, ?int $year = null, bool $upper = false)`
   that returns `"Dune by Frank Herbert (1965)"` (no year part when `$year` is `null`, everything upper-cased when
   `$upper` is true). Call it once positionally and once with named arguments, skipping `$year` but setting `$upper`.

<details>
<summary>Solution</summary>

```php
<?php

function formatBook(string $title, string $author, ?int $year = null, bool $upper = false): string
{
    $text = "$title by $author";
    if ($year !== null) {
        $text .= " ($year)";
    }

    return $upper ? mb_strtoupper($text) : $text;
}

echo formatBook('Dune', 'Frank Herbert', 1965), "\n";
echo formatBook('Kindred', 'Octavia E. Butler', upper: true), "\n";
```

```text
Dune by Frank Herbert (1965)
KINDRED BY OCTAVIA E. BUTLER
```

`?int` means "an int or null" (chapter 7).

</details>

2. **Capture semantics.** Predict the output, then run it. Explain each line.

   ```php
   <?php
   $rate = 10;
   $a = function ($d) use ($rate) { return $d * $rate; };
   $b = function ($d) use (&$rate) { return $d * $rate; };
   $c = fn ($d) => $d * $rate;
   $rate = 20;
   echo $a(2), ' ', $b(2), ' ', $c(2), "\n";
   ```

<details>
<summary>Solution</summary>

```text
20 40 20
```

`$a` copied `$rate` (10) when it was created. `$b` holds a reference to the variable, so it sees the later value 20.
The arrow function `$c` captured `$rate` by value at creation, like `$a`, so it also uses 10.

</details>

3. **A counter factory.** Write `makeCounter(int $start = 0): Closure` that returns a closure; each call to the closure
   returns the next number (`$start + 1`, `$start + 2`, ...). Two counters must be independent.

<details>
<summary>Solution</summary>

```php
<?php

function makeCounter(int $start = 0): Closure
{
    $n = $start;
    return function () use (&$n): int {
        return ++$n;
    };
}

$a = makeCounter();
$b = makeCounter(100);
$a();
$a();
echo $a(), ' ', $b(), "\n"; // 3 101
```

The closure needs `use (&$n)` because it must modify the captured variable, and an arrow function cannot do that. Each
call to `makeCounter()` creates a new `$n`, so counters do not share state. (A small class with a property is the more
common way to model this in PHP, chapter 9.)

</details>

4. **Sorting with callables.** Given `$titles = ['emma', 'Dune', 'beloved', 'Kindred']`, sort them case-insensitively
   with `usort()` three ways: with a closure, with an arrow function, and with a first-class callable of a built-in
   function.

<details>
<summary>Solution</summary>

```php
<?php

$titles = ['emma', 'Dune', 'beloved', 'Kindred'];

$t1 = $titles;
usort($t1, function (string $a, string $b): int {
    return strcasecmp($a, $b);
});

$t2 = $titles;
usort($t2, fn (string $a, string $b): int => strcasecmp($a, $b));

$t3 = $titles;
usort($t3, strcasecmp(...));

echo implode(', ', $t1), "\n", implode(', ', $t2), "\n", implode(', ', $t3), "\n";
```

```text
beloved, Dune, emma, Kindred
beloved, Dune, emma, Kindred
beloved, Dune, emma, Kindred
```

`strcasecmp()` already has exactly the comparator contract (two strings in, negative/zero/positive out), so it can be
passed directly.

</details>

5. **Pipeline.** Using the pipe operator, write `normalizeIsbn(string $input): ?string` that strips spaces and dashes,
   upper-cases a trailing `x`, and returns the result if it is 10 or 13 characters long, or `null` otherwise. Then write
   the same function without `|>`.

<details>
<summary>Solution</summary>

```php
<?php

function normalizeIsbn(string $input): ?string
{
    $isbn = $input
        |> (fn (string $s): string => preg_replace('/[\s-]+/', '', $s))
        |> strtoupper(...);

    return preg_match('/^(\d{9}[\dX]|\d{13})$/', $isbn) === 1 ? $isbn : null;
}

function normalizeIsbnClassic(string $input): ?string
{
    $isbn = strtoupper(preg_replace('/[\s-]+/', '', $input));

    return preg_match('/^(\d{9}[\dX]|\d{13})$/', $isbn) === 1 ? $isbn : null;
}

var_dump(normalizeIsbn('0-8044-2957-x'), normalizeIsbn('978 0441013593'), normalizeIsbn('12-34'));
var_dump(normalizeIsbnClassic('0-8044-2957-x'));
```

```text
string(10) "080442957X"
string(13) "9780441013593"
NULL
string(10) "080442957X"
```

The function only normalizes the format; checking the check digit is a separate job (`isValidIsbn13()` above). With
only two steps, the classic version is just as readable; pipes pay off for longer chains.

</details>

6. **Memoize.** Write `memoize(callable $fn): Closure` that returns a version of `$fn` which caches results by its
   arguments, so repeated calls with the same arguments do not recompute. Use it to make a slow recursive Fibonacci fast.

<details>
<summary>Solution</summary>

```php
<?php

function memoize(callable $fn): Closure
{
    $cache = [];

    return function (mixed ...$args) use ($fn, &$cache): mixed {
        $key = serialize($args);
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = $fn(...$args);
        }
        return $cache[$key];
    };
}

$fib = memoize(function (int $n) use (&$fib): int {
    return $n < 2 ? $n : $fib($n - 1) + $fib($n - 2);
});

echo $fib(90), "\n"; // 2880067194370816120
```

`serialize()` turns the argument list into a string key (fine for scalars and arrays). The cache is imported by
reference so that writes persist between calls. The inner function refers to `$fib` by reference too: when the closure
is created, `$fib` does not exist yet, and the reference lets it see the memoized version assigned afterwards, so the
recursive calls also hit the cache. Without memoization, `fib(90)` would take longer than the age of the universe.

</details>

---
← Previous: [Arrays](05-arrays.md) | Next: [The type system and strict types](07-the-type-system-and-strict-types.md) →
