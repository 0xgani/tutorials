# Chapter 18 — Security

## What you'll learn

- How to think about security: trust boundaries, validation versus escaping, and defense in depth
- Injection beyond SQL: shell commands, file paths, `include`, headers and `unserialize()`
- Cross-site scripting (XSS): escaping for each output context, and Content Security Policy
- Cross-site request forgery (CSRF): tokens, `SameSite` cookies and origin checks
- Storing passwords with `password_hash()`, and the details that go wrong
- Login, sessions, throttling and authorization
- Open redirects, SSRF, uploads, secrets and production settings
- Secure randomness, hashes and HMACs, and encryption with sodium
- Bookshelf gets logins, CSRF protection, security headers and staff-only pages

Security is not a feature you add at the end; it is a property of every line that handles input or produces output.
Most of the vulnerabilities in real PHP applications come from a handful of mistakes, and every one of them has a
standard, well-understood defense. This chapter goes through them in order of how often they cause damage.

## Thinking about security

Three ideas organize everything that follows.

**Trust boundaries.** Data crosses a boundary when it comes from somewhere you don't control: the request (query
string, form fields, cookies, headers, uploaded files), the database (other code and people wrote it), files, other
services' APIs, even environment variables in a shared host. Everything from outside the boundary is **untrusted**
until checked, and "it comes from our own form" changes nothing, because anyone can send any request.

```text
            untrusted                         │                trusted (your code)
  ─────────────────────────────────────────── │ ──────────────────────────────────────────────
  query string, form fields, JSON bodies  ──▶ │ validate ──▶ domain objects ──▶ escape ──▶ HTML
  cookies, headers, uploaded files        ──▶ │ (reject      (rules hold)       (for the     SQL (placeholders)
  database rows, files, other APIs        ──▶ │  bad input)                      context)    shell, URLs, ...
```

**Validate input, escape output.** These are two different jobs, and you need both:

- **Validation** at the entrance decides whether input is acceptable for your *business rules*: an ISBN with a valid
  check digit, a year between 1450 and next year, a genre from the enum. Reject what fails, with a helpful message.
- **Escaping** (or encoding) at the exit makes data safe for the *language it is inserted into*: HTML, SQL, a shell
  command, a URL, JavaScript. A perfectly valid book title such as `Tom & Jerry <Collected>` still needs HTML escaping.

Validation cannot replace escaping: a valid value can still contain characters that mean something in the output
language, and the same value goes into several languages. Escape at the moment of output, for that output.

**Defense in depth.** Assume each defense will fail sometimes, and add another layer behind it: escaping *and* a
Content Security Policy; a CSRF token *and* `SameSite` cookies; a check in PHP *and* a constraint in the database.
And apply **least privilege**: the database user the web app connects with doesn't need to drop tables, and the PHP
process doesn't need write access to its own code.

The [OWASP Top 10](https://owasp.org/www-project-top-ten/) is the standard list of the most critical web application
risks, and the OWASP Cheat Sheet Series gives concrete advice for each; both are worth bookmarking.

## Injection

**Injection** happens whenever untrusted text is inserted into something that is later *interpreted*: SQL, a shell
command, a file path, HTML. The text escapes its intended role (a value) and becomes code. Chapter 17 showed SQL
injection and its fix, placeholders. The same pattern appears in other places.

### Shell commands

```php
<?php

declare(strict_types=1);

$file = 'notes.txt; echo INJECTED';   // a "file name" from a form

echo "ls -l $file\n";                                // WRONG: the shell runs two commands
echo 'ls -l ' . escapeshellarg($file), "\n";        // quoted as one argument

// Better: no shell at all. With an array, proc_open() runs the program directly with these exact arguments.
$process = proc_open(['ls', '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
echo stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
echo 'exit code ', proc_close($process), "\n";
```

```text
ls -l notes.txt; echo INJECTED
ls -l 'notes.txt; echo INJECTED'
ls: cannot access 'notes.txt; echo INJECTED': No such file or directory
exit code 2
```

`exec()`, `shell_exec()`, `system()`, `passthru()`, backticks and `proc_open()` with a *string* all go through
`/bin/sh`, where `;`, `|`, `&&`, `$(...)` and backticks start new commands. The first defense is not to run a shell
command at all: PHP has functions for most things people shell out for (files, images, archives, HTTP). When you
must, pass an **array** to `proc_open()` (chapter 14), so there is no shell to inject into. Only if a shell is truly
needed, wrap **every** argument in `escapeshellarg()`. And a value starting with `-` can still be read as an option
by the program, so validate it too, or put `--` before it.

### File paths and `include`

Chapter 14 covered **path traversal**: `"/srv/covers/$name"` with `$name = '../../etc/passwd'` reads a file outside the
directory. The fix is to generate file names yourself, or to check with `realpath()` that the result stays inside the
intended directory.

The dangerous version of the same mistake is choosing code to run from input:

```php
// WRONG: ?page=../../config/secrets or ?page=../var/uploads/shell runs any PHP file the attacker can reach.
include __DIR__ . "/pages/{$_GET['page']}.php";

// RIGHT: input only selects from a fixed list.
$pages = ['about' => 'about.php', 'contact' => 'contact.php'];
include __DIR__ . '/pages/' . ($pages[$_GET['page'] ?? ''] ?? 'not-found.php');
```

The same applies to anything that turns text into code: never pass input to `eval()`, `create_function()` (removed in
PHP 8.0), `preg_replace()` with the `/e` modifier (removed in 7.0), or a "callable" string such as
`$_GET['action']()`. Map input to code through an allow list or a `match`.

### Headers

An HTTP header ends at a line break, so a value with `\r\n` in it could add headers of its own ("header injection" or
"response splitting"). PHP's `header()` refuses values that contain a line break:

```text
Warning: Header may not contain more than a single header, new line detected
```

That protects `Location: ...` and similar headers, but an e-mail library that builds `To:` and `Subject:` lines by hand
can have the same bug. Use a maintained mail library (Symfony Mailer, PHPMailer), never `mail()` with user-supplied
headers.

### `unserialize()` and object injection

`serialize()` turns any PHP value, objects included, into a string; `unserialize()` turns it back, **creating objects of
any class named in the string** and running their magic methods (`__wakeup()`, `__unserialize()`, later
`__destruct()`). Code that unserializes an attacker's string lets them create objects of any autoloadable class with
any property values. Chains of such objects ("gadget chains") that end in a file write or a shell command have been
found in most large frameworks and libraries; tools such as PHPGGC collect them.

```php
<?php

declare(strict_types=1);

final class TempFile
{
    public function __construct(public string $path)
    {
    }

    public function __destruct()
    {
        echo "deleting {$this->path}\n";      // imagine unlink($this->path)
    }
}

// A "preferences" cookie that someone has edited:
$cookie = 'O:8:"TempFile":1:{s:4:"path";s:17:"/srv/app/.env.php";}';

unserialize($cookie);                                       // creates a TempFile and destroys it: "deleting ..."
var_dump(unserialize($cookie, ['allowed_classes' => false]));
```

```text
deleting /srv/app/.env.php
object(__PHP_Incomplete_Class)#1 (2) {
  ["__PHP_Incomplete_Class_Name"]=>
  string(8) "TempFile"
  ["path"]=>
  string(17) "/srv/app/.env.php"
}
```

The rule: **never call `unserialize()` on data that a user could have changed.** For data that travels through the
client (cookies, hidden fields, API payloads), use `json_decode()`, which only ever creates arrays and scalars. If you
must unserialize semi-trusted data, pass `['allowed_classes' => false]` (or a list of classes), and add an HMAC
signature (below) so that tampering is detected before parsing. The `phar://` stream wrapper used to unserialize
metadata automatically; PHP 8.0 stopped doing that, but it is one more reason never to pass user-controlled paths to
file functions.

### XML

XML has its own injection risk: an **external entity** (`<!ENTITY x SYSTEM "file:///etc/passwd">`) makes a parser read
local files or URLs into the document (XXE). PHP 8.0+ with libxml2 2.9+ does not load external entities by default,
and `libxml_disable_entity_loader()` is deprecated because it is no longer needed. Keep it that way: don't pass
`LIBXML_NOENT` or `LIBXML_DTDLOAD` when parsing untrusted XML.

## Cross-site scripting (XSS)

**Cross-site scripting** is HTML injection: untrusted text is placed into a page without escaping, and the browser
runs it as part of your site. A script running in your page can read everything the user sees, make requests as the
user (change their e-mail address, then reset their password), and show fake login forms on your domain.

| Kind | How the script gets into the page |
|---|---|
| **Stored** | Saved by one user (a book suggestion, a comment, a profile name), shown to others later. The most damaging: it hits every viewer, including staff |
| **Reflected** | Part of the request, echoed back: a search page printing "No results for *query*", reached through a crafted link |
| **DOM-based** | Your own JavaScript takes text from the URL or an API and inserts it with `innerHTML` |

The defense is the same for the first two: **escape every value at output, for the context it goes into.** Chapter 16's
`e()` helper does that for HTML; this section adds the other contexts.

### HTML text and quoted attributes

```php
<?php

declare(strict_types=1);

function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$title = '"><script>alert(document.cookie)</script>';

echo '<input name="title" value="' . $title . '">', "\n";       // WRONG
echo '<input name="title" value="' . e($title) . '">', "\n";    // RIGHT
```

```text
<input name="title" value=""><script>alert(document.cookie)</script>">
<input name="title" value="&quot;&gt;&lt;script&gt;alert(document.cookie)&lt;/script&gt;">
```

`htmlspecialchars()` converts the five characters that have meaning in HTML (`&`, `<`, `>`, `"`, `'`) to entities.
`ENT_QUOTES` (escape both quote characters) and `ENT_SUBSTITUTE` (replace invalid UTF-8 with U+FFFD instead of
returning an empty string) have been the defaults since PHP 8.1, but spelling them out documents the intent and keeps
older code safe. That makes the result safe in two contexts: **HTML text** and **attribute values in quotes**.

It is **not** safe in other places:

| Context | Example | Defense |
|---|---|---|
| HTML text | `<td><?= e($title) ?></td>` | `e()` |
| Quoted attribute | `<input value="<?= e($title) ?>">` | `e()`, always with quotes |
| Unquoted attribute | `<input value=<?= e($v) ?>>` | Never write this: `x onfocus=alert(1) autofocus` contains no character `e()` changes, but adds attributes |
| URL in `href`/`src` | `<a href="<?= e($url) ?>">` | Check the scheme first (below), then `e()` |
| Inside `<script>` | `const book = <?= ... ?>;` | `json_encode()` with the `JSON_HEX_*` flags, or a `data-` attribute |
| Event handlers, `style`, CSS | `onclick="..."`, `style="..."` | Don't put untrusted data there |
| Element or attribute *names* | `<<?= $tag ?>>` | Choose from an allow list |

Templates are where escaping is forgotten, so make the safe way the default way. Every PHP template engine used in
practice (Twig, Blade, Latte, Plates) escapes `{{ value }}` automatically and makes raw output stand out
(`{{ value|raw }}`, `{!! $value !!}`), which turns "forgot to escape" into "had to ask for no escaping". In plain PHP
templates, `<?= e(...) ?>` everywhere, and an unescaped `<?= $html ?>` only for HTML your own code produced (like the
layout's `$content`), is the discipline.

### URLs

An escaped URL can still be dangerous, because the **scheme** decides what a link does:

```php
<?php

declare(strict_types=1);

use Uri\WhatWg\Url;

/** $url if it is an absolute http(s) URL, otherwise null. */
function safeUrl(string $url): ?string
{
    $parsed = Url::parse($url);

    return $parsed !== null && in_array($parsed->getScheme(), ['http', 'https'], true) ? $parsed->toAsciiString() : null;
}

$inputs = ['https://example.org/a b', 'javascript:alert(1)', ' JaVaScRiPt:alert(1)', "java\tscript:alert(1)"];
foreach ($inputs as $input) {
    printf("%-26s %-35s %s\n", json_encode($input), var_export(filter_var($input, FILTER_VALIDATE_URL), true),
        var_export(safeUrl($input), true));
}
var_dump(filter_var('javascript://comment%0Aalert(1)', FILTER_VALIDATE_URL));
```

```text
"https:\/\/example.org\/a b" false                               'https://example.org/a%20b'
"javascript:alert(1)"      false                               NULL
" JaVaScRiPt:alert(1)"     false                               NULL
"java\tscript:alert(1)"    false                               NULL
string(31) "javascript://comment%0Aalert(1)"
```

A member's "website" saved as `javascript:alert(1)` becomes a link that runs script when clicked, and HTML escaping
doesn't change it at all. `filter_var(..., FILTER_VALIDATE_URL)` checks the *syntax* only: it rejects a URL with a
space in it but **accepts** `javascript://comment%0Aalert(1)` (a JavaScript comment, a newline, then code). Browsers
also ignore leading spaces, letter case and embedded tabs in schemes. So parse with the **WHATWG** parser, which reads
URLs exactly as browsers do (PHP 8.5+; chapter 16), and allow only the schemes you want. On older versions,
`parse_url()` plus a scheme check, after removing whitespace and control characters, is the usual approximation.

For values placed *into* a URL, encode them as URL components: `rawurlencode()` for path segments and
`http_build_query()` for query strings, then `e()` for the HTML attribute around the whole URL.

### Inside `<script>`

To hand data to JavaScript, encode it as JSON, which is valid JavaScript syntax:

```php
<?php

declare(strict_types=1);

$book = ['title' => '</script><script>alert(1)</script>', 'note' => "it's \"new\" & shiny"];

echo json_encode($book, JSON_UNESCAPED_SLASHES), "\n";                                      // WRONG in a <script>
echo json_encode($book, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), "\n";  // RIGHT
```

```text
{"title":"</script><script>alert(1)</script>","note":"it's \"new\" & shiny"}
{"title":"<\/script><script>alert(1)<\/script>","note":"it's "new" & shiny"}
```

The browser's HTML parser ends a `<script>` element at the first `</script>`, without knowing anything about JavaScript
strings, so the first version lets the title close the script and open a new one. By default `json_encode()` escapes
`/` (which happens to prevent exactly this), but code that adds `JSON_UNESCAPED_SLASHES` for nicer URLs loses that
protection. The `JSON_HEX_*` flags encode `<`, `>`, `&`, `'` and `"` as `\u` escapes, which are safe in a `<script>`
element and in an HTML attribute. Often simpler still: put the JSON in an attribute,
`<div id="book" data-book="<?= e(json_encode($book)) ?>">`, and read it with `JSON.parse(element.dataset.book)`.

### Rich text

Sometimes users are *supposed* to submit HTML (a formatted description). Escaping would show the tags as text, and
removing tags with `strip_tags()` is not a security measure (it leaves attributes such as `onclick` on allowed tags).
Use an HTML **sanitizer**, which parses the HTML and keeps only an allow list of elements and attributes:
`symfony/html-sanitizer` or HTML Purifier. Or accept Markdown and render it with a library configured to escape raw
HTML (`league/commonmark` with `'html_input' => 'escape'`).

### Content Security Policy

A **Content Security Policy** (CSP) is a response header that tells the browser which sources of scripts, styles and
other content are allowed on the page. It is the second layer behind escaping: if a `<script>` gets injected anyway,
a policy that forbids inline scripts stops it from running.

```php
header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; "
    . "form-action 'self'; frame-ancestors 'none'");
```

| Directive | Meaning |
|---|---|
| `default-src 'self'` | Scripts, styles, images, fonts, connections only from this site's own origin. No inline `<script>`, no `onclick=`, no `javascript:` URLs, no `eval()` |
| `object-src 'none'` | No plugins (`<object>`, `<embed>`) |
| `base-uri 'none'` | An injected `<base href>` cannot redirect relative URLs |
| `form-action 'self'` | Forms can submit only to this site |
| `frame-ancestors 'none'` | No other site may show this page in a frame (prevents **clickjacking**: an invisible frame of your site under a decoy button) |

A strict policy means no inline JavaScript at all: code lives in `.js` files, event handlers are attached with
`addEventListener()`. When an inline script is unavoidable, allow it with a **nonce**, a random value generated per
response and repeated on the script tag:

```php
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'");
?>
<script nonce="<?= $nonce ?>">document.documentElement.classList.add('js');</script>
```

An injected script doesn't know the nonce, so it doesn't run. When adding CSP to an existing site, start with
`Content-Security-Policy-Report-Only`, which reports violations without blocking, and tighten the policy until the
reports are quiet.

## Cross-site request forgery (CSRF)

A browser sends a site's cookies with every request to that site, **whichever page started the request**. So while a
librarian is logged in to Bookshelf, any other website they visit can submit a form to Bookshelf, and the browser
attaches the session cookie:

```html
<!-- on https://evil.example, in a page the librarian opens -->
<form id="f" method="post" action="https://bookshelf.example/members/17/delete"></form>
<script>document.getElementById('f').submit();</script>
```

Bookshelf receives a perfectly normal POST with a valid session, and deletes the member. The attacker never sees the
response; they don't need to. This is **cross-site request forgery**. Three defenses, best used together:

1. **Never change anything on `GET`.** Links, images and prefetching all make GET requests. State changes use POST
   (or PUT, PATCH, DELETE), which also keeps them out of logs and browser history.
2. **`SameSite` cookies.** A session cookie with `SameSite=Lax` (chapter 16) is not sent with cross-site POSTs, which
   blocks the attack above in current browsers. It is a strong layer, but not a complete one on its own: it doesn't
   protect GET requests that change state, requests from other subdomains of the same site, or very old browsers.
3. **A CSRF token.** Each session gets a random secret; every form includes it in a hidden field; every POST is
   rejected unless the field matches the session's value. The attacker's page can make the browser *send* a request,
   but it cannot *read* your pages, so it cannot learn the token.

Bookshelf's token code is small:

```php
final class Csrf
{
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Compares in constant time, so the response time doesn't leak how much of a guess was right. */
    public static function isValid(mixed $expected, mixed $submitted): bool
    {
        return is_string($expected) && $expected !== '' && is_string($submitted) && hash_equals($expected, $submitted);
    }
}
```

- The token comes from `random_bytes()`, the cryptographically secure generator (below).
- `hash_equals()` compares two strings in **constant time**. `===` stops at the first differing byte, so in principle
  the response time tells an attacker how many leading characters of a guess were right, letting them find a secret
  one character at a time. Use `hash_equals()` whenever you compare a secret (tokens, signatures, API keys).
- Both values are checked to be strings: `$_POST['_csrf']` can be an array (chapter 16), and an empty expected token
  must never match an empty submission.
- The token is checked in **one place** for every POST, before any page runs (Bookshelf's front controller, below),
  so a new form cannot forget it. Frameworks do the same in a middleware.

JSON APIs called by your own JavaScript send the token in a header instead (`X-CSRF-Token`), read from a `<meta>` tag
in the page. APIs authenticated by an `Authorization: Bearer ...` header instead of cookies are not exposed to CSRF at
all, because browsers never attach that header on their own.

Modern browsers also tell you where a request came from. The `Sec-Fetch-Site` header is `same-origin` for requests
from your own pages and `cross-site` for requests started by another site, and `Origin` names the site for POSTs.
Rejecting POSTs whose `Sec-Fetch-Site` is `cross-site` is a cheap extra layer:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'cross-site') {
    http_response_code(403);
    exit('Cross-site form submissions are not allowed');
}
```

## Passwords

### Storing them: `password_hash()`

A database of passwords will leak someday: a backup on the wrong server, an SQL injection, an employee's laptop. What
matters then is what the attacker can do with it. Never store passwords in plain text, encrypted (the key leaks with
them) or with a fast hash such as `md5()` or `sha256` (a graphics card tries billions of those per second, so most real
passwords fall in hours). Store them with a **password hashing function**: deliberately slow, and with a random
**salt** per password so that identical passwords get different hashes and precomputed tables are useless.

PHP's password API does all of that:

```php
<?php

declare(strict_types=1);

$hash = password_hash('correct horse battery staple', PASSWORD_DEFAULT);
echo $hash, "\n";

var_dump(password_verify('correct horse battery staple', $hash));
var_dump(password_verify('Correct horse battery staple', $hash));
print_r(password_get_info($hash));
```

```text
$2y$12$V/pPkwZZAoi6uI8FdErXGeJWrGFOvMc2XRVVzFLyS8nSyTGGj4YG2
bool(true)
bool(false)
Array
(
    [algo] => 2y
    [algoName] => bcrypt
    [options] => Array
        (
            [cost] => 12
        )

)
```

(The hash is different on every run, because the salt is random.) The string contains everything needed to verify it
later:

```text
$2y$12$V/pPkwZZAoi6uI8FdErXGe JWrGFOvMc2XRVVzFLyS8nSyTGGj4YG2
 │   │  └── 22 characters ──┘ └──────── 31 characters ──────┘
 │   │       the salt                    the hash itself
 │   └── cost: 2^12 rounds of the key setup
 └── algorithm: bcrypt
```

- `PASSWORD_DEFAULT` is **bcrypt** today, and may become a stronger algorithm in a future PHP version. Store hashes in a
  column that can hold 255 characters, not exactly 60.
- **PHP 8.4+** raised bcrypt's default cost from 10 to 12, making each hash about four times slower to compute
  (a few hundred milliseconds, depending on the server). That cost is the point: a login waits once; an attacker
  testing billions of guesses waits billions of times.
- `password_verify()` reads the algorithm, cost and salt from the stored hash, and compares in constant time.
- `PASSWORD_ARGON2ID` (Argon2id, the winner of the Password Hashing Competition) is available when PHP is built with
  libargon2 or libsodium; it is also memory-hard, which slows down specialized cracking hardware further. Its hashes
  look like `$argon2id$v=19$m=65536,t=4,p=1$...`. Bcrypt with cost 12 or more remains a sound choice.

### Upgrading hashes

When the default algorithm or cost changes (an upgrade to PHP 8.4 turns cost 10 into cost 12), old hashes keep
working, and **`password_needs_rehash()`** tells you which ones to upgrade. The only time you can do that is at login,
when you have the plain password:

```php
if (password_verify($password, $member->passwordHash)) {
    if (password_needs_rehash($member->passwordHash, PASSWORD_DEFAULT)) {
        $member->changePasswordHash(password_hash($password, PASSWORD_DEFAULT));
        $members->save($member);
    }
    // ... log in
}
```

Applications that once used `md5()` can migrate without waiting for users: hash the existing md5 values with
`password_hash(md5_value)`, mark them, and at the next login verify with `password_verify(md5($password), $hash)` and
store a normal hash.

### Bcrypt's limits

Bcrypt reads at most **72 bytes** of the password, and cannot handle a NUL byte:

```php
<?php

declare(strict_types=1);

$hash = password_hash(str_repeat('a', 72) . 'X', PASSWORD_BCRYPT);
var_dump(password_verify(str_repeat('a', 72) . 'a completely different ending', $hash));

try {
    password_hash("abc\0def", PASSWORD_BCRYPT);
} catch (ValueError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
```

```text
bool(true)
ValueError: Bcrypt password must not contain null character
```

Everything after byte 72 is ignored, so two long passwords that share their first 72 bytes are the same password. For
passphrases in plain English, 72 bytes is a lot; with accents or emoji (two to four bytes each), it's fewer
characters. Either reject passwords over 72 bytes with a clear message (Bookshelf does), or use Argon2id, which has no
such limit. Don't "fix" it by hashing the password with SHA-256 first and passing the raw binary output to bcrypt:
binary output can contain NUL bytes. (If you pre-hash, encode the result in base64.)

### Password rules

Current guidance (NIST SP 800-63B, revision 4 from 2025) is simpler than the rules many sites still use:

- Require a **minimum length**: 15 characters when the password alone protects the account, 8 when it is one of two
  factors. Allow long passwords and passphrases (at least 64 characters). Count characters with `mb_strlen()`, not
  bytes.
- **Don't** require character classes ("one uppercase, one digit, one symbol"); they produce `Password1!`.
- **Don't** force periodic changes; require a change when there is evidence of compromise.
- **Do** reject passwords known from breaches. The Have I Been Pwned "Pwned Passwords" API supports this without
  sending the password: you send the first five characters of its SHA-1 hash and compare the returned suffixes locally.
- Allow paste and password managers. Never truncate silently, and never trim passwords: spaces are characters.

### Keeping passwords out of logs and traces

A stack trace prints the arguments of every function call in it, and those traces end up in logs and error trackers.
Mark parameters that hold secrets with **`#[\SensitiveParameter]`** (PHP 8.2+), and PHP replaces their values in
traces:

```php
<?php

declare(strict_types=1);

function connect(string $user, #[\SensitiveParameter] string $password): void
{
    throw new RuntimeException('Connection refused');
}

connect('bookshelf', 'hunter2-but-longer');
```

```text
PHP Fatal error:  Uncaught RuntimeException: Connection refused in connect.php:7
Stack trace:
#0 connect.php(10): connect('bookshelf', Object(SensitiveParameterValue))
#1 {main}
  thrown in connect.php on line 7
```

PHP's own functions that take passwords, such as `password_hash()`, `password_verify()` and `PDO::__construct()`, are
already marked. Mark your own: anything that receives a password, a token or a key.

## Authentication and sessions

**Authentication** answers "who is this?"; the session then remembers the answer. A login handler done right:

```text
POST /login (email, password, CSRF token)
  1. CSRF token valid?                                   no  ─▶ 403
  2. Too many recent failures for this account or IP?   yes ─▶ 429 "try again later"
  3. Look up the member; password_verify()               fail ─▶ record failure; "e-mail or password is wrong"
  4. password_needs_rehash()? Upgrade the hash.
  5. session_regenerate_id(true); store the member's id in the session
  6. 303 redirect to the page they wanted (if it is a local path)
```

### Don't reveal which accounts exist

"No account with that e-mail" and "wrong password" are different messages, and the difference tells an attacker which
e-mail addresses are registered (**user enumeration**), which helps phishing and targeted guessing. Give one message
for both. Response times can leak the same thing: for an unknown address, the code would skip the slow
`password_verify()` and answer in a millisecond instead of a few hundred. Bookshelf verifies against a dummy
hash in that case, so both paths take the same time. Registration and "forgot password" pages need the same care:
"if this address is registered, we've sent you an e-mail".

### Throttle guessing

Without a limit, an attacker can try thousands of passwords per minute against an account, or one common password
against thousands of accounts. Count recent failures **per account** and **per IP address**, and refuse attempts above
a threshold for a while (Bookshelf: 5 per account and 20 per address in 15 minutes). Per-account limits alone let
anyone lock a known user out by failing on purpose, so keep the window short, prefer delays or a CAPTCHA over long
lockouts, and alert on unusual volumes. Behind a load balancer or proxy, `REMOTE_ADDR` is the proxy's address; read the
client's address from the proxy's header (`X-Forwarded-For`) **only** when the request really came from your proxy,
since any client can send that header (chapter 22).

### Session fixation, timeouts and logout

- **Regenerate the session ID when privileges change**: at login, at logout, and when a user becomes an admin.
  `session_regenerate_id(true)` moves the session data to a new ID and deletes the old session. Without it, an attacker
  who planted a session ID in the victim's browser before login (**session fixation**) shares the logged-in session.
  `session.use_strict_mode` (chapter 16) closes the most common way of planting one, by refusing IDs PHP did not create.
- **Time out idle sessions.** Store the time of the last request in the session and log out after a period without
  one (Bookshelf: 30 minutes). `session.gc_maxlifetime` deletes old session files eventually, but it is a cleanup
  setting, not a security guarantee. For sensitive applications, add an absolute limit too (log in again after 12 hours),
  and ask for the password again before dangerous actions (changing the e-mail address or password).
- **Log out properly**: empty `$_SESSION` and regenerate (or destroy) the session, so the old ID is useless. And make
  logout a POST with a CSRF token, or other sites can log your users out.
- Session cookies are `HttpOnly`, `Secure` and `SameSite=Lax` (chapter 16). With HTTPS everywhere, a cookie name with
  the `__Host-` prefix (`session_name('__Host-bookshelf')`) makes browsers enforce `Secure`, `Path=/` and no `Domain`,
  so a subdomain cannot overwrite it.

### "Remember me" and other factors

A "remember me" cookie must not contain the user id or the password hash. Store a random token in the cookie and only
its hash (`hash('sha256', $token)`) in a database table with the user id and an expiry, exactly like the password reset
tokens of exercise 4; delete it at logout and when the password changes. For accounts that matter (staff, admins),
add a second factor: TOTP codes from an authenticator app (libraries such as `spomky-labs/otphp`), or passkeys
(WebAuthn, with a library such as `web-auth/webauthn-lib`). Frameworks ship all of this ready-made (chapter 23).

## Authorization

**Authorization** answers "may this user do this?", and it must be checked on the server, **on every request**, for
every object. Hiding a button is not authorization; the URL still works.

The most common authorization bug has a name, **insecure direct object reference** (IDOR): `/loans/42` shows loan 42
to anyone logged in, because the code checks *that* someone is logged in, not *who*. Change the number, see someone
else's loans. The fixes:

- **Scope queries to the user**: `SELECT ... FROM loans WHERE id = ? AND member_id = ?`, with the member id taken from
  the session, never from a parameter. Bookshelf's "My loans" page takes no id at all.
- **Check ownership** after loading: `if ($loan->memberId !== $member->id && !$member->isStaff()) { 403 }`.
- **Deny by default**: pages that need a role check it first thing, before loading anything. A central list of which
  routes need which role (chapter 19 moves this into the router) is easier to review than checks scattered through
  handlers.

Respond with `403 Forbidden` when a logged-in user lacks permission (or `404 Not Found` if you don't want to reveal
that the object exists), and redirect to the login page when nobody is logged in.

A related bug is **mass assignment**: updating a record from the whole request, `$member->update($_POST)`, lets a user
add `type=staff` to the form. Copy only the fields the form is meant to change, as Bookshelf's handlers do with
`stringParam()`.

## Other web vulnerabilities

### Open redirects

"Redirect back to where the user wanted to go" is a common feature: `/login?next=/loans`. If `next` can be any URL,
`https://bookshelf.example/login?next=https://evil.example/login` is a link on *your* domain that delivers the user,
right after a real login, to a lookalike page asking them to "log in again". Only redirect to **local paths**:

```php
<?php

declare(strict_types=1);

/** $target if it is a path on this site, otherwise '/'. */
function localPath(string $target): string
{
    // One slash, not followed by another slash or a backslash, and no control characters.
    return preg_match('~^/(?![/\\\\])[^\x00-\x1F\x7F]*$~', $target) === 1 ? $target : '/';
}

foreach (['/loans', '/?q=dune', '//evil.example', '/\evil.example', "/\t/evil.example", 'https://evil.example'] as $t) {
    printf("%-26s -> %s\n", json_encode($t, JSON_UNESCAPED_SLASHES), localPath($t));
}
```

```text
"/loans"                   -> /loans
"/?q=dune"                 -> /?q=dune
"//evil.example"           -> /
"/\\evil.example"          -> /
"/\t/evil.example"         -> /
"https://evil.example"     -> /
```

"Starts with a slash" is not enough: browsers treat `//evil.example` as "same scheme, other host", read a backslash as
a slash, and silently remove tabs and newlines from URLs, so `"/\t/evil.example"` becomes `//evil.example`.

### Server-side request forgery (SSRF)

When your server fetches a URL that a user supplied (an "import book cover from URL" feature, a webhook, a link
preview), the user can point it at things only your server can reach: `http://localhost:8080/admin`, the database
port, or the cloud metadata service at `169.254.169.254`, which hands out the server's cloud credentials. Defenses:

- Allow only `http`/`https`, and if possible only a list of known hosts.
- Resolve the host name and refuse private and reserved addresses:

```php
<?php

declare(strict_types=1);

foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '::1', '93.184.215.14'] as $ip) {
    $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    printf("%-16s %s\n", $ip, $public ? 'allowed' : 'blocked');
}
```

```text
127.0.0.1        blocked
10.0.0.5         blocked
169.254.169.254  blocked
::1              blocked
93.184.215.14    allowed
```

- Connect to the IP address you checked, not the name again (a DNS server controlled by the attacker can answer
  differently the second time: "DNS rebinding"), and don't follow redirects automatically, since each one is a new URL
  to check.
- Limit the response size and the time allowed.

### Uploaded files

Chapter 16's `storeCover()` already does the essentials: check the error code, check the size, detect the type from the
content with `finfo`, generate the name, and store the file outside the document root. Some additions for real
applications:

- **Images can carry more than pixels.** A file can be a valid image and valid HTML or a valid PHP archive at the same
  time ("polyglot" files). Re-encoding it with GD or Imagick (`imagecreatefromstring()` then `imagepng()`) keeps only
  the pixels and drops embedded metadata such as GPS coordinates.
- **Check the dimensions before decoding.** A small compressed file can expand to gigabytes of pixels (a
  "decompression bomb"). `getimagesize()` reads the dimensions from the header without decoding the image.
- **Serve user files carefully.** Send the type you detected, `X-Content-Type-Options: nosniff` (so the browser
  doesn't second-guess it), and for anything that isn't an image, `Content-Disposition: attachment` so it downloads
  instead of opening as a page on your domain. Even better, serve user uploads from a separate domain.
- **Scan** uploads that other people will download (ClamAV), and limit how many a user can upload.

## Secrets and configuration

Database passwords, API keys and encryption keys are **secrets**. Rules:

- **Never in the repository.** Git history is forever, repositories get shared, and secret scanners find keys in
  public code within minutes. Put an example file (`.env.example`, with fake values) in git and the real one in
  `.gitignore`.
- **Read them from the environment** (`getenv('BOOKSHELF_DB_PASSWORD')`), set by the deployment system, the container
  platform or the process manager, or from files that only the application's user can read (Docker and Kubernetes
  "secrets" are files). Chapter 22 shows both.
- **Never in the document root.** A `config.ini` or `.env` file in `public/` can be downloaded. A `config.php` that
  *returns* an array is safe only as long as the server executes PHP; if a misconfiguration ever serves it as text,
  the secrets are out. Keep configuration one directory up, where Bookshelf keeps everything except `public/`.
- **Not in logs, error pages or URLs.** URLs end up in server logs, browser history and `Referer` headers.
- **Different secrets per environment**, and a plan for **rotating** them (changing them without downtime) when
  someone leaves or a leak is suspected.

> **Just a taste — you don't need to learn or fully understand this now.** Many projects load a `.env` file in
> development with a library; chapter 22 covers configuration properly.

```php
// composer require vlucas/phpdotenv
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();                                   // fine if the file doesn't exist (production)
$dotenv->required(['BOOKSHELF_DATABASE'])->notEmpty();
```

### Production settings

A few `php.ini` settings matter for security (chapter 22 has the full production configuration):

| Setting | Production value | Why |
|---|---|---|
| `display_errors` | `Off` | Error messages show file paths, SQL and sometimes values to visitors. Log them instead |
| `log_errors` | `On` | ... to a log that people actually read |
| `expose_php` | `Off` | Removes the `X-Powered-By: PHP/8.5.x` header, which advertises your exact version |
| `allow_url_include` | `Off` (the default; deprecated since 7.4) | Stops `include 'http://...'` |
| `session.use_strict_mode`, `session.cookie_httponly`, `session.cookie_secure`, `session.cookie_samesite` | `1`, `1`, `1`, `Lax` | Safe session cookies for every session, even one started without options |
| `upload_max_filesize`, `post_max_size` | As small as the application allows | Limits resource abuse |

`disable_functions` (to forbid `exec()` and friends) and `open_basedir` (to confine file access) are sometimes
recommended. They add friction for an attacker, but they are not security boundaries that PHP guarantees; isolate
applications with separate users, containers or servers instead.

### Security headers

Besides the CSP, a few response headers turn on browser protections:

| Header | Value | Effect |
|---|---|---|
| `Content-Security-Policy` | See above | Restricts scripts and other sources; `frame-ancestors` stops clickjacking |
| `Strict-Transport-Security` | `max-age=31536000` | The browser uses HTTPS only for this site for a year, even if a link says `http://`. Send it only over HTTPS, once HTTPS works everywhere |
| `X-Content-Type-Options` | `nosniff` | The browser trusts your `Content-Type` instead of guessing from the content |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Other sites see only your origin in `Referer`, not full URLs (which can contain tokens) |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | Turns off browser features the site doesn't use |

They can be sent by PHP (Bookshelf does, in `sendSecurityHeaders()`) or added by the web server for every response,
static files included.

### Dependencies

Your application includes everyone else's code. Check it:

```bash
composer audit          # checks composer.lock against the PHP security advisory database
```

```text
No security vulnerability advisories found.
```

Run `composer audit` in CI, keep dependencies updated (`composer outdated` lists what's behind), and prefer
well-maintained packages over abandoned ones. The same goes for PHP itself: each minor version gets two years of bug
fixes and then two years of security fixes only (see [php.net/supported-versions](https://www.php.net/supported-versions.php));
running an unsupported version means known holes that will never be fixed.

## Randomness and cryptography

### Secure random values

Tokens, session IDs, password reset links, CSRF secrets and keys must be **unpredictable**. Use the functions backed by
the operating system's cryptographically secure generator:

```php
<?php

declare(strict_types=1);

echo bin2hex(random_bytes(16)), "\n";              // 32 hex characters: a token
echo random_int(100000, 999999), "\n";             // a uniformly distributed number, e.g. a one-time code

$randomizer = new Random\Randomizer();              // PHP 8.2+; uses the secure engine by default
echo $randomizer->getBytesFromString('ABCDEFGHJKMNPQRSTUVWXYZ23456789', 8), "\n";   // PHP 8.3+: no 0/O, 1/I/L
echo implode(', ', $randomizer->shuffleArray(['Dune', 'Emma', 'Kindred'])), "\n";
```

```text
b6f1c0e9a4d27f5813ce0a9d4f6b2e71
483920
K7QXN3MT
Emma, Dune, Kindred
```

(Different on every run.)

| Never for security | Why | Use instead |
|---|---|---|
| `rand()`, `mt_rand()`, `shuffle()`, `array_rand()`, `str_shuffle()` | Mersenne Twister: fast, but its output can be predicted after observing some values | `random_int()`, `Randomizer` |
| `uniqid()` | Based on the current time: unique-ish, not secret | `bin2hex(random_bytes(16))` |
| `lcg_value()` | Weak, and deprecated since PHP 8.4 | `Randomizer::getFloat()` (8.3+) |
| `md5(time())`, `sha1(microtime())` | Hashing a guessable value keeps it guessable | `random_bytes()` |

The non-secure engines still have a use: a **seeded** randomizer gives the same sequence every time, which is what tests
and reproducible simulations need.

```php
<?php

declare(strict_types=1);

$randomizer = new Random\Randomizer(new Random\Engine\Mt19937(42));   // seed 42: the same sequence every run
echo $randomizer->getInt(1, 100), ' ', $randomizer->getInt(1, 100), "\n";
```

```text
43 68
```

### Hashes and HMACs

A **cryptographic hash** (`hash('sha256', $data)`) is a fixed-size fingerprint: any change to the data changes the hash,
and the data cannot be recovered from it. Good for checking that a download is intact, deduplicating files, or storing
a random token in a database so that a database leak doesn't leak working tokens (exercise 4). **Not** for passwords,
which are guessable and need `password_hash()`.

A hash alone doesn't prove *who* made it: an attacker who changes the data can recompute the hash. An **HMAC** mixes in
a secret key, so only someone with the key can produce a valid one. That is how to make a value **tamper-proof** when it
travels through the client, such as a cookie or a link:

```php
<?php

declare(strict_types=1);

$key = 'a-long-random-secret-from-the-environment';

function sign(string $value, #[\SensitiveParameter] string $key): string
{
    return $value . '.' . hash_hmac('sha256', $value, $key);
}

function verify(string $signed, #[\SensitiveParameter] string $key): ?string
{
    $dot = strrpos($signed, '.');
    if ($dot === false) {
        return null;
    }
    $value = substr($signed, 0, $dot);

    return hash_equals(hash_hmac('sha256', $value, $key), substr($signed, $dot + 1)) ? $value : null;
}

$cookie = sign('theme=dark', $key);
echo $cookie, "\n";
var_dump(verify($cookie, $key));
var_dump(verify(str_replace('dark', 'evil', $cookie), $key));
```

```text
theme=dark.378d2618d69c44aa433ca3008d2496fdfa40176dca78aa6329be85ea5cceee77
string(10) "theme=dark"
NULL
```

A signed value is **not secret**: anyone can read `theme=dark`. Signing proves it hasn't changed; to hide the content,
encrypt it. Include an expiry time in anything signed that shouldn't be valid forever (exercise 2), and use separate
keys for separate purposes.

### Encryption with sodium

To store something you need to read back (a member's phone number, an API token for another service), you need
**encryption**. Don't build it from primitives: choosing modes, nonces and authentication correctly is where homemade
encryption fails. PHP bundles **sodium** (libsodium, since PHP 7.2), a library designed to be hard to misuse.
`sodium_crypto_secretbox()` encrypts *and* authenticates with one secret key:

```php
<?php

declare(strict_types=1);

$key = sodium_crypto_secretbox_keygen();    // 32 random bytes; generate once, store as a secret
// e.g. echo base64_encode($key);  and later  $key = base64_decode(getenv('BOOKSHELF_ENCRYPTION_KEY'));

function encrypt(string $plaintext, #[\SensitiveParameter] string $key): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);    // 24 bytes, new for every message

    return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
}

function decrypt(string $encoded, #[\SensitiveParameter] string $key): string
{
    $decoded = base64_decode($encoded, true);
    if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RuntimeException('Malformed ciphertext');
    }
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);

    return $plaintext !== false ? $plaintext : throw new RuntimeException('Decryption failed: wrong key or tampered data');
}

$stored = encrypt('+49 30 1234567', $key);
echo strlen($stored), " characters stored\n";
echo decrypt($stored, $key), "\n";

$tampered = base64_encode(substr_replace(base64_decode($stored), 'X', 30, 1));
try {
    decrypt($tampered, $key);
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
72 characters stored
+49 30 1234567
Decryption failed: wrong key or tampered data
```

- The **nonce** ("number used once") must never repeat with the same key. 24 random bytes make a repeat practically
  impossible, which is why secretbox's nonce size is that large. It isn't secret; it's stored with the ciphertext.
- The ciphertext includes a 16-byte **authentication tag**, so any change is detected and decryption fails. Encryption
  without authentication (plain AES-CBC, as old tutorials show with `openssl_encrypt()`) lets attackers modify data
  they cannot read.
- Encryption moves the problem to the **key**: store it like any other secret, never next to the data, and plan for
  rotation (keep a key id with each ciphertext so old data can be decrypted with the old key and re-encrypted).
- Encrypted data can't be searched or indexed. If you must look records up by an encrypted value, store an HMAC of it
  alongside ("blind index").

Sodium also covers the other common needs: public-key encryption (`sodium_crypto_box_seal()`), signatures
(`sodium_crypto_sign_detached()`), and authenticated encryption with additional data (`sodium_crypto_aead_*`). The
`openssl_*` functions exist too and are needed for TLS certificates and interoperating with other systems, but they
offer more ways to get things wrong. For higher-level APIs, libraries such as `paragonie/halite` wrap sodium further.

> **Just a taste — you don't need to learn or fully understand this now.** A signature proves who sent a message; anyone
> with the public key can verify it, only the secret key can sign.

```php
$pair = sodium_crypto_sign_keypair();
$signature = sodium_crypto_sign_detached('loan 42 returned', sodium_crypto_sign_secretkey($pair));
var_dump(sodium_crypto_sign_verify_detached($signature, 'loan 42 returned', sodium_crypto_sign_publickey($pair)));
```

## Bookshelf: logins and protections

Bookshelf now has member logins, a "My loans" page, a staff-only page listing the suggestions visitors sent, CSRF
protection on every form, and security headers on every response. The new and changed files:

```text
examples/bookshelf/
  migrations/003_create_login_attempts.sql   failed logins, for throttling
  src/Security/
    Passwords.php            the password policy and password_hash()
    Authenticator.php        e-mail and password check, rehashing, constant-time unknown accounts
    AuthenticationFailed.php
    LoginThrottle.php        failures per account and per IP address
    Csrf.php
  src/Cli/Command/SetPasswordCommand.php     bookshelf member:password
  src/Domain/BookRepository.php              + findByIds() (chapter 17's exercise 1)
  public/index.php           security headers, CSRF check, login/logout, /loans, /suggestions
  templates/login.php, loans.php, suggestions.php, message.php; layout.php shows the member
```

### The password policy

```php
final class Passwords
{
    public const int MIN_LENGTH = 15;

    /** Bcrypt, PHP's default algorithm, ignores everything after the first 72 bytes. */
    public const int MAX_BYTES = 72;

    /** Why $password is not acceptable as a new password, or null if it is. */
    public static function problemWith(#[\SensitiveParameter] string $password): ?string
    {
        return match (true) {
            !mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")
                => 'The password contains invalid characters',
            mb_strlen($password) < self::MIN_LENGTH
                => sprintf('Use at least %d characters', self::MIN_LENGTH),
            strlen($password) > self::MAX_BYTES
                => sprintf('Use at most %d bytes (fewer characters if you use accents or emoji)', self::MAX_BYTES),
            default => null,
        };
    }

    /** @throws InvalidArgumentException if the password breaks the policy */
    public static function hash(#[\SensitiveParameter] string $password): string
    {
        $problem = self::problemWith($password);
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }
}
```

Members get a password from the command line. The new `member:password` command reads it from standard input, never
from an argument (arguments are visible in the shell history and to other users in the process list). In a terminal
it turns off the echo with `stty -echo`, so the password isn't shown while typed:

```php
private function readPassword(Output $output): string
{
    $stdin = $this->stdin ?? STDIN;
    $interactive = stream_isatty($stdin) && PHP_OS_FAMILY !== 'Windows';

    if ($interactive) {
        $output->prompt('New password: ');
        shell_exec('stty -echo');            // stop the terminal from showing what is typed
    }
    try {
        $line = fgets($stdin);
    } finally {
        if ($interactive) {
            shell_exec('stty echo');
            $output->info('');
        }
    }

    return $line === false ? '' : rtrim($line, "\r\n");
}
```

### The authenticator

```php
final readonly class Authenticator
{
    /**
     * A bcrypt hash of a random string nobody knows. It is verified when the e-mail address is unknown, so that the
     * answer takes as long as for a real account and response times don't reveal which addresses are registered.
     */
    private const string DUMMY_HASH = '$2y$12$ah.zwSRfP.qohIDyoLUUu.vhgssNeI8H7WjEqE4KapObfr9iV4QNi';

    public function __construct(
        private MemberRepository $members,
        private LoginThrottle $throttle,
    ) {
    }

    /** @throws AuthenticationFailed */
    public function authenticate(string $email, #[\SensitiveParameter] string $password, string $address): Member
    {
        if ($this->throttle->isBlocked($email, $address)) {
            throw AuthenticationFailed::tooManyAttempts();
        }

        $member = $this->members->findByEmail($email);
        $hash = $member?->passwordHash;
        $matches = password_verify($password, $hash ?? self::DUMMY_HASH);

        if ($member === null || $hash === null || !$matches) {
            $this->throttle->recordFailure($email, $address);
            throw AuthenticationFailed::invalidCredentials();
        }

        $this->throttle->clear($email);

        // Hashes made with older settings (a lower cost, an older algorithm) are upgraded at the next login,
        // the only moment the plain password is available.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $member->changePasswordHash(password_hash($password, PASSWORD_DEFAULT));
            $this->members->save($member);
        }

        return $member;
    }
}
```

`password_verify()` always runs, against the real hash or the dummy, so a wrong password, an unknown address and a
member without a password all take the same time and produce the same message. `LoginThrottle` stores each failure
twice, as `account:<e-mail>` and `address:<IP>`, with a Unix timestamp from the `Clock`, and counts the rows inside the
15-minute window:

```php
public function isBlocked(string $email, string $address): bool
{
    $count = $this->pdo->prepare('SELECT count(*) FROM login_attempts WHERE subject = ? AND attempted_at > ?');
    $since = $this->now() - $this->windowSeconds;

    $count->execute([self::account($email), $since]);
    if ((int) $count->fetchColumn() >= $this->maxPerAccount) {
        return true;
    }
    $count->execute([self::address($address), $since]);

    return (int) $count->fetchColumn() >= $this->maxPerAddress;
}
```

### The front controller

Every response gets the security headers, and every POST is checked for the CSRF token before the router runs:

```php
sendSecurityHeaders();

// Every POST must carry the session's CSRF token. Checking here, before any page runs, means a new form
// cannot forget it.
if ($method === 'POST' && !Csrf::isValid(sessionValue('csrf'), $_POST['_csrf'] ?? null)) {
    message('Form expired', 'This form has expired. Please go back, reload the page and try again.', 403);
    return;
}

match ("$method $path") {
    'GET /', 'HEAD /' => showCatalog(),
    'GET /api/books' => apiBooks(),
    'GET /suggest' => showSuggestForm(),
    'POST /suggest' => handleSuggestion(),
    'GET /login' => showLoginForm(),
    'POST /login' => handleLogin(),
    'POST /logout' => handleLogout(),
    'GET /loans' => showMyLoans(),
    'GET /suggestions' => showSuggestions(),
    default => notFound(),
};
```

Forms include the token with a helper that templates call, like `e()`:

```php
/** The session's CSRF token, created on first use. */
function csrfToken(): string
{
    startSession();

    return $_SESSION['csrf'] ??= Csrf::generateToken();
}

/** The hidden field every POST form includes (with a line break, since PHP drops the one after a closing tag). */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . "\">\n";
}
```

```php
<form method="post" action="/suggest" class="stacked">
    <?= csrfField() ?>
    ...
```

Logging in and out regenerate the session ID; the CSRF token is renewed at login too:

```php
function logIn(Member $member): void
{
    startSession();
    session_regenerate_id(true);   // a new ID for the new privilege level; the old one stops working
    $_SESSION['member_id'] = $member->id;
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] = Csrf::generateToken();
}

function logOut(): void
{
    startSession();
    $_SESSION = [];                // forget everything ...
    session_regenerate_id(true);   // ... and continue with a new, empty session (it can still carry a flash message)
}
```

`currentMember()` reads the member id from the session and logs out after 30 idle minutes. The pages that need a login
call `requireMember()`, which redirects visitors to `/login?next=...` (the login page passes `next` through
`localPath()`). The staff page also checks the role, and the loans page shows only the logged-in member's loans,
with no id in the URL to change:

```php
function showMyLoans(): void
{
    $member = requireMember();
    if ($member === null) {
        return;
    }

    // The query is limited to this member's loans: the session decides whose loans are shown, never a parameter.
    $loans = loans()->openLoansOf($member->id);
    page('My loans', 'loans', [
        'loans' => $loans,
        'books' => books()->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
        'today' => new DateTimeImmutable('today', new DateTimeZone(LIBRARY_TIMEZONE)),
    ]);
}

function showSuggestions(): void
{
    $member = requireMember();
    if ($member === null) {
        return;
    }
    if (!$member->isStaff()) {
        message('Forbidden', 'This page is for library staff only.', 403);
        return;
    }
    // ... SELECT the suggestions and show them, every value through e()
}
```

### Trying it

From `examples/bookshelf`, after `db:migrate` (which applies the new migration) and `import`, give two members
passwords:

```bash
php bin/bookshelf db:migrate
php bin/bookshelf member:add "Grace Hopper" grace@example.org --type=staff
php bin/bookshelf member:password ada@example.org             # type it; nothing is shown
echo 'short' | php bin/bookshelf member:password grace@example.org
```

```text
applied 003_create_login_attempts.sql
1 migration applied
Added Grace Hopper as a Staff member with id 2
2
New password:
Password set for Ada Lovelace
Use at least 15 characters
```

Then, with `composer serve` running, a session with `curl` (which stores cookies in `jar` with `-c`/`-b`):

```bash
curl -i -X POST -d 'title=x&author=y' http://localhost:8000/suggest                # no token
curl -i http://localhost:8000/loans                                                  # not logged in
curl -s -c jar -b jar http://localhost:8000/login | grep _csrf                       # get a session and a token
curl -i -c jar -b jar -d "_csrf=$TOKEN" -d email=ada@example.org -d password=guess http://localhost:8000/login
curl -i -c jar -b jar -d "_csrf=$TOKEN" -d next=/loans -d email=ada@example.org \
     --data-urlencode 'password=correct horse battery staple' http://localhost:8000/login
```

```text
HTTP/1.1 403 Forbidden
...
<p>This form has expired. Please go back, reload the page and try again.</p>

HTTP/1.1 303 See Other
Location: /login?next=%2Floans

    <input type="hidden" name="_csrf" value="7f0a1e5d1e952e7c1ec9fc26a43641a6a48f0cd4b73cc680544bcaeba004f8c7">

HTTP/1.1 422 Unprocessable Entity
...
<p class="error">The e-mail address or the password is wrong.</p>

HTTP/1.1 303 See Other
Set-Cookie: PHPSESSID=4a9cfe6c26742f611e6bdb525100e574; path=/; HttpOnly; SameSite=Lax
Location: /loans
```

(`$TOKEN` is the value from the hidden field.) The successful login sets a **new** session cookie: the ID was
regenerated. Every response also carries the security headers:

```text
Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
```

What the other protections do, in the same session:

- `/loans` lists Ada's open loans; `/suggestions` answers `403 Forbidden` for her, and works for Grace.
- A suggestion titled `<script>alert("hi")</script>` appears on the staff page as
  `&lt;script&gt;alert(&quot;hi&quot;)&lt;/script&gt;`: visible text, not a script running in a librarian's browser.
- `/login?next=//evil.example` puts `next=/` in the form, so after logging in the browser goes to `/`.
- After five wrong passwords for an account, the next attempt answers `429 Too Many Requests` with "Too many failed
  attempts. Please wait a few minutes and try again.", even with the right password.
- A request with a made-up cookie such as `PHPSESSID=attacker-chosen-id` gets a fresh session ID from PHP
  (`use_strict_mode`), so a planted ID is never used.

## Key takeaways

- Treat everything that crosses a trust boundary as hostile. **Validate** input against your rules at the entrance,
  **escape** output for its context at the exit, and layer defenses so that one mistake isn't a breach.
- Injection is the same bug everywhere: data becoming code. Use placeholders for SQL, argument arrays instead of shell
  strings, allow lists for anything that selects code or files, and JSON instead of `unserialize()` for client data.
- XSS: `htmlspecialchars()` for HTML text and quoted attributes; a scheme allow list for URLs; `JSON_HEX_*` flags for
  data in scripts; a sanitizer for rich text; and a Content Security Policy behind all of it.
- CSRF: no state changes on GET, `SameSite` cookies, and a session token checked centrally on every POST with
  `hash_equals()`.
- Passwords: `password_hash()`/`password_verify()`/`password_needs_rehash()`, a length-based policy, bcrypt's 72-byte
  limit in mind, and `#[\SensitiveParameter]` on everything that carries a secret.
- Logins: one message for every failure, constant time for unknown accounts, throttling, `session_regenerate_id(true)`
  at login and logout, idle timeouts, logout by POST.
- Authorization on every request and every object: scope queries to the user, deny by default, never trust hidden
  buttons or ids from the client.
- Redirect only to local paths; check URLs your server fetches; keep secrets out of code, logs and the document root;
  turn off `display_errors`; send security headers; run `composer audit`.
- `random_bytes()`, `random_int()` and `Randomizer` for anything secret; HMAC to detect tampering; sodium for encryption.
  Never invent cryptography.

## Exercises

1. **Escape for the context.** This profile template has four injection points. Rewrite it safely, with helper
   functions as needed, and test it with the given hostile member.

   ```php
   <h2><?= $member['name'] ?></h2>
   <a href="<?= $member['website'] ?>">Website</a>
   <img src="/avatar.png" alt=<?= $member['name'] ?>>
   <script>const settings = <?= json_encode($member['settings'], JSON_UNESCAPED_SLASHES) ?>;</script>
   ```

   with `$member = ['name' => 'Eve" onmouseover="alert(1)', 'website' => ' javascript:alert(2)', 'settings' => ['theme' => '</script><script>alert(3)</script>']]`.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

use Uri\WhatWg\Url;

function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safeUrl(string $url): ?string
{
    $parsed = Url::parse($url);

    return $parsed !== null && in_array($parsed->getScheme(), ['http', 'https'], true) ? $parsed->toAsciiString() : null;
}

function jsonForScript(mixed $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

$member = [
    'name' => 'Eve" onmouseover="alert(1)',
    'website' => ' javascript:alert(2)',
    'settings' => ['theme' => '</script><script>alert(3)</script>'],
];
$website = safeUrl($member['website']);
?>
<h2><?= e($member['name']) ?></h2>
<?php if ($website !== null): ?>
<a href="<?= e($website) ?>" rel="nofollow noopener">Website</a>
<?php endif; ?>
<img src="/avatar.png" alt="<?= e($member['name']) ?>">
<script>const settings = <?= jsonForScript($member['settings']) ?>;</script>
```

```text
<h2>Eve&quot; onmouseover=&quot;alert(1)</h2>
<img src="/avatar.png" alt="Eve&quot; onmouseover=&quot;alert(1)">
<script>const settings = {"theme":"<\/script><script>alert(3)<\/script>"};</script>
```

The four fixes: `e()` for the text; a scheme check for the link (the leading space doesn't fool the WHATWG parser, and
the link is left out entirely); **quotes** around the attribute plus `e()`; and the `JSON_HEX_*` flags in the script.
`rel="nofollow noopener"` is good practice for user-supplied links: no search-engine credit for spam, and no access
to your page from the opened one.

</details>

2. **Expiring signed links.** Write `signedUrl(string $path, int $expires, string $key): string`, which appends
   `expires` and a `signature` (an HMAC over the path and expiry) to the query string, and
   `isValidSignedUrl(string $url, int $now, string $key): bool`. Show that the link works before it expires, fails
   after, and fails if someone edits the path or the expiry.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function signature(string $path, int $expires, #[\SensitiveParameter] string $key): string
{
    return hash_hmac('sha256', "$path\n$expires", $key);
}

function signedUrl(string $path, int $expires, #[\SensitiveParameter] string $key): string
{
    return $path . '?' . http_build_query(['expires' => $expires, 'signature' => signature($path, $expires, $key)]);
}

function isValidSignedUrl(string $url, int $now, #[\SensitiveParameter] string $key): bool
{
    $path = parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $expires = filter_var($query['expires'] ?? null, FILTER_VALIDATE_INT);
    $signature = $query['signature'] ?? null;

    return is_string($path) && is_int($expires) && is_string($signature)
        && $now <= $expires
        && hash_equals(signature($path, $expires, $key), $signature);
}

$key = 'another-long-random-secret';
$now = 1_791_720_000;
$url = signedUrl('/members/1/loan-history.csv', $now + 3600, $key);
echo $url, "\n";

var_dump(isValidSignedUrl($url, $now, $key));                                                 // valid
var_dump(isValidSignedUrl($url, $now + 7200, $key));                                          // expired
var_dump(isValidSignedUrl(str_replace('/members/1/', '/members/2/', $url), $now, $key));      // other member
var_dump(isValidSignedUrl(str_replace((string) ($now + 3600), (string) ($now + 999999), $url), $now + 7200, $key));
```

```text
/members/1/loan-history.csv?expires=1791723600&signature=4bf18409bbfd54a0e8e1dc3095da454190bf8d962a8a7ea2303f5f894641460a
bool(true)
bool(false)
bool(false)
bool(false)
```

The signature covers both the path and the expiry, with a separator that can't appear in either, so neither can be
changed without the key. The link needs no database lookup and can be checked by any server that has the key, which is
how "download links" in e-mails and pre-signed cloud storage URLs work. The current time is a parameter so that the
function is easy to test (chapter 15's clocks).

</details>

3. **Encrypt a column.** Wrap the sodium code from this chapter in a `final readonly class Encrypter` whose constructor
   takes a base64-encoded key (as it would come from an environment variable) and rejects keys of the wrong length.
   Add `encrypt()` and `decrypt()`, and show a round trip and a decryption with the wrong key.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

final readonly class Encrypter
{
    private string $key;

    public function __construct(#[\SensitiveParameter] string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(sprintf('The key must be %d bytes, base64-encoded', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        $minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($decoded === false || strlen($decoded) < $minimum) {
            throw new RuntimeException('Malformed ciphertext');
        }
        $plaintext = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return $plaintext !== false ? $plaintext : throw new RuntimeException('Decryption failed');
    }
}

$encrypter = new Encrypter(Encrypter::generateKey());
$stored = $encrypter->encrypt('+49 30 1234567');
echo $encrypter->decrypt($stored), "\n";

try {
    (new Encrypter(Encrypter::generateKey()))->decrypt($stored);
} catch (RuntimeException $e) {
    echo $e->getMessage(), "\n";
}

try {
    new Encrypter(base64_encode('too short'));
} catch (InvalidArgumentException $e) {
    echo $e->getMessage(), "\n";
}
```

```text
+49 30 1234567
Decryption failed
The key must be 32 bytes, base64-encoded
```

The key never appears in a stack trace (`#[\SensitiveParameter]`), and since the class is `readonly`, nothing can swap
it after construction. Checking the key length at startup turns a misconfigured environment variable into an immediate,
clear error instead of a confusing failure at the first decryption.

</details>

4. **Password reset tokens.** Using an in-memory SQLite table `password_resets (token_hash TEXT PRIMARY KEY, member_id
   INTEGER, expires_at INTEGER)`, write `createResetToken(PDO $pdo, int $memberId, int $now): string`, which stores
   only a SHA-256 hash of a random token valid for one hour and returns the token for the e-mail link, and
   `useResetToken(PDO $pdo, string $token, int $now): ?int`, which returns the member id once and only once, and only
   before expiry.

<details>
<summary>Solution</summary>

```php
<?php

declare(strict_types=1);

function createResetToken(PDO $pdo, int $memberId, int $now): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM password_resets WHERE member_id = ?')->execute([$memberId]);   // one at a time
    $pdo->prepare('INSERT INTO password_resets (token_hash, member_id, expires_at) VALUES (?, ?, ?)')
        ->execute([hash('sha256', $token), $memberId, $now + 3600]);

    return $token;
}

function useResetToken(PDO $pdo, #[\SensitiveParameter] string $token, int $now): ?int
{
    $statement = $pdo->prepare('DELETE FROM password_resets WHERE token_hash = ? RETURNING member_id, expires_at');
    $statement->execute([hash('sha256', $token)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return $row !== false && $now <= $row['expires_at'] ? $row['member_id'] : null;
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE password_resets (token_hash TEXT PRIMARY KEY, member_id INTEGER, expires_at INTEGER)');
$now = 1_791_720_000;

$token = createResetToken($pdo, 1, $now);
echo strlen($token), " characters, e.g. https://bookshelf.example/reset?token=...\n";
var_dump(useResetToken($pdo, $token, $now + 60));     // works
var_dump(useResetToken($pdo, $token, $now + 120));    // already used

$late = createResetToken($pdo, 1, $now);
var_dump(useResetToken($pdo, $late, $now + 7200));    // expired
var_dump(useResetToken($pdo, 'guess', $now));
```

```text
64 characters, e.g. https://bookshelf.example/reset?token=...
int(1)
NULL
NULL
NULL
```

Storing only the hash means that someone who reads the table (a backup, an SQL injection) gets nothing they can use.
SHA-256 is enough here, unlike for passwords, because the token is 256 random bits: there is nothing to guess.
Looking it up by hash also makes the comparison independent of timing, since the database compares hashes, not the
secret. `DELETE ... RETURNING` consumes the token in the same statement that reads it, so two simultaneous requests
can't both use it. After a successful reset, a real application also logs out the member's other sessions and sends
a "your password was changed" e-mail.

</details>

5. **Security review.** Find and fix every vulnerability in this script. There are at least seven.

   ```php
   <?php
   session_start();
   $prefs = unserialize($_COOKIE['prefs'] ?? '');
   if ($_GET['action'] == 'delete') {
       $pdo->exec("DELETE FROM books WHERE id = " . $_GET['id']);
   }
   if (md5($_POST['password'] ?? '') == $user['password_md5']) {
       $_SESSION['user'] = $user['id'];
   }
   echo "<h1>Results for " . $_GET['q'] . "</h1>";
   echo shell_exec("grep -i " . $_GET['q'] . " /srv/data/catalog.txt");
   header('Location: ' . $_GET['return']);
   ```

<details>
<summary>Solution</summary>

| Line | Problem | Fix |
|---|---|---|
| `unserialize($_COOKIE[...])` | Object injection | `json_decode($_COOKIE['prefs'] ?? '{}', true)`, and validate the result |
| `action=delete` via `$_GET` | State change on GET: CSRF by link or image; and no authorization check | POST only, with a CSRF token, and check that the user may delete books |
| `"... id = " . $_GET['id']` | SQL injection | `$pdo->prepare('DELETE FROM books WHERE id = ?')->execute([$id])` with `$id` validated as an int |
| `md5(...) == ...` | Fast unsalted hash; and `==` on strings that look like numbers (`'0e123...' == '0e456...'` is true) | `password_verify()`, with hashes from `password_hash()` |
| `$_SESSION['user'] = ...` | Session fixation | `session_regenerate_id(true)` before storing the user; strict mode and cookie flags in `session_start()` |
| `"Results for " . $_GET['q']` | Reflected XSS | `e($q)` |
| `shell_exec("grep -i " . ...)` | Command injection | Search in PHP (`str_contains(mb_strtolower(...))` over `file()`), or `proc_open(['grep', '-i', '--', $q, $file], ...)` |
| `header('Location: ' . $_GET['return'])` | Open redirect | `header('Location: ' . localPath($return))`, then `exit` |

Also: no `declare(strict_types=1)`, `$_GET['action']` read without `?? ''` (a warning when it's missing), and no `exit`
after the redirect header, so the rest of the script would still run. The `==` comparison deserves its own mention:
`md5('240610708')` is `0e462097431906509019562988736854` and `md5('QNKCDZO')` is `0e830400451993494058024219903391`.
Both strings look like numbers in scientific notation (zero times ten to some power), and `==` compares two numeric
strings as numbers, so `md5('240610708') == md5('QNKCDZO')` is `true`, in PHP 8 as before. Always use `hash_equals()`
or `password_verify()` for secrets.

</details>

---
← Previous: [Databases with PDO](17-databases-with-pdo.md) | Next: [Building an app without a framework](19-building-an-app-without-a-framework.md) →
