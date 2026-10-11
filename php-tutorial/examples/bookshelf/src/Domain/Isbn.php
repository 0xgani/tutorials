<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A valid ISBN-13, stored as 13 digits without separators. Immutable.
 */
final readonly class Isbn implements JsonSerializable, Stringable
{
    public string $value;

    public function __construct(string $input)
    {
        $digits = preg_replace('/[\s-]+/', '', $input);
        if (!self::isValid($digits)) {
            throw new InvalidArgumentException("Invalid ISBN-13: '$input'");
        }
        $this->value = $digits;
    }

    public static function isValid(string $digits): bool
    {
        if (preg_match('/^\d{13}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (str_split($digits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $sum % 10 === 0;
    }

    public function equals(Isbn $other): bool
    {
        return $this->value === $other->value;
    }

    /** The ISBN with the usual dashes: 978-0441013593 */
    public function format(): string
    {
        return substr($this->value, 0, 3) . '-' . substr($this->value, 3);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
