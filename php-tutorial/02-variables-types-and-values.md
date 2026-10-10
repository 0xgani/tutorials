# Chapter 2 — Variables, types and values

## What you'll learn

- How variables work: naming, assignment, undefined variables, `isset()`, `unset()` and `empty()`
- PHP's types: `bool`, `int`, `float`, `string`, `array`, `object`, `null`, and how to inspect them
- How integers and floats behave at their limits, and why money is never stored in a float
- Truthiness: which values count as `false`
- Type juggling: what PHP does when you mix types, and how PHP 8 made it much stricter
- Explicit casting and conversion functions
- Constants and the magic constants
- Assignment by value versus by reference

## Variables

A variable is a name that starts with `$`, followed by a letter or underscore, then letters, digits or underscores.
Names are **case-sensitive**: `$title` and `$Title` are different variables. There is no declaration keyword; a
variable comes into existence the first time you assign to it.

```php
<?php

$title = 'Dune';
$author = 'Frank Herbert';
$year = 1965;
$pages = 412;
$available = true;
$isbn = null;
```

The convention in modern PHP code is `$camelCase` for variables (`$dueDate`, `$memberCount`). Older code and
WordPress use `$snake_case`; follow whatever the project you are in uses.

A variable has no fixed type. Its **value** has a type, and assigning a different value changes it:

```php
$x = 42;      // int
$x = 'hello'; // now a string
```

This is legal, but it makes code hard to follow. Chapter 7 shows how type declarations on functions and properties
bring back the guarantees that a variable alone does not give you.

### Undefined variables

Reading a variable that was never assigned produces a **warning** and evaluates to `null`:

```php
<?php

echo $missing;
```

```text
PHP Warning:  Undefined variable $missing in demo.php on line 3
```

The script keeps running after a warning. That is dangerous: a typo in a variable name (`$tilte`) quietly becomes
`null` and the bug shows up somewhere else. Treat every warning as a bug. Chapter 8 shows how to turn warnings into
exceptions so they cannot be ignored, and chapter 20 introduces static analysis, which catches undefined variables
before the code even runs.

### `isset()`, `unset()` and `empty()`

These three language constructs are used constantly:

| Construct | Returns `true` when | Warns if undefined? |
|---|---|---|
| `isset($x)` | `$x` exists **and is not `null`** | No |
| `empty($x)` | `$x` does not exist, or its value is falsy (see below) | No |
| `is_null($x)` | `$x` is `null` | Yes |

`unset($x)` destroys the variable; afterwards `isset($x)` is `false`.

```php
<?php

$n = null;
var_dump(isset($n));        // bool(false): exists, but is null
var_dump(isset($nothing));  // bool(false): does not exist, no warning
var_dump(is_null($n));      // bool(true)

$pages = 412;
unset($pages);
var_dump(isset($pages));    // bool(false)
```

`isset()` treating `null` as "not set" surprises people coming from other languages, but it is consistent throughout
PHP: `null` means "no value". The null-coalescing operator `??`, covered in chapter 3, builds on the same idea:

```php
$label = $nickname ?? 'anonymous'; // 'anonymous' if $nickname is undefined or null
```

### Variable variables (and why to avoid them)

PHP lets you use the *value* of one variable as the *name* of another:

```php
$field = 'title';
$$field = 'Dune';   // assigns to $title
echo $title;        // Dune
```

You will see this in old code. Avoid it in new code: tools cannot follow it, and an array (`$book['title']`) does the
same job clearly.

## Inspecting values

Three functions answer "what is this?":

```php
<?php

$title = 'Dune';
$year = 1965;
$price = 9.99;
$available = true;
$isbn = null;

var_dump($title, $year, $price, $available, $isbn);
```

```text
string(4) "Dune"
int(1965)
float(9.99)
bool(true)
NULL
```

- **`var_dump()`** prints the type and value of each argument, recursively for arrays and objects. It is the debugging
  tool you will use most. Note that `string(4)` is the length in **bytes**, not characters (chapter 4).
- **`get_debug_type($x)`** (PHP 8.0+) returns a type name as you would write it in code: `int`, `float`, `string`,
  `bool`, `null`, `array`, or a class name such as `DateTimeImmutable`.
- **`gettype($x)`** is the older function; it returns historical names such as `integer`, `double` and `NULL`. Prefer
  `get_debug_type()`.

`print_r()` prints arrays and objects in a more compact format without types, and `var_export()` prints valid PHP code
that would recreate the value. Both take a second argument `true` to return a string instead of printing.

```php
print_r(['Dune', 1965]);
var_export(['Dune', 1965]);
```

```text
Array
(
    [0] => Dune
    [1] => 1965
)
array (
  0 => 'Dune',
  1 => 1965,
)
```

For type checks in code, use the `is_*` functions: `is_int()`, `is_float()`, `is_string()`, `is_bool()`,
`is_array()`, `is_object()`, `is_null()`, `is_numeric()`, `is_scalar()`, `is_callable()`, `is_iterable()`. For classes,
use `instanceof` (chapter 9).

## The types

PHP has ten built-in types plus classes:

```text
 scalar ─┬─ bool      true, false
         ├─ int       42, -7, 0x1A, 0b101, 1_000_000
         ├─ float     3.14, 1.5e3, NAN, INF
         └─ string    'Dune', "Hello\n"
 compound ┬─ array     ['a', 'b'], ['title' => 'Dune']
          ├─ object    new DateTimeImmutable(), instances of your classes
          ├─ callable  anything that can be called (a function name, a closure, ...)
          └─ iterable  an array or a Traversable object
 special ─┬─ null      null
          └─ resource  a handle to an external thing (an open file, a socket)
```

`callable` and `iterable` are *pseudo-types*: they describe a capability that values of other types have. Resources are
a legacy type; PHP has been steadily replacing them with proper objects (for example, `curl_init()` returns a
`CurlHandle` object since PHP 8.0).

### Booleans

`true` and `false`, case-insensitive (`TRUE` works, but lowercase is the standard style). Comparison operators and
functions such as `is_int()` return booleans.

### Integers

Integers are signed and 64 bits wide on every modern platform:

```php
<?php

var_dump(PHP_INT_MAX, PHP_INT_MIN, PHP_INT_SIZE);
var_dump(0x1A, 0o17, 0b101, 1_000_000);
```

```text
int(9223372036854775807)
int(-9223372036854775808)
int(8)
int(26)
int(15)
int(5)
int(1000000)
```

- Hexadecimal `0x`, octal `0o` (PHP 8.1+; the older form `017` also means octal, which is a trap if you ever write a
  number with a leading zero), binary `0b`.
- Underscores between digits (`1_000_000`) are ignored and make large literals readable.
- **Overflow does not wrap.** An integer operation that exceeds `PHP_INT_MAX` silently produces a `float`:

```php
var_dump(PHP_INT_MAX + 1);  // float(9.223372036854776E+18)
```

The result has lost precision. For arbitrarily large integers, use the `bcmath` or `gmp` extensions (PHP 8.4 added an
object API, `BcMath\Number`, with normal operators).

Division with `/` returns a float unless both operands divide exactly:

```php
<?php

var_dump(7 / 2);          // float(3.5)
var_dump(6 / 2);          // int(3)
var_dump(intdiv(7, 2));   // int(3), always rounds toward zero
var_dump(7 % 3);          // int(1)
var_dump(-7 % 3);         // int(-1): the sign follows the left operand
var_dump(2 ** 10);        // int(1024)
```

Dividing by zero throws a `DivisionByZeroError` (for both `/` and `%`). You will learn to catch errors in chapter 8.

### Floats

Floats are IEEE 754 double-precision numbers, the same as in almost every language. They are **approximations**:

```php
<?php

var_dump(0.1 + 0.2);
var_dump(0.1 + 0.2 == 0.3);
echo 0.1 + 0.2, "\n";
```

```text
float(0.30000000000000004)
bool(false)
0.3
```

`echo` rounds floats for display (to the `precision` setting, 14 significant digits), which hides the error;
`var_dump()` shows the shortest representation that round-trips exactly, which reveals it. Practical rules:

- **Never compare floats with `==`.** Compare with a tolerance: `abs($a - $b) < 0.00001`.
- **Never store money in a float.** Rounding errors accumulate and someone's balance ends up off by a cent. Store money
  as an integer number of the smallest unit (cents), or use `bcmath` with decimal strings. Bookshelf stores fines in
  cents from this chapter on.
- Round only for display: `round($x, 2)`, `number_format($x, 2)`.

```php
<?php

var_dump(round(3.14159, 3));      // float(3.142)
var_dump(round(2.5), round(-2.5)); // float(3) float(-3): halves round away from zero
var_dump(floor(-2.5), ceil(2.1)); // float(-3) float(3)
echo number_format(1234567.891, 2), "\n";           // 1,234,567.89
echo number_format(1234567.891, 2, ',', '.'), "\n"; // 1.234.567,89
```

Note that `round()`, `floor()` and `ceil()` return floats. Floats also have special values: `INF`, `-INF` and `NAN`
("not a number"). `fdiv(1, 0)` returns `INF` instead of throwing, and `is_nan()`/`is_finite()` test for them. `NAN` is
not equal to anything, not even itself.

### Strings

A string is a sequence of **bytes**. Single quotes are literal; double quotes interpret escape sequences and
variables:

```php
$title = 'Dune';
echo 'Title: $title\n';  // Title: $title\n
echo "Title: $title\n";  // Title: Dune  (followed by a newline)
```

`.` concatenates strings. Chapter 4 is entirely about strings, including UTF-8, which needs care because the basic
functions count bytes.

### Null

`null` is the only value of type `null`. A variable is `null` when it was assigned `null`, was never assigned, or was
`unset()`. Functions that "return nothing" return `null`.

### Arrays and objects

Arrays are PHP's universal data structure: ordered maps from keys to values, used as lists, dictionaries, sets, stacks
and records. They get their own chapter (chapter 5). Objects are instances of classes (chapter 9).

## Truthiness

When a value is used where a boolean is expected (an `if` condition, `!`, `&&`), PHP converts it. These values are
**falsy**; everything else is truthy:

| Falsy value | Type |
|---|---|
| `false` | bool |
| `0`, `-0` | int |
| `0.0`, `-0.0` | float |
| `''` (empty string) | string |
| `'0'` | string |
| `[]` (empty array) | array |
| `null` | null |

Three surprises are worth memorizing:

```php
var_dump((bool) '0');    // bool(false): the string "0" is falsy
var_dump((bool) '0.0');  // bool(true):  but "0.0" is truthy
var_dump((bool) ' ');    // bool(true):  a space is not empty
```

All objects are truthy, even "empty" ones. The `'0'` rule means that `if ($input)` is the wrong test when a user may
legitimately type `0`. Be explicit: `if ($input !== '')`, `if ($count > 0)`, `if ($user !== null)`.

## Type juggling

PHP converts values automatically when an operator needs a particular type. `+` needs numbers, `.` needs strings:

```php
<?php

var_dump('10' + 5);     // int(15)
var_dump('1.5' + 1);    // float(2.5)
var_dump('1e3' + 0);    // float(1000)
var_dump(' 42' + 0);    // int(42): leading and trailing whitespace is allowed
var_dump('abc' . 1);    // string(4) "abc1"
var_dump(1 . 2);        // string(2) "12"
var_dump(true + true);  // int(2)
var_dump(null + 5);     // int(5)
```

A string is **numeric** if it looks like an int or float, optionally surrounded by whitespace: `'42'`, `' 4.2 '`,
`'1e3'`. `is_numeric()` tests for exactly this. PHP 8.0 tightened what happens with strings that are not numeric:

```php
<?php

var_dump('10 apples' + 5);
var_dump('apples' + 5);
```

```text
PHP Warning:  A non-numeric value encountered in demo.php on line 3
int(15)
PHP Fatal error:  Uncaught TypeError: Unsupported operand types: string + int in demo.php:4
Stack trace:
#0 {main}
  thrown in demo.php on line 4
```

- A *leading-numeric* string (`'10 apples'`) is used as `10` with a warning.
- A completely non-numeric string (`'apples'`) in arithmetic throws a `TypeError`.

In PHP 7, `'apples' + 5` silently returned `5`. If you maintain old code, that difference is one of the most common
PHP 8 upgrade breakages.

The comparison operator `==` also juggles types, with its own rules. It is covered in chapter 3 together with `===`,
which never juggles.

## Casting and conversion

To convert explicitly, use a cast: `(int)`, `(float)`, `(string)`, `(bool)`, `(array)`, `(object)`.

```php
<?php

var_dump((int) '12abc');   // int(12): reads leading digits, no warning
var_dump((int) 'abc');     // int(0)
var_dump((int) 12.9);      // int(12): truncates toward zero
var_dump((int) -12.9);     // int(-12)
var_dump((int) '1e3');     // int(1000)
var_dump((float) '3.14xyz'); // float(3.14)
var_dump((string) 1.0);    // string(1) "1"
var_dump((string) false);  // string(0) ""
var_dump((string) null);   // string(0) ""
var_dump((array) 'x');     // array(1) { [0]=> string(1) "x" }
```

Casts never fail and never warn about garbage input (with one exception below), which makes them the wrong tool for
validating user input: `(int) 'abc'` is `0`, indistinguishable from a real zero. Chapter 16 uses `filter_var()` to
validate input properly:

```php
var_dump(filter_var('42', FILTER_VALIDATE_INT));   // int(42)
var_dump(filter_var('4x2', FILTER_VALIDATE_INT));  // bool(false)
```

Other conversion helpers:

- `intval($s, $base)` parses in a given base: `intval('101', 2)` is `5`, `intval('0x1A', 16)` is `26`, and base `0`
  detects `0x`/`0`/`0b` prefixes.
- `floatval()`, `strval()`, `boolval()` are function forms of the casts, handy as callbacks (chapter 6).
- `settype($var, 'int')` converts a variable in place. Rarely needed.

**PHP 8.5+:** two conversions now warn because they were silent data loss:

```php
var_dump((int) 1e20);
```

```text
Warning: The float 1.0E+20 is not representable as an int, cast occurred in demo.php on line 3
int(7766279631452241920)
```

Casting `NAN` to another type also warns. PHP 8.5 also deprecates the long cast names `(integer)`, `(boolean)`,
`(double)` and `(binary)`; use `(int)`, `(bool)`, `(float)` and `(string)`.

## Constants

A constant is a name for a value that cannot change. There are two ways to define one:

```php
<?php

const LOAN_DAYS = 21;          // compile-time, at the top level of a file or in a class
define('MAX_LOANS', 5);        // runtime function call; works inside if blocks too

echo LOAN_DAYS, ' ', MAX_LOANS, "\n";  // 21 5
var_dump(defined('LOAN_DAYS'));        // bool(true)
echo constant('MAX_LOANS'), "\n";      // 5: look up a constant by name
```

- Constants have no `$`, are global, and are conventionally `UPPER_SNAKE_CASE`.
- They can hold scalars, `null`, arrays and (since PHP 8.1) enum cases and `new` objects in some places.
- Prefer `const`: it is resolved when the file is compiled, works with namespaces and is visible to tools. Use
  `define()` only when the name or condition must be decided at runtime.
- Defining the same constant twice gives a warning and keeps the first value (PHP 8.5 adds "this will be an error in
  PHP 9" to the message).

Most constants in real code live inside classes (`Loan::DEFAULT_DAYS`), which chapter 9 covers.

### Predefined and magic constants

PHP defines many constants: `PHP_VERSION`, `PHP_OS_FAMILY`, `PHP_EOL`, `PHP_INT_MAX`, `PHP_FLOAT_EPSILON`, `E_ALL`
and every extension's options. **Magic constants** look like constants but change depending on where they appear:

| Magic constant | Value |
|---|---|
| `__LINE__` | Current line number |
| `__FILE__` | Full path of the current file |
| `__DIR__` | Directory of the current file (same as `dirname(__FILE__)`) |
| `__FUNCTION__` | Current function name |
| `__CLASS__`, `__METHOD__` | Current class / class and method name |
| `__NAMESPACE__` | Current namespace (chapter 12) |

`__DIR__` is the one you will use most: `require __DIR__ . '/config.php'` loads a file relative to the current file,
independent of the directory the script was started from.

## Values, copies and references

Assigning one variable to another copies the value:

```php
<?php

$a = 1;
$b = $a;
$b++;
var_dump($a, $b); // int(1) int(2): $a is unaffected
```

This is true for strings and arrays as well, and it is cheap: PHP uses **copy-on-write**. `$b = $a` does not
duplicate a big array; both variables point to the same value until one of them is modified. Chapter 5 shows this with
arrays and chapter 21 explains the reference counting underneath.

Objects behave differently: a variable holds a *handle* to an object, so copying the variable copies the handle, and
both variables refer to the same object. Chapter 9 explains this.

### References

`=&` makes two names refer to **the same variable**:

```php
<?php

$a = 1;
$c = &$a;   // $c is now another name for $a
$c = 99;
var_dump($a); // int(99)

unset($c);    // removes the name $c; $a keeps its value
var_dump($a); // int(99)
```

References exist and you will meet them in `foreach` loops and function parameters (chapters 3 and 6), but in
everyday code they are rare and usually a design smell: they make it hard to see what changes what. Return new values
instead of modifying variables through references.

## Bookshelf: one book in variables

Here is the data for one Bookshelf loan, written with plain variables. Note the fine in integer cents:

```php
<?php

$title = 'Dune';
$author = 'Frank Herbert';
$year = 1965;
$daysOverdue = 4;
$finePerDayCents = 25;          // 25 cents per day

$fineCents = $daysOverdue * $finePerDayCents;
$fine = number_format($fineCents / 100, 2);

echo "$title ($year) by $author\n";
echo "Overdue by $daysOverdue days, fine: \$$fine\n";
var_dump($fineCents);
```

```text
Dune (1965) by Frank Herbert
Overdue by 4 days, fine: $1.00
int(100)
```

The arithmetic happens in integers; only the final display divides by 100. `\$` prints a literal dollar sign inside
double quotes. In the next chapters this grows into arrays of books (chapter 5), functions (chapter 6), and finally
classes (chapter 9).

## Key takeaways

- Variables start with `$`, are case-sensitive and need no declaration. Reading an undefined one warns and gives
  `null`; treat warnings as bugs.
- `isset()` means "exists and is not `null`"; `empty()` means "missing or falsy". Neither warns.
- Use `var_dump()` and `get_debug_type()` to see what a value really is.
- Integers are 64-bit and overflow into floats. Floats are approximate: never compare them with `==` and never store
  money in them.
- Falsy values: `false`, `0`, `0.0`, `''`, `'0'`, `[]`, `null`. The string `'0'` is the classic trap.
- PHP 8 juggling is strict-ish: non-numeric strings in arithmetic throw `TypeError`. Casts never fail, so they are not
  validation; `filter_var()` is.
- Prefer `const` for constants. `__DIR__` builds paths relative to the current file.
- Assignment copies values (cheaply, via copy-on-write); `&` creates references, which are best avoided.

## Exercises

1. **Type tour.** Write a script that stores one value of each type `bool`, `int`, `float`, `string`, `array`, `null`
   in an array and prints `get_debug_type()` and `var_export()` of each, one per line.

<details>
<summary>Solution</summary>

```php
<?php

$values = [true, 42, 3.14, 'Dune', ['a', 'b'], null];

foreach ($values as $value) {
    echo get_debug_type($value), ': ', var_export($value, true), "\n";
}
```

```text
bool: true
int: 42
float: 3.14
string: 'Dune'
array: array (
  0 => 'a',
  1 => 'b',
)
null: NULL
```

</details>

2. **Truthiness table.** Predict, then check with `var_dump((bool) ...)`, the truthiness of: `"0"`, `"00"`, `"0.0"`,
   `" "`, `[]`, `[0]`, `0.0`, `"false"`, `null`, `new stdClass()`.

<details>
<summary>Solution</summary>

```php
<?php

foreach (['"0"' => '0', '"00"' => '00', '"0.0"' => '0.0', '" "' => ' ', '[]' => [], '[0]' => [0],
          '0.0' => 0.0, '"false"' => 'false', 'null' => null, 'stdClass' => new stdClass()] as $label => $v) {
    echo str_pad($label, 10), var_export((bool) $v, true), "\n";
}
```

```text
"0"       false
"00"      true
"0.0"     true
" "       true
[]        false
[0]       true
0.0       false
"false"   true
null      false
stdClass  true
```

Only the exact string `"0"` and the empty string are falsy strings. An array with one element (even `0`) is truthy.

</details>

3. **Money in cents.** A member owes three fines: \$0.10, \$0.20 and \$0.30. Add them as floats and compare the sum with
   `0.6` using `==`. Then redo it in integer cents and print the total formatted as `$0.60`.

<details>
<summary>Solution</summary>

```php
<?php

$floatTotal = 0.10 + 0.20 + 0.30;
var_dump($floatTotal == 0.6); // bool(false)
var_dump($floatTotal);        // float(0.6000000000000001)

$centsTotal = 10 + 20 + 30;
var_dump($centsTotal === 60); // bool(true)
printf("$%d.%02d\n", intdiv($centsTotal, 100), $centsTotal % 100); // $0.60
```

`printf()` with `%02d` pads the cents to two digits (chapter 4 covers format strings). Integer arithmetic is exact, so
the comparison works.

</details>

4. **Validating, not casting.** Write a script that takes a value from the command line (`$argv[1]`, chapter 14 explains
   it) and prints "valid page count: N" if it is a positive whole number, or "invalid" otherwise. Test it with `12`,
   `0`, `-3`, `12abc` and `1e3`. Why would `(int) $argv[1]` be the wrong approach?

<details>
<summary>Solution</summary>

```php
<?php

$input = $argv[1] ?? '';
$pages = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($pages === false) {
    echo "invalid\n";
} else {
    echo "valid page count: $pages\n";
}
```

```bash
php pages.php 12      # valid page count: 12
php pages.php 0       # invalid
php pages.php -3      # invalid
php pages.php 12abc   # invalid
php pages.php 1e3     # invalid
```

`(int) '12abc'` is `12` and `(int) 'abc'` is `0`, so a cast silently turns garbage into plausible numbers.
`filter_var()` returns `false` for anything that is not exactly an integer in range. Note the strict comparison
`=== false`: a valid result could be falsy if the range allowed `0`.

</details>

5. **Copy or share?** Predict the output, then run it:

   ```php
   <?php
   $a = ['Dune'];
   $b = $a;
   $b[] = 'Kindred';
   $c = &$a;
   $c[] = 'Emma';
   echo count($a), count($b), count($c), "\n";
   ```

<details>
<summary>Solution</summary>

```text
222
```

`$b = $a` copies the array (lazily, but the copy happens as soon as `$b` is modified), so appending `'Kindred'` to `$b`
leaves `$a` with one element and gives `$b` two. `$c = &$a` makes `$c` another name for `$a`, so appending `'Emma'`
through `$c` changes `$a` too. All three end up with two elements, but `$b` holds `Dune, Kindred` while `$a` and `$c`
hold `Dune, Emma`.

</details>

---
← Previous: [Getting started](01-getting-started.md) | Next: [Operators and control flow](03-operators-and-control-flow.md) →
