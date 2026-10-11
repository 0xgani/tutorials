<?php

declare(strict_types=1);

namespace Bookshelf\Persistence;

use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\Money;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Loans store calendar dates as 'YYYY-MM-DD' text; they are rebuilt at midnight in the library's time zone.
 */
final readonly class PdoLoanRepository implements LoanRepository
{
    private const string COLUMNS = 'id, book_id, member_id, lent_on, due_on, loan_days, fine_per_day_cents, returned_on, renewals';

    public function __construct(
        private PDO $pdo,
        private DateTimeZone $timezone,
    ) {
    }

    public function get(int $id): Loan
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM loans WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? throw EntityNotFound::loan($id) : $this->toLoan($row);
    }

    public function openLoansOf(int $memberId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM loans WHERE member_id = ? AND returned_on IS NULL ORDER BY lent_on, id',
        );
        $statement->execute([$memberId]);

        return array_map($this->toLoan(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function overdue(DateTimeImmutable $today): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM loans WHERE returned_on IS NULL AND due_on < ? ORDER BY due_on, id',
        );
        $statement->execute([$today->setTimezone($this->timezone)->format('Y-m-d')]);

        return array_map($this->toLoan(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function save(Loan $loan): void
    {
        $values = [
            'due_on' => $loan->dueOn->format('Y-m-d'),
            'returned_on' => $loan->returnedOn?->format('Y-m-d'),
            'renewals' => $loan->renewals,
        ];

        if ($loan->id === null) {
            $this->pdo->prepare(
                'INSERT INTO loans (book_id, member_id, lent_on, due_on, loan_days, fine_per_day_cents, returned_on, renewals)
                 VALUES (:book_id, :member_id, :lent_on, :due_on, :loan_days, :fine_per_day_cents, :returned_on, :renewals)',
            )->execute($values + [
                'book_id' => $loan->bookId,
                'member_id' => $loan->memberId,
                'lent_on' => $loan->lentOn->format('Y-m-d'),
                'loan_days' => $loan->loanDays,
                'fine_per_day_cents' => $loan->finePerDay->cents,
            ]);
            $loan->assignId((int) $this->pdo->lastInsertId());
            return;
        }

        // Only these three change during a loan's life; the rest is fixed when it starts.
        $this->pdo->prepare('UPDATE loans SET due_on = :due_on, returned_on = :returned_on, renewals = :renewals WHERE id = :id')
            ->execute($values + ['id' => $loan->id]);
    }

    /** @param array<string, mixed> $row */
    private function toLoan(array $row): Loan
    {
        return Loan::restore(
            $row['id'],
            $row['book_id'],
            $row['member_id'],
            $this->date($row['lent_on']),
            $this->date($row['due_on']),
            $row['loan_days'],
            Money::cents($row['fine_per_day_cents']),
            $row['returned_on'] === null ? null : $this->date($row['returned_on']),
            $row['renewals'],
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->timezone);
    }
}
