<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\Member;
use Bookshelf\Http\Access;
use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use Bookshelf\Support\Clock;
use Bookshelf\Web\View;

final readonly class LoanController
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private Clock $clock,
        private View $view,
    ) {
    }

    /** The logged-in member's own loans: whose loans comes from the session, never from the URL. */
    #[Route('GET', '/loans', Access::Member)]
    public function mine(Member $member): Response
    {
        $loans = $this->loans->openLoansOf($member->id);

        return $this->view->page('My loans', 'loans', [
            'loans' => $loans,
            'books' => $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
            'today' => $this->clock->now()->setTime(0, 0),
        ]);
    }
}
