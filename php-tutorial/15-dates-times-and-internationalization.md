# Chapter 15 — Dates, times and internationalization

## What you'll learn

- The difference between an *instant* and a *calendar date*, and why it decides how you store and compute
- `DateTimeImmutable`: creating, parsing strictly, formatting, comparing
- Time zones and daylight saving time, and the bugs they cause
- Date arithmetic: intervals, the month-end trap, calendar days versus hours, `diff()` and `DatePeriod`
- Making "now" testable with a clock object
- Storing and exchanging dates in databases and APIs
- The `intl` extension: locales, number and currency formatting, localized dates, plural-aware messages, sorting
- Bookshelf's clocks and its display formatter

## Two kinds of time

Before any code, one distinction prevents most date bugs. There are two different things people call "a time":

```text
An INSTANT: a point on the global timeline, the same everywhere.
   "The loan was recorded at 2026-10-11 16:45:00 UTC"
   = 18:45 in Berlin = 12:45 in New York = 01:45 the next day in Tokyo
   Stored as: a Unix timestamp, or a date-time with a UTC offset.

A CALENDAR value: what a wall calendar or clock shows, meaningful only in some place.
   "The book is due on 1 November 2026"
   "The library opens at 09:00"
   Stored as: a date ("2026-11-01") or a local time ("09:00"), plus the place's time zone if needed.
```

A due date is a calendar value: "1 November" means the whole of that day in the library's town, not one instant.
Converting it to UTC and back in another time zone can move it to 31 October. An event log entry is an instant: it
happened at one moment, and each viewer may want to see it in their own time zone. Keep asking "is this an instant or a
calendar value?" and the rest of the chapter falls into place.

## Timestamps and measuring time

The simplest representation of an instant is the **Unix timestamp**: seconds since 1970-01-01 00:00:00 UTC, as an
`int`. It has no time zone, which is exactly what makes it unambiguous.

```php
<?php

declare(strict_types=1);

$now = time();                 // int: seconds since the Unix epoch, e.g. 1791737100
$precise = microtime(true);    // float: with microseconds, e.g. 1791737100.4182

// Measuring how long something takes: use hrtime(), a monotonic clock in nanoseconds.
$numbers = range(1, 1_000_000);
shuffle($numbers);
$start = hrtime(true);
sort($numbers);
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
printf("sorted in %.1f ms\n", $elapsedMs);
```

```text
sorted in 264.7 ms
```

(The number varies by machine.) Use `hrtime()` for durations, not `time()` or `microtime()`: the wall clock can jump
when the system synchronizes its time, so the difference between two wall-clock readings can even be negative.
`hrtime()` is **monotonic**, it only ever moves forward.

PHP also has older functions that work on timestamps directly: `date('Y-m-d', $ts)` formats, `strtotime('+1 day')`
parses, `mktime()` builds. You'll meet them in older code. They depend on the global default time zone and pass bare
integers around, which hides what the number means, so new code uses the date objects below.

## `DateTimeImmutable`

PHP has two date-time classes with the same methods: `DateTime` and `DateTimeImmutable`. Both implement
`DateTimeInterface`. Use **`DateTimeImmutable`**. With `DateTime`, every "change" method modifies the object itself,
and because objects are shared by handle (chapter 9), it also modifies everyone else's copy:

```php
<?php

declare(strict_types=1);

$lentOn = new DateTime('2026-10-11');
$dueOn = $lentOn->modify('+21 days');     // modifies $lentOn and returns it!
echo $lentOn->format('Y-m-d'), ' / ', $dueOn->format('Y-m-d'), "\n";

$lentOn = new DateTimeImmutable('2026-10-11');
$dueOn = $lentOn->modify('+21 days');     // returns a new object
echo $lentOn->format('Y-m-d'), ' / ', $dueOn->format('Y-m-d'), "\n";
```

```text
2026-11-01 / 2026-11-01
2026-10-11 / 2026-11-01
```

The mirror-image mistake with `DateTimeImmutable` is calling a method and ignoring its result. Since PHP 8.5, its
methods carry the `#[\NoDiscard]` attribute (chapter 11), so PHP warns you:

```php
<?php

declare(strict_types=1);

$dueOn = new DateTimeImmutable('2026-11-01');
$dueOn->modify('+21 days');   // the result is thrown away; $dueOn is unchanged
```

```text
Warning: The return value of method DateTimeImmutable::modify() should either be used or intentionally ignored by casting it as (void), as DateTimeImmutable::modify() does not modify the object itself in demo.php on line 6
```

Type parameters as `DateTimeInterface` when you only read a date (so callers may pass either class) and as
`DateTimeImmutable` when you store or compute with it. `DateTimeImmutable::createFromInterface($dt)` converts.

## Creating and parsing dates

### The constructor and relative formats

`new DateTimeImmutable($text, $timezone)` understands a huge range of formats, including relative ones:

```php
<?php

declare(strict_types=1);

$utc = new DateTimeZone('UTC');
$base = new DateTimeImmutable('2026-10-11 09:30', $utc);   // a Sunday

foreach (['2026-10-11 14:30:00', '2026-10-11T14:30:00+02:00', '@1791737100', 'tomorrow', '+3 weekdays',
          'next friday', 'first monday of next month', 'last day of this month', 'midnight'] as $text) {
    $date = str_starts_with($text, '20') || str_starts_with($text, '@')
        ? new DateTimeImmutable($text, $utc)
        : $base->modify($text);
    printf("%-30s %s\n", $text, $date->format('D Y-m-d H:i P'));
}
```

```text
2026-10-11 14:30:00            Sun 2026-10-11 14:30 +00:00
2026-10-11T14:30:00+02:00      Sun 2026-10-11 14:30 +02:00
@1791737100                    Sun 2026-10-11 16:45 +00:00
tomorrow                       Mon 2026-10-12 00:00 +00:00
+3 weekdays                    Wed 2026-10-14 09:30 +00:00
next friday                    Fri 2026-10-16 00:00 +00:00
first monday of next month     Mon 2026-11-02 00:00 +00:00
last day of this month         Sat 2026-10-31 09:30 +00:00
midnight                       Sun 2026-10-11 00:00 +00:00
```

Relative formats are convenient for code you write (`'+21 days'`, `'first day of next month'`). They are dangerous for
**user input**, because almost anything parses as *something*: `'+3 dayz'` is read as "+3 days in the time zone Z".
Text that cannot be parsed at all throws a `DateMalformedStringException` (PHP 8.3+; earlier versions threw a plain
`Exception`):

```php
<?php

declare(strict_types=1);

try {
    new DateTimeImmutable('next blursday');
} catch (DateMalformedStringException $e) {
    echo $e->getMessage(), "\n";
}
try {
    new DateTimeZone('Mars/Olympus_Mons');
} catch (DateInvalidTimeZoneException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
Failed to parse time string (next blursday) at position 0 (n): The timezone could not be found in the database
DateTimeZone::__construct(): Unknown or bad timezone (Mars/Olympus_Mons)
```

PHP 8.3 introduced a family of specific exceptions: `DateMalformedStringException`, `DateInvalidTimeZoneException`,
`DateMalformedIntervalStringException` and others, all subclasses of `DateException`, so you can catch exactly what you
expect.

### Parsing a known format strictly

For input with a known format (a form field, a CSV column, an API value), use `createFromFormat()`. It returns `false`
on failure instead of throwing, and it has two surprises:

```php
<?php

declare(strict_types=1);

// 1. Fields not in the format are taken from the current time.
echo DateTimeImmutable::createFromFormat('Y-m-d', '2026-10-11')->format('Y-m-d H:i:s'), "\n";
// "!" (at the start) or "|" (at the end) resets them to zero instead.
echo DateTimeImmutable::createFromFormat('!Y-m-d', '2026-10-11')->format('Y-m-d H:i:s'), "\n";

// 2. Impossible dates "overflow" instead of failing, with only a warning recorded.
$date = DateTimeImmutable::createFromFormat('!Y-m-d', '2026-02-30');
echo $date->format('Y-m-d'), "\n";
print_r(DateTimeImmutable::getLastErrors());
```

```text
2026-10-11 16:45:12
2026-10-11 00:00:00
2026-03-02
Array
(
    [warning_count] => 1
    [warnings] => Array
        (
            [10] => The parsed date was invalid
        )

    [error_count] => 0
    [errors] => Array
        (
        )

)
```

(The first line shows whatever time it was when the script ran.) 30 February became 2 March. For validation, you must
check `getLastErrors()`, which returns `false` when the last parse had neither errors nor warnings (PHP 8.2+; earlier
it returned an array of zero counts). A strict parser:

```php
<?php

declare(strict_types=1);

function parseDate(string $input, string $format = 'Y-m-d'): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!' . $format, $input);
    if ($date === false || DateTimeImmutable::getLastErrors() !== false) {
        throw new InvalidArgumentException("'$input' is not a valid date in the format $format");
    }

    return $date;
}

echo parseDate('2026-02-28')->format('D j M Y'), "\n";
foreach (['2026-02-30', '28/02/2026', '2026-2-28x'] as $bad) {
    try {
        parseDate($bad);
    } catch (InvalidArgumentException $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
Sat 28 Feb 2026
'2026-02-30' is not a valid date in the format Y-m-d
'28/02/2026' is not a valid date in the format Y-m-d
'2026-2-28x' is not a valid date in the format Y-m-d
```

Other constructors worth knowing: `DateTimeImmutable::createFromTimestamp(1791737100)` (PHP 8.4+, accepts a float for
fractions of a second; it always gives a UTC date), `createFromInterface()` and `createFromMutable()`.

## Formatting

`format()` takes a pattern of single-letter codes. The ones you will use most:

| Code | Meaning | Example |
|---|---|---|
| `Y`, `y` | Year, 4 or 2 digits | `2026`, `26` |
| `m`, `n` | Month with or without leading zero | `03`, `3` |
| `d`, `j` | Day of month with or without leading zero | `07`, `7` |
| `D`, `l` | Day name, short or full (English) | `Sun`, `Sunday` |
| `M`, `F` | Month name, short or full (English) | `Oct`, `October` |
| `S` | English ordinal suffix | `th` (as in `jS` → `11th`) |
| `N` | Day of week, 1 (Monday) to 7 (Sunday) | `7` |
| `W` | ISO week number | `41` |
| `t`, `L` | Days in the month, leap year (1 or 0) | `31`, `0` |
| `H`, `G`, `h`, `g` | Hour 24h with/without zero, 12h with/without zero | `09`, `9`, `09`, `9` |
| `i`, `s`, `v`, `u` | Minutes, seconds, milliseconds, microseconds | `05`, `03`, `000`, `000000` |
| `a`, `A` | am/pm, AM/PM | `am` |
| `e`, `T`, `P`, `O` | Time zone name, abbreviation, offset with and without colon | `Europe/Berlin`, `CEST`, `+02:00`, `+0200` |
| `U` | Unix timestamp | `1791709503` |
| `\` | Escapes the next character | `\T` prints `T` |

Standard formats exist as constants on `DateTimeInterface` (and as older global `DATE_*` constants):

```php
<?php

declare(strict_types=1);

$date = new DateTimeImmutable('2026-10-11 09:05:03.250', new DateTimeZone('Europe/Berlin'));

echo $date->format('l, jS F Y \a\t g:i a'), "\n";
echo $date->format(DateTimeInterface::ATOM), "\n";               // RFC 3339 / ISO 8601
echo $date->format(DateTimeInterface::RFC3339_EXTENDED), "\n";   // with milliseconds
echo $date->format('Y-m-d'), ' ', $date->format('H:i'), ' ', $date->format('e T P'), "\n";
```

```text
Sunday, 11th October 2026 at 9:05 am
2026-10-11T09:05:03+02:00
2026-10-11T09:05:03.250+02:00
2026-10-11 09:05 Europe/Berlin CEST +02:00
```

`ATOM` (identical to `RFC3339`) is the format for APIs and logs: unambiguous, sortable as text, and understood by
JavaScript's `new Date()`. PHP 8.5 deprecates the `DATE_RFC7231` constant (and `DateTimeInterface::RFC7231`), whose
format hard-codes "GMT" whatever the date's real time zone. For HTTP headers, convert to UTC first and format with
`'D, d M Y H:i:s \G\M\T'`.

`format()` always uses English names. For names and orders in the reader's language, use `IntlDateFormatter`, later in
this chapter.

## Time zones

### The default time zone

Dates created without an explicit zone use the **default time zone**: the `date.timezone` setting in `php.ini`, or
`date_default_timezone_set()`, or UTC if neither is set. Servers should run PHP with `date.timezone = UTC` and
application code should name the time zone whenever a local time matters. Relying on the server's setting means your
due dates change when the app moves to a server in another region.

```php
<?php

declare(strict_types=1);

$berlin = new DateTimeZone('Europe/Berlin');
$meeting = new DateTimeImmutable('2026-10-11 14:30', $berlin);   // 14:30 *in Berlin*

foreach (['UTC', 'America/New_York', 'Asia/Tokyo', 'Asia/Kolkata'] as $zone) {
    echo str_pad($zone, 18), $meeting->setTimezone(new DateTimeZone($zone))->format('D H:i T (P)'), "\n";
}
```

```text
UTC               Sun 12:30 UTC (+00:00)
America/New_York  Sun 08:30 EDT (-04:00)
Asia/Tokyo        Sun 21:30 JST (+09:00)
Asia/Kolkata      Sun 18:00 IST (+05:30)
```

The two calls look similar and do opposite things:

- `new DateTimeImmutable('14:30', $zone)` says "14:30 **as read on a clock in** that zone". It picks an instant.
- `->setTimezone($zone)` keeps the instant and changes only how it is **displayed**.

Use **named** zones such as `Europe/Berlin`, never fixed offsets such as `+02:00`, for places. A named zone knows the
place's rules, including daylight saving time and historical changes; an offset is only correct for part of the year.
Berlin is `+02:00` in summer (CEST) and `+01:00` in winter (CET). Avoid the abbreviations (`EST`, `IST`) as input:
they are ambiguous (IST is India, Israel and Ireland). `DateTimeZone::listIdentifiers()` returns the roughly 400 valid
names from the IANA time zone database bundled with PHP.

### Daylight saving time

Twice a year in many countries, local time jumps. In the spring an hour does not exist; in the autumn an hour happens
twice:

```php
<?php

declare(strict_types=1);

$newYork = new DateTimeZone('America/New_York');

// 8 March 2026: clocks jump from 02:00 to 03:00. 02:30 never happens.
echo (new DateTimeImmutable('2026-03-08 02:30', $newYork))->format('Y-m-d H:i T'), "\n";

// 1 November 2026: 01:00-02:00 happens twice, first as EDT, then as EST. PHP picks the first.
echo (new DateTimeImmutable('2026-11-01 01:30', $newYork))->format('Y-m-d H:i T'), "\n";
```

```text
2026-03-08 03:30 EDT
2026-11-01 01:30 EDT
```

A nonexistent local time is moved forward, and an ambiguous one takes the first occurrence. This matters for
anything scheduled at night in local time: a job "every day at 02:30" either runs at 03:30 or twice. Schedule such
jobs in UTC, or outside the 01:00–03:00 window.

## Date arithmetic

### `modify()`, `add()` and `DateInterval`

`modify()` takes the relative formats shown earlier. `add()` and `sub()` take a `DateInterval`, built from an ISO 8601
duration: `P` (period), then years `Y`, months `M`, weeks `W`, days `D`, then `T` and hours `H`, minutes `M`,
seconds `S`:

```php
<?php

declare(strict_types=1);

$start = new DateTimeImmutable('2026-10-11 09:00');

echo $start->add(new DateInterval('P21D'))->format('Y-m-d H:i'), "\n";        // 21 days
echo $start->add(new DateInterval('P1M2DT3H'))->format('Y-m-d H:i'), "\n";    // 1 month, 2 days, 3 hours
echo $start->sub(new DateInterval('PT90M'))->format('Y-m-d H:i'), "\n";       // 90 minutes earlier
echo (new DateInterval('P1M2DT3H'))->format('%m month, %d days, %h hours'), "\n";
```

```text
2026-11-01 09:00
2026-11-13 12:00
2026-10-11 07:30
1 month, 2 days, 3 hours
```

### The month-end trap

Adding a month to 31 January asks for 31 February, which does not exist. PHP **overflows** into March:

```php
<?php

declare(strict_types=1);

$jan31 = new DateTimeImmutable('2026-01-31');

echo $jan31->modify('+1 month')->format('Y-m-d'), "\n";                   // probably not what you wanted
echo $jan31->modify('last day of next month')->format('Y-m-d'), "\n";     // the end of February
echo $jan31->modify('first day of next month')->format('Y-m-d'), "\n";
```

```text
2026-03-03
2026-02-28
2026-02-01
```

Monthly billing and "renew for one month" features must decide what the business rule is (same day number, clamped
to the month's end?) and implement it explicitly with `first day of` / `last day of`. Never `+1 month` blindly.

### Calendar days versus hours

Across a daylight saving change, "one day later" and "24 hours later" are different instants. `modify('+21 days')` and
`P21D` move the **calendar**; `PT504H` (21 × 24 hours) moves the **clock**:

```php
<?php

declare(strict_types=1);

$lentOn = new DateTimeImmutable('2026-10-11 00:00', new DateTimeZone('Europe/Berlin'));

echo $lentOn->modify('+21 days')->format('D j M H:i T'), "\n";
echo $lentOn->add(new DateInterval('PT504H'))->format('D j M H:i T'), "\n";
```

```text
Sun 1 Nov 00:00 CET
Sat 31 Oct 23:00 CET
```

Berlin left summer time on 25 October, so those 21 calendar days contain 505 hours. A due date "21 days after the
loan" is a calendar rule, so Bookshelf's `Loan` uses `modify("+$days days")` on midnight in the library's time zone,
and the due date stays at midnight on the right day. Conversely, "the session expires in 24 hours" is a duration, and
should use hours (or timestamps).

### Differences and comparisons

`diff()` returns a `DateInterval` describing the gap between two dates:

```php
<?php

declare(strict_types=1);

$due = new DateTimeImmutable('2026-01-15');
$returned = new DateTimeImmutable('2026-03-20');

$gap = $due->diff($returned);
echo $gap->days, " days in total\n";                     // total days: usually what you want
echo $gap->m, ' months and ', $gap->d, " days\n";       // broken down; ->d is NOT the total
echo $gap->format('%R%a days'), "\n";                    // sign and total days
echo $returned->diff($due)->format('%R%a days'), "\n";

var_dump($due < $returned, $due == new DateTimeImmutable('2026-01-15 00:00'));
```

```text
64 days in total
2 months and 5 days
+64 days
-64 days
bool(true)
bool(true)
```

- `$interval->days` is the total number of days; `->y`, `->m`, `->d`, `->h`, `->i`, `->s` are the broken-down parts.
  Confusing `->d` with `->days` is a classic bug: here `->d` is 5.
- `->invert` is `1` when the second date is earlier; `%R` prints the sign and `%r` prints `-` only when negative.
- Date objects compare with `<`, `>` and `==` by the **instant** they represent, even across time zones.
  `===` compares object identity, as for any object (chapter 9).

### Iterating over dates: `DatePeriod`

`DatePeriod` produces a series of dates, and works with `foreach` (chapter 13):

```php
<?php

declare(strict_types=1);

$period = new DatePeriod(
    new DateTimeImmutable('2026-10-01'),
    new DateInterval('P1W'),
    new DateTimeImmutable('2026-10-29'),
    DatePeriod::INCLUDE_END_DATE,          // PHP 8.2+; by default the end date is excluded
);

foreach ($period as $thursday) {
    echo $thursday->format('D j M'), "\n";
}
```

```text
Thu 1 Oct
Thu 8 Oct
Thu 15 Oct
Thu 22 Oct
Thu 29 Oct
```

The third argument can also be a number of recurrences instead of an end date, and `DatePeriod::EXCLUDE_START_DATE`
skips the first one.

## Testable time: clocks

Code that calls `new DateTimeImmutable()` (meaning "now") inside its logic is hard to test: the result changes every
time it runs, and you cannot check what happens on a leap day or 30 days from now without waiting. The fix is a
dependency (chapter 10): code that needs the current time asks a **clock** object, and tests pass a clock that stands
still.

The PHP community standardized this interface as **PSR-20** (`Psr\Clock\ClockInterface`, one method, `now()`). Bookshelf
defines the same shape itself, so it does not need the package for one method:

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

The real implementation creates "now" in the **library's** time zone, because that zone decides when a day starts and
ends, and therefore what "today" and "21 days from today" mean:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real time, in the library's own time zone (which decides when a day starts and ends).
 */
final readonly class SystemClock implements Clock
{
    public function __construct(
        private DateTimeZone $timezone = new DateTimeZone('UTC'),
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
```

(`new` in a parameter default has been allowed since PHP 8.1.) The test implementation, `FrozenClock`, returns the same
instant until you move it:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that stands still until told to move. For tests and demos.
 */
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

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
```

With it, a whole loan's life plays out in milliseconds. This script, saved in `examples/bookshelf/`, uses the domain
classes from chapters 9 to 11:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\Book;
use Bookshelf\Domain\Isbn;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberType;
use Bookshelf\Support\FrozenClock;

$clock = FrozenClock::at('2026-10-11 18:45', 'Europe/Berlin');

$book = new Book(1, 'Dune', 'Frank Herbert', new Isbn('978-0-441-01359-3'));
$member = new Member(1, 'Ada', 'ada@example.org', MemberType::Standard);
$loan = Loan::start($book, $member, $clock->now());
echo 'lent ', $loan->lentOn->format('D j M Y'), ', due ', $loan->dueOn->format('D j M Y'), "\n";

$clock->advance('+21 days');
echo $clock->now()->format('D j M'), ': ', $loan->status($clock->now())->label(), "\n";

$clock->advance('+1 day');
echo $clock->now()->format('D j M'), ': ', $loan->status($clock->now())->label(), ', fine ', $loan->fine($clock->now()), "\n";

$clock->advance('+60 days');
echo $clock->now()->format('D j M'), ': ', $loan->daysOverdue($clock->now()), ' days overdue, fine ', $loan->fine($clock->now()), " (capped)\n";
```

```text
lent Sun 11 Oct 2026, due Sun 1 Nov 2026
Sun 1 Nov: On loan
Mon 2 Nov: Overdue, fine $0.25
Fri 1 Jan: 61 days overdue, fine $10.00 (capped)
```

`Loan`'s methods were already written to take `$today` as a parameter rather than reading the time themselves, which
is the same idea one level down: pure domain logic receives the time, and only the outer layer (a controller, a
command) asks the clock. In chapter 20, tests use `FrozenClock` to check the overdue rules on exact days. The
`symfony/clock` package offers the same pair (`NativeClock`, `MockClock`) plus PSR-20 compatibility.

## Storing and exchanging dates

| Value | In PostgreSQL | In SQLite or MySQL | In JSON |
|---|---|---|---|
| An instant (created at, lent at) | `timestamptz` | UTC text `'2026-10-11 16:45:00'` or an integer timestamp | `"2026-10-11T16:45:00+00:00"` |
| A calendar date (due on, birthday) | `date` | `'2026-11-01'` | `"2026-11-01"` |
| A local time of day (opens at) | `time` | `'09:00'` | `"09:00"` |

- Store instants in **UTC**, and convert to the viewer's zone only for display. Then sorting, comparing and arithmetic
  in the database just work.
- Store calendar dates **as dates**, without a time or zone. Bookshelf stores due dates as `'Y-m-d'` text (chapter 17)
  and rebuilds them at midnight in the library's zone.
- In APIs, send instants in RFC 3339 format with an offset (`DateTimeInterface::ATOM`), never as local time without
  one. Do not `json_encode()` a date object directly: it produces
  `{"date":"2026-10-11 10:00:00.000000","timezone_type":3,"timezone":"Europe/Berlin"}`, an internal structure no client
  expects. Format it yourself or implement `JsonSerializable`.

## Internationalization with `intl`

**Internationalization** (i18n) means writing software that can adapt to different languages and regions;
**localization** (l10n) is the adaptation for one of them. Numbers, prices, dates, plurals and alphabetical order all
differ by region, and doing them by hand is a losing battle. The **`intl` extension** wraps ICU, the Unicode
consortium's library that browsers and operating systems also use. It is usually installed (`php -m | grep intl`;
on Debian and Ubuntu the package is `php8.5-intl`), and Bookshelf now lists `ext-intl` in its `composer.json`.

The outputs in this section come from ICU 74. Exact results, especially spacing, can change between ICU versions:
recent versions put a **narrow no-break space** (U+202F) before "PM" in English times and between thousands in French,
and a no-break space (U+00A0) before a trailing currency symbol. They look like ordinary spaces but are not, so tests that compare formatted strings should build the expected value
with the same formatter, or normalize spaces first.

### Locales

A **locale** identifies a language and optionally a region and other variants: `en` (English), `en_US` (English as in
the US), `de_CH` (German as in Switzerland), `pt_BR`, `sr_Latn_RS` (Serbian in Latin script, Serbia). PHP accepts both
`en_US` and the web's `en-US`.

```php
<?php

declare(strict_types=1);

// The best match for a browser's Accept-Language header, among the locales you support:
$header = 'de-CH,de;q=0.9,en;q=0.8';
echo Locale::lookup(['en', 'de', 'fr'], Locale::acceptFromHttp($header), true, 'en'), "\n";

echo Locale::getDisplayName('pt_BR', 'en'), ' / ', Locale::getDisplayName('pt_BR', 'pt'), "\n";
```

```text
de
Portuguese (Brazil) / português (Brasil)
```

`Locale::acceptFromHttp()` returns the header's first preference (`de_CH`), and `Locale::lookup()` falls back from
`de_CH` to `de`, the closest locale you actually support.

### Numbers and currencies

```php
<?php

declare(strict_types=1);

foreach (['en_US', 'de_DE', 'fr_FR', 'hi_IN'] as $locale) {
    $number = new NumberFormatter($locale, NumberFormatter::DECIMAL);
    $money = new NumberFormatter($locale, NumberFormatter::CURRENCY);
    echo $locale, ': ', $number->format(1234567.891), ' | ', $money->formatCurrency(1234.5, 'EUR'), "\n";
}

echo (new NumberFormatter('en_US', NumberFormatter::PERCENT))->format(0.256), "\n";
echo (new NumberFormatter('en_US', NumberFormatter::SPELLOUT))->format(1234), "\n";
echo (new NumberFormatter('en_US', NumberFormatter::ORDINAL))->format(22), "\n";

var_dump((new NumberFormatter('de_DE', NumberFormatter::DECIMAL))->parse('1.234,5'));
```

```text
en_US: 1,234,567.891 | €1,234.50
de_DE: 1.234.567,891 | 1.234,50 €
fr_FR: 1 234 567,891 | 1 234,50 €
hi_IN: 12,34,567.891 | €1,234.50
26%
one thousand two hundred thirty-four
22nd
float(1234.5)
```

Each region has its own decimal separator, grouping (Indian numbering groups lakhs and crores: `12,34,567`) and
currency position. `parse()` reads numbers the way users in that locale type them, which matters for form input: a
German user types `1.234,5`, and `(float) '1.234,5'` would give `1.234`. Note that the currency is a separate argument
from the locale: a German user can look at a price in US dollars (`1.234,50 $`). Keep amounts as integers in the
smallest unit (Bookshelf's `Money` stores cents) and convert to a float only at the moment of display.

### Localized dates

`IntlDateFormatter` formats dates with month and day names, order and punctuation of the locale, in a time zone you
choose:

```php
<?php

declare(strict_types=1);

$lentAt = new DateTimeImmutable('2026-10-11 14:30', new DateTimeZone('UTC'));

foreach (['en_US', 'de_DE', 'fr_FR', 'ja_JP'] as $locale) {
    $formatter = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::SHORT, 'Europe/Berlin');
    echo $locale, ': ', $formatter->format($lentAt), "\n";
}

// A custom ICU pattern (not the same codes as DateTimeImmutable::format()):
$custom = new IntlDateFormatter('fr_FR', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Paris',
    pattern: 'EEEE d MMMM y');
echo $custom->format($lentAt), "\n";
```

```text
en_US: October 11, 2026 at 4:30 PM
de_DE: 11. Oktober 2026 um 16:30
fr_FR: 11 octobre 2026 à 16:30
ja_JP: 2026年10月11日 16:30
dimanche 11 octobre 2026
```

The date and time styles are `FULL`, `LONG`, `MEDIUM`, `SHORT` or `NONE`. Prefer the styles over custom patterns: they
know that Americans write the month first and Germans put a period after the day. The time zone argument is the zone
in which to *display* the instant; the date object's own zone is ignored.

### Messages with plurals

"You have 1 loans" is the classic sign of a program that concatenates strings. English has two plural forms, but
Russian has four, Arabic six, and Japanese one. `MessageFormatter` uses ICU's message syntax, which handles plural
rules, gender-like choices and embedded numbers and dates:

```php
<?php

declare(strict_types=1);

$pattern = '{count, plural, =0 {You have no loans} one {You have one loan} other {You have # loans}}';
foreach ([0, 1, 5, 1200] as $count) {
    echo MessageFormatter::formatMessage('en_US', $pattern, ['count' => $count]), "\n";
}

$russian = '{count, plural, one {# книга} few {# книги} many {# книг} other {# книги}}';
foreach ([1, 2, 5, 21] as $count) {
    echo MessageFormatter::formatMessage('ru_RU', $russian, ['count' => $count]), "\n";
}

echo MessageFormatter::formatMessage(
    'en_US',
    '{name} borrowed {title} on {lent, date, long}. Fine: {fine, number, currency}.',
    ['name' => 'Ada', 'title' => 'Dune', 'lent' => new DateTime('2026-10-11'), 'fine' => 2.5],
), "\n";

echo MessageFormatter::formatMessage('en', '{pronoun, select, she {She} he {He} other {They}} returned the book.',
    ['pronoun' => 'other']), "\n";
```

```text
You have no loans
You have one loan
You have 5 loans
You have 1,200 loans
1 книга
2 книги
5 книг
21 книга
Ada borrowed Dune on October 11, 2026. Fine: $2.50.
They returned the book.
```

- `plural` picks a branch with the language's plural rules: `=0` matches exactly zero; `one`, `few`, `many`, `other`
  are the categories the language defines (Russian: 1 and 21 are `one`, 2–4 are `few`, 5–20 are `many`). `#` is the
  number, formatted for the locale (`1,200`).
- `select` chooses by a string value, with `other` as the fallback.
- `{x, number, currency}` and `{x, date, long}` format embedded values. (`date` arguments here accept `DateTime`
  objects or timestamps.)

### Translating text

`MessageFormatter` formats a pattern; it does not choose one. Translation means keeping each user-visible text in a
**catalog** per language, looked up by a key, and formatting the result:

```php
<?php

declare(strict_types=1);

$catalogs = [
    'en' => ['loans.count' => '{count, plural, =0 {No loans} one {One loan} other {# loans}}'],
    'de' => ['loans.count' => '{count, plural, =0 {Keine Ausleihen} one {Eine Ausleihe} other {# Ausleihen}}'],
];

function t(array $catalogs, string $locale, string $key, array $args = []): string
{
    $pattern = $catalogs[$locale][$key] ?? $catalogs['en'][$key] ?? $key;

    return MessageFormatter::formatMessage($locale, $pattern, $args);
}

echo t($catalogs, 'de', 'loans.count', ['count' => 3]), "\n";
echo t($catalogs, 'de', 'loans.count', ['count' => 1]), "\n";
echo t($catalogs, 'en', 'loans.count', ['count' => 0]), "\n";
```

```text
3 Ausleihen
Eine Ausleihe
No loans
```

Real projects keep catalogs in files (YAML, XLIFF, `.po` files from the `gettext` toolchain, or PHP arrays) that
translators edit, and use a component such as `symfony/translation` or Laravel's translator, which add file loading,
fallback locales and tooling to find untranslated keys. The principle stays the same: never build sentences by
concatenation, always format a whole pattern with arguments, because word order differs between languages.

### Sorting and comparing text

`sort()` compares bytes, which puts uppercase before lowercase and accented letters after `z`. `Collator` sorts the way
people in a locale expect:

```php
<?php

declare(strict_types=1);

$names = ['Zoë', 'zebra', 'Émile', 'Eve', 'apple', 'Ångström', 'Oscar', 'Örjan'];

$bytes = $names;
sort($bytes);
echo implode(', ', $bytes), "\n";

$english = $names;
(new Collator('en_US'))->sort($english);
echo implode(', ', $english), "\n";

$swedish = $names;
(new Collator('sv_SE'))->sort($swedish);
echo implode(', ', $swedish), "\n";

$chapters = ['ch10', 'ch9', 'ch1'];
$natural = new Collator('en_US');
$natural->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
$natural->sort($chapters);
echo implode(', ', $chapters), "\n";

$loose = new Collator('en_US');
$loose->setStrength(Collator::PRIMARY);          // ignore case and accents
var_dump($loose->compare('resume', 'Résumé'));    // 0: equal
```

```text
Eve, Oscar, Zoë, apple, zebra, Ångström, Émile, Örjan
Ångström, apple, Émile, Eve, Örjan, Oscar, zebra, Zoë
apple, Émile, Eve, Oscar, zebra, Zoë, Ångström, Örjan
ch1, ch9, ch10
int(0)
```

In Swedish, `Å` and `Ö` are separate letters at the end of the alphabet, so the "correct" order depends on the
reader's language. Sorting in the database instead (chapter 17) uses the database's collation, which you configure
there.

The other `intl` classes, briefly: `Normalizer` converts between Unicode normalization forms (chapter 4 showed why
`é` can be one code point or two), `Transliterator` converts between scripts (`'Any-Latin; Latin-ASCII'` turns
`Москва` into `Moskva`, handy for URL slugs), `IntlBreakIterator` finds word and sentence boundaries, and the
`grapheme_*` functions from chapter 4 count user-perceived characters.

## Bookshelf: clocks and a display formatter

Bookshelf now has the three clock files shown above in `src/Support/`, and a `DisplayFormatter` that the web pages
(chapter 19) and the command-line tool use to show values to people. It holds one locale and time zone, and creates
its ICU formatters once, because constructing them is comparatively expensive:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use Bookshelf\Domain\Money;
use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use MessageFormatter;
use NumberFormatter;

/**
 * Formats values for people, in one locale and time zone, using the intl extension.
 */
final class DisplayFormatter
{
    private readonly NumberFormatter $currency;
    private readonly IntlDateFormatter $date;
    private readonly MessageFormatter $dueMessage;

    public function __construct(
        public readonly string $locale = 'en_US',
        string $timezone = 'UTC',
    ) {
        $this->currency = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $this->date = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $timezone);
        $this->dueMessage = new MessageFormatter($locale, '{days, plural,'
            . ' =-1 {due yesterday} =0 {due today} =1 {due tomorrow}'
            . ' other {{days, number} days left}}');
    }

    /** Money amounts are stored in US cents; dividing for display is the only place a float appears. */
    public function money(Money $amount): string
    {
        return $this->currency->formatCurrency($amount->cents / 100, 'USD');
    }

    public function date(DateTimeInterface $date): string
    {
        return $this->date->format($date);
    }

    /** "due today", "due tomorrow", "12 days left"; overdue loans are described by overdue(). */
    public function dueIn(DateTimeImmutable $dueOn, DateTimeImmutable $today): string
    {
        $days = (int) $today->setTime(0, 0)->diff($dueOn->setTime(0, 0))->format('%r%a');

        return $days < -1 ? $this->overdue(-$days) : $this->dueMessage->format(['days' => $days]);
    }

    public function overdue(int $days): string
    {
        return MessageFormatter::formatMessage($this->locale, '{days, plural, one {# day overdue} other {# days overdue}}', [
            'days' => $days,
        ]);
    }
}
```

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\Money;
use Bookshelf\Support\DisplayFormatter;

$today = new DateTimeImmutable('2026-10-11 15:00');

foreach (['en_US', 'de_DE'] as $locale) {
    $format = new DisplayFormatter($locale, 'Europe/Berlin');
    echo $format->money(Money::cents(123450)), ' | ', $format->date($today), "\n";
    foreach ([-5, -1, 0, 1, 1500] as $days) {
        echo '  ', $format->dueIn($today->modify("$days days"), $today), "\n";
    }
}
```

```text
$1,234.50 | Oct 11, 2026
  5 days overdue
  due yesterday
  due today
  due tomorrow
  1,500 days left
1.234,50 $ | 11.10.2026
  5 days overdue
  due yesterday
  due today
  due tomorrow
  1.500 days left
```

The numbers and dates follow the locale, but the words stay English: the message patterns are hard-coded. A
multilingual Bookshelf would load them from per-language catalogs, as in "Translating text" above. Note how `dueIn()`
uses `%r%a` (signed total days) between two **midnights**, so that "due tomorrow" means the next calendar day, not
"within 24 hours".

## Key takeaways

- Decide for every value whether it is an **instant** (store in UTC, display in the viewer's zone) or a **calendar
  value** (store as a date or local time, interpret in the place's zone).
- Use `DateTimeImmutable`; `DateTime`'s methods change the object in place. PHP 8.5 warns when you discard the result
  of a `DateTimeImmutable` method.
- Parse user input with `createFromFormat('!Y-m-d', ...)` and check `getLastErrors()`; otherwise invalid dates overflow
  silently. PHP 8.3+ throws specific exceptions such as `DateMalformedStringException`.
- Always name time zones (`Europe/Berlin`), never rely on the server default or fixed offsets, and run servers in UTC.
  DST creates local times that don't exist or happen twice.
- `+1 month` overflows at month ends; `+N days` moves the calendar while `PTnH` moves the clock. `->days` is the total,
  `->d` is not.
- Inject a clock (PSR-20 shape) instead of calling "now" inside logic, and use a frozen clock in tests.
- Use `intl` for anything shown to people: `NumberFormatter`, `IntlDateFormatter`, `MessageFormatter` for plurals and
  whole-sentence patterns, `Collator` for sorting. Never build sentences by concatenation.

## Exercises

1. **Library opening hours.** The library opens 10:00–18:00 Monday to Friday and 10:00–14:00 on Saturday, closed
   Sunday, in `Europe/Berlin`. Write `isOpen(DateTimeImmutable $instant): bool` that works whatever time zone
   `$instant` is in. Check it with `2026-10-12 08:30 UTC` (open: 10:30 in Berlin), `2026-10-17 13:30 UTC` (closed:
   15:30 on a Saturday) and `2026-10-11 12:00 UTC` (Sunday).

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function isOpen(DateTimeImmutable $instant): bool
{
    $local = $instant->setTimezone(new DateTimeZone('Europe/Berlin'));
    $hours = [6 => ['10:00', '14:00']] + array_fill(1, 5, ['10:00', '18:00']);   // ISO day numbers: 1 = Monday

    $today = $hours[(int) $local->format('N')] ?? null;
    if ($today === null) {
        return false;
    }
    $time = $local->format('H:i');

    return $time >= $today[0] && $time < $today[1];
}

$utc = new DateTimeZone('UTC');
foreach (['2026-10-12 08:30', '2026-10-17 13:30', '2026-10-11 12:00'] as $text) {
    $instant = new DateTimeImmutable($text, $utc);
    echo $text, ' UTC: ', isOpen($instant) ? 'open' : 'closed', "\n";
}
```

```text
2026-10-12 08:30 UTC: open
2026-10-17 13:30 UTC: closed
2026-10-11 12:00 UTC: closed
```

Converting to the library's zone first is the whole trick: opening hours are calendar values that belong to Berlin.
Comparing `'H:i'` strings works because they have a fixed width; `'9:00' < '10:00'` would be false as strings, which is
why the format uses `H` (with a leading zero).

</details>

2. **Business days.** Write `addBusinessDays(DateTimeImmutable $start, int $days, array $holidays = []): DateTimeImmutable`
   that skips Saturdays, Sundays and dates listed in `$holidays` (as `'Y-m-d'` strings). Adding 5 business days to
   Thursday 2026-12-24 with the holidays `2026-12-25` and `2026-12-26` should give Friday 2027-01-01... unless
   `2027-01-01` is also a holiday, in which case Monday 2027-01-04.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/** @param list<string> $holidays */
function addBusinessDays(DateTimeImmutable $start, int $days, array $holidays = []): DateTimeImmutable
{
    $holidaySet = array_flip($holidays);
    $date = $start;
    while ($days > 0) {
        $date = $date->modify('+1 day');
        if ((int) $date->format('N') >= 6 || isset($holidaySet[$date->format('Y-m-d')])) {
            continue;
        }
        $days--;
    }

    return $date;
}

$start = new DateTimeImmutable('2026-12-24');
echo addBusinessDays($start, 5, ['2026-12-25', '2026-12-26'])->format('D Y-m-d'), "\n";
echo addBusinessDays($start, 5, ['2026-12-25', '2026-12-26', '2027-01-01'])->format('D Y-m-d'), "\n";
```

```text
Fri 2027-01-01
Mon 2027-01-04
```

`array_flip()` turns the list into a set, so each lookup is O(1) (chapter 5). The `'+3 weekdays'` relative format skips
weekends but knows nothing about holidays, which is why real systems compute business days themselves.

</details>

3. **Strict birthday input.** Write `parseBirthday(string $input, DateTimeImmutable $today): DateTimeImmutable` for a
   form field in `d/m/Y` format. Reject invalid dates (`31/04/2000`), dates in the future, and people older than 130
   years, each with a clear message.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function parseBirthday(string $input, DateTimeImmutable $today): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!d/m/Y', trim($input));
    if ($date === false || DateTimeImmutable::getLastErrors() !== false) {
        throw new InvalidArgumentException("Please enter a date as day/month/year, e.g. 21/07/1990");
    }
    $today = $today->setTime(0, 0);
    if ($date > $today) {
        throw new InvalidArgumentException('A birthday cannot be in the future');
    }
    if ($date->diff($today)->y > 130) {
        throw new InvalidArgumentException('Please check the year');
    }

    return $date;
}

$today = new DateTimeImmutable('2026-10-11');
foreach (['21/07/1990', '31/04/2000', '01/01/2030', '01/01/1850', '1990-07-21'] as $input) {
    try {
        $result = parseBirthday($input, $today)->format('j F Y');
    } catch (InvalidArgumentException $e) {
        $result = $e->getMessage();
    }
    echo $input, ' => ', $result, "\n";
}
```

```text
21/07/1990 => 21 July 1990
31/04/2000 => Please enter a date as day/month/year, e.g. 21/07/1990
01/01/2030 => A birthday cannot be in the future
01/01/1850 => Please check the year
1990-07-21 => Please enter a date as day/month/year, e.g. 21/07/1990
```

`$today` is a parameter, not `new DateTimeImmutable()` inside the function, so the output is the same on every run and
the function is trivially testable. `->y` (whole years) is the right field here; `->days / 365` would be wrong around
leap years.

</details>

4. **A localized overdue notice.** Using `MessageFormatter`, write `overdueNotice(string $locale, string $name, int
   $days, int $fineCents): string` producing, for `en_US`, "Ada, your book is 1 day overdue. Fine: $0.25." and "...3
   days overdue. Fine: $0.75.", and for `de_DE`, "Ada, Ihr Buch ist 3 Tage überfällig. Gebühr: 0,75 $.". Keep both
   patterns in a catalog array.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function overdueNotice(string $locale, string $name, int $days, int $fineCents): string
{
    $patterns = [
        'en' => '{name}, your book is {days, plural, one {# day} other {# days}} overdue. Fine: {fine, number, currency}.',
        'de' => '{name}, Ihr Buch ist {days, plural, one {# Tag} other {# Tage}} überfällig. Gebühr: {fine, number, currency}.',
    ];
    $language = Locale::getPrimaryLanguage($locale);
    $pattern = $patterns[$language] ?? $patterns['en'];

    $formatter = new MessageFormatter($locale . '@currency=USD', $pattern);

    return $formatter->format(['name' => $name, 'days' => $days, 'fine' => $fineCents / 100]);
}

echo overdueNotice('en_US', 'Ada', 1, 25), "\n";
echo overdueNotice('en_US', 'Ada', 3, 75), "\n";
echo overdueNotice('de_DE', 'Ada', 3, 75), "\n";
```

```text
Ada, your book is 1 day overdue. Fine: $0.25.
Ada, your book is 3 days overdue. Fine: $0.75.
Ada, Ihr Buch ist 3 Tage überfällig. Gebühr: 0,75 $.
```

`{fine, number, currency}` uses the locale's default currency (euros for `de_DE`). The `@currency=USD` locale keyword
overrides it, so the amount stays in dollars, the library's currency, while the formatting follows the reader.
`Locale::getPrimaryLanguage('de_DE')` returns `de`, the catalog key.

</details>

5. **Reminders, tested with a frozen clock.** Write a class `ReminderPolicy` with a method `shouldRemind(Loan $loan,
   Clock $clock): bool` that is true exactly two days before the due date, and on every day the loan is overdue, but
   never for returned loans. Without any test framework, use `FrozenClock` and `assert()`-style checks to prove the
   behavior on day 18, 19, 20, 21, 22 of a 21-day loan, and after return.

<details>
<summary>Solution</summary>

Run from `examples/bookshelf/`:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\Book;
use Bookshelf\Domain\Isbn;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanStatus;
use Bookshelf\Domain\Member;
use Bookshelf\Support\Clock;
use Bookshelf\Support\FrozenClock;

final class ReminderPolicy
{
    public function shouldRemind(Loan $loan, Clock $clock): bool
    {
        $today = $clock->now()->setTime(0, 0);

        return match ($loan->status($today)) {
            LoanStatus::Returned => false,
            LoanStatus::Overdue => true,
            LoanStatus::Active => $today->modify('+2 days') == $loan->dueOn,
        };
    }
}

function check(bool $condition, string $message): void
{
    echo ($condition ? 'ok   ' : 'FAIL ') . $message . "\n";
}

$clock = FrozenClock::at('2026-10-11 09:00', 'Europe/Berlin');
$loan = Loan::start(
    new Book(1, 'Dune', 'Frank Herbert', new Isbn('978-0-441-01359-3')),
    new Member(1, 'Ada', 'ada@example.org'),
    $clock->now(),
);
$policy = new ReminderPolicy();

$expected = [18 => false, 19 => true, 20 => false, 21 => false, 22 => true];
foreach ($expected as $day => $remind) {
    $clock->set($loan->lentOn->modify("+$day days")->setTime(15, 0));
    check($policy->shouldRemind($loan, $clock) === $remind, "day $day: " . ($remind ? 'remind' : 'no reminder'));
}

$loan->markReturned($clock->now());
check($policy->shouldRemind($loan, $clock) === false, 'returned loan: no reminder');
```

```text
ok   day 18: no reminder
ok   day 19: remind
ok   day 20: no reminder
ok   day 21: no reminder
ok   day 22: remind
ok   returned loan: no reminder
```

The due date is day 21 (1 November), so the reminder goes out on day 19, and the loan is overdue from day 22. Setting
the clock to 15:00 checks that the time of day doesn't matter. `==` compares the two dates by instant, which is
correct here because both are midnight in the same zone. Chapter 20 turns checks like these into PHPUnit tests.

</details>

---
← Previous: [Files, streams and the command line](14-files-streams-and-the-cli.md) | Next: [PHP on the web](16-php-on-the-web.md) →
