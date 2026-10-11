<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

/**
 * A lending rule was broken: too many loans, too many renewals, ...
 */
final class LoanRuleViolation extends BookshelfException
{
    public static function loanLimitReached(Member $member): self
    {
        return new self(sprintf('%s already has %d loans, the maximum for %s members', $member->name,
            $member->type->maxLoans(), strtolower($member->type->label())));
    }

    public static function alreadyBorrowed(Member $member, Book $book): self
    {
        return new self("{$member->name} already has a copy of '{$book->title}'");
    }

    public static function renewalLimitReached(Loan $loan): self
    {
        return new self(sprintf('This loan was already renewed %d times', $loan->renewals));
    }

    public static function alreadyReturned(Loan $loan): self
    {
        return new self("Loan {$loan->id} was already returned");
    }

    public static function overdue(Loan $loan): self
    {
        return new self('Overdue loans cannot be renewed; please return the book');
    }
}
