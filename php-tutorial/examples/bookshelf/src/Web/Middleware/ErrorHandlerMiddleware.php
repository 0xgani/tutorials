<?php

declare(strict_types=1);

namespace Bookshelf\Web\Middleware;

use Bookshelf\Http\HttpException;
use Bookshelf\Http\Middleware;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;
use Bookshelf\Web\View;
use Throwable;

/**
 * The outermost layer: turns exceptions into error pages. HttpExceptions become their status (404, 403, ...);
 * anything else is a bug, logged in full and shown as a 500 without details (unless $debug is on).
 */
final readonly class ErrorHandlerMiddleware implements Middleware
{
    public function __construct(
        private View $view,
        private bool $debug = false,
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        try {
            return $next->handle($request);
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status, $e->getMessage());
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        } catch (Throwable $e) {
            error_log(sprintf('%s %s: %s', $request->method, $request->path, $e));   // message and stack trace

            return $this->error($request, 500, $this->debug
                ? $e::class . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
                : 'Something went wrong on our side. Please try again later.');
        }
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if (str_starts_with($request->path, '/api/')) {
            return Response::json(['error' => $message], $status);
        }

        $title = match ($status) {
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            500 => 'Server error',
            default => 'Error',
        };
        try {
            return $this->view->page($title, 'message', ['message' => $message], $status);
        } catch (Throwable) {
            // The error page itself failed (a broken template, no database): fall back to plain text.
            return new Response($message, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }
}
