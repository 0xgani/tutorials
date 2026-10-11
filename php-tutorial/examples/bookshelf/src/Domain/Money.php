<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A non-negative amount of money in US cents. Immutable.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('Money cannot be negative');
        }
    }

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function times(int $factor): self
    {
        return new self($this->cents * $factor);
    }

    public function min(Money $other): self
    {
        return $this->cents <= $other->cents ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function format(): string
    {
        return sprintf('$%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): int
    {
        return $this->cents;
    }
}
