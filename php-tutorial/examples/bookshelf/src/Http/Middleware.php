<?php

declare(strict_types=1);

namespace Bookshelf\Http;

/**
 * A layer around the application. It can change the request, answer it itself, or call $next and change the
 * response. The same shape as PSR-15's MiddlewareInterface.
 */
interface Middleware
{
    public function process(Request $request, RequestHandler $next): Response;
}
