<?php

declare(strict_types=1);

namespace Bookshelf\Http;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Finds the controller method for a request's method and path.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{class-string, string}, access: Access}> */
    private array $routes = [];

    /** @param array{class-string, string} $handler */
    public function add(string $method, string $path, array $handler, Access $access = Access::Public): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => self::compile($path),
            'handler' => $handler,
            'access' => $access,
        ];
    }

    /**
     * Registers every public method of $class that has a #[Route] attribute.
     *
     * @param class-string $class
     */
    public function addController(string $class): void
    {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $this->add($route->method, $route->path, [$class, $method->getName()], $route->access);
            }
        }
    }

    /** @throws HttpException 404 when no route has this path, 405 when none accepts this method */
    public function match(string $method, string $path): RouteMatch
    {
        $method = $method === 'HEAD' ? 'GET' : $method;   // HEAD is a GET without the body
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            return new RouteMatch(
                $route['handler'],
                $route['access'],
                array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY),
            );
        }

        throw $allowed === [] ? HttpException::notFound() : HttpException::methodNotAllowed(array_unique($allowed));
    }

    /** Turns '/books/{id:\d+}' into '~^/books/(?P<id>\d+)$~'. */
    private static function compile(string $path): string
    {
        $regex = preg_replace_callback(
            '~\{([a-z_][a-z0-9_]*)(?::([^{}]+))?\}~i',
            fn (array $m): string => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $path,
        );
        if ($regex === null || preg_match("~^$regex$~", '') === false) {
            throw new InvalidArgumentException("Invalid route path $path");
        }

        return "~^$regex$~";
    }
}
