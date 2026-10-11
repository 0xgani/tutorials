<?php

declare(strict_types=1);

namespace Bookshelf\Http;

/** The result of routing: which controller method to call, and the values of the path parameters. */
final readonly class RouteMatch
{
    /**
     * @param array{class-string, string} $handler
     * @param array<string, string> $parameters
     */
    public function __construct(
        public array $handler,
        public Access $access,
        public array $parameters,
    ) {
    }
}
