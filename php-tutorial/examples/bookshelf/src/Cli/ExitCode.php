<?php

declare(strict_types=1);

namespace Bookshelf\Cli;

/**
 * Process exit codes. 0 means success to every shell and CI system; anything else is a failure.
 */
enum ExitCode: int
{
    case Success = 0;
    case Failure = 1;
    case Usage = 2;
}
