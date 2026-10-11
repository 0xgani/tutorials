# Chapter 20 — Testing and static analysis

## What you'll learn

- What automated tests buy you, and the three kinds Bookshelf uses: unit, integration and functional
- PHPUnit from the first test to a full configuration: assertions, exceptions, fixtures and data providers
- How to read a failing test, and how to run just the tests you care about
- Test doubles (stubs, mocks and fakes), when each one fits, and why over-mocking hurts
- Testing code that depends on the clock, the database and the whole web stack
- Code coverage: how it's measured, the drivers, and what the numbers do and don't mean
- Static analysis with PHPStan: rule levels, PHPDoc types, generics, baselines
- Consistent formatting with PHP-CS-Fixer and the PER Coding Style
- Step debugging with Xdebug, and how the tools fit into Composer scripts and CI

PHP checks types and finds most mistakes only when a line of code actually runs. A typo in a rarely used branch, a
`null` where an object was expected, or an argument in the wrong order can sit unnoticed until a user reaches it. Two
families of tools close that gap. **Tests** run the code on purpose, with inputs you choose, and check the results.
**Static analysers** read the code without running it and report what can go wrong on any path. They catch different
bugs, so serious PHP projects use both, along with a formatter that ends arguments about style.

By the end of this chapter, Bookshelf has 41 tests in three suites, passes PHPStan at level 8, and has a coding-style
check. All three run with one Composer command each.

## Installing the tools

All three tools are development dependencies: they're needed to work on the project, not to run it. Composer keeps
them apart with `require-dev`:

```bash
composer require --dev phpunit/phpunit:^13.4 phpstan/phpstan:^2.3 friendsofphp/php-cs-fixer:^3.95
```

Bookshelf's `composer.json` now contains:

```json
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.95",
        "phpstan/phpstan": "^2.3",
        "phpunit/phpunit": "^13.4"
    },
    "autoload-dev": {
        "psr-4": {
            "Bookshelf\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit",
        "analyse": "phpstan analyse",
        "cs": "php-cs-fixer check --diff",
        "cs:fix": "php-cs-fixer fix"
    }
```

`autoload-dev` maps the `Bookshelf\Tests\` namespace to `tests/`, so test helpers autoload like any other class but
never ship to production (`composer install --no-dev` skips both `require-dev` and `autoload-dev`). The scripts let
everyone run the same commands: `composer test`, `composer analyse`, `composer cs`. Composer puts `vendor/bin` on the
`PATH` while a script runs, which is why `phpunit` needs no path there. Outside scripts you type `vendor/bin/phpunit`.

PHPUnit 13 (released February 2026) requires PHP 8.4 or later. PHPUnit ships a major version every February and
supports each one for about two years, so an older project may still be on PHPUnit 11 or 12. Almost everything in
this chapter works the same there.

## Kinds of tests

A test is code that runs other code and checks the outcome. What differs between kinds of tests is how much of the
system takes part:

```text
                 ▲  slower, fewer, broader
                 │
   functional    │   the whole app: Request ─▶ Kernel ─▶ Response, with a real (in-memory) database
                 │
   integration   │   a few real parts together: LendingService + PDO repositories + SQLite
                 │
   unit          │   one class, no I/O: Isbn, Loan, Router, Authenticator (with test doubles)
                 │
                 ▼  faster, more, narrower
```

- **Unit tests** check one class or function in isolation. They run in milliseconds, point straight at the broken code
  when they fail, and make up most of a healthy suite.
- **Integration tests** check that parts work together, usually your code plus something external: SQL against a real
  database engine, files on disk, an HTTP client against a local fake server. They catch the bugs that live between
  classes (a column name typo, a transaction that doesn't roll back).
- **Functional tests** (also called end-to-end or acceptance tests, depending on whom you ask) drive the application
  from the outside: send a request, check the response. They prove the wiring is right, but when one fails you still
  have to find out which layer broke.

The shape above is the classic "test pyramid": many fast unit tests, fewer integration tests, a handful of functional
tests. It's a guideline, not a law. Code that is mostly SQL deserves mostly integration tests, because a unit test of
a repository with the database mocked out proves nothing about the SQL.

## Your first PHPUnit test

Bookshelf's `Isbn` value object (chapter 9) validates and normalizes ISBN-13 numbers. Here is its test, in
`tests/Unit/Domain/IsbnTest.php`:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Domain;

use Bookshelf\Domain\Isbn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Isbn::class)]
final class IsbnTest extends TestCase
{
    public function testFormatsWithADashAfterThePrefix(): void
    {
        self::assertSame('978-0441013593', new Isbn('9780441013593')->format());
    }

    public function testEqualityComparesTheDigits(): void
    {
        self::assertTrue(new Isbn('978-0-441-01359-3')->equals(new Isbn('9780441013593')));
        self::assertFalse(new Isbn('9780441013593')->equals(new Isbn('9780807083697')));
    }
}
```

The rules PHPUnit follows:

- A **test class** extends `PHPUnit\Framework\TestCase`, and its name ends in `Test` (that's how PHPUnit finds test
  files in a directory: `*Test.php`). Make it `final`; nothing should extend it.
- A **test method** is public, returns `void`, and its name starts with `test`. Alternatively, put the
  `#[PHPUnit\Framework\Attributes\Test]` attribute on any public method. Bookshelf uses the prefix.
- PHPUnit creates a **new instance of the test class for every test method**, so properties set in one test never
  leak into another.
- **Assertions** (`assertSame`, `assertTrue`, …) are static methods of `TestCase`. You may call them as `$this->...`
  or `self::...`; they're the same methods. Bookshelf uses `self::`, which matches what they are.
- `#[CoversClass(Isbn::class)]` declares which code this test is meant to test. It matters for code coverage (later in
  this chapter) and documents intent.

Configuration (in the next section) tells PHPUnit where the tests are. Then run it:

```bash
vendor/bin/phpunit --testsuite unit
```

```text
PHPUnit 13.4.1 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.10
Configuration: /srv/bookshelf/phpunit.xml.dist
Random Seed:   1791683652

............................                                      28 / 28 (100%)

Time: 00:00.512, Memory: 18.00 MB

OK (28 tests, 52 assertions)
```

Each dot is a passing test. Other letters you'll meet: `F` (failure: an assertion didn't hold), `E` (error: an
exception or PHP error escaped the test), `R` (risky: for example, the test made no assertions), `N` (a PHPUnit notice),
`S` (skipped), `I` (incomplete), `D` and `W` (the test triggered a deprecation or a warning).

### Naming and structure

A good test name states a behavior, as a sentence: `testFormatsWithADashAfterThePrefix`, not `testFormat` or
`testIsbn2`. When it fails, the name alone should tell you which promise broke. PHPUnit's `--testdox` output turns the
names back into sentences, which is a quick way to read a test suite as a specification:

```bash
vendor/bin/phpunit --testdox --order-by=default --filter IsbnTest
```

```text
Isbn (Bookshelf\Tests\Unit\Domain\Isbn)
 ✔ Accepts valid isbns with or without separators with data set "digits only"
 ✔ Accepts valid isbns with or without separators with data set "with dashes"
 ✔ Accepts valid isbns with or without separators with data set "with spaces"
 ✔ Rejects invalid input with data set "wrong check digit"
 ✔ Rejects invalid input with data set "too short"
 ✔ Rejects invalid input with data set "too long"
 ✔ Rejects invalid input with data set "a letter"
 ✔ Rejects invalid input with data set "empty"
 ✔ Formats with a dash after the prefix
 ✔ Equality compares the digits
```

(`--order-by=default` overrides the configured random order, so the data sets print in source order.)

Inside a test, the common structure is **arrange, act, assert**: set up the inputs, do the one thing under test, check
the outcome. Blank lines between the three parts make them visible:

```php
    public function testRenewingExtendsTheDueDateByTheLoanLength(): void
    {
        $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');   // arrange

        $loan->renew(self::day('2026-10-30'));                              // act

        self::assertSame('2026-11-22', $loan->dueOn->format('Y-m-d'));      // assert
        self::assertSame(1, $loan->renewals);
    }
```

Several assertions in one test are fine when they check one behavior from several sides. Two unrelated behaviors
belong in two tests, so that one failure doesn't hide the other.

## Assertions

`TestCase` has dozens of assertions. A small set covers most needs:

| Assertion | Passes when |
|---|---|
| `assertSame($expected, $actual)` | `$expected === $actual`: same type and value; for objects, the *same instance* |
| `assertEquals($expected, $actual)` | Values are equal after loose comparison; objects of the same class with equal properties |
| `assertTrue($x)` / `assertFalse($x)` | `$x` is exactly `true` / `false` (not truthy or falsy) |
| `assertNull($x)` / `assertNotNull($x)` | `$x` is / isn't `null` |
| `assertCount(3, $list)` | `count($list) === 3` |
| `assertContains($needle, $array)` | The array contains the value (strict comparison) |
| `assertArrayHasKey('id', $array)` | The key exists |
| `assertStringContainsString($part, $s)` | The substring occurs in the string |
| `assertMatchesRegularExpression($re, $s)` | `preg_match($re, $s)` matches |
| `assertInstanceOf(Book::class, $x)` | `$x instanceof Book` |
| `assertJsonStringEqualsJsonString($a, $b)` | Both strings decode to the same JSON value (key order and whitespace don't matter) |
| `assertEqualsWithDelta(0.3, $x, 0.0001)` | Floats are within a tolerance |
| `assertArraysAreIdenticalIgnoringOrder($a, $b)` | Same keys and values, strictly, in any order (PHPUnit 13+) |

Two choices come up all the time:

- **`assertSame` or `assertEquals`?** Prefer `assertSame` for scalars and arrays: `assertEquals('1', 1)`,
  `assertEquals(null, false)` and `assertEquals([1, 2], ['1', '2'])` all pass, which hides real bugs. For objects, `assertSame` checks
  identity (is it the very same object?), which is what you want when a method should return an object it was given.
  To compare two *different* objects by value, use `assertEquals`, or better, the object's own `equals()` method when it
  has one, since that is the definition of equality the code itself uses.
- **Expected value first.** Every two-argument assertion takes the expected value first and the actual value second.
  Swapping them still passes and fails at the right times, but the failure message then calls your expectation
  "actual", which is confusing at the worst moment.

### Testing exceptions

When the behavior under test is "this throws", tell PHPUnit before the code runs:

```php
    #[DataProvider('invalidIsbns')]
    public function testRejectsInvalidInput(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ISBN-13');     // a substring of the message

        new Isbn($input);
    }
```

`expectException()` makes the test pass only if an exception of that class (or a subclass) escapes the test method.
`expectExceptionMessage()` checks that the message *contains* the string, and `expectExceptionCode()` checks the code.
`expectExceptionObject($e)` checks class, message and code against an example exception, which is handy when the class
has named constructors:

```php
        $this->expectExceptionObject(LoanRuleViolation::renewalLimitReached($loan));
        $loan->renew(self::day('2026-10-12'));
```

Two pitfalls:

- **Nothing after the throwing line runs.** The exception ends the test method, so an assertion written below it is
  dead code. Put the throwing call last.
- **The expected exception may come from the wrong line.** If the arrange step of the test throws the same exception
  class, the test passes for the wrong reason. Keep the code before the throwing call minimal, and check the message
  too.

When you need to inspect the exception (a property, or several exceptions in a loop), catch it yourself. The
Router's test needs the HTTP status and headers of an `HttpException`:

```php
    public function testAKnownPathWithTheWrongMethodIsMethodNotAllowed(): void
    {
        $exception = $this->catchHttpException(fn () => $this->router->match('GET', '/books/42/loans'));

        self::assertSame(405, $exception->status);
        self::assertSame(['Allow' => 'POST'], $exception->headers);
    }

    private function catchHttpException(callable $code): HttpException
    {
        try {
            $code();
        } catch (HttpException $e) {
            return $e;
        }
        self::fail('Expected an HttpException');
    }
```

`self::fail()` ends the test as a failure. Its return type is `never`, which is why the helper needs no `return`
after it: PHP (and PHPStan) know the function can't fall off the end.

## Reading a failure

Suppose the fine for a loan returned ten days late were expected to be 240 cents. The test fails like this:

```text
.....F...                                                           9 / 9 (100%)

Time: 00:00.034, Memory: 18.00 MB

There was 1 failure:

1) Bookshelf\Tests\Unit\Domain\LoanTest::testFineGrowsPerDayLateUpToTheCap@ten days late with data ('2026-11-11', 240)
Failed asserting that two objects are equal.
--- Expected
+++ Actual
@@ @@
 Bookshelf\Domain\Money Object (
-    'cents' => 240
+    'cents' => 250
 )

/srv/bookshelf/tests/Unit/Domain/LoanTest.php:37

FAILURES!
Tests: 9, Assertions: 14, Failures: 1.
```

Read it top to bottom:

1. **Which test:** class, method, and (because it uses a data provider) the data set's name and values.
2. **What was asserted:** "two objects are equal".
3. **The difference**, as a diff: lines with `-` are the expected value, lines with `+` the actual one.
4. **Where:** the file and line of the failing assertion.

Then decide which side is wrong. Here the code is right (10 days × 25 cents = 250); the test's expectation was the
bug. A failing test means "the code and the test disagree", not automatically "the code is broken".

## Configuration: phpunit.xml.dist

PHPUnit reads `phpunit.xml` if it exists, otherwise `phpunit.xml.dist`. Commit the `.dist` file; a developer who needs
local changes copies it to `phpunit.xml`, which is git-ignored. Bookshelf's:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         executionOrder="random"
         beStrictAboutOutputDuringTests="true"
         failOnRisky="true"
         failOnWarning="true"
         failOnNotice="true"
         failOnDeprecation="true"
         displayDetailsOnTestsThatTriggerDeprecations="true"
         displayDetailsOnTestsThatTriggerNotices="true"
         displayDetailsOnTestsThatTriggerWarnings="true"
         displayDetailsOnPhpunitDeprecations="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>tests/Functional</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>

    <php>
        <ini name="error_reporting" value="-1"/>
        <env name="BOOKSHELF_DATABASE" value=":memory:" force="true"/>
        <env name="BOOKSHELF_DEBUG" value="1" force="true"/>
    </php>
</phpunit>
```

What each part does:

- `xsi:noNamespaceSchemaLocation` points at PHPUnit's XML schema, so editors can validate and autocomplete the file.
  After a PHPUnit upgrade, `vendor/bin/phpunit --migrate-configuration` rewrites an outdated file.
- `bootstrap` runs before any test: Composer's autoloader is all Bookshelf needs.
- `cacheDirectory` stores results between runs, which `--order-by=defects` uses to run last time's failures first.
  Git-ignore it.
- `executionOrder="random"` shuffles the tests on every run. A test that passes only after another test has run (shared
  static state, a leftover file, a database row) fails sooner or later, and you find the hidden dependency. The header
  prints the seed (`Random Seed: 1791683652`); rerun with `--random-order-seed 1791683652` to reproduce an order.
- `beStrictAboutOutputDuringTests` marks a test risky if it prints anything, which catches forgotten `var_dump()`s and
  code that echoes instead of returning.
- `failOn…` turns risky tests, warnings, notices and deprecations into a failing exit code. By default PHPUnit reports
  them but still exits with 0, so CI stays green while PHP warns you that your code will break in the next version.
- `displayDetailsOn…` prints the actual message and location for those issues instead of only counting them.
- `<testsuites>` names groups of test directories, which `--testsuite unit` selects.
- `<source>` lists your application code. It's what code coverage measures, and PHPUnit also uses it to tell
  deprecations triggered by *your* code from those triggered inside `vendor/`.
- `<php>` sets INI values and environment variables for the test run. `error_reporting=-1` reports everything,
  whatever `php.ini` says. `force="true"` overrides a value already set in the real environment, so tests never pick
  up a developer's real database by accident.

### Running a subset

| Command | Runs |
|---|---|
| `vendor/bin/phpunit` | Everything |
| `vendor/bin/phpunit tests/Unit/Domain/LoanTest.php` | One file |
| `vendor/bin/phpunit --testsuite integration` | One suite (comma-separate several) |
| `vendor/bin/phpunit --filter Fine` | Tests whose `Class::method` name matches the pattern |
| `vendor/bin/phpunit --filter 'LoanTest::testFine.*@ten days late'` | One data set of one test |
| `vendor/bin/phpunit --group slow` / `--exclude-group slow` | Tests tagged `#[Group('slow')]` |
| `vendor/bin/phpunit --stop-on-failure` | Everything, until the first failure |
| `vendor/bin/phpunit --order-by=defects` | Last run's failures first |
| `vendor/bin/phpunit --list-tests` | Nothing; lists the tests it would run |

## Data providers

Many tests are one check over many inputs. Instead of copying the method, feed it from a **data provider**: a public
static method that returns (or yields) one array of arguments per case:

```php
use PHPUnit\Framework\Attributes\DataProvider;

    #[DataProvider('validIsbns')]
    public function testAcceptsValidIsbnsWithOrWithoutSeparators(string $input): void
    {
        $isbn = new Isbn($input);

        self::assertSame('9780441013593', $isbn->value);
    }

    /** @return iterable<string, array{string}> */
    public static function validIsbns(): iterable
    {
        yield 'digits only' => ['9780441013593'];
        yield 'with dashes' => ['978-0-441-01359-3'];
        yield 'with spaces' => ['978 0 441 01359 3'];
    }
```

PHPUnit runs the test once per data set, passing each array's elements as arguments. Three habits pay off:

- **Name every data set** with a string key. The failure message then says `with data set "with dashes"` instead of
  `#1`, and `--filter '@with dashes'` runs just that case.
- **Keep expected values in the data**, not computed inside the test. The fines test lists dates and the fine expected
  on each:

  ```php
      #[DataProvider('fines')]
      public function testFineGrowsPerDayLateUpToTheCap(string $today, int $expectedCents): void
      {
          $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');   // due 2026-11-01, 25 cents a day

          self::assertEquals(Money::cents($expectedCents), $loan->fine(self::day($today)));
      }

      /** @return iterable<string, array{string, int}> */
      public static function fines(): iterable
      {
          yield 'on the due date' => ['2026-11-01', 0];
          yield 'one day late' => ['2026-11-02', 25];
          yield 'ten days late' => ['2026-11-11', 250];
          yield 'capped at $10' => ['2027-03-01', Loan::FINE_CAP_CENTS];
      }
  ```

  If the test recomputed `days × 25` itself, it would repeat the code's logic and its bugs.
- **Choose boundaries, not random samples.** "On the due date" and "one day late" sit on either side of the rule's edge;
  "capped" checks the other limit. Most bugs live at edges: off-by-one, empty input, the maximum.

The provider runs before any test (PHPUnit needs the data to count the tests), in a static context. It can't use
`setUp()` or `$this`, and it shouldn't do expensive work like opening a database connection. Build objects inside the
test from the plain values the provider passes.

## Fixtures: setUp and friends

The **fixture** is the state a test starts from. When several tests in a class share one, build it in `setUp()`,
which PHPUnit calls before each test method (on that test's fresh instance):

```php
final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->add('GET', '/books/{id:\d+}', [CatalogController::class, 'show']);
        $this->router->add('POST', '/books/{id:\d+}/loans', [LoanController::class, 'lend'], Access::Member);
    }

    public function testMatchesAPathAndExtractsItsParameters(): void
    {
        $match = $this->router->match('GET', '/books/42');

        self::assertSame([CatalogController::class, 'show'], $match->handler);
        self::assertSame(['id' => '42'], $match->parameters);
        self::assertSame(Access::Public, $match->access);
    }
```

The hooks, in the order they run:

```text
setUpBeforeClass()          static, once per class      (expensive shared resources; use sparingly)
  setUp()                   before each test
    test method
  tearDown()                after each test, even if it failed
  setUp() / test / tearDown() ...
tearDownAfterClass()        static, once per class
```

`tearDown()` is rarely needed in PHP. Each test object is garbage collected, so plain objects clean up after
themselves. You need it for things outside PHP's memory: a temporary file, an environment variable you changed, a
global setting. `setUpBeforeClass()` shares state between tests, which is exactly what random ordering is designed to
punish, so reserve it for read-only, expensive resources.

### Builders: say only what matters

Tests get hard to read when each one constructs a full `Book` or `Member` with seven arguments, six of them
irrelevant. Bookshelf's tests share a trait of **builder methods** with sensible defaults, so each test states only what
it is about:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Support;

use Bookshelf\Domain\Book;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberType;
use DateTimeImmutable;

/**
 * Builders for test objects with sensible defaults, so each test states only what matters to it.
 */
trait Builders
{
    private static function book(
        ?int $id = 1,
        string $title = 'Dune',
        string $isbn = '9780441013593',
        int $copies = 2,
        ?int $available = null,
    ): Book {
        return new Book($id, $title, 'Frank Herbert', new Isbn($isbn), Genre::ScienceFiction, 1965, $copies, $available);
    }

    private static function member(MemberType $type = MemberType::Standard, ?int $id = 1, string $email = 'ada@example.org'): Member
    {
        return new Member($id, 'Ada Lovelace', $email, $type);
    }

    private function loan(MemberType $type, string $lentOn): Loan
    {
        return Loan::start(self::book(), self::member($type), new DateTimeImmutable($lentOn));
    }
}
```

With named arguments, `self::book(copies: 1, available: 0)` reads as "a book with no copies left", and nothing else
about the book distracts the reader. When the `Book` constructor gains a parameter, only the builder changes, not
fifty tests.

## Test doubles

`Authenticator` (chapter 18) checks a password against the member stored in a `MemberRepository`. A unit test of the
authenticator shouldn't need a database full of members. It needs *something* that answers `findByEmail()` the way the
test wants. A stand-in for a real dependency is called a **test double**, and the kinds have names:

| Kind | What it does | Typical use |
|---|---|---|
| **Dummy** | Fills a parameter; is never used | A constructor needs it, the test path doesn't |
| **Stub** | Returns canned answers | "Pretend the repository finds this member" |
| **Mock** | A stub that also *verifies* how it was called | "The repository's `save()` must be called exactly once" |
| **Spy** | Records calls, which the test checks afterwards | Same goal as a mock, assertions written after the act step |
| **Fake** | A real, simplified implementation | An in-memory repository; `FrozenClock` |

PHPUnit generates stubs and mocks from an interface or class at run time.

### Stubs

`createStub()` returns an object that implements the type, with every method returning a default value for its return
type (`null` for a nullable type, `''`, `0`, `[]`, an empty stub for an object type). You then configure the methods
the test cares about:

```php
    public function testReturnsTheMemberForTheRightPassword(): void
    {
        $ada = $this->memberWithPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT));
        $members = $this->createStub(MemberRepository::class);
        $members->method('findByEmail')->willReturn($ada);

        $authenticator = new Authenticator($members, $this->throttle);

        self::assertSame($ada, $authenticator->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7'));
    }
```

The ways to configure an answer:

```php
$stub->method('findByEmail')->willReturn($ada);                    // always this value
$stub->method('findByEmail')->willReturn($ada, null);              // $ada on the first call, then null
$stub->method('get')->willReturnMap([[1, $ada], [2, $bob]]);       // by arguments: [args..., return value]
$stub->method('get')->willThrowException(EntityNotFound::member(9));
$stub->method('findByEmail')->willReturnCallback(                  // computed from the arguments
    fn (string $email) => $email === 'ada@example.org' ? $ada : null,
);
```

A stub answers questions; the test then asserts on the **result** of the code under test. That's the most robust kind
of test, because it doesn't care *how* the authenticator used the repository, only what came out.

### Mocks

Sometimes the effect you need to check *is* a call. When a member logs in with a password hashed by an outdated
algorithm or cost, the authenticator rehashes it and saves the member (chapter 18). The visible outcome is a call to
`save()`. A mock states that expectation up front, and PHPUnit verifies it when the test ends:

```php
    public function testUpgradesAnOutdatedHashAtLogin(): void
    {
        $ada = $this->memberWithPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]));
        $members = $this->createMock(MemberRepository::class);
        $members->method('findByEmail')->willReturn($ada);
        $members->expects($this->once())->method('save')->with($ada);

        new Authenticator($members, $this->throttle)->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7');

        self::assertFalse(password_needs_rehash((string) $ada->passwordHash, PASSWORD_DEFAULT));
        self::assertTrue(password_verify(self::PASSWORD, (string) $ada->passwordHash));
    }

    public function testDoesNotSaveWhenTheHashIsCurrent(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->method('findByEmail')->willReturn($this->memberWithPassword(password_hash(self::PASSWORD, PASSWORD_DEFAULT)));
        $members->expects($this->never())->method('save');

        new Authenticator($members, $this->throttle)->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7');
    }
```

- `expects($this->once())`, `$this->never()`, `$this->exactly(2)`, `$this->atLeastOnce()` set how many calls must
  happen.
- `->with($ada)` checks the arguments. A plain value is compared with `==`; wrap it in `$this->identicalTo($ada)` for
  `===`, or use constraints like `$this->stringContains('@')` or `$this->callback(fn ($m) => $m->id === 1)`.
- An unmet expectation fails the test with a clear message:

```text
1) Bookshelf\Tests\Scratch\FinalTest::testUnmetExpectation
Expectation failed for method name is "save" when invoked 1 time.
Method was expected to be called 1 time, actually called 0 times.
```

Since PHPUnit 12.5, a `createMock()` double with no `expects()` at all triggers a PHPUnit notice ("No expectations were
configured for the mock object … Consider refactoring your test code to use a test stub instead"). The distinction
matters: a mock is for verifying calls, and if you don't verify any, you wanted a stub. With `failOnNotice`, Bookshelf
treats that notice as a failure.

### What can't be doubled, and what shouldn't be

PHPUnit builds a double by generating a subclass or an implementation on the fly. That has a consequence:

```text
PHPUnit\Framework\MockObject\Generator\ClassIsFinalException: Class "Bookshelf\Security\LoginThrottle" is declared "final" and cannot be doubled
```

Final classes can't be subclassed, so they can't be doubled. That's a feature, not an obstacle. It nudges you toward
two good habits:

1. **Put an interface at boundaries you want to replace.** `MemberRepository`, `BookRepository` and `Clock` are
   interfaces because tests (and alternative storage) need to swap them. Value objects (`Isbn`, `Money`) and services
   that are cheap to build for real don't need one.
2. **Use the real thing when it's cheap.** `LoginThrottle` needs a `PDO` and a `Clock`, both trivial to provide.
   `AuthenticatorTest` builds a real one on an in-memory SQLite table:

```php
    protected function setUp(): void
    {
        // LoginThrottle is a final class, so it can't be replaced by a mock; a real one on an in-memory table is
        // simple and fast.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, subject TEXT, attempted_at INTEGER)');
        $this->throttle = new LoginThrottle($pdo, FrozenClock::at('2026-10-11 10:00'));
    }
```

Mocks are easy to overuse. Warning signs:

- **The test mirrors the implementation.** If every internal call is an expectation, any refactoring breaks the test
  even when behavior is unchanged. Mock the calls that *are* the behavior (a `save`, an email sent, a payment charged)
  and stub the rest.
- **Mocking what you don't own.** Doubling `PDO`, `PDOStatement` or a third-party HTTP client encodes your *guess* of
  how that library behaves. Wrap the library behind your own small interface (a repository, a `Mailer`), test the
  wrapper against the real thing in an integration test, and double your interface elsewhere.
- **Mocks returning mocks returning mocks.** The code under test reaches too far through its dependencies. That's a
  design problem the test is pointing at.

### Fakes

A **fake** is a working implementation that takes a shortcut: memory instead of a database, a fixed time instead of the
real clock. Fakes are classes you write and keep in `tests/Support/`, and they're often the most readable double,
because the test sets up state ("this member exists") instead of programming answers to calls. Bookshelf's most-used
fake is the clock.

## Testing time

Code that calls `new DateTimeImmutable()` or `time()` directly can only be tested "now", which changes on every run.
A due date computed today passes; the same test run next month fails. Bookshelf solved this in chapter 15: services
ask a `Clock` for the time:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use DateTimeImmutable;

/**
 * Tells the current time. Code that needs "now" asks a Clock instead of calling `new DateTimeImmutable()`,
 * so tests can control time. This is the same shape as PSR-20's `Psr\Clock\ClockInterface`.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
```

Production wires `SystemClock`; tests use `FrozenClock`, which stands still until the test moves it:

```php
final class FrozenClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {
    }

    public static function at(string $time, string $timezone = 'UTC'): self
    {
        return new self(new DateTimeImmutable($time, new DateTimeZone($timezone)));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /** Moves the clock, e.g. advance('+3 days') or advance('+90 minutes'). */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
```

Now "a book returned three days late" is a test you can write without waiting three weeks:

```php
    public function testReturningLateChargesAFine(): void
    {
        $bookId = $this->saveBook();
        $memberId = $this->saveMember(MemberType::Standard);       // 21 days, 25 cents a day
        $loanId = self::id($this->lending->lend($bookId, $memberId)->id);

        $this->clock->advance('+24 days');                         // three days late
        $fine = $this->lending->returnLoan($loanId);

        self::assertSame(75, $fine->cents);
        self::assertSame(2, $this->books->get($bookId)->available);
        self::assertTrue($this->loans->get($loanId)->isReturned);
    }
```

The same idea applies to every source of non-determinism: random numbers (pass a `Random\Randomizer` with a seeded
engine, chapter 18), generated ids, and anything read from the environment. Inject it, and tests can control it.

## Testing with a database

Repository code is mostly SQL, so its tests must run that SQL against a real database engine. Bookshelf's
integration tests start every test with a **fresh in-memory SQLite database** with all migrations applied. A base
class does it once for all of them:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Support;

use Bookshelf\Persistence\Database;
use Bookshelf\Persistence\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * A fresh in-memory SQLite database with all migrations applied, for every test.
 * In memory, so there is nothing to clean up and tests can't interfere with each other.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        $this->database = Database::sqlite(':memory:');
        new Migrator($this->database, dirname(__DIR__, 2) . '/migrations')->migrate();
    }
}
```

Running the real migrations means the tests also check the schema: a broken migration breaks every integration test
at once. A repository test then exercises the SQL that's easy to get wrong, such as the `LIKE` escaping from chapter 17:

```php
    public function testSearchTreatsLikeWildcardsInTheQueryLiterally(): void
    {
        $titles = array_map(fn (Book $book): string => $book->title, $this->books->search('100%'));

        self::assertSame(['100% Pure'], $titles);           // not "1000 Places"
    }
```

And the service tests check rules that span several tables, including that a failed operation leaves nothing behind:

```php
    public function testAFailedLoanChangesNothing(): void
    {
        $bookId = $this->saveBook(copies: 1, available: 0);
        $memberId = $this->saveMember();

        try {
            $this->lending->lend($bookId, $memberId);
            self::fail('Expected BookNotAvailable');
        } catch (BookNotAvailable) {
        }

        self::assertSame(0, $this->books->get($bookId)->available);
        self::assertSame([], $this->loans->openLoansOf($memberId));
        self::assertFalse($this->database->pdo->inTransaction());
    }
```

The strategies for database tests, and their trade-offs:

| Strategy | Speed | Fidelity | Notes |
|---|---|---|---|
| In-memory SQLite per test | Fastest | Only as good as SQLite's match with production | Perfect when production *is* SQLite, like Bookshelf |
| Real server, transaction per test, rolled back in `tearDown()` | Fast | Exact | Doesn't work if the code under test commits or uses several connections |
| Real server, truncate or recreate tables per test | Slower | Exact | Simple and always correct |
| Real server in a container, one per CI job | Setup cost once | Exact | The norm in CI; see chapter 22 |

If production runs PostgreSQL or MySQL, test the repositories against that engine. SQLite accepts SQL that PostgreSQL
rejects (and the other way around), handles types loosely, and lacks features you may rely on. Unit and functional
tests can still use SQLite for speed, as long as some tests cover the real dialect.

## Functional tests: the whole application

Chapter 19 made the application a function from `Request` to `Response`. That pays off now: a functional test builds
the real container, sends requests to the real kernel, and checks real responses. No web server, no browser:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Functional;

use Bookshelf\Container\Container;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Persistence\Migrator;
use Bookshelf\Tests\Support\Builders;
use Bookshelf\Web\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The whole web application, from Request to Response, with an in-memory database and no web server.
 */
#[CoversNothing]
final class WebTest extends TestCase
{
    use Builders;

    private Container $container;

    protected function setUp(): void
    {
        // phpunit.xml.dist sets BOOKSHELF_DATABASE to :memory:, so every test gets its own empty database.
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
        $this->container->get(Migrator::class)->migrate();
        $this->container->get(BookRepository::class)->save(self::book(id: null));
    }

    public function testTheCatalogListsTheBooks(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<a href="/books/1">Dune</a>', $response->body);
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function testTheApiReturnsABookAsJson(): void
    {
        $response = $this->get('/api/books/1');

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('Dune', json_decode($response->body, true)['data']['title']);
    }

    public function testAnUnknownBookIsNotFound(): void
    {
        self::assertSame(404, $this->get('/books/99')->status);
        self::assertJsonStringEqualsJsonString('{"error": "There is no book with id 99."}', $this->get('/api/books/99')->body);
    }

    public function testMemberPagesRedirectToTheLogin(): void
    {
        $response = $this->get('/loans');

        self::assertSame(303, $response->status);
        self::assertSame('/login?next=%2Floans', $response->header('Location'));
    }

    public function testAPostWithoutACsrfTokenIsRejected(): void
    {
        $response = $this->handle(new Request('POST', '/suggest', body: ['title' => 'Emma', 'author' => 'Jane Austen']));

        self::assertSame(403, $response->status);
    }

    private function get(string $path): Response
    {
        return $this->handle(new Request('GET', $path));
    }

    private function handle(Request $request): Response
    {
        return $this->container->get(Kernel::class)->handle($request);
    }
}
```

Points worth noticing:

- **A new container per test.** The container shares one instance of each service, including the database
  connection. Building it in `setUp()` gives every test its own in-memory database.
- **`#[CoversNothing]`.** A functional test runs most of the code base. Counting all of that as "covered" would make the
  coverage report claim more than the unit tests prove, so these tests opt out of coverage.
- **What to check.** Status codes, important headers, and a few landmarks in the body. Asserting the entire HTML makes
  tests break on every template tweak.
- **The limit.** Logged-in pages need a session, and `Session` wraps PHP's native session functions, which a test
  process can't drive cleanly. The usual fix is an interface for the session with an in-memory implementation in
  tests (a fake again), or a browser-level tool for a few critical flows.

The whole suite with `--testdox` reads like a description of the application:

```text
Authenticator (Bookshelf\Tests\Unit\Security\Authenticator)
 ✔ Returns the member for the right password
 ✔ Rejects a wrong password and an unknown address with the same message
 ✔ Upgrades an outdated hash at login
 ✔ Does not save when the hash is current
 ✔ Blocks after too many failures

Lending Service (Bookshelf\Tests\Integration\LendingService)
 ✔ Lending takes a copy and records the loan
 ✔ A member cannot borrow the same book twice
 ✔ A member cannot exceed their loan limit
 ✔ A failed loan changes nothing
 ✔ Returning late charges a fine

Web (Bookshelf\Tests\Functional\Web)
 ✔ The catalog lists the books
 ✔ The api returns a book as json
 ✔ An unknown book is not found
 ✔ Member pages redirect to the login
 ✔ A post without a csrf token is rejected

OK (41 tests, 87 assertions)
```

(The Isbn, Loan, PdoBookRepository and Router sections are left out here.)

## Code coverage

Code coverage records which lines (or branches) of `src/` ran during the tests. PHP itself can't measure that; a
**coverage driver** extension does:

| Driver | What it measures | Notes |
|---|---|---|
| **PCOV** | Lines | Small and fast; made for coverage only |
| **Xdebug** | Lines, branches and paths | Slower; also the debugger and profiler. Needs `XDEBUG_MODE=coverage` |

Without a driver, PHPUnit says so and runs nothing:

```bash
vendor/bin/phpunit --coverage-text
```

```text
There was 1 PHPUnit test runner warning:

1) No code coverage driver available that supports line coverage

No tests executed!
```

With a driver installed:

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-html var/coverage     # PCOV
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text                     # Xdebug
XDEBUG_MODE=coverage vendor/bin/phpunit --path-coverage --coverage-html var/coverage
```

The HTML report colors every line of every class in `src/`: green for executed, red for never executed, and shows
which tests ran each line. `--coverage-text` prints a per-class summary, and `--coverage-clover` writes XML for CI
services.

`#[CoversClass]` makes coverage honest. With it, a line counts as covered only by tests that declare they are testing
that class. Without it, `LendingServiceTest` would mark half of the persistence layer "covered" simply because it
called it. Set `requireCoverageMetadata="true"` in the configuration to make every test declare `#[CoversClass]`,
`#[CoversFunction]`, `#[CoversMethod]` or `#[CoversNothing]`.

What coverage tells you, and what it doesn't:

- **Red lines are facts.** No test ran that code, so no test can catch a bug in it. The report is a map of untested
  error paths and forgotten branches.
- **Green lines are not proof.** A line that ran without any assertion checking its effect is "covered" too. A suite can
  reach 100% and still miss bugs. Line coverage also says nothing about `$a && $b` where only one combination was ever
  tried; branch and path coverage (Xdebug only) get closer.
- **Targets get gamed.** "Coverage must be 90%" produces tests that execute code without checking it. Use coverage to
  find gaps, and judge tests by whether they would fail when the code is wrong.

**Mutation testing** measures that last point directly. A tool such as Infection makes small changes to your code (a
`<` becomes `<=`, a `return true` becomes `return false`) and runs the tests against each "mutant". If the tests still
pass, nothing checked that line's behavior. It's slow, so teams run it on changed files or nightly rather than on
every commit.

## Static analysis with PHPStan

Tests check the paths you thought of. A static analyser checks every path, by reading the code and inferring the type
of every expression. Here's a short file with five bugs, `overdue.php`:

```php
<?php

declare(strict_types=1);

final class Loan
{
    public function __construct(public int $id, public ?string $returnedOn, public string $dueOn)
    {
    }
}

/** @param list<Loan> $loans */
function dueDateOf(array $loans, int $id): string
{
    foreach ($loans as $loan) {
        if ($loan->id === $id) {
            return $loan->dueOn;
        }
    }
}

function daysLate(string $dueOn, string $today): int
{
    $due = DateTimeImmutable::createFromFormat('Y-m-d', $dueOn);

    return $due->diff(new DateTimeImmutable($today))->days;
}

function returnedLabel(Loan $loan): string
{
    return 'Returned on ' . strtoupper($loan->returnedOn);
}

echo daysLate(dueDateOf([], '7'), '2026-11-25');
echo returnedLabel(new Loan(1, null, '2026-11-01'), $verbose);
```

PHP itself would only report the first problem it runs into. PHPStan reports them all without running anything:

```bash
vendor/bin/phpstan analyse --level=5 overdue.php
```

```text
 ------ ------------------------------------------------------------------- 
  Line   overdue.php                                                        
 ------ ------------------------------------------------------------------- 
  15     Function dueDateOf() should return string but return statement is  
         missing.                                                           
         🪪  return.missing (non-ignorable)                                 
  34     Parameter #2 $id of function dueDateOf expects int, string given.  
         🪪  argument.type                                                  
  35     Function returnedLabel invoked with 2 parameters, 1 required.      
         🪪  arguments.count                                                
  35     Variable $verbose might not be defined.                            
         🪪  variable.undefined                                             
 ------ ------------------------------------------------------------------- 


 [ERROR] Found 4 errors
```

At level 8, two more bugs appear, the ones that only bite on unusual input:

```text
  26     Cannot call method diff() on DateTimeImmutable|false.              
         🪪  method.nonObject                                               
  31     Parameter #1 $string of function strtoupper expects string,        
         string|null given.                                                 
         🪪  argument.type                                                  
```

`createFromFormat()` returns `false` for a malformed date, and `returnedOn` is `null` for a loan that hasn't come back.
Both lines work in every test with happy-path data, and both crash in production on the first bad row. The 🪪 line is
the error's **identifier**; `https://phpstan.org/error-identifiers/<identifier>` explains each one with examples.

### Rule levels

PHPStan has levels 0 to 10 (`max` is an alias for the highest). Each level includes all the checks below it:

| Level | Adds |
|---|---|
| 0 | Unknown classes, functions and methods; wrong argument counts; always-undefined variables |
| 1 | Possibly undefined variables; unknown magic methods and properties |
| 2 | Unknown methods on any expression, not just `$this`; PHPDoc validation |
| 3 | Return types; types assigned to properties |
| 4 | Dead code: always-true/false conditions, unreachable branches |
| 5 | Argument types |
| 6 | Missing type declarations (parameters, returns, properties, array value types) |
| 7 | Partially wrong union types (only some members of `A\|B` support the call) |
| 8 | Calling methods or accessing properties on nullable types |
| 9 | Strict about explicit `mixed`: you can't use it without narrowing it first |
| 10 | Strict about implicit `mixed` too (values with no declared type at all) |

For a new project, start at the level you can hold and raise it over time. Level 6 is a common minimum for
application code, level 8 catches most of the `null` crashes that matter, and 9 or 10 suit libraries and code whose
inputs are fully typed. Bookshelf runs at 8. At level 10 it reports 57 more errors, almost all about values typed
`mixed`, such as the container's `get()` called through `array_map()`, or rows fetched from PDO. Fixing those
means narrowing each value explicitly, which is worth it for a library and debatable for an app.

### Configuration

`phpstan.neon` in the project root (NEON is a YAML-like format; `phpstan.neon.dist` works too, with the same
local-override convention as PHPUnit):

```neon
parameters:
    level: 8
    phpVersion: 80500
    paths:
        - bin/bookshelf
        - config
        - public
        - src
        - tests
```

`phpVersion` tells PHPStan which PHP version's functions and rules apply, independent of the PHP version that runs
PHPStan itself. Analyse the tests too: a wrong assertion argument or a typo in a test helper is a bug as well.

### What PHPStan found in Bookshelf

The first run on Bookshelf at level 8 reported 41 errors. None crashed in normal use, which is exactly the point:
they were the unusual paths. A few, with their fixes:

**Functions that return `false` or `null` on failure.** `preg_replace()` returns `null` if the regex engine fails, the
intl formatters return `false`, and `createFromFormat()` returns `false` for malformed input. The fix decides what
failure means instead of letting a `false` flow onward:

```php
        $digits = preg_replace('/[\s-]+/', '', $input) ?? '';   // null only if the regex engine fails
```

```php
    private function date(string $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone)
            ?: throw new UnexpectedValueException("Invalid date '$value' in the loans table");
    }
```

```php
    /** The intl formatters return false instead of throwing when they fail. */
    private static function checked(string|false $result): string
    {
        return $result !== false ? $result : throw new RuntimeException('Formatting failed: ' . intl_get_error_message());
    }
```

**Nullable ids.** A `Member` has `?int $id` because a new member has no id until it's saved. A logged-in member always
has one, but the type doesn't say so. Rather than casting the `null` away, the code states the assumption and fails
loudly if it's ever wrong:

```php
        $loans = $this->loans->openLoansOf($member->id ?? throw new LogicException('A logged-in member has an id'));
```

**Lists that weren't lists.** Repositories declare `@return list<Book>`, but `array_map()` over `fetchAll()` returns
`array<int, Book>` as far as types go. PHP's `array_map()` keeps the keys of its input, and nothing in the signature
promises those keys are 0, 1, 2…. Wrapping the result in `array_values()` makes the promise true by construction:

```php
        return array_values(array_map($this->toLoan(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
```

The fixes share a theme: **make the code say what it means, rather than silencing the analyser.** A cast like
`(int) $member->id` or `(string) $result` would also have made the errors disappear, by turning `null` into `0` and
`false` into `''` and carrying on with garbage.

### PHPDoc types: telling PHPStan more than PHP can

PHP's native types can say `array` but not "a list of `Book`s" or "an array with keys `title` and `year`". PHPStan
reads richer types from PHPDoc comments:

| PHPDoc type | Means |
|---|---|
| `list<Book>` | Array with keys 0, 1, 2… in order, values `Book` |
| `array<string, int>` | Any array with string keys and `int` values |
| `non-empty-list<string>` | A list with at least one element |
| `array{title: string, year: int, tags?: list<string>}` | An array shape: these keys, these types; `tags` optional |
| `positive-int`, `non-empty-string`, `numeric-string` | Refinements of scalar types |
| `class-string<Command>` | A string holding the name of `Command` or a subclass |
| `'sf'\|'fantasy'` | A union of literal values |
| `callable(Book): bool` | A callable taking a `Book` and returning `bool` |

You've seen these throughout Bookshelf (`@return list<Loan>`, `@param array<string, mixed> $row`). They cost nothing at
run time; PHP ignores comments. They're checked by PHPStan, so a wrong PHPDoc type is reported instead of trusted.

**Generics** describe functions and classes that work with any type while keeping track of which one. `@template T`
declares a type variable:

```php
<?php

declare(strict_types=1);

/**
 * @template T
 * @param list<T> $items
 * @param callable(T): bool $keep
 * @return T|null
 */
function firstWhere(array $items, callable $keep): mixed
{
    foreach ($items as $item) {
        if ($keep($item)) {
            return $item;
        }
    }

    return null;
}

final class Book
{
    public function __construct(public string $title, public int $year) {}
}

$books = [new Book('Dune', 1965), new Book('Emma', 1815)];
$old = firstWhere($books, fn (Book $b): bool => $b->year < 1900);

\PHPStan\dumpType($old);
echo $old->title;

/** @param array{title: string, year: int, tags?: list<string>} $row */
function describe(array $row): string
{
    return $row['title'] . ' (' . $row['year'] . ')' . implode(', ', $row['tags']);
}
```

`\PHPStan\dumpType()` is a debugging aid: PHPStan reports the type it inferred at that point (remove the call before
running the code; it exists only for the analyser). At level 8:

```text
 ------ ------------------------------------------------------------------ 
  Line   generics.php                                                      
 ------ ------------------------------------------------------------------ 
  30     Dumped type: Book|null                                            
         🪪  phpstan.dumpType (non-ignorable)                              
  31     Cannot access property $title on Book|null.                       
         🪪  property.nonObject                                            
  36     Offset 'tags' might not exist on array{title: string, year: int,  
         tags?: list<string>}.                                             
         🪪  offsetAccess.notFound                                         
 ------ ------------------------------------------------------------------ 


 [ERROR] Found 3 errors
```

From `list<Book>` PHPStan worked out that `T` is `Book`, so `$old` is `Book|null`, and it caught the missing `null`
check. It also knows `tags` is optional in the shape. Bookshelf's container uses the same mechanism: `get()` is
declared with `@template T of object`, `@param class-string<T>` and `@return T`, so
`$container->get(Kernel::class)` is known to return a `Kernel`.

### Living with errors: ignores and the baseline

Two tools exist for errors you won't fix right now:

- **An inline ignore**, for one line, naming the identifier so it can't hide a different error later:

  ```php
  // @phpstan-ignore argument.type (the legacy importer passes numeric strings; fixed in the CSV rewrite)
  $books->get($row['id']);
  ```

  Always add the reason. PHPStan reports an ignore that no longer matches any error, so stale ignores don't pile up.
- **A baseline**, for adopting PHPStan (or a higher level) on an existing code base. It records all current errors
  in a file, and they are ignored from then on. New code must be clean, and the old errors can be worked off over time:

  ```bash
  vendor/bin/phpstan analyse --generate-baseline      # writes phpstan-baseline.neon
  ```

  ```neon
  includes:
      - phpstan-baseline.neon

  parameters:
      level: 8
  ```

  The baseline lists each error with its message, identifier, file and count. When you fix one, PHPStan reports that
  the baseline entry no longer matches; regenerate the file so it shrinks. A baseline that only ever grows means the
  team is ignoring the tool.

Neither is a fix. Before reaching for one, check whether the error is telling you something true.

### Beyond the core

- **Extensions** teach PHPStan about frameworks and libraries whose magic it can't infer: `phpstan/phpstan-phpunit`
  (mocks are typed as both the mock and the doubled class), `phpstan/phpstan-strict-rules` (bans loose comparisons and
  other risky constructs), `phpstan/phpstan-deprecation-rules`, and plugins for Symfony, Doctrine and Laravel (Larastan).
  `phpstan/extension-installer` registers them automatically.
- **Psalm** is the other mature static analyser for PHP. It covers similar ground, with its own type syntax extensions
  and a taint analysis mode for security. Most projects pick one.
- **Editors** run PHPStan as you type (PhpStorm natively; VS Code via extensions), which is the fastest feedback of all.

## Coding style: PER-CS and PHP-CS-Fixer

Formatting arguments waste review time, and inconsistent formatting hides real changes in diffs. The PHP community's
shared style is the **PER Coding Style** (PER-CS), maintained by the PHP-FIG. It succeeded PSR-12 and adds rules for
newer syntax such as enums, attributes, property hooks and `new` without parentheses. Two tools apply such styles
automatically: **PHP-CS-Fixer** and **PHP_CodeSniffer** (`phpcs` reports, `phpcbf` fixes). Bookshelf uses
PHP-CS-Fixer, configured in `.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

// Coding style: PER Coding Style 3.0, adjusted to this project's habits, plus a few rules.
// Check with `composer cs`, fix with `composer cs:fix`.
$finder = new PhpCsFixer\Finder()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/public'])
    ->append([__DIR__ . '/bin/bookshelf']);

return new PhpCsFixer\Config()
    ->setRules([
        '@PER-CS3.0' => true,
        'function_declaration' => ['closure_fn_spacing' => 'one'],     // fn (int $x) rather than fn(int $x)
        'single_line_empty_body' => false,                              // ) {\n} for empty constructor bodies
        'method_argument_space' => ['on_multiline' => 'ignore'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
    ])
    ->setRiskyAllowed(true)          // declare_strict_types can change behavior, so it counts as "risky"
    ->setFinder($finder);
```

A rule set (`@PER-CS3.0`) is a bundle of individual rules; the lines after it adjust the bundle to the project's
existing habits and add rules PER-CS doesn't include. Adjust deliberately and rarely: the value of a standard is that
it's standard.

Here is what the check reports for a sloppily formatted file, with only `@PER-CS3.0`, `declare_strict_types` and
`no_unused_imports` enabled:

```bash
vendor/bin/php-cs-fixer check --diff
```

```text
   1) src/Shelf.php
      ---------- begin diff ----------
--- src/Shelf.php
+++ src/Shelf.php
@@ -1,13 +1,23 @@
 <?php
+
+declare(strict_types=1);
+
 namespace Demo;
 
-use Stringable;
 use Countable;
-class Shelf implements Countable{
-    private array $books=[];
-    public function add(string $title):void {
-        if($title=='') return;
-        $this->books[]=$title;
+
+class Shelf implements Countable
+{
+    private array $books = [];
+    public function add(string $title): void
+    {
+        if ($title == '') {
+            return;
+        }
+        $this->books[] = $title;
     }
-    public function count():int{ return count( $this->books ); }
+    public function count(): int
+    {
+        return count($this->books);
+    }
 }

      ----------- end diff -----------


Found 1 of 1 files that can be fixed in 0.039 seconds, 14.00 MB memory used
```

`check` changes nothing and exits with a non-zero code (8) when files need fixing, which is what CI wants. `fix`
rewrites the files. Notice what the fixer did *not* change: `$title == ''` is still a loose comparison. A formatter
changes how code looks, not what it does. Behavior-changing rules (like `strict_comparison`) are marked **risky** and
only run when you opt in, because they can change results. Spotting the loose comparison as a bug is a job for review,
tests, or PHPStan's strict-rules extension.

Add `.php-cs-fixer.cache` to `.gitignore`; the fixer uses it to skip unchanged files.

### Automated refactoring

**Rector** goes further than a formatter: it rewrites code structurally, using rules such as "upgrade this code base
to PHP 8.5 syntax", "add return types inferred from the code", or "replace this deprecated framework call". It's
mostly used for upgrades across many files: run it, review the diff like any other change, and run the tests.

## Debugging with Xdebug

`var_dump()` and `error_log()` get you far, and reading a failing test is often enough. When you need to watch the
code run (step through a loop, inspect every variable at a breakpoint, see the call stack), use a **step debugger**.
PHP's is **Xdebug**, an extension that connects from the running PHP process to your editor.

```text
  php (CLI script, test, or web request)          your editor (PhpStorm, VS Code + PHP Debug)
  ┌──────────────────────────────┐   DBGp over TCP   ┌────────────────────────────────┐
  │ Xdebug extension             │ ────────────────▶ │ listening on port 9003         │
  │ pauses at breakpoints        │ ◀──────────────── │ breakpoints, step, inspect     │
  └──────────────────────────────┘                   └────────────────────────────────┘
```

Note the direction: **PHP connects to the editor**, not the other way round. The editor must be listening first.

Install it from your OS packages (`apt install php8.5-xdebug` with the common Debian/Ubuntu PHP packages), from PECL,
or with PIE, the PHP Foundation's extension installer (`pie install xdebug/xdebug`). PHP 8.5 needs Xdebug 3.5 or later.
Then configure it in a development-only INI file:

```ini
; /etc/php/8.5/cli/conf.d/99-xdebug.ini (development machines only)
zend_extension=xdebug
xdebug.mode=develop,debug
xdebug.start_with_request=trigger
xdebug.client_host=localhost
xdebug.client_port=9003
```

- **`xdebug.mode`** selects features: `develop` (better error messages and `var_dump()` output), `debug` (step
  debugging), `coverage`, `profile` (chapter 21), `trace`, or `off`. The `XDEBUG_MODE` environment variable overrides
  it for one command, as in `XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text`.
- **`xdebug.start_with_request=trigger`** connects only when asked, so ordinary runs stay fast. For the CLI, set the
  `XDEBUG_TRIGGER` environment variable; for web requests, a browser extension sets an `XDEBUG_TRIGGER` cookie (a
  `?XDEBUG_TRIGGER=1` query parameter works too). With `yes`, every request tries to connect.
- **`xdebug.client_host` and `client_port`** say where the editor listens. Port 9003 is the default since Xdebug 3. When
  PHP runs in a container and the editor on the host, use `host.docker.internal` (Docker Desktop, or add
  `extra_hosts: ["host.docker.internal:host-gateway"]` on Linux), and map the container's paths to your local paths in
  the editor's settings.

A debugging session for one test:

```bash
XDEBUG_TRIGGER=1 vendor/bin/phpunit --filter testReturningLateChargesAFine
```

Set a breakpoint inside `LendingService::returnLoan()` first, start "listening" in the editor, then run the command:
execution pauses on the breakpoint with every variable inspectable. If nothing happens, `php -r 'xdebug_info();'` (or
`xdebug_info()` in a web page) prints Xdebug's configuration and the result of its last connection attempt.

Never load Xdebug in production. Even with `mode=off` the extension costs some speed, and with debugging enabled, it
lets anyone who can trigger it reach into the running process. Keep it in a development-only INI file or image.

## Putting it together: Composer scripts and CI

Locally, the three checks are one command each:

```bash
composer test          # PHPUnit
composer analyse       # PHPStan
composer cs            # PHP-CS-Fixer, check only (composer cs:fix to apply)
```

The same commands run in continuous integration on every push, so a change can't be merged while it breaks a test,
an analysis rule, or the style.

> **Just a taste — you don't need to learn or fully understand this now.** A GitHub Actions workflow running the three checks; chapter 22 covers CI and deployment.

```yaml
# .github/workflows/ci.yml
name: CI
on: [push, pull_request]

jobs:
  checks:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: intl, pdo_sqlite
          coverage: pcov
      - run: composer install --no-interaction --no-progress
      - run: composer cs
      - run: composer analyse
      - run: vendor/bin/phpunit --coverage-text
```

Order the steps from fastest to slowest so the cheapest failure is reported first.

## Testing habits that last

- **Write the test that would have caught the bug.** When a bug is reported, first write a test that fails because of
  it, then fix the code. The bug can't come back silently.
- **Test behavior through public methods.** Private methods are implementation details; test them through the public
  method that uses them. If a private method is complex enough to want its own tests, it's often a separate class
  waiting to be extracted.
- **Keep tests deterministic.** No real clock, no real randomness, no network, no dependence on order. A test that
  fails once in fifty runs ("flaky") teaches the team to ignore failures.
- **Keep tests fast.** A suite that runs in seconds gets run constantly. Bookshelf's 41 tests take under five
  seconds; most of that is bcrypt hashing at cost 12 in the authenticator tests (chapter 18), on purpose.
- **Treat test code as production code.** Same style, same static analysis, same refactoring. Helpers like
  `Builders` and `DatabaseTestCase` keep tests short as the suite grows.
- **Don't test the framework or the language.** A test that `new Book(...)` stores its title tests PHP's constructor
  promotion. Test the rules your code adds.

## Key takeaways

- Tests check the paths you choose; static analysis checks every path for type errors. Use both, plus a formatter.
- Unit tests (one class, no I/O) should be the bulk; integration tests prove the SQL and the wiring between parts;
  a few functional tests drive the whole app from `Request` to `Response`.
- PHPUnit 13 needs PHP 8.4+. Test classes extend `TestCase`; test methods start with `test` (or carry `#[Test]`);
  every test gets a fresh test-class instance.
- Prefer `assertSame` over `assertEquals` except for comparing distinct objects by value; expected value goes first.
- `expectException()` must come before the throwing call, which should be the last line of the test.
- Data providers are public static methods; name each data set and choose boundary values.
- Configure PHPUnit strictly: random order, fail on warnings, notices, deprecations and risky tests, and `force`d
  environment variables for tests.
- Stubs return canned answers; mocks verify calls. Mock only calls that *are* the behavior, don't mock what you don't
  own, and remember final classes can't be doubled. Fakes (in-memory repository, `FrozenClock`) are often clearest.
- Inject the clock and other sources of non-determinism so tests can control them.
- Coverage needs PCOV or Xdebug; red lines are facts, green lines aren't proof. `#[CoversClass]` keeps it honest.
- PHPStan levels 0–10: aim for at least 6, ideally 8+. Fix errors by making code state its intent, not by casting.
  PHPDoc types (`list<T>`, array shapes, generics) carry what native types can't.
- Baselines and identifier-specific `@phpstan-ignore` comments are for adoption and genuine exceptions, with reasons.
- PER-CS is the community style; PHP-CS-Fixer or PHP_CodeSniffer apply it. Formatters don't change behavior unless
  you allow risky rules.
- Xdebug connects *from* PHP *to* the editor on port 9003; use `start_with_request=trigger` and keep it out of
  production.

## Exercises

1. **Money.** Write `tests/Unit/Domain/MoneyTest.php` for Bookshelf's `Money` value object. Use a data provider for
   `format()` (zero, cents only, whole dollars, both), check that arithmetic returns new values and leaves the
   original unchanged, and that negative amounts are rejected.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Domain;

use Bookshelf\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    #[DataProvider('formats')]
    public function testFormatsAsDollarsAndCents(int $cents, string $expected): void
    {
        self::assertSame($expected, Money::cents($cents)->format());
    }

    /** @return iterable<string, array{int, string}> */
    public static function formats(): iterable
    {
        yield 'zero' => [0, '$0.00'];
        yield 'cents only' => [5, '$0.05'];
        yield 'whole dollars' => [1000, '$10.00'];
        yield 'both' => [1234, '$12.34'];
    }

    public function testArithmeticReturnsNewValues(): void
    {
        $fine = Money::cents(25);

        self::assertSame(75, $fine->times(3)->cents);
        self::assertSame(50, $fine->plus($fine)->cents);
        self::assertSame(25, $fine->cents);                  // unchanged: Money is immutable
        self::assertTrue(Money::cents(300)->min(Money::cents(250))->equals(Money::cents(250)));
    }

    public function testCannotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::cents(-1);
    }
}
```

The "cents only" case (`5` → `$0.05`) is the interesting one: it checks the zero padding in `%02d`, which a test with
only round amounts would never exercise. `Money` is a readonly class, so "unchanged" is guaranteed by the language;
the assertion documents that `plus()` and `times()` are meant to return new objects.

</details>

2. **The password policy.** Test `Passwords::problemWith()` with a data provider covering: an acceptable password,
   exactly 15 characters, 14 characters, 15 accented characters (30 bytes, still acceptable), too many bytes, a NUL
   byte, and invalid UTF-8. Add tests that `Passwords::hash()` refuses a bad password and produces a hash
   `password_verify()` accepts.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Security;

use Bookshelf\Security\Passwords;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Passwords::class)]
final class PasswordsTest extends TestCase
{
    #[DataProvider('passwords')]
    public function testExplainsWhatIsWrongWithAPassword(string $password, ?string $expected): void
    {
        self::assertSame($expected, Passwords::problemWith($password));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function passwords(): iterable
    {
        yield 'long enough' => ['correct horse battery staple', null];
        yield 'exactly 15 characters' => [str_repeat('a', 15), null];
        yield 'too short' => [str_repeat('a', 14), 'Use at least 15 characters'];
        yield '15 characters, counted as characters' => [str_repeat('é', 15), null];          // 30 bytes
        yield 'too many bytes' => [str_repeat('é', 37), 'Use at most 72 bytes (fewer characters if you use accents or emoji)'];
        yield 'NUL byte' => ["correct horse\0battery", 'The password contains invalid characters'];
        yield 'invalid UTF-8' => ["correct horse battery \xff", 'The password contains invalid characters'];
    }

    public function testHashRefusesABadPassword(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Use at least 15 characters'));

        Passwords::hash('short');
    }

    public function testHashProducesAVerifiableHash(): void
    {
        $hash = Passwords::hash('correct horse battery staple');

        self::assertTrue(password_verify('correct horse battery staple', $hash));
    }
}
```

37 × `é` is 37 characters but 74 bytes, so it passes the length rule and fails the byte rule. The "exactly 15" and
"14" cases pin the boundary on both sides. Comparing the full message with `assertSame` is deliberate here, because
the message is shown to users; for exception messages that are only for developers, a substring check is less brittle.

</details>

3. **The login throttle over time.** `LoginThrottle` takes limits as constructor arguments. Build one with
   `maxPerAccount: 3`, `maxPerAddress: 5` and `windowSeconds: 600` on an in-memory table and a `FrozenClock`, and test
   that: three failures block the account (also when the email differs in case and surrounding spaces) but not other
   accounts; five failures for different accounts from one address block that address; failures stop counting exactly
   when the window has passed; and `clear()` forgets an account's failures.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Security;

use Bookshelf\Security\LoginThrottle;
use Bookshelf\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginThrottle::class)]
final class LoginThrottleTest extends TestCase
{
    private FrozenClock $clock;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, subject TEXT, attempted_at INTEGER)');
        $this->clock = FrozenClock::at('2026-10-11 10:00');
        $this->throttle = new LoginThrottle($pdo, $this->clock, maxPerAccount: 3, maxPerAddress: 5, windowSeconds: 600);
    }

    public function testBlocksAnAccountAfterTooManyFailures(): void
    {
        $this->fail3('ada@example.org', '203.0.113.7');

        self::assertTrue($this->throttle->isBlocked('ada@example.org', '198.51.100.1'));
        self::assertTrue($this->throttle->isBlocked(' ADA@example.org ', '198.51.100.1'));   // same account
        self::assertFalse($this->throttle->isBlocked('bob@example.org', '198.51.100.1'));
    }

    public function testBlocksAnAddressThatTriesManyAccounts(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $this->throttle->recordFailure("$name@example.org", '203.0.113.7');
        }

        self::assertTrue($this->throttle->isBlocked('new@example.org', '203.0.113.7'));
        self::assertFalse($this->throttle->isBlocked('new@example.org', '198.51.100.1'));
    }

    public function testFailuresExpireAfterTheWindow(): void
    {
        $this->fail3('ada@example.org', '203.0.113.7');

        $this->clock->advance('+599 seconds');
        self::assertTrue($this->throttle->isBlocked('ada@example.org', '203.0.113.7'));

        $this->clock->advance('+1 second');
        self::assertFalse($this->throttle->isBlocked('ada@example.org', '203.0.113.7'));
    }

    public function testClearingForgetsTheAccountsFailures(): void
    {
        $this->fail3('ada@example.org', '203.0.113.7');

        $this->throttle->clear('ada@example.org');

        self::assertFalse($this->throttle->isBlocked('ada@example.org', '198.51.100.1'));
    }

    private function fail3(string $email, string $address): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->throttle->recordFailure($email, $address);
        }
    }
}
```

Small limits make the tests short and fast; the production defaults (5, 20, 15 minutes) are just constructor
defaults, so nothing about the logic changes. The expiry test checks both sides of the boundary, one second apart,
which is only possible because the clock is frozen. With the real clock, the test would need to sleep for ten minutes.

</details>

4. **Make PHPStan happy, honestly.** Fix all six errors in `overdue.php` so that it passes PHPStan at level 10, without
   casts, `@var` overrides or ignores. Decide what each function should do in the unusual case: an id that isn't in
   the list, a malformed date, a due date in the future, and a loan that hasn't been returned. Make the last two lines
   call the functions correctly.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final class Loan
{
    public function __construct(public int $id, public ?string $returnedOn, public string $dueOn)
    {
    }
}

/** @param list<Loan> $loans */
function dueDateOf(array $loans, int $id): string
{
    foreach ($loans as $loan) {
        if ($loan->id === $id) {
            return $loan->dueOn;
        }
    }

    throw new OutOfBoundsException("No loan with id $id");
}

function daysLate(string $dueOn, string $today): int
{
    $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueOn)
        ?: throw new InvalidArgumentException("Not a Y-m-d date: '$dueOn'");
    $interval = $due->diff(new DateTimeImmutable($today));

    return $interval->invert === 1 ? 0 : $interval->days;
}

function returnedLabel(Loan $loan): string
{
    return $loan->returnedOn === null ? 'Not returned yet' : 'Returned on ' . $loan->returnedOn;
}

$loans = [new Loan(7, null, '2026-11-01')];
echo daysLate(dueDateOf($loans, 7), '2026-11-25'), "\n";
echo returnedLabel($loans[0]), "\n";
```

```text
24
Not returned yet
```

Each fix is a decision, not a silencer:

- A missing id throws: returning `''` would push the problem into `daysLate()`.
- A malformed date throws with the bad value in the message. The `!` in the format resets the unspecified time fields
  to midnight; without it, the time of day is taken from "now", and the day count can be off by one depending on when
  the code runs.
- `diff()` returns a positive `days` count for both directions and sets `invert` when the interval is negative, so a
  future due date would have been reported as "days late". PHPStan didn't flag that bug; it's a logic error, not a
  type error, and the kind of thing a data-provider test with "due tomorrow" catches.
- A `null` return date gets its own label instead of `strtoupper(null)`.

PHPStan knows that a `DateInterval` produced by `diff()` always has an integer `days` (only intervals built by hand
have `days === false`), which is why no cast is needed there.

</details>

5. **API search tests.** Write a functional test for `GET /api/books`, using a data provider for the query parameters:
   no parameters, a title search, a genre filter, a genre with no books, and a search with no matches. Check the status,
   the titles in `data` (in any order) and the `count` field. Then test that an unknown genre returns `400` with the
   JSON body `{"error": "Unknown genre 'poetry'"}`. Make the whole test pass PHPStan at level 8.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Functional;

use Bookshelf\Container\Container;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Persistence\Migrator;
use Bookshelf\Tests\Support\Builders;
use Bookshelf\Web\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ApiTest extends TestCase
{
    use Builders;

    private Container $container;

    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
        $this->container->get(Migrator::class)->migrate();
        $books = $this->container->get(BookRepository::class);
        $books->save(self::book(id: null));
        $books->save(self::book(id: null, title: 'Emma', isbn: '9780141439587'));
    }

    /**
     * @param array<string, string> $query
     * @param list<string> $expectedTitles
     */
    #[DataProvider('searches')]
    public function testSearchesTheCatalog(array $query, array $expectedTitles): void
    {
        $response = $this->get('/api/books', $query);
        $titles = array_column($this->json($response)['data'], 'title');
        sort($titles);

        self::assertSame(200, $response->status);
        self::assertSame($expectedTitles, $titles);
        self::assertSame(count($expectedTitles), $this->json($response)['count']);
    }

    /** @return iterable<string, array{array<string, string>, list<string>}> */
    public static function searches(): iterable
    {
        yield 'everything' => [[], ['Dune', 'Emma']];
        yield 'by title' => [['q' => 'emm'], ['Emma']];
        yield 'by genre' => [['genre' => 'sf'], ['Dune', 'Emma']];
        yield 'another genre' => [['genre' => 'crime'], []];
        yield 'no match' => [['q' => 'zzz'], []];
    }

    public function testAnUnknownGenreIsABadRequest(): void
    {
        $response = $this->get('/api/books', ['genre' => 'poetry']);

        self::assertSame(400, $response->status);
        self::assertSame(['error' => "Unknown genre 'poetry'"], $this->json($response));
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->container->get(Kernel::class)->handle(new Request('GET', $path, query: $query));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
    }
}
```

The builder gives every book the science-fiction genre, which is why "by genre" returns both and "another genre"
returns none. Sorting the titles makes the assertion independent of the order SQL returns rows in, since the query has
no `ORDER BY` that the test should depend on. The first draft of this test built the query by parsing a URL string with
`parse_str()`; PHPStan rejected it, because `parse_str()` can produce nested arrays (`?a[]=1`) where `Request` expects
`array<string, mixed>`. Passing the array directly is simpler and fully typed.

</details>

6. **A fake repository.** Write `tests/Support/InMemoryMemberRepository.php`, a working `MemberRepository` that keeps
   members in an array, assigns ids on first save, finds members by email case-insensitively, throws `EntityNotFound`
   for unknown ids, and counts calls to `save()`. Use it to rewrite the "upgrades an outdated hash at login" test
   without `createMock()`. Which version of the test do you find clearer, and why?

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Support;

use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;

/**
 * A working MemberRepository that keeps members in an array: a fake, for tests.
 */
final class InMemoryMemberRepository implements MemberRepository
{
    /** @var array<int, Member> */
    private array $members = [];

    /** How many times save() was called, for tests that care. */
    public int $saves = 0;

    public function get(int $id): Member
    {
        return $this->members[$id] ?? throw EntityNotFound::member($id);
    }

    public function findByEmail(string $email): ?Member
    {
        foreach ($this->members as $member) {
            if (strcasecmp($member->email, $email) === 0) {
                return $member;
            }
        }

        return null;
    }

    public function save(Member $member): void
    {
        if ($member->id === null) {
            $member->assignId(count($this->members) + 1);
        }
        $this->members[(int) $member->id] = $member;
        $this->saves++;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Security;

use Bookshelf\Domain\Member;
use Bookshelf\Security\AuthenticationFailed;
use Bookshelf\Security\Authenticator;
use Bookshelf\Security\LoginThrottle;
use Bookshelf\Support\FrozenClock;
use Bookshelf\Tests\Support\InMemoryMemberRepository;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Authenticator::class)]
final class AuthenticatorWithFakeTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private InMemoryMemberRepository $members;
    private Authenticator $authenticator;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY, subject TEXT, attempted_at INTEGER)');
        $this->members = new InMemoryMemberRepository();
        $this->authenticator = new Authenticator(
            $this->members,
            new LoginThrottle($pdo, FrozenClock::at('2026-10-11 10:00')),
        );
    }

    public function testUpgradesAnOutdatedHashAtLogin(): void
    {
        $this->addAda(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]));

        $this->authenticator->authenticate('ada@example.org', self::PASSWORD, '203.0.113.7');

        $stored = $this->members->findByEmail('ada@example.org')?->passwordHash;
        self::assertSame(2, $this->members->saves);                     // the setup save, plus the upgrade
        self::assertFalse(password_needs_rehash((string) $stored, PASSWORD_DEFAULT));
    }

    public function testAWrongPasswordFails(): void
    {
        $this->addAda(password_hash(self::PASSWORD, PASSWORD_DEFAULT));

        $this->expectException(AuthenticationFailed::class);
        $this->authenticator->authenticate('ada@example.org', 'wrong password', '203.0.113.7');
    }

    private function addAda(string $hash): void
    {
        $this->members->save(new Member(null, 'Ada Lovelace', 'ada@example.org', passwordHash: $hash));
    }
}
```

The fake version sets up *state* ("Ada exists with this hash") and checks *state* afterwards ("the stored hash is
current"), so it would keep passing if the authenticator, say, saved through a different method. The mock version
is shorter for a single test and states the interaction exactly, but it breaks if the interaction changes. Fakes win
as more tests use them, because the setup cost is paid once. The price is that a fake is code that can be wrong, so
serious projects run the same contract tests against the fake and the real repository to keep them in agreement.
(The `(int)` cast in `save()` is safe because the line above guarantees an id; PHPStan can't see through
`assignId()`.)

</details>

---
← Previous: [Building an app without a framework](19-building-an-app-without-a-framework.md) | Next: [Performance and internals](21-performance-and-internals.md) →
