<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real time, in the library's own time zone (which decides when a day starts and ends).
 */
final readonly class SystemClock implements Clock
{
    public function __construct(
        private DateTimeZone $timezone = new DateTimeZone('UTC'),
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
