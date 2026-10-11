# Chapter 11 — Enums, attributes and modern object features

## What you'll learn

- Enums (PHP 8.1+): pure and backed enums, methods, interfaces, constants, and why they beat string constants
- Readonly classes (PHP 8.2+)
- Property hooks (PHP 8.4+): validation and computed properties without getter/setter boilerplate
- Asymmetric visibility (PHP 8.4+): `public private(set)`
- Clone-with (PHP 8.5+) for immutable "wither" methods, and `final` promoted properties (PHP 8.5+)
- Attributes: the built-in ones (`#[\Override]`, `#[\Deprecated]`, `#[\NoDiscard]`, ...), writing your own, and
  reading them with reflection
- Magic methods (`__get`, `__call`, `__invoke`, `__serialize`, ...) and when not to use them
- Bookshelf's `MemberType` and `LoanStatus` enums and its `Loan` class

## Enums

Chapters 3 and 7 represented member types as strings: `'standard'`, `'premium'`, `'staff'`. Strings are a poor fit. A
typo (`'premuim'`) is a valid string, every function has to validate its input again, and nothing lists the allowed
values in one place. An **enum** (enumeration) is a type with a fixed set of possible values.

### Pure enums

```php
<?php

declare(strict_types=1);

enum LoanStatus
{
    case Active;
    case Overdue;
    case Returned;
}

function describe(LoanStatus $status): string
{
    return match ($status) {
        LoanStatus::Active => 'on loan',
        LoanStatus::Overdue => 'overdue!',
        LoanStatus::Returned => 'back on the shelf',
    };
}

echo describe(LoanStatus::Overdue), "\n";      // overdue!
var_dump(LoanStatus::Active === LoanStatus::Active); // bool(true)
var_dump(LoanStatus::Active->name);            // string(6) "Active"
var_dump(count(LoanStatus::cases()));          // int(3)
```

- Each `case` is a **singleton object**: there is exactly one `LoanStatus::Active`, so `===` compares them correctly.
- `LoanStatus` is a real type. A function that takes `LoanStatus` cannot receive anything else, so no validation is
  needed inside.
- `cases()` returns all cases in declaration order. `->name` is the case name as a string.
- A `match` over an enum without `default` throws `UnhandledMatchError` if a case is missing, and static analysers
  (chapter 20) report the missing arm before you even run the code. Adding a case to the enum then shows you every
  `match` that needs updating.

Enums cannot be instantiated with `new`, cannot have properties (state), cannot be extended, and cannot be used as array
keys (`Cannot access offset of type LoanStatus on array`); use `->name` or `->value` as the key, or a `WeakMap`/
`SplObjectStorage` (chapter 13). Comparing them with `<` is meaningless and always false.

### Backed enums

A **backed** enum gives every case a scalar value, `string` or `int`, for storing in a database, putting in a URL or
sending as JSON:

```php
<?php

declare(strict_types=1);

enum MemberType: string
{
    case Standard = 'standard';
    case Premium = 'premium';
    case Staff = 'staff';
}

var_dump(MemberType::Premium->value);         // string(7) "premium"
var_dump(MemberType::from('staff'));          // enum(MemberType::Staff)
var_dump(MemberType::tryFrom('guest'));       // NULL
echo json_encode(['type' => MemberType::Staff]), "\n"; // {"type":"staff"}

MemberType::from('guest');
```

```text
string(7) "premium"
enum(MemberType::Staff)
NULL
{"type":"staff"}
PHP Fatal error:  Uncaught ValueError: "guest" is not a valid backing value for enum MemberType
```

- `from($value)` returns the case or throws `ValueError`; `tryFrom($value)` returns `null` instead. These are your
  boundary parsers (chapter 7): call them once where input arrives.
- `json_encode()` encodes a backed enum as its value. (Pure enums cannot be JSON-encoded.)
- Backing values must be unique literals. Do not use them for display text; labels belong in a method, so that
  changing the wording does not break stored data.

### Methods, constants and interfaces

Enums can have methods (including static ones), constants, and can implement interfaces. This is where they become
powerful: the rules attached to each value live next to the values.

```php
<?php

declare(strict_types=1);

interface HasLabel
{
    public function label(): string;
}

enum MemberType: string implements HasLabel
{
    case Standard = 'standard';
    case Premium = 'premium';
    case Staff = 'staff';

    public const self DEFAULT = self::Standard;

    public function maxLoans(): int
    {
        return match ($this) {
            self::Standard => 3,
            self::Premium => 6,
            self::Staff => 10,
        };
    }

    public function loanDays(): int
    {
        return match ($this) {
            self::Standard => 21,
            self::Premium => 42,
            self::Staff => 60,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function fromInput(string $input): self
    {
        return self::tryFrom(strtolower(trim($input)))
            ?? throw new InvalidArgumentException("Unknown member type '$input'");
    }
}

$type = MemberType::fromInput(' Premium ');
echo "{$type->label()}: {$type->maxLoans()} books for {$type->loanDays()} days\n";
echo 'Default: ', MemberType::DEFAULT->label(), "\n";

foreach (MemberType::cases() as $case) {
    echo $case->value, ' ';
}
echo "\n";
```

```text
Premium: 6 books for 42 days
Default: Standard
standard premium staff
```

Compare this with exercise 5 of chapter 3, where the same rules were two separate `match` blocks on strings. Inside an
enum method, `$this` is the current case and `self::Case` refers to cases. All enums implement the built-in `UnitEnum`
interface, and backed ones also `BackedEnum`, which lets generic code (form builders, serializers) work with any enum.

Enum cases are allowed in constant expressions, so they can be default parameter values and class constants:
`function register(string $name, MemberType $type = MemberType::Standard)`.

## Readonly classes (PHP 8.2+)

When every property of a class should be readonly, declare the class `readonly`:

```php
<?php

declare(strict_types=1);

final readonly class LoanTerms
{
    public function __construct(
        public int $days,
        public int $maxRenewals,
        public int $finePerDayCents,
    ) {
    }
}

$terms = new LoanTerms(days: 21, maxRenewals: 2, finePerDayCents: 25);
echo $terms->days, "\n";
$terms->days = 30;   // Error: Cannot modify readonly property LoanTerms::$days
```

Every declared property becomes readonly (so it must be typed), and dynamic properties are forbidden outright. This is
the natural form for value objects and data-transfer objects. Remember that readonly is shallow: a readonly property
holding a mutable object can still have that object changed.

## Clone-with (PHP 8.5+)

Immutable objects need a way to produce a modified copy, a "wither": `$terms->withDays(42)`. Before PHP 8.5 you either
called the constructor again with every argument or cloned and re-assigned inside `__clone()`. PHP 8.5's `clone()`
accepts an array of property values to change on the copy:

```php
<?php

declare(strict_types=1);

final readonly class LoanTerms
{
    public function __construct(
        public int $days,
        public int $maxRenewals,
        public int $finePerDayCents,
    ) {
    }

    public function withDays(int $days): static
    {
        if ($days < 1) {
            throw new InvalidArgumentException('days must be positive');
        }
        return clone($this, ['days' => $days]);
    }
}

$standard = new LoanTerms(21, 2, 25);
$summer = $standard->withDays(42);

echo $standard->days, ' ', $summer->days, ' ', $summer->maxRenewals, "\n"; // 21 42 2
```

Details that matter:

- `clone($object, [...])` copies the object, runs `__clone()` if there is one, then assigns the listed properties,
  respecting their types, visibility and hooks.
- Readonly properties are writable only from the class's own scope (they are implicitly `protected(set)`), so
  clone-with on a readonly property works **inside the class**, as in `withDays()` above, but not from outside:
  `clone($standard, ['days' => 1])` in global code throws "Cannot modify protected(set) readonly property". That is a
  feature: the class decides which changes are allowed and can validate them.
- A property name that does not exist creates a dynamic property (with the usual deprecation), so typos are not
  silently ignored, but they are not a hard error either. Keep withers inside the class.

## Property hooks (PHP 8.4+)

Traditionally, PHP classes kept properties private and exposed `getName()`/`setName()` methods so that validation
could be added later. **Property hooks** attach `get` and `set` logic directly to a property, so a public property can
gain validation without changing its interface:

```php
<?php

declare(strict_types=1);

final class Member
{
    public string $name {
        set(string $value) {
            $value = trim($value);
            if ($value === '') {
                throw new InvalidArgumentException('Name cannot be empty');
            }
            $this->name = $value;
        }
    }

    public string $email {
        set => strtolower(trim($value));
    }

    public string $displayName {
        get => "{$this->name} <{$this->email}>";
    }

    public function __construct(string $name, string $email)
    {
        $this->name = $name;
        $this->email = $email;
    }
}

$member = new Member('  Ada Lovelace ', ' ADA@Example.org');
var_dump($member->name, $member->email, $member->displayName);

$member->displayName = 'x';   // Error: Property Member::$displayName is read-only
```

```text
string(12) "Ada Lovelace"
string(15) "ada@example.org"
string(30) "Ada Lovelace <ada@example.org>"
```

How hooks work:

- A **`set` hook** runs on every assignment, including the ones in the constructor. In the block form
  `set(string $value) { ... }` you assign the backing value yourself with `$this->name = ...`. The short form
  `set => expression;` assigns the expression's result to the property. The parameter is called `$value` if you do not
  declare it.
- A **`get` hook** runs on every read. `get => expression;` returns the expression.
- Inside its own hooks, `$this->name` accesses the stored ("backing") value directly; anywhere else it goes through
  the hooks.
- A property whose hooks never refer to its own backing value is **virtual**: it has no storage, like `displayName`.
  A virtual property with only `get` is read-only. Virtual properties do not appear in `var_dump()` or `(array)` casts,
  but `json_encode()` and `foreach` over the object do read them.
- Hooked properties cannot be `readonly`. Use asymmetric visibility (next section) or no `set` hook instead.
- Interfaces can declare properties with hooks (`public string $title { get; }`), which any class with a readable
  `$title` satisfies, including one with a plain `public readonly string $title`.

One trap: the short `set =>` form always **stores** its result in the property. A setter meant to update a *different*
property must use the block form:

```php
public float $fahrenheit {
    get => $this->celsius * 9 / 5 + 32;
    set(float $value) {
        $this->celsius = ($value - 32) * 5 / 9;   // block form: nothing stored in $fahrenheit
    }
}
```

With `set => $this->celsius = ...` instead, the assignment expression's value would also be stored in a backing
`$fahrenheit`, making the property non-virtual.

When to use hooks: light validation or normalization (trimming, lower-casing), and cheap derived values. Expensive
work or anything with side effects belongs in an explicit method, because a reader of `$member->displayName` expects a
simple read.

## Asymmetric visibility (PHP 8.4+)

Often a property should be readable by everyone but changed only by the class itself, while still being mutable over
time (so `readonly` does not fit). Asymmetric visibility declares a separate visibility for writing:

```php
<?php

declare(strict_types=1);

final class Member
{
    public private(set) int $loanCount = 0;

    public function borrow(): void
    {
        $this->loanCount++;
    }
}

$member = new Member();
$member->borrow();
echo $member->loanCount, "\n"; // 1

$member->loanCount = 10;
```

```text
1
PHP Fatal error:  Uncaught Error: Cannot modify private(set) property Member::$loanCount from global scope
```

- `public private(set)` means public read, private write; `public protected(set)` allows subclasses to write too.
- If the read visibility is `public`, it can be omitted: `private(set) int $loanCount` means the same.
- It works with constructor promotion: `public function __construct(public private(set) string $status)`.
- PHP 8.5 extends asymmetric visibility to `static` properties.

This removes most of the reason for getter methods: `$member->loanCount` is as safe as `$member->loanCount()`, and
reads more naturally.

### `final` promoted properties (PHP 8.5+)

Constructor promotion now accepts `final`, which stops subclasses from redeclaring the property:
`public function __construct(final public int $id)`. (Promotion with `final` alone implies `public`.)

## Attributes

**Attributes** (PHP 8.0+) are structured metadata attached to classes, methods, functions, properties, parameters,
class constants and, since PHP 8.5, global constants. The syntax is `#[Name(arguments)]`. Attributes do nothing on
their own; code reads them (the engine, for the built-in ones; frameworks and tools, for others).

### Built-in attributes

| Attribute | Since | Effect |
|---|---|---|
| `#[\Override]` | 8.3 | Compile error if the method does not override anything (on properties since 8.5) |
| `#[\Deprecated]` | 8.4 | Calling the function/method (or, 8.5+, using the constant) emits `E_USER_DEPRECATED` |
| `#[\NoDiscard]` | 8.5 | Warns when the return value is ignored |
| `#[\SensitiveParameter]` | 8.2 | Hides the argument in stack traces (chapter 8) |
| `#[\AllowDynamicProperties]` | 8.2 | Opts a class back in to dynamic properties |
| `#[\ReturnTypeWillChange]` | 8.1 | Silences return-type deprecations when extending built-in classes in old code |

```php
<?php

declare(strict_types=1);

final class Catalog
{
    #[\Deprecated(message: 'use findById()', since: '2.0')]
    public function find(int $id): void
    {
    }
}

#[\NoDiscard('the renewed loan must be saved')]
function renewLoan(array $loan): array
{
    return [...$loan, 'renewals' => $loan['renewals'] + 1];
}

(new Catalog())->find(1);
renewLoan(['renewals' => 0]);           // result ignored: probably a bug
(void) renewLoan(['renewals' => 0]);    // explicitly discarded: no warning (PHP 8.5+)
```

```text
Deprecated: Method Catalog::find() is deprecated since 2.0, use findById() in demo.php on line 19

Warning: The return value of function renewLoan() should either be used or intentionally ignored by casting it as (void), the renewed loan must be saved in demo.php on line 20
```

`#[\NoDiscard]` suits functions whose only effect is their return value, especially "with"-style functions on
immutable data, where forgetting to use the result means the change is lost. The `(void)` cast was added in PHP 8.5
for exactly this case.

### Your own attributes

An attribute is an ordinary class marked with `#[Attribute]`. Its constructor arguments are the attribute's
arguments. You read attributes through the **Reflection** API, which lets a program inspect classes, methods and
properties at runtime:

```php
<?php

declare(strict_types=1);

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /** @param list<string> $methods */
    public function __construct(
        public string $path,
        public array $methods = ['GET'],
    ) {
    }
}

final class BookController
{
    #[Route('/books')]
    public function index(): string
    {
        return 'list of books';
    }

    #[Route('/books/{id}')]
    #[Route('/books/{id}', methods: ['POST'])]
    public function show(int $id): string
    {
        return "book $id";
    }
}

foreach ((new ReflectionClass(BookController::class))->getMethods() as $method) {
    foreach ($method->getAttributes(Route::class) as $attribute) {
        $route = $attribute->newInstance();
        printf("%-5s %-12s -> %s()\n", implode('|', $route->methods), $route->path, $method->getName());
    }
}
```

```text
GET   /books       -> index()
GET   /books/{id}  -> show()
POST  /books/{id}  -> show()
```

- `Attribute::TARGET_*` flags restrict where the attribute may be used; `IS_REPEATABLE` allows it more than once on
  the same element. A violated target is detected when `newInstance()` is called: "Attribute "Route" cannot target
  function (allowed targets: method)".
- `getAttributes()` returns `ReflectionAttribute` objects; `newInstance()` actually constructs the attribute class.
  The class does not even need to exist until then.
- Attribute arguments must be constant expressions. PHP 8.5 allows static closures and first-class callables there
  too, so an attribute can carry a small piece of logic.

This exact mechanism is how Symfony and other frameworks map URLs to controller methods, and how ORMs map properties
to database columns. Chapter 19 uses the `Route` attribute to build Bookshelf's router.

> **Just a taste — you don't need to learn or fully understand this now.** Lazy objects (PHP 8.4+) are another
> reflection feature, used by dependency-injection containers and ORMs to delay expensive construction until an object
> is first used.

```php
$catalog = (new ReflectionClass(Catalog::class))->newLazyGhost(function (Catalog $c): void {
    $c->__construct(loadEverythingFromTheDatabase());
});
// nothing has been loaded yet; the first property access triggers the initializer
```

## Magic methods

Methods whose names start with `__` are hooks the engine calls in specific situations. You have met `__construct`,
`__destruct`, `__clone` and `__toString`. The others:

| Method | Called when |
|---|---|
| `__get($name)`, `__set($name, $value)` | Reading/writing an inaccessible or undeclared property |
| `__isset($name)`, `__unset($name)` | `isset()`/`unset()` on such a property |
| `__call($name, $args)` | Calling an inaccessible or undefined method |
| `__callStatic($name, $args)` | The same, statically |
| `__invoke(...)` | The object is called like a function: `$obj(...)` |
| `__serialize()`, `__unserialize(array $data)` | `serialize()`/`unserialize()` (replace the older `__sleep`/`__wakeup`, soft-deprecated in 8.5) |
| `__debugInfo()` | `var_dump()` of the object |
| `__set_state(array $props)` | `var_export()` output is evaluated |

```php
<?php

declare(strict_types=1);

final class Settings
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? throw new OutOfBoundsException("No setting '$name'");
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __invoke(string $name, mixed $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    public function __debugInfo(): array
    {
        return ['keys' => array_keys($this->data)];   // never dump the values: they may be secrets
    }
}

$settings = new Settings(['loanDays' => 21, 'dbPassword' => 's3cret']);
echo $settings->loanDays, "\n";             // 21, via __get
echo $settings('maxLoans', 3), "\n";        // 3, via __invoke
var_dump($settings);
```

```text
21
3
object(Settings)#1 (1) {
  ["keys"]=>
  array(2) {
    [0]=>
    string(8) "loanDays"
    [1]=>
    string(10) "dbPassword"
  }
}
```

Use magic methods sparingly. `__get` and `__call` make properties and methods that do not exist anywhere in the code:
IDEs cannot complete them, static analysers cannot check them, and typos become runtime errors. Property hooks and
asymmetric visibility now cover most of what `__get`/`__set` were used for. Reasonable uses remain: `__invoke` for
single-method "action" objects that can be passed as callables, `__serialize`/`__unserialize` for controlling what is
stored, and `__debugInfo` for keeping secrets out of dumps. Frameworks (notably Laravel's Eloquent models) rely on magic
heavily, which is why they ship IDE helper tools to describe it.

## Bookshelf: enums and the `Loan` class

The member type and loan status become enums, and a `Loan` class uses asymmetric visibility and a virtual property.
Dates appear here for the first time as `DateTimeImmutable` objects; chapter 15 explains them fully, but the calls used
here read naturally: `modify('+21 days')`, `format('Y-m-d')`, and `<`/`>` to compare.

```php
<?php

declare(strict_types=1);

enum MemberType: string
{
    case Standard = 'standard';
    case Premium = 'premium';
    case Staff = 'staff';

    public function maxLoans(): int
    {
        return match ($this) {
            self::Standard => 3,
            self::Premium => 6,
            self::Staff => 10,
        };
    }

    public function loanDays(): int
    {
        return match ($this) {
            self::Standard => 21,
            self::Premium => 42,
            self::Staff => 60,
        };
    }

    public function finePerDayCents(): int
    {
        return $this === self::Staff ? 0 : 25;
    }
}

enum LoanStatus: string
{
    case Active = 'active';
    case Overdue = 'overdue';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'On loan',
            self::Overdue => 'Overdue',
            self::Returned => 'Returned',
        };
    }
}

final class Loan
{
    public const int MAX_RENEWALS = 2;

    public private(set) DateTimeImmutable $dueOn;
    public private(set) ?DateTimeImmutable $returnedOn = null;
    public private(set) int $renewals = 0;

    public bool $isReturned {
        get => $this->returnedOn !== null;
    }

    public function __construct(
        public readonly string $title,
        public readonly MemberType $memberType,
        public readonly DateTimeImmutable $lentOn,
    ) {
        $this->dueOn = $lentOn->modify("+{$memberType->loanDays()} days");
    }

    public function status(DateTimeImmutable $today): LoanStatus
    {
        return match (true) {
            $this->isReturned => LoanStatus::Returned,
            $today > $this->dueOn => LoanStatus::Overdue,
            default => LoanStatus::Active,
        };
    }

    public function renew(): void
    {
        if ($this->isReturned) {
            throw new LogicException('Cannot renew a returned loan');
        }
        if ($this->renewals >= self::MAX_RENEWALS) {
            throw new LogicException("'{$this->title}' was already renewed " . self::MAX_RENEWALS . ' times');
        }
        $this->renewals++;
        $this->dueOn = $this->dueOn->modify("+{$this->memberType->loanDays()} days");
    }

    public function markReturned(DateTimeImmutable $on): void
    {
        $this->returnedOn ??= $on;
    }

    public function fineCents(DateTimeImmutable $today): int
    {
        $end = $this->returnedOn ?? $today;
        if ($end <= $this->dueOn) {
            return 0;
        }
        $daysLate = $this->dueOn->diff($end)->days;

        return $daysLate * $this->memberType->finePerDayCents();
    }
}

$today = new DateTimeImmutable('2026-10-11');

$loans = [
    new Loan('Dune', MemberType::Standard, new DateTimeImmutable('2026-09-01')),
    new Loan('Kindred', MemberType::Premium, new DateTimeImmutable('2026-09-01')),
    new Loan('Emma', MemberType::Staff, new DateTimeImmutable('2026-08-01')),
];

$loans[1]->renew();
$loans[2]->markReturned(new DateTimeImmutable('2026-10-05'));

foreach ($loans as $loan) {
    printf(
        "%-8s %-9s due %s  %-8s fine %3d cents\n",
        $loan->title,
        $loan->memberType->value,
        $loan->dueOn->format('Y-m-d'),
        $loan->status($today)->label(),
        $loan->fineCents($today),
    );
}

try {
    $loans[0]->renewals = 0;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
```

```text
Dune     standard  due 2026-09-22  Overdue  fine 475 cents
Kindred  premium   due 2026-11-24  On loan  fine   0 cents
Emma     staff     due 2026-09-30  Returned fine   0 cents
Cannot modify private(set) property Loan::$renewals from global scope
```

Notice how little validation the class needs: `MemberType` cannot be an invalid string, the dates are real date
objects, and `renewals`/`dueOn` can only change through `renew()`, which enforces the limit. The `$today` parameter is
passed in rather than read from the clock inside the class, which keeps `status()` and `fineCents()` deterministic and
testable (chapter 15 introduces a `Clock` interface for this).

## Key takeaways

- Enums model a fixed set of values as a real type. Backed enums (`: string`/`: int`) convert with `from()`/`tryFrom()`
  and serialize to their value. Put the rules for each case in enum methods; `match` over cases without `default` makes
  missing cases loud.
- `readonly class` makes every property readonly; combine with clone-with (8.5+) for immutable withers, written inside
  the class.
- Property hooks (8.4+) add `get`/`set` logic to properties; virtual properties compute values. The short `set =>` form
  stores its result.
- `public private(set)` (8.4+) gives read access to everyone and write access to the class only.
- Attributes are metadata read by the engine (`#[\Override]`, `#[\Deprecated]`, `#[\NoDiscard]`) or by your code via
  Reflection, the basis of attribute routing in frameworks.
- Magic methods are powerful but invisible to tools; prefer declared properties, hooks and methods. `__debugInfo` and
  `__serialize` are the safe, useful ones.

## Exercises

1. **A genre enum.** Create a backed enum `Genre` with cases for science fiction, fantasy, crime and classic (string
   values `'sf'`, `'fantasy'`, `'crime'`, `'classic'`), a method `shelf(): string` returning `'A'`, `'A'`, `'B'`, `'C'`,
   and a static method `options(): array` returning `[value => label]` pairs for a `<select>` element.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

enum Genre: string
{
    case ScienceFiction = 'sf';
    case Fantasy = 'fantasy';
    case Crime = 'crime';
    case Classic = 'classic';

    public function shelf(): string
    {
        return match ($this) {
            self::ScienceFiction, self::Fantasy => 'A',
            self::Crime => 'B',
            self::Classic => 'C',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ScienceFiction => 'Science fiction',
            self::Fantasy => 'Fantasy',
            self::Crime => 'Crime',
            self::Classic => 'Classic',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $g): array => ['value' => $g->value, 'label' => $g->label()], self::cases()),
            'label',
            'value',
        );
    }
}

echo Genre::from('crime')->shelf(), "\n";   // B
print_r(Genre::options());
```

```text
B
Array
(
    [sf] => Science fiction
    [fantasy] => Fantasy
    [crime] => Crime
    [classic] => Classic
)
```

A simple `foreach (self::cases() as $g) { $out[$g->value] = $g->label(); }` is just as good and arguably clearer.

</details>

2. **Exhaustiveness.** Add a case `Lost` to the `LoanStatus` enum from the Bookshelf section without changing its
   `label()` method. What happens when you call `LoanStatus::Lost->label()`? What would happen if `label()` had a
   `default` arm, and why is the error preferable?

<details>
<summary>Solution</summary>

```text
PHP Fatal error:  Uncaught UnhandledMatchError: Unhandled match case LoanStatus::Lost
```

The `match` has no arm for the new case, so it throws. With a `default => 'Unknown'` arm, the program would silently
show "Unknown" to users wherever lost books appear, and nobody would notice that the label was never written. The
error points directly at the code that needs updating; a static analyser such as PHPStan reports it even before the
code runs ("Match expression does not handle remaining value").

</details>

3. **Hooks for an ISBN property.** Write a class `CatalogEntry` with a public `string $isbn` property whose `set` hook
   strips spaces and dashes and throws `InvalidArgumentException` unless the result is 13 digits, and a virtual
   read-only property `string $prefix` returning the first three digits. Show that assigning `'978-0-441-01359-3'`
   stores `'9780441013593'`.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final class CatalogEntry
{
    public string $isbn {
        set(string $value) {
            $digits = preg_replace('/[\s-]+/', '', $value);
            if (preg_match('/^\d{13}$/', $digits) !== 1) {
                throw new InvalidArgumentException("Not an ISBN-13: '$value'");
            }
            $this->isbn = $digits;
        }
    }

    public string $prefix {
        get => substr($this->isbn, 0, 3);
    }

    public function __construct(string $isbn)
    {
        $this->isbn = $isbn;
    }
}

$entry = new CatalogEntry('978-0-441-01359-3');
var_dump($entry->isbn, $entry->prefix);

try {
    $entry->isbn = '12-34';
} catch (InvalidArgumentException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
string(13) "9780441013593"
string(3) "978"
Not an ISBN-13: '12-34'
```

This is a "validated public property": callers use plain property syntax and still cannot store an invalid value.
For full checksum validation, the `Isbn` value object from chapter 9 is a better design, and the property would then be
typed `Isbn`.

</details>

4. **Withers.** Make `final readonly class SearchQuery` with `string $text`, `?Genre $genre` (use the enum from
   exercise 1 or a simplified one), `int $page = 1`. Add `withText()`, `withGenre()` and `nextPage()` using clone-with,
   with `withText()` resetting the page to 1. Show that the original query is unchanged.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

enum Genre: string
{
    case ScienceFiction = 'sf';
    case Classic = 'classic';
}

final readonly class SearchQuery
{
    public function __construct(
        public string $text = '',
        public ?Genre $genre = null,
        public int $page = 1,
    ) {
    }

    public function withText(string $text): static
    {
        return clone($this, ['text' => trim($text), 'page' => 1]);
    }

    public function withGenre(?Genre $genre): static
    {
        return clone($this, ['genre' => $genre, 'page' => 1]);
    }

    public function nextPage(): static
    {
        return clone($this, ['page' => $this->page + 1]);
    }
}

$q1 = new SearchQuery();
$q2 = $q1->withText(' dune ')->withGenre(Genre::ScienceFiction)->nextPage()->nextPage();
$q3 = $q2->withText('emma');

printf("q1: '%s' %s p%d\n", $q1->text, $q1->genre?->value ?? 'any', $q1->page);
printf("q2: '%s' %s p%d\n", $q2->text, $q2->genre?->value ?? 'any', $q2->page);
printf("q3: '%s' %s p%d\n", $q3->text, $q3->genre?->value ?? 'any', $q3->page);
```

```text
q1: '' any p1
q2: 'dune' sf p3
q3: 'emma' sf p1
```

`$q1->genre?->value` uses the nullsafe operator: it is `null` when there is no genre, and `??` turns that into
`'any'`. Each wither returns a new object, so all three queries coexist.

</details>

5. **Attribute-driven validation.** Write two attributes, `#[NotBlank]` and `#[Length(min: ..., max: ...)]`, that can
   target properties, and a function `validate(object $o): list<string>` that uses reflection to check every public
   property of an object against its attributes and returns error messages. Apply it to a `NewMember` form object
   with `name` and `email` properties.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

#[Attribute(Attribute::TARGET_PROPERTY)]
final class NotBlank
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Length
{
    public function __construct(public int $min = 0, public int $max = PHP_INT_MAX)
    {
    }
}

final class NewMember
{
    public function __construct(
        #[NotBlank, Length(min: 2, max: 40)]
        public string $name,
        #[NotBlank]
        public string $email,
    ) {
    }
}

/** @return list<string> */
function validate(object $object): array
{
    $errors = [];
    $class = new ReflectionObject($object);

    foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $value = $property->getValue($object);
        $name = $property->getName();

        foreach ($property->getAttributes() as $attribute) {
            $rule = $attribute->newInstance();
            $error = match (true) {
                $rule instanceof NotBlank => trim((string) $value) === '' ? "$name must not be blank" : null,
                $rule instanceof Length => mb_strlen((string) $value) < $rule->min || mb_strlen((string) $value) > $rule->max
                    ? "$name must be {$rule->min}-{$rule->max} characters"
                    : null,
                default => null,
            };
            if ($error !== null) {
                $errors[] = $error;
            }
        }
    }

    return $errors;
}

print_r(validate(new NewMember('A', '')));
print_r(validate(new NewMember('Ada', 'ada@example.org')));
```

```text
Array
(
    [0] => name must be 2-40 characters
    [1] => email must not be blank
)
Array
(
)
```

Attributes on promoted constructor parameters apply to both the parameter and the property (the `TARGET_PROPERTY` flag
selects which one may use them). This is a miniature version of the Symfony Validator component, which works exactly
this way.

</details>

---
← Previous: [Inheritance, interfaces and traits](10-inheritance-interfaces-and-traits.md) | Next: [Namespaces, autoloading and Composer](12-namespaces-autoloading-and-composer.md) →
