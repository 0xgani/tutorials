# Chapter 10 — Inheritance, interfaces and traits

## What you'll learn

- Extending a class: inheriting, overriding, calling `parent::`, and `protected`
- Abstract classes and methods, and `final`
- The `#[\Override]` attribute (PHP 8.3+)
- Method signature compatibility: covariant return types and contravariant parameter types
- Interfaces as contracts, the important built-in interfaces, and polymorphism
- Late static binding: `static::` versus `self::`, and the `static` return type
- Traits for horizontal reuse, including conflict resolution
- Anonymous classes
- Why composition usually beats inheritance, illustrated with Bookshelf's fine policies and notifiers

## Inheritance

A class can **extend** one other class. The subclass (child) inherits every public and protected property and method
of the parent, can add its own, and can **override** inherited methods:

```php
<?php

declare(strict_types=1);

class Item
{
    public function __construct(protected string $title)
    {
    }

    public function describe(): string
    {
        return $this->title;
    }
}

class Dvd extends Item
{
    public function __construct(string $title, private int $minutes)
    {
        parent::__construct($title);
    }

    #[\Override]
    public function describe(): string
    {
        return parent::describe() . " ({$this->minutes} min)";
    }
}

$items = [new Item('Dune'), new Dvd('Arrival', 116)];
foreach ($items as $item) {
    echo $item->describe(), "\n";
}
```

```text
Dune
Arrival (116 min)
```

- `parent::method()` calls the parent's version of a method. A child constructor must call `parent::__construct()`
  explicitly if the parent needs initializing; PHP does not do it automatically.
- `protected` members are visible to subclasses but not to outside code. `private` members of the parent exist in the
  child object but the child's code cannot touch them.
- A `Dvd` **is an** `Item`: it can be passed anywhere an `Item` is expected, and `$dvd instanceof Item` is true. Code
  written against `Item` works with every subclass. That substitutability is the whole point of inheritance.
- PHP has single inheritance: a class extends at most one class. Interfaces and traits (below) cover the other needs.

### `#[\Override]` (PHP 8.3+)

The `#[\Override]` attribute on a method says "this must override a parent method or implement an interface method".
If it does not, because of a typo or because the parent method was renamed, PHP refuses to compile the class:

```php
<?php

class A
{
    public function describe(): string { return 'A'; }
}

class B extends A
{
    #[\Override]
    public function describbe(): string { return 'B'; }  // typo
}
```

```text
PHP Fatal error:  B::describbe() has #[\Override] attribute, but no matching parent method exists in demo.php on line 11
```

Without the attribute, the typo silently creates a new method and the override never happens. Use `#[\Override]` on
every overriding method. (PHP 8.5 also allows it on properties.)

### Abstract classes

An **abstract** class cannot be instantiated; it exists to be extended. An **abstract method** has no body, and every
concrete subclass must implement it:

```php
<?php

declare(strict_types=1);

abstract class Notification
{
    public function __construct(protected string $memberName)
    {
    }

    // The template: fixed structure, one variable step.
    public function render(): string
    {
        return "Dear {$this->memberName},\n\n" . $this->body() . "\n\n-- Bookshelf\n";
    }

    abstract protected function body(): string;
}

final class OverdueNotice extends Notification
{
    public function __construct(string $memberName, private string $title, private int $days)
    {
        parent::__construct($memberName);
    }

    #[\Override]
    protected function body(): string
    {
        return "'{$this->title}' is {$this->days} days overdue. Please return it.";
    }
}

echo (new OverdueNotice('Ada', 'Dune', 4))->render();
```

```text
Dear Ada,

'Dune' is 4 days overdue. Please return it.

-- Bookshelf
```

This shape, a base class whose method calls an abstract "hook", is the *template method* pattern. `new Notification('x')`
throws `Error: Cannot instantiate abstract class Notification`, and a subclass that forgets `body()` is a compile error.

### `final`

`final` stops inheritance:

- A `final class` cannot be extended (`Class G cannot extend final class F`).
- A `final` method cannot be overridden.
- A `final` constant (PHP 8.1+) cannot be redefined by subclasses.

Mark classes `final` unless you have designed them to be extended. A class that was not designed for it usually has
assumptions a subclass can break, and once others extend it, every internal change risks breaking them. Removing
`final` later is easy; adding it later is a breaking change.

## Signature compatibility

An overriding method must be usable everywhere the parent's method is used. PHP enforces this with the **Liskov
substitution** rules:

- **Return types may be narrower** (covariant): if the parent returns `iterable`, the child may return `array`.
- **Parameter types may be wider** (contravariant): if the parent accepts `int`, the child may accept `int|string`.
- The child may add **optional** parameters, but not required ones.
- Visibility may be loosened (`protected` to `public`), never tightened.

```php
<?php

class A
{
    public function find(int $id): iterable { return []; }
}

class B extends A
{
    public function find(int|string $id): array { return []; }   // OK: wider param, narrower return
}

class C extends A
{
    public function find(string $id): array { return []; }       // not OK: narrower param
}
```

```text
PHP Fatal error:  Declaration of C::find(string $id): array must be compatible with A::find(int $id): Traversable|array in demo.php on line 15
```

The intuition: code holding an `A` may call `find(42)`. If the object is actually a `C`, that call must still work, and
whatever comes back must still be something the caller can iterate.

## Interfaces

An **interface** declares methods without implementing them: a contract that says "anything of this type can do
these things". A class `implements` any number of interfaces and must provide every method:

```php
<?php

declare(strict_types=1);

interface FinePolicy
{
    public function fineCents(int $daysOverdue): int;
}

final class PerDayFine implements FinePolicy
{
    public function __construct(private int $centsPerDay, private int $capCents)
    {
    }

    public function fineCents(int $daysOverdue): int
    {
        return min(max(0, $daysOverdue) * $this->centsPerDay, $this->capCents);
    }
}

final class NoFine implements FinePolicy
{
    public function fineCents(int $daysOverdue): int
    {
        return 0;
    }
}

function describeFine(FinePolicy $policy, int $days): string
{
    return sprintf('%d days late: %d cents', $days, $policy->fineCents($days));
}

echo describeFine(new PerDayFine(25, 500), 4), "\n";   // 4 days late: 100 cents
echo describeFine(new PerDayFine(25, 500), 40), "\n";  // 40 days late: 500 cents
echo describeFine(new NoFine(), 40), "\n";             // 40 days late: 0 cents
```

`describeFine()` does not know or care which policy it gets. This is **polymorphism**: one call,
`$policy->fineCents()`, with behavior chosen by the object. Adding a new policy means adding a class, not editing every
`if`/`match` that branches on member types.

Interface facts:

- All interface methods are public. Interfaces can declare constants, and (since PHP 8.4) property signatures with
  hooks, covered in chapter 11. They cannot hold state.
- An interface can `extend` one or more other interfaces.
- A class can extend a parent **and** implement interfaces: `class Ebook extends Item implements Downloadable, Countable`.
- Type parameters, properties and return values with interfaces wherever possible. Depending on `FinePolicy` rather
  than `PerDayFine` lets you swap the implementation, including in tests (chapter 20).

### Interface or abstract class?

| Use an interface when | Use an abstract class when |
|---|---|
| You are defining a capability or contract | You are sharing implementation between closely related classes |
| Unrelated classes may provide it | All subclasses are genuinely "kinds of" the base |
| You want several per class | One base is enough |

A common pattern combines them: an interface for the contract, and optionally an abstract base class that implements
the boring parts for convenience. Code depends only on the interface.

### Built-in interfaces worth knowing

| Interface | Implement it to... |
|---|---|
| `Countable` | Make `count($obj)` work (`count(): int`) |
| `Stringable` | Automatic for classes with `__toString()` |
| `JsonSerializable` | Control what `json_encode($obj)` produces (`jsonSerialize(): mixed`), chapter 14 |
| `IteratorAggregate`, `Iterator` | Make `foreach` work on your object, chapter 13 |
| `ArrayAccess` | Allow `$obj['key']` syntax (rarely a good idea in application code) |
| `Throwable` | Only via `Exception` or `Error`; you cannot implement it directly |

## Late static binding: `self` versus `static`

Inside a class, `self` always means **the class where the code is written**. `static` means **the class the method was
actually called on**, which differs when the method is inherited:

```php
<?php

declare(strict_types=1);

class Model
{
    public static function create(): static
    {
        return new static();
    }

    public static function createSelf(): self
    {
        return new self();
    }

    public static function names(): string
    {
        return static::class . ' / ' . self::class;
    }
}

final class Book extends Model
{
}

echo get_class(Book::create()), "\n";      // Book
echo get_class(Book::createSelf()), "\n";  // Model
echo Book::names(), "\n";                  // Book / Model
```

The `static` return type (PHP 8.0+) declares "returns an instance of the called class", which is what fluent methods and
inherited factory methods need. `static::CONSTANT` likewise lets a subclass override a constant used by inherited code.

## Traits

A **trait** is a bundle of methods (and properties, and since PHP 8.2 constants) that is copied into a class with
`use`. Traits solve a specific problem: sharing code between classes that are **not** related by inheritance.

```php
<?php

declare(strict_types=1);

trait RecordsEvents
{
    /** @var list<string> */
    private array $events = [];

    protected function record(string $event): void
    {
        $this->events[] = static::class . ': ' . $event;
    }

    /** @return list<string> */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }
}

final class Book
{
    use RecordsEvents;

    public function __construct(public readonly string $title, private int $copies)
    {
    }

    public function lendCopy(): void
    {
        $this->copies--;
        $this->record("lent '{$this->title}', {$this->copies} left");
    }
}

final class Member
{
    use RecordsEvents;

    public function __construct(public readonly string $name)
    {
    }

    public function renew(): void
    {
        $this->record("{$this->name} renewed membership");
    }
}

$book = new Book('Dune', 2);
$book->lendCopy();
$member = new Member('Ada');
$member->renew();

print_r([...$book->releaseEvents(), ...$member->releaseEvents()]);
```

```text
Array
(
    [0] => Book: lent 'Dune', 1 left
    [1] => Member: Ada renewed membership
)
```

How traits work and what to watch for:

- The trait's code behaves as if it had been written inside the class. `$this`, `self` and `static` refer to the using
  class, and private trait members become private members of the class.
- A trait can declare `abstract` methods that the using class must provide, and `static` methods.
- A trait is **not a type**: you cannot type a parameter as `RecordsEvents`, and `instanceof` does not work with it.
  Pair a trait with an interface when callers need a type (`interface HasEvents` + `trait RecordsEvents`).
- If two traits provide the same method, you must resolve the conflict:

```php
<?php

trait Hello
{
    public function hi(): string { return 'hello'; }
}

trait World
{
    public function hi(): string { return 'world'; }
}

final class Greeter
{
    use Hello, World {
        Hello::hi insteadof World;     // use Hello's hi()
        World::hi as protected worldHi; // keep World's under another name (and visibility)
    }

    public function both(): string
    {
        return $this->hi() . ' ' . $this->worldHi();
    }
}

echo (new Greeter())->both(), "\n"; // hello world
```

Traits are easy to overuse. Behavior injected by a trait is invisible at the call site, can depend on properties the
class happens to have, and makes classes harder to test in isolation. Good uses are small, self-contained helpers, like
`RecordsEvents` above. For anything with dependencies or its own state, prefer composition.

## Anonymous classes

`new class (...) { ... }` defines and instantiates a one-off class. It can extend a class and implement interfaces:

```php
<?php

declare(strict_types=1);

interface FinePolicy
{
    public function fineCents(int $daysOverdue): int;
}

$halfPrice = new class (12) implements FinePolicy {
    public function __construct(private int $centsPerDay) {}

    public function fineCents(int $daysOverdue): int
    {
        return max(0, $daysOverdue) * $this->centsPerDay;
    }
};

echo $halfPrice->fineCents(4), "\n"; // 48
```

They are handy in tests (a quick fake implementation) and for small adapters. A class that is used in more than one
place deserves a name.

## Composition over inheritance

Inheritance couples a subclass tightly to its parent's internals. The classic failure is a hierarchy built around one
axis of variation that then has to vary along another: `Member`, `PremiumMember`, `StaffMember`... and then
`StudentMember`, then `PremiumStudentMember`. **Composition** avoids this: instead of *being* a kind of thing, an
object *has* collaborators, usually typed by interface, and delegates to them.

```text
 Inheritance                          Composition
 ───────────                          ───────────
       Member                         Member ──has a──► FinePolicy (interface)
      /      \                                           ├── PerDayFine
 Premium    Staff                                        ├── NoFine
  Member    Member                                       └── ... any new policy
 (overrides fine())                   Member ──has a──► Notifier (interface)
                                                         ├── EmailNotifier
                                                         └── LogNotifier
```

A useful composition pattern is the **decorator**: a class that implements an interface by wrapping another
implementation of the same interface and adding behavior around it. Decorators let you stack features (logging,
caching, retries) without touching the wrapped class.

## Bookshelf: policies and notifiers

Members of different types get different fine rules, and the library wants to notify members about overdue books,
by e-mail in production and in a log during development. Both vary independently, so both become interfaces:

```php
<?php

declare(strict_types=1);

interface FinePolicy
{
    public function fineCents(int $daysOverdue): int;
}

final class PerDayFine implements FinePolicy
{
    public function __construct(private int $centsPerDay, private int $capCents)
    {
    }

    public function fineCents(int $daysOverdue): int
    {
        return min(max(0, $daysOverdue) * $this->centsPerDay, $this->capCents);
    }
}

final class GracePeriod implements FinePolicy
{
    // A decorator: no fine for the first $days, then delegate.
    public function __construct(private int $days, private FinePolicy $inner)
    {
    }

    public function fineCents(int $daysOverdue): int
    {
        return $this->inner->fineCents($daysOverdue - $this->days);
    }
}

final class NoFine implements FinePolicy
{
    public function fineCents(int $daysOverdue): int
    {
        return 0;
    }
}

interface Notifier
{
    public function notify(string $to, string $message): void;
}

final class EchoNotifier implements Notifier
{
    public function notify(string $to, string $message): void
    {
        echo "[to $to] $message\n";
    }
}

final class CountingNotifier implements Notifier
{
    // Another decorator: counts messages, then delegates.
    private int $sent = 0;

    public function __construct(private Notifier $inner)
    {
    }

    public function notify(string $to, string $message): void
    {
        $this->sent++;
        $this->inner->notify($to, $message);
    }

    public function sent(): int
    {
        return $this->sent;
    }
}

final class Member
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        private FinePolicy $finePolicy,
    ) {
    }

    public function fineFor(int $daysOverdue): int
    {
        return $this->finePolicy->fineCents($daysOverdue);
    }
}

final class OverdueReminder
{
    public function __construct(private Notifier $notifier)
    {
    }

    public function send(Member $member, string $title, int $daysOverdue): void
    {
        $fine = $member->fineFor($daysOverdue);
        $message = sprintf("'%s' is %d days overdue. Fine so far: $%.2f", $title, $daysOverdue, $fine / 100);
        $this->notifier->notify($member->email, $message);
    }
}

$standard = new PerDayFine(centsPerDay: 25, capCents: 500);

$members = [
    new Member('Ada', 'ada@example.org', $standard),
    new Member('Grace', 'grace@example.org', new GracePeriod(3, $standard)),
    new Member('Alan', 'alan@example.org', new NoFine()),
];

$notifier = new CountingNotifier(new EchoNotifier());
$reminder = new OverdueReminder($notifier);

foreach ($members as $member) {
    $reminder->send($member, 'Dune', 5);
}
echo "Sent {$notifier->sent()} reminders\n";
```

```text
[to ada@example.org] 'Dune' is 5 days overdue. Fine so far: $1.25
[to grace@example.org] 'Dune' is 5 days overdue. Fine so far: $0.50
[to alan@example.org] 'Dune' is 5 days overdue. Fine so far: $0.00
Sent 3 reminders
```

No class in this design knows about member "types". New rules ("students pay half", "no fines in December") are new
`FinePolicy` classes or decorators; a real e-mail notifier is a new `Notifier`; none of the existing code changes.
`OverdueReminder` receives its notifier through the constructor instead of creating it, which is *dependency
injection*; chapter 19 builds a container that wires such objects together automatically.

## Key takeaways

- `extends` inherits public and protected members; call `parent::__construct()` yourself. Use `#[\Override]` on every
  overriding method (8.3+).
- Abstract classes share implementation and force subclasses to fill in abstract methods. `final` classes cannot be
  extended; make classes final unless designed for extension.
- Overrides must be substitutable: narrower return types, wider parameter types, no new required parameters.
- Interfaces define contracts; code that depends on interfaces is flexible and testable. Polymorphism replaces
  type-switching.
- `static::` and the `static` return type refer to the called class; `self::` to the class where the code is written.
- Traits copy code into classes. They are not types; keep them small and self-contained.
- Prefer composition (has-a, typed by interface) and decorators to deep inheritance hierarchies.

## Exercises

1. **Shapes of media.** Create an abstract class `Media` with a constructor taking `title`, a concrete method
   `label(): string` returning `"<title> [<kind>]"`, and an abstract method `kind(): string`. Implement `Book`, `Dvd` and
   `AudioBook`. Print the labels of a mixed list.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

abstract class Media
{
    public function __construct(public readonly string $title)
    {
    }

    public function label(): string
    {
        return "{$this->title} [{$this->kind()}]";
    }

    abstract protected function kind(): string;
}

final class Book extends Media
{
    #[\Override]
    protected function kind(): string { return 'book'; }
}

final class Dvd extends Media
{
    #[\Override]
    protected function kind(): string { return 'DVD'; }
}

final class AudioBook extends Media
{
    #[\Override]
    protected function kind(): string { return 'audio book'; }
}

foreach ([new Book('Dune'), new Dvd('Arrival'), new AudioBook('Kindred')] as $media) {
    echo $media->label(), "\n";
}
```

```text
Dune [book]
Arrival [DVD]
Kindred [audio book]
```

</details>

2. **Countable shelf.** Write a `Shelf` class that holds titles and implements `Countable` and `Stringable` (via
   `__toString()`, listing the titles comma-separated). Show that `count($shelf)` and `echo $shelf` work, and that
   `$shelf instanceof Stringable` is true even though you did not write `implements Stringable`.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final class Shelf implements Countable
{
    /** @var list<string> */
    private array $titles = [];

    public function add(string $title): void
    {
        $this->titles[] = $title;
    }

    public function count(): int
    {
        return count($this->titles);
    }

    public function __toString(): string
    {
        return implode(', ', $this->titles);
    }
}

$shelf = new Shelf();
$shelf->add('Dune');
$shelf->add('Emma');

echo count($shelf), "\n";                       // 2
echo "Shelf: $shelf\n";                         // Shelf: Dune, Emma
var_dump($shelf instanceof Stringable);         // bool(true)
```

</details>

3. **Variance check.** Without running it, decide which of these overrides of
   `public function load(int $id): ?Book` are allowed, then verify:
   (a) `load(int $id): Book`, (b) `load(int|string $id): ?Book`, (c) `load(string $id): ?Book`,
   (d) `load(int $id, bool $fresh): ?Book`, (e) `load(int $id, bool $fresh = false): ?Book`,
   (f) `load(int $id): mixed`.

<details>
<summary>Solution</summary>

- (a) Allowed: `Book` is narrower than `?Book` (covariant return).
- (b) Allowed: `int|string` is wider than `int` (contravariant parameter).
- (c) Not allowed: `string` does not accept every `int`.
- (d) Not allowed: a new **required** parameter breaks callers that pass one argument.
- (e) Allowed: a new optional parameter.
- (f) Not allowed: `mixed` is wider than `?Book`; callers would get things that are not books.

```php
<?php

class Book {}

class Repo
{
    public function load(int $id): ?Book { return null; }
}

class A extends Repo { public function load(int $id): Book { return new Book(); } }
class B extends Repo { public function load(int|string $id): ?Book { return null; } }
class E extends Repo { public function load(int $id, bool $fresh = false): ?Book { return null; } }

echo "a, b, e compile\n";
// Uncomment one at a time to see the fatal error:
// class C extends Repo { public function load(string $id): ?Book { return null; } }
// class D extends Repo { public function load(int $id, bool $fresh): ?Book { return null; } }
// class F extends Repo { public function load(int $id): mixed { return null; } }
```

</details>

4. **A fluent builder with `static`.** Write an abstract `QueryBuilder` with methods `where(string $condition): static`
   and `limit(int $n): static` that store their arguments and return `$this`, plus an abstract `toSql(): string`.
   Write `BookQuery extends QueryBuilder` with an extra method `byAuthor(string $name): static` that calls `where()`.
   Show that `(new BookQuery())->where('year > 1900')->byAuthor('Austen')->limit(5)->toSql()` type-checks and works,
   and explain why `self` as the return type would break the chain.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

abstract class QueryBuilder
{
    /** @var list<string> */
    protected array $conditions = [];
    protected ?int $limit = null;

    public function where(string $condition): static
    {
        $this->conditions[] = $condition;
        return $this;
    }

    public function limit(int $n): static
    {
        $this->limit = $n;
        return $this;
    }

    abstract public function toSql(): string;
}

final class BookQuery extends QueryBuilder
{
    public function byAuthor(string $name): static
    {
        return $this->where("author LIKE '%$name%'");   // illustration only: never build SQL from input like this (chapter 17)
    }

    #[\Override]
    public function toSql(): string
    {
        $sql = 'SELECT * FROM books';
        if ($this->conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->conditions);
        }
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }
        return $sql;
    }
}

echo (new BookQuery())->where('year > 1900')->byAuthor('Austen')->limit(5)->toSql(), "\n";
```

```text
SELECT * FROM books WHERE year > 1900 AND author LIKE '%Austen%' LIMIT 5
```

With `self` as the return type of `where()`, the declared type of `(new BookQuery())->where(...)` would be
`QueryBuilder`, which has no `byAuthor()` method. At runtime it would still work (the object is a `BookQuery`), but
IDEs and static analysers would report an error. `static` says "the same class you called it on". (And as the comment
says, real queries must use prepared statements; chapter 17.)

</details>

5. **Decorate the notifier.** Using the `Notifier` interface from the Bookshelf section, write two decorators:
   `PrefixingNotifier` (adds `"[Bookshelf] "` in front of every message) and `QuietHoursNotifier` (drops messages when
   a given hour, passed to its constructor, is between 22 and 7, and otherwise delegates). Stack them around
   `EchoNotifier` and show that the order of decorators matters only where it should.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

interface Notifier
{
    public function notify(string $to, string $message): void;
}

final class EchoNotifier implements Notifier
{
    public function notify(string $to, string $message): void
    {
        echo "[to $to] $message\n";
    }
}

final class PrefixingNotifier implements Notifier
{
    public function __construct(private Notifier $inner, private string $prefix = '[Bookshelf] ')
    {
    }

    public function notify(string $to, string $message): void
    {
        $this->inner->notify($to, $this->prefix . $message);
    }
}

final class QuietHoursNotifier implements Notifier
{
    public function __construct(private Notifier $inner, private int $hour)
    {
    }

    public function notify(string $to, string $message): void
    {
        if ($this->hour >= 22 || $this->hour < 7) {
            return;   // dropped (a real version would queue it for the morning)
        }
        $this->inner->notify($to, $message);
    }
}

foreach ([9, 23] as $hour) {
    $notifier = new QuietHoursNotifier(new PrefixingNotifier(new EchoNotifier()), $hour);
    echo "at $hour:00\n";
    $notifier->notify('ada@example.org', 'Dune is due tomorrow');
}
```

```text
at 9:00
[to ada@example.org] [Bookshelf] Dune is due tomorrow
at 23:00
```

Swapping the two decorators (`new PrefixingNotifier(new QuietHoursNotifier(...))`) gives the same visible result,
because prefixing a message that is then dropped has no effect. Order matters when decorators interact, for example a
counting decorator outside the quiet-hours one counts dropped messages too, while inside it only counts sent ones.
Passing the hour in (instead of calling `date('G')` inside) keeps the class testable; chapter 15 introduces a proper
clock interface for this.

</details>

---
← Previous: [Classes and objects](09-classes-and-objects.md) | Next: [Enums, attributes and modern object features](11-enums-attributes-and-modern-objects.md) →
