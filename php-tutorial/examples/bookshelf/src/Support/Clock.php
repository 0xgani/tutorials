<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use DateTimeImmutable;

/**
 * Tells the current time. Code that needs "now" asks a Clock instead of calling `new DateTimeImmutable()`,
 * so tests can control time. This is the same shape as PSR-20's `Psr\Clock\ClockInterface`.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
