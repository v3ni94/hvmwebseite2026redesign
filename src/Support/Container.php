<?php

declare(strict_types=1);

namespace Hvm\Support;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Minimaler Dienstcontainer mit Autowiring über Konstruktor-Typen.
 * Jeder Dienst wird pro Anfrage einmal erzeugt (geteilte Instanz).
 */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $service): void
    {
        $this->instances[$id] = $service;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new \LogicException(sprintf('Zirkuläre Abhängigkeit bei %s.', $id));
        }
        $this->resolving[$id] = true;
        try {
            $service = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->autowire($id);
        } finally {
            unset($this->resolving[$id]);
        }

        return $this->instances[$id] = $service;
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Dienst %s ist nicht registriert.', $class));
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \LogicException(sprintf('%s ist nicht instanziierbar.', $class));
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }
            throw new \LogicException(sprintf('Parameter $%s von %s kann nicht aufgelöst werden.', $parameter->getName(), $class));
        }

        return $reflection->newInstanceArgs($args);
    }
}
