# Chapter 21 — Performance and internals

## What you'll learn

- How to measure before optimizing: timing, memory, benchmarks that don't lie, and where a request's time really goes
- How PHP turns source code into opcodes and runs them on the Zend VM, and how to read an opcode dump
- How values live in memory: zvals, reference counting, interned strings and immutable arrays
- Copy-on-write, and why passing big arrays around is cheap until you write to them
- What arrays and objects cost in memory, and when that matters
- Garbage collection: when memory is freed at once, when the cycle collector is needed, and how long-running processes leak
- OPcache, preloading and the JIT: what each one does, how to configure it, and what it gains
- Faster autoloading, profiling tools, and a checklist of the changes that pay off in real applications

Most PHP applications are fast enough without any tuning, because their time goes to waiting on databases and
networks rather than to PHP itself. When something *is* slow, the fix is almost always found by measuring, and
understanding how the engine works tells you what a measurement means. This chapter opens the hood. It isn't about
writing clever code; it's about knowing what your code costs, so you can spend effort where it counts and ignore the
myths.

## Measure first

### Time and memory

Two built-in functions cover most measurements:

- **`hrtime(true)`** returns a monotonic clock reading in nanoseconds, as an `int`. Unlike `microtime()`, it never
  jumps when the system clock is adjusted, so it's the right tool for durations.
- **`memory_get_usage()`** returns the bytes PHP's memory manager has handed out right now, and
  **`memory_get_peak_usage()`** the highest value so far (`memory_reset_peak_usage()`, PHP 8.2+, starts a new peak).
  Pass `true` to either to get the memory reserved from the operating system instead, which grows in 2 MB chunks.

A small benchmark helper that runs a piece of work several times and reports the median:

```php
<?php

declare(strict_types=1);

/**
 * Runs $work $runs times and returns the median duration in milliseconds.
 * The median ignores the occasional slow run caused by something else on the machine.
 */
function bench(callable $work, int $runs = 7): float
{
    $work();                                         // warm-up: fill caches, trigger lazy setup
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $work();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return $times[intdiv($runs, 2)];
}

$isbns = array_map(fn (int $i): string => (string) (9780000000000 + $i * 7), range(1, 10_000));
$lookups = array_map(fn (int $i): string => (string) (9780000000000 + $i * 5), range(1, 10_000));
$index = array_flip($isbns);

printf("in_array: %8.2f ms\n", bench(function () use ($isbns, $lookups) {
    $found = 0;
    foreach ($lookups as $isbn) {
        $found += in_array($isbn, $isbns, true) ? 1 : 0;
    }
    return $found;
}));
printf("isset:    %8.2f ms\n", bench(function () use ($index, $lookups) {
    $found = 0;
    foreach ($lookups as $isbn) {
        $found += isset($index[$isbn]) ? 1 : 0;
    }
    return $found;
}));
```

```text
in_array:   453.30 ms
isset:        0.34 ms
```

(Your numbers will differ; the ratio is what matters.) Ten thousand lookups in a list of ten thousand ISBNs: `in_array()`
compares against every element until it finds a match, so the work grows with the list (O(n) per lookup).
`isset()` on an array whose *keys* are the ISBNs is a hash lookup, which takes the same time however big the array is
(O(1)). That's a thousandfold difference from choosing the right data structure, and no amount of micro-tuning
`in_array()` would get close. Algorithmic wins like this one are the only "micro" optimizations that reliably matter.

Benchmarks are easy to get wrong:

- **Run with the configuration you deploy.** OPcache is disabled for the CLI by default (`opcache.enable_cli=0`), so
  a CLI benchmark compiles every file on every run, while production doesn't. Use `php -d opcache.enable_cli=1`.
- **Warm up, repeat, take the median.** The first run pays for autoloading and cache misses; later runs vary with
  whatever else the machine is doing.
- **Use realistic data.** Ten elements fit in the CPU cache and hide the difference between O(n) and O(1).
- **Make the result observable.** Work whose result is never used may be simplified away by the optimizer. Return
  or print something derived from it.
- **Measure the real thing afterwards.** A benchmark shows that a change *can* help. Whether it helps your
  application is answered by measuring the application.

### Where a request's time goes

A typical request to a PHP application, roughly:

```text
├─ PHP: route, build objects, render ─┤├──────── database queries (network + server) ────────┤├─ PHP ─┤
         ~1–5 ms                                    ~5–50 ms                                     ~1 ms
```

The PHP part is usually a small fraction. Measured on Bookshelf, building the router from controller attributes
(chapter 19) takes about 0.1 ms per request. Caching the routes would make the code more complex and save nothing a
user could notice. On the other hand, a page that runs one query per book in a list, the "N+1 queries" problem from
chapter 17, pays a network round trip per book: at 0.5 ms each, a 100-book page spends 50 ms just waiting. One query
with `WHERE id IN (...)`, as `BookRepository::findByIds()` does, pays one round trip.

So the order of investigation is: **queries first** (count them, and check that the slow ones use indexes), **then
external calls** (HTTP APIs, file systems), **then PHP**. The profilers at the end of this chapter show all three.

## From source code to execution

Each time PHP runs a file, it goes through these stages:

```text
 source code ──▶ lexer ──▶ tokens ──▶ parser ──▶ AST ──▶ compiler ──▶ opcodes ──▶ Zend VM executes them
  fine.php                                                  │                          ▲
                                                            └── OPcache: optimize and ──┘
                                                                keep in shared memory
```

1. The **lexer** splits the source into tokens. PHP exposes it as `PhpToken::tokenize()`:

   ```php
   <?php

   declare(strict_types=1);

   foreach (PhpToken::tokenize('<?php return $days * 25;') as $token) {
       printf("%-26s %s\n", $token->getTokenName(), var_export($token->text, true));
   }
   ```

   ```text
   T_OPEN_TAG                 '<?php '
   T_RETURN                   'return'
   T_WHITESPACE               ' '
   T_VARIABLE                 '$days'
   T_WHITESPACE               ' '
   *                          '*'
   T_WHITESPACE               ' '
   T_LNUMBER                  '25'
   ;                          ';'
   ```

   Tools like PHP-CS-Fixer and PHPStan start from this token stream or from the AST built from it.
2. The **parser** builds an abstract syntax tree (AST), the program's structure: "return the product of `$days`
   and `25`". Syntax errors are found here, before anything runs, which is why a parse error anywhere in a file stops
   the whole file.
3. The **compiler** walks the AST and emits **opcodes**: simple instructions for the Zend virtual machine, grouped
   into one *op array* per function plus one for the file's top-level code.
4. The **Zend VM** executes the opcodes one after another. Each opcode has a handler, a small C function that does
   the work (add two values, call a function, fetch an array element).

### Reading opcodes

OPcache can print the opcodes it produced. Take a small function:

```php
<?php

declare(strict_types=1);

function fine(int $daysLate, int $perDay = 25, int $cap = 1000): int
{
    if ($daysLate <= 0) {
        return 0;
    }

    return min($daysLate * $perDay, $cap);
}

echo fine(3), "\n";
```

```bash
php -d opcache.enable_cli=1 -d opcache.opt_debug_level=0x20000 fine.php
```

```text
fine:
     ; (lines=10, args=3, vars=3, tmps=2)
     ; (after optimizer)
     ; fine.php:5-12
0000 CV0($daysLate) = RECV 1
0001 CV1($perDay) = RECV_INIT 2 int(25)
0002 CV2($cap) = RECV_INIT 3 int(1000)
0003 T3 = IS_SMALLER_OR_EQUAL CV0($daysLate) int(0)
0004 JMPZ T3 0006
0005 RETURN int(0)
0006 T4 = MUL CV0($daysLate) CV1($perDay)
0007 T3 = FRAMELESS_ICALL_2(min) T4 CV2($cap)
0008 VERIFY_RETURN_TYPE T3
0009 RETURN T3
```

(`0x20000` prints the opcodes after optimization; the output also includes the file's top-level code, left out here.)

- **`CV0($daysLate)`** is a *compiled variable*: a local variable, resolved to a numbered slot at compile time. Local
  variables are array slots, not name lookups, which is why they're fast. **`T3`, `T4`** are temporaries for
  intermediate results.
- **`RECV`** and **`RECV_INIT`** receive the arguments (with defaults), including the `int` checks.
- **`JMPZ T3 0006`** jumps to instruction 6 if the comparison was false: the `if`.
- **`FRAMELESS_ICALL_2(min)`** calls the internal function `min()` with two arguments without setting up a full call
  frame. PHP 8.4 introduced these "frameless" calls for a set of common internal functions.
- **`VERIFY_RETURN_TYPE`** checks the `int` return type. The optimizer removes it where it can prove the type, as in
  the next example.

The optimizer does real work. Since PHP 8.4, a `sprintf()` that uses only `%s` and `%d` is compiled into string
interpolation:

```php
function label(string $title, int $year): string
{
    return sprintf('%s (%d)', $title, $year);
}
```

```text
0000 CV0($title) = RECV 1
0001 CV1($year) = RECV 2
0002 T3 = ROPE_INIT 4 CV0($title)
0003 T3 = ROPE_ADD 1 T3 string(" (")
0004 T2 = CAST (long) CV1($year)
0005 T3 = ROPE_ADD 2 T3 T2
0006 T2 = ROPE_END 3 T3 string(")")
0007 RETURN T2
```

No function call is left: a *rope* collects the pieces and builds the result string once, at `ROPE_END`. You don't
need to read opcodes to write PHP. But when someone claims that one spelling is faster than another, the opcode dump
settles it in seconds (exercise 5).

PHP 8.5 also brought a faster VM dispatch mode: when PHP is compiled with Clang 19 or later on x86-64 or ARM64, the
"tail-call" VM is used, and the default VM got faster with other compilers too. You get these improvements just by
upgrading, which is a recurring theme: each PHP release makes the same code a little faster.

## Values in memory: zvals

Every PHP value is stored in a **zval**, a 16-byte structure: 8 bytes for the value itself and 8 bytes for the type
and some flags. Small values fit inside it; larger ones live elsewhere and the zval points to them:

```text
 zval (16 bytes)                          heap
┌──────────────┬───────────┐
│ 42           │ int       │             (no allocation: the value is inline)
├──────────────┼───────────┤
│ 3.14         │ float     │
├──────────────┼───────────┤      ┌──────────────────────────────────────┐
│ pointer ─────┼───────────┼────▶ │ zend_string: refcount 2, length 4,   │
│              │ string    │      │ hash, "Dune"                          │
├──────────────┼───────────┤      └──────────────────────────────────────┘
│ pointer ─────┼───────────┼────▶ zend_array:  refcount, table of zvals ...
│              │ array     │
├──────────────┼───────────┤
│ pointer ─────┼───────────┼────▶ zend_object: refcount, class, property slots ...
│              │ object    │
└──────────────┴───────────┘
```

- `null`, booleans, integers and floats are stored **inline**: copying them copies 16 bytes, and nothing is ever
  allocated or freed for them.
- Strings, arrays and objects are **refcounted**: the structure on the heap counts how many zvals point at it. When the
  count drops to zero, the memory is freed at that moment.

`debug_zval_dump()` shows the type and the reference count. Strings that come from the source code are
**interned**: stored once, shared by everyone, never counted and never freed during the request:

```php
<?php
$s = 'Dune';
debug_zval_dump($s);
$t = str_repeat('ab', 3);
debug_zval_dump($t);
$u = $t;
debug_zval_dump($t);
```

```text
string(4) "Dune" interned
string(6) "ababab" refcount(2)
string(6) "ababab" refcount(3)
```

The counts are one higher than you might expect, because passing the value to `debug_zval_dump()` adds a reference of
its own. `$t` alone gives 2; after `$u = $t`, the same string has 3. Nothing was copied.

## Copy-on-write

PHP arrays and strings behave as *values*: assigning or passing one gives the receiver its own independent copy, as
far as anyone can observe. Actually copying on every assignment would be slow, so PHP shares the structure and copies
only at the moment someone **writes** to a shared one. This is **copy-on-write**:

```php
<?php

declare(strict_types=1);

$a = range(1, 3);          // built at run time, so it's a refcounted heap array
debug_zval_dump($a);

$b = $a;                   // no copy: both variables point at the same array
debug_zval_dump($a);

$b[] = 4;                  // the write separates $b: now it gets its own copy
debug_zval_dump($a);
debug_zval_dump($b);

$literal = [1, 2, 3];      // a compile-time constant array is immutable and shared
debug_zval_dump($literal);
```

```bash
php -d opcache.enable_cli=1 cow.php
```

```text
array(3) packed refcount(2){
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(3) packed refcount(3){
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(3) packed refcount(2){
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(4) packed refcount(2){
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
  [3]=>
  int(4)
}
array(3) packed interned {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
```

After `$b = $a`, one array has two owners. The write to `$b` sees a refcount above 1, so it copies the array first
and modifies the copy (*separation*). `$a` is untouched. The array literal is marked **immutable** by OPcache: it lives
in shared memory, every request uses the same one, and assigning it never even changes a counter. (Without OPcache it
would be an ordinary refcounted array.) "packed" is the array's storage layout, explained in the next section.

The practical consequence: **passing a large array to a function is cheap, as long as the function only reads it.**

```php
<?php

declare(strict_types=1);

function readOnly(array $books): int
{
    return count($books);
}

function writes(array $books): int
{
    $books[] = 0;        // the first write copies the whole array

    return count($books);
}

$books = range(1, 1_000_000);
$base = memory_get_usage();

readOnly($books);
printf("reading a 1,000,000-element parameter: peak +%s MB\n", number_format((memory_get_peak_usage() - $base) / 1e6, 1));

memory_reset_peak_usage();
writes($books);
printf("writing to it:                         peak +%s MB\n", number_format((memory_get_peak_usage() - $base) / 1e6, 1));
```

```text
reading a 1,000,000-element parameter: peak +0.0 MB
writing to it:                         peak +16.8 MB
```

Three conclusions follow:

- **Don't pass arrays by reference "for speed".** `function f(array &$books)` avoids nothing when `f` only reads, and
  it makes the code harder to follow. Use `&` only when the function's job is to modify the caller's variable.
- **References can make things slower.** A variable that is a reference can't share its value with a non-reference
  variable, so `$copy = $ref` has to copy at once. References also disable some optimizations.
- **Objects are not copied at all.** An object variable holds a *handle*. `$b = $a` makes both refer to the same object
  (refcount 2); a change through `$b` is visible through `$a`. Copy-on-write doesn't apply, because nothing is ever
  separated. You get a second object only with `clone`.

## What arrays and objects cost

A PHP array is an ordered hash table (chapter 5), with two internal layouts:

- **Packed**: when the keys are 0, 1, 2, … in increasing order, PHP stores just the values, 16 bytes each (one zval),
  with no keys or hashes. That's what `debug_zval_dump()` called "packed".
- **Hash**: any other keys. Each element takes a 32-byte *bucket* (the zval, the key's hash and a pointer to the key
  string), plus an 8-byte slot in the index that maps hashes to buckets.

In both layouts the capacity grows in powers of two, so an array of 100,000 elements reserves room for 131,072.
Measured with this script on 64-bit Linux:

```php
<?php

declare(strict_types=1);

function measure(string $label, callable $build): void
{
    gc_collect_cycles();
    $before = memory_get_usage();
    $value = $build();
    printf("%-34s %10s bytes\n", $label, number_format(memory_get_usage() - $before));
}

$n = 100_000;
measure('list of 100,000 ints', fn () => range(1, $n));
measure('same ints, keys not in order', fn () => array_flip(array_reverse(range(1, $n))));
measure('string keys => ints', function () use ($n) {
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a["book-$i"] = $i;
    }
    return $a;
});
measure('SplFixedArray of 100,000 ints', function () use ($n) {
    $a = new SplFixedArray($n);
    for ($i = 0; $i < $n; $i++) {
        $a[$i] = $i;
    }
    return $a;
});
```

```text
list of 100,000 ints                2,101,328 bytes
same ints, keys not in order        5,242,960 bytes
string keys => ints                 9,242,160 bytes
SplFixedArray of 100,000 ints       1,601,632 bytes
```

- The list: 131,072 × 16 bytes ≈ 2.1 MB.
- The same integers with keys out of order: 131,072 × (32 + 8) bytes ≈ 5.2 MB, because the array had to switch
  to the hash layout.
- String keys add the key strings themselves: each `"book-12345"` is a separate 40-byte allocation (a 24-byte
  header, the bytes, a terminating NUL byte, rounded up to a multiple of 8).
- `SplFixedArray` has exactly the size you asked for, with no spare capacity: 100,000 × 16 bytes.

Objects with **declared properties** are compact: the object has a fixed header and one 16-byte slot per property,
in an order known at compile time. An associative array with the same three fields is a hash table with buckets and
spare capacity:

```php
<?php

declare(strict_types=1);

final readonly class BookRow
{
    public function __construct(public int $id, public string $title, public int $year) {}
}

function measure(string $label, callable $build): void
{
    $before = memory_get_usage();
    $rows = $build();
    printf("%-26s %12s bytes\n", $label, number_format(memory_get_usage() - $before));
}

$title = 'Dune';   // one shared string, so only the containers are measured
measure('100,000 arrays', function () use ($title) {
    $rows = [];
    for ($i = 0; $i < 100_000; $i++) {
        $rows[] = ['id' => $i, 'title' => $title, 'year' => 1965];
    }
    return $rows;
});
measure('100,000 objects', function () use ($title) {
    $rows = [];
    for ($i = 0; $i < 100_000; $i++) {
        $rows[] = new BookRow($i, $title, 1965);
    }
    return $rows;
});
```

```text
100,000 arrays               39,701,328 bytes
100,000 objects              12,741,712 bytes
```

The objects use about a third of the memory, and property access by name is resolved to a slot once and cached, so it's
fast too. Both are reasons to turn rows into small readonly classes, beyond the type safety. Dynamic properties
(assigning a property the class doesn't declare) put a hash table back into the object, and they're deprecated since
PHP 8.2 unless the class opts in with `#[AllowDynamicProperties]`.

Most of the time none of this matters. It starts to matter when one process holds hundreds of thousands of items:
imports, reports, caches, long-running workers. Then the choices are: stream instead of loading everything (generators,
chapter 13; unbuffered queries, chapter 17), use objects rather than arrays of arrays, and keep lists packed.

## Strings

A string is an immutable byte buffer with a length, a cached hash and a refcount. "Immutable" means that operations
create new strings, with one important exception. `$html .= $piece` on a string whose refcount is 1 extends it in
place, growing the buffer as needed, so building a large string in a loop is linear, not quadratic. Collecting pieces
in an array and calling `implode()` once is equally fine.

Two common myths:

- **Single versus double quotes.** `'Dune'` and `"Dune"` compile to the same interned constant. A double-quoted string
  with variables compiles to a concatenation or a rope. There's no runtime difference worth a second of thought; choose
  by readability (chapter 4).
- **`strlen()` is not a scan.** The length is stored, so `strlen()` is O(1), whatever the string's size. The same goes
  for `count()` on arrays.

## Garbage collection

Reference counting frees most memory the instant it becomes unreachable: the last variable pointing at an array goes
out of scope, the count drops to zero, the array is freed, and the counts of everything it contained drop in turn. This
is also why destructors in PHP run at a predictable moment.

Reference counting has one blind spot: **cycles**. Here a member has a list of loans, and each loan points back at its
member:

```php
<?php

declare(strict_types=1);

final class Member
{
    /** @var list<Loan> */
    public array $loans = [];

    public function __construct(public string $name) {}
}

final class Loan
{
    public function __construct(public Member $member) {}   // points back at its member: a cycle
}

function borrowSomething(): void
{
    $member = new Member('Ada');
    $member->loans[] = new Loan($member);
}   // $member goes out of scope, but the member and the loan still reference each other

$start = memory_get_usage();
for ($i = 0; $i < 100_000; $i++) {
    borrowSomething();
}
$status = gc_status();
printf("collector runs: %d, cycles collected: %d\n", $status['runs'], $status['collected']);
printf("memory still held: %s bytes\n", number_format(memory_get_usage() - $start));

gc_disable();
$start = memory_get_usage();
for ($i = 0; $i < 100_000; $i++) {
    borrowSomething();
}
printf("with the collector disabled: %s bytes held\n", number_format(memory_get_usage() - $start));
printf("gc_collect_cycles() freed %d objects\n", gc_collect_cycles());
printf("after collecting: %s bytes held\n", number_format(memory_get_usage() - $start));
```

```text
collector runs: 19, cycles collected: 285000
memory still held: 1,884,064 bytes
with the collector disabled: 37,166,104 bytes held
gc_collect_cycles() freed 315000 objects
after collecting: 206,104 bytes held
```

When `borrowSomething()` returns, the member is still referenced by the loan, and the loan by the member's array, so
neither count reaches zero. PHP's **cycle collector** handles this. Whenever a refcount is decreased but doesn't
reach zero, the value is recorded as a possible cycle root in a buffer. When the buffer fills up (10,000 roots at
first, adjusted automatically afterwards), the collector checks those roots, finds groups of values that are only
referenced by each other, and frees them. Each iteration here creates three values (member, loans array, loan), so
285,000 values were freed in 19 runs, and the last few thousand cycles were still waiting in the buffer. With the
collector disabled, all 100,000 cycles stay in memory until `gc_collect_cycles()` frees them (plus the ones left over
from the first loop).

In a normal web request none of this needs attention: whatever is left at the end of the request is freed when the
request ends (the shared-nothing model of chapter 16). It matters in **long-running processes**: queue workers,
daemons, and application servers that keep the application loaded between requests (FrankenPHP's worker mode,
RoadRunner, Swoole; chapter 22). There, two things leak:

- **Data that grows without bound**: a static cache, a list of every event processed, a logger buffering messages.
  These aren't cycles, so no collector helps. Bound the size, or clear it per job.
- **Caches keyed by objects.** A cache that holds the object as a key keeps it alive forever. A
  `WeakMap` (PHP 8.0+) holds its keys *weakly*: when the object is otherwise unreachable, its entry disappears
  (exercise 4). `WeakReference` does the same for a single object.

The standard defense for workers is also the simplest: restart the process after a fixed number of jobs, or when
`memory_get_usage()` passes a limit. PHP-FPM does the same for web workers with `pm.max_requests`.

### The memory limit

`memory_limit` (default `128M`) caps what one request or script may allocate. Exceeding it is a fatal error:
"Allowed memory size of 134217728 bytes exhausted". Raising it hides the problem rather than solving it. When an import
needs 2 GB, it should stream instead. PHP 8.5 adds `max_memory_limit`, a startup-only setting that caps the value code
may set with `ini_set('memory_limit', …)`, so a library can't quietly lift the limit to `-1` (unlimited).

## OPcache

Without a cache, every request repeats the work of the previous section for every file it includes: read the file,
tokenize, parse, compile. For a framework application that's hundreds of files per request. **OPcache** keeps the
compiled op arrays in **shared memory**, where every PHP-FPM worker process uses them:

```text
                 first request for a file                  later requests, any worker
 index.php ──▶ compile ──▶ optimize ──▶ store ─┐        ┌──▶ run the cached opcodes
                                               ▼        │
                              ┌──────────────────────────────────┐
                              │ OPcache shared memory            │
                              │ opcodes, interned strings,       │
                              │ immutable arrays, class tables   │
                              └──────────────────────────────────┘
```

Besides skipping compilation, OPcache runs the optimizer (dead code removal, constant folding, the `sprintf()` rewrite
above) and makes strings and constant arrays immutable and shared, which saves memory in every worker. It's the
single most important performance setting in PHP. Since PHP 8.5, OPcache is always compiled into PHP and always
loaded; you only decide whether it's enabled. (Older versions needed `zend_extension=opcache` in `php.ini`; on 8.5 that
line now causes a warning.)

The settings that matter, with PHP's defaults and typical production values:

| Setting | Default | Production | Meaning |
|---|---|---|---|
| `opcache.enable` | `1` | `1` | OPcache for web SAPIs (FPM, Apache module) |
| `opcache.enable_cli` | `0` | `0` (or `1` for long CLI jobs) | OPcache for the CLI; each CLI run has its own memory, so it rarely helps short scripts |
| `opcache.memory_consumption` | `128` | `128`–`256` | Shared memory in MB for opcodes |
| `opcache.interned_strings_buffer` | `8` | `16`–`32` | MB for interned strings, shared by all workers |
| `opcache.max_accelerated_files` | `10000` | `20000`+ | Max number of cached scripts; must exceed your file count, `vendor/` included |
| `opcache.validate_timestamps` | `1` | `0` | Check files for changes? |
| `opcache.revalidate_freq` | `2` | (unused when the above is `0`) | Seconds between those checks |
| `opcache.save_comments` | `1` | `1` | Keep doc comments; libraries that read PHPDoc at run time need them |

`validate_timestamps=0` is the important production change: PHP never checks whether a file changed, which saves a
`stat()` per file per request. It also means **new code isn't picked up until PHP-FPM is reloaded**, which a
deployment must do anyway (chapter 22). In development keep the default, or code changes appear only after a restart.

`opcache_get_status()` reports how the cache is doing. The two numbers to watch are free memory and the hit rate.
Run this as `php -d opcache.enable_cli=1 status.php` (with OPcache disabled, the function returns `false`):

```php
<?php

$status = opcache_get_status(false);
print_r([
    'memory_usage' => $status['memory_usage'],
    'opcache_statistics' => array_intersect_key(
        $status['opcache_statistics'],
        array_flip(['num_cached_scripts', 'hits', 'misses', 'opcache_hit_rate']),
    ),
]);
```

```text
Array
(
    [memory_usage] => Array
        (
            [used_memory] => 8843264
            [free_memory] => 125374464
            [wasted_memory] => 0
            [current_wasted_percentage] => 0
        )

    [opcache_statistics] => Array
        (
            [num_cached_scripts] => 0
            [hits] => 0
            [misses] => 1
            [opcache_hit_rate] => 0
        )

)
```

That's a fresh CLI process, so nothing has been cached yet. On a warmed-up production server the hit rate should be
close to 100. If the cache fills up, OPcache stops caching new files, and once enough memory is wasted by replaced
scripts, it restarts the whole cache; both show up as sudden slowdowns. Expose these numbers through your monitoring
(on an internal endpoint, never a public one).

## Preloading

Even with OPcache, each request still has to *link* the classes it uses: look them up through the autoloader, check
parents and interfaces, and build the runtime class table. **Preloading** (PHP 7.4+) does that once, when PHP-FPM
starts. A preload script loads classes, and they stay loaded and linked in shared memory for every request until the
server restarts. Bookshelf's preload script is `config/preload.php`:

```php
<?php

declare(strict_types=1);

// Run once by OPcache when PHP-FPM starts, if php.ini sets opcache.preload to this file (chapter 22).
// Every class in src/ is compiled and linked into shared memory, so requests never load or link them again.
// Requiring through the autoloader means parent classes and interfaces are always loaded before their children.

require dirname(__DIR__) . '/vendor/autoload.php';

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(dirname(__DIR__) . '/src', FilesystemIterator::SKIP_DOTS),
);

foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        require_once $file->getPathname();
    }
}
```

It's enabled in `php.ini`:

```ini
opcache.preload=/srv/bookshelf/config/preload.php
opcache.preload_user=www-data      ; required when PHP starts as root; preloading never runs as root
```

To check that it worked, `opcache_get_status()` has a `preload_statistics` section:

```php
<?php

$preload = opcache_get_status(false)['preload_statistics'] ?? null;
if ($preload === null) {
    exit("Nothing was preloaded.\n");
}
printf(
    "classes: %d, functions: %d, files: %d, shared memory: %s bytes\n",
    count($preload['classes']),
    count($preload['functions']),
    count($preload['scripts']),
    number_format($preload['memory_consumption']),
);
var_dump(class_exists(Bookshelf\Domain\Isbn::class, autoload: false));
```

With Bookshelf installed for production (`composer install --no-dev`):

```text
classes: 72, functions: 1, files: 75, shared memory: 437,936 bytes
bool(true)
```

`class_exists(..., autoload: false)` returning `true` before any code asked for the class proves it was preloaded.
The one function is `e()`, the escaping helper from chapter 19.

Trade-offs to know:

- **Changing a preloaded file requires a full restart** of PHP-FPM, not just a reload of the cache.
- **Preloading isn't available on Windows.**
- **The gain depends on the application.** It's largest for frameworks with many classes per request, often in the
  range of a few percent to around 10% of PHP time; for a small app like Bookshelf it's negligible. Measure before and
  after.
- Preloading too much (all of `vendor/`) wastes memory and can fail on classes whose dependencies aren't installed.
  Frameworks generate tuned preload scripts.

## The JIT

The Zend VM *interprets* opcodes: for each instruction, it runs a generic handler. The **JIT** (just-in-time compiler,
PHP 8.0+) compiles hot code paths further, into machine code for your CPU, so loops of arithmetic run without the
interpreter's overhead. It's part of OPcache and needs it enabled.

```ini
opcache.jit=tracing            ; or "function"; "disable" turns it off (the default since PHP 8.4)
opcache.jit_buffer_size=64M    ; memory for generated machine code (default 64M since PHP 8.4)
```

- **`tracing`** watches which paths actually run and compiles hot loops and functions with the types observed at run
  time. It's the mode to try first.
- **`function`** compiles whole functions when they're first called, without run-time type information.

The JIT shines on CPU-bound code. Counting the points of a Mandelbrot set is nothing but floating-point arithmetic in
nested loops:

```php
<?php

declare(strict_types=1);

// CPU-bound work: count the points of a 600×600 grid that belong to the Mandelbrot set.
function mandelbrot(int $size, int $maxIterations): int
{
    $inside = 0;
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $cr = $x / $size * 3.0 - 2.0;
            $ci = $y / $size * 3.0 - 1.5;
            $zr = $zi = 0.0;
            $i = 0;
            while ($i < $maxIterations && $zr * $zr + $zi * $zi < 4.0) {
                $next = $zr * $zr - $zi * $zi + $cr;
                $zi = 2.0 * $zr * $zi + $ci;
                $zr = $next;
                $i++;
            }
            if ($i === $maxIterations) {
                $inside++;
            }
        }
    }

    return $inside;
}

$start = hrtime(true);
$inside = mandelbrot(600, 200);
$ms = (hrtime(true) - $start) / 1e6;

$jit = function_exists('opcache_get_status') ? (opcache_get_status(false)['jit']['on'] ?? false) : false;
printf("%d points inside, %.0f ms, JIT %s\n", $inside, $ms, $jit ? 'on' : 'off');
```

```bash
php -d opcache.enable_cli=1 mandelbrot.php
php -d opcache.enable_cli=1 -d opcache.jit=tracing mandelbrot.php
```

```text
61108 points inside, 245 ms, JIT off
61108 points inside, 63 ms, JIT on
```

Four times faster. Now a workload shaped like a web request: insert 20,000 rows into SQLite, read them back sorted, and
build HTML:

```php
<?php

declare(strict_types=1);

// Typical web-app work: database queries and string building, little pure computation.
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER)');
$insert = $pdo->prepare('INSERT INTO books (title, year) VALUES (?, ?)');
$start = hrtime(true);
$pdo->beginTransaction();
for ($i = 0; $i < 20_000; $i++) {
    $insert->execute(["Book $i", 1900 + $i % 120]);
}
$pdo->commit();
$html = '';
foreach ($pdo->query('SELECT id, title, year FROM books ORDER BY year, title') as $row) {
    $html .= '<li>' . htmlspecialchars($row['title']) . ' (' . $row['year'] . ")</li>\n";
}
$jit = opcache_get_status(false)['jit']['on'] ?? false;
printf("%d bytes of HTML, %.0f ms, JIT %s\n", strlen($html), (hrtime(true) - $start) / 1e6, $jit ? 'on' : 'off');
```

```text
528890 bytes of HTML, 32 ms, JIT off
528890 bytes of HTML, 31 ms, JIT on
```

No difference: the time is spent inside SQLite and inside internal functions like `htmlspecialchars()`, which are
already machine code. That's the typical picture for web applications, which is why the JIT is off by default. Turn
it on when profiling shows PHP code itself is the bottleneck: image or audio processing in pure PHP, parsers,
simulations, number crunching. And measure, because the JIT also costs memory and occasionally exposes engine bugs
that the interpreter doesn't have.

## Faster autoloading

The PSR-4 autoloader (chapter 12) turns a class name into a file path and checks whether the file exists, for each
class, on each request. In production, Composer can generate a **class map** instead, a single array from class name
to file:

```bash
composer install --no-dev --classmap-authoritative
# or, on an existing install:
composer dump-autoload --no-dev --classmap-authoritative
```

```text
Generating optimized autoload files (authoritative)
Generated optimized autoload files (authoritative) containing 70 classes
```

| Option | Effect |
|---|---|
| `--optimize` (`-o`) | Builds a class map of all PSR-4/PSR-0 classes; unknown classes still fall back to the file-system rules |
| `--classmap-authoritative` (`-a`) | Class map only: a class not in the map doesn't exist, so no file-system checks at all. Implies `-o` |
| `--apcu` | Caches lookups in APCu, for when an authoritative map isn't possible (classes generated at run time) |

With `-a`, a class added after the dump isn't found until the next dump, so it belongs in the build step, never in
development. PHP also caches the results of path resolution in the **realpath cache** (`realpath_cache_size`, 4 MB by
default since PHP 7.3, and `realpath_cache_ttl`, 120 seconds), which the defaults handle well.

## Profiling

A benchmark answers "how long does this take?". A **profiler** answers "where does the time go?": it records every
function call (or samples the call stack many times per second) and reports time per function, as a table or a flame
graph. Two numbers per function matter:

- **Inclusive time**: time in the function *and everything it called*. Big for `Kernel::handle()`, which is expected.
- **Exclusive (self) time**: time in the function's own code. A function with high self time is where the work
  actually happens.

The tools:

| Tool | Kind | Notes |
|---|---|---|
| Xdebug profiler | Records every call | `xdebug.mode=profile`; writes `cachegrind.out.<pid>` files; view in KCachegrind, QCachegrind or PhpStorm. Slows the code a lot, so absolute times are inflated |
| SPX | Records calls, built-in web UI | Open-source extension; low setup cost for development |
| Excimer | Sampling | Low overhead, so usable in production; outputs flame graphs. Made by Wikimedia |
| Blackfire, Tideways, and other APMs | Commercial, production profiling and monitoring | Profile real traffic; show SQL and HTTP calls alongside PHP functions |

With Xdebug installed (chapter 20), profiling one command looks like this:

```bash
XDEBUG_MODE=profile XDEBUG_TRIGGER=1 php bin/bookshelf import data/books.csv
ls /tmp/cachegrind.out.*          # xdebug.output_dir, /tmp by default
```

In production, even a profiler isn't the first tool. Log the duration of every request and every slow query
(`log_min_duration_statement` in PostgreSQL, the slow query log in MySQL), and look at the slowest endpoints first.
The `Server-Timing` response header (an exercise in chapter 19) shows per-request timings directly in the browser's
developer tools.

## A checklist that pays off

In rough order of payoff for a typical PHP web application:

1. **OPcache enabled, sized, `validate_timestamps=0`** in production, with a reload on deploy.
2. **The database.** Count queries per page (remove N+1 patterns), add indexes for the slow ones (`EXPLAIN`, chapter
   17), select only the columns you need, and paginate.
3. **Caching.** HTTP caching (`Cache-Control`, `ETag`) for pages that don't change per user. A data cache for
   expensive results: APCu (shared memory, one server) or Redis/Memcached (shared between servers).
4. **Composer's authoritative class map**, built at deploy time.
5. **Less work per request.** Move slow side effects (emails, image resizing, calls to third-party APIs) to a queue
   processed by a background worker (chapter 22). Under PHP-FPM, `fastcgi_finish_request()` sends the response to the
   client and then lets the script continue with cleanup work.
6. **A current PHP version.** Every release brings engine improvements for free.
7. **Preloading and the JIT**, when measurements show they help your application.

And some things that don't help, despite their reputation:

| Myth | Reality |
|---|---|
| "Single quotes are faster than double quotes" | Same opcodes for literal strings; no measurable difference |
| "Pass arrays by reference to avoid copying" | Copy-on-write already avoids the copy; references can force one |
| "`count()` in a `for` condition is slow" | `count()` is O(1); it's one `COUNT` opcode per iteration |
| "`isset()` is faster than `array_key_exists()`, so always use it" | It is slightly faster, but they mean different things for `null` values (chapter 5). Pick by meaning |
| "Unset variables at the end of functions to free memory" | Locals are released when the function returns anyway |
| "Objects are slower than arrays" | Objects with declared properties use less memory and access properties just as fast |

## Key takeaways

- Measure before you optimize, with OPcache on, realistic data, warm-up and repeated runs. Prefer `hrtime(true)` for
  durations and `memory_get_peak_usage()` for memory.
- Most request time is spent waiting on databases and networks. Count queries and fix N+1 patterns before touching PHP
  code. Choosing the right data structure (a hash lookup instead of a scan) beats any micro-optimization.
- PHP compiles source to opcodes and interprets them on the Zend VM. `opcache.opt_debug_level=0x20000` shows the
  opcodes, which settles "which is faster" debates.
- A zval is 16 bytes. Scalars live inside it; strings, arrays and objects are refcounted and freed when the count hits
  zero. Literal strings are interned and constant arrays immutable.
- Arrays and strings are copy-on-write: passing them is cheap, and the first write to a shared one copies it. Don't use
  references for speed. Objects are handles and are never implicitly copied.
- Packed arrays (keys 0, 1, 2…) cost 16 bytes per element, hash arrays about 40 plus keys. Objects with declared
  properties are far smaller than equivalent associative arrays.
- The cycle collector frees reference cycles in batches. Long-running processes leak through unbounded caches and
  object-keyed maps: use `WeakMap`, bound caches, and restart workers periodically.
- OPcache is the most important performance setting; PHP 8.5 always includes it. In production, disable timestamp
  validation and reload PHP-FPM on deploy.
- Preloading links classes once at startup; changes need a full restart. The JIT speeds up CPU-bound PHP code several
  times but rarely helps I/O-bound web requests; it's off by default.
- Build an authoritative Composer class map at deploy time. Use a profiler (Xdebug, SPX, Excimer, an APM) to find
  where time goes, and look at exclusive time.

## Exercises

1. **Predict the reference counts.** Without running it, predict the refcount `debug_zval_dump()` prints for `$fines`
   and `$more`, and whether any array was copied, and when. Then run it with `php -d opcache.enable_cli=1` and explain
   any difference.

   ```php
   <?php

   declare(strict_types=1);

   function total(array $fines): int
   {
       return array_sum($fines);
   }

   function withFine(array $fines, int $cents): array
   {
       $fines[] = $cents;

       return $fines;
   }

   $fines = range(25, 75, 25);      // [25, 50, 75], built at run time
   $copy = $fines;
   echo total($fines), "\n";
   $more = withFine($fines, 100);

   debug_zval_dump($fines);
   debug_zval_dump($more);
   ```

<details>
<summary>Solution</summary>

```text
150
array(3) packed refcount(3){
  [0]=>
  int(25)
  [1]=>
  int(50)
  [2]=>
  int(75)
}
array(4) packed refcount(2){
  [0]=>
  int(25)
  [1]=>
  int(50)
  [2]=>
  int(75)
  [3]=>
  int(100)
}
```

`$fines` and `$copy` share one array (2 references), and `debug_zval_dump()`'s own parameter adds the third.
`total()` only reads its parameter, so nothing is copied, and when it returns, its reference is released again. In
`withFine()`, the `$fines[] = $cents` line writes to an array with several owners, so that is the one moment a copy is
made. The copy, with four elements, is returned and owned only by `$more` (plus the dump's parameter, so 2). Exactly
one copy happened in the whole script.

</details>

2. **Rows as arrays or objects.** Bookshelf's repositories turn database rows into `Book` objects. Measure the memory
   of 100,000 rows held as associative arrays with five keys (`id`, `title`, `author`, `year`, `copies`) versus
   100,000 instances of a readonly class with five declared properties. Use one shared string for the title and author
   so that only the containers are measured. Explain the difference.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final readonly class BookRow
{
    public function __construct(
        public int $id,
        public string $title,
        public string $author,
        public int $year,
        public int $copies,
    ) {}
}

function measure(string $label, callable $build): void
{
    $before = memory_get_usage();
    $rows = $build();
    printf("%-18s %12s bytes, %4d per row\n", $label, number_format(memory_get_usage() - $before), (memory_get_usage() - $before) / 100_000);
}

$title = 'Dune';
$author = 'Frank Herbert';
measure('arrays', function () use ($title, $author) {
    $rows = [];
    for ($i = 0; $i < 100_000; $i++) {
        $rows[] = ['id' => $i, 'title' => $title, 'author' => $author, 'year' => 1965, 'copies' => 2];
    }
    return $rows;
});
measure('objects', function () use ($title, $author) {
    $rows = [];
    for ($i = 0; $i < 100_000; $i++) {
        $rows[] = new BookRow($i, $title, $author, 1965, 2);
    }
    return $rows;
});
```

On 64-bit Linux:

```text
arrays               39,701,328 bytes,  397 per row
objects              15,941,712 bytes,  159 per row
```

Each array is a separate hash table. Even with five elements it reserves 8 buckets (32 bytes each) plus its hash
index and a header, close to 400 bytes per row. The keys cost nothing extra: they're interned literal strings shared
by all rows. Each object is a fixed header plus one 16-byte slot per declared property, so two more properties than
the chapter's example cost exactly 32 bytes more per row (159 instead of 127). Both totals include the 16 bytes per
row of the outer list. A hash table never reserves fewer than 8 buckets, so arrays with one to eight keys all cost the
same, which is why three keys measured the same 39.7 MB earlier in the chapter.

</details>

3. **A faster duplicate check.** The CSV importer from chapter 14 must skip rows whose ISBN was already seen in the
   same file. A first version keeps a list and calls `in_array()`. Use the `bench()` helper from this chapter to compare
   it with a version that uses array keys, for 20,000 rows of which a quarter are duplicates. Explain the difference in
   terms of how the time grows with the file size.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function bench(callable $work, int $runs = 7): float
{
    $work();
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = hrtime(true);
        $work();
        $times[] = (hrtime(true) - $start) / 1e6;
    }
    sort($times);

    return $times[intdiv($runs, 2)];
}

// 20,000 rows; every fourth row repeats an earlier ISBN.
$rows = [];
for ($i = 0; $i < 20_000; $i++) {
    $rows[] = (string) (9780000000000 + ($i % 4 === 3 ? $i - 3 : $i));
}

$withList = function () use ($rows): int {
    $seen = [];
    foreach ($rows as $isbn) {
        if (!in_array($isbn, $seen, true)) {
            $seen[] = $isbn;
        }
    }
    return count($seen);
};

$withKeys = function () use ($rows): int {
    $seen = [];
    foreach ($rows as $isbn) {
        $seen[$isbn] = true;          // setting an existing key again is harmless
    }
    return count($seen);
};

printf("unique: %d / %d\n", $withList(), $withKeys());
printf("in_array: %8.2f ms\n", bench($withList));
printf("keys:     %8.2f ms\n", bench($withKeys));
```

```text
unique: 15000 / 15000
in_array:   725.29 ms
keys:         0.67 ms
```

(Exact times vary.) With a list, each row scans the ISBNs seen so far, so the total work grows with the *square* of
the row count: twice the rows, four times the time. With keys, each row is one hash operation, so the total grows
linearly. At 20,000 rows that's already a factor of a thousand; at 200,000 rows the list version would take over a
minute (ten times the rows, a hundred times the time), and the keyed version about 7 ms. One subtlety: numeric-string keys like `"9780000000000"` become integer keys
in a PHP array (chapter 5), which is fine for a set but means `array_keys($seen)` returns integers, not strings.

</details>

4. **Fix the leaking cache.** A long-running worker uses this cache to avoid recomputing slugs. Run it and observe the
   memory. Explain why it grows, then fix it with `WeakMap`. Finally, explain why keying a plain array by
   `spl_object_id($book)` would be a worse fix.

   ```php
   <?php

   declare(strict_types=1);

   final class Book
   {
       public function __construct(public string $title) {}
   }

   /** Remembers an expensive per-book value (here, a slug) for as long as the book object is alive. */
   final class SlugCache
   {
       /** @var SplObjectStorage<Book, string> */
       private SplObjectStorage $slugs;

       public function __construct()
       {
           $this->slugs = new SplObjectStorage();
       }

       public function slugOf(Book $book): string
       {
           if (!isset($this->slugs[$book])) {
               $this->slugs[$book] = strtolower(preg_replace('/\W+/', '-', $book->title) ?? '');
           }

           return $this->slugs[$book];
       }

       public function size(): int
       {
           return count($this->slugs);
       }
   }

   $cache = new SlugCache();
   $start = memory_get_usage();
   for ($i = 0; $i < 100_000; $i++) {
       $book = new Book("Book number $i");
       $cache->slugOf($book);
   }
   printf("entries: %d, memory held: %s bytes\n", $cache->size(), number_format(memory_get_usage() - $start));
   echo $cache->slugOf($book), "\n";
   ```

<details>
<summary>Solution</summary>

The original prints:

```text
entries: 100000, memory held: 20,726,064 bytes
book-number-99999
```

`SplObjectStorage` holds a normal (strong) reference to every object used as a key. The worker has long finished with
each `Book`, but the cache keeps all 100,000 of them alive, plus their slugs. A `WeakMap` holds its keys weakly: when
nothing else references the object, the entry is removed automatically:

```php
<?php

declare(strict_types=1);

final class Book
{
    public function __construct(public string $title) {}
}

/** Remembers an expensive per-book value (here, a slug) for as long as the book object is alive. */
final class SlugCache
{
    /** @var WeakMap<Book, string> */
    private WeakMap $slugs;

    public function __construct()
    {
        $this->slugs = new WeakMap();
    }

    public function slugOf(Book $book): string
    {
        return $this->slugs[$book] ??= strtolower(preg_replace('/\W+/', '-', $book->title) ?? '');
    }

    public function size(): int
    {
        return count($this->slugs);
    }
}

$cache = new SlugCache();
$start = memory_get_usage();
for ($i = 0; $i < 100_000; $i++) {
    $book = new Book("Book number $i");
    $cache->slugOf($book);
}
printf("entries: %d, memory held: %s bytes\n", $cache->size(), number_format(memory_get_usage() - $start));
echo $cache->slugOf($book), "\n";
```

```text
entries: 1, memory held: 1,144 bytes
book-number-99999
```

Only the book still referenced by `$book` has an entry. `spl_object_id()` would be worse because **object ids are
reused**: when an object is freed, the next new object can get its id. A cache keyed by id returns the *previous*
object's value:

```php
<?php

declare(strict_types=1);

final class Book
{
    public function __construct(public string $title) {}
}

/** @var array<int, string> $slugs keyed by spl_object_id() */
$slugs = [];
foreach (['Dune', 'Emma', 'Ubik'] as $title) {
    $book = new Book($title);
    $slugs[spl_object_id($book)] ??= strtolower($title);
    echo $title, ' -> ', $slugs[spl_object_id($book)], ' (object id ', spl_object_id($book), ")\n";
    unset($book);
}
```

```text
Dune -> dune (object id 1)
Emma -> dune (object id 1)
Ubik -> dune (object id 1)
```

That's not a leak but a correctness bug, which is worse. Ids are unique only among objects alive at the same time.

</details>

5. **Settle it with opcodes.** A colleague says `"Hello $name"` is slower than `'Hello ' . $name`, that `sprintf()` is
   slower than both, and that `count($items)` in a `for` condition should be hoisted out of the loop. Dump the
   optimized opcodes of the four functions below and decide each claim.

   ```php
   <?php

   declare(strict_types=1);

   function a(string $name): string
   {
       return "Hello $name";
   }

   function b(string $name): string
   {
       return 'Hello ' . $name;
   }

   function c(string $name): string
   {
       return sprintf('Hello %s', $name);
   }

   function d(array $items): int
   {
       $n = 0;
       for ($i = 0; $i < count($items); $i++) {
           $n += $items[$i];
       }

       return $n;
   }

   echo a('Ada'), b('Ada'), c('Ada'), d([1, 2]), "\n";
   ```

<details>
<summary>Solution</summary>

```bash
php -d opcache.enable_cli=1 -d opcache.opt_debug_level=0x20000 claims.php
```

```text
a:
0000 CV0($name) = RECV 1
0001 T1 = FAST_CONCAT string("Hello ") CV0($name)
0002 RETURN T1

b:
0000 CV0($name) = RECV 1
0001 T1 = FAST_CONCAT string("Hello ") CV0($name)
0002 RETURN T1

c:
0000 CV0($name) = RECV 1
0001 T1 = FAST_CONCAT string("Hello ") CV0($name)
0002 RETURN T1

d:
0000 CV0($items) = RECV 1
0001 CV1($n) = QM_ASSIGN int(0)
0002 CV2($i) = QM_ASSIGN int(0)
0003 JMP 0007
0004 T3 = FETCH_DIM_R CV0($items) CV2($i)
0005 ASSIGN_OP (ADD) CV1($n) T3
0006 PRE_INC CV2($i)
0007 T4 = COUNT CV0($items)
0008 T3 = IS_SMALLER CV2($i) T4
0009 JMPNZ T3 0004
0010 VERIFY_RETURN_TYPE CV1($n)
0011 RETURN CV1($n)
```

(The comment lines starting with `;` are left out.) `a`, `b` and `c` compile to the *identical* single `FAST_CONCAT`;
on PHP 8.4+ even the `sprintf()` call is gone. All three claims about strings are wrong; write whichever reads best.
In `d`, `count()` is not a function call at all but a `COUNT` opcode, which reads the stored element count: O(1).
Hoisting it saves one cheap instruction per iteration. It's still reasonable when the loop changes the array, or for
readability, but not for speed. (`foreach` would be clearer here anyway.)

</details>

6. **When does the JIT help?** Run the Mandelbrot script and the SQLite script from this chapter with the JIT off,
   with `opcache.jit=tracing` and with `opcache.jit=function`, five times each, and compare the medians. Then write one
   paragraph for your team on whether to enable the JIT for Bookshelf's PHP-FPM pool, and what you'd measure first.

<details>
<summary>Solution</summary>

```bash
for mode in disable tracing function; do
  for run in 1 2 3 4 5; do
    php -d opcache.enable_cli=1 -d opcache.jit=$mode mandelbrot.php
  done
done
```

Typical results (medians, one machine):

```text
Mandelbrot  JIT off:  ~245 ms   tracing: ~63 ms   function: ~63 ms
SQLite+HTML JIT off:   ~32 ms   tracing: ~31 ms   function: ~31 ms
```

For this tight numeric loop both modes perform similarly; with more varied code (many functions, types that change),
tracing usually does better because it compiles only the paths that run, using the types it observed.

A paragraph for the team: "Bookshelf's requests spend their time in SQLite queries, template rendering and internal
functions like `htmlspecialchars()`, all of which already run as machine code. The JIT can't speed those up, so we
expect no measurable gain, and it would cost 64 MB of memory per server plus a new class of possible engine bugs. We
keep it disabled. If we ever add CPU-heavy work in PHP (generating images, parsing large files), we'll profile that job,
and if PHP code dominates its exclusive time, we'll try `opcache.jit=tracing` for the worker that runs it, measured
against the same input."

</details>

---
← Previous: [Testing and static analysis](20-testing-and-static-analysis.md) | Next: [Deployment and production](22-deployment-and-production.md) →
