# Chapter 9 — Classes and objects

## What you'll learn

- Declaring classes, creating objects, properties and methods, and `$this`
- Constructors and constructor property promotion
- Visibility (`public`, `protected`, `private`) and why encapsulation matters
- `readonly` properties and immutable objects
- Static properties and methods, named constructors, class constants
- How object variables really work: handles, identity versus equality, cloning (shallow and deep)
- Destructors, `__toString()`, `instanceof`, `::class`, and why dynamic properties are deprecated
- Value objects versus entities, with Bookshelf's `Money`, `Isbn`, `Book` and `Member` classes

## Classes and objects

A **class** is a blueprint: it declares what data an object holds (**properties**) and what it can do
(**methods**). An **object** is an instance of a class, created with `new`.

```php
<?php

declare(strict_types=1);

class Book
{
    public string $title;
    public int $copies = 1;

    public function isAvailable(): bool
    {
        return $this->copies > 0;
    }
}

$book = new Book();
$book->title = 'Dune';
$book->copies = 2;

echo $book->title, ' available: ', var_export($book->isAvailable(), true), "\n"; // Dune available: true
```

- `->` accesses a property or calls a method on an object. Property names after `->` have **no `$`**.
- Inside a method, **`$this`** is the object the method was called on.
- Class names are `PascalCase` by convention, one class per file once you use autoloading (chapter 12).
- `new Book()` and `new Book` are the same; always write the parentheses.

Since PHP 8.4 you can call a method or read a property directly on a `new` expression without wrapping it in
parentheses: `new Book()->isAvailable()`. On older versions you need `(new Book())->isAvailable()`.

## Constructors

The constructor, a method named `__construct`, runs when the object is created. Its job is to put the object into a
valid state, so that no code ever sees a half-initialized `Book`:

```php
<?php

declare(strict_types=1);

class Book
{
    public string $title;
    public string $author;
    public int $copies;

    public function __construct(string $title, string $author, int $copies = 1)
    {
        if ($copies < 0) {
            throw new InvalidArgumentException('copies cannot be negative');
        }
        $this->title = $title;
        $this->author = $author;
        $this->copies = $copies;
    }
}

$book = new Book('Dune', 'Frank Herbert', 2);
```

### Constructor property promotion (PHP 8.0+)

Declaring a property, accepting a parameter and assigning it is so common that PHP has a shorthand: put a visibility
keyword on a constructor parameter and it becomes a property, assigned automatically.

```php
<?php

declare(strict_types=1);

class Book
{
    public function __construct(
        public string $title,
        public string $author,
        public int $copies = 1,
    ) {
        if ($copies < 0) {
            throw new InvalidArgumentException('copies cannot be negative');
        }
    }
}

$book = new Book(title: 'Dune', author: 'Frank Herbert');
echo "{$book->title} by {$book->author}, {$book->copies} copy\n";
```

Promoted and normal parameters can be mixed. The body still runs, after the assignments, so validation goes there.
Named arguments work with constructors like with any function, which makes `new` calls with several parameters
readable.

## Visibility and encapsulation

Every property, method and constant has a visibility:

| Keyword | Accessible from |
|---|---|
| `public` | Anywhere |
| `protected` | The class itself and its subclasses (chapter 10) |
| `private` | Only the class itself |

Visibility is how a class protects its **invariants**: rules that must always hold, such as "copies is never
negative". If `$copies` is public, any code can write `$book->copies = -5`. If it is private, the only way to change it
is through methods that enforce the rules:

```php
<?php

declare(strict_types=1);

final class Book
{
    public function __construct(
        public readonly string $title,
        private int $copies = 1,
    ) {
        if ($copies < 0) {
            throw new InvalidArgumentException('copies cannot be negative');
        }
    }

    public function copies(): int
    {
        return $this->copies;
    }

    public function lendCopy(): void
    {
        if ($this->copies === 0) {
            throw new LogicException("No copies of {$this->title} left");
        }
        $this->copies--;
    }

    public function returnCopy(): void
    {
        $this->copies++;
    }
}

$book = new Book('Dune', 2);
$book->lendCopy();
echo $book->copies(), "\n"; // 1

echo $book->copies;         // Error
```

```text
1
PHP Fatal error:  Uncaught Error: Cannot access private property Book::$copies in demo.php:39
```

Visibility is checked per **class**, not per object: a method of `Book` can read the private properties of *another*
`Book`, which is handy for `equals()` methods.

Guidelines:

- Make properties **private by default**. Expose behavior (`lendCopy()`), not data.
- Make a property public when it is plain data that may be read freely, and make it `readonly` so it cannot be changed
  from outside (next section). PHP 8.4's asymmetric visibility (`public private(set)`) and property hooks add more
  options; chapter 11 covers them.
- Methods are named for what they do or return. PHP has no special getter syntax; `copies()` or `getCopies()` are both
  common styles. Follow your project's convention.
- `final class` (chapter 10) prevents subclassing. Making classes final by default is a good habit: it keeps your
  options open and stops others from depending on internals through inheritance.

## Readonly properties (PHP 8.1+)

A `readonly` property can be assigned **once**, from inside the class (typically in the constructor), and never again:

```php
<?php

declare(strict_types=1);

final class Isbn
{
    public function __construct(public readonly string $value)
    {
    }
}

$isbn = new Isbn('9780441013593');
echo $isbn->value, "\n";

$isbn->value = 'x';
```

```text
9780441013593
PHP Fatal error:  Uncaught Error: Cannot modify readonly property Isbn::$value in demo.php:15
```

Readonly properties must be typed. They are the simplest way to make a public field safe to expose: anyone can read
it, nobody can change it. When every property of a class is readonly, you can declare the whole class `readonly`
(PHP 8.2+), as chapter 11 shows.

Note that readonly is **shallow**: a readonly property holding an object prevents replacing the object, not modifying
it. A readonly `array` property is fully immutable, because arrays are values.

(One detail you may meet in error messages: since PHP 8.4, a readonly property may only be written from the class's own
scope, as if it were `protected(set)`. Writing it from outside gives "Cannot modify protected(set) readonly property".)

## Static members

`static` properties and methods belong to the **class**, not to any object. You access them with `::`, using `self::`
inside the class:

```php
<?php

declare(strict_types=1);

final class LoanCounter
{
    private static int $issued = 0;

    public static function next(): int
    {
        return ++self::$issued;
    }
}

LoanCounter::next();
LoanCounter::next();
echo LoanCounter::next(), "\n"; // 3
```

Static properties are global state with a class name in front, with all the drawbacks discussed for globals in chapter
6. Use them rarely. **Static methods**, on the other hand, are very useful for one thing in particular: named
constructors.

### Named constructors

A class can have only one `__construct`, but it can have any number of static methods that create instances. Named
constructors make creation self-explanatory, and a **private** constructor forces everyone to use them:

```php
<?php

declare(strict_types=1);

final class Money
{
    private function __construct(public readonly int $cents)
    {
    }

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function dollars(string $amount): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $m) !== 1) {
            throw new InvalidArgumentException("Invalid amount '$amount'");
        }

        return new self((int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0'));
    }
}

echo Money::dollars('1.5')->cents, "\n"; // 150
echo Money::cents(250)->cents, "\n";     // 250
new Money(5);                            // Error: Call to private Money::__construct() from global scope
```

`self` refers to the class in which the code is written. (Chapter 10 explains `static`, which refers to the class the
method was *called* on, and matters with inheritance.)

## Class constants

Constants declared in a class are accessed with `ClassName::NAME` or `self::NAME`:

```php
<?php

declare(strict_types=1);

final class LoanPolicy
{
    public const int DEFAULT_DAYS = 21;          // typed constant, PHP 8.3+
    public const int FINE_PER_DAY_CENTS = 25;
    private const int MAX_RENEWALS = 2;

    public static function canRenew(int $renewals): bool
    {
        return $renewals < self::MAX_RENEWALS;
    }
}

echo LoanPolicy::DEFAULT_DAYS, "\n";               // 21
var_dump(LoanPolicy::canRenew(2));                 // bool(false)
```

Constants can have visibility (PHP 7.1+), types (PHP 8.3+; before that, write `public const DEFAULT_DAYS = 21;`) and
can be `final` (PHP 8.1+) so subclasses cannot override them. Every class also has a magic constant **`::class`** that
holds its fully qualified name as a string: `Book::class` is `'Book'` (or `'Bookshelf\Book'` with namespaces, chapter
12). Use it instead of writing class names in strings, so renaming tools and static analysers can follow it. On an
object, `$book::class` (PHP 8.0+) is the same as `get_class($book)`.

## How object variables work

This is the most important difference between objects and every other PHP type. A variable does not contain the
object; it contains a **handle**, an identifier that points to the object. Copying the variable copies the handle,
so both variables point to the **same object**:

```php
<?php

declare(strict_types=1);

final class Counter
{
    public int $n = 0;
}

$a = new Counter();
$b = $a;          // copies the handle, not the object
$b->n = 5;
echo $a->n, "\n"; // 5

function bump(Counter $c): void
{
    $c->n++;      // the function receives a copy of the handle, so it modifies the caller's object
}

bump($a);
echo $a->n, "\n"; // 6
```

```text
   $a ──┐
        ├──►  Counter { n: 6 }
   $b ──┘
```

Compare with arrays (chapter 5), which are values: passing an array to a function gives the function its own copy.
Passing an object lets the function change the object. That is often exactly what you want (a `lendCopy()` method
changes the book), but it also means an object you pass around can be modified by anyone holding the handle. This is
the strongest argument for **immutable** objects, below.

Strictly speaking, objects are not passed "by reference": reassigning the parameter (`$c = new Counter()`) inside the
function does not affect the caller's variable. Only modifications *through* the handle are visible.

### Identity and equality

| Operator | For objects means |
|---|---|
| `$a === $b` | Same object (same handle) |
| `$a == $b` | Same class and equal property values (compared with `==`, recursively) |

```php
<?php

declare(strict_types=1);

final class Isbn
{
    public function __construct(public readonly string $value) {}
}

$x = new Isbn('9780441013593');
$y = new Isbn('9780441013593');

var_dump($x == $y);   // bool(true): same class, same values
var_dump($x === $y);  // bool(false): two different objects
var_dump($x === $x);  // bool(true)
```

`==` on objects uses loose comparison on every property, so it inherits all of the surprises from chapter 3. For value
objects, write an explicit `equals()` method that compares exactly what matters.

### Cloning

`clone` creates a new object with copies of all properties:

```php
<?php

declare(strict_types=1);

final class Shelf
{
    /** @var list<string> */
    public array $titles = [];
    public ?Shelf $overflow = null;
}

$a = new Shelf();
$a->titles = ['Dune'];
$a->overflow = new Shelf();

$b = clone $a;
$b->titles[] = 'Emma';              // arrays are values: only $b changes
$b->overflow->titles[] = 'Kindred'; // but both share the same overflow object!

echo count($a->titles), ' ', count($a->overflow->titles), "\n"; // 1 1
```

The clone is **shallow**: properties holding objects still point to the same objects. To copy those too, define
`__clone()`, which runs on the new copy right after cloning:

```php
public function __clone()
{
    if ($this->overflow !== null) {
        $this->overflow = clone $this->overflow;   // deep copy
    }
}
```

Since PHP 8.3, `__clone()` may also re-assign readonly properties, which makes "clone and change one field" possible for
readonly classes. PHP 8.5 goes further with `clone($object, ['property' => $newValue])`, covered in chapter 11.

### Destructors

`__destruct()` runs when the last handle to an object disappears (the variable goes out of scope, is reassigned or is
`unset()`), or at the end of the script:

```php
<?php

final class TempFile
{
    public function __construct(public readonly string $path)
    {
        touch($path);
    }

    public function __destruct()
    {
        @unlink($this->path);
        echo "removed {$this->path}\n";
    }
}

$tmp = new TempFile(sys_get_temp_dir() . '/bookshelf.tmp');
$tmp = null;           // the destructor runs here
echo "after\n";
```

```text
removed /tmp/bookshelf.tmp
after
```

Destructors are rarely needed in application code. Do not put important logic in them: the order in which objects
are destroyed at the end of a script is not something to rely on, and they do not run after a fatal error.

## Useful built-ins

### `__toString()` and `Stringable`

A class with a `__toString(): string` method can be used wherever a string is expected: in `echo`, interpolation and
concatenation. Such classes automatically implement the `Stringable` interface (PHP 8.0+), which you can use as a type:

```php
public function __toString(): string
{
    return sprintf('$%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
}
```

Use `__toString()` for the one natural textual form of a value (an ISBN, an amount of money), not for debugging output.

### `instanceof`

`$x instanceof Book` is true if `$x` is a `Book`, a subclass of `Book`, or implements `Book` when `Book` is an
interface (chapter 10). It is how you narrow `mixed` or union types:

```php
if ($item instanceof Book) {
    echo $item->title;  // tools now know $item is a Book
}
```

### Dynamic properties are deprecated

Assigning to a property that the class does not declare used to create it silently. Since PHP 8.2 this is deprecated:

```text
Deprecated: Creation of dynamic property Book::$foo is deprecated in demo.php on line 16
```

It catches typos such as `$book->tilte = 'Dune'`. Declare every property. (Classes that genuinely need dynamic
properties can opt in with the `#[\AllowDynamicProperties]` attribute; `stdClass`, PHP's generic empty object, still
allows them.)

`stdClass` is what `json_decode()` returns without the associative flag, and what `(object) ['a' => 1]` produces. It
is fine for throwaway data, but your own data deserves real classes.

## Value objects and entities

Two kinds of classes appear again and again in application code:

| | Value object | Entity |
|---|---|---|
| Identified by | Its value | Its identity (an id) |
| Examples | `Money`, `Isbn`, `DateRange`, `EmailAddress` | `Book`, `Member`, `Loan` |
| Equality | Equal when all values are equal | Same id = same thing, even if fields differ |
| Mutability | **Immutable**: "changing" returns a new object | Changes over time through methods |

A value object is small, validates itself in the constructor and is immutable. Once you have an `Isbn` object, you
know it is valid; no function receiving one needs to check again. This is "make illegal states unrepresentable",
and it removes a whole category of bugs.

Immutability matters because of handles: if two parts of the program hold the same `Money` object and one could change
it, the other would see its value change unexpectedly. With immutable objects, sharing is always safe. Operations
return new objects:

```php
$fine = Money::cents(25)->times(4);   // a new Money; the 25-cent object is unchanged
```

## Bookshelf: the domain classes

Here are the first real Bookshelf classes. They replace the arrays used so far.

```php
<?php

declare(strict_types=1);

final class Money
{
    private function __construct(public readonly int $cents)
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative');
        }
    }

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function times(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    public function min(Money $other): self
    {
        return $this->cents <= $other->cents ? $this : $other;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function __toString(): string
    {
        return sprintf('$%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}

final class Isbn
{
    public readonly string $value;

    public function __construct(string $input)
    {
        $digits = preg_replace('/[\s-]+/', '', $input);
        if (!self::isValid($digits)) {
            throw new InvalidArgumentException("Invalid ISBN-13: '$input'");
        }
        $this->value = $digits;
    }

    private static function isValid(string $digits): bool
    {
        if (preg_match('/^\d{13}$/', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        foreach (str_split($digits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }
        return $sum % 10 === 0;
    }

    public function equals(Isbn $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class Book
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $author,
        public readonly Isbn $isbn,
        private int $copies = 1,
    ) {
        if ($copies < 0) {
            throw new InvalidArgumentException('copies cannot be negative');
        }
    }

    public function availableCopies(): int
    {
        return $this->copies;
    }

    public function isAvailable(): bool
    {
        return $this->copies > 0;
    }

    public function lendCopy(): void
    {
        if (!$this->isAvailable()) {
            throw new LogicException("No copies of '{$this->title}' are available");
        }
        $this->copies--;
    }

    public function returnCopy(): void
    {
        $this->copies++;
    }
}

final class Member
{
    public const int MAX_LOANS = 3;

    /** @var array<int, Book> books on loan, keyed by book id */
    private array $loans = [];

    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }

    public function borrow(Book $book): void
    {
        if (count($this->loans) >= self::MAX_LOANS) {
            throw new LogicException("{$this->name} already has " . self::MAX_LOANS . ' loans');
        }
        if (isset($this->loans[$book->id])) {
            throw new LogicException("{$this->name} already has '{$book->title}'");
        }
        $book->lendCopy();               // may throw; then nothing below runs
        $this->loans[$book->id] = $book;
    }

    public function giveBack(Book $book): void
    {
        if (!isset($this->loans[$book->id])) {
            throw new LogicException("{$this->name} does not have '{$book->title}'");
        }
        unset($this->loans[$book->id]);
        $book->returnCopy();
    }

    /** @return list<string> */
    public function loanTitles(): array
    {
        return array_values(array_map(fn (Book $b): string => $b->title, $this->loans));
    }
}

// --- using them ---

$dune = new Book(1, 'Dune', 'Frank Herbert', new Isbn('978-0-441-01359-3'), copies: 1);
$ada = new Member(1, 'Ada');
$alan = new Member(2, 'Alan');

$ada->borrow($dune);
echo 'Ada has: ', implode(', ', $ada->loanTitles()), "\n";
echo 'Dune copies left: ', $dune->availableCopies(), "\n";

try {
    $alan->borrow($dune);
} catch (LogicException $e) {
    echo 'Alan: ', $e->getMessage(), "\n";
}

$ada->giveBack($dune);
$alan->borrow($dune);
echo 'Alan has: ', implode(', ', $alan->loanTitles()), "\n";

$fine = Money::cents(25)->times(30)->min(Money::cents(500));
echo "Fine for 30 days late: $fine\n";

try {
    new Isbn('978-0-441-01359-4');
} catch (InvalidArgumentException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
Ada has: Dune
Dune copies left: 0
Alan: No copies of 'Dune' are available
Alan has: Dune
Fine for 30 days late: $5.00
Invalid ISBN-13: '978-0-441-01359-4'
```

Things to notice:

- `Money` and `Isbn` are value objects: immutable, validated on creation, compared with `equals()`.
- `Book` and `Member` are entities with ids. Their state changes only through methods that enforce the rules.
- `Member::borrow()` receives a `Book` handle and calls `$book->lendCopy()` on it: the change is visible to every
  other holder of that handle, which is exactly the point here.
- `Isbn` assigns its readonly property in the constructor body after normalizing the input, which promotion could not
  do.
- The exceptions are plain `LogicException`s for now; in the capstone they become the custom exception classes from
  chapter 8.

## Key takeaways

- A class declares properties and methods; `new` creates objects; `->` accesses members; `$this` is the current object.
- Constructors make objects valid from the start. Constructor promotion (`public function __construct(private int $x)`)
  removes boilerplate.
- Default to `private` properties and expose behavior. Use `public readonly` for data that may be read but not changed.
- Static methods are ideal as named constructors; static properties are global state and should be rare. Class
  constants can be typed (8.3+) and `final`.
- Object variables hold handles: assignment and parameter passing share the object. `===` is identity, `==` compares
  properties loosely. `clone` is shallow; `__clone()` makes it deep.
- Dynamic properties are deprecated; declare everything.
- Value objects (immutable, compared by value) and entities (identified by id, changed through methods) are the two
  workhorses of application code.

## Exercises

1. **Promotion.** Rewrite this class with constructor promotion, readonly properties and a named constructor
   `Member::standard(int $id, string $name)` that sets `$type` to `'standard'`:

   ```php
   class Member {
       public $id;
       public $name;
       public $type;
       public function __construct($id, $name, $type) {
           $this->id = $id;
           $this->name = $name;
           $this->type = $type;
       }
   }
   ```

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final class Member
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
    ) {
    }

    public static function standard(int $id, string $name): self
    {
        return new self($id, $name, 'standard');
    }
}

$ada = Member::standard(1, 'Ada');
echo "{$ada->name} ({$ada->type})\n"; // Ada (standard)
```

Types were added too: once properties are readonly they must be typed, and typed is better anyway. Chapter 11 turns
`$type` into an enum.

</details>

2. **Handles.** Predict the output, then run it:

   ```php
   <?php
   final class Tag { public function __construct(public string $name) {} }
   $tags = [new Tag('sf'), new Tag('classic')];
   $copy = $tags;
   $copy[0]->name = 'science-fiction';
   $copy[1] = new Tag('romance');
   echo $tags[0]->name, ' ', $tags[1]->name, "\n";
   ```

<details>
<summary>Solution</summary>

```text
science-fiction classic
```

`$copy = $tags` copies the array, but the array holds handles, so `$copy[0]` and `$tags[0]` are the same `Tag` object;
renaming it is visible through both. `$copy[1] = new Tag(...)` replaces an element of the copied array only, so
`$tags[1]` is still `classic`.

</details>

3. **A `DateRange` value object.** Write an immutable `DateRange` with `start` and `end` as `'YYYY-MM-DD'` strings,
   validated with `preg_match()` and requiring `start <= end`. Add `contains(string $date): bool`,
   `overlaps(DateRange $other): bool` and `equals(DateRange $other): bool`.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final class DateRange
{
    public function __construct(
        public readonly string $start,
        public readonly string $end,
    ) {
        foreach ([$start, $end] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new InvalidArgumentException("Not a YYYY-MM-DD date: '$date'");
            }
        }
        if ($start > $end) {
            throw new InvalidArgumentException("Range starts after it ends: $start > $end");
        }
    }

    public function contains(string $date): bool
    {
        return $this->start <= $date && $date <= $this->end;
    }

    public function overlaps(DateRange $other): bool
    {
        return $this->start <= $other->end && $other->start <= $this->end;
    }

    public function equals(DateRange $other): bool
    {
        return $this->start === $other->start && $this->end === $other->end;
    }
}

$loan = new DateRange('2026-10-01', '2026-10-21');
$holiday = new DateRange('2026-10-20', '2026-10-31');

var_dump($loan->contains('2026-10-11'));   // bool(true)
var_dump($loan->overlaps($holiday));        // bool(true)
var_dump($loan->equals(new DateRange('2026-10-01', '2026-10-21'))); // bool(true)
```

Two ranges overlap exactly when each starts before the other ends. The regex only checks the format, so
`'2026-02-31'` would pass; chapter 15 uses real date objects that reject it.

</details>

4. **Deep clone.** Write a `Catalog` class holding a private array of `Book` objects (use a minimal `Book` with a public
   `int $copies`). Implement `__clone()` so that cloning a catalog also clones its books, and show that lending from the
   clone does not affect the original.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final class Book
{
    public function __construct(public string $title, public int $copies) {}
}

final class Catalog
{
    /** @param list<Book> $books */
    public function __construct(private array $books) {}

    public function __clone()
    {
        $this->books = array_map(fn (Book $b): Book => clone $b, $this->books);
    }

    public function book(int $i): Book
    {
        return $this->books[$i];
    }
}

$original = new Catalog([new Book('Dune', 2)]);
$snapshot = clone $original;

$snapshot->book(0)->copies--;

echo $original->book(0)->copies, ' ', $snapshot->book(0)->copies, "\n"; // 2 1
```

Without `__clone()`, the cloned array would contain the same `Book` handles, and the output would be `1 1`.

</details>

5. **Encapsulation review.** The class below has three design problems that let callers break its rules. Identify them
   and fix the class.

   ```php
   class Account {
       public int $balanceCents = 0;
       public array $history = [];
       public function pay(int $cents) {
           $this->balanceCents -= $cents;
           $this->history[] = -$cents;
       }
   }
   ```

<details>
<summary>Solution</summary>

1. `$balanceCents` is public: anyone can set any balance.
2. `$history` is public: entries can be added, removed or edited, so it can disagree with the balance.
3. `pay()` accepts zero or negative amounts (a negative payment increases the balance) and has no return type.

```php
<?php

declare(strict_types=1);

final class Account
{
    private int $balanceCents = 0;
    /** @var list<int> */
    private array $history = [];

    public function charge(int $cents): void
    {
        $this->record($this->positive($cents));
    }

    public function pay(int $cents): void
    {
        $this->record(-$this->positive($cents));
    }

    public function balanceCents(): int
    {
        return $this->balanceCents;
    }

    /** @return list<int> */
    public function history(): array
    {
        return $this->history;   // arrays are values: the caller gets a copy
    }

    private function positive(int $cents): int
    {
        if ($cents <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
        return $cents;
    }

    private function record(int $delta): void
    {
        $this->balanceCents += $delta;
        $this->history[] = $delta;
    }
}

$account = new Account();
$account->charge(500);
$account->pay(200);
echo $account->balanceCents(), ' ', implode(',', $account->history()), "\n"; // 300 500,-200
```

Returning the private array is safe because arrays are copied on write; returning a private *object* would hand out a
handle that callers could modify.

</details>

---
← Previous: [Errors and exceptions](08-errors-and-exceptions.md) | Next: [Inheritance, interfaces and traits](10-inheritance-interfaces-and-traits.md) →
