<?php

declare(strict_types=1);

namespace Bookshelf\Tests\Support;

use Bookshelf\Persistence\Database;
use Bookshelf\Persistence\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * A fresh in-memory SQLite database with all migrations applied, for every test.
 * In memory, so there is nothing to clean up and tests can't interfere with each other.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        $this->database = Database::sqlite(':memory:');
        new Migrator($this->database, dirname(__DIR__, 2) . '/migrations')->migrate();
    }
}
