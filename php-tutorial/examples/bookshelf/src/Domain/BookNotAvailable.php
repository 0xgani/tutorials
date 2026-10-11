<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

final class BookNotAvailable extends BookshelfException
{
    public static function noCopiesLeft(Book $book): self
    {
        return new self("No copies of '{$book->title}' are available");
    }
}
