<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

final class EntityNotFound extends BookshelfException
{
    public static function book(int $id): self
    {
        return new self("No book with id $id");
    }

    public static function member(int $id): self
    {
        return new self("No member with id $id");
    }

    public static function loan(int $id): self
    {
        return new self("No loan with id $id");
    }
}
