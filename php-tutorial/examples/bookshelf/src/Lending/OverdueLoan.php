<?php

declare(strict_types=1);

namespace Bookshelf\Lending;

use Bookshelf\Domain\Money;
use DateTimeImmutable;

/**
 * One line of the overdue report: a late loan with what a person needs to chase it up.
 */
final readonly class OverdueLoan
{
    public function __construct(
        public int $loanId,
        public DateTimeImmutable $dueOn,
        public int $daysOverdue,
        public Money $fine,
        public string $title,
        public string $memberName,
        public string $memberEmail,
    ) {
    }
}
