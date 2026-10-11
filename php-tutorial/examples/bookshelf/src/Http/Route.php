<?php

declare(strict_types=1);

namespace Bookshelf\Http;

use Attribute;

/**
 * Maps a controller method to a method and path. Path parameters are written {name} or {name:regex}:
 * #[Route('GET', '/books/{id:\d+}')].
 */
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
