<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Cli\UsageError;
use Bookshelf\Domain\Isbn;
use InvalidArgumentException;

final class IsbnCommand implements Command
{
    public function name(): string
    {
        return 'isbn';
    }

    public function description(): string
    {
        return 'Check ISBN-13 numbers given as arguments or on standard input';
    }

    public function help(): string
    {
        return <<<'TEXT'
            Usage: bookshelf isbn [<isbn>...] [--quiet|-q]

            Prints each valid ISBN in its normalized form. With no arguments, reads one ISBN per line
            from standard input. Exits with code 1 if any ISBN is invalid.

            Options:
              -q, --quiet  Print nothing; only set the exit code
            TEXT;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $candidates = $input->arguments !== [] ? $input->arguments : $this->linesFromStdin();
        if ($candidates === []) {
            throw new UsageError('No ISBNs given');
        }

        $quiet = $input->flag('quiet', 'q');
        $exitCode = ExitCode::Success;
        foreach ($candidates as $candidate) {
            try {
                $isbn = new Isbn($candidate);
                if (!$quiet) {
                    $output->line($isbn->value);
                }
            } catch (InvalidArgumentException $e) {
                if (!$quiet) {
                    $output->error($e->getMessage());
                }
                $exitCode = ExitCode::Failure;
            }
        }

        return $exitCode;
    }

    /**
     * @return list<string>
     */
    private function linesFromStdin(): array
    {
        if (stream_isatty(STDIN)) {
            return [];
        }

        $lines = [];
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
