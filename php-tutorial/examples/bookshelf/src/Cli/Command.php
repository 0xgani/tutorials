<?php

declare(strict_types=1);

namespace Bookshelf\Cli;

interface Command
{
    /** The word typed after the program name, e.g. "import". */
    public function name(): string;

    /** One line for the command list. */
    public function description(): string;

    /** The full help text shown by `bookshelf <command> --help`. */
    public function help(): string;

    public function run(Input $input, Output $output): ExitCode;
}
