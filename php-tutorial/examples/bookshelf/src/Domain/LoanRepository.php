<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use DateTimeImmutable;

interface LoanRepository
{
    /** @throws EntityNotFound */
    public function get(int $id): Loan;

    /**
     * The member's loans that have not been returned, oldest first.
     *
     * @return list<Loan>
     */
    public function openLoansOf(int $memberId): array;

    /**
     * Loans not returned and due before $today, most overdue first.
     *
     * @return list<Loan>
     */
    public function overdue(DateTimeImmutable $today): array;

    /** Inserts a new loan (and assigns its id) or updates an existing one. */
    public function save(Loan $loan): void;
}
