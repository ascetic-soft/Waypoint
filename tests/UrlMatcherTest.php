<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Cache\RouteCompiler;
use AsceticSoft\Waypoint\CompiledArrayMatcher;
use AsceticSoft\Waypoint\CompiledClassMatcher;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\RouteMatchResult;
use AsceticSoft\Waypoint\RouteTrie;
use AsceticSoft\Waypoint\TrieMatcher;
use AsceticSoft\Waypoint\UrlMatcherInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlMatcherTest extends TestCase
{
    // ── Phase 1: runtime matching (TrieMatcher) ─────────────────

    #[Test]
    public function matchStaticRoute(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/about', ['GET'], ['C', 'm']),
        );

        $result = $matcher->match('GET', '/about');

        self::assertInstanceOf(RouteMatchResult::class, $result);
        self::assertSame('/about', $result->route->getPattern());
        self::assertSame([], $result->parameters);
    }

    #[Test]
    public function matchDynamicRoute(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/users/{id:\d+}', ['GET'], ['C', 'm']),
        );

        $result = $matcher->match('GET', '/users/42');

        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function matchIsCaseInsensitiveForMethod(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/test', ['POST'], ['C', 'm']),
        );

        $result = $matcher->match('post', '/test');

        self::assertSame('/test', $result->route->getPattern());
    }

    #[Test]
    public function throwsRouteNotFoundForNoMatch(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/about', ['GET'], ['C', 'm']),
        );

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionCode(404);

        $matcher->match('GET', '/nonexistent');
    }

    #[Test]
    public function throwsMethodNotAllowedWhenUriMatchesButMethodDoesNot(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/items', ['GET'], ['C', 'm']),
            new Route('/items', ['POST'], ['C', 'm']),
        );

        try {
            $matcher->match('DELETE', '/items');
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
        $matcher = $this->createTrieMatcher(
            new Route('/users/{name}', ['GET'], ['C', 'lowPriority'], priority: 0),
            new Route('/users/{id:\d+}', ['GET'], ['C', 'highPriority'], priority: 10),
        );

        $result = $matcher->match('GET', '/users/42');

        self::assertSame('/users/{id:\d+}', $result->route->getPattern());
    }

    #[Test]
    public function matchPreservesInsertionOrderForEqualPriority(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/a', ['GET'], ['C', 'first'], name: 'first'),
            new Route('/a', ['GET'], ['C', 'second'], name: 'second'),
        );

        $result = $matcher->match('GET', '/a');

        self::assertSame('first', $result->route->getName());
    }

    #[Test]
    public function emptyCollectionThrowsNotFound(): void
    {
        $matcher = new TrieMatcher(new RouteCollection());

        $this->expectException(RouteNotFoundException::class);

        $matcher->match('GET', '/anything');
    }

    // ── Fallback (non-trie-compatible) routes ────────────────────

    #[Test]
    public function matchFallbackRouteWithNonTrieCompatiblePattern(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']),
        );

        $result = $matcher->match('GET', '/files/prefix-hello.txt');

        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    #[Test]
    public function throwsMethodNotAllowedForFallbackRoute(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']),
        );

        try {
            $matcher->match('POST', '/files/prefix-hello.txt');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fallbackRouteNotFoundThrows404(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']),
        );

        $this->expectException(RouteNotFoundException::class);

        $matcher->match('GET', '/something/else');
    }

    // ── findByName ──────────────────────────────────────────────

    #[Test]
    public function findByNameReturnsCorrectRoute(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/users', ['GET'], ['C', 'm'], name: 'users.list'),
            new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'),
        );

        $route = $matcher->findByName('users.show');

        self::assertNotNull($route);
        self::assertSame('/users/{id:\d+}', $route->getPattern());
    }

    #[Test]
    public function findByNameReturnsNullForUnknownName(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/users', ['GET'], ['C', 'm'], name: 'users.list'),
        );

        self::assertNull($matcher->findByName('nonexistent'));
    }

    // ── getRouteCollection ──────────────────────────────────────

    #[Test]
    public function getRouteCollectionReturnsCollection(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/a', ['GET'], ['C', 'm']));
        $collection->add(new Route('/b', ['GET'], ['C', 'm']));

        $matcher = new TrieMatcher($collection);

        self::assertSame($collection, $matcher->getRouteCollection());
        self::assertCount(2, $matcher->getRouteCollection()->all());
    }

    // ── CompiledArrayMatcher (Phase 2) ──────────────────────────

    #[Test]
    public function fromCompiledRawMatchesStaticRoute(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('GET', '/about');
        self::assertSame('/about', $result->route->getPattern());
        self::assertSame([], $result->parameters);
    }

    #[Test]
    public function fromCompiledRawMatchesViaStaticTable(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('GET', '/about');
        self::assertSame('/about', $result->route->getPattern());
    }

    #[Test]
    public function fromCompiledRawMatchesDynamicRoute(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('GET', '/users/42');
        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function fromCompiledRawThrowsRouteNotFound(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $this->expectException(RouteNotFoundException::class);
        $matcher->match('GET', '/nonexistent');
    }

    #[Test]
    public function fromCompiledRawThrowsMethodNotAllowed(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        try {
            $matcher->match('DELETE', '/about');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledRawFindByNameReturnsRoute(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $route = $matcher->findByName('about');
        self::assertNotNull($route);
        self::assertSame('/about', $route->getPattern());
    }

    #[Test]
    public function fromCompiledRawFindByNameReturnsNullForUnknown(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        self::assertNull($matcher->findByName('nonexistent'));
    }

    #[Test]
    public function fromCompiledRawFindByNameIsLazyAndDoesNotHydrateAllRoutes(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $route = $matcher->findByName('about');
        self::assertNotNull($route);
        self::assertSame('/about', $route->getPattern());

        $ref = new \ReflectionProperty($matcher, 'routeCache');
        $cache = $ref->getValue($matcher);

        self::assertCount(1, $cache, 'Only the requested route should be materialised');
    }

    #[Test]
    public function fromCompiledRawFindByNameCachesAcrossCalls(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $first = $matcher->findByName('about');
        $second = $matcher->findByName('about');

        self::assertSame($first, $second, 'Repeated lookups should return the same Route instance');
    }

    #[Test]
    public function fromCompiledRawGetRouteCollectionHydratesRoutes(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $all = $matcher->getRouteCollection()->all();
        self::assertCount(2, $all);
        self::assertInstanceOf(Route::class, $all[0]);
    }

    #[Test]
    public function fromCompiledRawFallbackRouteMatchesCorrectly(): void
    {
        $cacheData = $this->buildPhase2DataWithFallback();
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('GET', '/files/prefix-hello.txt');
        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    #[Test]
    public function fromCompiledRawFallbackRouteMethodNotAllowed(): void
    {
        $cacheData = $this->buildPhase2DataWithFallback();
        $matcher = new CompiledArrayMatcher($cacheData);

        try {
            $matcher->match('POST', '/files/prefix-hello.txt');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    // ── CompiledClassMatcher (Phase 3) ──────────────────────────

    #[Test]
    public function fromCompiledMatcherMatchesStaticRoute(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $result = $matcher->match('GET', '/about');
        self::assertSame('/about', $result->route->getPattern());
    }

    #[Test]
    public function fromCompiledMatcherMatchesDynamicRoute(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $result = $matcher->match('GET', '/users/42');
        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function fromCompiledMatcherThrowsRouteNotFound(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $this->expectException(RouteNotFoundException::class);
        $matcher->match('GET', '/nonexistent');
    }

    #[Test]
    public function fromCompiledMatcherThrowsMethodNotAllowed(): void
    {
        $matcher = $this->buildPhase3Matcher();

        try {
            $matcher->match('DELETE', '/about');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledMatcherCollectsStaticMethodsFor405(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/items', ['GET'], ['C', 'm'], name: 'items.get'));
        $source->add(new Route('/items', ['POST'], ['C', 'm'], name: 'items.post'));

        $matcher = $this->compileAndLoadMatcher($source);

        try {
            $matcher->match('DELETE', '/items');
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

        $matcher = $this->compileAndLoadMatcher($source);

        try {
            $matcher->match('DELETE', '/users/42');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('PUT', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledMatcherFindByNameReturnsRoute(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $route = $matcher->findByName('about');
        self::assertNotNull($route);
        self::assertSame('/about', $route->getPattern());
    }

    #[Test]
    public function fromCompiledMatcherFindByNameReturnsNull(): void
    {
        $matcher = $this->buildPhase3Matcher();

        self::assertNull($matcher->findByName('nonexistent'));
    }

    #[Test]
    public function fromCompiledMatcherGetRouteCollectionHydratesRoutes(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $all = $matcher->getRouteCollection()->all();
        self::assertCount(2, $all);
        self::assertInstanceOf(Route::class, $all[0]);
    }

    #[Test]
    public function fromCompiledMatcherFallbackRouteMatches(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));

        $matcher = $this->compileAndLoadMatcher($source);

        $result = $matcher->match('GET', '/files/prefix-doc.txt');
        self::assertSame(['name' => 'doc'], $result->parameters);
    }

    #[Test]
    public function fromCompiledMatcherFallbackMethodNotAllowed(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));

        $matcher = $this->compileAndLoadMatcher($source);

        try {
            $matcher->match('POST', '/files/prefix-doc.txt');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fromCompiledMatcherFindByNameThenGetRouteCollectionHydrates(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $route = $matcher->findByName('about');
        self::assertNotNull($route);

        $all = $matcher->getRouteCollection()->all();
        self::assertCount(2, $all);
    }

    // ── Phase 3: fallback route iteration (skip non-matching) ──────

    #[Test]
    public function fromCompiledMatcherFallbackSkipsNonMatchingRoutes(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'prefix'));
        $source->add(new Route('/files/suffix-{name}.doc', ['GET'], ['C', 'm'], name: 'suffix'));

        $matcher = $this->compileAndLoadMatcher($source);

        $result = $matcher->match('GET', '/files/suffix-hello.doc');
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
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('GET', '/files/suffix-hello.doc');
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
        $matcher = new CompiledArrayMatcher($cacheData);

        try {
            $matcher->match('DELETE', '/users/42');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('PUT', $e->getAllowedMethods());
        }
    }

    // ── Fallback prefix grouping ──────────────────────────────────

    #[Test]
    public function fallbackGroupingMatchesRoutesByFirstSegment(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'),
            new Route('/docs/page-{id}.html', ['GET'], ['C', 'm'], name: 'docs'),
        );

        $result = $matcher->match('GET', '/files/prefix-hello.txt');
        self::assertSame('files', $result->route->getName());
        self::assertSame(['name' => 'hello'], $result->parameters);

        $result = $matcher->match('GET', '/docs/page-42.html');
        self::assertSame('docs', $result->route->getName());
        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function fallbackGroupingDoesNotMatchWrongPrefix(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']),
        );

        $this->expectException(RouteNotFoundException::class);
        $matcher->match('GET', '/docs/prefix-hello.txt');
    }

    #[Test]
    public function fallbackGroupingCatchAllRouteMatchesAnyPrefix(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/{lang}/page-{id}.html', ['GET'], ['C', 'm'], name: 'catchall'),
        );

        $result = $matcher->match('GET', '/en/page-42.html');
        self::assertSame('catchall', $result->route->getName());

        $result = $matcher->match('GET', '/fr/page-99.html');
        self::assertSame('catchall', $result->route->getName());
    }

    #[Test]
    public function fallbackGroupingPreservesGlobalPriorityAcrossGroups(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/en/page-{id}.html', ['GET'], ['C', 'specific'], name: 'specific', priority: 0),
            new Route('/{lang}/page-{id}.html', ['GET'], ['C', 'catchall'], name: 'catchall', priority: 10),
        );

        $result = $matcher->match('GET', '/en/page-42.html');
        self::assertSame('catchall', $result->route->getName());
    }

    #[Test]
    public function fallbackGroupingPreservesGlobalPrioritySpecificWins(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/en/page-{id}.html', ['GET'], ['C', 'specific'], name: 'specific', priority: 10),
            new Route('/{lang}/page-{id}.html', ['GET'], ['C', 'catchall'], name: 'catchall', priority: 0),
        );

        $result = $matcher->match('GET', '/en/page-42.html');
        self::assertSame('specific', $result->route->getName());

        $result = $matcher->match('GET', '/fr/page-42.html');
        self::assertSame('catchall', $result->route->getName());
    }

    #[Test]
    public function fallbackGroupingMethodNotAllowedAcrossGroups(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/en/page-{id}.html', ['GET'], ['C', 'm'], name: 'specific'),
            new Route('/{lang}/page-{id}.html', ['POST'], ['C', 'm'], name: 'catchall'),
        );

        try {
            $matcher->match('DELETE', '/en/page-42.html');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('POST', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function fallbackGroupingPhase2PreservesGlobalPriority(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/en/page-{id}.html', ['GET'], ['C', 'specific'], name: 'specific', priority: 0));
        $source->add(new Route('/{lang}/page-{id}.html', ['GET'], ['C', 'catchall'], name: 'catchall', priority: 10));

        $cacheData = $this->buildPhase2DataFromCollection($source);
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('GET', '/en/page-42.html');
        self::assertSame('catchall', $result->route->getName());
    }

    #[Test]
    public function fallbackGroupingPhase3PreservesGlobalPriority(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/en/page-{id}.html', ['GET'], ['C', 'specific'], name: 'specific', priority: 0));
        $source->add(new Route('/{lang}/page-{id}.html', ['GET'], ['C', 'catchall'], name: 'catchall', priority: 10));

        $matcher = $this->compileAndLoadMatcher($source);

        $result = $matcher->match('GET', '/en/page-42.html');
        self::assertSame('catchall', $result->route->getName());
    }

    #[Test]
    public function fallbackGroupingPhase3MatchesByPrefix(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));
        $source->add(new Route('/docs/page-{id}.html', ['GET'], ['C', 'm'], name: 'docs'));

        $matcher = $this->compileAndLoadMatcher($source);

        $result = $matcher->match('GET', '/files/prefix-hello.txt');
        self::assertSame('files', $result->route->getName());

        $result = $matcher->match('GET', '/docs/page-42.html');
        self::assertSame('docs', $result->route->getName());
    }

    // ── Phase 1 fallback: skip non-matching candidates ──────────

    #[Test]
    public function fallbackSkipsNonMatchingCandidateInSameGroup(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/files/suffix-{name}.doc', ['GET'], ['C', 'm'], name: 'suffix'),
            new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'prefix'),
        );

        $result = $matcher->match('GET', '/files/prefix-hello.txt');
        self::assertSame('prefix', $result->route->getName());
        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    // ── HEAD method automatic fallback to GET (RFC 7231 §4.3.2) ──

    #[Test]
    public function headMatchesStaticGetRoute(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/about', ['GET'], ['C', 'm'], name: 'about'),
        );

        $result = $matcher->match('HEAD', '/about');

        self::assertSame('/about', $result->route->getPattern());
        self::assertSame([], $result->parameters);
    }

    #[Test]
    public function headMatchesDynamicGetRoute(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/users/{id:\d+}', ['GET'], ['C', 'm']),
        );

        $result = $matcher->match('HEAD', '/users/42');

        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function headMatchesFallbackGetRoute(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']),
        );

        $result = $matcher->match('HEAD', '/files/prefix-hello.txt');

        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    #[Test]
    public function headPrefersExplicitHeadRouteOverGetFallback(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/info', ['HEAD'], ['C', 'headHandler'], name: 'head'),
            new Route('/info', ['GET'], ['C', 'getHandler'], name: 'get'),
        );

        $result = $matcher->match('HEAD', '/info');

        self::assertSame('head', $result->route->getName());
    }

    #[Test]
    public function headThrows405WhenOnlyPostRouteExists(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/items', ['POST'], ['C', 'm']),
        );

        try {
            $matcher->match('HEAD', '/items');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(405, $e->getCode());
            self::assertContains('POST', $e->getAllowedMethods());
            self::assertNotContains('GET', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function headThrows404WhenNoRouteMatches(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/about', ['GET'], ['C', 'm']),
        );

        $this->expectException(RouteNotFoundException::class);

        $matcher->match('HEAD', '/nonexistent');
    }

    #[Test]
    public function headIsCaseInsensitive(): void
    {
        $matcher = $this->createTrieMatcher(
            new Route('/test', ['GET'], ['C', 'm']),
        );

        $result = $matcher->match('head', '/test');

        self::assertSame('/test', $result->route->getPattern());
    }

    // ── HEAD fallback: Phase 2 compiled raw ─────────────────────

    #[Test]
    public function headMatchesStaticGetRoutePhase2(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('HEAD', '/about');

        self::assertSame('/about', $result->route->getPattern());
    }

    #[Test]
    public function headMatchesDynamicGetRoutePhase2(): void
    {
        $cacheData = $this->buildPhase2Data();
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('HEAD', '/users/42');

        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function headMatchesFallbackGetRoutePhase2(): void
    {
        $cacheData = $this->buildPhase2DataWithFallback();
        $matcher = new CompiledArrayMatcher($cacheData);

        $result = $matcher->match('HEAD', '/files/prefix-hello.txt');

        self::assertSame(['name' => 'hello'], $result->parameters);
    }

    // ── HEAD fallback: Phase 3 compiled matcher ─────────────────

    #[Test]
    public function headMatchesStaticGetRoutePhase3(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $result = $matcher->match('HEAD', '/about');

        self::assertSame('/about', $result->route->getPattern());
    }

    #[Test]
    public function headMatchesDynamicGetRoutePhase3(): void
    {
        $matcher = $this->buildPhase3Matcher();

        $result = $matcher->match('HEAD', '/users/42');

        self::assertSame(['id' => '42'], $result->parameters);
    }

    #[Test]
    public function headMatchesFallbackGetRoutePhase3(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));

        $matcher = $this->compileAndLoadMatcher($source);

        $result = $matcher->match('HEAD', '/files/prefix-doc.txt');

        self::assertSame(['name' => 'doc'], $result->parameters);
    }

    #[Test]
    public function headThrows405WhenOnlyPostRouteExistsPhase3(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/items', ['POST'], ['C', 'm'], name: 'items'));

        $matcher = $this->compileAndLoadMatcher($source);

        try {
            $matcher->match('HEAD', '/items');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('POST', $e->getAllowedMethods());
            self::assertNotContains('GET', $e->getAllowedMethods());
        }
    }

    // ── Phase 3: static methods pre-population when not static-only ──

    #[Test]
    public function fromCompiledMatcherPrePopulatesStaticMethodsWhenNotStaticOnly(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/{page}', ['PUT'], ['C', 'm'], name: 'page'));

        $matcher = $this->compileAndLoadMatcher($source);

        try {
            $matcher->match('DELETE', '/about');
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertContains('GET', $e->getAllowedMethods());
            self::assertContains('PUT', $e->getAllowedMethods());
        }
    }

    // ── Phase 3: root URI triggers uriFirstSegment empty path ──

    #[Test]
    public function fromCompiledMatcherThrowsNotFoundForRootUri(): void
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm'], name: 'files'));

        $matcher = $this->compileAndLoadMatcher($source);

        $this->expectException(RouteNotFoundException::class);
        $matcher->match('GET', '/');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Create a TrieMatcher from a list of routes (Phase 1).
     */
    private function createTrieMatcher(Route ...$routes): TrieMatcher
    {
        $collection = new RouteCollection();
        foreach ($routes as $route) {
            $collection->add($route);
        }

        return new TrieMatcher($collection);
    }

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
     * Build a Phase 3 matcher using RouteCompiler -> CompiledClassMatcher.
     */
    private function buildPhase3Matcher(): UrlMatcherInterface
    {
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));

        return $this->compileAndLoadMatcher($source);
    }

    private function compileAndLoadMatcher(RouteCollection $source): UrlMatcherInterface
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
