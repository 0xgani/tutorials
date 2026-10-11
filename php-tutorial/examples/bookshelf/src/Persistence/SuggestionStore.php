<?php

declare(strict_types=1);

namespace Bookshelf\Persistence;

use Bookshelf\Support\Clock;
use DateTimeZone;
use PDO;

/**
 * Book suggestions sent by visitors. Plain rows rather than domain objects: they are only stored and listed.
 */
final readonly class SuggestionStore
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock,
    ) {
    }

    public function add(string $title, string $author, ?string $isbn, string $reason): void
    {
        $this->pdo
            ->prepare('INSERT INTO suggestions (created_at, title, author, isbn, reason) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $title,
                $author,
                $isbn,
                $reason,
            ]);
    }

    /** @return list<array{created_at: string, title: string, author: string, isbn: ?string, reason: string}> */
    public function latest(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT created_at, title, author, isbn, reason FROM suggestions ORDER BY id DESC LIMIT ?',
        );
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
