<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use DateTimeImmutable;
use LogicException;

/**
 * One copy of a book lent to one member. Entity.
 *
 * The loan length and the fine rate are copied from the member type when the loan starts,
 * so later changes to the rules do not change existing loans.
 */
final class Loan
{
    public const int MAX_RENEWALS = 2;
    public const int FINE_CAP_CENTS = 1000;

    public bool $isReturned {
        get => $this->returnedOn !== null;
    }

    private function __construct(
        public private(set) ?int $id,
        public readonly int $bookId,
        public readonly int $memberId,
        public readonly DateTimeImmutable $lentOn,
        public private(set) DateTimeImmutable $dueOn,
        public readonly int $loanDays,
        public readonly Money $finePerDay,
        public private(set) ?DateTimeImmutable $returnedOn = null,
        public private(set) int $renewals = 0,
    ) {
    }

    public static function start(Book $book, Member $member, DateTimeImmutable $today): self
    {
        if ($book->id === null || $member->id === null) {
            throw new LogicException('Only saved books and members can take part in a loan');
        }
        $lentOn = $today->setTime(0, 0);
        $days = $member->type->loanDays();

        return new self(null, $book->id, $member->id, $lentOn, $lentOn->modify("+$days days"), $days,
            $member->type->finePerDay());
    }

    /** Rebuild a loan from stored data (used by repositories). */
    public static function restore(
        int $id,
        int $bookId,
        int $memberId,
        DateTimeImmutable $lentOn,
        DateTimeImmutable $dueOn,
        int $loanDays,
        Money $finePerDay,
        ?DateTimeImmutable $returnedOn,
        int $renewals,
    ): self {
        return new self($id, $bookId, $memberId, $lentOn, $dueOn, $loanDays, $finePerDay, $returnedOn, $renewals);
    }

    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new LogicException("Loan already has id {$this->id}");
        }
        $this->id = $id;
    }

    public function status(DateTimeImmutable $today): LoanStatus
    {
        return match (true) {
            $this->isReturned => LoanStatus::Returned,
            $this->daysOverdue($today) > 0 => LoanStatus::Overdue,
            default => LoanStatus::Active,
        };
    }

    public function daysOverdue(DateTimeImmutable $today): int
    {
        $end = ($this->returnedOn ?? $today)->setTime(0, 0);
        if ($end <= $this->dueOn) {
            return 0;
        }

        return $this->dueOn->diff($end)->days;
    }

    public function fine(DateTimeImmutable $today): Money
    {
        return $this->finePerDay
            ->times($this->daysOverdue($today))
            ->min(Money::cents(self::FINE_CAP_CENTS));
    }

    public function renew(DateTimeImmutable $today): void
    {
        if ($this->isReturned) {
            throw LoanRuleViolation::alreadyReturned($this);
        }
        if ($this->daysOverdue($today) > 0) {
            throw LoanRuleViolation::overdue($this);
        }
        if ($this->renewals >= self::MAX_RENEWALS) {
            throw LoanRuleViolation::renewalLimitReached($this);
        }
        $this->renewals++;
        $this->dueOn = $this->dueOn->modify("+{$this->loanDays} days");
    }

    public function markReturned(DateTimeImmutable $today): void
    {
        if ($this->isReturned) {
            throw LoanRuleViolation::alreadyReturned($this);
        }
        $this->returnedOn = $today->setTime(0, 0);
    }
}
