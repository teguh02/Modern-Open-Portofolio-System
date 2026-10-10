<?php

declare(strict_types=1);

namespace Mops\Core;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Tiny lazy service container with constructor autowiring. Slim 4 wraps any
 * PSR-11 container passed to AppFactory::create().
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * @param callable(self): mixed $factory
     */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function setInstance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
        unset($this->factories[$id]);
    }

    /**
     * Register a class so it is built from its constructor type hints.
     */
    public function bind(string $class): void
    {
        $this->set($class, fn (self $container): object => $container->autowire($class));
    }

    /**
     * @param array<int, string> $classes
     */
    public function bindMany(array $classes): void
    {
        foreach ($classes as $class) {
            $this->bind($class);
        }
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new class("Container has no entry '{$id}'.") extends RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    /**
     * Build an object by resolving constructor type hints from this container.
     */
    public function autowire(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $name = $type->getName();

                if ($this->has($name)) {
                    $arguments[] = $this->get($name);
                    continue;
                }

                // Concrete class types are autowired recursively.
                if (class_exists($name) && (new ReflectionClass($name))->isInstantiable()) {
                    $arguments[] = $this->autowire($name);
                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException(
                "Cannot autowire {$class}::\${$parameter->getName()} — register it in the container.",
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
