<?php

declare(strict_types=1);

namespace Bookshelf\Web\Middleware;

use Bookshelf\Http\HttpException;
use Bookshelf\Http\Middleware;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;
use Bookshelf\Security\Csrf;
use Bookshelf\Web\Session;

/** Rejects every state-changing request that doesn't carry the session's CSRF token. */
final readonly class CsrfMiddleware implements Middleware
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private Session $session,
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        if (!in_array($request->method, self::SAFE_METHODS, true)
            && !Csrf::isValid($this->session->get('csrf'), $request->body['_csrf'] ?? $request->header('X-CSRF-Token'))) {
            throw HttpException::forbidden('This form has expired. Please go back, reload the page and try again.');
        }

        return $next->handle($request);
    }
}
