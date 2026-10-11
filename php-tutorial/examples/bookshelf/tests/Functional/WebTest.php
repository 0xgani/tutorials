<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Functional;

use Bookshelf\Container\Container;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Persistence\Migrator;
use Bookshelf\Tests\Support\Builders;
use Bookshelf\Web\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The whole web application, from Request to Response, with an in-memory database and no web server.
 */
#[CoversNothing]
final class WebTest extends TestCase
{
    use Builders;

    private Container $container;

    protected function setUp(): void
    {
        // phpunit.xml.dist sets BOOKSHELF_DATABASE to :memory:, so every test gets its own empty database.
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
        $this->container->get(Migrator::class)->migrate();
        $this->container->get(BookRepository::class)->save(self::book(id: null));
    }

    public function testTheCatalogListsTheBooks(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<a href="/books/1">Dune</a>', $response->body);
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function testTheApiReturnsABookAsJson(): void
    {
        $response = $this->get('/api/books/1');

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        self::assertSame('Dune', json_decode($response->body, true)['data']['title']);
    }

    public function testAnUnknownBookIsNotFound(): void
    {
        self::assertSame(404, $this->get('/books/99')->status);
        self::assertJsonStringEqualsJsonString('{"error": "There is no book with id 99."}', $this->get('/api/books/99')->body);
    }

    public function testMemberPagesRedirectToTheLogin(): void
    {
        $response = $this->get('/loans');

        self::assertSame(303, $response->status);
        self::assertSame('/login?next=%2Floans', $response->header('Location'));
    }

    public function testTheHealthCheckReportsOk(): void
    {
        $response = $this->get('/health');

        self::assertSame(200, $response->status);
        self::assertSame('{"status":"ok"}', $response->body);
        self::assertSame('no-store', $response->header('Cache-Control'));
    }

    public function testAPostWithoutACsrfTokenIsRejected(): void
    {
        $response = $this->handle(new Request('POST', '/suggest', body: ['title' => 'Emma', 'author' => 'Jane Austen']));

        self::assertSame(403, $response->status);
    }

    private function get(string $path): Response
    {
        return $this->handle(new Request('GET', $path));
    }

    private function handle(Request $request): Response
    {
        return $this->container->get(Kernel::class)->handle($request);
    }
}
