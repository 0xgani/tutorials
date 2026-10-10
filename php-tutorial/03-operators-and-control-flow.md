# Chapter 3 — Operators and control flow

## What you'll learn

- Arithmetic, assignment, string and logical operators, and the precedence traps among them
- The difference between loose `==` and strict `===` comparison, and why you should default to `===`
- The spaceship operator `<=>`, the ternary forms `?:`, and the null-coalescing operators `??` and `??=`
- Branching with `if`, `match` and `switch`, and why `match` is usually the better choice
- Loops: `while`, `do`/`while`, `for`, `foreach`, `break` and `continue`
- The alternative syntax used in templates
- A Bookshelf loan-status classifier

## Arithmetic and assignment

The arithmetic operators are the usual ones: `+`, `-`, `*`, `/`, `%` (remainder), `**` (power), and unary `-`. Every
binary operator has a compound assignment form:

```php
<?php

$fineCents = 0;
$fineCents += 25;      // 25
$fineCents *= 4;       // 100
$fineCents -= 10;      // 90
$fineCents %= 50;      // 40
$fineCents **= 2;      // 1600

$title = 'Dune';
$title .= ': Messiah'; // 'Dune: Messiah'  (string concatenation assignment)
```

`++` and `--` increment and decrement. `++$i` changes the variable and returns the new value; `$i++` returns the old
value and then changes it. Use them as standalone statements and the difference never matters.

```php
$i = 5;
echo $i++; // 5, and $i is now 6
echo ++$i; // 7
```

PHP has a historical quirk: `++` on a non-numeric string "increments" it alphabetically (`'a'` becomes `'b'`, `'Az'`
becomes `'Ba'`). PHP 8.5 deprecates this; if you really need it, `str_increment()` (PHP 8.3+) does it explicitly.

## String operators

`.` concatenates and `.=` appends. Concatenation has **lower precedence than `+` and `-`** (since PHP 8.0), so
arithmetic inside a concatenation happens first:

```php
echo 'Total: ' . 2 + 3 . "\n";  // Total: 5
```

Even so, parenthesize mixed expressions. A reader should not need the precedence table to understand your code.

## Comparison: `==` versus `===`

This is the most important section of the chapter.

- `$a === $b` (**identical**) is true when both have the **same type and the same value**. No conversion happens.
- `$a == $b` (**equal**) converts the operands to a common type first, following a set of rules called *loose
  comparison*, and then compares.

`!==` and `!=` (also written `<>`) are the negations. Here is what loose comparison does with some common pairs, from
a run on PHP 8.5:

| `$a` | `$b` | `$a == $b` | `$a === $b` |
|---|---|---|---|
| `'1'` | `'01'` | **true** | false |
| `'10'` | `'1e1'` | **true** | false |
| `100` | `'1e2'` | **true** | false |
| `null` | `false` | **true** | false |
| `[]` | `false` | **true** | false |
| `null` | `0` | **true** | false |
| `''` | `null` | **true** | false |
| `'0'` | `false` | **true** | false |
| `' 1'` | `1` | **true** | false |
| `1.0` | `1` | **true** | false |
| `0` | `'a'` | false | false |
| `'abc'` | `0` | false | false |
| `'abc'` | `'ABC'` | false | false |
| `NAN` | `NAN` | false | false |

The rules behind the table:

- Two **numeric strings** are compared as numbers: `'1' == '01'` and `'10' == '1e1'` are true. This has caused real
  security bugs, for example comparing password hashes that happened to look like `0e123...` (both "zero in
  scientific notation", therefore equal).
- A number and a numeric string are compared as numbers.
- A number and a **non-numeric** string are compared as strings (since PHP 8.0). Before PHP 8, `0 == 'a'` was `true`,
  because `'a'` was converted to `0`. If you read old code that relies on this, it now behaves differently.
- `null` and `false` are loosely equal to every falsy value.

**The rule for your own code: use `===` and `!==` by default.** Use `==` only when you deliberately want conversion, and
then write a comment saying so. The same advice applies to functions that compare: `in_array()` and `array_search()`
take a third argument `true` for strict comparison, and you should almost always pass it (chapter 5).

### Ordering: `<`, `>`, `<=`, `>=` and `<=>`

The ordering operators follow the same conversion rules as `==`:

```php
<?php

var_dump('apple' < 'banana'); // bool(true): strings compare byte by byte
var_dump('10' < '9');         // bool(false): both numeric, so compared as numbers
var_dump('10' < '9a');        // bool(true): '9a' is not numeric, so compared as strings
```

The **spaceship operator** `<=>` returns `-1`, `0` or `1` when the left side is smaller, equal or larger. It exists for
sorting callbacks (chapter 5):

```php
var_dump(1 <=> 2);           // int(-1)
var_dump('b' <=> 'a');       // int(1)
var_dump([1, 2] <=> [1, 3]); // int(-1): arrays compare element by element
```

### Comparing arrays

`==` on arrays is true when both have the same key/value pairs; `===` additionally requires the same order and types:

```php
<?php

$a = ['title' => 'Dune', 'year' => 1965];
$b = ['year' => 1965, 'title' => 'Dune'];

var_dump($a == $b);   // bool(true)
var_dump($a === $b);  // bool(false): different order
```

Objects are compared in chapter 9: `==` compares their properties, `===` asks "is this the same object?".

## Logical operators

| Operator | Meaning |
|---|---|
| `$a && $b` | true if both are truthy; `$b` is not evaluated if `$a` is falsy |
| `$a \|\| $b` | true if either is truthy; `$b` is not evaluated if `$a` is truthy |
| `!$a` | true if `$a` is falsy |
| `$a xor $b` | true if exactly one is truthy |

The result is always a `bool`, unlike some languages where `||` returns one of its operands. Short-circuiting is useful
for guards: `if ($user !== null && $user->isAdmin())`.

PHP also has the keywords `and` and `or`. They do the same thing but have **lower precedence than `=`**, which produces
a famous bug:

```php
<?php

$x = true and false;   // parsed as ($x = true) and false
var_dump($x);          // bool(true)!

$y = true && false;
var_dump($y);          // bool(false)
```

Do not use `and`/`or` in expressions. You will see them in old idioms like `$f = fopen(...) or die(...)`, which works
precisely because of the low precedence, but modern code throws exceptions instead (chapter 8).

## Conditional expressions

### The ternary operator

`condition ? valueIfTrue : valueIfFalse` is an expression, so you can use it inline:

```php
$label = $copies > 0 ? 'available' : 'all on loan';
```

Nested ternaries without parentheses are a compile error since PHP 8.0, because their meaning was confusing:

```text
PHP Fatal error:  Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)` in demo.php on line 2
```

If you need more than one condition, use `match` (below).

### The short ternary `?:`

`$a ?: $b` returns `$a` if it is truthy, otherwise `$b`:

```php
var_dump(null ?: 'empty');  // string(5) "empty"
var_dump(0 ?: 'zero');      // string(4) "zero"
var_dump('val' ?: 'none');  // string(3) "val"
```

Because it tests truthiness, `'0'` and `0` are replaced too. That is often not what you want.

### Null coalescing: `??` and `??=`

`$a ?? $b` returns `$a` if it **exists and is not null**, otherwise `$b`. It never warns about undefined variables or
missing array keys, which makes it the standard way to read optional input:

```php
<?php

$query = ['page' => '0'];

$page = $query['page'] ?? '1';    // '0': exists and is not null, so it is kept
$sort = $query['sort'] ?? 'title'; // 'title': the key is missing, no warning
```

Compare with `?:`, which would have replaced the legitimate `'0'`. `??` chains: `$a ?? $b ?? 'default'`.

`??=` assigns only if the left side is null or missing:

```php
<?php

$config = [];
$config['loanDays'] ??= 21;   // sets it
$config['loanDays'] ??= 99;   // already set, does nothing
var_dump($config['loanDays']); // int(21)
```

### The nullsafe operator `?->`

When a value may be `null` or an object, `$obj?->method()` calls the method only if `$obj` is not null, and otherwise
the whole chain evaluates to `null`. You will use it with objects from chapter 9:

```php
$city = $member?->address?->city ?? 'unknown';
```

## Other operators you will meet

- **Array union `+`** combines two arrays, keeping the left side's value for duplicate keys:
  `['a' => 1] + ['a' => 2, 'b' => 3]` is `['a' => 1, 'b' => 3]`. Chapter 5 compares it with `array_merge()`.
- **Bitwise** `&`, `|`, `^`, `~`, `<<`, `>>` work on integers. You mostly see them combining flags:
  `json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)`.
- **`instanceof`** tests whether an object is an instance of a class or interface (chapter 9).
- **Error suppression `@`** silences warnings from one expression: `@file_get_contents($path)`. Avoid it; it hides
  real problems and makes debugging miserable. Check conditions explicitly or handle errors (chapter 8).
- **Execution operator** (backticks, `` `ls` ``) runs a shell command. It is deprecated in PHP 8.5; use
  `shell_exec()` or, better, `proc_open()` with an argument array (chapter 14).

### Precedence, briefly

From highest to lowest, the groups you need to remember:

```text
clone new                       highest
**                              (right-associative: 2 ** 3 ** 2 is 2 ** 9)
++ -- ~ (int) (string) ... @    unary
instanceof
!
* / %
+ -
<< >>
.                               (below + and - since PHP 8.0)
|>                              pipe (PHP 8.5+, chapter 6)
< <= > >=
== != === !== <> <=>
&  ^  |
&&
||
??                              (right-associative)
? :                             ternary
= += -= .= ??= ...              assignment
yield from, yield, print
and
xor
or                              lowest
```

Two consequences: `-3 ** 2` is `-9` (the power binds tighter than the minus), and `!$a instanceof B` means
`!($a instanceof B)`. When in doubt, add parentheses.

## Branching

### `if`, `elseif`, `else`

```php
<?php

$copies = 0;

if ($copies > 1) {
    echo "Several copies available\n";
} elseif ($copies === 1) {
    echo "Last copy!\n";
} else {
    echo "All on loan\n";
}
```

Always use braces, even for one-line bodies. `elseif` and `else if` both work; PSR-12 and PER style prefer `elseif`.

### `match` (PHP 8.0+)

`match` is an expression that compares a value against a list of arms and returns the result of the first one that
matches:

```php
<?php

$genre = 'sf';

$shelf = match ($genre) {
    'sf', 'fantasy' => 'Shelf A',
    'crime' => 'Shelf B',
    'history', 'biography' => 'Shelf C',
    default => 'Unsorted',
};

echo $shelf, "\n"; // Shelf A
```

What makes `match` good:

- It compares with **`===`**, so `match ('2') { 2 => ... }` does not match.
- It is an **expression**: it returns a value you can assign or return.
- There is **no fall-through**: each arm is independent, and there are no `break` statements to forget.
- It is **exhaustive**: if no arm matches and there is no `default`, it throws an `UnhandledMatchError` instead of
  silently doing nothing.

```php
<?php

$code = 99;

echo match ($code) {
    1 => 'one',
    2 => 'two',
};
```

```text
PHP Fatal error:  Uncaught UnhandledMatchError: Unhandled match case 99 in demo.php:5
```

Leaving out `default` on purpose is a good technique: when someone adds a new value, the program fails loudly instead
of producing a wrong answer. With enums (chapter 11) static analysers even tell you about the missing arm before the
code runs.

Each arm's right-hand side is a single expression. For multiple statements, call a function. An arm can also `throw`,
since `throw` is an expression in PHP 8:

```php
$days = match ($memberType) {
    'standard' => 21,
    'premium' => 42,
    default => throw new InvalidArgumentException("Unknown member type: $memberType"),
};
```

#### `match (true)` for ranges

To branch on conditions rather than values, match against `true`. The first arm whose condition is `true` wins. This is
the first piece of Bookshelf logic, classifying a loan by the number of days left until it is due:

```php
<?php

$daysLeft = -3;

$status = match (true) {
    $daysLeft < 0 => 'overdue',
    $daysLeft === 0 => 'due today',
    $daysLeft <= 3 => 'due soon',
    default => 'on loan',
};

echo $status, "\n"; // overdue
```

Order matters: arms are tested top to bottom, so put the narrow conditions first.

### `switch`

`switch` is the older statement form. You will read a lot of it, so know its two traps:

```php
<?php

switch ('apple') {
    case 0:
        echo "zero\n";
        break;
    default:
        echo "default\n";
}
```

1. **It compares with `==`.** (In PHP 7 the example above printed `zero`, because `'apple' == 0` was true. In PHP 8 it
   prints `default`, but `'1' == '01'`-style surprises remain.)
2. **It falls through.** Without `break`, execution continues into the next `case`. Sometimes this is intended
   (stacked `case 'a': case 'b':` labels); usually a missing `break` is a bug.

Prefer `match` in new code. `switch` is still the right tool when each branch runs several statements and does not
produce a value, though often a `match` that calls functions reads better. (PHP 8.5 deprecates ending a `case` with
`;` instead of `:`, an old syntax variant you may meet in legacy code.)

## Loops

### `while` and `do`/`while`

```php
<?php

$copies = 3;
while ($copies > 0) {
    echo "Lending a copy, $copies left\n";
    $copies--;
}

do {
    echo "Runs at least once\n";
} while (false);
```

### `for`

```php
<?php

for ($day = 1; $day <= 3; $day++) {
    echo "Day $day\n";
}
```

The three parts (initialize, condition, step) can each hold comma-separated expressions:
`for ($i = 0, $j = 10; $i < $j; $i += 3, $j -= 3)`. When iterating over an array, prefer `foreach`, and if you do use
`for` with `count()`, compute the count once: `for ($i = 0, $n = count($items); $i < $n; $i++)`.

### `foreach`

`foreach` is the loop you will use most. It iterates over arrays and over iterable objects (chapter 13):

```php
<?php

$books = ['Dune', 'Kindred', 'Emma'];

foreach ($books as $title) {
    echo $title, "\n";
}

$loanDays = ['standard' => 21, 'premium' => 42];

foreach ($loanDays as $type => $days) {
    echo "$type: $days days\n";
}
```

```text
Dune
Kindred
Emma
standard: 21 days
premium: 42 days
```

`foreach` works on a **copy** of the array (copy-on-write makes this cheap), so changing `$title` inside the loop does
not change the array. To modify elements in place, iterate by reference with `&`:

```php
<?php

$books = ['Dune', 'Kindred', 'Emma'];

foreach ($books as &$title) {
    $title = strtoupper($title);
}
unset($title); // important: break the reference

print_r($books);
```

```text
Array
(
    [0] => DUNE
    [1] => KINDRED
    [2] => EMMA
)
```

The `unset($title)` is not decoration. After the loop, `$title` is still a reference to the **last element**. If you
reuse the name in a later `foreach`, each iteration writes into that last element:

```php
<?php

$books = ['Dune', 'Kindred', 'Emma'];
foreach ($books as &$title) {
    $title = strtoupper($title);
}
// forgot unset($title)

foreach ($books as $title) {
    // each iteration assigns to $title, which is still $books[2]
}
print_r($books);
```

```text
Array
(
    [0] => DUNE
    [1] => KINDRED
    [2] => KINDRED
)
```

This bug is famous enough to have its own name in PHP folklore. Safer alternatives are to write `$books[$i] = ...`
using the key, or to build a new array (`array_map()`, chapter 5).

You can destructure inside `foreach` (chapter 5 covers destructuring):

```php
foreach ([['Dune', 1965], ['Emma', 1815]] as [$title, $year]) {
    echo "$title ($year)\n";
}
```

### `break` and `continue`

`break` leaves the loop; `continue` skips to the next iteration. Both accept a number that says how many nested loops
to affect:

```php
<?php

$shelves = [['Dune', 'SKIP', 'Emma'], ['Kindred', 'STOP', 'Beloved']];

foreach ($shelves as $shelf) {
    foreach ($shelf as $title) {
        if ($title === 'SKIP') {
            continue 2;  // next shelf
        }
        if ($title === 'STOP') {
            break 2;     // leave both loops
        }
        echo $title, "\n";
    }
}
```

```text
Dune
Kindred
```

Deeply nested loops with numbered `break`s are hard to read. Extracting the inner loop into a function that returns
early is usually clearer.

## Alternative syntax for templates

For `if`, `foreach`, `for`, `while` and `switch`, PHP has a colon-based syntax ending in `endif;`, `endforeach;`,
`endfor;`, `endwhile;` and `endswitch;`. It exists for templates, where a closing `endforeach` is far easier to match
than a `}` buried in HTML:

```php
<?php $books = ['Dune' => 2, 'Kindred' => 0]; ?>
<ul>
<?php foreach ($books as $title => $copies): ?>
  <li>
    <?= htmlspecialchars($title) ?>
    <?php if ($copies === 0): ?>
      <em>(all on loan)</em>
    <?php else: ?>
      (<?= $copies ?> available)
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>
```

Use braces in pure PHP code and the alternative syntax in templates. Do not mix them in one construct.

## Bookshelf: a loan report

Combining what you have so far, here is a script that prints a status line for each loan. Each loan is a small array
(chapter 5 explains the `['key' => value]` syntax).

```php
<?php

$finePerDayCents = 25;
$loans = [
    ['title' => 'Dune', 'member' => 'Ada', 'daysLeft' => 5],
    ['title' => 'Kindred', 'member' => 'Grace', 'daysLeft' => 0],
    ['title' => 'Emma', 'member' => 'Alan', 'daysLeft' => -4],
    ['title' => 'Beloved', 'member' => 'Ada', 'daysLeft' => 2],
];

$totalFineCents = 0;

foreach ($loans as $loan) {
    $daysLeft = $loan['daysLeft'];

    $status = match (true) {
        $daysLeft < 0 => 'OVERDUE',
        $daysLeft === 0 => 'due today',
        $daysLeft <= 3 => 'due soon',
        default => 'on loan',
    };

    $fineCents = $daysLeft < 0 ? -$daysLeft * $finePerDayCents : 0;
    $totalFineCents += $fineCents;

    echo str_pad($loan['title'], 10), str_pad($loan['member'], 8), $status;
    if ($fineCents > 0) {
        echo ' (fine: ', number_format($fineCents / 100, 2), ')';
    }
    echo "\n";
}

echo 'Total fines: ', number_format($totalFineCents / 100, 2), "\n";
```

```text
Dune      Ada     on loan
Kindred   Grace   due today
Emma      Alan    OVERDUE (fine: 1.00)
Beloved   Ada     due soon
Total fines: 1.00
```

`str_pad()` pads a string to a fixed width, which lines up the columns (chapter 4).

## Key takeaways

- Default to `===` and `!==`. Loose `==` converts types first and has surprising rules, especially with numeric
  strings.
- `??` reads "if it exists and is not null"; it never warns and is the right way to read optional keys. `?:` tests
  truthiness and replaces `'0'` and `0` too.
- `&&`/`||`, not `and`/`or`: the keywords bind more loosely than `=`.
- Prefer `match` to `switch`: strict comparison, no fall-through, returns a value, and fails loudly on unhandled
  values. `match (true)` handles ranges.
- `foreach` is the main loop. After `foreach ($a as &$v)`, always `unset($v)`.
- Templates use the alternative syntax (`foreach (...):` ... `endforeach;`).

## Exercises

1. **Predict the comparisons.** Without running them, write down the result of each, then check with `var_dump()`:
   `'abc' == 0`, `'1' == '1.0'`, `null == []`, `'0' == ''`, `0 == ''`, `'' == null`.

<details>
<summary>Solution</summary>

```php
<?php

var_dump('abc' == 0);   // false: non-numeric string vs number compares as strings ('abc' vs '0') in PHP 8
var_dump('1' == '1.0'); // true: both numeric strings, compared as numbers
var_dump(null == []);   // true: null equals every falsy value
var_dump('0' == '');    // false: two strings, '0' is numeric but '' is not, so compared as strings
var_dump(0 == '');      // false in PHP 8: '' is not numeric, so 0 is compared as the string '0'
var_dump('' == null);   // true: null is converted to ''
```

`0 == ''` was `true` in PHP 7. These rules are exactly why `===` is the safe default.

</details>

2. **FizzBuzz with `match`.** Print the numbers 1 to 15, but print `Fizz` for multiples of 3, `Buzz` for multiples of 5
   and `FizzBuzz` for multiples of both. Use `match (true)`.

<details>
<summary>Solution</summary>

```php
<?php

for ($i = 1; $i <= 15; $i++) {
    echo match (true) {
        $i % 15 === 0 => 'FizzBuzz',
        $i % 3 === 0 => 'Fizz',
        $i % 5 === 0 => 'Buzz',
        default => $i,
    }, "\n";
}
```

The `% 15` arm must come first, because `match` stops at the first true arm.

</details>

3. **Safe defaults.** Given `$input = ['limit' => '0', 'name' => '']`, compute `$limit` defaulting to `'10'` and
   `$name` defaulting to `'guest'`, so that an explicit `'0'` limit is kept but an empty name is replaced. Which
   operator do you use for each, and why?

<details>
<summary>Solution</summary>

```php
<?php

$input = ['limit' => '0', 'name' => ''];

$limit = $input['limit'] ?? '10';    // '0' is a real value: keep it
$name = ($input['name'] ?? '') ?: 'guest'; // missing or empty: use 'guest'

var_dump($limit, $name); // string(1) "0" string(5) "guest"
```

`??` only replaces missing or `null` values, so the explicit `'0'` survives. For the name, an empty string should also
be replaced; `?:` does that, but on its own it would warn if the key were missing, so `?? ''` first makes the key
lookup safe.

</details>

4. **The reference bug.** The following code is meant to add 10% to each price and then print them. Explain the
   output, then fix it two different ways.

   ```php
   <?php
   $prices = [100, 200, 300];
   foreach ($prices as &$p) {
       $p = (int) round($p * 1.1);
   }
   foreach ($prices as $p) {
       echo $p, ' ';
   }
   echo "\n";
   print_r($prices);
   ```

<details>
<summary>Solution</summary>

The first loop correctly produces `110, 220, 330`, but leaves `$p` as a reference to `$prices[2]`. The second loop
assigns each element to `$p`, i.e. to `$prices[2]`: first `110`, then `220`, then `$prices[2]` itself (now `220`). It
prints `110 220 220` and the array ends as `[110, 220, 220]`.

Fix 1: `unset($p);` after the first loop.

Fix 2: avoid references altogether by writing through the key:

```php
<?php

$prices = [100, 200, 300];
foreach ($prices as $i => $p) {
    $prices[$i] = (int) round($p * 1.1);
}
echo implode(' ', $prices), "\n"; // 110 220 330
```

(Chapter 5 shows a third fix, `array_map()`, which builds a new array.)

</details>

5. **Loan rules.** Bookshelf members are `'standard'`, `'premium'` or `'staff'`. Standard members may borrow 3 books
   for 21 days, premium 6 books for 42 days, staff 10 books for 60 days. Write a script with a `$memberType` variable
   that uses two `match` expressions to compute `$maxLoans` and `$loanDays`, throwing an `InvalidArgumentException` for
   an unknown type. Test it with `'premium'` and `'guest'`.

<details>
<summary>Solution</summary>

```php
<?php

$memberType = 'premium';

$maxLoans = match ($memberType) {
    'standard' => 3,
    'premium' => 6,
    'staff' => 10,
    default => throw new InvalidArgumentException("Unknown member type: $memberType"),
};

$loanDays = match ($memberType) {
    'standard' => 21,
    'premium' => 42,
    'staff' => 60,
    default => throw new InvalidArgumentException("Unknown member type: $memberType"),
};

echo "$memberType: $maxLoans books for $loanDays days\n";
```

```text
premium: 6 books for 42 days
```

With `'guest'`:

```text
PHP Fatal error:  Uncaught InvalidArgumentException: Unknown member type: guest in rules.php:9
```

The duplication between the two `match` blocks is a hint that this data wants to be one table (an array, chapter 5) or
an enum with methods (chapter 11).

</details>

6. **Multiplication table.** Print a 5×5 multiplication table with aligned columns using nested `for` loops, but stop
   the whole table as soon as a product exceeds 15 (use `break 2`). Then rewrite it so it skips (rather than stops at)
   products above 15 on each row, using `continue`.

<details>
<summary>Solution</summary>

```php
<?php

for ($row = 1; $row <= 5; $row++) {
    for ($col = 1; $col <= 5; $col++) {
        $product = $row * $col;
        if ($product > 15) {
            echo "\n";
            break 2;
        }
        echo str_pad((string) $product, 4, ' ', STR_PAD_LEFT);
    }
    echo "\n";
}
```

```text
   1   2   3   4   5
   2   4   6   8  10
   3   6   9  12  15
   4   8  12
```

The skipping version replaces the `if` body with `continue;` (which moves on to the next column of the same row):

```php
<?php

for ($row = 1; $row <= 5; $row++) {
    for ($col = 1; $col <= 5; $col++) {
        $product = $row * $col;
        if ($product > 15) {
            continue;
        }
        echo str_pad((string) $product, 4, ' ', STR_PAD_LEFT);
    }
    echo "\n";
}
```

```text
   1   2   3   4   5
   2   4   6   8  10
   3   6   9  12  15
   4   8  12
   5  10  15
```

</details>

---
← Previous: [Variables, types and values](02-variables-types-and-values.md) | Next: [Strings and text](04-strings-and-text.md) →
