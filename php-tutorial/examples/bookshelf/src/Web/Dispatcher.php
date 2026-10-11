<?php

declare(strict_types=1);

namespace Bookshelf\Web;

use Bookshelf\Container\Container;
use Bookshelf\Domain\Member;
use Bookshelf\Http\Access;
use Bookshelf\Http\HttpException;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;
use Bookshelf\Http\Router;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The innermost handler: routes the request, checks the route's access level, and calls the controller method
 * with the arguments it asks for.
 */
final readonly class Dispatcher implements RequestHandler
{
    public function __construct(
        private Router $router,
        private Container $container,
    ) {
    }

    public function handle(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        $member = $request->attribute('member');

        // Deny by default: the route declares who may use it, and nothing below runs for anyone else.
        if ($match->access !== Access::Public && !$member instanceof Member) {
            return Response::redirect('/login?' . http_build_query(['next' => $request->target()]));
        }
        if ($match->access === Access::Staff && !$member->isStaff()) {
            throw HttpException::forbidden('This page is for library staff only.');
        }

        [$class, $method] = $match->handler;
        $controller = $this->container->get($class);
        $arguments = $this->arguments(new ReflectionMethod($controller, $method), $request, $match->parameters);

        return $controller->$method(...$arguments);
    }

    /**
     * Decides each argument from the parameter's type and name: the Request, the logged-in Member,
     * or a path parameter of the same name (converted to int when the parameter is an int).
     *
     * @param array<string, string> $parameters
     * @return list<mixed>
     */
    private function arguments(ReflectionMethod $method, Request $request, array $parameters): array
    {
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $name = $parameter->getName();

            $arguments[] = match (true) {
                $typeName === Request::class => $request,
                $typeName === Member::class => $request->attribute('member'),
                isset($parameters[$name]) && $typeName === 'int' => (int) $parameters[$name],
                isset($parameters[$name]) => $parameters[$name],
                $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                default => throw new LogicException("Cannot supply \$$name for {$method->class}::{$method->name}()"),
            };
        }

        return $arguments;
    }
}
