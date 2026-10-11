<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Unit\Http;

use Bookshelf\Http\Access;
use Bookshelf\Http\HttpException;
use Bookshelf\Http\Router;
use Bookshelf\Web\Controller\CatalogController;
use Bookshelf\Web\Controller\LoanController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->add('GET', '/books/{id:\d+}', [CatalogController::class, 'show']);
        $this->router->add('POST', '/books/{id:\d+}/loans', [LoanController::class, 'lend'], Access::Member);
    }

    public function testMatchesAPathAndExtractsItsParameters(): void
    {
        $match = $this->router->match('GET', '/books/42');

        self::assertSame([CatalogController::class, 'show'], $match->handler);
        self::assertSame(['id' => '42'], $match->parameters);
        self::assertSame(Access::Public, $match->access);
    }

    public function testHeadRequestsUseTheGetRoute(): void
    {
        self::assertSame([CatalogController::class, 'show'], $this->router->match('HEAD', '/books/42')->handler);
    }

    public function testAParameterThatDoesNotMatchItsPatternIsNotFound(): void
    {
        $exception = $this->catchHttpException(fn () => $this->router->match('GET', '/books/dune'));

        self::assertSame(404, $exception->status);
    }

    public function testAKnownPathWithTheWrongMethodIsMethodNotAllowed(): void
    {
        $exception = $this->catchHttpException(fn () => $this->router->match('GET', '/books/42/loans'));

        self::assertSame(405, $exception->status);
        self::assertSame(['Allow' => 'POST'], $exception->headers);
    }

    private function catchHttpException(callable $code): HttpException
    {
        try {
            $code();
        } catch (HttpException $e) {
            return $e;
        }
        self::fail('Expected an HttpException');
    }
}
