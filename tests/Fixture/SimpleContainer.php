<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Fixture;

use Psr\Container\ContainerInterface;

/**
 * Minimal PSR-11 container for testing — instantiates classes on-the-fly.
 */
final class SimpleContainer implements ContainerInterface
{
    /** @var array<string,mixed> */
    private array $services = [];

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): mixed
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (class_exists($id)) {
            return new $id();
        }

        throw new class ("Service \"$id\" not found.") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || class_exists($id);
    }
}
