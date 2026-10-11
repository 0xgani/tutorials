<?php

declare(strict_types=1);

namespace Bookshelf\Persistence;

use PDO;
use Pdo\Sqlite;
use RuntimeException;
use Throwable;

/**
 * The application's database connection, and its transaction boundary.
 */
final readonly class Database
{
    public function __construct(
        public PDO $pdo,
    ) {
    }

    /** Opens (and creates, if needed) an SQLite database file, or ':memory:' for a throwaway one. */
    public static function sqlite(string $path): self
    {
        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create the database directory $directory");
            }
        }

        $pdo = new Sqlite('sqlite:' . $path, options: [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,                                           // wait up to 5 s for a lock
            Sqlite::ATTR_TRANSACTION_MODE => Sqlite::TRANSACTION_MODE_IMMEDIATE,   // PHP 8.5+
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return new self($pdo);
    }

    /** The database configured by the BOOKSHELF_DATABASE environment variable, or var/bookshelf.sqlite. */
    public static function fromEnvironment(string $projectRoot): self
    {
        $path = getenv('BOOKSHELF_DATABASE');

        return self::sqlite($path !== false && $path !== '' ? $path : $projectRoot . '/var/bookshelf.sqlite');
    }

    /**
     * Runs $work in a transaction: commits if it returns, rolls back if it throws.
     * Called inside another transaction, it simply joins it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
