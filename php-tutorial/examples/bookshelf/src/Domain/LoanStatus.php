<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

enum LoanStatus: string
{
    case Active = 'active';
    case Overdue = 'overdue';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'On loan',
            self::Overdue => 'Overdue',
            self::Returned => 'Returned',
        };
    }
}
