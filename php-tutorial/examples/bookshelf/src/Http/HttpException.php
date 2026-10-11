<?php

declare(strict_types=1);

namespace Bookshelf\Http;

use RuntimeException;
use Throwable;

/**
 * A failure that has an HTTP status, such as 404 for a missing page. The error middleware turns it into a response.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'There is no page at this address.'): self
    {
        return new self(404, $message);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(405, 'This address does not accept that method.', ['Allow' => implode(', ', $allowed)]);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }
}
