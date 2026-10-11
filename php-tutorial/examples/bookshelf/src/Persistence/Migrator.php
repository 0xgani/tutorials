<?php

declare(strict_types=1);

namespace Bookshelf\Persistence;

use RuntimeException;

/**
 * Applies the numbered .sql files in a directory, each once, in order.
 *
 * Applied versions are recorded in the schema_migrations table, so running it again only applies new files.
 */
final readonly class Migrator
{
    public function __construct(
        private Database $database,
        private string $directory,
    ) {
    }

    /**
     * @return list<string> the files applied by this run
     */
    public function migrate(): array
    {
        $pdo = $this->database->pdo;
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL
        )');
        $done = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));

        $applied = [];
        foreach ($this->pending($done) as $version => $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read $file");
            }
            $this->database->transactional(function () use ($pdo, $sql, $version): void {
                $pdo->exec($sql);
                $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                    ->execute([$version, gmdate('Y-m-d H:i:s')]);
            });
            $applied[] = basename($file);
        }

        return $applied;
    }

    /**
     * @param array<string, int> $done
     * @return array<string, string> version => file, in order
     */
    private function pending(array $done): array
    {
        $pending = [];
        foreach (glob($this->directory . '/*.sql') ?: [] as $file) {
            $version = basename($file, '.sql');
            if (!isset($done[$version])) {
                $pending[$version] = $file;
            }
        }
        ksort($pending, SORT_STRING);

        return $pending;
    }
}
