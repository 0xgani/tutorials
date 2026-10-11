<?php

declare(strict_types=1);

// Connection and transaction helpers shared by the PHP examples.

/**
 * Opens a connection. Settings come from INKWELL_DSN if it's set, for example
 * 'pgsql:host=localhost;port=5432;dbname=inkwell;user=inkwell_app;password=...;sslmode=verify-full',
 * otherwise from libpq's PG* environment variables (PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD).
 */
function db(): PDO
{
    $dsn = getenv('INKWELL_DSN') ?: 'pgsql:application_name=inkwell-php';

    return new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,      // the default since PHP 8.0; be explicit
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // rows as ['column' => value]
    ]);
}

/**
 * Runs $fn($pdo) in a transaction and retries the whole transaction when PostgreSQL reports
 * a serialization failure (40001) or a deadlock (40P01).
 *
 * @template T
 * @param callable(PDO): T $fn
 * @return T
 */
function transaction(PDO $pdo, callable $fn, string $isolation = 'READ COMMITTED', int $retries = 3): mixed
{
    if (!in_array($isolation, ['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'], true)) {
        throw new InvalidArgumentException("unknown isolation level: $isolation");
    }

    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $pdo->exec("SET TRANSACTION ISOLATION LEVEL $isolation");
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retryable = $e instanceof PDOException && in_array($e->getCode(), ['40001', '40P01'], true);
            if ($retryable && $attempt <= $retries) {
                fwrite(STDERR, "attempt $attempt failed with {$e->getCode()}, retrying\n");
                usleep(random_int(0, 20_000 * 2 ** $attempt)); // exponential backoff with jitter
                continue;
            }
            throw $e;
        }
    }
}
