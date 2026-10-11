<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that stands still until told to move. For tests and demos.
 */
final class FrozenClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {
    }

    public static function at(string $time, string $timezone = 'UTC'): self
    {
        return new self(new DateTimeImmutable($time, new DateTimeZone($timezone)));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /** Moves the clock, e.g. advance('+3 days') or advance('+90 minutes'). */
    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
