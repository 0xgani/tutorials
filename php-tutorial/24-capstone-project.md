# Chapter 24 — Capstone project: Bookshelf

## What you'll learn

- How the finished Bookshelf fits together: its layers, the direction of its dependencies, and the path one request
  takes through the code
- How to finish an application: turning a brief into routes, use cases and tests, and adding features without
  disturbing what works
- How to make logged-in flows testable by putting an interface around PHP's native sessions
- How to give one use case several front ends (a web page and a command) without duplicating its logic
- Authorization by ownership: why "logged in" is not the same as "allowed", and how to answer requests for other
  people's data
- How to review a whole application before release: security, performance, operations and what's deliberately left out

This chapter is different from the others. It doesn't introduce new language features. It takes everything from
chapters 1–23 and finishes the application you've been building. Bookshelf already has a catalog, logins, a JSON API,
a command-line tool, tests and container files. What it still lacks is the part a library actually runs on: **lending
books over the counter**. The service that implements the rules (`LendingService`, chapter 17) has existed for a while,
but nothing in the web application calls it.

You'll add that last feature the way you would in a real codebase: read the brief, find where each part belongs, close
the testing gap chapter 20 left open, write the code and its tests, try it end to end, and finally review the whole
application. The complete source is in [`examples/bookshelf/`](examples/bookshelf/), with its own
[README](examples/bookshelf/README.md).

## The brief

The library's volunteers wrote down what they need. Briefs like this are rarely precise, so the right-hand column
records how each point was interpreted.

| The volunteers wrote | What that means for Bookshelf |
|---|---|
| "At the desk we look up the member, scan the book and hand it over." | A staff-only **lending desk** page: find a member by e-mail address, lend a book by its ISBN |
| "Same at the desk when it comes back. Tell us if there's a fine." | A **Take back** button per open loan; the confirmation shows the fine to collect |
| "Members should be able to extend a loan themselves." | A **Renew** button on "My loans", for loans that can be renewed (chapter 9's rules) |
| "We need to see what's overdue, not only in the morning e-mail." | The **overdue list** on the desk page, the same report as `loans:overdue` (chapter 22) |
| "Don't let members see each other's loans." | Every member route works on the logged-in member's own data; other people's loans don't exist for them |
| "It has to keep working when we're not around." | Tests for each flow, CI that runs every check, the deployment from chapter 22 |

The rules themselves are not new. Standard members borrow up to 3 books for 21 days, premium members 6 for 42 days,
staff 10 for 60 days. A loan can be renewed twice, never once it's overdue. Late returns cost 25 cents a day, capped at
$10, and staff pay no fines. All of this lives in `MemberType`, `Loan` and `LendingService` already, which is exactly
why the new feature is small.

## The finished application

### What it does

| Area | Routes and commands | Who |
|---|---|---|
| Catalog | `GET /`, `GET /books/{id}` | everyone |
| JSON API | `GET /api/books?q=&genre=`, `GET /api/books/{id}` | everyone |
| Suggestions | `GET /suggest`, `POST /suggest`, `GET /suggestions` | everyone; the list is staff only |
| Accounts | `GET /login`, `POST /login`, `POST /logout` | everyone |
| My loans | `GET /loans`, `POST /loans/{id}/renew` | members |
| Lending desk | `GET /desk`, `POST /desk/loans`, `POST /desk/loans/{id}/return` | staff |
| Operations | `GET /health` | load balancers and monitoring |
| Command line | `db:migrate`, `import`, `member:add`, `member:password`, `isbn`, `loans:overdue` | administrators, cron |

### Its layers

Bookshelf is about 4,100 lines of PHP in 77 classes, plus about 1,000 lines of tests. Every class belongs to one of
these layers, and each layer may only use the layers below it:

```text
 ┌───────────────────────────────────────────────────────────────────────────────────────┐
 │ Entry points   public/index.php (web)           bin/bookshelf (command line)          │
 ├───────────────────────────────────────────────────────────────────────────────────────┤
 │ Delivery       Web/: controllers, middleware,   Cli/: Application, Input, Output,     │
 │                session, views, templates/       commands                              │
 │                Http/: Request, Response, Router, Pipeline (a tiny framework)          │
 ├───────────────────────────────────────────────────────────────────────────────────────┤
 │ Use cases      Lending/: LendingService (lend, return, renew), OverdueReport          │
 │                Security/: Authenticator, LoginThrottle    Import/: CsvBookReader      │
 ├───────────────────────────────────────────────────────────────────────────────────────┤
 │ Domain         Domain/: Book, Member, Loan, Isbn, Money, Genre, MemberType, ...       │
 │                and the repository interfaces BookRepository, MemberRepository, ...    │
 ├───────────────────────────────────────────────────────────────────────────────────────┤
 │ Infrastructure Persistence/: Database, Migrator, PdoBookRepository, ...               │
 │                Support/: Clock, SystemClock, FrozenClock     Container/: Container    │
 └───────────────────────────────────────────────────────────────────────────────────────┘
```

The domain sits in the middle and knows nothing about HTTP, SQL or the terminal. `Loan` doesn't know it's stored in
SQLite; it knows how fines work. The persistence classes *implement* interfaces the domain defines
(`BookRepository`), so the arrow of dependency points inward even though the code runs outward. That's why chapter
23's comparison could say "the domain stays yours": you could put a Symfony or Laravel delivery layer on top and keep
`Domain/` and `Lending/` unchanged.

### Where each chapter lives

Every chapter left something behind in the code. When you want to see an idea used in context, this is where to look:

| Chapters | Topic | Where in Bookshelf |
|---|---|---|
| 2–5 | Values, strings, arrays | Everywhere; `Support/Text.php`, `Support/DisplayFormatter.php`, the array juggling in repositories |
| 6 | Functions and closures | Container factories in `config/container.php`, `Router::compile()`'s callback, `Database::transactional()` |
| 7 | Types and strict types | `declare(strict_types=1)` in every file; union types such as `catch (LoanRuleViolation\|BookNotAvailable $e)` |
| 8 | Errors and exceptions | `Domain/BookshelfException` and its children, `ErrorHandlerMiddleware`, `HttpException` |
| 9 | Classes and objects | Value objects `Money` and `Isbn`; entities `Book`, `Member`, `Loan` |
| 10 | Interfaces and composition | Repositories, `Clock`, `Middleware`, `RequestHandler`, `Command`, `SessionStorage` |
| 11 | Enums, attributes, modern objects | `Genre`, `MemberType`, `LoanStatus`, `Access`; `#[Route]`; `Loan::$isReturned` (a property hook); `private(set)`; clone-with in `Request::withAttribute()` |
| 12 | Composer and autoloading | `composer.json`, PSR-4 for `src/` and `tests/`, scripts |
| 13 | Generators | `CsvBookReader::read()` streams any size of file |
| 14 | Files and the CLI | `bin/bookshelf`, `Cli/`, `ImportBooksCommand` |
| 15 | Dates and times | `Clock`, `FrozenClock`, dates in `PdoLoanRepository`, the library's time zone setting |
| 16 | PHP on the web | `public/index.php`, `Request::fromGlobals()`, sessions, POST-redirect-GET |
| 17 | PDO | `Persistence/`, `migrations/`, transactions in `LendingService` |
| 18 | Security | `Security/`, `CsrfMiddleware`, `SecurityHeadersMiddleware`, `e()`, `AuthController::localPath()` |
| 19 | An app without a framework | `Http/`, `Container/`, `Web/` |
| 20 | Testing and static analysis | `tests/`, `phpunit.xml.dist`, `phpstan.neon`, `.php-cs-fixer.dist.php` |
| 21 | Performance | `config/preload.php`, the authoritative class map in the Dockerfile |
| 22 | Deployment | `Dockerfile`, `compose.yaml`, `docker/`, `HealthController`, `OverdueCommand` |

### One request, all the way through

Here is what happens when a librarian clicks **Lend** on the desk page, which sends `POST /desk/loans` with the
member's e-mail address, the ISBN and the CSRF token:

```text
nginx ──FastCGI──▶ public/index.php
                   │  require vendor/autoload.php, config/container.php
                   │  Request::fromGlobals()                         $_SERVER, $_POST, $_COOKIE → one immutable object
                   ▼
                   Kernel::handle()  →  Pipeline, outermost middleware first
                   │
                   ├─ SecurityHeadersMiddleware   (adds CSP, nosniff, ... to whatever comes back)
                   ├─ ErrorHandlerMiddleware      (turns any exception below into an error page)
                   ├─ AuthenticationMiddleware    session member_id → Member, request attribute 'member'
                   ├─ CsrfMiddleware              POST without the session's token → 403
                   ▼
                   Dispatcher::handle()
                   │  Router::match('POST', '/desk/loans')        → [DeskController, lend], Access::Staff
                   │  not logged in → 303 to /login;  not staff → 403
                   │  Container builds DeskController (autowired), Dispatcher passes the Request
                   ▼
                   DeskController::lend()
                   │  MemberRepository::findByEmail(), BookRepository::findByIsbn()
                   │  LendingService::lend()  ── one transaction ──▶  rules in Member, Book, Loan
                   │                                                   PdoBookRepository::save(), PdoLoanRepository::save()
                   │  Session::flash('Lent "Kindred" to Ada Lovelace, ...')
                   ▼
                   Response::redirect('/desk?member=ada%40example.org')   303 See Other
                   │
                   ▲  back out through the middleware (headers added), then Response::send()
```

Each box does one job, and each could be tested on its own. Most of chapter 19 was about making this chain explicit,
and it pays off now: adding the lending desk means adding one controller and one template, and registering one
class. Nothing in the chain above changes.

## Running it

From `examples/bookshelf/`, with PHP 8.5 and Composer installed:

```bash
composer install
php bin/bookshelf db:migrate
php bin/bookshelf import data/books.csv
php bin/bookshelf member:add "Grace Hopper" grace@example.org --type=staff
php bin/bookshelf member:add "Ada Lovelace" ada@example.org
php bin/bookshelf member:password grace@example.org
php bin/bookshelf member:password ada@example.org
composer serve
```

```text
applied 001_initial_schema.sql
applied 002_create_suggestions.sql
applied 003_create_login_attempts.sql
3 migrations applied
10 books imported, 0 already in the catalog, 0 invalid lines skipped
1
Added Grace Hopper as a Staff member with id 1
2
Added Ada Lovelace as a Standard member with id 2
New password:
Password set for Grace Hopper
New password:
Password set for Ada Lovelace
```

(`member:add` prints the new id on standard output and the message on standard error, so a script can capture the id.
`member:password` reads the password without echoing it.) Open <http://localhost:8000>, log in as Grace, and the
navigation shows **Lending desk**. The rest of this chapter builds that page.

## Step 1: sessions you can test

Chapter 20 ended its functional tests with a confession: logged-in pages couldn't be tested, because `Session`
called PHP's session functions directly. `session_start()` sends a cookie header, and under PHPUnit output has already
been sent, so a session can't start cleanly. Every page in the brief needs a logged-in member. Before writing any of
them, fix the testing gap.

The fix is the one chapter 20 suggested: an interface at the boundary, with the real implementation for production
and an in-memory one for tests. The boundary is the storage, not the whole `Session`, because the interesting logic
(flash messages, CSRF tokens) is the same in both cases and should be tested in both.

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Web;

/**
 * Where the visitor's session data lives. Session builds flash messages and CSRF tokens on top of it.
 *
 * Production uses PHP's native sessions (NativeSessionStorage); tests use an in-memory implementation,
 * so they can log in and follow a visitor across several requests.
 */
interface SessionStorage
{
    /** Whether the visitor has a session already. Reading must never create one. */
    public function exists(): bool;

    /** The stored value, or null when there is none. */
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** Gives the session a new ID; with $clear, also forgets all its data. */
    public function regenerate(bool $clear = false): void;
}
```

`NativeSessionStorage` is the code that used to be inside `Session`, moved unchanged: the lazy start (no session
until something is stored or the browser sends a cookie) and the cookie settings from chapter 18.

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Web;

/**
 * Session data in PHP's native sessions ($_SESSION and the session cookie). The session starts only when
 * something is stored, or when the browser already sent a session cookie, so visitors who only browse never get one.
 */
final readonly class NativeSessionStorage implements SessionStorage
{
    public function __construct(
        private bool $secureCookie = false,
        private string $name = 'PHPSESSID',
    ) {
    }

    public function exists(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[$this->name]);
    }

    public function get(string $key): mixed
    {
        if (!$this->exists()) {
            return null;
        }
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        if ($this->exists()) {
            $this->start();
            unset($_SESSION[$key]);
        }
    }

    public function regenerate(bool $clear = false): void
    {
        $this->start();
        if ($clear) {
            $_SESSION = [];
        }
        session_regenerate_id(true);
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'name' => $this->name,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'cookie_secure' => $this->secureCookie,
                'use_strict_mode' => true,
            ]);
        }
    }
}
```

`Session` keeps its public methods, so none of its callers (the view, the middleware, the controllers) change. Only
its insides now delegate:

```php
final readonly class Session
{
    public function __construct(
        private SessionStorage $storage,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage->get($key) ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage->set($key, $value);
    }

    /** A new session ID for the same data: call it when the visitor's privileges change (login, logout). */
    public function regenerate(): void
    {
        $this->storage->regenerate();
    }

    /** Forgets everything and continues with a new, empty session. */
    public function reset(): void
    {
        $this->storage->regenerate(clear: true);
    }

    // remove(), flash(), takeFlash(), csrfToken() and renewCsrfToken() as before
}
```

In the container, `Session` no longer needs a factory, since the container can autowire it. The setting moves to the
storage:

```php
// Session itself is autowired; tests replace the storage with an in-memory one.
$container->set(SessionStorage::class, fn () => new NativeSessionStorage(secureCookie: $settings['https']));
```

The test double lives in `tests/Support/`, because production never uses it. One instance plays the role of the
browser's cookie jar: every request a test sends with the same instance belongs to the same visitor.

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Support;

use Bookshelf\Web\SessionStorage;

/**
 * Session data in a plain array. One instance plays the browser's session cookie: every request in a test that
 * uses the same instance belongs to the same visitor.
 */
final class ArraySessionStorage implements SessionStorage
{
    /** @var array<string, mixed> */
    public private(set) array $data = [];

    public private(set) bool $started = false;

    /** How often the session ID was changed (at login and logout). */
    public private(set) int $regenerations = 0;

    public function exists(): bool
    {
        return $this->started;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->started = true;
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(bool $clear = false): void
    {
        $this->started = true;
        $this->regenerations++;
        if ($clear) {
            $this->data = [];
        }
    }
}
```

This is a **fake** in chapter 20's sense: a working implementation, simpler than the real one. The `regenerations`
counter lets a test check a security property that would otherwise be invisible: that the session ID changes at
login (chapter 18's defence against session fixation).

Run the existing suite after a refactoring like this, before adding anything. All 44 tests still pass, which is the
point of refactoring: the structure changes and the behavior doesn't.

## Step 2: one report, two front ends

The desk page should list overdue loans. `OverdueCommand` (chapter 22) already builds that list: it loads the late
loans, their books in one query, and each member once. Copying that loop into a controller would work today and
drift tomorrow, when someone fixes a bug in one copy.

So the logic moves into the use-case layer, and both front ends call it. The result of the report is a small
read-only class rather than an array, so the template and the command get named, typed fields:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Lending;

use Bookshelf\Domain\Money;
use DateTimeImmutable;

/**
 * One line of the overdue report: a late loan with what a person needs to chase it up.
 */
final readonly class OverdueLoan
{
    public function __construct(
        public int $loanId,
        public DateTimeImmutable $dueOn,
        public int $daysOverdue,
        public Money $fine,
        public string $title,
        public string $memberName,
        public string $memberEmail,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Lending;

use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use DateTimeImmutable;
use LogicException;

/**
 * Every loan that is past its due date, with the book's title, the member and the fine so far.
 * One use case with two front ends: the loans:overdue command and the staff's lending desk.
 */
final readonly class OverdueReport
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private MemberRepository $members,
    ) {
    }

    /** @return list<OverdueLoan> the most overdue first */
    public function on(DateTimeImmutable $today): array
    {
        $overdue = $this->loans->overdue($today);
        $books = $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $overdue));

        /** @var array<int, Member> $members */
        $members = [];
        $report = [];
        foreach ($overdue as $loan) {
            $member = $members[$loan->memberId] ??= $this->members->get($loan->memberId);
            $report[] = new OverdueLoan(
                $loan->id ?? throw new LogicException('A stored loan has an id'),
                $loan->dueOn,
                $loan->daysOverdue($today),
                $loan->fine($today),
                $books[$loan->bookId]->title ?? "(book {$loan->bookId})",
                $member->name,
                $member->email,
            );
        }

        return $report;
    }
}
```

The command shrinks to what is specific to a command: turning the report into rows for a table or for JSON.

```php
    public function __construct(
        private OverdueReport $report,
        private Clock $clock,
    ) {
    }

    // name(), description() and help() unchanged

    public function run(Input $input, Output $output): ExitCode
    {
        $rows = array_map(fn (OverdueLoan $late): array => [
            'loan' => $late->loanId,
            'due' => $late->dueOn->format('Y-m-d'),
            'days' => $late->daysOverdue,
            'fine' => $late->fine->format(),
            'title' => $late->title,
            'member' => $late->memberEmail,
        ], $this->report->on($this->clock->now()));

        if ($input->flag('json')) {
            $output->json($rows);
        } elseif ($rows === []) {
            $output->success('No overdue loans');
        } else {
            $output->table(
                ['Loan', 'Due', 'Days', 'Fine', 'Title', 'Member'],
                array_map(fn (array $row): array => array_map(strval(...), array_values($row)), $rows),
            );
        }

        return ExitCode::Success;
    }
```

`OverdueCommandTest` needs one changed line (it builds the command with an `OverdueReport` now), and its assertions
pass unchanged: the JSON output is identical, so scripts that consume it (chapter 22's cron job) aren't affected. The
test gains `#[CoversClass(OverdueReport::class)]`, since it now exercises both classes.

## Step 3: the lending desk

### Designing the routes

Before any code, decide the URLs and methods. The rules from chapter 16 apply: reading is `GET`, changing is `POST`,
and every successful `POST` answers with a redirect.

| Route | Does | Answers |
|---|---|---|
| `GET /desk?member=<email>` | Shows the desk; with a member, their loans and the lending form | 200 |
| `POST /desk/loans` | Lends the book with `isbn` to the member with e-mail `member` | 303 back to the desk, or 422 with the reason |
| `POST /desk/loans/{id}/return` | Takes back loan `{id}` | 303 back to the desk; 404 for an unknown loan |

All three are `Access::Staff`, so the dispatcher refuses them before the controller exists: anonymous visitors are
sent to the login page and members get 403. The controller never checks roles itself, which means it can't forget to.

Two smaller decisions:

- **The member is identified by e-mail address**, because that's what a librarian can ask for at the counter. The
  form carries it in a hidden field once the member is found.
- **The book is identified by ISBN**, because that's what a barcode scanner types (followed by Enter, which submits
  the form). `autofocus` on the ISBN field means the librarian can scan book after book without touching the mouse.

### The controller

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Domain\BookNotAvailable;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Isbn;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\LoanRuleViolation;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Http\Access;
use Bookshelf\Http\HttpException;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use Bookshelf\Lending\LendingService;
use Bookshelf\Lending\OverdueReport;
use Bookshelf\Support\Clock;
use Bookshelf\Web\Session;
use Bookshelf\Web\View;
use LogicException;

/**
 * The lending desk, for library staff: find a member, lend them a book by its ISBN, take books back.
 * The rules live in LendingService and the domain; this class turns forms into calls and outcomes into pages.
 */
final readonly class DeskController
{
    public function __construct(
        private LendingService $lending,
        private OverdueReport $overdue,
        private MemberRepository $members,
        private BookRepository $books,
        private LoanRepository $loans,
        private Clock $clock,
        private Session $session,
        private View $view,
    ) {
    }

    #[Route('GET', '/desk', Access::Staff)]
    public function index(Request $request): Response
    {
        return $this->page($request->query('member'));
    }

    #[Route('POST', '/desk/loans', Access::Staff)]
    public function lend(Request $request): Response
    {
        $email = $request->input('member');
        $isbn = preg_replace('/[\s-]+/', '', $request->input('isbn', 30)) ?? '';

        $member = $this->members->findByEmail($email);
        $book = Isbn::isValid($isbn) ? $this->books->findByIsbn(new Isbn($isbn)) : null;
        if ($member === null) {
            return $this->page($email, error: 'There is no member with that e-mail address.', status: 422);
        }
        if ($book === null) {
            return $this->page($email, error: "There is no book with the ISBN '$isbn'.", isbn: $isbn, status: 422);
        }

        try {
            $loan = $this->lending->lend(
                $book->id ?? throw new LogicException('A stored book has an id'),
                $member->id ?? throw new LogicException('A stored member has an id'),
            );
        } catch (LoanRuleViolation|BookNotAvailable $e) {
            // Broken lending rules are part of the job at a desk, not errors: show why, keep what was typed.
            return $this->page($email, error: $e->getMessage(), isbn: $isbn, status: 422);
        }

        $this->session->flash(sprintf('Lent "%s" to %s, due back on %s.', $book->title, $member->name,
            $loan->dueOn->format('D j M Y')));

        return Response::redirect(self::deskFor($member->email));   // POST, redirect, GET (chapter 16)
    }

    #[Route('POST', '/desk/loans/{id:\d+}/return', Access::Staff)]
    public function takeBack(int $id): Response
    {
        try {
            $loan = $this->loans->get($id);
        } catch (EntityNotFound $e) {
            throw new HttpException(404, "There is no loan with id $id.", previous: $e);
        }
        $title = $this->books->get($loan->bookId)->title;
        $member = $this->members->get($loan->memberId);

        try {
            $fine = $this->lending->returnLoan($id);
            $this->session->flash($fine->isZero()
                ? "Took back \"$title\"."
                : "Took back \"$title\", returned late. Please collect a fine of $fine.");
        } catch (LoanRuleViolation $e) {
            $this->session->flash($e->getMessage());     // a second click on the same button
        }

        return Response::redirect(self::deskFor($member->email));
    }

    private function page(string $email, ?string $error = null, string $isbn = '', int $status = 200): Response
    {
        $today = $this->clock->now();
        $borrower = $email === '' ? null : $this->members->findByEmail($email);
        if ($email !== '' && $borrower === null) {
            $error ??= 'There is no member with that e-mail address.';
        }
        $loans = $borrower?->id === null ? [] : $this->loans->openLoansOf($borrower->id);

        return $this->view->page('Lending desk', 'desk', [
            'email' => $email,
            'borrower' => $borrower,
            'loans' => $loans,
            'books' => $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
            'isbn' => $isbn,
            'error' => $error,
            'overdue' => $this->overdue->on($today),
            'today' => $today,
        ], $status);
    }

    private static function deskFor(string $email): string
    {
        return '/desk?' . http_build_query(['member' => $email]);
    }
}
```

Read it for what it *doesn't* contain. There's no lending rule in it: no "three books at most", no check that a copy is
on the shelf, no transaction. Those are in `LendingService` and the domain, which already have tests. The controller's
job is translation:

- **Form fields to domain calls.** Trimmed and length-limited input (`Request::input()`), an ISBN normalized the same
  way `Isbn` does it, and `findByEmail()`, which lower-cases the address like `Member` does.
- **Domain outcomes to HTTP.** A broken lending rule is a normal event at a desk ("she already has three books"), so
  it becomes a page that shows the reason with status **422** (named "Unprocessable Content" in RFC 9110; PHP still
  sends the older phrase "Unprocessable Entity"), the same status the suggestion form uses for invalid input, and
  keeps the typed ISBN in the field. A loan id that doesn't exist is a
  **404**. Success is a **303** redirect with a flash message.
- **Expected and unexpected failures.** The `catch` names exactly the exceptions that mean "the rules said no". Anything
  else (a database outage, a bug) isn't caught here and reaches `ErrorHandlerMiddleware`, which logs it and shows a
  500. Catching `Throwable` in a controller would turn bugs into friendly messages that nobody investigates.

The `?? throw new LogicException(...)` on the ids deserves a word. `Book::$id` is `?int` because a book that hasn't been
saved has no id yet. A book loaded from the repository always has one, but PHPStan can't know that. Rather than casting
(`(int) $book->id` would silently turn `null` into book 0), the code states the assumption and fails loudly if it's
ever wrong.

The double-click case in `takeBack()` comes from thinking about the real counter. A librarian clicks **Take back**,
the page is slow, they click again. The second request finds the loan already returned, and `Loan::markReturned()`
throws `LoanRuleViolation`. Rather than an error page, the desk shows "Loan 2 was already returned", which is true and
harmless. Because the return runs in a transaction, there's no way for two clicks to put two copies back on the shelf.

### The template

The template is long but plain. These are its interesting parts:

```php
<?php if ($borrower !== null): ?>
    <h2><?= e($borrower->name) ?></h2>
    <p>
        <?= e($borrower->type->label()) ?> member · <?= e(count($loans)) ?> of <?= e($borrower->type->maxLoans()) ?> loans
        · <?= e($borrower->type->loanDays()) ?> days per loan
    </p>

    <form method="post" action="/desk/loans" class="search">
        <?= $csrfField() ?>
        <input type="hidden" name="member" value="<?= e($borrower->email) ?>">
        <input name="isbn" value="<?= e($isbn) ?>" placeholder="ISBN of the book" aria-label="ISBN" required autofocus>
        <button>Lend</button>
    </form>

    <?php if ($loans !== []): ?>
        <table>
            <!-- ... -->
            <?php foreach ($loans as $loan): ?>
                <tr>
                    <td><?= e($books[$loan->bookId]->title ?? 'Unknown book') ?></td>
                    <td><?= e($loan->lentOn->format('j M Y')) ?></td>
                    <td><?= e($loan->dueOn->format('j M Y')) ?></td>
                    <td><?= e($loan->status($today)->label()) ?></td>
                    <td><?= e($loan->fine($today)->format()) ?></td>
                    <td>
                        <form method="post" action="/desk/loans/<?= e($loan->id) ?>/return" class="inline">
                            <?= $csrfField() ?>
                            <button>Take back</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
<?php endif; ?>
```

Every value goes through `e()`, including numbers and things that "can't" contain HTML, such as the member type's
label. Escaping everything is easier to review than deciding case by case (chapter 18). Every `POST` form carries
`$csrfField()`. **Take back** is a form with a button, not a link: a link would be a `GET`, and `GET` must never change
anything, since browsers prefetch links and crawlers follow them.

The overdue list at the bottom links each member's name to `/desk?member=...`, with the address passed through
`rawurlencode()` for the URL and then `e()` for the HTML attribute: two contexts, two encodings, applied in that order.

Finally, the controller is registered with the router in `config/container.php`, and the layout gets a **Lending
desk** link inside its existing `isStaff()` block:

```php
    $router->addController(Controller\DeskController::class);
```

## Step 4: renewals, and whose loan is it?

Members renew from **My loans**. The new route takes the loan id from the URL:

```php
    /** A member renews one of their own loans. */
    #[Route('POST', '/loans/{id:\d+}/renew', Access::Member)]
    public function renew(int $id, Member $member): Response
    {
        try {
            $loan = $this->loans->get($id);
        } catch (EntityNotFound) {
            $loan = null;
        }
        // The id in the URL proves nothing about ownership (chapter 18). Someone else's loan gets the same answer
        // as a loan that doesn't exist, so the URL can't be used to probe which loans exist.
        if ($loan === null || $loan->memberId !== $member->id) {
            throw HttpException::notFound("There is no loan with id $id.");
        }

        try {
            $renewed = $this->lending->renew($id);
            $this->session->flash('Renewed. The new due date is ' . $renewed->dueOn->format('D j M Y') . '.');
        } catch (LoanRuleViolation $e) {
            $this->session->flash($e->getMessage());
        }

        return Response::redirect('/loans');
    }
```

`Access::Member` only says *someone* is logged in. Without the ownership check, Ada could renew Alan's loan by sending
`POST /loans/2/renew` herself: the button isn't on her page, but nothing stops her from crafting the request. This
mistake is common enough to have names, **IDOR** (insecure direct object reference) or **broken object-level
authorization**, and it tops lists of API vulnerabilities. The rule: every route that takes an id checks that the
current user may act on *that* object.

Answering 404 instead of 403 is deliberate. "403 Forbidden" would confirm that loan 2 exists; "404 Not Found" tells
Ada nothing she didn't know. The `/loans` page itself never had the problem, because it never took an id: whose loans
to show comes from the session.

The page should only offer **Renew** when renewing can work. The template could repeat the rules (`renewals < 2`,
not overdue, not returned), but then they'd live in two places. Instead, `Loan` gets a method that asks the same
questions `renew()` asks:

```php
    /** Whether renew() would succeed today, e.g. to show or hide a Renew button. */
    public function canBeRenewed(DateTimeImmutable $today): bool
    {
        return !$this->isReturned && $this->daysOverdue($today) === 0 && $this->renewals < self::MAX_RENEWALS;
    }
```

```php
<?php if ($loan->canBeRenewed($today)): ?>
    <form method="post" action="/loans/<?= e($loan->id) ?>/renew" class="inline">
        <?= $csrfField() ?>
        <button>Renew</button>
    </form>
<?php endif; ?>
```

The button is a convenience, not a security control. `renew()` still checks every rule itself, which is why a crafted
request for an overdue loan gets the flash message "Overdue loans cannot be renewed; please return the book" instead
of an extra renewal. A unit test in `LoanTest` keeps the two in agreement:

```php
    public function testCanBeRenewedAgreesWithRenew(): void
    {
        $loan = $this->loan(MemberType::Standard, lentOn: '2026-10-11');   // due 2026-11-01

        self::assertTrue($loan->canBeRenewed(self::day('2026-10-12')));
        self::assertFalse($loan->canBeRenewed(self::day('2026-11-02')));   // overdue
        $loan->renew(self::day('2026-10-12'));
        $loan->renew(self::day('2026-10-12'));
        self::assertFalse($loan->canBeRenewed(self::day('2026-10-12')));   // renewed twice already
    }
```

## Step 5: testing the flows

With `ArraySessionStorage`, a functional test can play a visitor over several requests. The setup builds the real
container and swaps two services: the session storage and the clock.

```php
#[CoversNothing]
final class LendingDeskTest extends TestCase
{
    use Builders;

    private Container $container;
    private ArraySessionStorage $session;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
        $this->session = new ArraySessionStorage();
        $this->clock = FrozenClock::at('2026-10-11 10:00', 'Europe/Berlin');
        $this->container->set(SessionStorage::class, fn () => $this->session);
        $this->container->set(Clock::class, fn () => $this->clock);
        $this->container->get(Migrator::class)->migrate();

        $this->container->get(BookRepository::class)->save(self::book(id: null, copies: 1));   // Dune, id 1
        $members = $this->container->get(MemberRepository::class);
        $members->save(new Member(null, 'Grace Hopper', 'grace@example.org', MemberType::Staff));
        $members->save(self::member(id: null));                                               // Ada, standard
        $members->save(new Member(null, 'Alan Turing', 'alan@example.org'));
    }
```

`Container::set()` replaces a definition, and because nothing has been built yet, every service that needs a `Clock`
or a `Session` gets the test's. The helpers keep each test about one flow:

```php
    /** Logs in without the login form, the way a session looks after a successful login. */
    private function logIn(string $email): void
    {
        $this->session->set('member_id', $this->findMember($email)->id);
        $this->session->set('last_seen', $this->clock->now()->getTimestamp());
    }

    /**
     * Submits a form, with the session's CSRF token as a real page would include it.
     *
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields): Response
    {
        $token = $this->container->get(Session::class)->csrfToken();

        return $this->handle(new Request('POST', $path, body: [...$fields, '_csrf' => $token]));
    }

    private function handle(Request $request): Response
    {
        return $this->container->get(Kernel::class)->handle($request);
    }
```

`logIn()` writes the session directly, which keeps most tests fast (no bcrypt). One test goes through the real login
form, so the shortcut can't hide a broken login:

```php
    public function testStaffLogInAndLandOnTheDesk(): void
    {
        $member = $this->findMember('grace@example.org');
        $member->changePasswordHash(password_hash('correct horse battery staple', PASSWORD_DEFAULT));
        $this->container->get(MemberRepository::class)->save($member);

        self::assertSame(200, $this->get('/login', ['next' => '/desk'])->status);    // the form sets the CSRF token
        $response = $this->post('/login', [
            'email' => 'grace@example.org',
            'password' => 'correct horse battery staple',
            'next' => '/desk',
        ]);

        self::assertSame(303, $response->status);
        self::assertSame('/desk', $response->header('Location'));
        self::assertSame(1, $this->session->regenerations);                         // a new session ID at login
        $desk = $this->get('/desk');
        self::assertSame(200, $desk->status);
        self::assertStringContainsString('Welcome back, Grace Hopper.', $desk->body);
        self::assertStringContainsString('Nothing is overdue.', $desk->body);
    }
```

The main flow lends a book, moves the clock forward, and takes it back:

```php
    public function testStaffLendAndTakeBackABook(): void
    {
        $this->logIn('grace@example.org');

        $lent = $this->post('/desk/loans', ['member' => 'ada@example.org', 'isbn' => '978-0441013593']);

        self::assertSame(303, $lent->status);
        self::assertSame('/desk?member=ada%40example.org', $lent->header('Location'));
        $desk = $this->get('/desk', ['member' => 'ada@example.org']);
        self::assertStringContainsString('Lent &quot;Dune&quot; to Ada Lovelace, due back on Sun 1 Nov 2026.', $desk->body);
        self::assertStringContainsString('action="/desk/loans/1/return"', $desk->body);
        self::assertSame(0, $this->container->get(BookRepository::class)->get(1)->available);

        $this->clock->advance('+24 days');                                          // three days late
        $this->logIn('grace@example.org');                                          // the old session timed out
        $returned = $this->post('/desk/loans/1/return', []);

        self::assertSame('/desk?member=ada%40example.org', $returned->header('Location'));
        self::assertStringContainsString('Please collect a fine of $0.75.', $this->get('/desk')->body);
        self::assertSame(1, $this->container->get(BookRepository::class)->get(1)->available);
    }
```

Note the expected text `&quot;Dune&quot;`: the flash message contains quotes, and the layout escapes it. The test
checks the HTML the browser receives, so it also proves the escaping happens.

And the security properties get tests of their own, because they're the ones nobody notices breaking:

```php
    public function testMembersCannotUseTheDesk(): void
    {
        $this->logIn('ada@example.org');

        self::assertSame(403, $this->get('/desk')->status);
        self::assertSame(403, $this->post('/desk/loans', ['member' => 'ada@example.org', 'isbn' => '9780441013593'])->status);
    }

    public function testAMemberCannotRenewALoanOfAnotherMember(): void
    {
        $this->lend('alan@example.org');                                             // loan 1 is Alan's
        $this->logIn('ada@example.org');

        $response = $this->post('/loans/1/renew', []);

        self::assertSame(404, $response->status);
        self::assertSame(0, $this->container->get(LoanRepository::class)->get(1)->renewals);
    }
```

The second test checks the response *and* the database. A 404 from a route that renewed the loan anyway would pass a
test that only looked at the status.

### What the tests found

Writing these tests found two problems, which is what tests are for.

The first version of `testStaffLendAndTakeBackABook` failed at the end: the desk page after the return was empty. The
response was a redirect to the login page. Advancing the clock by 24 days had made the session idle for 24 days, and
`AuthenticationMiddleware` logged Grace out after 30 minutes, exactly as chapter 18 designed it. The code was right
and the test was wrong; real librarians log in again the next day, so the test does too.

The second was a real inconsistency. `AuthController` recorded the login time with `time()`, while
`AuthenticationMiddleware` measured idleness with the injected `Clock`. In production both are the system clock, so
nobody noticed. With a frozen clock in tests, they disagree. The fix is one line, and the rule behind it is general:
once a class depends on a clock, *all* of it uses that clock.

```php
        $this->session->set('last_seen', $this->clock->now()->getTimestamp());   // the clock the idle check uses
```

The new tests, listed with `--testdox`:

```text
Lending Desk (Bookshelf\Tests\Functional\LendingDesk)
 ✔ Staff log in and land on the desk
 ✔ Staff lend and take back a book
 ✔ The desk explains why a book cannot be lent
 ✔ Members cannot use the desk
 ✔ A member renews their own loan
 ✔ A member cannot renew a loan of another member
 ✔ Forms without the token are rejected
```

## Step 6: trying it for real

Tests prove the code does what the tests say. Before calling a feature done, use it the way a person will. Here is a
session with `curl` against `composer serve`, logged in as Grace (the cookie jar `-c`/`-b` plays the browser, and each
form's CSRF token is read from the page before posting):

```bash
curl -s -i -c jar -b jar -X POST http://localhost:8000/desk/loans \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "member=ada@example.org" --data-urlencode "isbn=978-0-8070-8369-7"
```

```text
HTTP/1.1 303 See Other
location: /desk?member=ada%40example.org
```

Following the redirect shows the flash message and Ada's loan:

```text
<p class="flash">Lent &quot;Kindred&quot; to Ada Lovelace, due back on Sun 1 Nov 2026.</p>
<h1>Lending desk</h1>
...
<h2>Ada Lovelace</h2>
<p>
    Standard member · 1 of 3 loans
    · 21 days per loan
</p>
...
<td>Kindred</td>
<td>11 Oct 2026</td>
<td>1 Nov 2026</td>
<td>On loan</td>
<td>$0.00</td>
```

Lending the same book to her again is refused with the domain's own message:

```text
HTTP/1.1 422 Unprocessable Entity
<p class="error">Ada Lovelace already has a copy of &#039;Kindred&#039;</p>
```

To see the overdue list, lend Ada *Emma* and backdate that loan in the database (`lent_on` 2026-09-14, `due_on`
2026-10-05). The desk and the command now show the same report:

```text
<h2>Overdue loans</h2>
...
<td>Emma</td>
<td><a href="/desk?member=ada%40example.org">Ada Lovelace</a></td>
<td>5 Oct 2026</td>
<td>6</td>
<td>$1.50</td>
```

```bash
php bin/bookshelf loans:overdue
```

```text
Loan  Due         Days  Fine   Title  Member
----  ----------  ----  -----  -----  ---------------
2     2026-10-05  6     $1.50  Emma   ada@example.org
```

In a second cookie jar, log in as Ada. **My loans** offers **Renew** only for *Kindred*, since *Emma* is overdue. The
renewal works; a hand-made request to renew *Emma* is refused by the rules; loan 99 doesn't exist; the desk is closed
to her:

```text
<p class="flash">Renewed. The new due date is Sun 22 Nov 2026.</p>
<p class="flash">Overdue loans cannot be renewed; please return the book</p>
HTTP/1.1 404 Not Found
<p>There is no loan with id 99.</p>
HTTP/1.1 403 Forbidden
<p>This page is for library staff only.</p>
```

Back as Grace, take back *Emma*, then click again:

```text
<p class="flash">Took back &quot;Emma&quot;, returned late. Please collect a fine of $1.50.</p>
<p class="flash">Loan 2 was already returned</p>
```

Exploring like this regularly finds what tests miss: a confusing message, a missing link, a page that's awkward with a
barcode scanner. Here it confirmed the design; the volunteers will find the rest.

## Quality gates

Three Composer scripts (chapter 20) run every check a contributor needs:

```bash
composer test
composer analyse
composer cs
```

```text
OK (52 tests, 124 assertions)

 [OK] No errors

Found 0 of 93 files that can be fixed
```

(The output is shortened to the summary lines.) The suite grew from 44 to 52 tests: seven functional tests for the
desk and renewals, and one unit test for `canBeRenewed()`. It still runs in about five seconds, most of which is the
deliberate bcrypt cost in the login tests. PHPStan stays at level 8 with no baseline; it flagged nothing in the new code
because the code states its assumptions (`?? throw`) instead of hoping. The style check caught one thing while the
chapter was being written: `catch (LoanRuleViolation | BookNotAvailable $e)` with spaces, which PER-CS writes without
them.

### Continuous integration

The same checks run on every push in `examples/bookshelf/.github/workflows/ci.yml`. GitHub reads workflows only from a
repository's root, so this file takes effect once Bookshelf is a repository of its own:

```yaml
# Runs Bookshelf's checks on every push and pull request.
# GitHub only reads workflows from the repository root, so this file takes effect once
# examples/bookshelf/ is a repository of its own.
name: CI
on: [push, pull_request]

jobs:
  checks:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: intl, pdo_sqlite
          coverage: none
      - run: composer validate --strict
      - run: composer install --no-interaction --no-progress
      - run: composer audit
      - run: composer cs
      - run: composer analyse
      - run: composer test
```

Compared with chapter 20's sketch, it adds two cheap steps at the front. `composer validate --strict` fails if
`composer.json` is invalid or `composer.lock` is out of date with it (someone edited one without the other).
`composer audit` fails if any locked package has a published security advisory, so a vulnerable dependency stops the
build rather than reaching production.

## Shipping it

Nothing in this chapter changed how Bookshelf is deployed, which is a sign the deployment from chapter 22 is in good
shape. The new classes are picked up by the authoritative class map and by `config/preload.php` (which loads every file
in `src/`), and there's no new migration. A release is the same as before:

```bash
docker compose up -d --build
docker compose run --rm app php bin/bookshelf db:migrate
```

Before the first real release, go through this list. Each line is a chapter's lesson in one sentence:

- [ ] **Production `php.ini`**: `display_errors=Off`, `log_errors=On`, OPcache on with `validate_timestamps=0`
  (chapters 21–22).
- [ ] **HTTPS** at the proxy, and `BOOKSHELF_HTTPS=1` so cookies get the `Secure` flag and responses carry HSTS
  (chapters 18, 22).
- [ ] **Never `BOOKSHELF_DEBUG=1`** in production: it puts exception details on error pages (chapter 19).
- [ ] **A staff account with a strong password**, created with `member:add --type=staff` and `member:password`; no
  default accounts (chapter 18).
- [ ] **Backups of the database volume**, and one restore actually tested. With SQLite in WAL mode, back up with
  `sqlite3 bookshelf.sqlite ".backup backup.sqlite"` rather than copying the file while it's in use.
- [ ] **The overdue report scheduled** with cron or a timer, and its failures reported to someone (chapter 22).
- [ ] **Logs collected** from both containers, and the `/health` check wired to monitoring (chapter 22).
- [ ] **Dependencies current**: `composer audit` clean, and a plan for PHP upgrades (chapter 22).

## Reviewing the whole application

A review at the end looks at the application as a whole, not at a diff. Here is Bookshelf's, as a table you can reuse
for your own projects.

### Security

| Threat | Defence in Bookshelf | Where |
|---|---|---|
| SQL injection | Prepared statements everywhere; no SQL built from input | `Persistence/` |
| Cross-site scripting | `e()` on every value in every template; a strict Content-Security-Policy | `templates/`, `SecurityHeadersMiddleware` |
| Cross-site request forgery | A per-session token in every form, checked for every non-GET request; `SameSite=Lax` cookies | `CsrfMiddleware`, `Session` |
| Stolen or guessed passwords | bcrypt via `password_hash()`, a 15-character minimum, rehash on login, login throttling per address and account | `Passwords`, `Authenticator`, `LoginThrottle` |
| Account enumeration | The same message and timing for unknown addresses and wrong passwords | `Authenticator` |
| Session fixation and theft | New session ID at login and logout, strict mode, HttpOnly and Secure cookies, an idle timeout | `NativeSessionStorage`, `AuthController`, `AuthenticationMiddleware` |
| Missing authorization | Deny by default: every route declares its access level, checked before the controller runs | `#[Route]`, `Dispatcher` |
| Insecure direct object references | Member routes act on the session's member; ids from URLs are checked for ownership | `LoanController` |
| Open redirects | `?next=` accepted only for local paths | `AuthController::localPath()` |
| Information leaks | Generic error pages; details only in logs | `ErrorHandlerMiddleware`, production `php.ini` |
| Vulnerable dependencies | `composer audit` in CI; few dependencies at all (none at run time) | `ci.yml`, `composer.json` |
| A compromised PHP process | Runs as `www-data`, no Composer or dev tools in the image, code not writable | `Dockerfile` |

### Performance and reliability

- **Queries per page are bounded.** Lists load their books with one `findByIds()` query instead of one per loan, and
  the overdue report fetches each member once. The catalog search returns at most 50 books.
- **Every change is a transaction.** Lending checks the member's limit and takes a copy in one `IMMEDIATE`
  transaction, so two librarians lending the last copy at the same moment can't both succeed.
- **OPcache, preloading and the class map** are configured in the container image; the JIT is left off, because
  chapter 21 measured that this I/O-bound application doesn't benefit.
- **SQLite is the limit.** It handles a community library easily (thousands of loans, a handful of writers), and its
  single-writer design is what makes the transactions simple. A library network with many branches would move to
  PostgreSQL; the repositories are the only code that would change, since everything else depends on interfaces.

### What's deliberately missing

Knowing what you left out is part of finishing. Bookshelf has no password reset (staff set passwords), no
self-registration, no e-mail sending (chapter 22's exercise prepared for it), no reservations, no pagination in the
HTML catalog, and no translations. None of these is hard with what you know now; the exercises below take on some of
them.

## Where to go next

You've built a complete application without a framework, which means you know what a framework does for you. Good next
steps, roughly in order:

1. **Build Bookshelf's delivery layer again in a framework.** Chapter 23 showed the map. Rebuilding the desk in Symfony
   or Laravel, keeping `Domain/` and `Lending/`, teaches the framework faster than any tutorial, because you already
   know every requirement.
2. **Read code you didn't write.** The source of `slim/slim`, `symfony/routing` or `laravel/framework` is readable PHP.
   Find their versions of `Router::match()` and the middleware pipeline.
3. **Go deeper on the database.** Move Bookshelf to PostgreSQL, using the dialect differences from chapter 17: only
   the migrations and the `Persistence/` classes should need to change.
4. **Follow the language.** Read the `UPGRADING` notes of each new PHP release (8.6 is next), and the RFCs at
   <https://wiki.php.net/rfc> to see what's coming and why.
5. **Contribute.** Fixing a documentation error or a small bug in an open-source PHP package is the fastest way to have
   your code reviewed by experienced people.

## Key takeaways

- A layered design pays off when features are added: the lending desk needed one controller, one template and one
  container line, because the rules already lived in the domain and the use cases.
- Dependencies point inward. The domain defines interfaces (`BookRepository`, `Clock`, `SessionStorage`) and the outer
  layers implement them, which is what makes the core testable and framework-independent.
- Put an interface where your code meets something hard to control (sessions, time, the database), and test through it
  with fakes.
- Refactor before adding features, and run the tests in between: the structure changes, the behavior doesn't.
- When two front ends need the same logic, move it into a use-case class with a typed result; keep each front end to
  input parsing and output formatting.
- Controllers translate: form fields into domain calls, and domain outcomes into status codes, pages and redirects.
  Catch only the exceptions that mean "the rules said no"; let everything else reach the error handler.
- Logged in is not the same as allowed. Every route that takes an id checks ownership, and answers 404 for objects the
  user may not see.
- Hiding a button is a convenience; the server checks every rule on every request.
- Test the security properties explicitly, and check the database as well as the response.
- Use the feature by hand before calling it done, and review the whole application against a checklist before
  releasing it.

## Exercises

These build on the finished Bookshelf. Work in a copy of `examples/bookshelf/`, and keep `composer test`,
`composer analyse` and `composer cs` passing after each one.

1. **Fines on "My loans".** Members should see what they owe so far. On `/loans`, below the table, show "Fines so far:
   **$1.00**. They stop growing when you bring the books back." with the sum of the fines of all open loans as of
   today, and nothing when the sum is zero. Compute the sum in the controller, not the template, and add a functional
   test to `LendingDeskTest`.

<details>
<summary>Solution</summary>

In `LoanController::mine()`, compute the total with `array_reduce()` and `Money::plus()`. `Money` is immutable, so
each step returns a new value, and `Money::zero()` is the starting value (add `use Bookshelf\Domain\Money;`):

```php
    #[Route('GET', '/loans', Access::Member)]
    public function mine(Member $member): Response
    {
        $loans = $this->loans->openLoansOf($member->id ?? throw new LogicException('A logged-in member has an id'));
        $today = $this->clock->now()->setTime(0, 0);

        return $this->view->page('My loans', 'loans', [
            'loans' => $loans,
            'books' => $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
            'today' => $today,
            'owed' => array_reduce(
                $loans,
                fn (Money $sum, Loan $loan): Money => $sum->plus($loan->fine($today)),
                Money::zero(),
            ),
        ]);
    }
```

In `templates/loans.php`, document the new variable in the docblock (`@var Bookshelf\Domain\Money $owed`) and add the
paragraph after the table, inside the `else` branch:

```php
    <?php if (!$owed->isZero()): ?>
        <p>Fines so far: <strong><?= e($owed->format()) ?></strong>. They stop growing when you bring the books back.</p>
    <?php endif; ?>
```

The test lends Dune to Ada (due 1 November), moves the clock 25 days to 5 November (four days late, 4 × 25 cents), and
logs in again because the old session has timed out:

```php
    public function testMyLoansShowsTheFinesSoFar(): void
    {
        $this->lend('ada@example.org');                                              // due Sun 1 Nov 2026
        $this->clock->advance('+25 days');                                           // four days late
        $this->logIn('ada@example.org');

        self::assertStringContainsString('Fines so far: <strong>$1.00</strong>', $this->get('/loans')->body);
    }
```

Why the controller: the sum is a fact about the loans, and the template's job is to show facts. A template that
computes is harder to test and tends to grow logic. (Strictly, "fine if returned today" could even be a method on a
small `LoanList` class in the domain, if more pages needed it.)

</details>

2. **Paginate the API.** `GET /api/books` returns at most 50 books, and a client can't get the rest. Add `page`
   (default 1) and `per_page` (default 20, at most 100) query parameters. The response should contain `count` (all
   matching books), `page`, `pages`, `links` with `prev` and `next` URLs (or `null`), and `data`. Invalid values
   (`page=0`, `per_page=500`, `page=abc`) get a 400 with a JSON error. Keep the `q` and `genre` filters in the links.

<details>
<summary>Solution</summary>

`BookRepository` already has what's needed: `search()` takes a limit and an offset, and `count()` takes the same
filters. In `CatalogController`:

```php
    #[Route('GET', '/api/books')]
    public function apiIndex(Request $request): Response
    {
        $genreParameter = $request->query('genre');
        $genre = Genre::tryFrom($genreParameter);
        if ($genreParameter !== '' && $genre === null) {
            throw new HttpException(400, "Unknown genre '$genreParameter'");
        }

        $page = self::positiveInt($request->query('page'), 'page', default: 1);
        $perPage = self::positiveInt($request->query('per_page'), 'per_page', default: 20);
        if ($perPage > 100) {
            throw new HttpException(400, 'per_page can be at most 100');
        }

        $text = $request->query('q');
        $total = $this->books->count($text, $genre);
        $pages = max(1, (int) ceil($total / $perPage));
        $books = $this->books->search($text, $genre, $perPage, ($page - 1) * $perPage);

        $link = fn (int $to): ?string => $to >= 1 && $to <= $pages
            ? '/api/books?' . http_build_query([...$request->query, 'page' => $to, 'per_page' => $perPage])
            : null;

        return Response::json([
            'count' => $total,
            'page' => $page,
            'pages' => $pages,
            'links' => ['prev' => $link($page - 1), 'next' => $link($page + 1)],
            'data' => array_map(self::toJson(...), $books),
        ]);
    }

    /** A query parameter that must be a whole number of at least 1, or $default when it is missing. */
    private static function positiveInt(string $value, string $name, int $default): int
    {
        if ($value === '') {
            return $default;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $number === false ? throw new HttpException(400, "$name must be a whole number of at least 1") : $number;
    }
```

With the ten sample books:

```text
GET /api/books?per_page=4
200 {"count":10,"page":1,"pages":3,"links":{"prev":null,"next":"/api/books?per_page=4&page=2"},"data":["A Wizard of Earthsea","Beloved","Dune","Emma"]}
GET /api/books?per_page=4&page=3
200 {"count":10,"page":3,"pages":3,"links":{"prev":"/api/books?per_page=4&page=2","next":null},"data":["The Left Hand of Darkness","The Murder of Roger Ackroyd"]}
GET /api/books?page=0
400 {"error":"page must be a whole number of at least 1"}
GET /api/books?per_page=500
400 {"error":"per_page can be at most 100"}
GET /api/books?q=austen&page=1&per_page=1
200 {"count":2,"page":1,"pages":2,"links":{"prev":null,"next":"/api/books?q=austen&page=2&per_page=1"},"data":["Emma"]}
```

(`data` is shortened to the titles here.) Points to notice:

- `filter_var()` with `FILTER_VALIDATE_INT` rejects `"1.5"`, `"abc"` and `" 2"`, which `(int)` would quietly turn into
  1, 0 and 2. Validating instead of casting is what makes `page=abc` a clear 400.
- The upper limit on `per_page` protects the server: without it, `per_page=1000000` is a denial-of-service request.
- `count` changed meaning, from "books in this response" to "books matching". That breaks any client that relied on
  the old meaning. In a public API, you'd add a new field (`total`) or a new version instead. Chapter 20's exercise 5
  tests may need updating.
- Offset pagination is simple but drifts when books are added between requests, and large offsets get slow. For big
  or fast-changing collections, *cursor* pagination (`?after=<last id>`) avoids both.

</details>

3. **Reading history.** Add `GET /loans/history` for members: their returned loans, most recently returned first,
   with title, author, and the dates borrowed and returned. Add a `returnedLoansOf()` method to the `LoanRepository`
   interface and its PDO implementation, a template, and a functional test that proves another member's loans don't
   appear.

<details>
<summary>Solution</summary>

The interface method, in `Domain/LoanRepository.php`:

```php
    /**
     * The member's returned loans, most recently returned first.
     *
     * @return list<Loan>
     */
    public function returnedLoansOf(int $memberId, int $limit = 50): array;
```

The implementation in `PdoLoanRepository`. The limit is bound as an integer, like `SuggestionStore::latest()` does,
because a `LIMIT` placeholder needs a number, not a string:

```php
    public function returnedLoansOf(int $memberId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM loans WHERE member_id = :member AND returned_on IS NOT NULL
             ORDER BY returned_on DESC, id DESC LIMIT :limit',
        );
        $statement->bindValue('member', $memberId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_values(array_map($this->toLoan(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }
```

The route in `LoanController`. As with `/loans`, whose history to show comes from the session, so there's no id to
check:

```php
    #[Route('GET', '/loans/history', Access::Member)]
    public function history(Member $member): Response
    {
        $loans = $this->loans->returnedLoansOf($member->id ?? throw new LogicException('A logged-in member has an id'));

        return $this->view->page('Books I have read', 'loan-history', [
            'loans' => $loans,
            'books' => $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
        ]);
    }
```

`templates/loan-history.php`:

```php
<?php
/**
 * @var list<Bookshelf\Domain\Loan> $loans          returned loans, most recent first
 * @var array<int, Bookshelf\Domain\Book> $books    by id
 */
?>
<?php if ($loans === []): ?>
    <p>You haven't returned any books yet.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Title</th><th>Author</th><th>Borrowed</th><th>Returned</th></tr>
        </thead>
        <tbody>
        <?php foreach ($loans as $loan): ?>
            <tr>
                <td><?= e($books[$loan->bookId]->title ?? 'Unknown book') ?></td>
                <td><?= e($books[$loan->bookId]->author ?? '') ?></td>
                <td><?= e($loan->lentOn->format('j M Y')) ?></td>
                <td><?= e($loan->returnedOn?->format('j M Y')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<p><a href="/loans">Back to my loans</a></p>
```

The test. Dune has one copy, so Ada returns it before Alan borrows it; Alan's loan is still open, and Ada's page must
show Dune exactly once:

```php
    public function testTheHistoryListsOnlyMyReturnedLoans(): void
    {
        $this->lend('ada@example.org');                                              // loan 1
        $this->clock->advance('+3 days');
        $this->container->get(LendingService::class)->returnLoan(1);
        $this->lend('alan@example.org');                                             // loan 2, Alan's, still out
        $this->logIn('ada@example.org');

        $body = $this->get('/loans/history')->body;

        self::assertStringContainsString('<td>11 Oct 2026</td>', $body);
        self::assertStringContainsString('<td>14 Oct 2026</td>', $body);
        self::assertSame(1, substr_count($body, '<td>Dune</td>'));
    }
```

Adding a method to an interface breaks every class that implements it. Here there's only `PdoLoanRepository`, but an
in-memory fake like the one chapter 20's exercise 6 wrote for members would need the method too. PHPStan reports any
you miss.
A link to the page from `/loans` completes the feature. For privacy, consider whether members should be able to turn
the history off; a library is a place where that question matters.

</details>

4. **API tokens.** A member wants a script that checks their loans every morning. Scripts can't fill in login forms,
   so add personal API tokens:
   - a migration for an `api_tokens` table that stores a hash of each token, never the token;
   - a `bookshelf token:create <email>` command that prints a new token once;
   - a middleware that, for `/api/` requests with `Authorization: Bearer <token>`, makes the token's owner the
     request's member, and answers 401 for a wrong token;
   - `GET /api/loans` (`Access::Member`) with the member's open loans as JSON;
   - unauthenticated requests to member-only API routes get **401** with a `WWW-Authenticate: Bearer` header instead of
     a redirect to the login page.

   Explain why a fast hash (SHA-256) is fine for tokens when chapter 18 insisted on bcrypt for passwords, and whether
   token-authenticated requests need CSRF protection.

<details>
<summary>Solution</summary>

`migrations/004_create_api_tokens.sql`. `ON DELETE CASCADE` removes a member's tokens with the member:

```sql
-- Personal API tokens, for scripts that call the JSON API. Only a SHA-256 hash of each token is stored.
CREATE TABLE api_tokens (
    id           INTEGER PRIMARY KEY,
    member_id    INTEGER NOT NULL REFERENCES members (id) ON DELETE CASCADE,
    token_hash   TEXT    NOT NULL UNIQUE CHECK (length(token_hash) = 64),
    created_at   TEXT    NOT NULL
) STRICT;
```

`src/Security/ApiTokens.php`:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Security;

use Bookshelf\Support\Clock;
use DateTimeZone;
use PDO;

/**
 * Personal API tokens: long random secrets that identify a member to the JSON API.
 *
 * The database keeps only a SHA-256 hash of each token, so a leaked database (or backup) leaks no usable token.
 * A fast hash is right here, unlike for passwords: a token has 256 random bits, so there is nothing to guess.
 */
final readonly class ApiTokens
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
    ) {
    }

    /** Creates a token for the member. The plain token is returned once and never stored. */
    public function create(int $memberId): string
    {
        $token = 'bks_' . bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO api_tokens (member_id, token_hash, created_at) VALUES (?, ?, ?)')
            ->execute([
                $memberId,
                self::hash($token),
                $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]);

        return $token;
    }

    /** The id of the member the token belongs to, or null for an unknown token. */
    public function memberIdFor(#[\SensitiveParameter] string $token): ?int
    {
        $statement = $this->pdo->prepare('SELECT member_id FROM api_tokens WHERE token_hash = ?');
        $statement->execute([self::hash($token)]);
        $memberId = $statement->fetchColumn();

        return $memberId === false ? null : (int) $memberId;
    }

    private static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
```

`src/Web/Middleware/ApiTokenMiddleware.php`. It goes into the kernel's list right after `AuthenticationMiddleware`,
so a token overrides any session on API requests:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Web\Middleware;

use Bookshelf\Domain\MemberRepository;
use Bookshelf\Http\HttpException;
use Bookshelf\Http\Middleware;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;
use Bookshelf\Security\ApiTokens;

/**
 * For API requests with "Authorization: Bearer <token>", makes the token's owner the request's member.
 * A token that is present but wrong is an error, never a silent fallback to "not logged in".
 */
final readonly class ApiTokenMiddleware implements Middleware
{
    public function __construct(
        private ApiTokens $tokens,
        private MemberRepository $members,
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $authorization = $request->header('Authorization');
        if ($authorization === null || !str_starts_with($request->path, '/api/')) {
            return $next->handle($request);
        }

        $memberId = preg_match('/^Bearer +(\S+)$/i', $authorization, $matches) === 1
            ? $this->tokens->memberIdFor($matches[1])
            : null;
        if ($memberId === null) {
            throw new HttpException(401, 'The API token is missing or invalid.', [
                'WWW-Authenticate' => 'Bearer error="invalid_token"',
            ]);
        }

        return $next->handle($request->withAttribute('member', $this->members->get($memberId)));
    }
}
```

```php
// config/container.php, in the Kernel's middleware list
        $c->get(Middleware\AuthenticationMiddleware::class),
        $c->get(Middleware\ApiTokenMiddleware::class),
        $c->get(Middleware\CsrfMiddleware::class),
```

In the `Dispatcher`, API routes answer 401 instead of redirecting. RFC 9110 requires a `WWW-Authenticate` header with
every 401, naming the scheme the client should use:

```php
        if ($match->access !== Access::Public && !$member instanceof Member) {
            if (str_starts_with($request->path, '/api/')) {
                // A program can't fill in a login form: tell it which kind of credentials to send.
                throw new HttpException(401, 'This part of the API needs a token.', ['WWW-Authenticate' => 'Bearer']);
            }
            return Response::redirect('/login?' . http_build_query(['next' => $request->target()]));
        }
```

The route, in `LoanController`. It works with a token and also with a session cookie, for JavaScript on Bookshelf's
own pages:

```php
    /** The same list as /loans, for programs: with a session cookie or an API token. */
    #[Route('GET', '/api/loans', Access::Member)]
    public function apiMine(Member $member): Response
    {
        $loans = $this->loans->openLoansOf($member->id ?? throw new LogicException('A logged-in member has an id'));
        $books = $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans));
        $today = $this->clock->now();

        return Response::json(['data' => array_map(fn (Loan $loan): array => [
            'id' => $loan->id,
            'book' => ['id' => $loan->bookId, 'title' => $books[$loan->bookId]->title ?? null],
            'due' => $loan->dueOn->format('Y-m-d'),
            'status' => $loan->status($today)->value,
            'fine_cents' => $loan->fine($today)->cents,
            'renewable' => $loan->canBeRenewed($today),
        ], $loans)]);
    }
```

`src/Cli/Command/CreateTokenCommand.php`, registered in `bin/bookshelf`. The token goes to standard output and the
message to standard error, so `TOKEN=$(php bin/bookshelf token:create ada@example.org)` captures only the token:

```php
<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Cli\UsageError;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Security\ApiTokens;
use LogicException;

final readonly class CreateTokenCommand implements Command
{
    public function __construct(
        private MemberRepository $members,
        private ApiTokens $tokens,
    ) {
    }

    public function name(): string
    {
        return 'token:create';
    }

    public function description(): string
    {
        return 'Create an API token for a member';
    }

    public function help(): string
    {
        return <<<'TEXT'
            Usage: bookshelf token:create <email>

            Prints a new API token on standard output. It is shown only this once; store it like a password.
            TEXT;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $email = $input->requireArgument(0, 'email');
        $member = $this->members->findByEmail($email)
            ?? throw new UsageError("There is no member with the e-mail address $email");

        $output->line($this->tokens->create($member->id ?? throw new LogicException('A stored member has an id')));
        $output->success("Created an API token for {$member->name}");

        return ExitCode::Success;
    }
}
```

Trying it against the development server:

```bash
php bin/bookshelf db:migrate
TOKEN=$(php bin/bookshelf token:create ada@example.org)
curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/loans
curl -s -i http://localhost:8000/api/loans
```

```text
applied 004_create_api_tokens.sql
1 migration applied
Created an API token for Ada Lovelace
{"data":[{"id":1,"book":{"id":2,"title":"Kindred"},"due":"2026-11-22","status":"active","fine_cents":0,"renewable":true}]}
HTTP/1.1 401 Unauthorized
www-authenticate: Bearer
{"error":"This part of the API needs a token."}
```

A functional test (`tests/Functional/ApiTokenTest.php`) checks the three cases: a valid token reads the loans, no token
gets 401 with `WWW-Authenticate: Bearer`, and a wrong token gets 401 with the error message. It builds requests with
`headers: ['authorization' => "Bearer $token"]`, the lower-case form `Request::fromGlobals()` produces.

**Why SHA-256 is enough.** bcrypt is slow on purpose because passwords are guessable: people choose them, so an
attacker with the hashes tries likely passwords, and slowness limits how many. A token is 32 bytes from
`random_bytes()`, 2²⁵⁶ possibilities, and nobody can guess any of them however fast the hash is. A fast hash also
allows looking the token up by its hash with an index. A slow, salted hash would force checking every stored hash.
Hashing still matters, because a leaked backup must not contain working credentials. The `bks_` prefix makes leaked
tokens easy to recognise, for instance by secret scanners in code repositories.

**CSRF.** CSRF works because browsers attach cookies to cross-site requests automatically. They never add an
`Authorization` header by themselves, so a request authenticated only by a bearer token can't be forged that way.
`GET /api/loans` doesn't change anything anyway. If you add state-changing API routes, `CsrfMiddleware` may skip
requests that `ApiTokenMiddleware` authenticated (mark them with a request attribute), but it must still check
cookie-authenticated ones.

</details>

5. **Design reservations.** Members want to reserve a book whose copies are all out. When a copy comes back, it should
   be set aside for the first member in the queue for 7 days, then go to the next one or back on the shelf. Don't
   implement it fully. Write the design: the table, the rules, which classes change and how, the routes, and the tests
   you'd write first. Then write the changed `LendingService::returnLoan()`.

<details>
<summary>Solution</summary>

**The table.** A hold is an entity with a life cycle, like a loan:

```sql
CREATE TABLE holds (
    id         INTEGER PRIMARY KEY,
    book_id    INTEGER NOT NULL REFERENCES books (id),
    member_id  INTEGER NOT NULL REFERENCES members (id),
    placed_on  TEXT    NOT NULL,                       -- 'YYYY-MM-DD'
    ready_on   TEXT,                                   -- a copy was set aside on this date
    status     TEXT    NOT NULL DEFAULT 'waiting'
               CHECK (status IN ('waiting', 'ready', 'fulfilled', 'expired', 'cancelled'))
) STRICT;

-- One active hold per member and book.
CREATE UNIQUE INDEX one_active_hold ON holds (book_id, member_id) WHERE status IN ('waiting', 'ready');
CREATE INDEX holds_queue ON holds (book_id, status, placed_on);
```

**The rules**, and where each lives:

| Rule | Where |
|---|---|
| A hold is only allowed when no copy is on the shelf | `LendingService::reserve()` |
| One active hold per member and book | `reserve()`, backed by the unique index |
| A member can't reserve a book they have on loan | `reserve()` |
| A returned copy goes to the first waiting hold, not the shelf | `LendingService::returnLoan()` |
| A ready hold expires after 7 days; the copy passes to the next hold or the shelf | `LendingService::expireHolds()`, run daily by a command |
| A set-aside copy can only be lent to that hold's member | `LendingService::lend()` |

**`Book::$available` keeps its meaning**: copies on the shelf that anyone may borrow. A set-aside copy is not available.
That keeps the catalog and the existing checks correct without changes: lending the last *available* copy still
fails when the only copy left is set aside.

**New and changed classes.** `Domain/Hold` (entity: `place()`, `markReady()`, `fulfil()`, `expire()`, `cancel()`,
each checking the current status), `Domain/HoldStatus` (enum), `Domain/HoldRepository` (interface: `firstWaitingFor()`,
`readyFor()`, `readySince()`, `save()`), `Persistence/PdoHoldRepository`, and in `LendingService`: `reserve()`,
`expireHolds()`, and changes to `lend()` and `returnLoan()`.

`lend()` gains one branch. If the member has a ready hold for this book, the copy is already off the shelf, so the hold
is fulfilled instead of calling `lendCopy()`:

```php
        $hold = $this->holds->readyFor($bookId, $memberId);
        if ($hold !== null) {
            $hold->fulfil();
            $this->holds->save($hold);
        } else {
            $book->lendCopy();
        }
```

**Routes.** `POST /books/{id}/hold` (member; a **Reserve** button on the book page when no copy is available),
`POST /holds/{id}/cancel` (member, with the ownership check from step 4), the member's holds on `/loans`, and a
"Set aside" list on the lending desk, so staff know which copies to keep behind the counter.

**Tests first**, as integration tests of `LendingService` like the existing ones: a return with a waiting hold doesn't
increase `available` and marks the hold ready; only the hold's member can borrow the set-aside copy; an expired hold
passes the copy to the next in line, oldest first; with no one waiting, expiry puts the copy back on the shelf;
reserving a book with a copy on the shelf is refused; and a second hold for the same member and book is refused.

**The changed return:**

```php
    /** Records the return and gives back the fine owed (zero if returned on time). */
    public function returnLoan(int $loanId): Money
    {
        return $this->database->transactional(function () use ($loanId): Money {
            $loan = $this->loans->get($loanId);
            $today = $this->clock->now();
            $loan->markReturned($today);
            $this->loans->save($loan);

            // The copy goes to the first member waiting for it; only if no one is waiting does it go on the shelf.
            $hold = $this->holds->firstWaitingFor($loan->bookId);
            if ($hold !== null) {
                $hold->markReady($today);
                $this->holds->save($hold);
            } else {
                $book = $this->books->get($loan->bookId);
                $book->returnCopy();
                $this->books->save($book);
            }

            return $loan->fine($today);
        });
    }
```

Everything happens in the existing transaction, so a return and the hold it fulfils are saved together or not at all.
Notifying the member ("your book is ready") shouldn't happen inside the transaction: an e-mail can't be rolled back.
Record the event (a `notified_on` column, or an outbox table) and let a background job send it after the commit,
as in chapter 22.

</details>

---
← Previous: [A tour of the major frameworks](23-a-tour-of-the-major-frameworks.md) | Next: [Back to the start](README.md) →
