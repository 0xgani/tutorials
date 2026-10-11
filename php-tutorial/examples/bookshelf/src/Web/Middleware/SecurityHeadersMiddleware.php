<?php

declare(strict_types=1);

namespace Bookshelf\Web\Middleware;

use Bookshelf\Http\Middleware;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;

/** Adds the browser protections from chapter 18 to every response. */
final readonly class SecurityHeadersMiddleware implements Middleware
{
    public function __construct(
        private bool $https = false,
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $response = $next->handle($request)
            ->withHeader('Content-Security-Policy', "default-src 'self'; object-src 'none'; base-uri 'none'; "
                . "form-action 'self'; frame-ancestors 'none'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $this->https ? $response->withHeader('Strict-Transport-Security', 'max-age=31536000') : $response;
    }
}
