<?php

declare(strict_types=1);

// Reacting to specific database errors by their SQLSTATE code. Run: php errors.php

require __DIR__ . '/db.php';

$pdo = db();

/** Turns the errors the application expects into answers; anything else is rethrown. */
function toResponse(PDOException $e): array
{
    // errorInfo is [SQLSTATE, driver error code, message (with DETAIL and HINT lines)]
    [$sqlstate, , $message] = $e->errorInfo;

    return match ($sqlstate) {
        '23505' => ['status' => 409, 'message' => 'already exists'],
        '23503' => ['status' => 422, 'message' => 'refers to a row that does not exist'],
        'IW001' => ['status' => 409, 'message' => strtok(substr($message, strlen('ERROR:  ')), "\n")],
        default => throw $e,
    };
}

$attempts = [
    'duplicate e-mail' => ['INSERT INTO customers (email, full_name, country) VALUES (?, ?, ?) RETURNING id',
        ['ana@example.com', 'Ana Again', 'BR']],
    'too many pens' => ['SELECT place_order(?, ?)', [5, json_encode([['product_id' => 2, 'quantity' => 5]])]],
];

foreach ($attempts as $label => [$sql, $params]) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo $label, ': ', json_encode(['status' => 200, 'result' => $stmt->fetch()]), "\n";
    } catch (PDOException $e) {
        echo $label, ': ', json_encode(toResponse($e)), "\n";
    }
}

try {
    $pdo->query('SELECT 1 / 0');
} catch (PDOException $e) {
    echo 'unexpected: ', $e->getCode(), ' ', $e->getMessage(), "\n";
}
