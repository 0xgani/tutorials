<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use PDO;
use PDOException;

/**
 * GET /health, for load balancers, container orchestrators and uptime monitors: 200 when the application can
 * reach its database, 503 when it can't. Anything other than a 2xx counts as unhealthy.
 */
final readonly class HealthController
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    #[Route('GET', '/health')]
    public function check(): Response
    {
        try {
            $this->pdo->query('SELECT 1');
            $response = Response::json(['status' => 'ok']);
        } catch (PDOException $e) {
            error_log('Health check failed: ' . $e->getMessage());
            $response = Response::json(['status' => 'unavailable'], 503);
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }
}
