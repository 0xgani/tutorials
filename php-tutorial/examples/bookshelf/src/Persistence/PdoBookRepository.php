<?php

declare(strict_types=1);

namespace Bookshelf\Persistence;

use Bookshelf\Domain\Book;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use PDO;

final readonly class PdoBookRepository implements BookRepository
{
    private const string COLUMNS = 'id, title, author, isbn, genre, year, copies, available';

    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function get(int $id): Book
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM books WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? throw EntityNotFound::book($id) : $this->toBook($row);
    }

    public function findByIsbn(Isbn $isbn): ?Book
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM books WHERE isbn = ?');
        $statement->execute([$isbn->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->toBook($row);
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM books WHERE id IN ($placeholders)");
        $statement->execute($ids);

        $books = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $books[$row['id']] = $this->toBook($row);
        }

        return $books;
    }

    public function search(string $text = '', ?Genre $genre = null, int $limit = 50, int $offset = 0): array
    {
        [$where, $parameters] = $this->filter($text, $genre);
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM books $where ORDER BY title, id LIMIT :limit OFFSET :offset",
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->toBook(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function count(string $text = '', ?Genre $genre = null): int
    {
        [$where, $parameters] = $this->filter($text, $genre);
        $statement = $this->pdo->prepare("SELECT count(*) FROM books $where");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function save(Book $book): void
    {
        $values = [
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn->value,
            'genre' => $book->genre?->value,
            'year' => $book->year,
            'copies' => $book->copies,
            'available' => $book->available,
        ];

        if ($book->id === null) {
            $this->pdo->prepare(
                'INSERT INTO books (title, author, isbn, genre, year, copies, available)
                 VALUES (:title, :author, :isbn, :genre, :year, :copies, :available)',
            )->execute($values);
            $book->assignId((int) $this->pdo->lastInsertId());
            return;
        }

        $this->pdo->prepare(
            'UPDATE books SET title = :title, author = :author, isbn = :isbn, genre = :genre, year = :year,
                              copies = :copies, available = :available
             WHERE id = :id',
        )->execute($values + ['id' => $book->id]);
    }

    /**
     * Builds the WHERE clause for a search. User text only ever travels as a parameter.
     *
     * @return array{string, array<string, string>}
     */
    private function filter(string $text, ?Genre $genre): array
    {
        $conditions = [];
        $parameters = [];
        if ($text !== '') {
            // Escape LIKE's wildcards so that a search for "100%" means a literal percent sign.
            $pattern = '%' . addcslashes($text, '%_\\') . '%';
            $conditions[] = "(title LIKE :title ESCAPE '\\' OR author LIKE :author ESCAPE '\\')";
            $parameters['title'] = $pattern;
            $parameters['author'] = $pattern;
        }
        if ($genre !== null) {
            $conditions[] = 'genre = :genre';
            $parameters['genre'] = $genre->value;
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    /** @param array<string, mixed> $row */
    private function toBook(array $row): Book
    {
        return new Book(
            $row['id'],
            $row['title'],
            $row['author'],
            new Isbn($row['isbn']),
            $row['genre'] === null ? null : Genre::from($row['genre']),
            $row['year'],
            $row['copies'],
            $row['available'],
        );
    }
}
