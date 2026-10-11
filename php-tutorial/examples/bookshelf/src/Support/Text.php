<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use Bookshelf\Domain\Isbn;

final class Text
{
    /** "The Left Hand of Darkness" -> "the-left-hand-of-darkness" */
    public static function slugify(string $title): string
    {
        return $title
            |> trim(...)
            |> mb_strtolower(...)
            |> (fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '-', $s))
            |> (fn (string $s): string => trim($s, '-'));
    }

    public static function isValidIsbn13(string $input): bool
    {
        return Isbn::isValid(preg_replace('/[\s-]+/', '', $input));
    }
}
