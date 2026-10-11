# Chapter 16 — PHP on the web

## What you'll learn

- How a web server runs PHP: SAPIs, PHP-FPM, and the request lifecycle
- The shared-nothing model, and what it means for your code
- Reading the request: superglobals, headers, query strings, form data and JSON bodies, and why none of it is trusted
- Writing the response: status codes, headers, redirects, output buffering, and the "headers already sent" error
- PHP as a template language, and escaping output
- Forms, validation and the Post/Redirect/Get pattern
- Cookies, sessions and file uploads, including their security settings
- URLs, and the URI extension (PHP 8.5+)
- Bookshelf's first web pages: a searchable catalog, a JSON endpoint and a suggestion form

## How PHP runs behind a web server

PHP itself does not speak HTTP to browsers. A **SAPI** (Server API) connects the PHP engine to the outside world: it
receives a request from somewhere, fills in the superglobals, runs your script, and sends back whatever the script
printed. `PHP_SAPI` tells you which one is running:

| SAPI | `PHP_SAPI` | Used for |
|---|---|---|
| Command line | `cli` | Scripts, tools, workers (chapter 14) |
| Built-in web server | `cli-server` | Local development only |
| PHP-FPM | `fpm-fcgi` | Production, behind nginx, Caddy or Apache. The standard choice |
| Apache module | `apache2handler` | Older setups that load PHP inside Apache (`mod_php`) |
| FrankenPHP | `frankenphp` | A Go web server (built on Caddy) with PHP embedded, including a worker mode |

The common production setup looks like this:

```text
                            ┌──────────────────────── PHP-FPM ────────────────────────┐
Browser ──HTTPS──▶ nginx ──FastCGI──▶ master process                                  │
            │        │      (socket)     ├── worker 1 ── runs public/index.php ...    │
            │        │                   ├── worker 2 ── runs public/index.php ...    │
            │        │                   └── worker N (a pool, e.g. 10–50 processes)  │
            │        └── serves static files (CSS, images) itself                     │
            └────────────────────────────────────────────────────────────────────────-┘
```

nginx handles the network: TLS, slow clients, static files, compression. For requests that need PHP, it forwards the
request over **FastCGI** to **PHP-FPM** (FastCGI Process Manager), which keeps a pool of PHP worker processes. Each
worker handles one request at a time, then the next. Chapter 22 configures all of this.

### The request lifecycle and "shared nothing"

When a worker process starts, PHP loads its extensions and configuration once. Then, **for every request**:

1. The SAPI builds the superglobals (`$_GET`, `$_POST`, `$_SERVER`, ...) from the HTTP request.
2. Your entry script runs from the top. It loads classes, connects to the database, handles the request.
3. Everything the script printed becomes the response body; headers set with `header()` are sent before it.
4. PHP **destroys everything** the request created: variables, objects, static properties, database connections
   (unless persistent), open files. The worker is clean for the next request.

This is the **shared-nothing** model, and it shapes PHP code more than any language feature:

- **No state survives between requests in memory.** A static property or global array set in one request is gone in
  the next one, which may run in a different worker anyway. Anything that must persist goes to a database, a cache
  (Redis, APCu), a file, a cookie or the session.
- **Each request pays the bootstrap cost again**: loading classes, reading configuration, building objects. **OPcache**
  (chapter 21) keeps the *compiled* code in shared memory, so files are not re-parsed, but your setup code still runs.
  Keep the bootstrap lean.
- **Failures are isolated.** A request that leaks memory, crashes, or corrupts a global variable affects only itself.
  This makes PHP applications unusually robust, and lets you scale by adding workers and servers without coordination.
- **Concurrency is simple.** Two requests never share variables, so there are no data races in your PHP code (only in
  shared resources like the database or files, chapter 14).

Some newer runtimes (FrankenPHP's worker mode, RoadRunner, Swoole) keep the application booted in memory and feed it
request after request, which removes the bootstrap cost but gives up some of these guarantees: static state now
*does* leak between requests. Chapter 22 discusses when that trade is worth it.

Two related functions: `ignore_user_abort(true)` keeps the script running if the visitor closes the connection, and
under PHP-FPM, `fastcgi_finish_request()` sends the response to the browser immediately and lets the script continue
working (for example, to write logs) without making the visitor wait.

### The built-in development server

PHP includes a small web server for development. From Bookshelf's directory:

```bash
php -S localhost:8000 -t public
```

`-t public` sets the **document root**, the directory whose files are reachable by URL. Requests for existing files are
served directly; anything else runs `public/index.php`. You can also pass a **router script** as the last argument,
which then runs for every request; returning `false` from it tells the server to serve the requested file itself:

```php
<?php

// public/index.php used as a router: php -S localhost:8000 -t public public/index.php
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}
```

The built-in server handles one request at a time (set the `PHP_CLI_SERVER_WORKERS` environment variable to run
several), has no TLS, and is not hardened. **Never use it in production.**

Keep the document root small. Bookshelf's `public/` contains only `index.php` and static assets; the source code,
templates, data and `vendor/` live one level up, where no URL can reach them. If the web server's document root were
the project root, a misconfiguration could serve `.env` or `composer.json` to anyone who asks.

## The request

### Superglobals

PHP parses the request into **superglobals**, arrays available in every scope:

| Variable | Contains |
|---|---|
| `$_GET` | Query string parameters (`?q=dune&page=2`), for any method |
| `$_POST` | Form fields from a `POST` body encoded as `application/x-www-form-urlencoded` or `multipart/form-data` |
| `$_FILES` | Uploaded files from a `multipart/form-data` body |
| `$_COOKIE` | Cookies the browser sent |
| `$_SERVER` | Request details (method, URI, headers) and server environment |
| `$_SESSION` | Session data, after `session_start()` |
| `$_ENV` | Environment variables, often empty (it depends on `variables_order`); use `getenv()` |
| `$_REQUEST` | `$_GET`, `$_POST` and `$_COOKIE` merged. **Avoid it**: you can't tell where a value came from |

The most useful `$_SERVER` entries:

| Key | Example |
|---|---|
| `REQUEST_METHOD` | `'GET'`, `'POST'`, `'DELETE'` |
| `REQUEST_URI` | `'/api/books?q=dune'` (path and query string, as sent) |
| `QUERY_STRING` | `'q=dune'` |
| `HTTP_HOST` | `'bookshelf.example'` (the `Host` header) |
| `HTTP_*` | Every request header: `Accept-Language` becomes `HTTP_ACCEPT_LANGUAGE` |
| `CONTENT_TYPE`, `CONTENT_LENGTH` | The body's type and size (no `HTTP_` prefix) |
| `HTTPS` | Set to a non-empty value other than `'off'` when the request came over TLS |
| `REMOTE_ADDR` | The client's IP address, or the proxy's if there is one in front |
| `SCRIPT_FILENAME` | The full path of the script that is running |

Here is a request inspector, run with a query string and a header:

```php
<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo $_SERVER['REQUEST_METHOD'], ' ', $_SERVER['REQUEST_URI'], "\n";
echo 'q: ', var_export($_GET['q'] ?? null, true), "\n";
echo 'tags: ', var_export($_GET['tags'] ?? null, true), "\n";
echo 'accept-language: ', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '(none)', "\n";
```

```bash
curl -H 'Accept-Language: de-CH' 'http://localhost:8000/inspect.php?q=dune&tags[]=sf&tags[]=classic'
```

```text
GET /inspect.php?q=dune&tags[]=sf&tags[]=classic
q: 'dune'
tags: array (
  0 => 'sf',
  1 => 'classic',
)
accept-language: de-CH
```

A parameter name ending in `[]` becomes an array, and `filter[genre]=sf` becomes `['filter' => ['genre' => 'sf']]`.
That is convenient for forms with checkboxes, and it is also a trap.

### Every input is untrusted, and may not even be a string

Values in `$_GET`, `$_POST` and `$_COOKIE` are strings **or arrays**, chosen by whoever sends the request. Anyone can
type `?q[]=x` into the address bar. Code that assumes a string then fails:

```php
<?php

declare(strict_types=1);

// Requested as /search.php?q[]=x
try {
    echo strtoupper($_GET['q'] ?? ''), "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
```

```text
TypeError: strtoupper(): Argument #1 ($string) must be of type string, array given
```

Without the `try`, that is an uncaught error and a 500 response, triggered by anyone. In some older code without
strict types, an array where a string was expected even bypassed security checks. So read parameters through small
functions that check the type, as Bookshelf does:

```php
function stringParam(array $source, string $name, int $maxLength = 200): string
{
    $value = $source[$name] ?? '';

    return is_string($value) ? mb_substr(trim($value), 0, $maxLength) : '';
}
```

For integers and other formats, `filter_var()` from chapter 7 does the job, and `filter_input()` reads straight from the
request:

```php
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 1]]);
```

`?page=3` gives `3`; a missing parameter, `?page=abc` or `?page=-5` gives the default `1`.

Also untrusted: every header (including `Host`, `Referer` and `User-Agent`), the cookies, the uploaded file names and
types, and even `REQUEST_URI`. Chapter 18 builds on this.

### JSON and other request bodies

PHP fills `$_POST` only for the two form encodings. An API client that sends JSON gets an empty `$_POST`; read the raw
body from the `php://input` stream (chapter 14) and decode it yourself:

```php
<?php

declare(strict_types=1);

if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    http_response_code(415);   // Unsupported Media Type
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    http_response_code(400);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['received' => $data], JSON_THROW_ON_ERROR);
```

```bash
curl -X POST -H 'Content-Type: application/json' -d '{"title":"Dune"}' http://localhost:8000/api.php
```

```text
{"received":{"title":"Dune"}}
```

As chapter 14 explained, a successful decode only means the body was JSON. Validate its shape before using it.

## The response

Everything a script prints is the **response body**. The status code and headers are set with functions, and they
must be set **before** the first byte of the body is sent, because HTTP sends headers first.

```php
<?php

declare(strict_types=1);

http_response_code(201);                                 // the status; 200 by default
header('Content-Type: application/json; charset=utf-8');
header('Location: /api/books/42');
header('Cache-Control: no-store');

echo json_encode(['id' => 42, 'title' => 'Dune']);
```

```text
HTTP/1.1 201 Created
Content-Type: application/json; charset=utf-8
Location: /api/books/42
Cache-Control: no-store

{"id":42,"title":"Dune"}
```

- `header()` **replaces** an earlier header with the same name, unless you pass `false` as the second argument (needed
  for headers that may repeat). `header_remove('X-Powered-By')` removes one, and `headers_list()` shows what will be
  sent.
- PHP adds `Content-Type: text/html; charset=UTF-8` if you don't set one, and an `X-Powered-By: PHP/8.5.x` header
  unless `expose_php` is off (turn it off in production; it advertises your exact version).
- `exit` after a redirect or an error response is common in small scripts. In structured applications, a function
  returns a response object instead (chapter 19).

### Status codes you will use

| Code | Meaning | Typical use |
|---|---|---|
| 200 | OK | A normal page or API result |
| 201 | Created | A `POST` that created something; send a `Location` header |
| 204 | No Content | Success with no body (a `DELETE`) |
| 301 / 308 | Moved permanently | A page's address changed for good (308 keeps the method) |
| 302 / 307 | Found / temporary redirect | Temporarily elsewhere (307 keeps the method) |
| 303 | See Other | After a form `POST`: "now `GET` this page" |
| 304 | Not Modified | The client's cached copy is still valid |
| 400 | Bad Request | Malformed input (invalid JSON, wrong parameter type) |
| 401 / 403 | Unauthorized / Forbidden | Not logged in / logged in but not allowed |
| 404 | Not Found | No such page or resource |
| 405 | Method Not Allowed | The path exists but not for this method; send an `Allow` header |
| 415 | Unsupported Media Type | The body is in a format you don't accept |
| 422 | Unprocessable Content | Well-formed input that fails validation |
| 429 | Too Many Requests | Rate limited |
| 500 / 503 | Server error / unavailable | Bugs, outages, maintenance |

### "Headers already sent"

Once any output has been sent, headers can no longer change:

```php
<?php

declare(strict_types=1);

echo "Hello";
header('Location: /elsewhere');
```

```text
Hello
Warning: Cannot modify header information - headers already sent by (output started at /srv/demo.php:5) in /srv/demo.php on line 6
```

The redirect silently does not happen. The output that "started" can be invisible: a space or blank line **before**
`<?php`, a byte-order mark saved by an editor, or a newline **after** a closing `?>` at the end of an included file.
That last one is why PHP files that contain only code **omit the closing `?>` tag**: nothing after the code can then
leak into the output. The warning's "output started at" names the file and line to look at.

**Output buffering** collects output in memory instead of sending it immediately, so headers can still be changed until
the buffer is flushed:

```php
ob_start();                  // start collecting output
echo "Hello";
header('X-Late: still works');
$html = ob_get_clean();      // take the collected output and stop buffering
echo strtoupper($html);
```

Many servers enable a 4 KB buffer through the `output_buffering` setting, which hides the problem in development and
lets it reappear on a server configured differently. Don't rely on it; structure code so that the response is decided
before it is printed. Bookshelf's `render()` function below uses `ob_start()` deliberately, to turn a template's output
into a string.

## PHP as a template language

PHP began as a template language, and it is still a decent one. Outside `<?php ?>` tags, text is output as it is;
`<?= $expr ?>` prints an expression; and the **alternative syntax** (`if:`, `endif;`, `foreach:`, `endforeach;`) reads
better between HTML tags than braces do:

```php
<?php
$books = [['title' => 'Dune', 'year' => 1965], ['title' => 'Emma', 'year' => 1815]];
$query = '<b>classics</b>';
?>
<h2>Results for <?= htmlspecialchars($query) ?></h2>
<?php if ($books === []): ?>
    <p>Nothing found.</p>
<?php else: ?>
    <ul>
        <?php foreach ($books as $book): ?>
            <li><?= htmlspecialchars($book['title']) ?> (<?= $book['year'] ?>)</li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
```

```text
<h2>Results for &lt;b&gt;classics&lt;/b&gt;</h2>
    <ul>
                    <li>Dune (1965)</li>
                    <li>Emma (1815)</li>
            </ul>
```

**Every** piece of data printed into HTML must be escaped with `htmlspecialchars()`, which turns `<`, `>`, `&`, `"` and
`'` into entities. Otherwise a book titled `<script>...</script>`, or a search for it, runs JavaScript in your visitors'
browsers: **cross-site scripting** (XSS), the most common web vulnerability. Chapter 18 covers it fully, including the
other contexts (attributes, URLs, JavaScript) that need different escaping. Since escaping is needed so often, projects
define a short helper; Bookshelf's is `e()`:

```php
function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`ENT_QUOTES` escapes both quote characters, and `ENT_SUBSTITUTE` replaces invalid UTF-8 instead of returning an empty
string. Both have been part of the default flags since PHP 8.1, but older code and other libraries may differ, so
spelling them out costs nothing. Template engines such as **Twig** and
Laravel's **Blade** escape automatically, which removes the risk of forgetting (chapter 23).

## Forms

### GET or POST

```html
<form method="get" action="/">               <!-- search: parameters end up in the URL -->
    <input type="search" name="q">
</form>

<form method="post" action="/suggest">       <!-- changes something: parameters go in the body -->
    <input name="title">
    <textarea name="reason"></textarea>
    <button>Send</button>
</form>
```

- Use **GET** for requests that only read: searches, filters, pagination. The URL can be bookmarked and shared, and
  browsers and caches may repeat GET requests freely.
- Use **POST** (or PUT, PATCH, DELETE from JavaScript) for requests that **change** something. Browsers warn before
  re-submitting a POST, and crawlers and link prefetchers do not send them.

An HTML form sends only fields that have a `name`. An **unchecked checkbox sends nothing at all**, so test with
`isset($_POST['newsletter'])` rather than looking for a value. Several checkboxes named `genres[]` arrive as an array
(and may arrive as a string if someone crafts the request, so check the type).

### Validate, re-display, then redirect

The standard flow for handling a form:

```text
GET /suggest  ──▶ show the empty form
POST /suggest ──▶ validate
                    ├── invalid ──▶ 422: show the form again, with the values typed and an error per field
                    └── valid   ──▶ save, then 303 redirect to a page that shows the result
GET /         ──▶ (browser follows the redirect) show "Thank you" once
```

The redirect after a successful POST is the **Post/Redirect/Get** pattern. Without it, the browser's address bar still
shows the POST, and pressing Reload submits the form a second time (a second suggestion, a second payment). With it,
reloading just reloads the GET. `303 See Other` is the status that means "the result is over there; fetch it with GET".

To show "Thank you" on the next page, store a one-time **flash message** in the session (below) before redirecting,
and remove it when it's displayed. Bookshelf's suggestion form, at the end of this chapter, implements the whole flow.

Every form that changes data also needs protection against **cross-site request forgery** (CSRF), where another
website makes your visitor's browser submit the form. That needs a secret token in the form, and is covered in
chapter 18; the `SameSite` cookie setting below already blocks most such attacks.

## Cookies

A **cookie** is a small named value the server asks the browser to store and send back with every later request to
the same site. Set one with `setcookie()`, using the options array:

```php
<?php

declare(strict_types=1);

setcookie('theme', 'dark', [
    'expires' => time() + 30 * 86400,   // 30 days; 0 (the default) means "until the browser closes"
    'path' => '/',                      // sent for every path on the site
    'secure' => true,                   // only over HTTPS
    'httponly' => true,                 // invisible to JavaScript
    'samesite' => 'Lax',                // not sent on cross-site POSTs
]);

setcookie('old', '', ['expires' => 1, 'path' => '/']);   // delete: an expiry in the past
```

```text
Set-Cookie: theme=dark; expires=Tue, 10 Nov 2026 12:45:00 GMT; Max-Age=2592000; path=/; secure; HttpOnly; SameSite=Lax
Set-Cookie: old=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/
```

| Option | Effect | Recommendation |
|---|---|---|
| `expires` | When the browser deletes it | Short for anything sensitive |
| `path`, `domain` | Which URLs receive it | `path => '/'`; leave `domain` empty (exact host only) |
| `secure` | Only sent over HTTPS | Always, in production |
| `httponly` | Not readable from JavaScript (`document.cookie`) | Always, unless your JavaScript must read it |
| `samesite` | `Strict`: never on cross-site requests; `Lax`: on top-level GET navigations only; `None`: always (requires `secure`) | `Lax` by default |
| `partitioned` | Stored separately per top-level site (CHIPS), for embedded third-party content. **PHP 8.5+** | Only for cookies of embedded widgets |

Things to remember:

- `setcookie()` sends a header, so it must be called before any output.
- `$_COOKIE` contains what the browser sent **with this request**. A cookie you set now appears there only on the
  next request.
- Cookies are **user input**: visitors can read and change them freely. Never store anything there you would not show
  the user, and never trust a cookie's value for decisions such as "is admin". To detect tampering, sign the value with
  `hash_hmac()` (chapter 18); to keep data on the server, use a session.
- Browsers limit cookies to about 4 KB each.

## Sessions

A **session** keeps data on the server between requests from the same visitor. Only a random **session ID** travels
in a cookie; the data stays on the server:

```text
First request:   browser ──────────────────────────────────▶ session_start(): no cookie, so a new ID
                 browser ◀── Set-Cookie: PHPSESSID=8f3c...a1 ── script stores $_SESSION['user_id'] = 7
                                                                 end of request: saved to sess_8f3c...a1

Later requests:  browser ── Cookie: PHPSESSID=8f3c...a1 ──▶ session_start(): loads sess_8f3c...a1
                                                                 $_SESSION['user_id'] is 7 again
```

```php
<?php

declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

$_SESSION['visits'] = ($_SESSION['visits'] ?? 0) + 1;
echo "Visit number {$_SESSION['visits']}\n";
```

Requested twice by the same browser:

```text
Visit number 1
Visit number 2
```

- `session_start()` sends a cookie and caching headers, so call it before any output. Its options override the
  `session.*` settings from `php.ini` for this request; in production, set them in `php.ini` instead (chapter 22).
- `use_strict_mode` makes PHP refuse session IDs it did not create itself, which blocks **session fixation** (an
  attacker making you use an ID they know). It is off by default. Turn it on, together with `cookie_httponly`,
  `cookie_secure` and `cookie_samesite`.
- When a user logs in or out, or their privileges change, call **`session_regenerate_id(true)`** to give them a new ID
  and delete the old one (chapter 18).
- To log out, clear the data, delete the cookie and destroy the stored session: `$_SESSION = []`, `setcookie(session_name(),
  '', ['expires' => 1, 'path' => '/'])`, `session_destroy()`.
- The headers `Expires: Thu, 19 Nov 1981 08:52:00 GMT` and `Cache-Control: no-store, no-cache, must-revalidate` that
  appear with sessions come from `session.cache_limiter`: pages that depend on a session must not be cached by proxies.
  (The date is reportedly the birthday of the developer who wrote that code.)

### Where session data lives, and session locking

By default, sessions are stored as files, one per session, in `session.save_path` (the system's temporary directory,
or `/var/lib/php/sessions` on Debian and Ubuntu). Expired files are deleted by a probabilistic garbage collector
(`session.gc_maxlifetime`, 1440 seconds by default, a value that also limits how long an idle visitor stays logged in).

Files have two consequences for production:

1. With **several web servers**, each has its own files, so a visitor whose requests alternate between servers loses
   their session. Store sessions centrally instead, usually in **Redis** (`session.save_handler = redis` from the
   `redis` extension) or the database, via `SessionHandlerInterface`.
2. The files handler **locks** the session file from `session_start()` until the end of the request. A second
   request from the same visitor (a page that fires several AJAX calls, or a second tab) **waits** until the first
   finishes. If a slow report holds the session for 20 seconds, everything else that visitor does freezes for 20
   seconds. Release the lock as soon as you have finished changing the session:

```php
session_start();
$userId = $_SESSION['user_id'] ?? null;
session_write_close();      // save and unlock; $_SESSION can still be read but changes are not saved

$report = buildSlowReport($userId);   // other requests from this visitor are no longer blocked
```

`session_start(['read_and_close' => true])` reads the session and releases it immediately, for requests that never
modify it.

Keep sessions small (an ID, a few flags) and keep real data in the database: the whole session is read and written on
every request that starts it.

## File uploads

A form that uploads files must use `method="post"` and `enctype="multipart/form-data"`:

```html
<form method="post" action="/covers" enctype="multipart/form-data">
    <input type="file" name="cover" accept="image/png,image/jpeg">
    <button>Upload</button>
</form>
```

PHP saves each uploaded file to a temporary file and describes it in `$_FILES`:

```text
$_FILES['cover'] = [
    'name'      => 'dune-cover.png',     // the file name on the user's computer: untrusted
    'full_path' => 'dune-cover.png',     // with directories, for folder uploads (PHP 8.1+): untrusted
    'type'      => 'image/png',          // what the browser claims: untrusted
    'tmp_name'  => '/tmp/phpA8sd2k',     // where PHP stored it; deleted at the end of the request
    'error'     => 0,                    // UPLOAD_ERR_OK, or one of the codes below
    'size'      => 48213,                // bytes
]
```

| `error` | Constant | Meaning |
|---|---|---|
| 0 | `UPLOAD_ERR_OK` | Success |
| 1 | `UPLOAD_ERR_INI_SIZE` | Larger than `upload_max_filesize` (default 2 MB) |
| 2 | `UPLOAD_ERR_FORM_SIZE` | Larger than the form's `MAX_FILE_SIZE` field |
| 3 | `UPLOAD_ERR_PARTIAL` | Only partly received |
| 4 | `UPLOAD_ERR_NO_FILE` | No file was chosen |
| 6, 7, 8 | `UPLOAD_ERR_NO_TMP_DIR`, `UPLOAD_ERR_CANT_WRITE`, `UPLOAD_ERR_EXTENSION` | Server problems |

Two settings limit uploads: `upload_max_filesize` per file, and `post_max_size` (default 8 MB) for the whole request
body. When a request exceeds `post_max_size`, PHP discards the body entirely: **`$_POST` and `$_FILES` are both empty**,
and only a startup warning in the log says why. A form that "loses all its fields" on large uploads is usually this.
Detect it by comparing `$_SERVER['CONTENT_LENGTH']` with the limit.

Handling an upload safely means treating everything about it as hostile:

```php
<?php

declare(strict_types=1);

function storeCover(array $upload, string $directory): string
{
    if (!isset($upload['error'], $upload['tmp_name']) || is_array($upload['error'])) {
        throw new InvalidArgumentException('Expected a single file');      // e.g. cover[]=... sent instead
    }
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(match ($upload['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large',
            UPLOAD_ERR_NO_FILE => 'Please choose a file',
            default => 'The upload failed, please try again',
        });
    }
    if (!is_uploaded_file($upload['tmp_name']) || $upload['size'] > 1_000_000) {
        throw new InvalidArgumentException('The file is too large');
    }

    // Decide the type from the file's content, never from its name or the browser's claim.
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $extension = match ($type) {
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        default => throw new InvalidArgumentException('Please upload a PNG or JPEG image'),
    };

    // Our own name, in a directory outside the document root.
    $name = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($upload['tmp_name'], "$directory/$name")) {
        throw new RuntimeException('Could not store the upload');
    }

    return $name;
}
```

- `is_uploaded_file()` and `move_uploaded_file()` work only on files PHP received in this request, so a manipulated
  `tmp_name` such as `/etc/passwd` cannot trick you into moving a system file.
- The **content type** comes from `finfo`, which reads the file's first bytes. The browser's `type` and the file name's
  extension are whatever the client wants them to be.
- The stored name is **generated**. User-supplied names can contain `../`, collide with other files, or end in `.php`.
- The directory is **outside the document root**. If uploads were stored in `public/` and the server ran `.php` files
  there, uploading `shell.php` would let an attacker run code on your server. Serve stored files through a script that
  sets the right `Content-Type`, or from a separate domain or object storage (S3 and similar).

## URLs

### Building URLs and query strings

```php
<?php

declare(strict_types=1);

echo http_build_query(['q' => 'sci fi & more', 'genre' => 'sf', 'page' => 2]), "\n";
echo '/books/' . rawurlencode('Brave New World?'), "\n";
echo urlencode('a b+c'), ' vs ', rawurlencode('a b+c'), "\n";

parse_str('q=dune&tags[]=sf&tags[]=classic', $params);
print_r($params);
```

```text
q=sci+fi+%26+more&genre=sf&page=2
/books/Brave%20New%20World%3F
a+b%2Bc vs a%20b%2Bc
Array
(
    [q] => dune
    [tags] => Array
        (
            [0] => sf
            [1] => classic
        )

)
```

`http_build_query()` builds query strings, encoding each value. Encode **path segments** with `rawurlencode()`
(spaces become `%20`); `urlencode()` is for the query string's form encoding, where spaces become `+`. Never build a
URL by concatenating raw user input.

### Parsing URLs: the URI extension (PHP 8.5+)

The old `parse_url()` splits a URL into parts, but it follows no standard, accepts many malformed URLs, doesn't
normalize, and different parsers in your stack can disagree about the same string, which has led to real security
bugs. **PHP 8.5** adds an always-available URI extension with two standards-compliant parsers:

- `Uri\Rfc3986\Uri` follows RFC 3986, the generic URI standard. Use it for URIs in general and for server-side work.
- `Uri\WhatWg\Url` follows the WHATWG URL standard, which is what browsers implement. Use it when you need to
  interpret a URL exactly as a browser would (links users paste, redirects you validate).

```php
<?php

declare(strict_types=1);

use Uri\Rfc3986\Uri;
use Uri\WhatWg\Url;

$uri = new Uri('https://ada@Example.ORG:8080/books/../catalog/%7edune?q=sci%20fi#top');
echo $uri->getScheme(), ' | ', $uri->getHost(), ' | ', $uri->getPort(), ' | ', $uri->getPath(), "\n";
echo $uri->toString(), "\n";        // normalized
echo $uri->toRawString(), "\n";     // exactly as given

$base = new Uri('https://example.org/books/7/loans');
echo $base->resolve('../8')->toString(), "\n";                              // like a relative link
echo $base->withPath('/members')->withQuery('page=2')->toString(), "\n";    // immutable "withers"

var_dump(Uri::parse('https://exa mple.org'));   // invalid: parse() returns null, the constructor throws

$url = new Url('https://münchen.example/straße');
echo $url->getAsciiHost(), ' | ', $url->getUnicodeHost(), ' | ', $url->toAsciiString(), "\n";
```

```text
https | example.org | 8080 | /catalog/~dune
https://ada@example.org:8080/catalog/~dune?q=sci%20fi#top
https://ada@Example.ORG:8080/books/../catalog/%7edune?q=sci%20fi#top
https://example.org/books/8
https://example.org/members?page=2
NULL
xn--mnchen-3ya.example | münchen.example | https://xn--mnchen-3ya.example/stra%C3%9Fe
```

- The getters return **normalized** values (lowercase host, `..` resolved, unnecessary percent-encoding decoded).
  The `getRaw*()` methods return the parts exactly as written.
- Objects are immutable; `with*()` methods return modified copies, like `DateTimeImmutable`.
- `new Uri(...)` throws `Uri\InvalidUriException` for invalid input; the static `parse()` returns `null`.
  `Url::parse($text, null, $errors)` also reports the WHATWG *validation errors* it recovered from.
- Internationalized domain names come out in both forms: the ASCII "punycode" form for DNS, and Unicode for display.

A typical use is validating a URL a user supplied before redirecting to it or fetching it: parse it with the same
standard the browser uses, then check the scheme and host against an allow list.

> **If you know Node.js:** `Uri\WhatWg\Url` implements the same WHATWG standard as the global `URL` class, so both
> parse a given string identically.

## Bookshelf: the first web pages

Bookshelf now has a website: a catalog with search and a genre filter, a JSON endpoint for the same data, and a form
for suggesting new books. Everything goes through one **front controller**, `public/index.php`: the web server sends
every request that isn't a static file to it, and it decides what to do from the method and path. The new files:

```text
examples/bookshelf/
  public/
    index.php          the front controller, with the page functions
    style.css
  templates/
    layout.php         the HTML page around every page's content
    catalog.php
    suggest.php
    not-found.php
  var/                 created at runtime, ignored by git: suggestions.csv
```

There is no database yet, so the catalog reads `data/books.csv` with chapter 13's `CsvBookReader` on **every request**,
a direct consequence of shared nothing. Chapter 17 moves the data into SQLite. Chapter 19 then splits this one file into
a router, request and response objects, and controllers; for now, plain functions show the moving parts.

The bottom of `index.php` is the "router", a `match` on method and path. It parses `REQUEST_URI` with the URI
extension, whose `getPath()` has already removed the query string and resolved any `..` segments:

```php
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = Uri::parse($_SERVER['REQUEST_URI'] ?? '/')?->getPath() ?? '/';

match ("$method $path") {
    'GET /', 'HEAD /' => showCatalog(),
    'GET /api/books' => apiBooks(),
    'GET /suggest' => showSuggestForm(),
    'POST /suggest' => handleSuggestion(),
    default => notFound(),
};
```

`render()` runs a template with its variables and returns the HTML as a string, using output buffering; `page()` sets
the status and content type, wraps the page in the layout, and prints it:

```php
/** Runs a template file with the given variables and returns the HTML it printed. */
function render(string $template, array $variables = []): string
{
    extract($variables);
    ob_start();
    try {
        require ROOT . "/templates/$template.php";
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function page(string $title, string $template, array $variables = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo render('layout', [
        'title' => $title,
        'flash' => takeFlash(),
        'content' => render($template, $variables),
    ]);
}
```

`extract()` turns the array's keys into local variables (`['books' => $list]` gives the template a `$books`), and
because the template is `require`d inside the function, it sees only those variables. The `finally` discards the
buffer even when a template throws, so a half-rendered page never leaks out before an error page.

The catalog page reads its two parameters safely and hands the matching books to its template:

```php
function showCatalog(): void
{
    $query = stringParam($_GET, 'q');
    $genre = Genre::tryFrom(stringParam($_GET, 'genre'));

    page('Catalog', 'catalog', [
        'books' => searchBooks($query, $genre),
        'query' => $query,
        'genre' => $genre,
    ]);
}
```

And the template escapes every value it prints, including the search text it puts back into the form:

```php
<form method="get" action="/" class="search">
    <input type="search" name="q" value="<?= e($query) ?>" placeholder="Title or author" aria-label="Search">
    <select name="genre" aria-label="Genre">
        <option value="">All genres</option>
        <?php foreach (Genre::cases() as $case): ?>
            <option value="<?= e($case->value) ?>" <?= $case === $genre ? 'selected' : '' ?>><?= e($case->label()) ?></option>
        <?php endforeach; ?>
    </select>
    <button>Search</button>
</form>

<?php if ($books === []): ?>
    <p>No books match your search.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Title</th><th>Author</th><th>Genre</th><th>Year</th><th>Available</th></tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td><?= e($book->title) ?></td>
                <td><?= e($book->author) ?></td>
                <td><?= e($book->genre?->label()) ?></td>
                <td><?= e($book->year) ?></td>
                <td><?= e($book->available) ?> of <?= e($book->copies) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
```

The suggestion form follows the validate, re-display, redirect flow. Errors re-render the form with status 422 and the
visitor's input; success appends a line to `var/suggestions.csv` under a lock (chapter 14), stores a flash message in
the session, and redirects with 303:

```php
function handleSuggestion(): void
{
    $values = [
        'title' => stringParam($_POST, 'title'),
        'author' => stringParam($_POST, 'author'),
        'isbn' => stringParam($_POST, 'isbn', 20),
        'reason' => stringParam($_POST, 'reason', 1000),
    ];

    $errors = [];
    if ($values['title'] === '') {
        $errors['title'] = 'Please enter the title.';
    }
    if ($values['author'] === '') {
        $errors['author'] = 'Please enter the author.';
    }
    if ($values['isbn'] !== '' && !Isbn::isValid(preg_replace('/[\s-]+/', '', $values['isbn']))) {
        $errors['isbn'] = 'That is not a valid ISBN-13. Leave it empty if you are unsure.';
    }

    if ($errors !== []) {
        page('Suggest a book', 'suggest', ['values' => $values, 'errors' => $errors], 422);
        return;
    }

    $directory = ROOT . '/var';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create $directory");
    }
    $file = fopen("$directory/suggestions.csv", 'a');
    flock($file, LOCK_EX);
    fputcsv($file, [date(DATE_ATOM), ...array_values($values)], escape: '');
    flock($file, LOCK_UN);
    fclose($file);

    flash("Thank you! We'll consider \"{$values['title']}\".");
    redirect('/');
}
```

`takeFlash()` only starts a session if the browser already has a session cookie, so visitors who never submit the form
never get one. The full file, with the JSON endpoint and the session helpers, is
[`examples/bookshelf/public/index.php`](examples/bookshelf/public/index.php).

Run it from `examples/bookshelf` and open `http://localhost:8000`:

```bash
composer serve       # runs: php -S localhost:8000 -t public
```

Or try it with `curl`:

```bash
curl 'http://localhost:8000/api/books?genre=fantasy'
curl -i 'http://localhost:8000/api/books?genre=poetry'
curl -i -X POST -d 'title=The Dispossessed&author=Ursula K. Le Guin&isbn=978-0-06-051275-0' http://localhost:8000/suggest
```

```text
{"count":2,"data":[{"title":"The Hobbit","author":"J. R. R. Tolkien","isbn":"9780547928227","genre":"fantasy","year":1937,"available":2},{"title":"A Wizard of Earthsea","author":"Ursula K. Le Guin","isbn":"9780547773742","genre":"fantasy","year":1968,"available":1}]}

HTTP/1.1 400 Bad Request
Content-Type: application/json; charset=utf-8

{"error":"Unknown genre 'poetry'"}

HTTP/1.1 303 See Other
Set-Cookie: PHPSESSID=676e2a344f21fe72608b575e42f357d7; path=/; HttpOnly; SameSite=Lax
Expires: Thu, 19 Nov 1981 08:52:00 GMT
Cache-Control: no-store, no-cache, must-revalidate
Pragma: no-cache
Location: /
Content-Type: text/html; charset=UTF-8
```

(Headers such as `Date` and `X-Powered-By` are left out.) The JSON is the `Isbn` and `Genre` values' own
serialization: `Isbn` implements `JsonSerializable`, and a backed enum encodes as its value. A browser following the
redirect shows the catalog with "Thank you! We'll consider "The Dispossessed"." above it, once.

## Key takeaways

- A SAPI connects PHP to the web server; in production that is PHP-FPM behind nginx (or FrankenPHP). The built-in
  server is for development only.
- Shared nothing: every request starts from scratch and everything is freed at the end. Persist state externally, keep
  the bootstrap lean, and enjoy the isolation.
- Point the document root at `public/`, with only the front controller and assets in it.
- All request data is untrusted and may be an array where you expect a string: read parameters through type-checking
  helpers or `filter_input()`. JSON bodies come from `php://input`.
- Headers and the status must be set before output. Omit the closing `?>` in code files, and use output buffering on
  purpose, not by accident.
- Escape everything you print into HTML with `htmlspecialchars()` (`e()`).
- Use GET to read and POST to change; validate, re-display with 422, and redirect with 303 after success.
- Cookies: `secure`, `httponly`, `samesite=Lax`; never trust their contents. Sessions: strict mode, regenerate the ID on
  login, release the lock early, store centrally when you have several servers.
- Uploads: check the error code and size, detect the type from the content, generate the name, and store outside the
  document root. Know that exceeding `post_max_size` empties `$_POST`.
- PHP 8.5's `Uri\Rfc3986\Uri` and `Uri\WhatWg\Url` parse URLs by the standards; prefer them to `parse_url()`.

## Exercises

1. **Method not allowed.** Bookshelf's router answers `DELETE /suggest` with a 404, which is misleading: the path
   exists. Change the `match` so that a known path with the wrong method gets `405 Method Not Allowed` with an `Allow`
   header listing the methods it supports (`Allow: GET, POST` for `/suggest`).

<details>
<summary>Solution</summary>

Replace the `match` at the bottom of `public/index.php` with a table of routes, then look up the path first and the
method second:

```php
$routes = [
    '/' => ['GET' => showCatalog(...), 'HEAD' => showCatalog(...)],
    '/api/books' => ['GET' => apiBooks(...)],
    '/suggest' => ['GET' => showSuggestForm(...), 'POST' => handleSuggestion(...)],
];

$handlers = $routes[$path] ?? null;
if ($handlers === null) {
    notFound();
} elseif (!isset($handlers[$method])) {
    http_response_code(405);
    header('Allow: ' . implode(', ', array_keys($handlers)));
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method $method is not allowed here.\n";
} else {
    $handlers[$method]();
}
```

```bash
curl -i -X DELETE http://localhost:8000/suggest
```

```text
HTTP/1.1 405 Method Not Allowed
Allow: GET, POST
Content-Type: text/plain; charset=utf-8

Method DELETE is not allowed here.
```

First-class callables (`showCatalog(...)`, chapter 6) store the functions without calling them. This table is the seed
of the router chapter 19 builds.

</details>

2. **A CSV download.** Add `GET /catalog.csv`, which sends the (optionally filtered by `?q=`) catalog as a CSV file that
   the browser downloads as `bookshelf-catalog.csv`, with a UTF-8 BOM for Excel and formula-safe cells (chapter 14).

<details>
<summary>Solution</summary>

```php
function downloadCatalog(): void
{
    $books = searchBooks(stringParam($_GET, 'q'), null);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bookshelf-catalog.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['title', 'author', 'isbn', 'genre', 'year', 'copies'], escape: '');
    $safe = fn (string $cell): string => preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'" . $cell : $cell;
    foreach ($books as $book) {
        fputcsv($out, array_map($safe, [
            $book->title, $book->author, $book->isbn->value,
            $book->genre?->value ?? '', (string) $book->year, (string) $book->copies,
        ]), escape: '');
    }
    fclose($out);
}
```

And in the router: `'GET /catalog.csv' => downloadCatalog(),`.

```bash
curl -i 'http://localhost:8000/catalog.csv?q=austen'
```

```text
HTTP/1.1 200 OK
Content-Type: text/csv; charset=utf-8
Content-Disposition: attachment; filename="bookshelf-catalog.csv"

﻿title,author,isbn,genre,year,copies
Emma,"Jane Austen",9780141439587,classic,1815,1
Persuasion,"Jane Austen",9780141439686,classic,1817,1
```

`Content-Disposition: attachment` makes the browser save the response instead of displaying it. `php://output` writes
to the same output as `echo`, so `fputcsv()` can stream rows straight to the browser without building the file in
memory. (The BOM is invisible at the start of the first line.)

</details>

3. **Recent searches in a cookie.** Remember the visitor's last three non-empty searches in a cookie named `recent`
   (JSON-encoded, newest first, no duplicates) and show them as links under the search form. The cookie is user input:
   reading it must survive any value someone puts there.

<details>
<summary>Solution</summary>

```php
/** @return list<string> */
function recentSearches(): array
{
    $raw = $_COOKIE['recent'] ?? '';
    if (!is_string($raw) || strlen($raw) > 1000) {
        return [];
    }
    try {
        $list = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [];
    }
    if (!is_array($list)) {
        return [];
    }

    return array_slice(array_values(array_filter($list, fn (mixed $q): bool => is_string($q) && $q !== '')), 0, 3);
}

function rememberSearch(string $query): array
{
    $recent = array_values(array_unique([$query, ...recentSearches()]));
    $recent = array_slice($recent, 0, 3);
    setcookie('recent', json_encode($recent, JSON_THROW_ON_ERROR), [
        'expires' => time() + 90 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);

    return $recent;
}
```

In `showCatalog()`, compute `$recent = $query !== '' ? rememberSearch($query) : recentSearches();` before calling
`page()` (cookies are headers, so this must happen before any output), and pass it to the template:

```php
<?php if ($recent !== []): ?>
    <p>Recent:
        <?php foreach ($recent as $item): ?>
            <a href="/?q=<?= e(rawurlencode($item)) ?>"><?= e($item) ?></a>
        <?php endforeach; ?>
    </p>
<?php endif; ?>
```

Every way the cookie can be wrong (not a string, too long, not JSON, nested too deep, not a list of strings) falls back
to an empty list. The link uses `rawurlencode()` for the URL and then `e()` for the HTML attribute: two different
contexts, two encodings, in that order.

</details>

4. **Upload a cover.** Add `GET /covers` with an upload form and `POST /covers` that stores a PNG or JPEG of at most
   1 MB in `var/covers/` using the `storeCover()` function from this chapter, then redirects back with a flash message
   naming the stored file. Show the error on the form (status 422) otherwise. Test it with a real image and with a text
   file renamed to `.png`.

<details>
<summary>Solution</summary>

```php
function showCoverForm(?string $error = null): void
{
    page('Upload a cover', 'cover', ['error' => $error], $error === null ? 200 : 422);
}

function handleCoverUpload(): void
{
    $directory = ROOT . '/var/covers';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Cannot create $directory");
    }
    try {
        $name = storeCover($_FILES['cover'] ?? [], $directory);
    } catch (InvalidArgumentException $e) {
        showCoverForm($e->getMessage());
        return;
    }
    flash("Cover stored as $name.");
    redirect('/covers');
}
```

`templates/cover.php`:

```php
<?php /** @var ?string $error */ ?>
<form method="post" action="/covers" enctype="multipart/form-data" class="stacked">
    <label>Cover image (PNG or JPEG, up to 1 MB)
        <input type="file" name="cover" accept="image/png,image/jpeg">
    </label>
    <?php if ($error !== null): ?>
        <span class="error"><?= e($error) ?></span>
    <?php endif; ?>
    <button>Upload</button>
</form>
```

Routes: `'GET /covers' => showCoverForm(),` and `'POST /covers' => handleCoverUpload(),`. Testing:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -F 'cover=@dune.png' http://localhost:8000/covers
echo 'not an image' > fake.png
curl -s -w '%{http_code}\n' -F 'cover=@fake.png;type=image/png' http://localhost:8000/covers | grep -o 'Please upload[^<]*'
```

```text
303
Please upload a PNG or JPEG image
```

The fake file claims `image/png` in both its name and the browser's `type`, and is still rejected, because `finfo`
looks at the bytes (`text/plain`). `$_FILES['cover'] ?? []` handles a POST without a file field at all: `storeCover()`
then fails its first check.

</details>

5. **Measure session locking.** Write `slow.php`, which starts a session, increments a counter and sleeps for two
   seconds, and `fast.php`, which starts a session and prints the counter. Request `slow.php` and, while it runs,
   `fast.php` with the same session cookie, and time `fast.php`. Then fix `slow.php` so that `fast.php` returns
   immediately.

<details>
<summary>Solution</summary>

```php
<?php

// slow.php
declare(strict_types=1);

session_start();
$_SESSION['count'] = ($_SESSION['count'] ?? 0) + 1;
sleep(2);   // a slow report, an external API call...
echo "slow done\n";
```

```php
<?php

// fast.php
declare(strict_types=1);

session_start(['read_and_close' => true]);
echo 'count: ', $_SESSION['count'] ?? 0, "\n";
```

```bash
PHP_CLI_SERVER_WORKERS=2 php -S localhost:8000 &      # two workers, so two requests can run at once
curl -s -c jar http://localhost:8000/fast.php          # get a session cookie
curl -s -b jar http://localhost:8000/slow.php > /dev/null &
sleep 0.2
time curl -s -b jar http://localhost:8000/fast.php
```

```text
count: 0
count: 1
real    0m1.806s
```

`fast.php` waited for `slow.php` to release the session file, even though it only reads. The fix is in `slow.php`:
finish with the session before the slow part.

```php
session_start();
$_SESSION['count'] = ($_SESSION['count'] ?? 0) + 1;
session_write_close();   // saves and unlocks
sleep(2);
```

Now `fast.php` returns in milliseconds (`real 0m0.009s` on the same machine) and already sees the new count. With the
built-in server's default single worker, the second request would wait anyway, for the worker rather than the lock,
which is why the test starts the server with two.

</details>

---
← Previous: [Dates, times and internationalization](15-dates-times-and-internationalization.md) | Next: [Databases with PDO](17-databases-with-pdo.md) →
