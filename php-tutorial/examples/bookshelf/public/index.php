<?php

declare(strict_types=1);

/*
 * Bookshelf's first web front controller (chapter 16; chapter 17 moved the data into the database).
 * Every request to the site runs this file.
 * Chapter 19 replaces this single file with a router, request/response objects and controllers.
 */

use Bookshelf\Domain\Book;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Bookshelf\Persistence\Database;
use Bookshelf\Persistence\PdoBookRepository;
use Uri\Rfc3986\Uri;

// With PHP's built-in server, let it serve existing files (CSS, images) itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

const ROOT = __DIR__ . '/..';

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
        'flash' => takeFlash(),
        'content' => render($template, $variables),
    ]);
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

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => ($_SERVER['HTTPS'] ?? 'off') !== 'off',
            'use_strict_mode' => true,
        ]);
    }
}

function flash(string $message): void
{
    startSession();
    $_SESSION['flash'] = $message;
}

function takeFlash(): ?string
{
    if (!isset($_COOKIE[session_name()])) {
        return null;   // no session yet, so nothing to show; don't create one just to look
    }
    startSession();
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $message;
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

function notFound(): void
{
    page('Not found', 'not-found', [], 404);
}

// ---------------------------------------------------------------------------------------------------------------
// The front controller: pick a page by method and path
// ---------------------------------------------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = Uri::parse($_SERVER['REQUEST_URI'] ?? '/')?->getPath() ?? '/';

match ("$method $path") {
    'GET /', 'HEAD /' => showCatalog(),
    'GET /api/books' => apiBooks(),
    'GET /suggest' => showSuggestForm(),
    'POST /suggest' => handleSuggestion(),
    default => notFound(),
};
