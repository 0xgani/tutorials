# Chapter 13 — Iterators, generators and fibers

## What you'll learn

- What `foreach` can iterate over, and the `Traversable`, `Iterator` and `IteratorAggregate` interfaces
- Building a typed collection class that works with `foreach` and `count()`
- Generators: `yield`, keys, `yield from`, return values, `send()`, and cleanup with `finally`
- Lazy pipelines that process millions of records in constant memory
- The SPL iterators and data structures: stacks, queues, heaps, `SplObjectStorage`, `WeakMap`
- Fibers: what they are, what they are not, and how async libraries use them
- Bookshelf's streaming CSV importer

## What `foreach` iterates

`foreach` works on arrays and on any object that implements **`Traversable`**. (It also walks the public properties of
plain objects, but that is rarely useful.) You cannot implement `Traversable` directly; you implement one of its two
children:

```text
Traversable
├── Iterator            you write the cursor yourself: current(), key(), next(), rewind(), valid()
└── IteratorAggregate   you return some other iterator from getIterator()   ← usually what you want
```

The `iterable` type accepts arrays and `Traversable` objects, so functions typed `iterable` work with both.

### `Iterator`: the full protocol

To see what `foreach` does under the hood, here is an iterator over a range of due dates:

```php
<?php

declare(strict_types=1);

/** @implements Iterator<int, string> */
final class DayRange implements Iterator
{
    private int $offset = 0;

    public function __construct(private DateTimeImmutable $start, private int $days)
    {
    }

    public function rewind(): void { $this->offset = 0; }
    public function valid(): bool { return $this->offset < $this->days; }
    public function current(): string { return $this->start->modify("+{$this->offset} days")->format('D j M'); }
    public function key(): int { return $this->offset; }
    public function next(): void { $this->offset++; }
}

foreach (new DayRange(new DateTimeImmutable('2026-10-12'), 3) as $i => $day) {
    echo "$i: $day\n";
}
```

```text
0: Mon 12 Oct
1: Tue 13 Oct
2: Wed 14 Oct
```

`foreach` calls `rewind()`, then repeatedly `valid()`, `current()`, `key()`, loop body, `next()`. Five methods and a
cursor for something this simple is a lot of ceremony, which is why you will mostly use the next two tools.

### `IteratorAggregate`: a typed collection

A very common need is a collection that holds only one type of object, so that code receiving it knows what is inside.
`IteratorAggregate` needs one method, `getIterator()`, which can simply return an `ArrayIterator` or a generator:

```php
<?php

declare(strict_types=1);

final readonly class Book
{
    public function __construct(public string $title, public int $year) {}
}

/** @implements IteratorAggregate<int, Book> */
final class BookList implements IteratorAggregate, Countable
{
    /** @var list<Book> */
    private array $books;

    public function __construct(Book ...$books)
    {
        $this->books = $books;   // the variadic type check guarantees every element is a Book
    }

    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->books);
    }

    public function count(): int
    {
        return count($this->books);
    }

    public function filter(callable $predicate): self
    {
        return new self(...array_filter($this->books, $predicate));
    }

    public function sortedByYear(): self
    {
        $books = $this->books;
        usort($books, fn (Book $a, Book $b): int => $a->year <=> $b->year);
        return new self(...$books);
    }
}

$books = new BookList(new Book('Dune', 1965), new Book('Emma', 1815), new Book('Kindred', 1979));

foreach ($books->filter(fn (Book $b): bool => $b->year > 1900)->sortedByYear() as $book) {
    echo "{$book->year} {$book->title}\n";
}
echo count($books), " books in total\n";
```

```text
1965 Dune
1979 Kindred
3 books in total
```

The `Book ...$books` variadic constructor is a neat trick: PHP checks the type of every argument, so a `BookList` can
only ever contain books. The `@implements IteratorAggregate<int, Book>` docblock tells static analysers what
`foreach` yields, so they know `$book` is a `Book`.

## Generators

A **generator** is a function that contains `yield`. Calling it does not run the body; it returns a `Generator`
object (which implements `Iterator`). Each time the loop asks for the next value, the function runs until the next
`yield`, hands that value out, and **pauses**, keeping all its local variables, until it is asked again.

```php
<?php

declare(strict_types=1);

function numbers(): Generator
{
    echo '[start] ';
    yield 1;
    echo '[after 1] ';
    yield 2;
    echo '[end] ';
    return 99;
}

$gen = numbers();
echo "created\n";               // nothing has run yet

foreach ($gen as $key => $value) {
    echo "$key=>$value ";
}
echo "\nreturn value: ", $gen->getReturn(), "\n";
```

```text
created
[start] 0=>1 [after 1] 1=>2 [end] 
return value: 99
```

- Without explicit keys, yielded values get keys `0, 1, 2, ...`. `yield $key => $value` sets a key.
- A generator's `return` value is not yielded; read it with `getReturn()` after the generator finishes.
- **Generators run once.** Iterating a finished generator throws `Exception: Cannot traverse an already closed
  generator`. To iterate again, call the function again. (This is the biggest practical difference from arrays.)
- The return type of a generator function is `Generator`, `Iterator`, `Traversable` or `iterable`.

Generators are the easy way to write iterators: the `DayRange` class above becomes three lines:

```php
function dayRange(DateTimeImmutable $start, int $days): Generator
{
    for ($i = 0; $i < $days; $i++) {
        yield $i => $start->modify("+$i days")->format('D j M');
    }
}
```

### Laziness: constant memory

The point of generators is that values are produced **on demand**. A generator that reads a file yields one line at a
time, so the whole file is never in memory. Here is a 200,000-line CSV file read eagerly into an array versus lazily
through a generator:

```php
<?php

declare(strict_types=1);

$path = __DIR__ . '/big.csv';
$out = fopen($path, 'w');
for ($i = 1; $i <= 200_000; $i++) {
    fputcsv($out, ["Title $i", 'Author ' . ($i % 997), 1900 + $i % 125], escape: '');
}
fclose($out);

function readAll(string $path): array
{
    $rows = [];
    $h = fopen($path, 'r');
    while (($row = fgetcsv($h, escape: '')) !== false) {
        $rows[] = $row;
    }
    fclose($h);
    return $rows;
}

function readLazily(string $path): Generator
{
    $h = fopen($path, 'r');
    try {
        while (($row = fgetcsv($h, escape: '')) !== false) {
            yield $row;
        }
    } finally {
        fclose($h);
    }
}

$mode = $argv[1] ?? 'lazy';
$rows = $mode === 'eager' ? readAll($path) : readLazily($path);

$count = 0;
foreach ($rows as $row) {
    if ((int) $row[2] < 1950) {
        $count++;
    }
}
printf("%s: %d books before 1950, peak memory %.1f MiB\n", $mode, $count, memory_get_peak_usage() / 1024 / 1024);
```

```bash
php lazy.php eager
php lazy.php lazy
```

```text
eager: 80000 books before 1950, peak memory 64.8 MiB
lazy: 80000 books before 1950, peak memory 0.5 MiB
```

The eager version's memory grows with the file (a 6 MiB file costs 65 MiB as PHP arrays); the lazy one stays flat
however big the file is. With PHP's default `memory_limit` of 128 MiB for web requests, the eager approach fails on a
file twice this size. (The `escape: ''` argument is explained in chapter 14.)

The `try`/`finally` inside the generator guarantees the file is closed even if the consumer stops early with `break`:
when an unfinished generator is destroyed, PHP runs its `finally` blocks.

### Lazy pipelines

Small generator functions compose into pipelines in which each element flows through every stage before the next one
is read:

```php
<?php

declare(strict_types=1);

function naturals(): Generator
{
    for ($n = 1; ; $n++) {
        yield $n;           // an infinite sequence is fine: nothing asks for more than it needs
    }
}

function filter(iterable $items, callable $keep): Generator
{
    foreach ($items as $key => $item) {
        if ($keep($item)) {
            yield $key => $item;
        }
    }
}

function map(iterable $items, callable $transform): Generator
{
    foreach ($items as $key => $item) {
        yield $key => $transform($item);
    }
}

function take(iterable $items, int $n): Generator
{
    if ($n <= 0) {
        return;
    }
    foreach ($items as $key => $item) {
        yield $key => $item;
        if (--$n === 0) {
            return;
        }
    }
}

$squaresOfMultiplesOf3 = take(map(filter(naturals(), fn (int $n): bool => $n % 3 === 0), fn (int $n): int => $n * $n), 5);
echo implode(', ', iterator_to_array($squaresOfMultiplesOf3, false)), "\n";

// The same pipeline with the PHP 8.5 pipe operator reads top to bottom:
$result = naturals()
    |> (fn (iterable $it) => filter($it, fn (int $n): bool => $n % 3 === 0))
    |> (fn (iterable $it) => map($it, fn (int $n): int => $n * $n))
    |> (fn (iterable $it) => take($it, 5))
    |> (fn (iterable $it) => iterator_to_array($it, false));
echo implode(', ', $result), "\n";
```

```text
9, 36, 81, 144, 225
9, 36, 81, 144, 225
```

Contrast with `array_map()`/`array_filter()`, which build a complete intermediate array at each step and cannot work
on an infinite (or merely huge) source at all.

### Helpers: `iterator_to_array()` and friends

- `iterator_to_array($it, $preserveKeys = true)` collects an iterator into an array. **Pass `false`** unless you need
  the keys: keys repeat when generators are combined (`yield from`, below), and later values overwrite earlier ones.
- `iterator_count($it)` consumes an iterator and counts its elements.
- `iterator_apply()` calls a function for each element.
- Since PHP 8.2, `iterator_to_array()` and `iterator_count()` accept arrays too, so they work on any `iterable`.
  `iterator_apply()` still requires a `Traversable`.

### `yield from`

`yield from` delegates to another generator, array or iterator, yielding all of its values:

```php
<?php

declare(strict_types=1);

function inner(): Generator
{
    yield 1;
    yield 2;
    return 'inner done';
}

function outer(): Generator
{
    $result = yield from inner();   // receives inner()'s return value
    yield 3;
    yield $result;
}

var_dump(iterator_to_array(outer()));         // keys collide!
var_dump(iterator_to_array(outer(), false));  // what you meant
```

```text
array(2) {
  [0]=>
  int(3)
  [1]=>
  string(10) "inner done"
}
array(4) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
  [3]=>
  string(10) "inner done"
}
```

`inner()` yields keys 0 and 1, then `outer()`'s own yields also start at keys 0 and 1, overwriting them. This is the
trap mentioned above. `yield from` is ideal for recursive structures, such as walking a tree of categories.

### Two-way generators: `send()`

`yield` is also an expression: `$x = yield $value;` receives whatever the caller passes to `$gen->send($x)`. This turns
generators into coroutines that consume values:

```php
<?php

declare(strict_types=1);

function lineWriter(string $path): Generator
{
    $h = fopen($path, 'w');
    try {
        while (($line = yield) !== null) {
            fwrite($h, $line . "\n");
        }
    } finally {
        fclose($h);
    }
}

$path = sys_get_temp_dir() . '/log.txt';
$writer = lineWriter($path);
$writer->current();         // run up to the first yield
$writer->send('first');
$writer->send('second');
$writer->send(null);        // ends the loop; finally closes the file

echo file_get_contents($path);
```

```text
first
second
```

In everyday code you will rarely need `send()`. It was the foundation of early asynchronous PHP libraries, which have
since moved to fibers (below).

## The SPL: iterators and data structures

The **Standard PHP Library** (SPL), always available, provides ready-made iterators and data structures.

### Iterators worth knowing

| Class | Does |
|---|---|
| `ArrayIterator` | Wraps an array as an iterator |
| `LimitIterator($it, $offset, $count)` | A slice of another iterator |
| `CallbackFilterIterator($it, $fn)` | Keeps elements for which `$fn` returns true |
| `RecursiveDirectoryIterator` + `RecursiveIteratorIterator` | Walk a directory tree |
| `RegexIterator` | Keeps elements matching a pattern |
| `InfiniteIterator`, `AppendIterator`, `MultipleIterator` | Cycle, concatenate, zip |

```php
<?php

declare(strict_types=1);

// Every .php file in this script's directory and below.
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        echo $file->getPathname(), "\n";
    }
}
```

A generator is often simpler than composing SPL iterator objects, but the directory iterators are very handy.

### Data structures

PHP arrays can act as stacks and queues (`array_push`/`array_pop`, `array_shift`), but `array_shift()` reindexes the
array every time, so a large queue built from an array gets slow. The SPL classes have the right performance and say
what they are:

```php
<?php

declare(strict_types=1);

$stack = new SplStack();            // last in, first out
$stack->push('a');
$stack->push('b');
echo $stack->pop(), "\n";           // b

$queue = new SplQueue();            // first in, first out
$queue->enqueue('first reservation');
$queue->enqueue('second reservation');
echo $queue->dequeue(), "\n";       // first reservation

$jobs = new SplPriorityQueue();     // highest priority first
$jobs->insert('send newsletter', 1);
$jobs->insert('send overdue notice', 10);
$jobs->insert('rebuild search index', 5);
while (!$jobs->isEmpty()) {
    echo $jobs->extract(), "\n";
}

$heap = new SplMinHeap();           // always gives the smallest element
foreach ([5, 1, 3] as $n) {
    $heap->insert($n);
}
echo $heap->extract(), "\n";        // 1
```

```text
b
first reservation
send overdue notice
rebuild search index
send newsletter
1
```

`SplFixedArray` is an array with a fixed size and integer indexes only; out-of-range access throws
`OutOfBoundsException`. Its memory advantage over modern packed PHP arrays is small, so use it when the fixed size is
itself a useful guarantee.

### Objects as keys: `SplObjectStorage` and `WeakMap`

Arrays cannot have objects (or enums) as keys. Two classes fill the gap:

```php
<?php

declare(strict_types=1);

final class Member
{
    public function __construct(public string $name) {}
}

$ada = new Member('Ada');

// SplObjectStorage: a map from objects to data, using array syntax.
$finesByMember = new SplObjectStorage();
$finesByMember[$ada] = 475;
var_dump(isset($finesByMember[$ada]), $finesByMember[$ada], count($finesByMember));

// WeakMap (PHP 8.0+): like SplObjectStorage, but does not keep its keys alive.
$grace = new Member('Grace');
$cache = new WeakMap();
$cache[$grace] = 'expensive computed report';
echo count($cache), "\n";   // 1
unset($grace);              // the last reference to the member is gone...
echo count($cache), "\n";   // 0: ...so its cache entry disappears too
```

```text
bool(true)
int(475)
int(1)
1
0
```

The WeakMap demo uses a second member on purpose: `unset($ada)` would leave the count at 1, because `$finesByMember`
still holds a normal (strong) reference to Ada and keeps her alive. Use `WeakMap` for caches attached to objects:
entries vanish automatically when the object is no longer used anywhere else, so the cache cannot leak memory. (PHP
8.5 deprecates `SplObjectStorage`'s `attach()`, `detach()` and `contains()` methods in favor of the array-style syntax
shown above.) `WeakReference::create($obj)` holds a single reference that does not prevent garbage collection;
`->get()` returns the object or `null`.

## Fibers

A **fiber** (PHP 8.1+) is a function that can pause itself in the middle, at any depth of nested calls, and be resumed
later. Generators can only pause at a `yield` in their own body; a fiber can pause from inside any function it calls.

```php
<?php

declare(strict_types=1);

$fiber = new Fiber(function (string $name): string {
    echo "$name: step 1\n";
    $received = Fiber::suspend('paused after step 1');  // pause, handing a value out
    echo "$name: resumed with '$received'\n";
    return 'done';
});

$value = $fiber->start('worker');   // runs until the first suspend
echo "main got: $value\n";
$fiber->resume('hello');            // continues from the suspend
var_dump($fiber->isTerminated(), $fiber->getReturn());
```

```text
worker: step 1
main got: paused after step 1
worker: resumed with 'hello'
bool(true)
string(4) "done"
```

With a queue of fibers, you can build a tiny cooperative scheduler, in which tasks take turns:

```php
<?php

declare(strict_types=1);

final class Scheduler
{
    private SplQueue $ready;

    public function __construct()
    {
        $this->ready = new SplQueue();
    }

    public function spawn(callable $task): void
    {
        $this->ready->enqueue(new Fiber($task));
    }

    public function run(): void
    {
        while (!$this->ready->isEmpty()) {
            $fiber = $this->ready->dequeue();
            $fiber->isStarted() ? $fiber->resume() : $fiber->start();
            if (!$fiber->isTerminated()) {
                $this->ready->enqueue($fiber);
            }
        }
    }
}

function pause(): void
{
    Fiber::suspend();   // called from a nested function: generators could not do this
}

$scheduler = new Scheduler();
foreach (['A', 'B', 'C'] as $name) {
    $scheduler->spawn(function () use ($name): void {
        for ($i = 1; $i <= 3; $i++) {
            echo "$name$i ";
            pause();
        }
    });
}
$scheduler->run();
echo "\n";
```

```text
A1 B1 C1 A2 B2 C2 A3 B3 C3
```

What fibers are **not**: threads. Only one fiber runs at a time, on one CPU core; nothing runs in parallel. Fibers are
useful when tasks spend most of their time **waiting** (for the network, a database, a timer). An event loop suspends a
fiber when it starts waiting and resumes it when the data arrives, running other fibers in between. That is how async
libraries such as **AMPHP** (on top of the **Revolt** event loop) and **ReactPHP** let you write code that looks
synchronous but handles thousands of concurrent connections in one process. You will almost never create fibers
yourself; you use a library that does.

> **Just a taste — you don't need to learn or fully understand this now.** With AMPHP, two HTTP requests run
> concurrently; each `await()` suspends the current fiber until its response arrives. Chapter 22 discusses when
> long-running async PHP is worth it.

```php
use function Amp\async;
use function Amp\Future\await;

$client = Amp\Http\Client\HttpClientBuilder::buildDefault();
[$a, $b] = await([
    async(fn () => $client->request(new Amp\Http\Client\Request('https://example.org/a'))),
    async(fn () => $client->request(new Amp\Http\Client\Request('https://example.org/b'))),
]);
```

> **If you know Node.js:** a fiber is roughly what `await` gives you, the ability to pause a function until a result
> arrives, but without the `async` marker spreading through every caller. PHP has no built-in event loop; the libraries
> provide one.

## Bookshelf: a streaming CSV importer

The library has a spreadsheet of books to import, and it may have tens of thousands of rows. The importer reads it
lazily with a generator, turning each line into either a valid `Book` or an `InvalidRow` that explains what was wrong,
so one bad line does not stop the import. This is
[`examples/bookshelf/src/Import/CsvBookReader.php`](examples/bookshelf/src/Import/CsvBookReader.php):

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Import;

use Bookshelf\Domain\Book;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads books from a CSV file with the header: title,author,isbn,genre,year,copies
 *
 * The file is read lazily, one line at a time, so it can be arbitrarily large.
 */
final class CsvBookReader
{
    private const array COLUMNS = ['title', 'author', 'isbn', 'genre', 'year', 'copies'];

    /**
     * @return Generator<int, Book|InvalidRow> keyed by line number
     */
    public function read(string $path): Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open $path");
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if ($header !== false) {
                // Spreadsheet programs often start UTF-8 files with a byte-order mark. It is not part of the text.
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            }
            if ($header === false || array_map(trim(...), $header) !== self::COLUMNS) {
                throw new RuntimeException('Expected the header ' . implode(',', self::COLUMNS));
            }

            $line = 1;
            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                if ($fields === [null]) {
                    continue;   // blank line
                }
                yield $line => $this->toBook($line, $fields);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string|null> $fields */
    private function toBook(int $line, array $fields): Book|InvalidRow
    {
        if (count($fields) !== count(self::COLUMNS)) {
            return new InvalidRow($line, sprintf('expected %d fields, got %d', count(self::COLUMNS), count($fields)));
        }

        $row = array_combine(self::COLUMNS, array_map(fn (?string $f): string => trim((string) $f), $fields));

        try {
            $genre = $row['genre'] === '' ? null : (Genre::tryFrom($row['genre'])
                ?? throw new InvalidArgumentException("unknown genre '{$row['genre']}'"));
            $year = $row['year'] === '' ? null : $this->integer($row['year'], 'year', 0, 2100);
            $copies = $this->integer($row['copies'] === '' ? '1' : $row['copies'], 'copies', 1, 1000);

            return new Book(null, $row['title'], $row['author'], new Isbn($row['isbn']), $genre, $year, $copies);
        } catch (InvalidArgumentException $e) {
            return new InvalidRow($line, $e->getMessage());
        }
    }

    private function integer(string $value, string $field, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        return $int === false
            ? throw new InvalidArgumentException("$field must be a whole number from $min to $max, got '$value'")
            : $int;
    }
}
```

`InvalidRow` is a two-property readonly class (`int $line`, `string $reason`). Points to notice:

- The generator is keyed by **line number**, so callers can report "line 13: ..." without tracking a counter.
- Errors in the *file as a whole* (cannot open it, wrong header) throw, because nothing useful can continue. Errors in
  a *single row* are returned as values, so the caller decides whether to skip, collect or stop.
- The header check throws inside the generator, so it surfaces on the first iteration, not when `read()` is called.
  That is normal generator behavior and worth remembering when you write tests.
- `fgetcsv()` returns `[null]` for a blank line.
- A **byte-order mark** (three invisible bytes that Excel and other spreadsheet programs put at the start of UTF-8
  files) is removed from the header, otherwise the first column name would be `"\xEF\xBB\xBFtitle"`.
- Every value from the file passes through the boundary rules from chapter 7: `Genre::tryFrom()`, a validated integer
  parser, and the `Isbn` and `Book` constructors.

Using it from a script saved in `examples/bookshelf/` (so `vendor/autoload.php` is next to it), with the sample file [`examples/bookshelf/data/books.csv`](examples/bookshelf/data/books.csv) plus a few
broken lines appended:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\Book;
use Bookshelf\Import\CsvBookReader;

$path = sys_get_temp_dir() . '/import.csv';
file_put_contents($path, file_get_contents(__DIR__ . '/data/books.csv')
    . "Ulysses,James Joyce,978-0-679-72276-1,modernist,1922,1\n"
    . "Bad,Row\n"
    . "\n"
    . "No Isbn,Someone,123,sf,2001,1\n"
    . "Zero,Someone,978-0-441-01359-3,,1999,0\n");

$imported = 0;
foreach ((new CsvBookReader())->read($path) as $line => $result) {
    if ($result instanceof Book) {
        $imported++;
    } else {
        echo "line $line skipped: {$result->reason}\n";
    }
}
echo "$imported books ready to import\n";
```

```text
line 12 skipped: unknown genre 'modernist'
line 13 skipped: expected 6 fields, got 2
line 15 skipped: Invalid ISBN-13: '123'
line 16 skipped: copies must be a whole number from 1 to 1000, got '0'
10 books ready to import
```

Chapter 14 wraps this in a command-line tool, and chapter 17 saves the books to the database.

## Key takeaways

- `foreach` works on arrays and `Traversable` objects. Implement `IteratorAggregate` (returning an `ArrayIterator` or
  a generator) rather than the full `Iterator` interface.
- A variadic constructor (`Book ...$books`) is a simple way to build a type-safe collection.
- Generators produce values lazily and pause between them. They run once, support keys, `yield from` and return values,
  and clean up through `finally` when abandoned.
- Generator pipelines process huge or infinite sequences in constant memory; array functions build every intermediate
  array.
- `iterator_to_array($it, false)` unless you really need keys.
- SPL offers stacks, queues, priority queues, heaps and directory iterators; `SplObjectStorage` and `WeakMap` map
  objects to data, and `WeakMap` cannot leak.
- Fibers are pausable call stacks, not threads. They power async libraries like AMPHP and ReactPHP.

## Exercises

1. **Chunked processing.** Write a generator `chunk(iterable $items, int $size): Generator` that yields arrays of up to
   `$size` elements (the last chunk may be smaller). Use it to print the numbers 1–10 in groups of 4. Make sure it
   works with an infinite generator as input when combined with `take()`.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function chunk(iterable $items, int $size): Generator
{
    if ($size < 1) {
        throw new InvalidArgumentException('size must be at least 1');
    }

    $batch = [];
    foreach ($items as $item) {
        $batch[] = $item;
        if (count($batch) === $size) {
            yield $batch;
            $batch = [];
        }
    }
    if ($batch !== []) {
        yield $batch;
    }
}

foreach (chunk(range(1, 10), 4) as $group) {
    echo implode(' ', $group), "\n";
}
```

```text
1 2 3 4
5 6 7 8
9 10
```

Because `chunk()` yields as soon as a batch is full, it never needs the whole input; with an infinite input, only the
consumer decides when to stop (`take(chunk(naturals(), 4), 2)` gives two batches). Chunking is how you insert
imported rows into a database in batches of, say, 500 (chapter 17).

</details>

2. **Tree walking with `yield from`.** Given nested categories as arrays
   `['name' => 'Fiction', 'children' => [['name' => 'SF', 'children' => [...]], ...]]`, write a generator
   `walk(array $node, int $depth = 0)` that yields each category name indented by depth, using `yield from` for
   children.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/**
 * @param array{name: string, children?: list<array>} $node
 */
function walk(array $node, int $depth = 0): Generator
{
    yield str_repeat('  ', $depth) . $node['name'];
    foreach ($node['children'] ?? [] as $child) {
        yield from walk($child, $depth + 1);
    }
}

$tree = ['name' => 'Fiction', 'children' => [
    ['name' => 'Science fiction', 'children' => [['name' => 'Space opera'], ['name' => 'Cyberpunk']]],
    ['name' => 'Crime'],
]];

foreach (walk($tree) as $line) {
    echo $line, "\n";
}
```

```text
Fiction
  Science fiction
    Space opera
    Cyberpunk
  Crime
```

Iterating with `foreach` ignores the colliding keys, so `yield from` is safe here. `iterator_to_array(walk($tree))`
would lose lines; pass `false`.

</details>

3. **A generator-backed collection.** Change the `BookList` class from this chapter so that `getIterator()` is a
   generator that yields books keyed by title. Then add a method `titles(): Generator` that yields only titles. Why can
   `getIterator()` be a generator method even though a generator can only be iterated once?

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final readonly class Book
{
    public function __construct(public string $title, public int $year) {}
}

/** @implements IteratorAggregate<string, Book> */
final class BookList implements IteratorAggregate
{
    /** @var list<Book> */
    private array $books;

    public function __construct(Book ...$books)
    {
        $this->books = $books;
    }

    public function getIterator(): Generator
    {
        foreach ($this->books as $book) {
            yield $book->title => $book;
        }
    }

    public function titles(): Generator
    {
        foreach ($this as $title => $book) {
            yield $title;
        }
    }
}

$list = new BookList(new Book('Dune', 1965), new Book('Emma', 1815));
foreach ($list as $title => $book) {
    echo "$title: {$book->year}\n";
}
echo implode(', ', iterator_to_array($list->titles(), false)), "\n";
echo implode(', ', iterator_to_array($list->titles(), false)), "\n"; // works again
```

```text
Dune: 1965
Emma: 1815
Dune, Emma
Dune, Emma
```

`foreach` calls `getIterator()` each time a loop starts, and each call creates a **new** generator, so the collection
can be iterated any number of times. Only a single generator *object* is one-shot.

</details>

4. **A round-robin reading list.** Members have reservation queues per book. Write a function that takes an array of
   `SplQueue`s (one per book, each holding member names) and returns the order in which members get notified when one
   copy of every book comes back, round-robin across books, one member per book per round. Use `SplQueue` methods
   only, no array conversions.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

/**
 * @param array<string, SplQueue<string>> $queues book title => waiting members
 * @return list<string>
 */
function notificationOrder(array $queues): array
{
    $order = [];
    do {
        $progress = false;
        foreach ($queues as $title => $queue) {
            if (!$queue->isEmpty()) {
                $order[] = "{$queue->dequeue()} gets $title";
                $progress = true;
            }
        }
    } while ($progress);

    return $order;
}

$dune = new SplQueue();
$dune->enqueue('Ada');
$dune->enqueue('Alan');
$dune->enqueue('Grace');
$emma = new SplQueue();
$emma->enqueue('Linus');

print_r(notificationOrder(['Dune' => $dune, 'Emma' => $emma]));
```

```text
Array
(
    [0] => Ada gets Dune
    [1] => Linus gets Emma
    [2] => Alan gets Dune
    [3] => Grace gets Dune
)
```

`dequeue()` is O(1), whereas `array_shift()` on a large array is O(n) because it renumbers the keys.

</details>

5. **Count lines in many files lazily.** Write a generator `linesOf(string ...$paths)` that yields every line of every
   file (without trailing newlines), keyed by `"path:lineNumber"`, opening each file only when it is reached and
   closing it before opening the next. Use it with `iterator_count()` and to find the first line containing `TODO`.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function linesOf(string ...$paths): Generator
{
    foreach ($paths as $path) {
        $h = fopen($path, 'r') ?: throw new RuntimeException("Cannot open $path");
        try {
            $n = 0;
            while (($line = fgets($h)) !== false) {
                $n++;
                yield "$path:$n" => rtrim($line, "\r\n");
            }
        } finally {
            fclose($h);
        }
    }
}

$dir = sys_get_temp_dir();
file_put_contents("$dir/a.txt", "one\ntwo\n");
file_put_contents("$dir/b.txt", "three\n// TODO: renewals\nfive\n");

echo iterator_count(linesOf("$dir/a.txt", "$dir/b.txt")), " lines\n";

foreach (linesOf("$dir/a.txt", "$dir/b.txt") as $where => $line) {
    if (str_contains($line, 'TODO')) {
        echo basename($where), " => $line\n";
        break;   // the generator's finally block still closes b.txt
    }
}
```

```text
5 lines
b.txt:2 => // TODO: renewals
```

`iterator_count()` consumes the first generator; the search needs a fresh one, so `linesOf()` is called again.
`basename()` on `"/tmp/b.txt:2"` keeps `b.txt:2`, which is a convenient side effect here.

</details>

---
← Previous: [Namespaces, autoloading and Composer](12-namespaces-autoloading-and-composer.md) | Next: [Files, streams and the command line](14-files-streams-and-the-cli.md) →
