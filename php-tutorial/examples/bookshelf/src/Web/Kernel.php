<?php

declare(strict_types=1);

namespace Bookshelf\Web;

use Bookshelf\Http\Middleware;
use Bookshelf\Http\Pipeline;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;

/**
 * The web application as one request handler: the middleware stack around the dispatcher.
 */
final readonly class Kernel implements RequestHandler
{
    /** @param list<Middleware> $middleware outermost first */
    public function __construct(
        private array $middleware,
        private Dispatcher $dispatcher,
    ) {
    }

    public function handle(Request $request): Response
    {
        return new Pipeline($this->middleware, $this->dispatcher)->handle($request);   // PHP 8.4+: no parentheses needed
    }
}
