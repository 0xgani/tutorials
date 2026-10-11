<?php

declare(strict_types=1);

namespace Bookshelf\Container;

use Closure;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A small dependency-injection container.
 *
 * get() returns one shared instance per id. A service is built by its factory if one was registered with set(),
 * otherwise by "autowiring": calling the class's constructor with the services its parameter types name.
 * The same idea as PSR-11's ContainerInterface (get/has), plus the wiring.
 */
final class Container
{
    /** @var array<string, Closure(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, true> ids being built right now, to detect circular dependencies */
    private array $building = [];

    /** @param Closure(self): object $factory */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : object)
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->building[$id])) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', array_keys($this->building)) . " -> $id");
        }

        $this->building[$id] = true;
        try {
            $service = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->autowire($id);
        } finally {
            unset($this->building[$id]);
        }

        return $this->instances[$id] = $service;
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new ContainerException("No factory for '$class', and it is not a class that can be built automatically");
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("$class is an interface or abstract class; register a factory for it");
        }

        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new ContainerException(
                    "Cannot autowire \${$parameter->getName()} of $class::__construct(); register a factory for $class",
                );
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
