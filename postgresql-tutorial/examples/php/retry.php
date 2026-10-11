<?php

declare(strict_types=1);

// A serialization failure, and the transaction() helper retrying it. Run: php retry.php

require __DIR__ . '/db.php';

$a = db();
$b = db();
$a->exec('UPDATE products SET stock = 3 WHERE id = 2'); // the seed value, so the demo can be repeated

// Two sessions read the Heron pen's stock in REPEATABLE READ transactions...
foreach ([$a, $b] as $pdo) {
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
}
$stockA = $a->query('SELECT stock FROM products WHERE id = 2')->fetchColumn();
$stockB = $b->query('SELECT stock FROM products WHERE id = 2')->fetchColumn();
echo "A and B read stock $stockA and $stockB\n";

// ...A writes and commits first...
$a->prepare('UPDATE products SET stock = ? WHERE id = 2')->execute([$stockA - 1]);
$a->commit();

// ...so B's write would overwrite a change it has never seen.
try {
    $b->prepare('UPDATE products SET stock = ? WHERE id = 2')->execute([$stockB - 1]);
    $b->commit();
} catch (PDOException $e) {
    echo 'B failed: ', $e->getCode(), ' ', $e->errorInfo[2], "\n";
    $b->rollBack();
}

// The fix: run the whole read-modify-write through transaction(), which retries from the start.
$stock = transaction($b, function (PDO $pdo): int {
    $stock = (int) $pdo->query('SELECT stock FROM products WHERE id = 2')->fetchColumn();
    $pdo->prepare('UPDATE products SET stock = ? WHERE id = 2')->execute([$stock - 1]);
    return $stock - 1;
}, 'REPEATABLE READ');
echo "B committed, stock now $stock\n";
