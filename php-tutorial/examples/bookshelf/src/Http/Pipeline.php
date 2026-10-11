<?php

declare(strict_types=1);

namespace Bookshelf\Http;

/**
 * Runs a request through a list of middleware, in order, and finally through the handler.
 */
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
