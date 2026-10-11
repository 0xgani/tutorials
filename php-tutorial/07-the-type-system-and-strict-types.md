# Chapter 7 — The type system and strict types

## What you'll learn

- What type declarations check, when they are checked and what a `TypeError` tells you
- Coercive mode versus `declare(strict_types=1)`, and exactly which calls strict mode affects
- Every kind of type you can declare: scalars, `array`, `callable`, `iterable`, `object`, `mixed`, class types,
  nullable, union, intersection and DNF types, `void`, `never`, `null`, `true`, `false`, `static`
- Typed properties and the "uninitialized" state
- What the language cannot express (generics, array shapes) and how docblock types fill the gap
- How to design with types: validate at the edges, be strict inside

## Why types

PHP checks types **at runtime**: when a function is called, its arguments are checked against the declared parameter
types, and when it returns, the value is checked against the return type. If a check fails, PHP throws a `TypeError`.

```php
<?php

declare(strict_types=1);

function loanDays(int $weeks): int
{
    return $weeks * 7;
}

echo loanDays(3), "\n";       // 21
echo loanDays('three'), "\n"; // TypeError
```

```text
21
PHP Fatal error:  Uncaught TypeError: loanDays(): Argument #1 ($weeks) must be of type int, string given, called in demo.php on line 11 and defined in demo.php:5
```

The error names the function, the argument position and name, what was expected and what was given, and both the
call site and the definition. Without the declaration, `'three' * 7` would have thrown a less helpful error deep
inside the function, or, with a value like `'3 weeks'`, silently returned a wrong result with only a warning.

Types do three jobs:

1. **They catch bugs at the boundary** where a wrong value enters a function, instead of wherever it finally causes
   damage.
2. **They document** what a function expects and returns, in a form that cannot go out of date.
3. **They power tools.** IDEs complete methods based on types, and static analysers (chapter 20) use them to find bugs
   without running the code.

## Coercive mode and strict mode

By default PHP runs in **coercive** mode: if a scalar argument does not match a scalar parameter type, PHP tries to
convert it, using the juggling rules from chapter 2.

```php
<?php

// No declare(strict_types=1): coercive mode.
function days(int $d): int
{
    return $d;
}

var_dump(days('21'));   // int(21): numeric string converted
var_dump(days(21.0));   // int(21): integral float converted
var_dump(days(true));   // int(1)
days('21 days');        // TypeError: not a numeric string
days(null);             // TypeError: null is never coerced for userland functions
```

Coercion is limited: non-numeric strings, `null`, arrays and objects are rejected. Converting a float with a
fractional part (`days(21.5)`) truncates it with a deprecation notice.

`declare(strict_types=1);` as the **very first statement** of a file switches that file to **strict** mode, where
scalar types must match exactly:

```php
<?php

declare(strict_types=1);

function days(int $d): int
{
    return $d;
}

function half(float $x): float
{
    return $x / 2;
}

days('21');           // TypeError: must be of type int, string given
var_dump(half(3));    // float(1.5): the one exception, int is accepted for float
var_dump(strlen(123)); // TypeError: applies to PHP's own functions too
```

The single exception is **int-to-float widening**: an `int` is always accepted where a `float` is expected, because no
information is lost.

### Exactly what strict mode affects

`strict_types` is a per-file setting, and it controls the calls **made from** that file, not the functions **defined**
in it:

```text
 caller file          callee file           check applied to the arguments
 ───────────          ───────────           ──────────────────────────────
 strict_types=1  ──►  (either mode)         strict
 coercive        ──►  strict_types=1        coercive (!)
 (either mode)        return statements     the mode of the file containing the return
```

So a library that declares strict types does not force strictness on its callers, and your strict file stays strict
even when calling a library written without it. Return values are checked using the mode of the file the function is
defined in.

One more edge: when PHP's own functions call *your* callback (for example `array_map()` calling a closure), the
arguments are passed in coercive mode, even if the closure was written in a strict file:

```php
<?php

declare(strict_types=1);

var_dump(array_map(fn (int $x) => $x, ['5'])); // [5]: '5' was coerced
```

### Which mode to use

**Use `declare(strict_types=1);` in every file you write.** It turns silent conversions into immediate, clear errors,
and it is the norm in modern PHP projects; most coding standards require it. The rest of this tutorial uses it in every
complete file. The conversions you actually want, such as turning form input into integers, belong in one explicit
place at the edge of your program (see "Validate at the edges" below).

Even in coercive mode, passing `null` to a non-nullable parameter of a **built-in** function is deprecated since
PHP 8.1 (`strlen(null)` warns: "Passing null to parameter #1 ($string) of type string is deprecated"). Upgrading old
code often turns up many of these.

## The types you can declare

### Scalar and built-in types

| Type | Accepts |
|---|---|
| `int`, `float`, `string`, `bool` | The scalar types (in strict mode, `int` is also accepted for `float`) |
| `array` | Any array |
| `callable` | Anything callable: closures, function-name strings, `[$obj, 'method']` (parameters only, not properties) |
| `iterable` | An array or a `Traversable` object; since PHP 8.2 it is an alias for `Traversable\|array` |
| `object` | Any object |
| `mixed` | Anything, including `null`. Equivalent to having no type, but says "I thought about it" |
| `ClassName`, `InterfaceName` | An instance of that class, a subclass, or a class implementing that interface |
| `self`, `parent`, `static` | Within a class: the class itself, its parent, or the class the method was called on (chapter 10) |

### Nullable types

`?T` accepts `T` or `null`. It is common for "might not exist" results:

```php
<?php

declare(strict_types=1);

function findTitle(int $id): ?string
{
    $titles = [1 => 'Dune', 3 => 'Emma'];
    return $titles[$id] ?? null;
}

var_dump(findTitle(1), findTitle(2)); // string(4) "Dune" NULL
```

A nullable **parameter** is not the same as an optional one. `?string $x` must still be passed (possibly as `null`);
`?string $x = null` may be omitted.

Writing `string $x = null` (a non-nullable type with a `null` default) used to make the parameter implicitly nullable.
This is **deprecated since PHP 8.4**:

```text
Deprecated: maybe(): Implicitly marking parameter $x as nullable is deprecated, the explicit nullable type must be used instead
```

Write `?string $x = null` explicitly.

### Union types (PHP 8.0+)

`A|B` accepts a value of either type:

```php
function formatId(int|string $id): string
{
    return is_int($id) ? sprintf('#%05d', $id) : strtoupper($id);
}
```

`?T` is shorthand for `T|null`. Inside a function that takes a union, you narrow the type with checks such as
`is_int()`, `is_string()` or `instanceof`, and static analysers understand those checks.

The literal types `false` and `true` exist for unions such as `string|false`, which is how many built-in functions are
declared (`strpos()` returns `int|false`). PHP 8.2 allowed `null`, `false` and `true` as standalone types too. In your
own code, prefer `?string` (return `null`) over `string|false` for "no result"; `false` as a sentinel is a legacy
pattern.

### Intersection types (PHP 8.1+) and DNF types (PHP 8.2+)

`A&B` accepts an object that is an instance of **both** `A` and `B`, typically two interfaces:

```php
<?php

declare(strict_types=1);

function countItems(Countable&ArrayAccess $collection): int
{
    return count($collection);
}

var_dump(countItems(new ArrayObject([1, 2]))); // int(2): ArrayObject implements both
countItems([1]);  // TypeError: must be of type Countable&ArrayAccess, array given
```

**Disjunctive normal form** (DNF) types combine the two: a union of intersections, each in parentheses, for example
`(Countable&ArrayAccess)|null`. You will mostly meet these in library code.

### Return-only types: `void`, `never`, `static`

- **`void`** means the function returns nothing. A `return;` without a value is allowed; `return null;` is not.
- **`never`** (PHP 8.1+) means the function never returns normally: it always throws, exits, or loops forever. Use it
  for helpers like `abort()` or `fail()`. A `never` function that finishes normally throws a `TypeError`. Static
  analysers know that code after a call to a `never` function is unreachable.
- **`static`** (PHP 8.0+) means "an instance of the class this method was called on", used by fluent and factory
  methods (chapter 10).

```php
<?php

declare(strict_types=1);

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function bookTitle(?string $title): string
{
    return $title ?? fail('Book has no title');
}
```

## Typed properties (PHP 7.4+)

Class properties can be typed, with the same types as parameters except `callable` and `void`/`never`:

```php
<?php

declare(strict_types=1);

class Book
{
    public string $title;
    public ?int $year = null;
    public int $pages;
}

$book = new Book();
var_dump($book->year);     // NULL: it has a default

echo $book->title;         // Error
```

```text
NULL
PHP Fatal error:  Uncaught Error: Typed property Book::$title must not be accessed before initialization in demo.php:15
```

A typed property without a default value starts in the **uninitialized** state, which is different from `null`:
reading it is an error, and `isset()` returns `false`. That is a feature. It is impossible to forget to set a required
property and silently work with `null`; the constructor should initialize every property (chapter 9 shows constructor
promotion, which makes this easy).

Assignments to typed properties are checked too, using the strict or coercive mode of the file **where the assignment
happens**:

```php
$book->pages = '412'; // strict mode: TypeError, Cannot assign string to property Book::$pages of type int
```

Class constants can also be typed since PHP 8.3 (`const int MAX_LOANS = 3;`), covered in chapter 9.

## What the language cannot express

PHP's type system is checked at runtime, which keeps it simpler than a compile-time system. Some things you will want
to say cannot be declared:

- **What an array contains.** `array` says nothing about its keys or values. There is no `array<string>`.
- **Generics.** You cannot write `Collection<Book>`.
- **Value constraints**: "a positive int", "a non-empty string".

The PHP community fills this gap with **docblock types**, which PHP ignores but static analysers (PHPStan, Psalm) and
IDEs read and check:

```php
<?php

declare(strict_types=1);

/**
 * @param list<string>                         $titles    a list (keys 0..n-1) of strings
 * @param array<int, string>                   $byId      int keys, string values
 * @param array{title: string, year?: int}     $book      an array "shape"; year is optional
 * @param positive-int                         $limit
 * @param non-empty-string                     $query
 * @return array<string, list<string>>         titles grouped by author
 */
function search(array $titles, array $byId, array $book, int $limit, string $query): array
{
    // ...
    return [];
}
```

The native types still come first and are enforced at runtime; the docblock adds the precision. Chapter 20 sets up
PHPStan, which reports a call like `search(['a'], [], ['title' => 1], 0, '')` as three errors without running it.
In application code, a better answer to "array of what?" is often a class: a `Book` object instead of an
`array{title: string, ...}` shape (chapter 9), or a typed collection class (chapter 13).

## Validate at the edges, be strict inside

Data from outside your program (form fields, query strings, CSV files, JSON, environment variables) arrives as strings
or loosely typed arrays. Inside your program you want precise types. The clean design is to convert **once, at the
boundary**, with explicit validation, and pass only well-typed values inward:

```text
  outside world            boundary                         inside (strict types)
  ─────────────            ────────                         ─────────────────────
  $_GET['weeks'] = "3" ──► parseWeeks(string): int  ──────► loanDays(int $weeks): int
  "three"          ──────► throws InvalidArgumentException  (never reached)
```

```php
<?php

declare(strict_types=1);

function parseWeeks(string $input): int
{
    $weeks = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    if ($weeks === false) {
        throw new InvalidArgumentException("Loan length must be 1-12 weeks, got '$input'");
    }

    return $weeks;
}

function loanDays(int $weeks): int
{
    return $weeks * 7;
}

foreach (['3', '12', 'three', '0'] as $input) {
    try {
        $days = loanDays(parseWeeks($input));
        echo "$input => $days days\n";
    } catch (InvalidArgumentException $e) {
        echo "$input => {$e->getMessage()}\n";
    }
}
```

```text
3 => 21 days
12 => 84 days
three => Loan length must be 1-12 weeks, got 'three'
0 => Loan length must be 1-12 weeks, got '0'
```

This separation is why strict mode is practical: the only place that deals with strings-that-should-be-numbers is the
parser, and it does so deliberately. Chapter 8 covers exceptions; chapter 16 applies the pattern to web forms.

## Designing with types

- **Type everything**: parameters, return values, properties. Leave a type off only when it truly cannot be
  expressed, and then use `mixed` to show it was deliberate.
- **Prefer narrow types.** `int` over `int|string`, a specific class over `object`, `Closure` over `callable` when you
  only accept closures.
- **Use `null` deliberately.** A nullable return forces every caller to handle `null`. If "not found" is exceptional,
  throw instead; if it is normal, `?T` is right. Avoid nullable parameters that switch behavior; split the function.
- **Replace primitive flags and codes with types.** A string `'standard'|'premium'` member type becomes an enum
  (chapter 11); an ISBN string becomes an `Isbn` value object that can only hold valid ISBNs (chapter 9).
- **Do not fight the checks with casts.** `(int) $x` to silence a `TypeError` hides the real bug, which is that a string
  reached a place where it should not be.

## Bookshelf: typing the function library

Here is part of the Bookshelf function library from chapter 6, now strict, with a boundary function for the loan
length chosen in a form:

```php
<?php

declare(strict_types=1);

const FINE_PER_DAY_CENTS = 25;
const FINE_CAP_CENTS = 500;

function formatCents(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

function fineCents(int $daysLeft): int
{
    if ($daysLeft >= 0) {
        return 0;
    }

    return min(-$daysLeft * FINE_PER_DAY_CENTS, FINE_CAP_CENTS);
}

/**
 * @param list<array{title: string, daysLeft: int}> $loans
 */
function totalFines(array $loans): int
{
    return array_sum(array_map(fn (array $loan): int => fineCents($loan['daysLeft']), $loans));
}

/**
 * Boundary: parse a member type typed by staff.
 *
 * @return 'standard'|'premium'|'staff'
 */
function parseMemberType(string $input): string
{
    $type = strtolower(trim($input));

    return match ($type) {
        'standard', 'premium', 'staff' => $type,
        default => throw new InvalidArgumentException("Unknown member type '$input'"),
    };
}

$loans = [
    ['title' => 'Dune', 'daysLeft' => 5],
    ['title' => 'Emma', 'daysLeft' => -4],
    ['title' => 'Kindred', 'daysLeft' => -30],
];

echo formatCents(totalFines($loans)), "\n";   // $6.00
echo parseMemberType(' Premium '), "\n";      // premium
```

The literal-string union in `@return 'standard'|'premium'|'staff'` is understood by static analysers. It is also a sign
that this value wants to be an enum, which is exactly what it becomes in chapter 11.

## Key takeaways

- Parameter, return and property types are checked at runtime; a mismatch throws a `TypeError` with a precise message.
- Coercive mode converts compatible scalars; `declare(strict_types=1)` requires exact scalar types (except int-to-float).
  Put it at the top of every file.
- Strict mode is decided by the **calling** file for arguments and by the **defining** file for return values.
  Callbacks invoked by built-in functions are always coercive.
- Know the type vocabulary: `?T`, `A|B`, `A&B`, `(A&B)|null`, `mixed`, `void`, `never`, `static`, `iterable`.
- `T $x = null` is deprecated since PHP 8.4; write `?T $x = null`.
- Typed properties start uninitialized, not `null`; reading them before assignment is an error.
- Docblock types (`list<T>`, `array<K, V>`, array shapes, `positive-int`) add precision for static analysers.
- Convert and validate untrusted data once at the boundary; keep everything inside strictly typed.

## Exercises

1. **Coercive or strict?** For each call, say what happens in coercive mode and in strict mode, then verify:
   `f('5')`, `f(5.0)`, `f(5.5)`, `f(null)`, `g(5)`, where `function f(int $x): int` and `function g(float $x): float`.

<details>
<summary>Solution</summary>

| Call | Coercive mode | Strict mode |
|---|---|---|
| `f('5')` | `5` | `TypeError` |
| `f(5.0)` | `5` | `TypeError` |
| `f(5.5)` | `5`, with a deprecation notice | `TypeError` |
| `f(null)` | `TypeError` | `TypeError` |
| `g(5)` | `5.0` | `5.0` (int-to-float widening is allowed) |

```php
<?php

declare(strict_types=1);   // remove this line to test coercive mode

function f(int $x): int { return $x; }
function g(float $x): float { return $x; }

foreach ([fn () => f('5'), fn () => f(5.0), fn () => f(5.5), fn () => f(null), fn () => g(5)] as $i => $call) {
    try {
        var_dump($call());
    } catch (TypeError $e) {
        echo "call $i: TypeError\n";
    }
}
```

The calls are wrapped in arrow functions defined in this file, so the calling file is this one and its mode applies.

</details>

2. **Add the types.** Add the most precise native types (and docblock types where native types fall short) to this
   function, then make it strict:

   ```php
   function averageYear($books)
   {
       if (count($books) === 0) {
           return null;
       }
       return array_sum(array_column($books, 'year')) / count($books);
   }
   ```

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/**
 * @param list<array{title: string, year: int}> $books
 */
function averageYear(array $books): ?float
{
    if (count($books) === 0) {
        return null;
    }

    return array_sum(array_column($books, 'year')) / count($books);
}

var_dump(averageYear([['title' => 'Dune', 'year' => 1965], ['title' => 'Emma', 'year' => 1815]])); // float(1890)
var_dump(averageYear([])); // NULL
```

The division can produce an int when it divides exactly (`3780 / 2` is `int(1890)`), but the return type `?float`
converts it: int-to-float is allowed even in strict mode, so callers always get a float. Returning `null` for an empty
list is a design choice; throwing an exception would also be reasonable.

</details>

3. **Uninitialized versus null.** Create a class with `public ?string $subtitle;` (no default) and
   `public ?string $series = null;`. Show what `isset()`, `var_dump()` and reading each property do on a fresh object.
   Why might the uninitialized state be preferable to defaulting everything to `null`?

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

class Book
{
    public ?string $subtitle;
    public ?string $series = null;
}

$book = new Book();
var_dump(isset($book->subtitle), isset($book->series)); // false false
var_dump($book->series);                                 // NULL

try {
    var_dump($book->subtitle);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
```

```text
bool(false)
bool(false)
NULL
Typed property Book::$subtitle must not be accessed before initialization
```

`isset()` cannot tell them apart, but reading can. With a `null` default, forgetting to set the property goes unnoticed
and `null` spreads through the program. Uninitialized properties make the omission fail loudly at the first read.

</details>

4. **A `never` helper.** Write `abortWith(int $status, string $message): never` that prints the message to standard
   error (`fwrite(STDERR, ...)`) and exits with the status. Use it in a function `requireEnv(string $name): string` that
   returns the environment variable `$name` (`getenv($name)`) or aborts if it is missing. Why does `requireEnv()` not
   need a `return` after the call to `abortWith()`?

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function abortWith(int $status, string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

function requireEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        abortWith(78, "Missing environment variable $name");
    }

    return $value;
}

echo requireEnv('HOME'), "\n";
echo requireEnv('BOOKSHELF_DB'), "\n";
```

```bash
php env.php; echo "exit status: $?"
```

```text
/home/ada
Missing environment variable BOOKSHELF_DB
exit status: 78
```

Because `abortWith()` is declared `never`, PHP and static analysers know that execution cannot continue past it, so
after the `if` block `$value` is known to be a non-empty string. (`getenv()` returns `string|false`; exit status 78 is
the conventional "configuration error" code.)

</details>

5. **A boundary parser.** Write `parseLoanQuery(array $query): array` that takes something like `$_GET`
   (`['member' => '42', 'days' => '14', 'renew' => '1']`) and returns `['member' => int, 'days' => int, 'renew' => bool]`
   with these rules: `member` is required and a positive int; `days` defaults to 21 and must be 7–42; `renew` defaults
   to `false` and accepts `'1'`, `'0'`, `'true'`, `'false'`. Throw `InvalidArgumentException` with a clear message for
   anything else. Declare the return shape in a docblock.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $query
 * @return array{member: positive-int, days: int<7, 42>, renew: bool}
 */
function parseLoanQuery(array $query): array
{
    $member = filter_var($query['member'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($member === false) {
        throw new InvalidArgumentException('member must be a positive integer');
    }

    $days = filter_var($query['days'] ?? '21', FILTER_VALIDATE_INT, ['options' => ['min_range' => 7, 'max_range' => 42]]);
    if ($days === false) {
        throw new InvalidArgumentException('days must be between 7 and 42');
    }

    $renew = filter_var($query['renew'] ?? 'false', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($renew === null) {
        throw new InvalidArgumentException('renew must be true or false');
    }

    return ['member' => $member, 'days' => $days, 'renew' => $renew];
}

var_dump(parseLoanQuery(['member' => '42', 'days' => '14', 'renew' => '1']));

foreach ([['days' => '14'], ['member' => '7', 'days' => '90'], ['member' => '7', 'renew' => 'maybe']] as $bad) {
    try {
        parseLoanQuery($bad);
    } catch (InvalidArgumentException $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
array(3) {
  ["member"]=>
  int(42)
  ["days"]=>
  int(14)
  ["renew"]=>
  bool(true)
}
member must be a positive integer
days must be between 7 and 42
renew must be true or false
```

`filter_var()` with `FILTER_VALIDATE_INT` returns `false` for `null`, so a missing `member` fails the check.
`FILTER_VALIDATE_BOOLEAN` accepts `1/0`, `true/false`, `on/off` and `yes/no`; `FILTER_NULL_ON_FAILURE` makes it return
`null` (instead of `false`) for anything else, so that a real `false` can be told apart from garbage.

</details>

---
← Previous: [Functions and closures](06-functions-and-closures.md) | Next: [Errors and exceptions](08-errors-and-exceptions.md) →
