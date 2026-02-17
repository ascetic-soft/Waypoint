<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteCollectionTest extends TestCase
{
    #[Test]
    public function allReturnsSortedRoutes(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/low', ['GET'], ['C', 'm'], name: 'low', priority: 0));
        $collection->add(new Route('/high', ['GET'], ['C', 'm'], name: 'high', priority: 10));
        $collection->add(new Route('/mid', ['GET'], ['C', 'm'], name: 'mid', priority: 5));

        $all = $collection->all();

        self::assertSame('high', $all[0]->getName());
        self::assertSame('mid', $all[1]->getName());
        self::assertSame('low', $all[2]->getName());
    }

    // ── findByName ──────────────────────────────────────────────

    #[Test]
    public function findByNameReturnsCorrectRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users', ['GET'], ['C', 'm'], name: 'users.list'));
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));

        $route = $collection->findByName('users.show');

        self::assertNotNull($route);
        self::assertSame('/users/{id:\d+}', $route->getPattern());
    }

    #[Test]
    public function findByNameReturnsNullForUnknownName(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users', ['GET'], ['C', 'm'], name: 'users.list'));

        self::assertNull($collection->findByName('nonexistent'));
    }

    #[Test]
    public function findByNameIgnoresUnnamedRoutes(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/unnamed', ['GET'], ['C', 'm']));
        $collection->add(new Route('/named', ['GET'], ['C', 'm'], name: 'named'));

        self::assertNull($collection->findByName(''));
        self::assertNotNull($collection->findByName('named'));
    }

    #[Test]
    public function findByNameLastRegisteredWinsForDuplicateNames(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/first', ['GET'], ['C', 'm'], name: 'dup'));
        $collection->add(new Route('/second', ['GET'], ['C', 'm'], name: 'dup'));

        $route = $collection->findByName('dup');

        self::assertNotNull($route);
        self::assertSame('/second', $route->getPattern());
    }

    #[Test]
    public function findByNameIndexIsInvalidatedAfterAdd(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/old', ['GET'], ['C', 'm'], name: 'route'));

        // Build index
        self::assertSame('/old', $collection->findByName('route')?->getPattern());

        // Add a new route with the same name
        $collection->add(new Route('/new', ['GET'], ['C', 'm'], name: 'route'));

        // Index should be rebuilt and reflect the new route
        self::assertSame('/new', $collection->findByName('route')?->getPattern());
    }
}
