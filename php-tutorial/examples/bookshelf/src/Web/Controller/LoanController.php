<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\LoanRuleViolation;
use Bookshelf\Domain\Member;
use Bookshelf\Http\Access;
use Bookshelf\Http\HttpException;
use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use Bookshelf\Lending\LendingService;
use Bookshelf\Support\Clock;
use Bookshelf\Web\Session;
use Bookshelf\Web\View;
use LogicException;

final readonly class LoanController
{
    public function __construct(
        private LoanRepository $loans,
        private BookRepository $books,
        private LendingService $lending,
        private Clock $clock,
        private Session $session,
        private View $view,
    ) {
    }

    /** The logged-in member's own loans: whose loans comes from the session, never from the URL. */
    #[Route('GET', '/loans', Access::Member)]
    public function mine(Member $member): Response
    {
        $loans = $this->loans->openLoansOf($member->id ?? throw new LogicException('A logged-in member has an id'));

        return $this->view->page('My loans', 'loans', [
            'loans' => $loans,
            'books' => $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
            'today' => $this->clock->now()->setTime(0, 0),
        ]);
    }

    /** A member renews one of their own loans. */
    #[Route('POST', '/loans/{id:\d+}/renew', Access::Member)]
    public function renew(int $id, Member $member): Response
    {
        try {
            $loan = $this->loans->get($id);
        } catch (EntityNotFound) {
            $loan = null;
        }
        // The id in the URL proves nothing about ownership (chapter 18). Someone else's loan gets the same answer
        // as a loan that doesn't exist, so the URL can't be used to probe which loans exist.
        if ($loan === null || $loan->memberId !== $member->id) {
            throw HttpException::notFound("There is no loan with id $id.");
        }

        try {
            $renewed = $this->lending->renew($id);
            $this->session->flash('Renewed. The new due date is ' . $renewed->dueOn->format('D j M Y') . '.');
        } catch (LoanRuleViolation $e) {
            $this->session->flash($e->getMessage());
        }

        return Response::redirect('/loans');
    }
}
