<?php

declare(strict_types=1);

namespace Bookshelf\Lending;

use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use DateTimeImmutable;
use LogicException;

/**
 * Every loan that is past its due date, with the book's title, the member and the fine so far.
 * One use case with two front ends: the loans:overdue command and the staff's lending desk.
 */
final readonly class OverdueReport
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private MemberRepository $members,
    ) {
    }

    /** @return list<OverdueLoan> the most overdue first */
    public function on(DateTimeImmutable $today): array
    {
        $overdue = $this->loans->overdue($today);
        $books = $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $overdue));

        /** @var array<int, Member> $members */
        $members = [];
        $report = [];
        foreach ($overdue as $loan) {
            $member = $members[$loan->memberId] ??= $this->members->get($loan->memberId);
            $report[] = new OverdueLoan(
                $loan->id ?? throw new LogicException('A stored loan has an id'),
                $loan->dueOn,
                $loan->daysOverdue($today),
                $loan->fine($today),
                $books[$loan->bookId]->title ?? "(book {$loan->bookId})",
                $member->name,
                $member->email,
            );
        }

        return $report;
    }
}
