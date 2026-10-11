<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

/**
 * Where books are stored. The domain depends on this interface; src/Persistence implements it.
 */
interface BookRepository
{
    /** @throws EntityNotFound */
    public function get(int $id): Book;

    public function findByIsbn(Isbn $isbn): ?Book;

    /**
     * The books with the given ids, keyed by id, in one query. Unknown ids are left out.
     *
     * @param list<int> $ids
     * @return array<int, Book>
     */
    public function findByIds(array $ids): array;

    /**
     * Books whose title or author contains $text (case-insensitively), optionally of one genre, by title.
     *
     * @return list<Book>
     */
    public function search(string $text = '', ?Genre $genre = null, int $limit = 50, int $offset = 0): array;

    public function count(string $text = '', ?Genre $genre = null): int;

    /** Inserts a new book (and assigns its id) or updates an existing one. */
    public function save(Book $book): void;
}
