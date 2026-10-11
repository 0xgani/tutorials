<?php

declare(strict_types=1);

namespace Bookshelf\Cli;

use InvalidArgumentException;

/**
 * The command line itself is wrong (missing argument, unknown option value). Exits with code 2.
 */
final class UsageError extends InvalidArgumentException
{
}
