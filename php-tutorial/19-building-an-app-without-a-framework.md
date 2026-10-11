# Chapter 19 — Building an app without a framework

## What you'll learn

- Why a single front-controller file stops scaling, and what replaces it
- Request and response objects, and why they are immutable
- A router with path parameters, `404` versus `405`, and routes declared with attributes
- Thin controllers, and how a dispatcher passes them the arguments they ask for
- Middleware: the "onion" around the application, and what belongs in it
- Error handling for a whole application in one place
- Dependency injection, and a small container with autowiring
- Templates with a layout, rendered in isolation
- The PSR standards (PSR-7, PSR-15, PSR-11 and friends) that let libraries fit together
- Bookshelf rebuilt on these pieces, with the CLI sharing the same wiring

Every PHP framework is built from the same handful of parts: a front controller, request and response objects, a
router, controllers, middleware, a dependency-injection container and a template renderer. In this chapter you build
each one for Bookshelf, small enough to read in full. The goal is not to replace frameworks (chapter 23 tours them);
it is to make them unsurprising. Once you have written a router and a container yourself, the framework versions are
the same ideas with more features and more testing.

## Where Bookshelf stands

Since chapter 16, every web request runs `public/index.php`: a few hundred lines of functions and a `match` at the
bottom. It works, but its problems grow with every page:

- **Everything reads globals.** Page functions take `$_GET`, `$_POST`, `$_SESSION` and `$_SERVER` directly, so none of
  them can run without a real request. Testing a page means starting a server.
- **Everything prints.** `header()` and `echo` are spread across functions, so nothing can inspect or change a response
  after it is created ("add this header to every page" means editing every page).
- **Wiring is everywhere.** `books()`, `members()` and `authenticator()` each know how to build their objects, and the
  CLI in `bin/bookshelf` repeats the same knowledge.
- **Cross-cutting concerns are hand-placed.** The CSRF check, the security headers and the login check each sit in a
  particular spot of the file, and a new page must remember the rules.

The structure that fixes this:

```text
public/index.php            Request::fromGlobals()  ──▶  Kernel  ──▶  Response::send()
                                                           │
           ┌───────────────────────────────────────────────┘
           ▼
  SecurityHeadersMiddleware ─▶ ErrorHandlerMiddleware ─▶ AuthenticationMiddleware ─▶ CsrfMiddleware ─▶ Dispatcher
                                                                                                          │
                                                                    Router: "GET /books/7" ─▶ CatalogController::show(7)
                                                                                                          │
                                                                               View::page('Dune', 'book', [...])
```

Superglobals are read in exactly one place (`Request::fromGlobals()`), output happens in exactly one place
(`Response::send()`), and objects are built in exactly one place (the container's configuration). Everything in
between is ordinary objects calling each other, which can be created and tested in isolation.

## Requests and responses

### The request

`Request` holds everything the application needs to know about the incoming request, read once from the globals:

```php
final readonly class Request
{
    /**
     * @param array<string, mixed> $query       the query string ($_GET)
     * @param array<string, mixed> $body        form fields ($_POST)
     * @param array<string, string> $cookies
     * @param array<string, string> $headers    by lower-case name
     * @param array<string, mixed> $server      $_SERVER, for details such as REMOTE_ADDR
     * @param array<string, mixed> $attributes  values added while handling the request (route parameters, the member)
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $cookies = [],
        public array $headers = [],
        public array $server = [],
        public array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            Uri::parse($_SERVER['REQUEST_URI'] ?? '/')?->getPath() ?? '/',
            $_GET,
            $_POST,
            $_COOKIE,
            $headers,
            $_SERVER,
        );
    }

    /** A query-string parameter as trimmed text ('' when missing or not a string). */
    public function query(string $name, int $maxLength = 200): string
    {
        return self::text($this->query[$name] ?? null, $maxLength);
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return clone($this, ['attributes' => [...$this->attributes, $name => $value]]);   // PHP 8.5+
    }

    // ... header(), input() for form fields, attribute(), target(), clientAddress()
}
```

PHP passes request headers to scripts as `$_SERVER` entries: `User-Agent` arrives as `HTTP_USER_AGENT`, and the two
body headers `Content-Type` and `Content-Length` arrive without the `HTTP_` prefix. `fromGlobals()` turns them back
into header names. The helper methods `query()` and `input()` replace chapter 16's `stringParam()`: they return a
trimmed, length-limited string, or `''` when the parameter is missing or is an array (chapter 16's `?q[]=x` trick).

The class is `readonly`: a request never changes. When middleware needs to add information (the route's parameters,
the logged-in member), it creates a **modified copy** with `withAttribute()`. PHP 8.5's **clone with**,
`clone($object, ['property' => $value])`, makes that a one-liner even for `readonly` properties, as long as it is
called from inside the class (which is allowed to initialize them):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Http\Request;
use Bookshelf\Http\Response;

$request = new Request('GET', '/books/1', query: ['q' => '  dune ', 'genre' => ['sf']]);
var_dump($request->query('q'), $request->query('genre'), $request->query('missing'));

$routed = $request->withAttribute('id', 1);
var_dump($request->attribute('id'), $routed->attribute('id'));

$response = Response::json(['title' => 'Dune'], 201)->withHeader('Location', '/books/1');
echo $response->status, ' ', $response->header('content-type'), ' ', $response->body, "\n";
```

```text
string(4) "dune"
string(0) ""
string(0) ""
NULL
int(1)
201 application/json; charset=utf-8 {"title":"Dune"}
```

(Snippets in this chapter that `require __DIR__ . '/vendor/autoload.php'` are meant to be saved in
`examples/bookshelf/` and run from there.) Why immutability? Because a request passes through many hands. If
middleware could change the request in place, a controller would see a different request depending on what ran
before it, and a bug in one layer would show up in another. With immutable objects, each layer that wants a change
makes its own copy and passes it on; nothing can change behind your back. Before PHP 8.5, `with...()` methods were
written as "clone, then set the property", which `readonly` properties don't allow after initialization, so such
classes avoided `readonly` or called the constructor again with every argument.

### The response

```php
final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($json, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** 303 See Other: after a POST, the browser follows with a GET. */
    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        return clone($this, ['headers' => [...$this->headers, $name => $value]]);
    }

    /** Hands the response to PHP's SAPI. The only place in the web application that produces output. */
    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
```

A controller **returns** a response instead of printing one. Until `send()` runs, the response is just a value:
middleware can add headers to it, tests can check its status, and "headers already sent" (chapter 16) can no longer
happen, because nothing is sent before the very end. The headers array keeps one value per name, which is enough for
Bookshelf; real HTTP allows repeated headers (`Set-Cookie` above all), which is why PSR-7 stores a list of values per
header. Bookshelf's session cookie is set by PHP's own session functions, which call `header()` themselves.

The front controller is now the only file that touches PHP's globals:

```php
<?php

declare(strict_types=1);

use Bookshelf\Http\Request;
use Bookshelf\Web\Kernel;

// With PHP's built-in server, let it serve existing files (CSS, images) itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$container = require dirname(__DIR__) . '/config/container.php';
$container->get(Kernel::class)->handle(Request::fromGlobals())->send();
```

## Routing

A **router** maps a method and a path to the code that handles them. Chapter 16's `match ("$method $path")` handled
fixed paths; real applications need **path parameters**: `/books/7`, `/books/42/loans`.

### Patterns and regular expressions

Bookshelf's router compiles each route path into a regular expression, with a named group for each parameter. A
parameter is written `{name}` (any characters except `/`) or `{name:regex}` to restrict it:

```php
/** Turns '/books/{id:\d+}' into '~^/books/(?P<id>\d+)$~'. */
private static function compile(string $path): string
{
    $regex = preg_replace_callback(
        '~\{([a-z_][a-z0-9_]*)(?::([^{}]+))?\}~i',
        fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
        $path,
    );
    if ($regex === null || preg_match("~^$regex$~", '') === false) {
        throw new InvalidArgumentException("Invalid route path $path");
    }

    return "~^$regex$~";
}
```

The `preg_match()` on an empty string is a cheap check that the generated pattern compiles: `preg_match()` returns
`false` for an invalid pattern, so a typo in a route fails when routes are registered rather than at the first matching
request. (A parameter's own regex can't contain braces, so write `[0-9][0-9][0-9][0-9]` rather than `\d{4}`; that
keeps the parser simple.)

Matching tries the routes in order. The useful subtlety is the difference between "no such page" and "this page
exists, but not for this method":

```php
/** @throws HttpException 404 when no route has this path, 405 when none accepts this method */
public function match(string $method, string $path): RouteMatch
{
    $method = $method === 'HEAD' ? 'GET' : $method;   // HEAD is a GET without the body
    $allowed = [];

    foreach ($this->routes as $route) {
        if (preg_match($route['regex'], $path, $matches) !== 1) {
            continue;
        }
        if ($route['method'] !== $method) {
            $allowed[] = $route['method'];
            continue;
        }

        return new RouteMatch(
            $route['handler'],
            $route['access'],
            array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY),
        );
    }

    throw $allowed === [] ? HttpException::notFound() : HttpException::methodNotAllowed(array_unique($allowed));
}
```

`preg_match()` puts each named group in `$matches` twice, by name and by number; `array_filter()` with
`ARRAY_FILTER_USE_KEY` keeps the string keys, the names. In action:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Http\HttpException;
use Bookshelf\Http\Router;

$router = new Router();
$router->add('GET', '/books/{id:\d+}', ['BookController', 'show']);
$router->add('POST', '/books/{id:\d+}/loans', ['LoanController', 'lend']);
$router->add('GET', '/members/{email}', ['MemberController', 'show']);

$requests = [['GET', '/books/42'], ['HEAD', '/books/42'], ['GET', '/members/ada@example.org'], ['GET', '/books/dune'],
    ['GET', '/books/42/loans']];

foreach ($requests as [$method, $path]) {
    try {
        $match = $router->match($method, $path);
        printf("%-4s %-26s -> %s %s\n", $method, $path, implode('::', $match->handler), json_encode($match->parameters));
    } catch (HttpException $e) {
        printf("%-4s %-26s -> %d %s\n", $method, $path, $e->status, json_encode($e->headers));
    }
}
```

```text
GET  /books/42                  -> BookController::show {"id":"42"}
HEAD /books/42                  -> BookController::show {"id":"42"}
GET  /members/ada@example.org   -> MemberController::show {"email":"ada@example.org"}
GET  /books/dune                -> 404 []
GET  /books/42/loans            -> 405 {"Allow":"POST"}
```

`/books/dune` doesn't match `\d+`, so it's a 404 without the controller ever seeing an invalid id. `/books/42/loans`
exists for POST only, so a GET gets `405 Method Not Allowed` with an `Allow` header, as HTTP requires.

A linear scan with one regex per route is fine for dozens of routes. Routers for large applications (FastRoute,
Symfony's routing) combine all routes into a few big regular expressions, or compile them to PHP arrays that are
cached, so matching stays fast with thousands of routes. They also generate URLs from route names (exercise 1), so
templates never hard-code paths.

### Routes as attributes

Bookshelf declares routes next to the code that handles them, with an attribute (chapter 11):

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Route
{
    public function __construct(
        public string $method,
        public string $path,
        public Access $access = Access::Public,
    ) {
    }
}
```

```php
final readonly class CatalogController
{
    public function __construct(
        private BookRepository $books,
        private View $view,
    ) {
    }

    #[Route('GET', '/')]
    public function index(Request $request): Response
    {
        $query = $request->query('q');
        $genre = Genre::tryFrom($request->query('genre'));

        return $this->view->page('Catalog', 'catalog', [
            'books' => $this->books->search($query, $genre),
            'query' => $query,
            'genre' => $genre,
        ]);
    }

    #[Route('GET', '/books/{id:\d+}')]
    public function show(int $id): Response
    {
        $book = $this->find($id);

        return $this->view->page($book->title, 'book', ['book' => $book]);
    }

    // ... apiIndex() at GET /api/books, apiShow() at GET /api/books/{id:\d+}
}
```

The router reads them with reflection:

```php
/** @param class-string $class */
public function addController(string $class): void
{
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getAttributes(Route::class) as $attribute) {
            $route = $attribute->newInstance();
            $this->add($route->method, $route->path, [$class, $method->getName()], $route->access);
        }
    }
}
```

The `access` argument declares who may use a route: `Access::Public` (the default), `Access::Member` or
`Access::Staff`. That puts chapter 18's authorization rule in one visible place per route, and the dispatcher enforces
it before the controller runs. Reflection on every request costs a little; frameworks that use attributes for routing
(Symfony) scan once and cache the result, which is one of the jobs a framework's build or cache step does.

## Controllers and the dispatcher

A **controller** is the thin layer between HTTP and the application: it reads what it needs from the request, calls
the domain or a service, and turns the result into a response. It contains no SQL, no business rules, and no HTML
(templates have that). Bookshelf's controllers are classes with one method per route, and they receive their
collaborators through the constructor.

The **dispatcher** is the handler at the center of the application. It asks the router for a match, checks the
route's access level, gets the controller from the container, and calls the method with the right arguments:

```php
public function handle(Request $request): Response
{
    $match = $this->router->match($request->method, $request->path);
    $member = $request->attribute('member');

    // Deny by default: the route declares who may use it, and nothing below runs for anyone else.
    if ($match->access !== Access::Public && !$member instanceof Member) {
        return Response::redirect('/login?' . http_build_query(['next' => $request->target()]));
    }
    if ($match->access === Access::Staff && !$member->isStaff()) {
        throw HttpException::forbidden('This page is for library staff only.');
    }

    [$class, $method] = $match->handler;
    $controller = $this->container->get($class);
    $arguments = $this->arguments(new ReflectionMethod($controller, $method), $request, $match->parameters);

    return $controller->$method(...$arguments);
}
```

The arguments come from the method's signature, so each controller method declares exactly what it needs:

```php
private function arguments(ReflectionMethod $method, Request $request, array $parameters): array
{
    $arguments = [];
    foreach ($method->getParameters() as $parameter) {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $name = $parameter->getName();

        $arguments[] = match (true) {
            $typeName === Request::class => $request,
            $typeName === Member::class => $request->attribute('member'),
            isset($parameters[$name]) && $typeName === 'int' => (int) $parameters[$name],
            isset($parameters[$name]) => $parameters[$name],
            $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
            default => throw new LogicException("Cannot supply \$$name for {$method->class}::{$method->name}()"),
        };
    }

    return $arguments;
}
```

`show(int $id)` gets the `{id}` path parameter as an int (the `\d+` in the route already guarantees digits);
`index(Request $request)` gets the request; `LoanController::mine(Member $member)` gets the logged-in member, which is
never `null` there because the route is `Access::Member`. Frameworks call this **argument resolution**, and theirs
can also convert an `{id}` into a loaded entity, or a JSON body into a validated object.

## Middleware

Some work applies to every request, or to most: error handling, sessions, authentication, CSRF checks, security
headers, logging, caching, CORS. Putting it in each controller repeats it; putting it in the front controller turns
that file back into what we just took apart. **Middleware** is the standard answer: each concern is a layer around
the application, and a request passes through every layer on the way in, and the response passes back through each
on the way out.

```text
          request ──▶ ┌─ SecurityHeaders ────────────────────────────────────────────┐
                      │   ┌─ ErrorHandler ─────────────────────────────────────────┐ │
                      │   │   ┌─ Authentication ──────────────────────────────────┐ │ │
                      │   │   │   ┌─ Csrf ──────────────────────────────────────┐ │ │ │
                      │   │   │   │                Dispatcher                   │ │ │ │
                      │   │   │   │       (router ─▶ controller ─▶ view)        │ │ │ │
                      │   │   │   └─────────────────────────────────────────────┘ │ │ │
                      │   │   └───────────────────────────────────────────────────┘ │ │
                      │   └─────────────────────────────────────────────────────────┘ │
         response ◀── └─────────────────────────────────────────────────────────────┘ ◀── adds the headers
```

Two small interfaces define it. A **handler** turns a request into a response; a **middleware** receives the request
and "the rest of the application" as a handler, which it may call or not:

```php
interface RequestHandler
{
    public function handle(Request $request): Response;
}

interface Middleware
{
    public function process(Request $request, RequestHandler $next): Response;
}
```

A middleware can do four things: change the request before passing it on (authentication adds the member), answer
without calling `$next` at all (CSRF rejects a forged POST), change the response that comes back (security headers),
or catch what goes wrong below it (the error handler). The **pipeline** connects a list of middleware to the final
handler:

```php
final readonly class Pipeline implements RequestHandler
{
    /** @param list<Middleware> $middleware */
    public function __construct(
        private array $middleware,
        private RequestHandler $handler,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->middleware === []) {
            return $this->handler->handle($request);
        }

        // The first middleware gets "the rest of the pipeline" as its $next.
        return $this->middleware[0]->process($request, new self(array_slice($this->middleware, 1), $this->handler));
    }
}
```

The order of the list is the order of the layers, outermost first, and it matters. Here is the flow made visible with
three throwaway middleware written as anonymous classes:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Http\Middleware;
use Bookshelf\Http\Pipeline;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;

function tracer(string $name): Middleware
{
    return new class ($name) implements Middleware {
        public function __construct(private string $name)
        {
        }

        public function process(Request $request, RequestHandler $next): Response
        {
            echo "{$this->name}: before\n";
            $response = $next->handle($request);
            echo "{$this->name}: after\n";

            return $response->withHeader("X-{$this->name}", 'seen');
        }
    };
}

$blockAdmin = new class implements Middleware {
    public function process(Request $request, RequestHandler $next): Response
    {
        return str_starts_with($request->path, '/admin') ? new Response('No', 403) : $next->handle($request);
    }
};

$controller = new class implements RequestHandler {
    public function handle(Request $request): Response
    {
        echo "  controller runs for {$request->path}\n";

        return new Response('Hello');
    }
};

$app = new Pipeline([tracer('Outer'), tracer('Inner'), $blockAdmin], $controller);

$response = $app->handle(new Request('GET', '/books'));
echo $response->status, ' ', json_encode($response->headers), "\n\n";

$response = $app->handle(new Request('GET', '/admin'));
echo $response->status, ' ', json_encode($response->headers), "\n";
```

```text
Outer: before
Inner: before
  controller runs for /books
Inner: after
Outer: after
200 {"X-Inner":"seen","X-Outer":"seen"}

Outer: before
Inner: before
Inner: after
Outer: after
403 {"X-Inner":"seen","X-Outer":"seen"}
```

The request goes in through Outer and Inner; the response comes back out through Inner and then Outer. When
`$blockAdmin` answers by itself, the controller never runs, but the outer layers still see (and decorate) the response.
That is why Bookshelf's order is what it is:

| Position | Middleware | Why there |
|---|---|---|
| 1 | `SecurityHeadersMiddleware` | Outermost, so that every response gets the headers, error pages included |
| 2 | `ErrorHandlerMiddleware` | Catches exceptions from everything inside it, and renders an error page |
| 3 | `AuthenticationMiddleware` | Adds the member to the request, so the layout shows it even on a 403 or 404 page |
| 4 | `CsrfMiddleware` | Rejects forged POSTs before any controller runs |
| 5 | `Dispatcher` (the handler) | Routes and calls the controller |

### Bookshelf's middleware

The CSRF check from chapter 18 becomes a middleware, which now also accepts the token in an `X-CSRF-Token` header for
JavaScript requests:

```php
final readonly class CsrfMiddleware implements Middleware
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private Session $session,
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        if (!in_array($request->method, self::SAFE_METHODS, true)
            && !Csrf::isValid($this->session->get('csrf'), $request->body['_csrf'] ?? $request->header('X-CSRF-Token'))) {
            throw HttpException::forbidden('This form has expired. Please go back, reload the page and try again.');
        }

        return $next->handle($request);
    }
}
```

`AuthenticationMiddleware` looks up the logged-in member (with chapter 18's idle timeout) and passes it on as a request
attribute; it never blocks anything itself. Blocking is the dispatcher's job, based on each route's access level. The
session itself is wrapped in a small `Session` class (`get()`, `set()`, `regenerate()`, `reset()`, `flash()`,
`csrfToken()`), which still starts PHP's native session only when a value is stored or the browser already has a session
cookie.

### Error handling in one place

Everything the application doesn't handle ends up in the error middleware:

```php
public function process(Request $request, RequestHandler $next): Response
{
    try {
        return $next->handle($request);
    } catch (HttpException $e) {
        $response = $this->error($request, $e->status, $e->getMessage());
        foreach ($e->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    } catch (Throwable $e) {
        error_log(sprintf('%s %s: %s', $request->method, $request->path, $e));   // message and stack trace

        return $this->error($request, 500, $this->debug
            ? $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            : 'Something went wrong on our side. Please try again later.');
    }
}
```

- **`HttpException`** carries a status: controllers throw `new HttpException(404, "There is no book with id $id.")`
  or `HttpException::forbidden(...)`, and the middleware shows the message with that status. These are expected
  situations, so they are not logged as errors.
- **Any other `Throwable`** is a bug or an outage. It is **logged in full** (casting an exception to a string gives
  the class, message, file, line and stack trace) and shown to the visitor as a generic 500. With
  `BOOKSHELF_DEBUG=1`, the page shows the exception's message instead, for development; never in production
  (chapter 18).
- `error()` renders an HTML page, or JSON (`{"error": "..."}`) for paths under `/api/`, and falls back to plain text if
  rendering the error page fails too, so an error page can never cause a second, unhandled error.

PHP's own error handlers still matter for what happens outside the pipeline (a parse error in a file, a fatal error
from memory exhaustion); chapter 22 configures logging for those.

## Dependency injection

### The problem

A controller needs a book repository, which needs a PDO connection, which needs a file path from the environment.
Where should each object come from? Two common answers cause most of the pain in older PHP code:

```php
// 1. Building dependencies inside the class: every LoanController talks to the real database,
//    and changing how PDO is configured means finding every "new PDO".
final class LoanController
{
    public function mine(): Response
    {
        $loans = new PdoLoanRepository(new PDO('sqlite:/srv/bookshelf.sqlite'), new DateTimeZone('Europe/Berlin'));
        // ...
    }
}

// 2. Reaching for a global or a static "registry": the dependency is hidden, and tests must reset global state.
$loans = App::get('loans');
```

**Dependency injection** is the alternative: a class *declares* what it needs in its constructor and receives it from
outside. It doesn't know or care how its collaborators are built:

```php
final readonly class LoanController
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private Clock $clock,
        private View $view,
    ) {
    }
}
```

The benefits are practical: the dependencies are visible in one place; the class can depend on interfaces
(`LoanRepository`, `Clock`), so a test passes an in-memory repository and a frozen clock (chapter 20); and swapping
SQLite for PostgreSQL changes one line of wiring rather than every class. Bookshelf's domain and services have been
written this way since chapter 9; what changes now is who does the wiring.

### A container

Wiring by hand works, and for small applications it's fine (`bin/bookshelf` did it until now). With dozens of
services it becomes a long, order-sensitive list. A **dependency-injection container** builds objects on demand: you
ask it for a class, and it builds the class's dependencies first, recursively. Bookshelf's container is about 90
lines:

```php
final class Container
{
    /** @var array<string, Closure(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, true> ids being built right now, to detect circular dependencies */
    private array $building = [];

    /** @param Closure(self): object $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : object)
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->building[$id])) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', array_keys($this->building)) . " -> $id");
        }

        $this->building[$id] = true;
        try {
            $service = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->autowire($id);
        } finally {
            unset($this->building[$id]);
        }

        return $this->instances[$id] = $service;
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new ContainerException("No factory for '$class', and it is not a class that can be built automatically");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("$class is an interface or abstract class; register a factory for it");
        }

        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new ContainerException(
                    "Cannot autowire \${$parameter->getName()} of $class::__construct(); register a factory for $class",
                );
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
```

- **Factories** (`set()`) say how to build things the container can't guess: which class an interface means, and
  objects that need settings (a file path, a time zone).
- **Autowiring** handles everything else: for a class without a factory, the container reads the constructor's
  parameter types with reflection and gets each one from itself. Parameters without a class type must have a default.
- **One shared instance** per id: `get(View::class)` returns the same `View` every time during a request, which is what
  you want for connections, repositories and services. (Exercise 3 adds fresh instances on demand.)
- **Circular dependencies** (A needs B, B needs A) would recurse forever; `$building` detects them and reports the
  chain instead.
- The `@template` docblock tells static analysers (chapter 20) that `get(View::class)` returns a `View`, so your editor
  and PHPStan know the type of `$container->get(View::class)->page(...)`.

In use:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Container\Container;
use Bookshelf\Container\ContainerException;

interface Greeter
{
    public function greet(string $name): string;
}

final readonly class FriendlyGreeter implements Greeter
{
    public function greet(string $name): string
    {
        return "Hello, $name!";
    }
}

final readonly class WelcomePage
{
    public function __construct(public Greeter $greeter, public string $title = 'Welcome')
    {
    }
}

final class Chicken { public function __construct(public Egg $egg) {} }
final class Egg { public function __construct(public Chicken $chicken) {} }

$container = new Container();
$container->set(Greeter::class, fn (Container $c) => new FriendlyGreeter());

$page = $container->get(WelcomePage::class);              // autowired: Greeter comes from the factory
echo $page->title, ': ', $page->greeter->greet('Ada'), "\n";
var_dump($page === $container->get(WelcomePage::class));   // shared

foreach ([Egg::class, 'Countable', 'NoSuchClass'] as $id) {
    try {
        $container->get($id);
    } catch (ContainerException $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
Welcome: Hello, Ada!
bool(true)
Circular dependency: Egg -> Chicken -> Egg
Countable is an interface or abstract class; register a factory for it
No factory for 'NoSuchClass', and it is not a class that can be built automatically
```

### Bookshelf's wiring

All of Bookshelf's wiring now lives in `config/container.php`, which the web front controller and the CLI both
`require`:

```php
$root = dirname(__DIR__);
$settings = [
    'debug' => getenv('BOOKSHELF_DEBUG') === '1',
    'timezone' => getenv('BOOKSHELF_TIMEZONE') ?: 'Europe/Berlin',
    'https' => ($_SERVER['HTTPS'] ?? 'off') !== 'off',
];

$container = new Container();

// Settings and infrastructure
$container->set(Database::class, fn () => Database::fromEnvironment($root));
$container->set(PDO::class, fn (Container $c) => $c->get(Database::class)->pdo);
$container->set(DateTimeZone::class, fn () => new DateTimeZone($settings['timezone']));   // the library's time zone
$container->set(Clock::class, fn (Container $c) => new SystemClock($c->get(DateTimeZone::class)));
$container->set(Migrator::class, fn (Container $c) => new Migrator($c->get(Database::class), "$root/migrations"));

// Interfaces and the implementations they mean
$container->set(BookRepository::class, fn (Container $c) => $c->get(PdoBookRepository::class));
$container->set(MemberRepository::class, fn (Container $c) => $c->get(PdoMemberRepository::class));
$container->set(LoanRepository::class, fn (Container $c) => $c->get(PdoLoanRepository::class));

// The web application
$container->set(Session::class, fn () => new Session(secureCookie: $settings['https']));
$container->set(View::class, fn (Container $c) => new View("$root/templates", $c->get(Session::class)));

$container->set(Router::class, function (): Router {
    $router = new Router();
    $router->addController(Controller\CatalogController::class);
    $router->addController(Controller\SuggestionController::class);
    $router->addController(Controller\AuthController::class);
    $router->addController(Controller\LoanController::class);

    return $router;
});

$container->set(Kernel::class, fn (Container $c) => new Kernel(
    [
        new Middleware\SecurityHeadersMiddleware($settings['https']),
        new Middleware\ErrorHandlerMiddleware($c->get(View::class), $settings['debug']),
        $c->get(Middleware\AuthenticationMiddleware::class),
        $c->get(Middleware\CsrfMiddleware::class),
    ],
    new Dispatcher($c->get(Router::class), $c),
));

return $container;
```

Everything not listed is autowired: the controllers, `PdoBookRepository` (it needs `PDO`), `PdoLoanRepository` (`PDO`
and `DateTimeZone`), `Authenticator`, `LoginThrottle`, `LendingService`, the middleware, and the CLI commands. Services
are built lazily: a request for the catalog never builds the `Authenticator`, and a CLI command never builds the
`Router`.

The CLI shrinks to a list of command classes:

```php
$container = require dirname(__DIR__) . '/config/container.php';

$commands = [
    Command\MigrateCommand::class,
    Command\ImportBooksCommand::class,
    Command\AddMemberCommand::class,
    Command\SetPasswordCommand::class,
    Command\IsbnCommand::class,
];

$app = new Application('bookshelf', ...array_map($container->get(...), $commands));

exit($app->run($argv, new Output()));
```

### Using a container well

- **Only the edges talk to the container.** The front controller, the CLI script and the dispatcher (which must build
  whichever controller a route names) call `get()`. Nothing else receives the container: a class that takes the
  container and pulls its own dependencies out of it (the **service locator** pattern) hides what it needs again,
  which is the problem dependency injection solved.
- **Constructor injection, not setters.** An object should be complete and valid once constructed, which `readonly`
  properties enforce.
- **Configuration is data.** Settings come from the environment into one `$settings` array, and services receive the
  values they need (`new Session(secureCookie: ...)`), not the whole configuration.
- **Shared nothing still applies.** The container, and every service in it, is built for one request and thrown away
  at its end (chapter 16), so a service holding per-request state (like `View`'s current member) is safe here. In
  long-running worker modes (FrankenPHP workers, RoadRunner, Swoole), the same container serves many requests, and such
  state must be reset between them.

## Templates and the view

The template functions of chapter 16 become a `View` class. `render()` runs one template and returns its HTML;
`page()` renders a template inside the layout and returns a `Response`:

```php
/** @param array<string, mixed> $variables */
public function page(string $title, string $template, array $variables = [], int $status = 200): Response
{
    $content = $this->render($template, $variables);

    return Response::html($this->render('layout', [
        'title' => $title,
        'member' => $this->member,
        'flash' => $this->session->takeFlash(),
        'content' => $content,
    ]), $status);
}

public function render(string $template, array $variables = []): string
{
    $file = "{$this->directory}/$template.php";
    if (!is_file($file)) {
        throw new RuntimeException("No template $template");
    }
    $variables['csrfField'] = fn (): string
        => '<input type="hidden" name="_csrf" value="' . e($this->session->csrfToken()) . "\">\n";

    return (static function (string $__file, array $__variables): string {
        extract($__variables);
        ob_start();
        try {
            require $__file;

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    })($file, $variables);
}
```

- The template runs inside a **static closure**, so it sees only the variables it was given: no `$this` (the view's
  internals), no `$template` or `$file` from the method. The double-underscore names make collisions with template
  variables unlikely.
- `$csrfField` is a closure the templates call, `<?= $csrfField() ?>`, so a session starts only for pages that
  actually contain a form.
- `e()` stays a plain function, because templates use it on every line. It lives in `src/Web/helpers.php`, which
  Composer loads on every request through the `files` section of the autoload configuration (chapter 12):

```json
"autoload": {
    "psr-4": {
        "Bookshelf\\": "src/"
    },
    "files": [
        "src/Web/helpers.php"
    ]
}
```

After changing `autoload`, run `composer dump-autoload` so Composer regenerates `vendor/autoload.php`.

Plain PHP templates are fast and need nothing to install, but they make escaping opt-in. Template engines make it
opt-out, and add inheritance, includes and sandboxing:

> **Just a taste — you don't need to learn or fully understand this now.** The book page in Twig, the template engine
> Symfony uses; `{{ }}` escapes automatically. Chapter 23 shows where it fits.

```twig
{% extends 'layout.html.twig' %}
{% block content %}
  <dl class="details">
    <dt>Author</dt><dd>{{ book.author }}</dd>
    <dt>Available</dt><dd>{{ book.available }} of {{ book.copies }}</dd>
  </dl>
{% endblock %}
```

## The PSR standards

The **PHP-FIG** (Framework Interoperability Group) publishes **PSRs**, PHP Standard Recommendations: shared interfaces
that let libraries from different authors work together. You've met PSR-4 (autoloading, chapter 12) and PSR-20
(`ClockInterface`, the shape of Bookshelf's `Clock`). The ones behind this chapter:

| PSR | Defines | Bookshelf's counterpart |
|---|---|---|
| PSR-7 | HTTP messages: `ServerRequestInterface`, `ResponseInterface`, `UriInterface`, `StreamInterface`; immutable, with `with...()` methods | `Request`, `Response` |
| PSR-17 | Factories for PSR-7 objects, so code can create a response without depending on one implementation | `Response::html()` and friends |
| PSR-15 | `RequestHandlerInterface::handle()` and `MiddlewareInterface::process()` | `RequestHandler`, `Middleware` (same method names) |
| PSR-11 | `ContainerInterface` with `get()` and `has()` | `Container` |
| PSR-3 | `LoggerInterface` (`error()`, `warning()`, `info()`, ...) | `error_log()` in the error middleware |
| PSR-14 | Event dispatching | (none) |
| PSR-18 | HTTP clients (`ClientInterface::sendRequest()`) | (none) |

Bookshelf's classes are simplified versions: PSR-7 bodies are streams (so a 2 GB download doesn't have to fit in a
string), headers have several values each, and the URI is an object. Code written against the PSR interfaces works
with any implementation: a PSR-15 middleware from one package runs in any PSR-15 application.

> **Just a taste — you don't need to learn or fully understand this now.** The security-headers middleware written
> against the PSR interfaces, for example with the `nyholm/psr7` and `psr/http-server-middleware` packages.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeaders implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
```

Assembling a "framework" from PSR packages is a realistic option: `nyholm/psr7` or `laminas/laminas-diactoros` for
messages, `nikic/fast-route` or `league/route` for routing, `php-di/php-di` or `league/container` for the container,
`twig/twig` for templates, `monolog/monolog` for logging. Micro-frameworks such as Slim (chapter 23) are exactly that,
pre-assembled.

## Bookshelf: the new structure

`public/index.php` is now 20 lines, and everything it used to contain has a place:

```text
examples/bookshelf/
  config/container.php                 all the wiring, for the web and the CLI
  public/index.php                     the front controller: fromGlobals() → Kernel → send()
  src/Container/                       Container, ContainerException
  src/Http/                            Request, Response, HttpException, Router, Route, RouteMatch, Access,
                                       RequestHandler, Middleware, Pipeline
  src/Web/
    Kernel.php                         the middleware stack around the dispatcher
    Dispatcher.php                     routing, access checks, argument resolution
    Session.php, View.php, helpers.php
    Middleware/                        SecurityHeaders, ErrorHandler, Authentication, Csrf
    Controller/                        Catalog, Suggestion, Auth, Loan
  src/Persistence/SuggestionStore.php  the suggestions table
  templates/book.php                   new: a page per book, at /books/{id}
  bin/bookshelf                        the CLI, wired by the same container
```

New on the way: a page for each book (`/books/7`, linked from the catalog), `GET /api/books/{id}`, proper `404` and
`405` responses with JSON for the API, and a generic 500 page. The visible behavior otherwise stays the same as in
chapter 18, which is the point of a refactoring. The other controllers are short:

```php
final readonly class LoanController
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private Clock $clock,
        private View $view,
    ) {
    }

    /** The logged-in member's own loans: whose loans comes from the session, never from the URL. */
    #[Route('GET', '/loans', Access::Member)]
    public function mine(Member $member): Response
    {
        $loans = $this->loans->openLoansOf($member->id);

        return $this->view->page('My loans', 'loans', [
            'loans' => $loans,
            'books' => $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
            'today' => $this->clock->now()->setTime(0, 0),
        ]);
    }
}
```

Run it as before (`composer serve` from `examples/bookshelf`) and try the new responses:

```bash
curl -s http://localhost:8000/api/books/2
curl -s -i http://localhost:8000/api/books/99
curl -s -i http://localhost:8000/books/abc
curl -s -i http://localhost:8000/logout
curl -s -i http://localhost:8000/suggestions
```

```text
{"data":{"id":2,"title":"Kindred","author":"Octavia E. Butler","isbn":"9780807083697","genre":"sf","year":1979,"available":1}}

HTTP/1.1 404 Not Found
Content-Type: application/json; charset=utf-8
...
{"error":"There is no book with id 99."}

HTTP/1.1 404 Not Found
Content-Type: text/html; charset=utf-8
...
    <p>There is no page at this address.</p>

HTTP/1.1 405 Method Not Allowed
Allow: POST
...

HTTP/1.1 303 See Other
Location: /login?next=%2Fsuggestions
```

And with a database that was never migrated (`BOOKSHELF_DATABASE=/tmp/empty.sqlite composer serve`), the catalog
answers `500` with "Something went wrong on our side. Please try again later.", while the server's log gets the
details:

```text
GET /: PDOException: SQLSTATE[HY000]: General error: 1 no such table: books in .../src/Persistence/PdoBookRepository.php:63
Stack trace:
#0 .../src/Persistence/PdoBookRepository.php(63): PDO->prepare('SELECT id, titl...')
...
```

With `BOOKSHELF_DEBUG=1`, the page itself shows that first line.

## Key takeaways

- Read the superglobals in one place (`Request::fromGlobals()`), produce output in one place (`Response::send()`), and
  build objects in one place (the container's configuration). Everything between is testable plain objects.
- Requests and responses are immutable values; `with...()` methods return modified copies (PHP 8.5's
  `clone($object, [...])` makes them one-liners, even with `readonly`).
- A router compiles path patterns to regular expressions, distinguishes 404 from 405, and can read routes from
  attributes. Restrict parameters (`{id:\d+}`) so invalid values never reach a controller.
- Controllers are thin: read the request, call the domain, return a response. A dispatcher resolves their arguments
  from the route, the request and the session.
- Middleware wraps the application in layers; order matters. Put error handling, security headers, authentication and
  CSRF checks there, and authorization next to the routes.
- Inject dependencies through constructors. A container with autowiring removes the wiring boilerplate; only the
  edges of the application call it.
- PSR-7, -15, -11 and -3 are the standard shapes of these parts, and the reason libraries and frameworks interoperate.

## Exercises

1. **URL generation.** Give routes optional names (`#[Route('GET', '/books/{id:\d+}', name: 'book')]`) and add
   `Router::url(string $name, array $parameters = []): string`, which builds the path, URL-encodes each parameter with
   `rawurlencode()`, checks it against the parameter's pattern, and throws for unknown names or missing parameters.
   Write it as a standalone class to test the idea.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final class UrlGenerator
{
    /** @var array<string, string> route name => path pattern */
    private array $paths = [];

    public function add(string $name, string $path): void
    {
        $this->paths[$name] = $path;
    }

    /** @param array<string, string|int> $parameters */
    public function url(string $name, array $parameters = []): string
    {
        $path = $this->paths[$name] ?? throw new InvalidArgumentException("No route named '$name'");

        return preg_replace_callback('~\{([a-z_][a-z0-9_]*)(?::([^{}]+))?\}~i', function (array $m) use ($name, $parameters): string {
            $value = (string) ($parameters[$m[1]] ?? throw new InvalidArgumentException("Route '$name' needs '{$m[1]}'"));
            if (preg_match('~^(?:' . ($m[2] ?? '[^/]+') . ')$~', $value) !== 1) {
                throw new InvalidArgumentException("'$value' doesn't match {$m[0]} in route '$name'");
            }

            return rawurlencode($value);
        }, $path);
    }
}

$urls = new UrlGenerator();
$urls->add('book', '/books/{id:\d+}');
$urls->add('member', '/members/{email}');

echo $urls->url('book', ['id' => 7]), "\n";
echo $urls->url('member', ['email' => 'ada lovelace@example.org']), "\n";

foreach ([['book', ['id' => 'dune']], ['book', []], ['shelf', []]] as [$name, $parameters]) {
    try {
        $urls->url($name, $parameters);
    } catch (InvalidArgumentException $e) {
        echo $e->getMessage(), "\n";
    }
}
```

```text
/books/7
/members/ada%20lovelace%40example.org
'dune' doesn't match {id:\d+} in route 'book'
Route 'book' needs 'id'
No route named 'shelf'
```

Templates then write `<a href="<?= e($urls->url('book', ['id' => $book->id])) ?>">`, and renaming a path means editing
one route, not every template that links to it. Note `(?: ... )` around the parameter's pattern: without the group,
a pattern with `|` such as `new|used` would bind wrongly to the `^` and `$` anchors.

</details>

2. **Maintenance mode and timing.** Write two middleware: `MaintenanceMiddleware`, which answers `503 Service
   Unavailable` with a `Retry-After: 300` header while a given file exists (except for staff, when the request has a
   member attribute who is staff); and `TimingMiddleware`, which adds a `Server-Timing: app;dur=12.3` header with the
   milliseconds the rest of the pipeline took. Decide where each goes in Bookshelf's list.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\Member;
use Bookshelf\Http\Middleware;
use Bookshelf\Http\Pipeline;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;

final readonly class MaintenanceMiddleware implements Middleware
{
    public function __construct(private string $flagFile)
    {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $member = $request->attribute('member');
        if (is_file($this->flagFile) && !($member instanceof Member && $member->isStaff())) {
            return new Response('Bookshelf is being updated. Please try again in a few minutes.', 503, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Retry-After' => '300',
            ]);
        }

        return $next->handle($request);
    }
}

final readonly class TimingMiddleware implements Middleware
{
    public function process(Request $request, RequestHandler $next): Response
    {
        $start = hrtime(true);
        $response = $next->handle($request);
        $milliseconds = (hrtime(true) - $start) / 1e6;

        return $response->withHeader('Server-Timing', sprintf('app;dur=%.1f', $milliseconds));
    }
}

$slowPage = new class implements RequestHandler {
    public function handle(Request $request): Response
    {
        usleep(20_000);

        return new Response('ok');
    }
};

$flag = sys_get_temp_dir() . '/bookshelf.maintenance';
$app = new Pipeline([new TimingMiddleware(), new MaintenanceMiddleware($flag)], $slowPage);

@unlink($flag);
$response = $app->handle(new Request('GET', '/'));
echo $response->status, ' ', $response->header('Server-Timing'), "\n";

touch($flag);
$response = $app->handle(new Request('GET', '/'));
echo $response->status, ' Retry-After: ', $response->header('Retry-After'), "\n";
unlink($flag);
```

```text
200 app;dur=20.1
503 Retry-After: 300
```

(The duration varies.) `TimingMiddleware` goes first, outermost, to measure everything. `MaintenanceMiddleware` must
come **after** `AuthenticationMiddleware`, since it reads the member attribute, but before the dispatcher, so that no
controller (and no database write) runs during maintenance. `hrtime()` is a monotonic clock in nanoseconds, the right
tool for measuring durations (`microtime()` jumps when the system clock is adjusted). The `Server-Timing` header shows
up in the browser's developer tools, next to the request.

</details>

3. **Fresh instances.** Add `make(string $class, array $arguments = []): object` to a copy of the container: it always
   builds a new instance (never shared), autowiring the constructor but taking parameters named in `$arguments` from
   there. Use it to build two `Greeting` objects with different `$name`s that share one `Clock`-like dependency.

<details>
<summary>Solution</summary>

The method, added to `Container`, reuses the autowiring loop with one more source of values:

```php
/**
 * A new instance of $class every time, with $arguments for parameters of those names.
 *
 * @template T of object
 * @param class-string<T> $class
 * @param array<string, mixed> $arguments
 * @return T
 */
public function make(string $class, array $arguments = []): object
{
    $reflection = new ReflectionClass($class);
    $values = [];
    foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        $values[] = match (true) {
            array_key_exists($parameter->getName(), $arguments) => $arguments[$parameter->getName()],
            $type instanceof ReflectionNamedType && !$type->isBuiltin() => $this->get($type->getName()),
            $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
            default => throw new ContainerException("make($class) needs a value for \${$parameter->getName()}"),
        };
    }

    return $reflection->newInstanceArgs($values);
}
```

Tested on a minimal container with just `get()` and `make()`:

```php
<?php

declare(strict_types=1);

final class MiniContainer
{
    private array $instances = [];

    public function get(string $class): object
    {
        return $this->instances[$class] ??= $this->make($class);
    }

    public function make(string $class, array $arguments = []): object
    {
        $reflection = new ReflectionClass($class);
        $values = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $values[] = match (true) {
                array_key_exists($parameter->getName(), $arguments) => $arguments[$parameter->getName()],
                $type instanceof ReflectionNamedType && !$type->isBuiltin() => $this->get($type->getName()),
                $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                default => throw new LogicException("make($class) needs a value for \${$parameter->getName()}"),
            };
        }

        return $reflection->newInstanceArgs($values);
    }
}

final class Counter
{
    public int $calls = 0;
}

final readonly class Greeting
{
    public function __construct(public Counter $counter, public string $name)
    {
    }

    public function __toString(): string
    {
        return 'Hello, ' . $this->name . ' (greeting #' . ++$this->counter->calls . ')';
    }
}

$container = new MiniContainer();
$ada = $container->make(Greeting::class, ['name' => 'Ada']);
$alan = $container->make(Greeting::class, ['name' => 'Alan']);

echo $ada, "\n", $alan, "\n";
var_dump($ada === $alan, $ada->counter === $alan->counter);

try {
    $container->make(Greeting::class);
} catch (LogicException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
Hello, Ada (greeting #1)
Hello, Alan (greeting #2)
bool(false)
bool(true)
make(Greeting) needs a value for $name
```

`make()` is for objects that carry per-use data (a value plus services), while `get()` stays for services. Containers
call these "transient" or "prototype" services, versus shared "singletons". (The `readonly` class can still increment
`$this->counter->calls`: `readonly` stops reassigning the `$counter` property, not changes inside the object it
points to.)

</details>

4. **An API endpoint for the member.** Add `GET /api/me`, which returns the logged-in member as JSON (`id`, `name`,
   `email`, `type`, open loan count). An API client cannot follow a redirect to a login form, so change the
   dispatcher to answer `401 Unauthorized` with JSON for `/api/` paths that require a login.

<details>
<summary>Solution</summary>

A new controller method (in `LoanController`, or a new `MemberController` registered in `config/container.php`):

```php
#[Route('GET', '/api/me', Access::Member)]
public function me(Member $member): Response
{
    return Response::json(['data' => [
        'id' => $member->id,
        'name' => $member->name,
        'email' => $member->email,
        'type' => $member->type,
        'openLoans' => count($this->loans->openLoansOf($member->id)),
    ]]);
}
```

And in `Dispatcher::handle()`, the not-logged-in branch distinguishes API paths:

```php
if ($match->access !== Access::Public && !$member instanceof Member) {
    if (str_starts_with($request->path, '/api/')) {
        return Response::json(['error' => 'Log in first.'], 401)->withHeader('WWW-Authenticate', 'Session');
    }

    return Response::redirect('/login?' . http_build_query(['next' => $request->target()]));
}
```

```bash
curl -s -i http://localhost:8000/api/me             # without the session cookie
curl -s -b jar http://localhost:8000/api/me         # with Ada's session
```

```text
HTTP/1.1 401 Unauthorized
Content-Type: application/json; charset=utf-8
WWW-Authenticate: Session
...
{"error":"Log in first."}

{"data":{"id":1,"name":"Ada Lovelace","email":"ada@example.org","type":"premium","openLoans":2}}
```

`401` means "who are you?" and `403` means "I know who you are, and no". HTTP requires a `WWW-Authenticate` header with
401, naming the authentication scheme; there's no standard scheme for cookie sessions, so applications pick a name.
The member's `type` enum serializes as its value.

</details>

5. **Testing without a server.** Write a script that builds the application from `config/container.php` with
   `BOOKSHELF_DATABASE` set to `:memory:`, runs the migrations, saves a book through the repository, and then calls the
   kernel directly with hand-built requests: `GET /api/books/1`, `GET /books/2`, and a `POST /suggest` without a token.
   Print each response's status and the start of its body.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bookshelf\Domain\Book;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Bookshelf\Http\Request;
use Bookshelf\Persistence\Migrator;
use Bookshelf\Web\Kernel;

putenv('BOOKSHELF_DATABASE=:memory:');
$container = require __DIR__ . '/config/container.php';

$container->get(Migrator::class)->migrate();
$container->get(BookRepository::class)
    ->save(new Book(null, 'Dune', 'Frank Herbert', new Isbn('978-0-441-01359-3'), Genre::ScienceFiction, 1965, 2));

$kernel = $container->get(Kernel::class);
$requests = [
    new Request('GET', '/api/books/1'),
    new Request('GET', '/books/2'),
    new Request('POST', '/suggest', body: ['title' => 'Emma', 'author' => 'Jane Austen']),
];

foreach ($requests as $request) {
    $response = $kernel->handle($request);
    // For HTML pages, the <h1> is enough to see which page came back.
    $summary = preg_match('~<h1>(.*?)</h1>~', $response->body, $m) === 1 ? "page \"$m[1]\"" : $response->body;
    printf("%-4s %-13s -> %d %s\n", $request->method, $request->path, $response->status, $summary);
}
```

```text
GET  /api/books/1  -> 200 {"data":{"id":1,"title":"Dune","author":"Frank Herbert","isbn":"9780441013593","genre":"sf","year":1965,"available":2}}
GET  /books/2      -> 404 page "Not found"
POST /suggest      -> 403 page "Forbidden"
```

Because nothing reads globals or prints, the whole application runs as a function from `Request` to `Response`, with
a throwaway database. The container is shared for all three requests here, which a real test avoids by building a
fresh one per test (chapter 20 does exactly that with PHPUnit). One thing still reaches outside: the `Session` class
uses PHP's native session functions, which is why the CSRF failure works here (there's no session, so no token) but a
test of a logged-in page would need a session too. Wrapping the session behind an interface with an in-memory
implementation for tests is the usual fix.

</details>

---
← Previous: [Security](18-security.md) | Next: [Testing and static analysis](20-testing-and-static-analysis.md) →
