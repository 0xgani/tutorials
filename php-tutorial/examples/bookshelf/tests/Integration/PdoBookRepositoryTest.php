<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Integration;

use Bookshelf\Domain\Book;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Bookshelf\Persistence\PdoBookRepository;
use Bookshelf\Tests\Support\Builders;
use Bookshelf\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PdoBookRepository::class)]
final class PdoBookRepositoryTest extends DatabaseTestCase
{
    use Builders;

    private PdoBookRepository $books;

    protected function setUp(): void
    {
        parent::setUp();
        $this->books = new PdoBookRepository($this->database->pdo);
        foreach ([
            ['Dune', '9780441013593'],
            ['100% Pure', '9780807083697'],
            ['1000 Places', '9780141439518'],
        ] as [$title, $isbn]) {
            $this->books->save(self::book(id: null, title: $title, isbn: $isbn));
        }
    }

    public function testSavingAssignsAnIdAndLoadingRestoresTheBook(): void
    {
        $dune = $this->books->findByIsbn(new Isbn('9780441013593'));

        self::assertNotNull($dune);
        self::assertSame('Dune', $dune->title);
        self::assertSame(Genre::ScienceFiction, $dune->genre);
        self::assertEquals($dune, $this->books->get((int) $dune->id));
    }

    public function testSearchTreatsLikeWildcardsInTheQueryLiterally(): void
    {
        $titles = array_map(fn (Book $book): string => $book->title, $this->books->search('100%'));

        self::assertSame(['100% Pure'], $titles);           // not "1000 Places"
    }

    public function testFindByIdsReturnsBooksKeyedByIdAndSkipsUnknownIds(): void
    {
        $books = $this->books->findByIds([3, 1, 99, 1]);

        $ids = array_keys($books);
        sort($ids);                                          // SQL doesn't promise an order without ORDER BY

        self::assertSame([1, 3], $ids);
        self::assertSame('Dune', $books[1]->title);
        self::assertSame([], $this->books->findByIds([]));
    }
}
