<?php

declare(strict_types=1);

namespace Bookshelf\Domain;

use InvalidArgumentException;
use LogicException;

/**
 * A title in the catalog, with a number of physical copies. Entity.
 */
final class Book
{
    public private(set) int $available;

    public function __construct(
        public private(set) ?int $id,
        public readonly string $title,
        public readonly string $author,
        public readonly Isbn $isbn,
        public readonly ?Genre $genre = null,
        public readonly ?int $year = null,
        public readonly int $copies = 1,
        ?int $available = null,
    ) {
        if (trim($title) === '' || trim($author) === '') {
            throw new InvalidArgumentException('A book needs a title and an author');
        }
        if ($copies < 1) {
            throw new InvalidArgumentException('A book needs at least one copy');
        }
        $available ??= $copies;
        if ($available < 0 || $available > $copies) {
            throw new InvalidArgumentException("Available copies must be between 0 and $copies");
        }
        $this->available = $available;
    }

    /** Called by a repository once the database has assigned an id. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new LogicException("Book already has id {$this->id}");
        }
        $this->id = $id;
    }

    public function isAvailable(): bool
    {
        return $this->available > 0;
    }

    public function lendCopy(): void
    {
        if (!$this->isAvailable()) {
            throw BookNotAvailable::noCopiesLeft($this);
        }
        $this->available--;
    }

    public function returnCopy(): void
    {
        if ($this->available >= $this->copies) {
            throw new LogicException("All copies of '{$this->title}' are already on the shelf");
        }
        $this->available++;
    }
}
