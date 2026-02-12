<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\RouteMatchResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteCollectionTest extends TestCase
{
    #[Test]
    public function matchStaticRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm']));

        $result = $collection->match('GET', '/about');

        self::assertInstanceOf(RouteMatchResult::class, $result);
        self::assertSame('/about', $result->route->getPattern());
        self::assertSame([], $result->parameters);
    }

    #[Test]
    public function matchDynamicRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm']));

        $result = $collection->match('GET', '/users/42');

        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function matchIsCaseInsensitiveForMethod(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/test', ['POST'], ['C', 'm']));

        $result = $collection->match('post', '/test');

        self::assertSame('/test', $result->route->getPattern());
    }

    #[Test]
    public function throwsRouteNotFoundForNoMatch(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm']));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionCode(404);

        $collection->match('GET', '/nonexistent');
    }

    #[Test]
    public function throwsMethodNotAllowedWhenUriMatchesButMethodDoesNot(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/items', ['GET'], ['C', 'm']));
        $collection->add(new Route('/items', ['POST'], ['C', 'm']));

        try {
            $collection->match('DELETE', '/items');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(405, $e->getCode());
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('POST', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function matchRespectsPriority(): void
    {
        $collection = new RouteCollection();

        // Lower priority, added first
        $collection->add(new Route('/users/{name}', ['GET'], ['C', 'lowPriority'], priority: 0));
        // Higher priority, added second
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'highPriority'], priority: 10));

        // "42" matches both patterns, but higher priority should win
        $result = $collection->match('GET', '/users/42');

        self::assertSame('/users/{id:\d+}', $result->route->getPattern());
    }

    #[Test]
    public function matchPreservesInsertionOrderForEqualPriority(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/a', ['GET'], ['C', 'first'], name: 'first'));
        $collection->add(new Route('/a', ['GET'], ['C', 'second'], name: 'second'));

        $result = $collection->match('GET', '/a');

        self::assertSame('first', $result->route->getName());
    }

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

    #[Test]
    public function emptyCollectionThrowsNotFound(): void
    {
        $collection = new RouteCollection();

        $this->expectException(RouteNotFoundException::class);

        $collection->match('GET', '/anything');
    }

    // ── Fallback (non-trie-compatible) routes ────────────────────

    #[Test]
    public function matchFallbackRouteWithNonTrieCompatiblePattern(): void
    {
        $collection = new RouteCollection();
        // Mixed static + parameter in one segment — NOT trie-compatible.
        $collection->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']));

        $result = $collection->match('GET', '/files/prefix-hello.txt');

        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    #[Test]
    public function throwsMethodNotAllowedForFallbackRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']));

        try {
            $collection->match('POST', '/files/prefix-hello.txt');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fallbackRouteNotFoundThrows404(): void
    {
        $collection = new RouteCollection();
        // Only a non-trie-compatible route, nothing matches
        $collection->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']));

        $this->expectException(RouteNotFoundException::class);

        $collection->match('GET', '/something/else');
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
