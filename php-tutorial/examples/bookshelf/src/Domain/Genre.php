<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

enum Genre: string
{
    case ScienceFiction = 'sf';
    case Fantasy = 'fantasy';
    case Crime = 'crime';
    case Classic = 'classic';
    case NonFiction = 'non-fiction';

    public function label(): string
    {
        return match ($this) {
            self::ScienceFiction => 'Science fiction',
            self::Fantasy => 'Fantasy',
            self::Crime => 'Crime',
            self::Classic => 'Classic',
            self::NonFiction => 'Non-fiction',
        };
    }
}
