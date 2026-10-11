<?php

declare(strict_types=1);

/*
 * Bookshelf's first web front controller (chapter 16; chapter 17 moved the data into the database,
 * chapter 18 added logins, CSRF protection and security headers).
 * Every request to the site runs this file.
 * Chapter 19 replaces this single file with a router, request/response objects and controllers.
 */

use Bookshelf\Domain\Book;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\Member;
use Bookshelf\Persistence\Database;
use Bookshelf\Persistence\PdoBookRepository;
use Bookshelf\Persistence\PdoLoanRepository;
use Bookshelf\Persistence\PdoMemberRepository;
use Bookshelf\Security\AuthenticationFailed;
use Bookshelf\Security\Authenticator;
use Bookshelf\Security\Csrf;
use Bookshelf\Security\LoginThrottle;
use Bookshelf\Support\SystemClock;
use Uri\Rfc3986\Uri;

// With PHP's built-in server, let it serve existing files (CSS, images) itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

const ROOT = __DIR__ . '/..';
const LIBRARY_TIMEZONE = 'Europe/Berlin';
const SESSION_IDLE_SECONDS = 30 * 60;   // log out after 30 minutes without a request

// ---------------------------------------------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------------------------------------------

/** Escapes text for HTML. Every value printed into a template goes through this. */
function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
        'member' => currentMember(),
        'flash' => takeFlash(),
        'content' => render($template, $variables),
    ]);
}

function message(string $title, string $message, int $status): void
{
    page($title, 'message', ['message' => $message], $status);
}

function json(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function redirect(string $location): void
{
    http_response_code(303);   // "See Other": the browser follows with a GET
    header("Location: $location");
}

/**
 * $target if it is a path on this site, otherwise '/'. Used for "?next=" parameters, so that a link such as
 * /login?next=https://evil.example cannot send visitors to another site after they log in (an "open redirect").
 */
function localPath(string $target): string
{
    // One slash, not followed by another slash or a backslash (browsers read "//host" and "/\host" as another
    // host), and no control characters (browsers drop tabs and newlines, which would turn "/\t/host" into "//host").
    return preg_match('~^/(?![/\\\\])[^\x00-\x1F\x7F]*$~', $target) === 1 ? $target : '/';
}

function isHttps(): bool
{
    return ($_SERVER['HTTPS'] ?? 'off') !== 'off';
}

/** Headers that tell the browser to enforce extra protections on every page. */
function sendSecurityHeaders(): void
{
    header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'none'; "
        . "form-action 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => isHttps(),
            'use_strict_mode' => true,
        ]);
    }
}

/** A value from the session, without creating a session for visitors who don't have one. */
function sessionValue(string $key): mixed
{
    if (!isset($_COOKIE[session_name()])) {
        return null;
    }
    startSession();

    return $_SESSION[$key] ?? null;
}

function flash(string $message): void
{
    startSession();
    $_SESSION['flash'] = $message;
}

function takeFlash(): ?string
{
    $message = sessionValue('flash');
    if ($message !== null) {
        unset($_SESSION['flash']);
    }

    return $message;
}

// ---------------------------------------------------------------------------------------------------------------
// CSRF protection and logins
// ---------------------------------------------------------------------------------------------------------------

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

/** The logged-in member, or null. Also enforces the idle timeout. */
function currentMember(): ?Member
{
    $id = sessionValue('member_id');
    if (!is_int($id)) {
        return null;
    }
    if (time() - (int) ($_SESSION['last_seen'] ?? 0) > SESSION_IDLE_SECONDS) {
        logOut();
        return null;
    }
    $_SESSION['last_seen'] = time();

    try {
        return members()->get($id);
    } catch (EntityNotFound) {
        logOut();               // the account was deleted meanwhile
        return null;
    }
}

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

/** Sends visitors who are not logged in to the login page, and returns null; otherwise returns the member. */
function requireMember(): ?Member
{
    $member = currentMember();
    if ($member === null) {
        redirect('/login?' . http_build_query(['next' => $_SERVER['REQUEST_URI'] ?? '/']));
    }

    return $member;
}

/** The database connection, opened on first use and shared for the rest of the request. */
function database(): Database
{
    static $database = null;

    return $database ??= Database::fromEnvironment(ROOT);
}

function books(): BookRepository
{
    return new PdoBookRepository(database()->pdo);
}

function members(): PdoMemberRepository
{
    return new PdoMemberRepository(database()->pdo);
}

function loans(): PdoLoanRepository
{
    return new PdoLoanRepository(database()->pdo, new DateTimeZone(LIBRARY_TIMEZONE));
}

function authenticator(): Authenticator
{
    return new Authenticator(members(), new LoginThrottle(database()->pdo, new SystemClock()));
}

function stringParam(array $source, string $name, int $maxLength = 200): string
{
    $value = $source[$name] ?? '';

    return is_string($value) ? mb_substr(trim($value), 0, $maxLength) : '';
}

// ---------------------------------------------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------------------------------------------

function showCatalog(): void
{
    $query = stringParam($_GET, 'q');
    $genre = Genre::tryFrom(stringParam($_GET, 'genre'));

    page('Catalog', 'catalog', [
        'books' => books()->search($query, $genre),
        'query' => $query,
        'genre' => $genre,
    ]);
}

function apiBooks(): void
{
    $genreParam = stringParam($_GET, 'genre');
    $genre = Genre::tryFrom($genreParam);
    if ($genreParam !== '' && $genre === null) {
        json(['error' => "Unknown genre '$genreParam'"], 400);
        return;
    }

    $books = books()->search(stringParam($_GET, 'q'), $genre);
    json([
        'count' => count($books),
        'data' => array_map(fn (Book $book): array => [
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'genre' => $book->genre,
            'year' => $book->year,
            'available' => $book->available,
        ], $books),
    ]);
}

function showSuggestForm(): void
{
    page('Suggest a book', 'suggest', ['values' => [], 'errors' => []]);
}

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

    database()->pdo
        ->prepare('INSERT INTO suggestions (created_at, title, author, isbn, reason)
                   VALUES (:created_at, :title, :author, :isbn, :reason)')
        ->execute(['created_at' => gmdate('Y-m-d H:i:s'), 'isbn' => $values['isbn'] === '' ? null : $values['isbn']] + $values);

    flash("Thank you! We'll consider \"{$values['title']}\".");
    redirect('/');
}

function showLoginForm(): void
{
    page('Log in', 'login', ['email' => '', 'error' => null, 'next' => localPath(stringParam($_GET, 'next', 2000))]);
}

function handleLogin(): void
{
    $email = stringParam($_POST, 'email');
    $password = $_POST['password'] ?? '';          // not trimmed: spaces are part of a password
    $next = localPath(stringParam($_POST, 'next', 2000));

    try {
        $member = authenticator()->authenticate(
            $email,
            is_string($password) ? $password : '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        );
    } catch (AuthenticationFailed $e) {
        page('Log in', 'login', ['email' => $email, 'error' => $e->getMessage(), 'next' => $next],
            $e->throttled ? 429 : 422);
        return;
    }

    logIn($member);
    flash("Welcome back, {$member->name}.");
    redirect($next);
}

function handleLogout(): void
{
    logOut();
    flash('You have been logged out.');
    redirect('/');
}

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

    page('Suggestions', 'suggestions', [
        'suggestions' => database()->pdo
            ->query('SELECT created_at, title, author, isbn, reason FROM suggestions ORDER BY id DESC LIMIT 100')
            ->fetchAll(),
    ]);
}

function notFound(): void
{
    page('Not found', 'not-found', [], 404);
}

// ---------------------------------------------------------------------------------------------------------------
// The front controller: pick a page by method and path
// ---------------------------------------------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = Uri::parse($_SERVER['REQUEST_URI'] ?? '/')?->getPath() ?? '/';

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
