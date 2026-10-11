<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Domain\Book;
use Bookshelf\Import\CsvBookReader;

final class ImportBooksCommand implements Command
{
    public function __construct(
        private readonly CsvBookReader $reader,
    ) {
    }

    public function name(): string
    {
        return 'import';
    }

    public function description(): string
    {
        return 'Read books from a CSV file and report what would be imported';
    }

    public function help(): string
    {
        return <<<'TEXT'
            Usage: bookshelf import <file> [--format=table|json] [--strict]

            Reads a CSV file with the header title,author,isbn,genre,year,copies.
            Use - as the file name to read standard input.

            Options:
              --format=table|json  How to print the valid books (default: table)
              --strict             Exit with code 1 if any line is invalid
            TEXT;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $file = $input->requireArgument(0, 'file');
        $format = $input->option('format', 'table', ['table', 'json']);
        $path = $file === '-' ? 'php://stdin' : $file;

        $books = [];
        $invalid = 0;
        foreach ($this->reader->read($path) as $line => $result) {
            if ($result instanceof Book) {
                $books[] = $result;
            } else {
                $output->warning("line $line skipped: {$result->reason}");
                $invalid++;
            }
        }

        if ($format === 'json') {
            $output->json(array_map($this->toArray(...), $books));
        } else {
            $output->table(
                ['Title', 'Author', 'ISBN', 'Genre', 'Year', 'Copies'],
                array_map(fn (Book $book): array => array_map(strval(...), array_values($this->toArray($book))), $books),
            );
        }

        $output->success(sprintf('%d books ready to import, %d skipped', count($books), $invalid));

        return $invalid > 0 && $input->flag('strict') ? ExitCode::Failure : ExitCode::Success;
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
