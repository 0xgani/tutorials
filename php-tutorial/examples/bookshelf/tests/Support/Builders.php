<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Support;

use Bookshelf\Domain\Book;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Bookshelf\Domain\Loan;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberType;
use DateTimeImmutable;

/**
 * Builders for test objects with sensible defaults, so each test states only what matters to it.
 */
trait Builders
{
    private static function book(
        ?int $id = 1,
        string $title = 'Dune',
        string $isbn = '9780441013593',
        int $copies = 2,
        ?int $available = null,
    ): Book {
        return new Book($id, $title, 'Frank Herbert', new Isbn($isbn), Genre::ScienceFiction, 1965, $copies, $available);
    }

    private static function member(MemberType $type = MemberType::Standard, ?int $id = 1, string $email = 'ada@example.org'): Member
    {
        return new Member($id, 'Ada Lovelace', $email, $type);
    }

    private function loan(MemberType $type, string $lentOn): Loan
    {
        return Loan::start(self::book(), self::member($type), new DateTimeImmutable($lentOn));
    }
}
