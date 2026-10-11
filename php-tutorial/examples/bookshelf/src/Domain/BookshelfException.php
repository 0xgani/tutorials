<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use RuntimeException;

/**
 * Base class for every failure the Bookshelf domain reports on purpose.
 */
class BookshelfException extends RuntimeException
{
}
