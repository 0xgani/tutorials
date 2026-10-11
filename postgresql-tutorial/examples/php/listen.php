<?php

declare(strict_types=1);

// LISTEN/NOTIFY from PHP. Run: php listen.php

require __DIR__ . '/db.php';

$listener = db();
$listener->exec('LISTEN order_paid');

db()->prepare("SELECT pg_notify('order_paid', ?)")
    ->execute([json_encode(['order_id' => 11, 'customer_id' => 1])]);

// Wait up to 1000 ms for a notification; returns false if none arrived.
$notification = $listener->pgsqlGetNotify(PDO::FETCH_ASSOC, 1000);
var_dump($notification);
