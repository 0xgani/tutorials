# Chapter 4 — Strings and text

## What you'll learn

- Single-quoted, double-quoted, heredoc and nowdoc strings, and exactly what each one interprets
- Interpolating variables, array elements and object properties into strings
- Formatting with `printf()`/`sprintf()` and `number_format()`
- The everyday string functions, and the `strpos()` trap
- Why PHP strings are bytes, what that means for UTF-8 text, and how the `mbstring` and `intl` functions fix it
- Regular expressions with the PCRE functions, including Unicode and error handling
- Two Bookshelf helpers: a URL slug generator and an ISBN-13 validator

## Writing strings

### Single quotes: literal

Inside single quotes, only two escape sequences exist: `\'` for a quote and `\\` for a backslash. Everything else,
including `$` and `\n`, is literal:

```php
echo 'single \n \' \\ end';   // single \n ' \ end
```

### Double quotes: escapes and interpolation

Double quotes interpret escape sequences and **interpolate** variables:

| Sequence | Meaning |
|---|---|
| `\n`, `\r`, `\t` | Newline, carriage return, tab |
| `\\`, `\$`, `\"` | Literal backslash, dollar sign, double quote |
| `\x41`, `\101` | Byte by hex or octal value (`A`) |
| `\u{1F4DA}` | UTF-8 encoding of a Unicode code point (📚) |
| `\e`, `\v`, `\f`, `\0` | Escape, vertical tab, form feed, NUL byte |

Interpolation has a simple form and a "complex" (curly-brace) form:

```php
<?php

$title = 'Dune';
$book = ['title' => 'Dune', 'year' => 1965];
$n = 3;

echo "Title: $title\n";                       // simple variable
echo "Year: $book[year]\n";                   // array key, NO quotes in the simple form
echo "Year: {$book['year']}\n";               // curly form: any expression starting with $
echo "{$n}rd edition\n";                      // braces separate the name from following text
```

```text
Title: Dune
Year: 1965
Year: 1965
3rd edition
```

The curly form `{$...}` accepts anything that starts with `$`: nested array keys, object properties, method calls.
It is the safe choice whenever the simple form is not obviously right:

```php
echo "By {$book['author']['name']}\n";
echo "By {$member->name()}\n";
```

You cannot call plain functions or use constants inside interpolation (`"{strtoupper($x)}"` does not work). Compute the
value first or use concatenation or `sprintf()`. The old `"${var}"` syntax is deprecated since PHP 8.2.

### Heredoc and nowdoc

For multi-line text, **heredoc** behaves like a double-quoted string and **nowdoc** like a single-quoted one:

```php
<?php

$book = ['title' => 'Dune', 'year' => 1965];

$html = <<<HTML
    <h1>{$book['title']}</h1>
      <p>Published $book[year]</p>
    HTML;

$raw = <<<'TXT'
    No $interpolation here\n
    TXT;

echo $html, "\n", $raw, "\n";
```

```text
<h1>Dune</h1>
  <p>Published 1965</p>
No $interpolation here\n
```

- The opening identifier (`HTML`, `TXT`, anything you like) follows `<<<`; quoting it with `'...'` makes a nowdoc.
- The closing identifier can be indented. Its indentation is removed from **every line** of the body (PHP 7.3+), so
  you can indent heredocs with your code. Body lines must be indented at least as much as the closing marker.
- The resulting string has no trailing newline; the newline before the closing identifier is not included.

Heredocs are handy for SQL, HTML fragments and help texts. Choosing a meaningful identifier (`SQL`, `HTML`) lets
editors syntax-highlight the contents.

### Concatenation versus interpolation

```php
$line = 'Title: ' . $title . ' (' . $year . ')';
$line = "Title: $title ($year)";
$line = sprintf('Title: %s (%d)', $title, $year);
```

All three produce the same string, and the performance difference is irrelevant. Pick whichever is clearest: short
interpolation for simple cases, `sprintf()` when you need formatting or when the template comes from elsewhere
(translations, configuration).

## Accessing characters

Strings can be indexed like arrays. Offsets count **bytes**, starting at 0; negative offsets count from the end:

```php
<?php

$s = 'Dune';
var_dump($s[0], $s[-1]); // string(1) "D" string(1) "e"

$t = $s;
$t[0] = 'T';
var_dump($t, $s);        // "Tune" "Dune": strings are values, $s is unchanged

echo $s[10];             // Warning: Uninitialized string offset 10
echo $s[10] ?? '-';      // '-', no warning
```

## Formatting

### `printf()` and `sprintf()`

`printf()` prints a formatted string; `sprintf()` returns it. A format specifier is `%` followed by optional flags,
width and precision, and a conversion letter:

```text
%  [argnum$]  [flags]  [width]  [.precision]  conversion
     2$         -  +  0  'x       10              .2           s d f x ...
```

```php
<?php

printf("%s has %d pages and costs %.2f\n", 'Dune', 412, 9.5);
printf("[%5d] [%-5d] [%05d] [%5.1f]\n", 42, 42, 42, 3.14159);
printf("[%'*10s] [%-10s]\n", 'Dune', 'Dune');
printf("[%x] [%o] [%b] [%e] [%%] [%+d]\n", 255, 8, 5, 1234.5, 5);
printf("%2\$s, %1\$s\n", 'Frank', 'Herbert');
```

```text
Dune has 412 pages and costs 9.50
[   42] [42   ] [00042] [  3.1]
[******Dune] [Dune      ]
[ff] [10] [101] [1.234500e+3] [%] [+5]
Herbert, Frank
```

| Conversion | Meaning |
|---|---|
| `%s` | String |
| `%d` | Integer (decimal) |
| `%f`, `%.2f` | Float, with precision |
| `%x`, `%X`, `%o`, `%b` | Hex, upper-case hex, octal, binary |
| `%e` | Scientific notation |
| `%%` | A literal percent sign |

| Flag | Meaning |
|---|---|
| `-` | Left-align within the width |
| `+` | Always show the sign |
| `0` | Pad numbers with zeros |
| `'c` | Pad with the character `c` |

`%2$s` picks argument number 2, which lets a translated template reorder arguments. (In double quotes the `$` must be
escaped, `%2\$s`, or PHP will try to interpolate `$s`; another reason to use single quotes for format strings.)

`sprintf()` converts its arguments like a cast does, so `sprintf('%d', '12abc')` silently produces `'12'`. Validate
first (chapter 2).

Related functions: `vsprintf($format, $array)` takes the arguments as an array, `fprintf()` writes to a stream, and
`sscanf()` parses a string with a format.

### `number_format()`

```php
echo number_format(1234567.891, 2);           // 1,234,567.89
echo number_format(1234567.891, 2, ',', '.'); // 1.234.567,89
```

For real locale-aware number and currency formatting (`€1.234,57`, `1 234,57 €`), use the `intl` extension's
`NumberFormatter`, covered in chapter 15.

## The everyday string toolkit

PHP has well over a hundred string functions. These are the ones you will use every week.

### Searching

```php
<?php

$t = 'The Left Hand of Darkness';

var_dump(str_contains($t, 'Hand'));      // bool(true)   PHP 8.0+
var_dump(str_starts_with($t, 'The'));    // bool(true)   PHP 8.0+
var_dump(str_ends_with($t, 'ness'));     // bool(true)   PHP 8.0+
var_dump(strpos($t, 'Hand'));            // int(9): byte offset of the first match
var_dump(stripos($t, 'hand'));           // int(9): case-insensitive
var_dump(strrpos($t, 'e'));              // int(22): last match
var_dump(strpos($t, 'x'));               // bool(false): not found
var_dump(substr_count($t, 'e'));         // int(3)
```

**The `strpos()` trap.** `strpos()` returns the offset, which can be `0`, or `false` when the needle is missing. Both are
falsy:

```php
<?php

$t = 'The Left Hand of Darkness';

if (strpos($t, 'The')) {
    echo "found\n";
} else {
    echo "not found?!\n";   // this runs: strpos() returned 0
}
```

Always compare with `!== false`, or better, use `str_contains()` when you only need a yes/no answer. This pattern (a
function that returns a value *or* `false`) is common in older PHP functions; check the manual's "Return values"
section and compare strictly.

### Extracting and replacing

```php
<?php

$t = 'The Left Hand of Darkness';

var_dump(substr($t, 4, 4));    // "Left": start at byte 4, take 4 bytes
var_dump(substr($t, -8));      // "Darkness": the last 8 bytes
var_dump(substr($t, 100));     // "": out of range gives an empty string

$new = str_replace(['Left', 'Darkness'], ['Right', 'Light'], $t, $count);
var_dump($new, $count);        // "The Right Hand of Light" int(2)

var_dump(strtr('Hi all', ['Hi' => 'Hello', 'all' => 'everyone'])); // "Hello everyone"
```

`str_replace()` accepts strings or arrays, and the optional fourth argument receives the number of replacements.
`str_ireplace()` is case-insensitive. `strtr()` with an array replaces all keys in one pass, preferring the longest
match, so a replacement is never replaced again.

### Trimming, case and padding

```php
<?php

var_dump(trim("  Dune \n"));          // "Dune": strips spaces, tabs, newlines, NUL, \v, \r
var_dump(rtrim('1,2,3,', ','));       // "1,2,3": second argument = characters to strip
var_dump(trim('--Dune--', '-'));      // "Dune"

var_dump(strtolower('DUNE'), strtoupper('dune'));
var_dump(ucfirst('dune'), lcfirst('Dune'), ucwords('the left hand'));

var_dump(str_pad('7', 3, '0', STR_PAD_LEFT));      // "007"
var_dump(str_pad('Dune', 10, '.', STR_PAD_BOTH));  // "...Dune..."
var_dump(str_repeat('=-', 3));                     // "=-=-=-"
```

### Splitting and joining

```php
<?php

var_dump(explode(',', 'sf,fantasy,,crime'));  // ['sf', 'fantasy', '', 'crime']
var_dump(explode(',', 'a,b,c', 2));           // ['a', 'b,c']: at most 2 parts
var_dump(implode(', ', ['Dune', 'Emma']));    // "Dune, Emma"
var_dump(str_split('abcdef', 4));             // ['abcd', 'ef']
echo wordwrap('The quick brown fox jumps over', 10, "\n", true), "\n";
```

```text
The quick
brown fox
jumps over
```

Note that `explode()` keeps empty parts. Filter them with `array_filter()` (chapter 5) or split with a regular
expression (below) if the input is messy.

### Comparing

```php
var_dump(strcmp('a', 'b'));             // negative: 'a' sorts before 'b'
var_dump(strcasecmp('DUNE', 'dune'));   // 0: equal ignoring case
var_dump(strnatcmp('img12', 'img10'));  // positive: "natural" order compares the numbers
var_dump(levenshtein('kitten', 'sitting')); // int(3): edit distance
```

The `strcmp()` family returns a negative number, zero or a positive number, the same contract as `<=>`, so they work
directly as sorting callbacks. Use `strnatcasecmp()` to sort file names and titles the way people expect
(`img2` before `img10`). For plain equality, `===` is enough; for comparing secrets such as tokens, use
`hash_equals()` (chapter 18), which takes the same time regardless of where the strings differ.

## Strings are bytes: working with UTF-8

A PHP string is a sequence of **bytes** with no encoding attached. The basic functions (`strlen`, `substr`,
`strtoupper`, `str_split`, `strrev`, `$s[0]`) all work on bytes. That is perfect for binary data and ASCII, and wrong
for human text in UTF-8, where one character can take 1 to 4 bytes:

```php
<?php

$t = 'Café';

var_dump(strlen($t));           // int(5): 'é' is two bytes, C3 A9
var_dump(strtoupper($t));       // "CAFé": byte functions only touch ASCII letters
var_dump(substr($t, 0, 4));     // "Caf\xC3": cuts 'é' in half, an invalid string
var_dump(strrev($t));           // reverses bytes: garbage
```

The **`mbstring`** ("multibyte string") extension provides character-aware versions. They assume UTF-8 by default
(`mb_internal_encoding()` returns `"UTF-8"`):

```php
<?php

$t = 'Café';

var_dump(mb_strlen($t));            // int(4)
var_dump(mb_strtoupper($t));        // "CAFÉ"
var_dump(mb_substr($t, 0, 4));      // "Café"
var_dump(mb_str_split('añb'));      // ['a', 'ñ', 'b']
var_dump(mb_str_pad('Café', 6, '*'));     // "Café**"   PHP 8.3+
var_dump(mb_trim("\u{3000}Ünïcode\u{00A0}")); // "Ünïcode" PHP 8.4+ (strips Unicode spaces)
var_dump(mb_ucfirst('éclair'));     // "Éclair"   PHP 8.4+
```

| Byte function | Character-aware function |
|---|---|
| `strlen` | `mb_strlen` |
| `substr`, `strpos`, `str_split` | `mb_substr`, `mb_strpos`, `mb_str_split` |
| `strtolower`, `strtoupper`, `ucwords` | `mb_strtolower`, `mb_strtoupper`, `mb_convert_case($s, MB_CASE_TITLE)` |
| `str_pad` | `mb_str_pad` (8.3+) |
| `trim`, `ltrim`, `rtrim` | `mb_trim`, `mb_ltrim`, `mb_rtrim` (8.4+) |
| `ucfirst`, `lcfirst` | `mb_ucfirst`, `mb_lcfirst` (8.4+) |

The rule: **if a string contains text that people wrote, use the `mb_` function.** Byte functions remain correct for
some UTF-8 operations, because UTF-8 was designed so that ASCII bytes never appear inside multi-byte characters:
`str_contains()`, `str_replace()`, `explode()` on an ASCII delimiter and `===` all work fine on UTF-8.

### Characters that are more than one code point

`mb_` functions count **code points**. What a reader perceives as one character (a *grapheme*) can be several code
points: an emoji family, a flag, or an `e` followed by a combining accent. The `intl` extension's `grapheme_*`
functions handle that:

```php
<?php

$family = "👨‍👩‍👧";

var_dump(strlen($family));           // int(18) bytes
var_dump(mb_strlen($family));        // int(5) code points (3 people + 2 joiners)
var_dump(grapheme_strlen($family));  // int(1) what a human sees
```

The same visible text can also be encoded differently: `é` can be one code point (U+00E9) or `e` plus a combining acute
accent (U+0301). The two look identical but are different bytes, so `===` says they differ. Normalize text before
comparing or storing it with `Normalizer::normalize()` from `intl`. Chapter 15 covers `intl` further.

### Validating and converting encodings

Never assume input is valid UTF-8. Check it at the boundary of your program:

```php
<?php

$latin1 = "Caf\xE9";                                  // 'Café' in ISO-8859-1
var_dump(mb_check_encoding($latin1, 'UTF-8'));        // bool(false)
var_dump(mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1')); // "Café"
```

Make UTF-8 the encoding everywhere: in your source files, in `<meta charset="utf-8">` and `Content-Type` headers, and
in the database connection (chapter 17). Mismatched encodings are what produce `CafÃ©`-style mojibake.

## Regular expressions

PHP's regular expressions are the **PCRE2** library ("Perl-compatible regular expressions"), used through the
`preg_*` functions. A pattern is a string with **delimiters** around it and optional **modifiers** after the closing
delimiter:

```text
'/^(\d{3})-(\d{10})$/i'
 │                  ││
 delimiter          │└ modifiers
                    delimiter
```

`/` is the usual delimiter; use another one such as `#` or `~` when the pattern contains slashes:
`'#^/books/(\d+)$#'`. Always write patterns in **single quotes** so that PHP does not interpret backslashes first.

### Matching

```php
<?php

$found = preg_match('/^(\d{3})-(\d{10})$/', '978-0441013593', $m);
var_dump($found);   // int(1): 1 = matched, 0 = no match, false = error
print_r($m);

preg_match('/(?<prefix>\d{3})-(?<rest>\d+)/', 'ISBN 978-0441013593', $m);
echo $m['prefix'], ' / ', $m['rest'], "\n";
```

```text
int(1)
Array
(
    [0] => 978-0441013593
    [1] => 978
    [2] => 0441013593
)
978 / 0441013593
```

`$m[0]` is the whole match, then one entry per capturing group. Named groups `(?<name>...)` are much easier to read
than numbered ones. `preg_match_all()` finds every match:

```php
preg_match_all('/\b\w{4}\b/', 'Dune and Emma were here', $all);
print_r($all[0]); // ['Dune', 'Emma', 'were', 'here']
```

### Replacing and splitting

```php
<?php

// Collapse runs of whitespace.
var_dump(preg_replace('/\s+/', ' ', "The   Left\tHand"));       // "The Left Hand"

// Reorder with backreferences ($1, $2 or ${1}).
var_dump(preg_replace('/(\w+) (\w+)/', '$2, $1', 'Frank Herbert')); // "Herbert, Frank"

// Compute each replacement with a callback.
var_dump(preg_replace_callback('/\d+/', fn ($m) => $m[0] * 2, 'a1 b22 c3')); // "a2 b44 c6"

// Split on commas or semicolons with optional spaces.
print_r(preg_split('/\s*[,;]\s*/', 'sf , fantasy;crime', -1, PREG_SPLIT_NO_EMPTY));

// Keep only array elements that match.
print_r(preg_grep('/^D/', ['Dune', 'Emma', 'Dracula'])); // [0 => 'Dune', 2 => 'Dracula']
```

`fn ($m) => ...` is an arrow function, covered in chapter 6.

### Modifiers you need

| Modifier | Effect |
|---|---|
| `i` | Case-insensitive |
| `u` | Treat pattern and subject as UTF-8: `.` and `\w`-style classes work on characters, and invalid UTF-8 makes the match fail |
| `m` | `^` and `$` match at every line start/end, not just the string's |
| `s` | `.` also matches newlines |
| `x` | Extended: whitespace and `# comments` in the pattern are ignored |

**Always use `u` for text.** Without it, `\w` does not match `é`, and `.` matches single bytes:

```php
var_dump(preg_match('/^\w+$/', 'Café'));     // int(0)
var_dump(preg_match('/^\w+$/u', 'Café'));    // int(1)
var_dump(preg_match('/^\p{L}+$/u', 'Café')); // int(1): \p{L} = any Unicode letter
```

The `x` modifier makes complex patterns maintainable:

```php
$pattern = '/
    ^(?<year>\d{4})    # four-digit year
    -(?<month>\d{2})$  # dash, two-digit month
/x';
```

### Escaping user input in patterns

If part of a pattern comes from a variable, escape it with `preg_quote()`, passing your delimiter:

```php
$search = '1.5 (approx)';
$pattern = '/' . preg_quote($search, '/') . '/i';  // '/1\.5 \(approx\)/i'
```

Without it, `.` and `(` would be interpreted as regex syntax, and a user could make your pattern do something
unintended.

### Errors and catastrophic backtracking

`preg_*` functions return `false` (or `null` for `preg_replace()`) on error. An invalid pattern also emits a warning;
a runtime failure does not, so check `preg_last_error_msg()`:

```php
<?php

var_dump(preg_match('/(a+)+$/', str_repeat('a', 30) . 'b'));
var_dump(preg_last_error_msg());

var_dump(preg_match('/x/u', "\xff"));
var_dump(preg_last_error_msg());
```

```text
bool(false)
string(25) "Backtrack limit exhausted"
bool(false)
string(56) "Malformed UTF-8 characters, possibly incorrectly encoded"
```

The first pattern has *nested quantifiers* (`(a+)+`). On a string that almost matches, the engine tries an exponential
number of ways to split the `a`s before giving up. PHP's `pcre.backtrack_limit` setting stops it, and `preg_match()`
returns `false`. If you only check `if (preg_match(...))`, that `false` looks like "no match" and the failure goes
unnoticed. With patterns run on untrusted input, this is a denial-of-service risk ("ReDoS"). Avoid nested quantifiers,
prefer specific character classes over `.*`, and check for `false` explicitly.

### When not to use a regex

Use plain functions when they do the job: `str_contains()` instead of `preg_match('/foo/')`, `str_replace()` for
fixed strings, `explode()` for simple delimiters, `filter_var()` for validating e-mails and URLs, `json_decode()` for
JSON. And never parse HTML with regular expressions; PHP has a real HTML5 parser (`Dom\HTMLDocument`, PHP 8.4+).

## Bookshelf: slugs and ISBNs

Two text helpers the Bookshelf web app will need. A **slug** is the URL-friendly form of a title
(`/books/the-left-hand-of-darkness`), and an **ISBN-13** is the 13-digit book number with a check digit. You will turn
these into functions in chapter 6; for now they are scripts.

```php
<?php

$title = '  The Left Hand of Darkness: 50th Anniversary Édition! ';

// 1. Trim and lower-case, character-aware.
$slug = mb_strtolower(trim($title));
// 2. Transliterate accents to ASCII where possible (é -> e).
$slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
// 3. Replace every run of non-alphanumerics with one dash, then trim dashes.
$slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

echo $slug, "\n";
```

```text
the-left-hand-of-darkness-50th-anniversary-edition
```

`iconv()` transliteration depends on the system's iconv library and locale; the `intl` extension's `Transliterator`
(`Transliterator::create('Any-Latin; Latin-ASCII; Lower()')`) gives consistent results everywhere and is what you
would use in production.

An ISBN-13 is valid when its digits, weighted alternately 1 and 3, sum to a multiple of 10. Users type ISBNs with dashes
and spaces, so normalize first:

```php
<?php

$input = '978-0-441-01359-3';

$isbn = preg_replace('/[\s-]+/', '', $input);   // strip spaces and dashes

if (preg_match('/^\d{13}$/', $isbn) !== 1) {
    echo "Not 13 digits: $input\n";
    exit(1);
}

$sum = 0;
for ($i = 0; $i < 13; $i++) {
    $digit = (int) $isbn[$i];
    $sum += $i % 2 === 0 ? $digit : $digit * 3;
}

echo $sum % 10 === 0 ? "Valid ISBN-13: $isbn\n" : "Bad check digit: $input\n";
```

```text
Valid ISBN-13: 9780441013593
```

`exit(1)` stops the script with a non-zero exit code, the convention for "failed" (chapter 14).

## Key takeaways

- Single quotes are literal; double quotes and heredocs interpolate `$var`, `$arr[key]` and `{$any->expression}`;
  nowdocs are literal multi-line strings.
- Use `sprintf()`/`printf()` for formatting and write format strings in single quotes.
- `str_contains()`, `str_starts_with()`, `str_ends_with()` answer yes/no questions. When you need `strpos()`, compare
  its result with `!== false`.
- PHP strings are bytes. For human text, use the `mb_*` functions; for user-perceived characters, `grapheme_*`.
  Validate encodings at the edges and use UTF-8 everywhere.
- Regular expressions: single-quoted patterns, the `u` modifier for text, `preg_quote()` for variables, named groups
  for readability, and an explicit check for `false` to catch errors and backtracking limits.

## Exercises

1. **Interpolation drill.** Given `$member = ['name' => 'Ada', 'loans' => ['Dune', 'Emma']]`, print
   `Ada has 2 loans; the first is Dune.` three ways: with interpolation, with concatenation and with `sprintf()`.

<details>
<summary>Solution</summary>

```php
<?php

$member = ['name' => 'Ada', 'loans' => ['Dune', 'Emma']];
$count = count($member['loans']);

echo "{$member['name']} has $count loans; the first is {$member['loans'][0]}.\n";
echo $member['name'] . ' has ' . $count . ' loans; the first is ' . $member['loans'][0] . ".\n";
printf("%s has %d loans; the first is %s.\n", $member['name'], $count, $member['loans'][0]);
```

`count()` is a function call, so it cannot appear inside the string; it is computed into `$count` first. (`{$member[...]}`
is fine because it starts with `$`.)

</details>

2. **Fixed-width report.** Print this table of loans using `printf()` only, with the title left-aligned in 28
   characters and the fine right-aligned in 8 characters with 2 decimals:
   `[['Dune', 1.0], ['The Left Hand of Darkness', 12.5], ['Emma', 0.25]]`.

<details>
<summary>Solution</summary>

```php
<?php

$rows = [['Dune', 1.0], ['The Left Hand of Darkness', 12.5], ['Emma', 0.25]];

printf("%-28s%8s\n", 'Title', 'Fine');
foreach ($rows as [$title, $fine]) {
    printf("%-28s%8.2f\n", $title, $fine);
}
```

```text
Title                           Fine
Dune                            1.00
The Left Hand of Darkness      12.50
Emma                            0.25
```

Width and alignment count **bytes**, so a title with accented letters would be misaligned. `mb_str_pad()` (8.3+) pads by
characters if you need that.

</details>

3. **Initials.** Write code that turns `'ursula k. le guin'` into `'U.K.L.G.'` and `'émile zola'` into `'É.Z.'`. Make it
   work for accented letters.

<details>
<summary>Solution</summary>

```php
<?php

foreach (['ursula k. le guin', 'émile zola'] as $name) {
    $words = preg_split('/[\s.]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $initials = '';
    foreach ($words as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1)) . '.';
    }
    echo $initials, "\n";
}
```

```text
U.K.L.G.
É.Z.
```

`$word[0]` would take only the first *byte* of `é`, producing an invalid string; `mb_substr()` takes the first
character.

</details>

4. **Highlight search terms.** Given a title and a search term typed by a user, wrap every case-insensitive occurrence
   of the term in `<mark>...</mark>`, keeping the original capitalization. The output goes into HTML, so make sure both
   the title and the term are escaped and that a term like `(a` cannot break the regex.

<details>
<summary>Solution</summary>

```php
<?php

$title = 'The Left Hand of Darkness & <Light>';
$term = 'and';

$safeTitle = htmlspecialchars($title);
$safeTerm = htmlspecialchars($term);
$pattern = '/' . preg_quote($safeTerm, '/') . '/iu';

echo preg_replace($pattern, '<mark>$0</mark>', $safeTitle), "\n";
```

```text
The Left H<mark>and</mark> of Darkness &amp; &lt;Light&gt;
```

Escape first, then highlight, so the inserted `<mark>` tags are the only real HTML. Escaping the term the same way keeps
it matching the escaped title. `$0` in the replacement is the whole match, which preserves the original case. A rare
edge case remains: a term such as `amp` would match inside `&amp;`; a production version would split the title on the
term first and escape each piece.

</details>

5. **ISBN-10 to ISBN-13.** Old books have 10-character ISBNs (the last character may be `X`). Convert one to ISBN-13:
   drop the old check character, prefix `978`, and compute the new check digit as `(10 - (sum % 10)) % 10` using the
   1/3 weighting from this chapter. `0-441-01359-7` should become `9780441013593`.

<details>
<summary>Solution</summary>

```php
<?php

$isbn10 = preg_replace('/[\s-]+/', '', '0-441-01359-7');

if (preg_match('/^\d{9}[\dX]$/i', $isbn10) !== 1) {
    exit("Invalid ISBN-10\n");
}

$base = '978' . substr($isbn10, 0, 9);
$sum = 0;
for ($i = 0; $i < 12; $i++) {
    $sum += (int) $base[$i] * ($i % 2 === 0 ? 1 : 3);
}
$check = (10 - $sum % 10) % 10;

echo $base . $check, "\n";
```

```text
9780441013593
```

The outer `% 10` handles the case where `$sum` is already a multiple of 10 (check digit 0, not 10). `exit()` with a
string prints it and exits with status 0; pass an integer for a failure status.

</details>

6. **Spot the ReDoS.** Explain why `'/^(\w+\s?)*$/'` is dangerous on input like `str_repeat('word ', 20) . '!'`, run it to
   see the result, and write a safe pattern that accepts "words separated by single spaces".

<details>
<summary>Solution</summary>

```php
<?php

$input = str_repeat('word ', 20) . '!';

var_dump(preg_match('/^(\w+\s?)*$/', $input), preg_last_error_msg());
var_dump(preg_match('/^\w+(?: \w+)*$/', $input), preg_last_error_msg());
var_dump(preg_match('/^\w+(?: \w+)*$/', 'two words'));
```

```text
bool(false)
string(25) "Backtrack limit exhausted"
int(0)
string(8) "No error"
int(1)
```

Because the space is optional, `(\w+\s?)*` can split a run of letters into words in exponentially many ways ("word" =
"w"+"ord" = "wo"+"rd" ...), and the trailing `!` forces the engine to try them all before failing. The safe pattern
requires exactly one space between words, so there is only one way to match any input, and it fails immediately.

</details>

---
← Previous: [Operators and control flow](03-operators-and-control-flow.md) | Next: [Arrays](05-arrays.md) →
