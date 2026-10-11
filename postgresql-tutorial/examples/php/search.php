<?php

declare(strict_types=1);

// Full-text search through search_products() (chapters 13 and 19).
// Run: php search.php blue ink

require __DIR__ . '/db.php';

$terms = implode(' ', array_slice($argv, 1)) ?: 'blue ink';

$stmt = db()->prepare('SELECT id, name, round(rank::numeric, 3) AS rank, snippet FROM search_products(:terms, :limit)');
$stmt->execute(['terms' => $terms, 'limit' => 5]);
$rows = $stmt->fetchAll();

printf("%d result(s) for \"%s\"\n", count($rows), $terms);
foreach ($rows as $row) {
    printf("%d\t%s\t%s\t%s\n", $row['id'], $row['rank'], $row['name'], $row['snippet']);
}
