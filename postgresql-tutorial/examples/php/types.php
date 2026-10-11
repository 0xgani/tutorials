<?php

declare(strict_types=1);

// How PostgreSQL types arrive in PHP. Run: php types.php

require __DIR__ . '/db.php';

$pdo = db();
$row = $pdo->query(<<<'SQL'
    SELECT 42::integer                          AS int4,
           9007199254740993::bigint             AS int8,
           12.50::numeric(10, 2)                AS price,
           0.1::double precision                AS float8,
           true                                 AS bool,
           timestamptz '2026-04-09 20:05:00+00' AS ts,
           '{"nib": "fine"}'::jsonb             AS doc,
           ARRAY['ink', 'pen']                  AS tags,
           NULL                                 AS nothing
    SQL)->fetch();
var_dump($row);

// Decoding is up to you: JSON with json_decode(), timestamps with DateTimeImmutable.
$doc = json_decode($row['doc'], true, flags: JSON_THROW_ON_ERROR);
$ts = new DateTimeImmutable($row['ts']);
echo $doc['nib'], ' / ', $ts->format(DATE_ATOM), "\n";

// Booleans need PDO::PARAM_BOOL: a plain false is sent as '', which isn't a boolean.
$stmt = $pdo->prepare('SELECT count(*) FROM products WHERE active = :active');
$stmt->bindValue('active', false, PDO::PARAM_BOOL);
$stmt->execute();
echo 'inactive products: ', $stmt->fetchColumn(), "\n";

try {
    $pdo->prepare('SELECT count(*) FROM products WHERE active = ?')->execute([false]);
} catch (PDOException $e) {
    echo $e->getMessage(), "\n";
}
