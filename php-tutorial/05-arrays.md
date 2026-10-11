# Chapter 5 — Arrays

## What you'll learn

- What a PHP array really is: an ordered hash map that serves as list, dictionary, set, stack and record
- How keys work, including the automatic conversions that surprise people
- Reading safely: missing keys, `isset()` versus `array_key_exists()`
- Copy-on-write: why passing arrays around is cheap, and when a copy actually happens
- Destructuring and the spread operator
- The array toolkit: searching, adding and removing, combining, `array_column()`, `array_map()`, `array_filter()`,
  `array_reduce()`, and the PHP 8.4 `array_find()` family and PHP 8.5 `array_first()`/`array_last()`
- Sorting, including multi-key sorts and natural order
- The Bookshelf catalog as an array, with real queries against it

## One structure, many uses

PHP has exactly one array type, and it is an **ordered map**: a collection of key/value pairs that remembers the order
in which they were inserted. Keys are integers or strings; values can be anything, including other arrays.

```php
<?php

// Used as a list: keys 0, 1, 2 are assigned automatically.
$titles = ['Dune', 'Kindred', 'Emma'];

// Used as a dictionary (a "map", or an "associative array").
$book = ['title' => 'Dune', 'author' => 'Frank Herbert', 'year' => 1965];

// Nested: a list of maps, the shape of most data in a PHP program.
$catalog = [
    ['title' => 'Dune', 'year' => 1965],
    ['title' => 'Emma', 'year' => 1815],
];

echo $titles[1], "\n";             // Kindred
echo $book['author'], "\n";        // Frank Herbert
echo $catalog[1]['title'], "\n";   // Emma
```

The older `array(...)` syntax means the same as `[...]`. You will see it in legacy code; use `[]`.

Internally, the engine stores arrays as a hash table, with an optimized "packed" layout for lists whose keys are
`0, 1, 2, ...` in order. Lookups by key take constant time on average, regardless of the array's size.

### Lists versus maps

A **list** is an array whose keys are exactly `0, 1, 2, ..., n-1` in that order. `array_is_list()` (PHP 8.1+) checks it:

```php
var_dump(array_is_list(['a', 'b']));          // bool(true)
var_dump(array_is_list([1 => 'a']));          // bool(false)
var_dump(array_is_list([0 => 'a', 2 => 'b'])); // bool(false): a gap
```

The distinction matters when arrays leave PHP: `json_encode()` turns a list into a JSON array `[...]` and anything else
into a JSON object `{...}` (chapter 14). A list that loses an element in the middle becomes an object in JSON, which is a
classic API bug.

### Appending and automatic keys

`$array[] = $value` appends with the next integer key, which is one more than the largest integer key so far:

```php
<?php

$b = [5 => 'x'];
$b[] = 'y';        // key 6
$b['k'] = 'z';     // string key, does not affect numbering
$b[] = 'w';        // key 7
print_r($b);
```

```text
Array
(
    [5] => x
    [6] => y
    [k] => z
    [7] => w
)
```

(Since PHP 8.3, an array whose only integer key is negative continues from it: after `[-5 => 'a']`, the next key is
`-4`. Before 8.3 it was `0`.)

### Key conversion rules

Keys can only be `int` or `string`, so PHP converts other key types, and it also converts some strings:

| You write | Stored key | Note |
|---|---|---|
| `'8'` | `8` (int) | A string that is a canonical decimal integer becomes an int |
| `'08'`, `' 9'`, `'8.0'` | unchanged strings | Not canonical, so they stay strings |
| `true` / `false` | `1` / `0` | |
| `1.7` | `1` | Truncated, with a deprecation warning since PHP 8.1 |
| `null` | `''` | Deprecated in PHP 8.5; write `''` if that is what you mean |

The first rule surprises people: `$a['8']` and `$a[8]` are the **same element**. If you build a map keyed by
numeric-looking strings (ISBNs, ZIP codes, phone numbers), the keys become integers, and functions such as
`array_merge()` will renumber them (see "Combining arrays"). Arrays and objects cannot be keys at all; chapter 13 shows
`SplObjectStorage` and `WeakMap` for object keys.

## Reading safely

Reading a key that does not exist produces a warning and `null`:

```php
<?php

$book = ['title' => 'Dune', 'isbn' => null];

echo $book['author'];
```

```text
PHP Warning:  Undefined array key "author" in demo.php on line 5
```

Three ways to read safely, which differ in how they treat `null` values:

```php
var_dump($book['author'] ?? 'unknown');         // "unknown": missing or null -> default
var_dump(isset($book['isbn']));                 // bool(false): the key exists but the value is null
var_dump(array_key_exists('isbn', $book));      // bool(true): the key exists, whatever its value
```

Use `??` to read with a default and `isset()` for "is there a usable value". Use `array_key_exists()` only when
"present but `null`" must be told apart from "absent", for example when applying a partial update where `null` means
"clear this field".

## Arrays are values: copy-on-write

Assigning an array, passing it to a function or returning it **copies** it, as far as your code can tell. The original
is never affected:

```php
<?php

function addOne(array $items): array
{
    $items[] = 1;   // modifies the function's own copy
    return $items;
}

$list = [1, 2, 3];
$new = addOne($list);
echo count($list), ' ', count($new), "\n"; // 3 4
```

Copying sounds expensive, but PHP is lazy about it. Assignment only makes both variables point to the same array and
increments a reference count; the real copy happens on the first **write**, and only if the array is shared:

```php
<?php

$big = range(1, 1_000_000);
$before = memory_get_usage();

$copy = $big;   // no copy yet
echo 'after assignment: ', memory_get_usage() - $before, " bytes\n";

$copy[] = 1;    // now PHP must copy the million elements
echo 'after modification: ', round((memory_get_usage() - $before) / 1024 / 1024, 1), " MiB\n";
```

```text
after assignment: 32 bytes
after modification: 16.1 MiB
```

Consequences:

- Passing arrays to functions is cheap as long as the function only reads them. Do not pass arrays by reference "for
  performance"; it does not help and can hurt (chapter 21).
- Value semantics make code easier to reason about: a function cannot change your array behind your back unless its
  parameter is declared by reference (`array &$items`), which is visible in the signature.
- Objects are different: arrays of objects copy the *handles*, so both arrays point to the same objects (chapter 9).

## Destructuring

Square brackets on the left of `=` unpack an array into variables:

```php
<?php

[$title, $year] = ['Dune', 1965];             // by position
[, $second] = ['x', 'y'];                     // skip elements
['title' => $t, 'year' => $y] = ['year' => 1965, 'title' => 'Dune']; // by key, any order
[$a, [$b, $c]] = [1, [2, 3]];                 // nested

$p = 1;
$q = 2;
[$p, $q] = [$q, $p];                          // swap without a temporary

echo "$title $year | $second | $t $y | $a$b$c | $p $q\n";
```

```text
Dune 1965 | y | Dune 1965 | 123 | 2 1
```

Destructuring a missing key gives the usual "Undefined array key" warning. `list($a, $b) = ...` is the older spelling.
Destructuring works in `foreach` as well, which makes loops over records read nicely:

```php
foreach ($catalog as ['title' => $title, 'year' => $year]) {
    echo "$title ($year)\n";
}
```

## The spread operator

`...` inside an array literal unpacks another array (or any `Traversable`) into it:

```php
<?php

$sf = ['Dune', 'Kindred'];
$classics = ['Emma', 'Persuasion'];

$all = [...$sf, ...$classics, 'Beloved'];
print_r($all); // a list of 5 titles

$defaults = ['loanDays' => 21, 'maxLoans' => 3];
$premium = [...$defaults, 'maxLoans' => 6];   // string keys: later wins (PHP 8.1+)
print_r($premium); // ['loanDays' => 21, 'maxLoans' => 6]
```

Integer keys are renumbered, string keys overwrite. The same `...` passes an array as separate function arguments
(chapter 6).

## The array toolkit

### Inspecting and searching

```php
count($titles);                          // number of elements (count($a, COUNT_RECURSIVE) for nested)
in_array('Emma', $titles, true);         // is this value present?  ALWAYS pass true
array_search('Emma', $titles, true);     // its key, or false
array_keys($book);                       // ['title', 'author', 'year']
array_values($book);                     // the values, reindexed as a list
array_key_first($book);                  // 'title' (null if empty)
array_key_last($book);                   // 'year'
array_first($titles);                    // 'Dune'  PHP 8.5+  (null if empty)
array_last($titles);                     // 'Emma'  PHP 8.5+
```

Why always `true` in `in_array()`? Without it, the search uses `==`:

```php
var_dump(in_array('1e1', ['10']));        // bool(true): '1e1' == '10' numerically
var_dump(in_array('1e1', ['10'], true));  // bool(false)
```

`array_search()` has the `strpos()` problem from chapter 4: a key of `0` is falsy, so compare its result with
`!== false`.

`in_array()` checks every element, so it gets slower as the array grows. For repeated membership tests, flip the values
into keys once and use `isset()`, which is a constant-time hash lookup:

```php
<?php

$onLoan = array_flip(['Dune', 'Emma', 'Kindred']);   // ['Dune' => 0, 'Emma' => 1, 'Kindred' => 2]

var_dump(isset($onLoan['Emma']));     // bool(true)
var_dump(isset($onLoan['Ulysses']));  // bool(false)
```

This "array as a set" idiom is everywhere in PHP code.

### Adding and removing

```php
<?php

$stack = [];
array_push($stack, 'a', 'b');   // same as $stack[] = 'a'; $stack[] = 'b';
$stack[] = 'c';
echo array_pop($stack);          // 'c': remove from the end
echo array_shift($stack);        // 'a': remove from the start (reindexes, so it is O(n))
array_unshift($stack, 'z');      // add at the start
print_r($stack);                 // ['z', 'b']
```

`unset($array[$key])` removes one element **without renumbering** the others:

```php
<?php

$list = ['a', 'b', 'c'];
unset($list[1]);
print_r($list);                // [0 => 'a', 2 => 'c']: no longer a list
print_r(array_values($list));  // [0 => 'a', 1 => 'c']
```

Call `array_values()` when you need a proper list again, for example before `json_encode()`.

`array_slice()` returns a portion (pass `true` as the fourth argument to keep the keys), and `array_splice()` removes
and/or inserts in place:

```php
<?php

print_r(array_slice(['a', 'b', 'c', 'd'], 1, 2));   // ['b', 'c']

$arr = ['a', 'b', 'c', 'd'];
$removed = array_splice($arr, 1, 2, ['X']);         // remove 2 elements at offset 1, insert 'X'
print_r($arr);       // ['a', 'X', 'd']
print_r($removed);   // ['b', 'c']
```

### Combining arrays

There are four ways to combine two arrays, and they differ in how they handle keys:

```php
<?php

$x = [3 => 'a', 'k' => 'b'];
$y = [3 => 'c', 'k' => 'd', 'z' => 'e'];

print_r($x + $y);              // union: keep LEFT values for duplicate keys
print_r(array_merge($x, $y));  // renumber int keys, RIGHT wins for string keys
print_r(array_replace($x, $y)); // RIGHT wins for every key, no renumbering
print_r([...$x, ...$y]);        // same as array_merge()
```

| Operation | Integer keys | String keys | Result for the example |
|---|---|---|---|
| `$x + $y` | left wins, kept | left wins | `[3 => 'a', 'k' => 'b', 'z' => 'e']` |
| `array_merge($x, $y)` | **renumbered**, all kept | right wins | `[0 => 'a', 'k' => 'd', 1 => 'c', 'z' => 'e']` |
| `array_replace($x, $y)` | right wins, kept | right wins | `[3 => 'c', 'k' => 'd', 'z' => 'e']` |
| `[...$x, ...$y]` | renumbered | right wins | same as `array_merge()` |

Rules of thumb:

- **Concatenating lists:** `[...$a, ...$b]` or `array_merge()`.
- **Applying defaults to options:** `$options + $defaults` (user values win, missing ones are filled in), or
  `array_replace($defaults, $options)`.
- **Maps keyed by numeric IDs:** never `array_merge()`, which destroys the keys. Use `+` or `array_replace()`.

### Building arrays

```php
range(1, 5);                      // [1, 2, 3, 4, 5]
range('a', 'e', 2);               // ['a', 'c', 'e']
range(10, 0, 5);                  // [10, 5, 0]
array_fill(0, 3, 'x');            // ['x', 'x', 'x']
array_fill_keys(['a', 'b'], 0);   // ['a' => 0, 'b' => 0]
array_combine(['x', 'y'], [1, 2]); // ['x' => 1, 'y' => 2]
array_flip(['a', 'b']);           // ['a' => 0, 'b' => 1]
array_pad([1, 2], 4, 0);          // [1, 2, 0, 0]
```

### Transforming: unique, chunk, count, diff, intersect

```php
array_unique([3, '3', 1, 2, 1]);       // [0 => 3, 2 => 1, 3 => 2]: keeps the first, compares as strings
array_chunk([1, 2, 3, 4, 5], 2);       // [[1, 2], [3, 4], [5]]
array_count_values(['sf', 'sf', 'classic']); // ['sf' => 2, 'classic' => 1]
array_reverse(['a', 'b']);             // ['b', 'a']
array_diff(['a', 'b', 'c'], ['b']);    // [0 => 'a', 2 => 'c']: values in the first, not the second
array_intersect(['a', 'b'], ['b', 'c']); // [1 => 'b']
array_diff_key(['a' => 1, 'b' => 2], ['a' => 0]);              // ['b' => 2]
array_intersect_key(['a' => 1, 'b' => 2, 'c' => 3], ['a' => 0, 'c' => 0]); // ['a' => 1, 'c' => 3]
```

`array_intersect_key()` with an `array_flip()`ped list of allowed keys is the standard way to whitelist fields:
`array_intersect_key($input, array_flip(['title', 'author']))`.

### `array_column()`: pulling fields out of records

`array_column()` is one of the most useful functions in PHP. Given a list of records, it extracts one field, optionally
keyed by another:

```php
<?php

$catalog = [
    ['id' => 1, 'title' => 'Dune', 'year' => 1965],
    ['id' => 3, 'title' => 'Emma', 'year' => 1815],
];

print_r(array_column($catalog, 'title'));          // ['Dune', 'Emma']
print_r(array_column($catalog, 'title', 'id'));    // [1 => 'Dune', 3 => 'Emma']
$byId = array_column($catalog, null, 'id');        // whole records keyed by id
echo $byId[3]['title'], "\n";                      // Emma
```

It also works on lists of objects with public properties.

## Functional helpers: map, filter, reduce, find

These take a **callback**, a function passed as an argument. The examples use arrow functions (`fn ($b) => ...`),
which chapter 6 explains in detail; for now read `fn ($b) => $b['copies'] > 0` as "a function that takes `$b` and
returns whether its copies are positive".

### `array_map()`

`array_map($callback, $array)` returns a new array with the callback applied to every value. **Keys are preserved**
when you pass one array:

```php
print_r(array_map(fn ($v) => $v * 2, ['x' => 1, 'y' => 2])); // ['x' => 2, 'y' => 4]
```

With several arrays, the callback receives one element from each, and the result is a list:

```php
print_r(array_map(fn ($t, $y) => "$t ($y)", ['Dune', 'Emma'], [1965, 1815]));
// ['Dune (1965)', 'Emma (1815)']
```

Note the argument order: callback first for `array_map()`, but array first for `array_filter()` and `array_reduce()`.
It is a well-known inconsistency; your editor's hints will save you.

### `array_filter()`

`array_filter($array, $callback)` keeps the elements for which the callback returns a truthy value. **Keys are
preserved**, so the result of filtering a list usually has gaps:

```php
<?php

$copies = ['Dune' => 2, 'Kindred' => 0, 'Emma' => 1];
print_r(array_filter($copies, fn ($n) => $n > 0));     // ['Dune' => 2, 'Emma' => 1]

// Filter by key, or by key and value:
print_r(array_filter($copies, fn ($k) => $k !== 'Emma', ARRAY_FILTER_USE_KEY));
print_r(array_filter($copies, fn ($n, $k) => $n > 0 && $k !== 'Dune', ARRAY_FILTER_USE_BOTH));
```

Without a callback, `array_filter()` removes every falsy element, including `0` and `'0'`, which is rarely what you
want for data. Pass an explicit callback such as `fn ($v) => $v !== null`.

### `array_reduce()`

`array_reduce($array, $callback, $initial)` folds an array into a single value. The callback receives the running
result ("carry") and the current element:

```php
$total = array_reduce($catalog, fn (int $carry, array $b) => $carry + $b['year'], 0);
```

Many reductions have a dedicated function that is clearer: `array_sum()`, `array_product()`, `max()`, `min()`,
`count()`, `implode()`.

### `array_find()` and friends (PHP 8.4+)

PHP 8.4 added four functions that stop as soon as they have an answer:

| Function | Returns |
|---|---|
| `array_find($a, $cb)` | The first **value** for which the callback returns `true`, or `null` |
| `array_find_key($a, $cb)` | The first **key** for which the callback returns `true`, or `null` |
| `array_any($a, $cb)` | `true` if the callback returns `true` for at least one element |
| `array_all($a, $cb)` | `true` if the callback returns `true` for every element |

The callbacks receive the value and the key. Before 8.4 you wrote a `foreach` loop with `break`, or misused
`array_filter()` (which checks every element even after a match).

### `array_walk()`

`array_walk()` calls a callback for each element, passing the value by reference so the callback can modify it in
place. It is mostly a legacy tool; `foreach` or `array_map()` is usually clearer.

### `foreach` or functional?

Both are fine. A `foreach` loop is often more readable for anything with several steps, and it is easier to debug.
`array_map()`/`array_filter()` shine for one-line transformations. Do not chain five of them in one expression
(chapter 6 shows the pipe operator, which helps, and chapter 13 shows lazy generator pipelines for large data).

## Sorting

PHP's sort functions **sort in place** (they take the array by reference) and return `true`. A common mistake is
`$sorted = sort($titles);`, which sets `$sorted` to `true`. The functions differ in what they sort by and whether they
keep keys:

| Function | Sorts by | Keeps keys? | Order |
|---|---|---|---|
| `sort` / `rsort` | value | no, reindexes | ascending / descending |
| `asort` / `arsort` | value | yes | ascending / descending |
| `ksort` / `krsort` | key | yes | ascending / descending |
| `usort` | value, with your callback | no | your comparison |
| `uasort` | value, with your callback | yes | your comparison |
| `uksort` | key, with your callback | yes | your comparison |

The mnemonic: `a` = associative (keep keys), `k` = by key, `r` = reverse, `u` = user callback.

```php
<?php

$fines = ['Ada' => 125, 'Grace' => 50, 'Alan' => 300];

$byAmount = $fines;
arsort($byAmount);             // highest fine first, names kept
print_r($byAmount);            // ['Alan' => 300, 'Ada' => 125, 'Grace' => 50]

$byName = $fines;
ksort($byName);                // alphabetical by name
print_r($byName);              // ['Ada' => 125, 'Alan' => 300, 'Grace' => 50]
```

Because sorting is in place, copy the array first (as above) if you still need the original order.

### Sort flags and natural order

By default, values are compared with the same rules as `<`, which is fine for numbers but not for mixed-case strings
or numbered names. Pass flags:

```php
<?php

$names = ['Dune', 'emma', 'Kindred', 'beloved', 'img12', 'img10', 'img2'];

$t = $names;
sort($t);
echo implode(',', $t), "\n";   // Dune,Kindred,beloved,emma,img10,img12,img2  (upper case first, byte order)

$t = $names;
sort($t, SORT_NATURAL | SORT_FLAG_CASE);
echo implode(',', $t), "\n";   // beloved,Dune,emma,img2,img10,img12,Kindred
```

Other flags: `SORT_STRING` (compare as strings), `SORT_NUMERIC`, and `SORT_LOCALE_STRING`. For true
language-aware sorting (where `é` sorts next to `e`), use `Collator` from `intl` (chapter 15).

### Custom comparisons with `usort()`

The callback receives two elements and returns a negative number, zero or a positive number, which is exactly what `<=>`
produces. To sort by several keys, compare arrays, since `<=>` compares them element by element:

```php
<?php

$catalog = [
    ['title' => 'Dune', 'genre' => 'sf', 'year' => 1965],
    ['title' => 'Emma', 'genre' => 'classic', 'year' => 1815],
    ['title' => 'Kindred', 'genre' => 'sf', 'year' => 1979],
    ['title' => 'Persuasion', 'genre' => 'classic', 'year' => 1817],
];

// Genre ascending, then year descending (note $b and $a swapped for the second key).
usort($catalog, fn ($a, $b) => [$a['genre'], $b['year']] <=> [$b['genre'], $a['year']]);

foreach ($catalog as $book) {
    echo "{$book['genre']} {$book['year']} {$book['title']}\n";
}
```

```text
classic 1817 Persuasion
classic 1815 Emma
sf 1979 Kindred
sf 1965 Dune
```

Since PHP 8.0 all sorts are **stable**: elements that compare equal keep their original relative order. That means you
can also sort by the secondary key first and the primary key second.

## Arrays and memory

PHP arrays are flexible, and that flexibility costs memory: each element in a hash-map array uses roughly 32 bytes
plus the value, and lists about 16 bytes per element. A million-element list of integers takes about 16 MiB, as the
copy-on-write example showed. That is fine for typical web requests, but if you load a large CSV file into one array,
memory runs out quickly. Chapter 13 shows generators, which process large data one element at a time, and the SPL data
structures; chapter 21 covers measuring memory.

## Bookshelf: the catalog as an array

Here is the Bookshelf catalog as a list of maps, with the kind of queries the app will need. Later chapters will turn
each record into a `Book` object and the queries into methods, but the logic stays the same.

```php
<?php

$catalog = [
    ['id' => 1, 'title' => 'Dune', 'author' => 'Frank Herbert', 'year' => 1965, 'genre' => 'sf', 'copies' => 2],
    ['id' => 2, 'title' => 'Kindred', 'author' => 'Octavia E. Butler', 'year' => 1979, 'genre' => 'sf', 'copies' => 0],
    ['id' => 3, 'title' => 'Emma', 'author' => 'Jane Austen', 'year' => 1815, 'genre' => 'classic', 'copies' => 1],
    ['id' => 4, 'title' => 'The Left Hand of Darkness', 'author' => 'Ursula K. Le Guin', 'year' => 1969, 'genre' => 'sf', 'copies' => 3],
    ['id' => 5, 'title' => 'Persuasion', 'author' => 'Jane Austen', 'year' => 1817, 'genre' => 'classic', 'copies' => 0],
];

// 1. Titles of available books, as a clean list.
$available = array_values(array_filter($catalog, fn ($b) => $b['copies'] > 0));
echo 'Available: ', implode(', ', array_column($available, 'title')), "\n";

// 2. Lookup by id.
$byId = array_column($catalog, null, 'id');
echo 'Book #4: ', $byId[4]['title'] ?? 'not found', "\n";

// 3. Group titles by author.
$byAuthor = [];
foreach ($catalog as $book) {
    $byAuthor[$book['author']][] = $book['title'];
}
ksort($byAuthor);
foreach ($byAuthor as $author => $titles) {
    echo "$author: ", implode(', ', $titles), "\n";
}

// 4. Counts per genre, and total copies.
print_r(array_count_values(array_column($catalog, 'genre')));
echo 'Copies on the shelves: ', array_sum(array_column($catalog, 'copies')), "\n";

// 5. The oldest book, and whether any book is out of stock (PHP 8.4+).
$sorted = $catalog;
usort($sorted, fn ($a, $b) => $a['year'] <=> $b['year']);
echo 'Oldest: ', array_first($sorted)['title'], "\n";   // PHP 8.5+
var_dump(array_any($catalog, fn ($b) => $b['copies'] === 0));
echo 'First pre-1900 book: ', array_find($catalog, fn ($b) => $b['year'] < 1900)['title'], "\n";
```

```text
Available: Dune, Emma, The Left Hand of Darkness
Book #4: The Left Hand of Darkness
Frank Herbert: Dune
Jane Austen: Emma, Persuasion
Octavia E. Butler: Kindred
Ursula K. Le Guin: The Left Hand of Darkness
Array
(
    [sf] => 3
    [classic] => 2
)
Copies on the shelves: 6
Oldest: Emma
bool(true)
First pre-1900 book: Emma
```

`$byAuthor[$book['author']][] = ...` relies on a handy behavior: appending to a key that does not exist yet creates an
empty array there first, with no warning. (Reading a missing key warns; writing into one does not.)

## Key takeaways

- A PHP array is an ordered hash map with `int` or `string` keys. It is a list when its keys are `0..n-1` in order
  (`array_is_list()`).
- Numeric strings such as `'8'` become integer keys. Remember that before using IDs or ISBNs as keys with
  `array_merge()`.
- Read with `??` for defaults, `isset()` for "usable value", and `array_key_exists()` only when `null` must be told
  apart from missing.
- Arrays are values with copy-on-write: cheap to pass, safe from spooky action at a distance.
- Pass `true` to `in_array()` and `array_search()`. For repeated lookups, flip values into keys and use `isset()`.
- `+`, `array_merge()`, `array_replace()` and spread differ in key handling. Know which you need.
- `array_filter()` and `unset()` keep keys; call `array_values()` to get a list back.
- Sort functions work in place and return `true`; pick the variant by "keep keys?", "by key?" and "custom
  comparison?". `<=>` on arrays gives multi-key sorts.

## Exercises

1. **List or not?** For each array, predict `array_is_list()` and `json_encode()`'s output, then check:
   `['a', 'b']`, `[1 => 'a', 2 => 'b']`, the result of `array_filter([1, 0, 2])`, and the same wrapped in
   `array_values()`.

<details>
<summary>Solution</summary>

```php
<?php

$cases = [
    ['a', 'b'],
    [1 => 'a', 2 => 'b'],
    array_filter([1, 0, 2]),
    array_values(array_filter([1, 0, 2])),
];

foreach ($cases as $case) {
    echo var_export(array_is_list($case), true), ' ', json_encode($case), "\n";
}
```

```text
true ["a","b"]
false {"1":"a","2":"b"}
false {"0":1,"2":2}
true [1,2]
```

Filtering removed key 1, leaving keys 0 and 2, so JSON encodes it as an object. `array_values()` turns it back into a
list.

</details>

2. **Options with defaults.** Write code that merges user options `['maxLoans' => 6, 'color' => 'red']` with defaults
   `['loanDays' => 21, 'maxLoans' => 3]` so that user values win, missing ones come from the defaults, and unknown keys
   (`color`) are dropped.

<details>
<summary>Solution</summary>

```php
<?php

$defaults = ['loanDays' => 21, 'maxLoans' => 3];
$options = ['maxLoans' => 6, 'color' => 'red'];

$config = array_intersect_key($options, $defaults) + $defaults;
print_r($config);
```

```text
Array
(
    [maxLoans] => 6
    [loanDays] => 21
)
```

`array_intersect_key()` keeps only option keys that also exist in the defaults; `+` then fills in the missing ones from
the right-hand side. If the key order matters, use `array_replace($defaults, array_intersect_key($options, $defaults))`,
which keeps the defaults' order.

</details>

3. **Top borrowers.** Given a list of loans `[['member' => 'Ada', 'title' => 'Dune'], ['member' => 'Alan', ...], ...]`,
   print the members ordered by number of loans (most first), with ties broken alphabetically.

<details>
<summary>Solution</summary>

```php
<?php

$loans = [
    ['member' => 'Ada', 'title' => 'Dune'],
    ['member' => 'Alan', 'title' => 'Emma'],
    ['member' => 'Grace', 'title' => 'Kindred'],
    ['member' => 'Ada', 'title' => 'Persuasion'],
    ['member' => 'Grace', 'title' => 'Beloved'],
    ['member' => 'Ada', 'title' => 'Emma'],
];

$counts = array_count_values(array_column($loans, 'member'));

uksort($counts, fn ($a, $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

foreach ($counts as $member => $n) {
    echo "$member: $n\n";
}
```

```text
Ada: 3
Grace: 2
Alan: 1
```

The comparison puts `$counts[$b]` before `$counts[$a]` for descending counts, and `$a` before `$b` for ascending names.
`uksort()` keeps the keys (the member names). The arrow function can read `$counts` because arrow functions capture
outer variables automatically (chapter 6).

</details>

4. **Fix the bugs.** This code is supposed to print the titles of available books sorted alphabetically, as a
   comma-separated list. It has three bugs. Find and fix them.

   ```php
   <?php
   $copies = ['Dune' => 2, 'Kindred' => 0, 'Emma' => 1, 'Beloved' => 4];
   $available = array_filter($copies, fn ($n) => $n > 0);
   $titles = array_values($available);
   $sorted = sort($titles);
   echo implode(', ', $sorted), "\n";
   ```

<details>
<summary>Solution</summary>

1. The titles are the **keys**, but `array_values()` takes the values (the copy counts). Use `array_keys()`.
2. `sort()` sorts in place and returns `true`, so `$sorted` is `true`.
3. `implode()` therefore receives `true` instead of an array, which throws a `TypeError`.

```php
<?php

$copies = ['Dune' => 2, 'Kindred' => 0, 'Emma' => 1, 'Beloved' => 4];
$titles = array_keys(array_filter($copies, fn ($n) => $n > 0));
sort($titles, SORT_NATURAL | SORT_FLAG_CASE);
echo implode(', ', $titles), "\n";
```

```text
Beloved, Dune, Emma
```

</details>

5. **Index and join.** You have `$books` (each with `id` and `title`) and `$loans` (each with `bookId` and `member`).
   Produce a list of strings `"Ada has Dune"` for every loan, skipping loans whose book does not exist, without a
   nested loop.

<details>
<summary>Solution</summary>

```php
<?php

$books = [['id' => 1, 'title' => 'Dune'], ['id' => 3, 'title' => 'Emma']];
$loans = [
    ['bookId' => 3, 'member' => 'Ada'],
    ['bookId' => 9, 'member' => 'Alan'],
    ['bookId' => 1, 'member' => 'Grace'],
];

$titleById = array_column($books, 'title', 'id');   // [1 => 'Dune', 3 => 'Emma']

$lines = [];
foreach ($loans as ['bookId' => $id, 'member' => $member]) {
    if (isset($titleById[$id])) {
        $lines[] = "$member has {$titleById[$id]}";
    }
}

print_r($lines);
```

```text
Array
(
    [0] => Ada has Emma
    [1] => Grace has Dune
)
```

Building an index with `array_column()` turns an O(n×m) nested loop into O(n+m): one pass to index, one pass to join,
each lookup a constant-time `isset()`. This is the same idea as a hash join in a database.

</details>

6. **Implement `array_find()` yourself.** Write a function `find_first(array $items, callable $predicate)` that behaves
   like PHP 8.4's `array_find()`: it returns the first value for which `$predicate($value, $key)` is `true`, or `null`.
   (Chapter 6 covers functions in detail; you only need `function`, parameters and `return`.)

<details>
<summary>Solution</summary>

```php
<?php

function find_first(array $items, callable $predicate): mixed
{
    foreach ($items as $key => $value) {
        if ($predicate($value, $key) === true) {
            return $value;
        }
    }

    return null;
}

$years = ['Dune' => 1965, 'Emma' => 1815, 'Kindred' => 1979];

var_dump(find_first($years, fn ($year) => $year > 1970));                 // int(1979)
var_dump(find_first($years, fn ($year, $title) => str_starts_with($title, 'E'))); // int(1815)
var_dump(find_first($years, fn ($year) => $year > 2000));                 // NULL
```

Returning from inside the loop stops at the first match, which is the whole point compared with `array_filter()`. Note
that, like `array_find()`, this cannot distinguish "found a `null` value" from "found nothing"; `array_find_key()`
solves that, since keys are never `null`.

</details>

---
← Previous: [Strings and text](04-strings-and-text.md) | Next: [Functions and closures](06-functions-and-closures.md) →
