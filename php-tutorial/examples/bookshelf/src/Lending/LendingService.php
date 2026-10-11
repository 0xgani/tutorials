<?php

declare(strict_types=1);

namespace Bookshelf\Lending;

use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\LoanRuleViolation;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Domain\Money;
use Bookshelf\Persistence\Database;
use Bookshelf\Support\Clock;

/**
 * The library's use cases: lend, return, renew. Each runs in one transaction.
 *
 * The domain objects enforce the rules about a single book or loan; this service enforces the rules that
 * need several (a member's loan limit) and makes sure all the changes are saved together, or none.
 */
final readonly class LendingService
{
    public function __construct(
        private Database $database,
        private BookRepository $books,
        private MemberRepository $members,
        private LoanRepository $loans,
        private Clock $clock,
    ) {
    }

    public function lend(int $bookId, int $memberId): Loan
    {
        return $this->database->transactional(function () use ($bookId, $memberId): Loan {
            $book = $this->books->get($bookId);
            $member = $this->members->get($memberId);

            $openLoans = $this->loans->openLoansOf($memberId);
            if (count($openLoans) >= $member->type->maxLoans()) {
                throw LoanRuleViolation::loanLimitReached($member);
            }
            foreach ($openLoans as $open) {
                if ($open->bookId === $bookId) {
                    throw LoanRuleViolation::alreadyBorrowed($member, $book);
                }
            }

            $book->lendCopy();
            $loan = Loan::start($book, $member, $this->clock->now());
            $this->books->save($book);
            $this->loans->save($loan);

            return $loan;
        });
    }

    /** Records the return and gives back the fine owed (zero if returned on time). */
    public function returnLoan(int $loanId): Money
    {
        return $this->database->transactional(function () use ($loanId): Money {
            $loan = $this->loans->get($loanId);
            $today = $this->clock->now();
            $loan->markReturned($today);

            $book = $this->books->get($loan->bookId);
            $book->returnCopy();
            $this->books->save($book);
            $this->loans->save($loan);

            return $loan->fine($today);
        });
    }

    public function renew(int $loanId): Loan
    {
        return $this->database->transactional(function () use ($loanId): Loan {
            $loan = $this->loans->get($loanId);
            $loan->renew($this->clock->now());
            $this->loans->save($loan);

            return $loan;
        });
    }
}
