<?php

declare(strict_types=1);

namespace Bookshelf\Import;

use Bookshelf\Domain\Book;
use Bookshelf\Domain\Genre;
use Bookshelf\Domain\Isbn;
use Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads books from a CSV file with the header: title,author,isbn,genre,year,copies
 *
 * The file is read lazily, one line at a time, so it can be arbitrarily large.
 */
final class CsvBookReader
{
    private const array COLUMNS = ['title', 'author', 'isbn', 'genre', 'year', 'copies'];

    /**
     * @return Generator<int, Book|InvalidRow> keyed by line number
     */
    public function read(string $path): Generator
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open $path");
        }

        try {
            $header = fgetcsv($handle, escape: '');
            if ($header !== false) {
                // Spreadsheet programs often start UTF-8 files with a byte-order mark. It is not part of the text.
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            }
            if ($header === false || array_map(fn (?string $cell): string => trim((string) $cell), $header) !== self::COLUMNS) {
                throw new RuntimeException('Expected the header ' . implode(',', self::COLUMNS));
            }

            $line = 1;
            while (($fields = fgetcsv($handle, escape: '')) !== false) {
                $line++;
                if ($fields === [null]) {
                    continue;   // blank line
                }
                yield $line => $this->toBook($line, $fields);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string|null> $fields */
    private function toBook(int $line, array $fields): Book|InvalidRow
    {
        if (count($fields) !== count(self::COLUMNS)) {
            return new InvalidRow($line, sprintf('expected %d fields, got %d', count(self::COLUMNS), count($fields)));
        }

        $row = array_combine(self::COLUMNS, array_map(fn (?string $f): string => trim((string) $f), $fields));

        try {
            $genre = $row['genre'] === '' ? null : (Genre::tryFrom($row['genre'])
                ?? throw new InvalidArgumentException("unknown genre '{$row['genre']}'"));
            $year = $row['year'] === '' ? null : $this->integer($row['year'], 'year', 0, 2100);
            $copies = $this->integer($row['copies'] === '' ? '1' : $row['copies'], 'copies', 1, 1000);

            return new Book(null, $row['title'], $row['author'], new Isbn($row['isbn']), $genre, $year, $copies);
        } catch (InvalidArgumentException $e) {
            return new InvalidRow($line, $e->getMessage());
        }
    }

    private function integer(string $value, string $field, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        return $int === false
            ? throw new InvalidArgumentException("$field must be a whole number from $min to $max, got '$value'")
            : $int;
    }
}
