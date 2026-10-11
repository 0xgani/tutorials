<?php

declare(strict_types=1);

/*
 * The front controller: every request that isn't a static file runs this script.
 * It builds the request from PHP's globals, hands it to the application, and sends the response.
 */

use Bookshelf\Http\Request;
use Bookshelf\Web\Kernel;

// With PHP's built-in server, let it serve existing files (CSS, images) itself.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$container = require dirname(__DIR__) . '/config/container.php';
$container->get(Kernel::class)->handle(Request::fromGlobals())->send();
