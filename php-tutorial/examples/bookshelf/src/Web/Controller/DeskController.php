<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Domain\BookNotAvailable;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Isbn;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\LoanRuleViolation;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Http\Access;
use Bookshelf\Http\HttpException;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use Bookshelf\Lending\LendingService;
use Bookshelf\Lending\OverdueReport;
use Bookshelf\Support\Clock;
use Bookshelf\Web\Session;
use Bookshelf\Web\View;
use LogicException;

/**
 * The lending desk, for library staff: find a member, lend them a book by its ISBN, take books back.
 * The rules live in LendingService and the domain; this class turns forms into calls and outcomes into pages.
 */
final readonly class DeskController
{
    public function __construct(
        private LendingService $lending,
        private OverdueReport $overdue,
        private MemberRepository $members,
        private BookRepository $books,
        private LoanRepository $loans,
        private Clock $clock,
        private Session $session,
        private View $view,
    ) {
    }

    #[Route('GET', '/desk', Access::Staff)]
    public function index(Request $request): Response
    {
        return $this->page($request->query('member'));
    }

    #[Route('POST', '/desk/loans', Access::Staff)]
    public function lend(Request $request): Response
    {
        $email = $request->input('member');
        $isbn = preg_replace('/[\s-]+/', '', $request->input('isbn', 30)) ?? '';

        $member = $this->members->findByEmail($email);
        $book = Isbn::isValid($isbn) ? $this->books->findByIsbn(new Isbn($isbn)) : null;
        if ($member === null) {
            return $this->page($email, error: 'There is no member with that e-mail address.', status: 422);
        }
        if ($book === null) {
            return $this->page($email, error: "There is no book with the ISBN '$isbn'.", isbn: $isbn, status: 422);
        }

        try {
            $loan = $this->lending->lend(
                $book->id ?? throw new LogicException('A stored book has an id'),
                $member->id ?? throw new LogicException('A stored member has an id'),
            );
        } catch (LoanRuleViolation|BookNotAvailable $e) {
            // Broken lending rules are part of the job at a desk, not errors: show why, keep what was typed.
            return $this->page($email, error: $e->getMessage(), isbn: $isbn, status: 422);
        }

        $this->session->flash(sprintf('Lent "%s" to %s, due back on %s.', $book->title, $member->name,
            $loan->dueOn->format('D j M Y')));

        return Response::redirect(self::deskFor($member->email));   // POST, redirect, GET (chapter 16)
    }

    #[Route('POST', '/desk/loans/{id:\d+}/return', Access::Staff)]
    public function takeBack(int $id): Response
    {
        try {
            $loan = $this->loans->get($id);
        } catch (EntityNotFound $e) {
            throw new HttpException(404, "There is no loan with id $id.", previous: $e);
        }
        $title = $this->books->get($loan->bookId)->title;
        $member = $this->members->get($loan->memberId);

        try {
            $fine = $this->lending->returnLoan($id);
            $this->session->flash($fine->isZero()
                ? "Took back \"$title\"."
                : "Took back \"$title\", returned late. Please collect a fine of $fine.");
        } catch (LoanRuleViolation $e) {
            $this->session->flash($e->getMessage());     // a second click on the same button
        }

        return Response::redirect(self::deskFor($member->email));
    }

    private function page(string $email, ?string $error = null, string $isbn = '', int $status = 200): Response
    {
        $today = $this->clock->now();
        $borrower = $email === '' ? null : $this->members->findByEmail($email);
        if ($email !== '' && $borrower === null) {
            $error ??= 'There is no member with that e-mail address.';
        }
        $loans = $borrower?->id === null ? [] : $this->loans->openLoansOf($borrower->id);

        return $this->view->page('Lending desk', 'desk', [
            'email' => $email,
            'borrower' => $borrower,
            'loans' => $loans,
            'books' => $this->books->findByIds(array_map(fn (Loan $loan): int => $loan->bookId, $loans)),
            'isbn' => $isbn,
            'error' => $error,
            'overdue' => $this->overdue->on($today),
            'today' => $today,
        ], $status);
    }

    private static function deskFor(string $email): string
    {
        return '/desk?' . http_build_query(['member' => $email]);
    }
}
