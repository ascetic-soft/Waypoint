<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Cache\RouteCompiler;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\RouteMatchResult;
use AsceticSoft\Waypoint\RouteTrie;
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

    // ── fromCompiled (pre-built trie) ─────────────────────────────

    #[Test]
    public function fromCompiledMatchesRoutes(): void
    {
        // Build routes and trie manually
        $routes = [
            new Route('/users', ['GET'], ['C', 'm'], name: 'users.list'),
            new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'),
        ];

        $trie = new RouteTrie();
        foreach ($routes as $route) {
            $trie->insert($route, RouteTrie::parsePattern($route->getPattern()));
        }

        $collection = RouteCollection::fromCompiled($routes, $trie, []);

        $result = $collection->match('GET', '/users');
        self::assertSame('users.list', $result->route->getName());

        $result = $collection->match('GET', '/users/42');
        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function fromCompiledWithFallbackRoutes(): void
    {
        $static = new Route('/about', ['GET'], ['C', 'm']);
        $fallback = new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']);

        $trie = new RouteTrie();
        $trie->insert($static, RouteTrie::parsePattern('/about'));

        $collection = RouteCollection::fromCompiled([$static, $fallback], $trie, [$fallback]);

        $result = $collection->match('GET', '/files/prefix-hello.txt');
        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    #[Test]
    public function fromCompiledAllReturnsRoutes(): void
    {
        $routes = [
            new Route('/a', ['GET'], ['C', 'm'], name: 'a'),
            new Route('/b', ['GET'], ['C', 'm'], name: 'b'),
        ];

        $trie = new RouteTrie();
        $collection = RouteCollection::fromCompiled($routes, $trie, []);

        $all = $collection->all();
        self::assertCount(2, $all);
    }

    // ── fromCompiledRaw (Phase 2) ─────────────────────────────────

    #[Test]
    public function fromCompiledRawMatchesStaticRoute(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        $result = $collection->match('GET', '/about');
        self::assertSame('/about', $result->route->getPattern());
        self::assertSame([], $result->parameters);
    }

    #[Test]
    public function fromCompiledRawMatchesViaStaticTable(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        // Static table should provide O(1) lookup
        $result = $collection->match('GET', '/about');
        self::assertSame('/about', $result->route->getPattern());
    }

    #[Test]
    public function fromCompiledRawMatchesDynamicRoute(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        $result = $collection->match('GET', '/users/42');
        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function fromCompiledRawThrowsRouteNotFound(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        $this->expectException(RouteNotFoundException::class);
        $collection->match('GET', '/nonexistent');
    }

    #[Test]
    public function fromCompiledRawThrowsMethodNotAllowed(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        try {
            $collection->match('DELETE', '/about');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledRawFindByNameReturnsRoute(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        $route = $collection->findByName('about');
        self::assertNotNull($route);
        self::assertSame('/about', $route->getPattern());
    }

    #[Test]
    public function fromCompiledRawFindByNameReturnsNullForUnknown(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        self::assertNull($collection->findByName('nonexistent'));
    }

    #[Test]
    public function fromCompiledRawAllHydratesRoutes(): void
    {
        $cacheData = $this->buildPhase2Data();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        $all = $collection->all();
        self::assertCount(2, $all);
        self::assertInstanceOf(Route::class, $all[0]);
    }

    #[Test]
    public function fromCompiledRawFallbackRouteMatchesCorrectly(): void
    {
        $cacheData = $this->buildPhase2DataWithFallback();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        $result = $collection->match('GET', '/files/prefix-hello.txt');
        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    #[Test]
    public function fromCompiledRawFallbackRouteMethodNotAllowed(): void
    {
        $cacheData = $this->buildPhase2DataWithFallback();
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        try {
            $collection->match('POST', '/files/prefix-hello.txt');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    // ── fromCompiledMatcher (Phase 3) ─────────────────────────────

    #[Test]
    public function fromCompiledMatcherMatchesStaticRoute(): void
    {
        $collection = $this->buildPhase3Collection();

        $result = $collection->match('GET', '/about');
        self::assertSame('/about', $result->route->getPattern());
    }

    #[Test]
    public function fromCompiledMatcherMatchesDynamicRoute(): void
    {
        $collection = $this->buildPhase3Collection();

        $result = $collection->match('GET', '/users/42');
        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function fromCompiledMatcherThrowsRouteNotFound(): void
    {
        $collection = $this->buildPhase3Collection();

        $this->expectException(RouteNotFoundException::class);
        $collection->match('GET', '/nonexistent');
    }

    #[Test]
    public function fromCompiledMatcherThrowsMethodNotAllowed(): void
    {
        $collection = $this->buildPhase3Collection();

        try {
            $collection->match('DELETE', '/about');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledMatcherCollectsStaticMethodsFor405(): void
    {
        // Build a collection with only static routes and different methods
        $source = new RouteCollection();
        $source->add(new Route('/items', ['GET'], ['C', 'm'], name: 'items.get'));
        $source->add(new Route('/items', ['POST'], ['C', 'm'], name: 'items.post'));

        $collection = $this->compileAndLoadMatcher($source);

        try {
            $collection->match('DELETE', '/items');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('POST', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledMatcherDynamicMethodNotAllowed(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));
        $source->add(new Route('/users/{id:\d+}', ['PUT'], ['C', 'm'], name: 'users.update'));

        $collection = $this->compileAndLoadMatcher($source);

        try {
            $collection->match('DELETE', '/users/42');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('PUT', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledMatcherFindByNameReturnsRoute(): void
    {
        $collection = $this->buildPhase3Collection();

        $route = $collection->findByName('about');
        self::assertNotNull($route);
        self::assertSame('/about', $route->getPattern());
    }

    #[Test]
    public function fromCompiledMatcherFindByNameReturnsNull(): void
    {
        $collection = $this->buildPhase3Collection();

        self::assertNull($collection->findByName('nonexistent'));
    }

    #[Test]
    public function fromCompiledMatcherAllHydratesRoutes(): void
    {
        $collection = $this->buildPhase3Collection();

        $all = $collection->all();
        self::assertCount(2, $all);
        self::assertInstanceOf(Route::class, $all[0]);
    }

    #[Test]
    public function fromCompiledMatcherFallbackRouteMatches(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));

        $collection = $this->compileAndLoadMatcher($source);

        $result = $collection->match('GET', '/files/prefix-doc.txt');
        self::assertSame(['name' => 'doc'], $result->parameters);
    }

    #[Test]
    public function fromCompiledMatcherFallbackMethodNotAllowed(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));

        $collection = $this->compileAndLoadMatcher($source);

        try {
            $collection->match('POST', '/files/prefix-doc.txt');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledMatcherFindByNameThenAllHydrates(): void
    {
        $collection = $this->buildPhase3Collection();

        // First use findByName (does not hydrate)
        $route = $collection->findByName('about');
        self::assertNotNull($route);

        // Then call all() which should hydrate
        $all = $collection->all();
        self::assertCount(2, $all);
    }

    // ── Phase 3: fallback route iteration (skip non-matching) ──────

    #[Test]
    public function fromCompiledMatcherFallbackSkipsNonMatchingRoutes(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'prefix'));
        $source->add(new Route('/files/suffix-{name}.doc', ['GET'], ['C', 'm'], name: 'suffix'));

        $collection = $this->compileAndLoadMatcher($source);

        // Request matches second fallback route but not first
        $result = $collection->match('GET', '/files/suffix-hello.doc');
        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    // ── Phase 2: fallback route iteration (skip non-matching) ────

    #[Test]
    public function fromCompiledRawFallbackSkipsNonMatchingRoutes(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'prefix'));
        $source->add(new Route('/files/suffix-{name}.doc', ['GET'], ['C', 'm'], name: 'suffix'));

        $cacheData = $this->buildPhase2DataFromCollection($source);
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        // Request matches second fallback route but not first
        $result = $collection->match('GET', '/files/suffix-hello.doc');
        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    // ── Phase 2 compiled raw: trie method-not-allowed ─────────────

    #[Test]
    public function fromCompiledRawTrieMethodNotAllowed(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));
        $source->add(new Route('/users/{id:\d+}', ['PUT'], ['C', 'm'], name: 'users.update'));

        $cacheData = $this->buildPhase2DataFromCollection($source);
        $collection = RouteCollection::fromCompiledRaw($cacheData);

        try {
            $collection->match('DELETE', '/users/42');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('PUT', $e->getAllowedMethods());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Build Phase 2 cache data with static + dynamic routes.
     *
     * @return array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>}
     */
    private function buildPhase2Data(): array
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));

        return $this->buildPhase2DataFromCollection($source);
    }

    /**
     * Build Phase 2 cache data with a non-trie-compatible (fallback) route.
     *
     * @return array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>}
     */
    private function buildPhase2DataWithFallback(): array
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));

        return $this->buildPhase2DataFromCollection($source);
    }

    /**
     * @return array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>}
     */
    private function buildPhase2DataFromCollection(RouteCollection $source): array
    {
        $allRoutes = $source->all();
        $routeIndexMap = [];
        $routeData = [];
        $trie = new RouteTrie();
        $fallbackIndices = [];

        foreach ($allRoutes as $index => $route) {
            $routeIndexMap[spl_object_id($route)] = $index;
            $route->compile();

            $entry = $route->toArray();
            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $trie->insert($route, $segments);
            } else {
                $fallbackIndices[] = $index;
            }

            $routeData[] = $entry;
        }

        $staticTable = [];
        foreach ($allRoutes as $index => $route) {
            if ($route->getParameterNames() === []) {
                foreach ($route->getMethods() as $method) {
                    $key = $method . ':' . $route->getPattern();
                    if (!isset($staticTable[$key])) {
                        $staticTable[$key] = $index;
                    }
                }
            }
        }

        return [
            'routes' => $routeData,
            'trie' => $trie->toArray($routeIndexMap),
            'fallback' => $fallbackIndices,
            'staticTable' => $staticTable,
        ];
    }

    /**
     * Build a Phase 3 collection using RouteCompiler → CompiledMatcher.
     */
    private function buildPhase3Collection(): RouteCollection
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));

        return $this->compileAndLoadMatcher($source);
    }

    private function compileAndLoadMatcher(RouteCollection $source): RouteCollection
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_test_' . uniqid() . '.php';

        try {
            $compiler = new RouteCompiler();
            $compiler->compile($source, $cacheFile);

            return $compiler->load($cacheFile);
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }
}
