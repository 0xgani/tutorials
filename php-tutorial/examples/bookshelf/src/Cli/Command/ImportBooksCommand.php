<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Domain\Book;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Import\CsvBookReader;
use Bookshelf\Persistence\Database;

final class ImportBooksCommand implements Command
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly CsvBookReader $reader,
        private readonly BookRepository $books,
        private readonly Database $database,
    ) {
    }

    public function name(): string
    {
        return 'import';
    }

    public function description(): string
    {
        return 'Add the books in a CSV file to the catalog';
    }

    public function help(): string
    {
        return <<<'TEXT'
            Usage: bookshelf import <file> [--dry-run] [--format=table|json] [--strict]

            Reads a CSV file with the header title,author,isbn,genre,year,copies and adds
            the books to the catalog. Books whose ISBN is already in the catalog are skipped.
            Use - as the file name to read standard input.

            Options:
              --dry-run            Only check the file and print the books; change nothing
              --format=table|json  How to print the books with --dry-run (default: table)
              --strict             Exit with code 1 if any line is invalid
            TEXT;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $file = $input->requireArgument(0, 'file');
        $format = $input->option('format', 'table', ['table', 'json']);
        $path = $file === '-' ? 'php://stdin' : $file;
        $dryRun = $input->flag('dry-run');

        $books = [];
        $invalid = 0;
        $imported = 0;
        $duplicates = 0;
        foreach ($this->reader->read($path) as $line => $result) {
            if (!$result instanceof Book) {
                $output->warning("line $line skipped: {$result->reason}");
                $invalid++;
                continue;
            }
            $books[] = $result;
            if (!$dryRun && count($books) === self::BATCH_SIZE) {
                [$added, $skipped] = $this->saveBatch($books, $output);
                $imported += $added;
                $duplicates += $skipped;
                $books = [];
            }
        }

        if ($dryRun) {
            $this->printBooks($books, $format, $output);
            $output->success(sprintf('%d books ready to import, %d skipped', count($books), $invalid));
        } else {
            [$added, $skipped] = $this->saveBatch($books, $output);
            $imported += $added;
            $duplicates += $skipped;
            $output->success(sprintf('%d books imported, %d already in the catalog, %d invalid lines skipped',
                $imported, $duplicates, $invalid));
        }

        return $invalid > 0 && $input->flag('strict') ? ExitCode::Failure : ExitCode::Success;
    }

    /**
     * Saves up to BATCH_SIZE books in one transaction: much faster than one transaction per row.
     *
     * @param list<Book> $books
     * @return array{int, int} books added, books skipped as duplicates
     */
    private function saveBatch(array $books, Output $output): array
    {
        return $this->database->transactional(function () use ($books, $output): array {
            $added = 0;
            foreach ($books as $book) {
                if ($this->books->findByIsbn($book->isbn) !== null) {
                    $output->info("already in the catalog: {$book->title} ({$book->isbn})");
                    continue;
                }
                $this->books->save($book);
                $added++;
            }

            return [$added, count($books) - $added];
        });
    }

    /** @param list<Book> $books */
    private function printBooks(array $books, string $format, Output $output): void
    {
        if ($format === 'json') {
            $output->json(array_map($this->toArray(...), $books));
            return;
        }
        $output->table(
            ['Title', 'Author', 'ISBN', 'Genre', 'Year', 'Copies'],
            array_map(fn (Book $book): array => array_map(strval(...), array_values($this->toArray($book))), $books),
        );
    }

    /**
     * @return array{title: string, author: string, isbn: string, genre: ?string, year: ?int, copies: int}
     */
    private function toArray(Book $book): array
    {
        return [
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn->value,
            'genre' => $book->genre?->value,
            'year' => $book->year,
            'copies' => $book->copies,
        ];
    }
}
