<?php

declare(strict_types=1);

namespace Bookshelf\Http;

/** Anything that turns a request into a response. The same shape as PSR-15's RequestHandlerInterface. */
interface RequestHandler
{
    public function handle(Request $request): Response;
}
