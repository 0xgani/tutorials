<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

enum MemberType: string
{
    case Standard = 'standard';
    case Premium = 'premium';
    case Staff = 'staff';

    public function maxLoans(): int
    {
        return match ($this) {
            self::Standard => 3,
            self::Premium => 6,
            self::Staff => 10,
        };
    }

    public function loanDays(): int
    {
        return match ($this) {
            self::Standard => 21,
            self::Premium => 42,
            self::Staff => 60,
        };
    }

    public function finePerDay(): Money
    {
        return $this === self::Staff ? Money::zero() : Money::cents(25);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
