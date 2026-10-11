# Chapter 23 — A tour of the major frameworks

## What you'll learn

- What a framework adds on top of the parts you built in chapter 19, and what it costs
- How the PSR standards let frameworks and libraries share components
- Laravel: its conventions, Eloquent, Blade, Artisan, and the shape of a Laravel version of Bookshelf
- Symfony: components, bundles, the compiled container, Doctrine and Twig, and the same Bookshelf pieces in Symfony
- Micro-frameworks: Slim and Mezzio, with a working Slim version of Bookshelf's JSON API
- The rest of the landscape: CakePHP, CodeIgniter, Yii, API Platform, application servers, and CMSs
- How to choose, and how to keep your domain code independent of whichever framework you pick

Every framework in this chapter does what you did by hand in chapter 19: it turns a request into a response through a
front controller, a router, middleware, controllers and a container, and it adds a database layer, templates, a
command-line tool, and dozens of features you'd otherwise write and maintain yourself. Because you've built the
parts, none of this is magic any more. This chapter maps each framework onto those parts, shows what a slice of
Bookshelf looks like in it, and helps you choose.

Framework code in this chapter is marked as "just a taste": the point is to recognize the shapes and know where to go
next, not to learn a framework's API from a few snippets. Each framework has excellent official documentation, and
it's the place to continue.

## What a framework gives you

The parts from chapter 19, and what a full-stack framework puts in their place:

| Bookshelf (chapter 19) | Laravel | Symfony | Slim |
|---|---|---|---|
| `public/index.php` front controller | `public/index.php` | `public/index.php` (with `symfony/runtime`) | `public/index.php` |
| `Request`, `Response` | `Illuminate\Http\Request`, built on Symfony's HttpFoundation | HttpFoundation `Request`, `Response` | Any PSR-7 implementation |
| `Router` with `#[Route]` attributes | `routes/web.php`: `Route::get(...)` | `#[Route]` attributes on controllers | `$app->get(...)` |
| `Middleware` and `Pipeline` | Middleware classes | Event listeners on kernel events | PSR-15 middleware |
| `Container` with autowiring | Service container, autowiring | DependencyInjection: autowired, compiled to PHP | Any PSR-11 container (PHP-DI) |
| `View` with PHP templates | Blade | Twig | Optional (`slim/twig-view`, `slim/php-view`) |
| PDO repositories | Eloquent ORM (Active Record) | Doctrine ORM (Data Mapper) | Your choice |
| `Migrator` and SQL files | Migrations in PHP, `php artisan migrate` | Doctrine Migrations | Your choice |
| `bin/bookshelf` CLI | Artisan | Console | Your choice (often `symfony/console`) |
| `Csrf`, `Session`, `Authenticator`, `LoginThrottle` | Built in, plus starter kits | Security bundle, Form CSRF, login throttling | Your choice |
| `ErrorHandlerMiddleware` | Exception handler, error pages | ErrorHandler component | Error middleware |
| Functional tests through `Kernel` | `$this->get('/')->assertOk()` | `WebTestCase` | `$app->handle($request)` |
| `loans:overdue` on cron | Task scheduler, queues | Scheduler, Messenger | Your choice |

Full-stack frameworks fill every row. Micro-frameworks fill the top half (routing, middleware, a container) and leave
the rest to libraries you choose. Either way, you get:

- **Code you don't write or maintain**: authentication, password resets, email, file storage, caching, queues,
  localization, rate limiting, each with security fixes from a large community.
- **Conventions**: where files go, how things are named, how a feature is structured. A developer who knows the
  framework is productive in your project on the first day.
- **An ecosystem** of packages built for that framework, and answers to most questions already written down.

And it costs:

- **Coupling.** Code written against a framework's APIs moves with that framework. Major versions bring upgrade work
  (both Laravel and Symfony ship a major version regularly, with tooling to help).
- **Indirection.** More layers between the request and your code, which makes debugging harder until you know them.
  Chapter 19 is your map.
- **Overhead** for very small applications, which a micro-framework or no framework avoids.

### The PSR standards

The PHP-FIG's PSRs (chapter 19) are why these frameworks share so much. A PSR-3 logger (Monolog) works in all of them;
PSR-7, PSR-15 and PSR-17 let Slim and Mezzio use any HTTP message library; PSR-11 lets any container plug into Slim.
And the big frameworks are themselves built from reusable libraries: Laravel uses Symfony's HttpFoundation, Console
and other components, and Drupal is built on Symfony too. Learning one framework teaches you parts of the others.

## Laravel

Laravel (version 13 as of this writing; requires PHP 8.3+) is the most popular PHP framework. It favors developer
experience: expressive APIs, sensible defaults, a large official ecosystem, and conventions that make common work
short. Create a project with `composer create-project laravel/laravel bookshelf` (or the `laravel new` installer).

```text
bookshelf/
├── app/
│   ├── Http/Controllers/     controllers
│   ├── Models/               Eloquent models (one class per table)
│   └── Providers/            service registration
├── bootstrap/app.php         routing, middleware and exception handling configuration
├── config/                   configuration files, reading values from .env
├── database/migrations/      schema changes, written in PHP
├── resources/views/          Blade templates
├── routes/web.php            routes for the web UI; routes/console.php for commands and the schedule
├── storage/                  logs, cache, compiled templates, uploads
├── tests/                    PHPUnit or Pest tests
└── artisan                   the command-line tool
```

### Routes and controllers

> **Just a taste — you don't need to learn or fully understand this now.** Bookshelf's catalog page and lending action in Laravel; see the Laravel documentation on routing and controllers.

```php
// routes/web.php
use App\Http\Controllers\BookController;
use App\Http\Controllers\LoanController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BookController::class, 'index']);
Route::get('/books/{book}', [BookController::class, 'show']);
Route::post('/books/{book}/loans', [LoanController::class, 'store'])->middleware('auth');
```

```php
<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookController extends Controller
{
    public function index(Request $request): View
    {
        $books = Book::query()
            ->when($request->query('q'), fn ($query, $q) => $query->where('title', 'like', "%{$q}%"))
            ->orderBy('title')
            ->paginate(20);

        return view('books.index', ['books' => $books]);
    }

    public function show(Book $book): View      // "route model binding": {book} is loaded by id, or a 404
    {
        return view('books.show', ['book' => $book]);
    }
}
```

What you recognize: routes map to controller methods, and the container builds controllers and passes them what
their parameters ask for, like Bookshelf's `Dispatcher`. What's new: `Book $book` is loaded from the database by the
`{book}` parameter automatically, and `paginate(20)` handles page links. The `where('title', 'like', …)` call binds the
search term as a parameter, so it's not SQL injection. But unlike Bookshelf's repository (chapter 17), it doesn't
escape `%` and `_` in the search term.

Validation reads like a specification, and a failure redirects back with error messages (or returns a 422 JSON
response to API clients):

> **Just a taste — you don't need to learn or fully understand this now.** Validating Bookshelf's suggestion form in Laravel.

```php
public function store(Request $request): RedirectResponse
{
    $data = $request->validate([
        'title' => ['required', 'string', 'max:200'],
        'author' => ['required', 'string', 'max:200'],
        'isbn' => ['nullable', 'digits:13', 'unique:books,isbn'],
    ]);

    Suggestion::create($data);

    return redirect('/')->with('status', 'Thank you for the suggestion!');
}
```

CSRF protection for forms is on by default (Blade's `@csrf` directive outputs the hidden field), and so are the
session, cookie encryption, and authentication guards. Starter kits generate login, registration and password-reset
pages in one command.

### Eloquent: Active Record

Eloquent models are **Active Record** objects: a model class represents a table, an instance represents a row, and the
object saves itself:

> **Just a taste — you don't need to learn or fully understand this now.** Bookshelf's books and loans as Eloquent models.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'author', 'isbn', 'genre', 'year', 'copies'])]
class Book extends Model
{
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function isAvailable(): bool
    {
        return $this->loans()->whereNull('returned_on')->count() < $this->copies;
    }
}

// Elsewhere:
$book = Book::create(['title' => 'Dune', 'author' => 'Frank Herbert', 'isbn' => '9780441013593', 'copies' => 2]);
$book->copies = 3;
$book->save();

$late = Loan::with('book', 'member')->whereNull('returned_on')->where('due_on', '<', today())->get();
```

`#[Fillable]` lists the attributes that may be mass-assigned from input, a protection against a request setting
columns it shouldn't (like `is_admin`). `with('book', 'member')` loads the related rows in two extra queries instead of
one per loan, the N+1 fix from chapter 21. Forgetting it is the most common Eloquent performance problem, and Laravel
can be configured to throw an exception when a relation is lazy-loaded.

### Blade, Artisan, and the rest

Blade templates compile to plain PHP and are cached. `{{ $book->title }}` escapes its output with
`htmlspecialchars()`; `{!! $html !!}` doesn't, and needs a reason (chapter 18).

Artisan is Laravel's CLI, and the place where commands, generators, and the scheduler live:

```bash
php artisan make:model Loan --migration      # a model class and a migration file
php artisan migrate                          # run pending migrations
php artisan route:list                       # every route, its middleware and controller
php artisan queue:work                       # a background worker (chapter 22)
php artisan schedule:run                     # run due scheduled tasks; cron calls this every minute
php artisan config:cache route:cache         # production optimizations: cache configuration and routes
```

> **Just a taste — you don't need to learn or fully understand this now.** The overdue report scheduled in Laravel's routes/console.php.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('loans:overdue')->dailyAt('07:00')->withoutOverlapping();
```

Around the framework is an official ecosystem: queues with a dashboard (Horizon), WebSockets (Reverb), application
performance on long-running servers (Octane, on FrankenPHP, RoadRunner or Swoole), reactive components without
writing JavaScript (Livewire), single-page apps with server-side routing (Inertia), admin panels (the community's
Filament), and hosting platforms. Tests extend Laravel's `TestCase`, which adds HTTP helpers on top of PHPUnit (or
Pest, a test runner with a more compact syntax):

> **Just a taste — you don't need to learn or fully understand this now.** A Laravel feature test of the catalog.

```php
public function test_the_catalog_lists_the_books(): void
{
    Book::factory()->create(['title' => 'Dune']);

    $this->get('/')->assertOk()->assertSee('Dune');
}
```

A note on **facades**: `Route::get()` and `Schedule::command()` look like static calls, but they forward to objects
in the container. They're convenient, but they hide a class's dependencies. Constructor injection, which Laravel
supports everywhere, keeps them visible, and makes the code read like Bookshelf's.

## Symfony

Symfony (version 8.1 as of this writing; requires PHP 8.4+) is both a framework and a set of over 50 standalone
**components** (HttpFoundation, Console, Routing, Mailer, Messenger, Validator, …) used across the PHP world. It
favors explicitness and long-term maintainability: a strict release schedule with long-term support versions,
deprecations announced a version ahead, and a container compiled to plain PHP. Create a project with
`composer create-project symfony/skeleton bookshelf` (minimal) and add features as needed: `composer require twig`,
`composer require orm`. **Symfony Flex**, a Composer plugin, installs each package with a ready-made configuration
"recipe".

```text
bookshelf/
├── bin/console               the command-line tool
├── config/                   YAML or PHP configuration: packages, routes, services
├── migrations/               Doctrine migrations
├── public/index.php
├── src/
│   ├── Controller/
│   ├── Entity/               Doctrine entities
│   ├── Repository/           Doctrine repositories
│   └── Command/              console commands
├── templates/                Twig templates
└── tests/
```

### Controllers and routing

> **Just a taste — you don't need to learn or fully understand this now.** Bookshelf's book page as a Symfony controller; see the Symfony documentation on controllers and routing.

```php
<?php

namespace App\Controller;

use App\Repository\BookRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BookController extends AbstractController
{
    public function __construct(
        private readonly BookRepository $books,
    ) {
    }

    #[Route('/books/{id}', name: 'book_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $book = $this->books->find($id) ?? throw $this->createNotFoundException("There is no book with id $id.");

        return $this->render('book/show.html.twig', ['book' => $book]);
    }
}
```

This is very close to Bookshelf's `CatalogController`: a route attribute, constructor injection, a typed route
parameter, and a 404 exception. Symfony reads the attributes and compiles all routes into an optimized matcher once,
caching the result. `name: 'book_show'` lets templates generate URLs (`path('book_show', {id: 3})`) instead of
hard-coding them.

Symfony's container is **compiled**: at build time it resolves every service definition, autowiring included, into a
generated PHP class with one method per service. At run time there's no reflection and no configuration parsing; the
container is plain code that OPcache caches. That's the production version of Bookshelf's reflection-based
`Container`.

Middleware doesn't exist under that name. The HTTP kernel dispatches **events** (`kernel.request`, `kernel.controller`,
`kernel.response`, `kernel.exception`), and listeners for them do what middleware does. Bookshelf's security headers
would be a `kernel.response` listener.

### Doctrine: Data Mapper

Symfony applications usually use **Doctrine ORM**, which follows the **Data Mapper** pattern: entities are plain
objects that know nothing about the database, and a separate *entity manager* loads and saves them, using mapping
metadata (attributes):

> **Just a taste — you don't need to learn or fully understand this now.** A Bookshelf book as a Doctrine entity.

```php
<?php

namespace App\Entity;

use App\Repository\BookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookRepository::class)]
class Book
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, Loan> */
    #[ORM\OneToMany(targetEntity: Loan::class, mappedBy: 'book')]
    private Collection $loans;

    public function __construct(
        #[ORM\Column(length: 200)]
        private string $title,
        #[ORM\Column(length: 13, unique: true)]
        private string $isbn,
        #[ORM\Column]
        private int $copies = 1,
    ) {
        $this->loans = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

// In a service:
$entityManager->persist($book);    // "track this new object"
$entityManager->flush();           // write all changes to tracked objects, in one transaction
```

Active Record and Data Mapper are the two big ORM designs, and Bookshelf's hand-written repositories are a small
Data Mapper:

| | Active Record (Eloquent) | Data Mapper (Doctrine) |
|---|---|---|
| Who saves an object | The object itself: `$book->save()` | The entity manager: `persist()`, `flush()` |
| What an entity knows | Its table, its connection, how to query | Nothing about storage |
| Strengths | Very little code; fast to build with | Domain objects stay pure and testable; complex mappings |
| Costs | Domain logic mixed with persistence; tests need a database | More concepts (unit of work, identity map, proxies) |

Neither is "right". Active Record fits applications that are mostly forms over tables; Data Mapper fits a rich domain
with rules like Bookshelf's lending policy. Both generate SQL for you, and both let you write SQL (or DQL, Doctrine's
object query language) when generated SQL isn't good enough, which chapter 17 has prepared you for.

### Twig, commands, and the rest

Twig templates escape output by default (`{{ book.title }}`) and can't run arbitrary PHP, which makes them safe to hand
to designers. Console commands are classes, discovered automatically:

> **Just a taste — you don't need to learn or fully understand this now.** Bookshelf's overdue report as a Symfony console command.

```php
<?php

namespace App\Command;

use App\Repository\LoanRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'loans:overdue', description: 'List the loans that are past their due date')]
final class OverdueCommand
{
    public function __construct(
        private readonly LoanRepository $loans,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Print the loans as JSON')] bool $json = false): int
    {
        $rows = $this->loans->overdueReport(new \DateTimeImmutable('today'));
        $json ? $io->writeln(json_encode($rows, JSON_PRETTY_PRINT)) : $io->table(array_keys($rows[0] ?? []), $rows);

        return Command::SUCCESS;
    }
}
```

The same command as Bookshelf's `OverdueCommand`, with option parsing, help output and styling from the Console
component. Around the framework: Messenger (queues and workers), Scheduler, Mailer, Security (authentication,
authorization voters, login throttling), Forms (with CSRF built in), Validator, Serializer, and **API Platform**, which
builds a REST or GraphQL API with documentation from your entities.

## Micro-frameworks: Slim and Mezzio

A micro-framework is a router, a middleware pipeline and a container interface, and little more. It suits APIs,
small services, and teams that prefer to pick their own libraries for everything else.

**Slim** (version 4) is built entirely on PSRs: PSR-7 messages, PSR-15 middleware, PSR-11 containers. Here is
Bookshelf's `GET /api/books/{id}` endpoint in Slim, reusing Bookshelf's domain and persistence classes unchanged:

> **Just a taste — you don't need to learn or fully understand this now.** Slim 4 with PHP-DI (`composer require slim/slim slim/psr7 php-di/php-di`); see the Slim documentation.

```php
<?php

declare(strict_types=1);

use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

// Bookshelf's domain services, built by its existing wiring; the framework only replaces the HTTP layer.
$bookshelf = require dirname(__DIR__) . '/config/container.php';

$container = new Container();
$container->set(BookRepository::class, fn () => $bookshelf->get(BookRepository::class));

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(displayErrorDetails: false, logErrors: true, logErrorDetails: false);

$app->get('/api/books/{id:\d+}', function (Request $request, Response $response, array $args): Response {
    try {
        $book = $this->get(BookRepository::class)->get((int) $args['id']);
    } catch (EntityNotFound) {
        throw new HttpNotFoundException($request, "There is no book with id {$args['id']}.");
    }

    $response->getBody()->write(json_encode(
        ['data' => ['id' => $book->id, 'title' => $book->title, 'author' => $book->author]],
        JSON_THROW_ON_ERROR,
    ));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
```

```bash
curl -i http://localhost:8080/api/books/1
```

```text
HTTP/1.1 200 OK
content-type: application/json

{"data":{"id":1,"title":"Dune","author":"Frank Herbert"}}
```

```bash
curl -H 'Accept: application/json' http://localhost:8080/api/books/99
```

```text
{
    "message": "404 Not Found"
}
```

Things to notice:

- **PSR-7 responses are immutable**, like Bookshelf's: `withHeader()` returns a new response. But the *body* is a
  stream that you write to, which is the one mutable part of PSR-7.
- **The route closure is bound to the container**, so `$this->get(...)` fetches services. Controller classes work too,
  as `[BookController::class, 'show']`, built by PHP-DI with autowiring.
- **Error rendering depends on the `Accept` header.** The default error handler sends HTML to browsers and JSON to
  clients that ask for it. With `displayErrorDetails: false` it shows only a generic message, never the exception's
  own; exercise 2 replaces it with Bookshelf-style JSON errors.
- **Bookshelf's `BookRepository` didn't change.** Only the HTTP layer did.

**Mezzio** (from the Laminas project, the successor of Zend Framework) is the same idea with more structure: PSR-15
middleware pipelines everywhere, a choice of routers and containers, and Laminas components for forms, validation and
more.

## The rest of the landscape

| Framework or tool | What it is | Notes |
|---|---|---|
| **CakePHP** (5.x) | Full-stack, convention over configuration, Active Record ORM | One of the oldest PHP frameworks; stable and well documented |
| **CodeIgniter** (4.x) | Lightweight full-stack framework | Small footprint, easy to learn; popular for smaller projects |
| **Yii** (2.x; Yii 3 as separate packages) | Full-stack with strong code generation (Gii) | Yii 3 is built from PSR-based packages |
| **Laminas / Mezzio** | Components and a PSR-15 micro-framework | Enterprise heritage (Zend Framework) |
| **API Platform** | API framework on top of Symfony or Laravel | REST and GraphQL with OpenAPI docs from your models |
| **FrankenPHP, RoadRunner, Swoole** | Application servers | Keep the app in memory between requests (chapter 22); Laravel Octane and Symfony Runtime integrate with them |
| **WordPress, Drupal, TYPO3** | Content management systems | Not frameworks, but huge PHP platforms; Drupal is built on Symfony components |

Hundreds of standalone libraries fill in the gaps for any framework, or for none: Monolog (logging), Guzzle and
Symfony HttpClient (HTTP clients), Doctrine DBAL (a database abstraction without the ORM), Flysystem (file storage),
Carbon (dates, used by Laravel), league/commonmark (Markdown), and many more on Packagist.

## How to choose

Some questions that usually settle it:

| If… | Consider |
|---|---|
| You want to ship a typical web application fast, with a large ecosystem and many ready-made features | Laravel |
| The application will live for many years, has complex domain rules, or the team values explicit configuration | Symfony |
| You're building an API or a small service and want to choose every library yourself | Slim or Mezzio |
| You're building a REST or GraphQL API around a data model | API Platform |
| The team already knows a framework well | That framework, unless there's a strong reason not to |
| The application is tiny, or is a learning exercise | No framework, as in this tutorial |

Hiring and community size matter as much as technical merits: Laravel and Symfony have by far the most developers,
packages, tutorials, and paid support options. All the frameworks above are capable of serious production work; the
differences are in style and in what's included.

### Keep the domain yours

Whatever you choose, keep the rules of your application in code that doesn't depend on the framework. Bookshelf's
`Domain` and `Lending` namespaces (`Loan`, `Money`, `Isbn`, `LendingService`) use no HTTP classes, no framework, and
only their own repository interfaces. That code would move into a Laravel or Symfony project unchanged, as it moved
into Slim above; only the controllers, the persistence implementation and the wiring would be rewritten. In practice:

- Controllers stay thin: parse input, call a service, render the result (chapter 19).
- Business rules live in plain classes with their own unit tests, which run without booting the framework.
- Framework features are used at the edges (HTTP, persistence, queues), where they shine.

This is the same separation that made Bookshelf testable in chapter 20. It also makes framework upgrades smaller,
because less code touches the framework.

## Key takeaways

- Every PHP framework is built from the parts of chapter 19: front controller, request/response, router, middleware,
  container, controllers, views. Knowing them makes any framework readable.
- Frameworks trade coupling and indirection for a lot of maintained code, conventions, and an ecosystem. PSRs let
  libraries work across frameworks, and the big frameworks share components.
- Laravel (13, PHP 8.3+) favors developer speed: expressive routing and validation, Eloquent (Active Record), Blade,
  Artisan, queues and scheduling, and a large first-party ecosystem.
- Symfony (8.1, PHP 8.4+) favors explicitness and longevity: reusable components, attribute routing, a compiled
  container, Doctrine (Data Mapper), Twig, Console, Messenger, and LTS releases.
- Active Record puts persistence in the object; Data Mapper keeps entities storage-agnostic. Choose by how rich your
  domain is.
- Micro-frameworks such as Slim and Mezzio provide routing, PSR-15 middleware and a PSR-11 container, and leave the
  rest to you.
- Choose by the application's lifespan and complexity, the team's experience, and the ecosystem you need. Keep domain
  code framework-independent either way.

## Exercises

1. **Map the parts.** For each of these Bookshelf files, name the equivalent in Laravel and in Symfony, and say in
   one sentence what the framework version adds: `src/Http/Router.php`, `src/Container/Container.php`,
   `src/Web/Middleware/CsrfMiddleware.php`, `src/Persistence/Migrator.php`, `bin/bookshelf`.

<details>
<summary>Solution</summary>

| Bookshelf | Laravel | Symfony | What the framework adds |
|---|---|---|---|
| `Router.php` | `routes/web.php` and the `Route` facade (Illuminate Routing) | Routing component with `#[Route]` | Named routes and URL generation, route groups and prefixes, compiled/cached route tables, model or entity binding |
| `Container.php` | The service container | DependencyInjection component | Compiled (Symfony) or cached definitions, scoped and tagged services, lazy services, configuration parameters |
| `CsrfMiddleware.php` | CSRF middleware (on by default for web routes), Blade's `@csrf` | CSRF protection in Forms and the Security component | Per-form tokens, automatic inclusion in forms, configurable exclusions for webhooks |
| `Migrator.php` | Migrations and `php artisan migrate` | DoctrineMigrationsBundle (`bin/console doctrine:migrations:migrate`) | Migrations written in PHP or generated from entity changes, rollbacks, status listing, multiple databases |
| `bin/bookshelf` | `artisan` | `bin/console` | Argument and option parsing from declarations, help pages, interactive questions, progress bars, shell completion |

The pattern: the framework version of each part is a more complete, much more tested version of the same idea, plus
integration with the other parts (a route that knows its middleware, a migration that knows the ORM).

</details>

2. **JSON errors and security headers in Slim.** Extend the Slim example: add `GET /api/books` with `q` and `genre`
   query parameters like Bookshelf's, make every error a JSON response `{"error": "..."}` with the exception's own
   message for HTTP errors (and an `Allow` header for 405), and port Bookshelf's `SecurityHeadersMiddleware` to a
   PSR-15 middleware that also applies to error responses.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

use Bookshelf\Domain\Book;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Genre;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

/** Bookshelf's SecurityHeadersMiddleware, rewritten as a PSR-15 middleware: the same code, different interfaces. */
final readonly class SecurityHeaders implements MiddlewareInterface
{
    public function __construct(
        private bool $https = false,
    ) {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', "default-src 'self'; object-src 'none'; base-uri 'none'; "
                . "form-action 'self'; frame-ancestors 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $this->https ? $response->withHeader('Strict-Transport-Security', 'max-age=31536000') : $response;
    }
}

/** @return array<string, mixed> */
function bookJson(Book $book): array
{
    return ['id' => $book->id, 'title' => $book->title, 'author' => $book->author, 'genre' => $book->genre?->value];
}

function json(Response $response, mixed $data, int $status = 200): Response
{
    $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
}

$bookshelf = require dirname(__DIR__) . '/config/container.php';
$container = new Container();
$container->set(BookRepository::class, fn () => $bookshelf->get(BookRepository::class));

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addRoutingMiddleware();

// HTTP errors as {"error": "..."} with their own message, like Bookshelf's ErrorHandlerMiddleware does for /api/.
$errors = $app->addErrorMiddleware(displayErrorDetails: false, logErrors: true, logErrorDetails: true);
$errors->setDefaultErrorHandler(function (Request $request, Throwable $e) use ($app): Response {
    [$status, $message] = $e instanceof HttpException
        ? [$e->getCode(), $e->getMessage()]
        : [500, 'Something went wrong.'];
    if (!$e instanceof HttpException) {
        error_log((string) $e);
    }

    $response = json($app->getResponseFactory()->createResponse(), ['error' => $message], $status);

    return $e instanceof HttpMethodNotAllowedException
        ? $response->withHeader('Allow', implode(', ', $e->getAllowedMethods()))
        : $response;
});

$app->add(new SecurityHeaders());     // added last, so it runs first (outermost) and also wraps error responses

$app->get('/api/books', function (Request $request, Response $response): Response {
    $query = $request->getQueryParams();
    $genre = Genre::tryFrom((string) ($query['genre'] ?? ''));
    $books = $this->get(BookRepository::class)->search((string) ($query['q'] ?? ''), $genre);

    return json($response, ['count' => count($books), 'data' => array_map(bookJson(...), $books)]);
});

$app->get('/api/books/{id:\d+}', function (Request $request, Response $response, array $args): Response {
    try {
        $book = $this->get(BookRepository::class)->get((int) $args['id']);
    } catch (EntityNotFound) {
        throw new HttpNotFoundException($request, "There is no book with id {$args['id']}.");
    }

    return json($response, ['data' => bookJson($book)]);
});

$app->run();
```

```text
GET /api/books?q=dun     200  {"count":1,"data":[{"id":1,"title":"Dune","author":"Frank Herbert","genre":"sf"}]}
GET /api/books/99        404  {"error":"There is no book with id 99."}
GET /nope                404  {"error":"Not found."}
DELETE /api/books/2      405  {"error":"Method not allowed. Must be one of: GET"}   Allow: GET
```

Every response, errors included, carries the security headers. Slim's middleware runs last-added-first, like layers
of an onion added from the inside out, so adding `SecurityHeaders` after the error middleware puts it *outside* it:
it sees the error responses the error middleware produces. Added before, it would never see a response for a
request that threw. Bookshelf's own pipeline (chapter 19) has the same ordering question, and answers it the same way.
The middleware's body is identical to Bookshelf's; only the interface names changed, because Bookshelf's
`Middleware` interface was designed after PSR-15.

</details>

3. **Active Record or Data Mapper?** Bookshelf's `LendingService::lend()` loads a book and a member, checks the lending
   rules in `Loan::start()`, decreases the available copies and saves the loan, all in one transaction. Sketch (in
   pseudocode or PHP) how this looks with Eloquent and with Doctrine, and list one thing that becomes easier and one that
   becomes harder in each, compared with Bookshelf's hand-written repositories.

<details>
<summary>Solution</summary>

With Eloquent, models save themselves and transactions use a closure:

```text
DB::transaction(function () use ($bookId, $memberId) {
    $book = Book::lockForUpdate()->findOrFail($bookId);
    $member = Member::findOrFail($memberId);
    // rules: in a domain service or on the models
    if ($book->available === 0) throw new BookNotAvailable($book);
    $loan = $member->loans()->create(['book_id' => $book->id, 'due_on' => ..., ...]);
    $book->decrement('available');
    return $loan;
});
```

- Easier: no repository classes or mapping code at all; relations (`$member->loans()`) and queries come for free.
- Harder: the rules tend to spread over models, controllers and services, because every model can save itself from
  anywhere. Testing `Loan`'s rules needs a database, since `Loan` *is* a database row.

With Doctrine, entities are plain objects and the unit of work tracks changes:

```text
$em->wrapInTransaction(function () use ($bookId, $memberId) {
    $book = $em->find(Book::class, $bookId, LockMode::PESSIMISTIC_WRITE);
    $member = $em->find(Member::class, $memberId);
    $loan = Loan::start($book, $member, $clock->now());   // same domain code as Bookshelf
    $book->lendCopy();                                     // a method on the entity changes its state
    $em->persist($loan);
    return $loan;                                          // flush happens at the end of the transaction
});
```

- Easier: Bookshelf's domain classes would survive almost unchanged (they're already plain objects), and Doctrine
  writes the SQL and tracks which objects changed, so `PdoBookRepository::save()` and friends disappear.
- Harder: more concepts to learn (managed versus detached entities, flush timing, lazy-loading proxies), and the
  generated SQL needs watching for N+1 queries.

Bookshelf's hand-written repositories are the most explicit of the three (every query is visible SQL) at the cost of
writing and testing the mapping code yourself. That trade-off is reasonable for a small domain and becomes expensive as
the number of tables grows.

</details>

4. **Choose for three projects.** Recommend a framework (or none) for each, and give the two strongest reasons:
   (a) a city library network wants Bookshelf rebuilt with online renewals, email reminders, an admin area and a
   public API, by a team of three PHP developers who know Laravel; (b) a single endpoint that receives webhook events
   from a payment provider, verifies their signature and puts them on a queue; (c) an insurance company's claims
   system, expected to run for 15 years, built by several teams.

<details>
<summary>Solution</summary>

(a) **Laravel.** The team already knows it, which outweighs almost any technical argument; and nearly every feature
on the list (authentication, queued email reminders, scheduling, an admin panel with Filament, API resources and
token authentication) is built in or one well-maintained package away. Keep the lending rules in plain domain classes,
as in Bookshelf, so they're testable without the framework.

(b) **Slim, or no framework.** One route, one middleware (signature verification), one queue client: a full-stack
framework would be mostly unused weight. A micro-framework gives routing, PSR-15 middleware and error handling with
very little code; Bookshelf's chapter 19 kernel would also do. Fast startup and a small dependency tree also mean
fewer security updates to track.

(c) **Symfony.** Its release process (long-term support versions, deprecations always announced a major version
ahead, upgrade tooling) is designed for applications that outlive several major versions. The component structure and
explicit configuration help several teams own separate parts of a large codebase, and Doctrine's Data Mapper keeps a
complex domain model independent of the database.

In all three cases, the reasons are about the team, the lifespan and the required features, not raw speed. Any of
these frameworks is fast enough for all three projects.

</details>

---
← Previous: [Deployment and production](22-deployment-and-production.md) | Next: [Capstone project: Bookshelf](24-capstone-project.md) →
