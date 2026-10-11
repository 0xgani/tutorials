<?php

declare(strict_types=1);

namespace Bookshelf\Import;

/**
 * A CSV line that could not be turned into a book, and why.
 */
final readonly class InvalidRow
{
    public function __construct(
        public int $line,
        public string $reason,
    ) {
    }
}
