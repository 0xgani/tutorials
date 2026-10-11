<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Integration;

use Bookshelf\Domain\BookNotAvailable;
use Bookshelf\Domain\LoanRuleViolation;
use Bookshelf\Domain\MemberType;
use Bookshelf\Lending\LendingService;
use Bookshelf\Persistence\PdoBookRepository;
use Bookshelf\Persistence\PdoLoanRepository;
use Bookshelf\Persistence\PdoMemberRepository;
use Bookshelf\Support\FrozenClock;
use Bookshelf\Tests\Support\Builders;
use Bookshelf\Tests\Support\DatabaseTestCase;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LendingService::class)]
final class LendingServiceTest extends DatabaseTestCase
{
    use Builders;

    private PdoBookRepository $books;
    private PdoMemberRepository $members;
    private PdoLoanRepository $loans;
    private FrozenClock $clock;
    private LendingService $lending;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->database->pdo;
        $this->books = new PdoBookRepository($pdo);
        $this->members = new PdoMemberRepository($pdo);
        $this->loans = new PdoLoanRepository($pdo, new DateTimeZone('Europe/Berlin'));
        $this->clock = FrozenClock::at('2026-10-11 10:00', 'Europe/Berlin');
        $this->lending = new LendingService($this->database, $this->books, $this->members, $this->loans, $this->clock);
    }

    public function testLendingTakesACopyAndRecordsTheLoan(): void
    {
        $bookId = $this->saveBook(copies: 2);
        $memberId = $this->saveMember(MemberType::Premium);

        $loan = $this->lending->lend($bookId, $memberId);

        self::assertSame(1, $this->books->get($bookId)->available);
        self::assertSame('2026-11-22', $loan->dueOn->format('Y-m-d'));      // premium: 42 days
        self::assertCount(1, $this->loans->openLoansOf($memberId));
    }

    public function testAMemberCannotBorrowTheSameBookTwice(): void
    {
        $bookId = $this->saveBook(copies: 2);
        $memberId = $this->saveMember();
        $this->lending->lend($bookId, $memberId);

        $this->expectException(LoanRuleViolation::class);
        $this->expectExceptionMessage("already has a copy of 'Dune'");
        $this->lending->lend($bookId, $memberId);
    }

    public function testAMemberCannotExceedTheirLoanLimit(): void
    {
        $memberId = $this->saveMember(MemberType::Standard);        // at most 3 loans
        foreach (['9780441013593', '9780807083697', '9780141439518'] as $isbn) {
            $this->lending->lend($this->saveBook(isbn: $isbn), $memberId);
        }
        $fourth = $this->saveBook(isbn: '9780547928227');

        $this->expectExceptionObject(LoanRuleViolation::loanLimitReached($this->members->get($memberId)));
        $this->lending->lend($fourth, $memberId);
    }

    public function testAFailedLoanChangesNothing(): void
    {
        $bookId = $this->saveBook(copies: 1, available: 0);
        $memberId = $this->saveMember();

        try {
            $this->lending->lend($bookId, $memberId);
            self::fail('Expected BookNotAvailable');
        } catch (BookNotAvailable) {
        }

        self::assertSame(0, $this->books->get($bookId)->available);
        self::assertSame([], $this->loans->openLoansOf($memberId));
        self::assertFalse($this->database->pdo->inTransaction());
    }

    public function testReturningLateChargesAFine(): void
    {
        $bookId = $this->saveBook();
        $memberId = $this->saveMember(MemberType::Standard);       // 21 days, 25 cents a day
        $loanId = self::id($this->lending->lend($bookId, $memberId)->id);

        $this->clock->advance('+24 days');                         // three days late
        $fine = $this->lending->returnLoan($loanId);

        self::assertSame(75, $fine->cents);
        self::assertSame(2, $this->books->get($bookId)->available);
        self::assertTrue($this->loans->get($loanId)->isReturned);
    }

    private function saveBook(string $isbn = '9780441013593', int $copies = 2, ?int $available = null): int
    {
        $book = self::book(id: null, isbn: $isbn, copies: $copies, available: $available);
        $this->books->save($book);

        return self::id($book->id);
    }

    private function saveMember(MemberType $type = MemberType::Standard): int
    {
        $member = self::member($type, id: null);
        $this->members->save($member);

        return self::id($member->id);
    }

    /** An entity's id is null until it is saved; after saving, a missing id is a failed test. */
    private static function id(?int $id): int
    {
        return $id ?? self::fail('Expected the repository to assign an id');
    }
}
