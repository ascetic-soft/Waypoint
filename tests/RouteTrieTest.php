<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteTrie;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteTrieTest extends TestCase
{
    // ── parsePattern ─────────────────────────────────────────────

    #[Test]
    public function parsePatternRoot(): void
    {
        self::assertSame([], RouteTrie::parsePattern('/'));
    }

    #[Test]
    public function parsePatternStaticSegments(): void
    {
        $segments = RouteTrie::parsePattern('/api/users');

        self::assertCount(2, $segments);
        self::assertSame('static', $segments[0]['type']);
        self::assertSame('api', $segments[0]['value']);
        self::assertSame('static', $segments[1]['type']);
        self::assertSame('users', $segments[1]['value']);
    }

    #[Test]
    public function parsePatternDynamicSegmentWithoutConstraint(): void
    {
        $segments = RouteTrie::parsePattern('/users/{name}');

        self::assertCount(2, $segments);
        self::assertSame('param', $segments[1]['type']);
        self::assertSame('name', $segments[1]['paramName']);
        self::assertSame('[^/]+', $segments[1]['pattern']);
    }

    #[Test]
    public function parsePatternDynamicSegmentWithConstraint(): void
    {
        $segments = RouteTrie::parsePattern('/users/{id:\d+}');

        self::assertCount(2, $segments);
        self::assertSame('param', $segments[1]['type']);
        self::assertSame('id', $segments[1]['paramName']);
        self::assertSame('\d+', $segments[1]['pattern']);
    }

    #[Test]
    public function parsePatternMixedStaticAndDynamic(): void
    {
        $segments = RouteTrie::parsePattern('/api/{version:\d+}/users/{id:\d+}/posts/{slug}');

        self::assertCount(6, $segments);
        self::assertSame('static', $segments[0]['type']);
        self::assertSame('api', $segments[0]['value']);
        self::assertSame('param', $segments[1]['type']);
        self::assertSame('version', $segments[1]['paramName']);
        self::assertSame('static', $segments[2]['type']);
        self::assertSame('users', $segments[2]['value']);
        self::assertSame('param', $segments[3]['type']);
        self::assertSame('id', $segments[3]['paramName']);
        self::assertSame('static', $segments[4]['type']);
        self::assertSame('posts', $segments[4]['value']);
        self::assertSame('param', $segments[5]['type']);
        self::assertSame('slug', $segments[5]['paramName']);
    }

    #[Test]
    public function parsePatternWithBracedConstraint(): void
    {
        $segments = RouteTrie::parsePattern('/posts/{year:\d{4}}');

        self::assertSame('param', $segments[1]['type']);
        self::assertSame('year', $segments[1]['paramName']);
        self::assertSame('\d{4}', $segments[1]['pattern']);
    }

    // ── splitUri ─────────────────────────────────────────────────

    #[Test]
    public function splitUriRoot(): void
    {
        self::assertSame([], RouteTrie::splitUri('/'));
    }

    #[Test]
    public function splitUriSimplePath(): void
    {
        self::assertSame(['api', 'users'], RouteTrie::splitUri('/api/users'));
    }

    #[Test]
    public function splitUriPreservesTrailingSlash(): void
    {
        self::assertSame(['users', ''], RouteTrie::splitUri('/users/'));
    }

    #[Test]
    public function splitUriWithDynamicValues(): void
    {
        self::assertSame(['users', '42', 'posts'], RouteTrie::splitUri('/users/42/posts'));
    }

    // ── isCompatible ─────────────────────────────────────────────

    #[Test]
    public function isCompatibleWithStaticRoute(): void
    {
        self::assertTrue(RouteTrie::isCompatible('/about'));
    }

    #[Test]
    public function isCompatibleWithSimpleParameter(): void
    {
        self::assertTrue(RouteTrie::isCompatible('/users/{id:\d+}'));
    }

    #[Test]
    public function isCompatibleWithDefaultParameter(): void
    {
        self::assertTrue(RouteTrie::isCompatible('/users/{name}'));
    }

    #[Test]
    public function isCompatibleRootPath(): void
    {
        self::assertTrue(RouteTrie::isCompatible('/'));
    }

    #[Test]
    public function isNotCompatibleWithCrossSegmentRegex(): void
    {
        // Regex [a-zA-Z0-9_/.-]+ can match '/'
        self::assertFalse(RouteTrie::isCompatible('/files/{path:[a-zA-Z0-9_/.-]+}'));
    }

    #[Test]
    public function isNotCompatibleWithDotStarRegex(): void
    {
        self::assertFalse(RouteTrie::isCompatible('/catch/{all:.+}'));
    }

    #[Test]
    public function isNotCompatibleWithMixedSegment(): void
    {
        // Segment mixes static text with a parameter placeholder.
        self::assertFalse(RouteTrie::isCompatible('/files/prefix-{name}.txt'));
    }

    // ── insert + match ───────────────────────────────────────────

    #[Test]
    public function matchStaticRoute(): void
    {
        $trie = new RouteTrie();
        $route = new Route('/about', ['GET'], ['C', 'm']);
        $trie->insert($route, RouteTrie::parsePattern('/about'));

        $result = $trie->match('GET', RouteTrie::splitUri('/about'));

        self::assertNotNull($result);
        self::assertSame($route, $result['route']);
        self::assertSame([], $result['params']);
    }

    #[Test]
    public function matchDynamicRoute(): void
    {
        $trie = new RouteTrie();
        $route = new Route('/users/{id:\d+}', ['GET'], ['C', 'm']);
        $trie->insert($route, RouteTrie::parsePattern('/users/{id:\d+}'));

        $result = $trie->match('GET', RouteTrie::splitUri('/users/42'));

        self::assertNotNull($result);
        self::assertSame(['id' => '42'], $result['params']);
    }

    #[Test]
    public function matchRejectsInvalidDynamicSegment(): void
    {
        $trie = new RouteTrie();
        $route = new Route('/users/{id:\d+}', ['GET'], ['C', 'm']);
        $trie->insert($route, RouteTrie::parsePattern('/users/{id:\d+}'));

        $result = $trie->match('GET', RouteTrie::splitUri('/users/abc'));

        self::assertNull($result);
    }

    #[Test]
    public function matchRootPath(): void
    {
        $trie = new RouteTrie();
        $route = new Route('/', ['GET'], ['C', 'm']);
        $trie->insert($route, RouteTrie::parsePattern('/'));

        $result = $trie->match('GET', RouteTrie::splitUri('/'));

        self::assertNotNull($result);
        self::assertSame($route, $result['route']);
    }

    #[Test]
    public function matchMultipleParameters(): void
    {
        $trie = new RouteTrie();
        $route = new Route('/posts/{year:\d{4}}/{slug}', ['GET'], ['C', 'm']);
        $trie->insert($route, RouteTrie::parsePattern('/posts/{year:\d{4}}/{slug}'));

        $result = $trie->match('GET', RouteTrie::splitUri('/posts/2025/hello-world'));

        self::assertNotNull($result);
        self::assertSame(['year' => '2025', 'slug' => 'hello-world'], $result['params']);
    }

    #[Test]
    public function matchReturnsNullForUnknownPath(): void
    {
        $trie = new RouteTrie();
        $trie->insert(
            new Route('/about', ['GET'], ['C', 'm']),
            RouteTrie::parsePattern('/about'),
        );

        self::assertNull($trie->match('GET', RouteTrie::splitUri('/contact')));
    }

    #[Test]
    public function matchReturnsNullForExtraSegments(): void
    {
        $trie = new RouteTrie();
        $trie->insert(
            new Route('/users', ['GET'], ['C', 'm']),
            RouteTrie::parsePattern('/users'),
        );

        self::assertNull($trie->match('GET', RouteTrie::splitUri('/users/42')));
    }

    #[Test]
    public function matchReturnsNullForFewerSegments(): void
    {
        $trie = new RouteTrie();
        $trie->insert(
            new Route('/users/{id:\d+}', ['GET'], ['C', 'm']),
            RouteTrie::parsePattern('/users/{id:\d+}'),
        );

        self::assertNull($trie->match('GET', RouteTrie::splitUri('/users')));
    }

    #[Test]
    public function matchChecksHttpMethod(): void
    {
        $trie = new RouteTrie();
        $route = new Route('/items', ['GET'], ['C', 'm']);
        $trie->insert($route, RouteTrie::parsePattern('/items'));

        self::assertNull($trie->match('POST', RouteTrie::splitUri('/items')));
    }

    #[Test]
    public function matchCollectsAllowedMethodsForMethodMismatch(): void
    {
        $trie = new RouteTrie();
        $trie->insert(
            new Route('/items', ['GET'], ['C', 'm']),
            RouteTrie::parsePattern('/items'),
        );
        $trie->insert(
            new Route('/items', ['POST'], ['C', 'm']),
            RouteTrie::parsePattern('/items'),
        );

        $allowedMethods = [];
        $result = $trie->match('DELETE', RouteTrie::splitUri('/items'), 0, [], $allowedMethods);

        self::assertNull($result);
        self::assertArrayHasKey('GET', $allowedMethods);
        self::assertArrayHasKey('POST', $allowedMethods);
    }

    #[Test]
    public function matchStaticChildTakesPrecedenceOverDynamic(): void
    {
        $trie = new RouteTrie();

        $dynamicRoute = new Route('/users/{name}', ['GET'], ['C', 'dynamic']);
        $staticRoute = new Route('/users/profile', ['GET'], ['C', 'static']);

        // Insert dynamic first, then static.
        $trie->insert($dynamicRoute, RouteTrie::parsePattern('/users/{name}'));
        $trie->insert($staticRoute, RouteTrie::parsePattern('/users/profile'));

        // Static child should be tried first.
        $result = $trie->match('GET', RouteTrie::splitUri('/users/profile'));

        self::assertNotNull($result);
        self::assertSame($staticRoute, $result['route']);
    }

    #[Test]
    public function matchBacktracksFromStaticToDynamic(): void
    {
        $trie = new RouteTrie();

        // Static path /users/profile/settings has no route.
        $trie->insert(
            new Route('/users/profile/avatar', ['GET'], ['C', 'avatar']),
            RouteTrie::parsePattern('/users/profile/avatar'),
        );

        // Dynamic /users/{id}/settings does.
        $dynamicRoute = new Route('/users/{id:\d+}/settings', ['GET'], ['C', 'settings']);
        $trie->insert($dynamicRoute, RouteTrie::parsePattern('/users/{id:\d+}/settings'));

        // URI /users/42/settings should NOT match static child "profile",
        // then fall back to dynamic {id} and succeed.
        $result = $trie->match('GET', RouteTrie::splitUri('/users/42/settings'));

        self::assertNotNull($result);
        self::assertSame($dynamicRoute, $result['route']);
        self::assertSame(['id' => '42'], $result['params']);
    }

    #[Test]
    public function matchPrefersMoreSpecificDynamicPattern(): void
    {
        $trie = new RouteTrie();

        // Higher-priority route with stricter regex, inserted first.
        $specific = new Route('/users/{id:\d+}', ['GET'], ['C', 'highPriority'], priority: 10);
        $generic = new Route('/users/{name}', ['GET'], ['C', 'lowPriority'], priority: 0);

        $trie->insert($specific, RouteTrie::parsePattern('/users/{id:\d+}'));
        $trie->insert($generic, RouteTrie::parsePattern('/users/{name}'));

        // "42" matches both patterns, but specific is tried first (priority order).
        $result = $trie->match('GET', RouteTrie::splitUri('/users/42'));

        self::assertNotNull($result);
        self::assertSame($specific, $result['route']);
    }

    #[Test]
    public function matchFallsBackToGenericDynamic(): void
    {
        $trie = new RouteTrie();

        $specific = new Route('/users/{id:\d+}', ['GET'], ['C', 'highPriority'], priority: 10);
        $generic = new Route('/users/{name}', ['GET'], ['C', 'lowPriority'], priority: 0);

        $trie->insert($specific, RouteTrie::parsePattern('/users/{id:\d+}'));
        $trie->insert($generic, RouteTrie::parsePattern('/users/{name}'));

        // "john" does NOT match \d+, so it falls through to {name}.
        $result = $trie->match('GET', RouteTrie::splitUri('/users/john'));

        self::assertNotNull($result);
        self::assertSame($generic, $result['route']);
        self::assertSame(['name' => 'john'], $result['params']);
    }

    #[Test]
    public function matchDeepNestedRoute(): void
    {
        $trie = new RouteTrie();
        $route = new Route(
            '/api/{version:\d+}/users/{id:\d+}/posts/{slug}',
            ['GET'],
            ['C', 'm'],
        );
        $trie->insert($route, RouteTrie::parsePattern($route->getPattern()));

        $result = $trie->match('GET', RouteTrie::splitUri('/api/2/users/99/posts/hello'));

        self::assertNotNull($result);
        self::assertSame(
            ['version' => '2', 'id' => '99', 'slug' => 'hello'],
            $result['params'],
        );
    }

    #[Test]
    public function matchTrailingSlashDoesNotMatchWithout(): void
    {
        $trie = new RouteTrie();
        $trie->insert(
            new Route('/users', ['GET'], ['C', 'm']),
            RouteTrie::parsePattern('/users'),
        );

        // /users/ has an extra empty segment -> should NOT match /users.
        self::assertNull($trie->match('GET', RouteTrie::splitUri('/users/')));
    }

    #[Test]
    public function matchManyRoutesSelectsCorrect(): void
    {
        $trie = new RouteTrie();

        $routes = [
            new Route('/api/users', ['GET'], ['C', 'listUsers']),
            new Route('/api/users/{id:\d+}', ['GET'], ['C', 'getUser']),
            new Route('/api/users/{id:\d+}/posts', ['GET'], ['C', 'listPosts']),
            new Route('/api/users/{id:\d+}/posts/{postId:\d+}', ['GET'], ['C', 'getPost']),
            new Route('/api/products', ['GET'], ['C', 'listProducts']),
            new Route('/api/products/{slug}', ['GET'], ['C', 'getProduct']),
        ];

        foreach ($routes as $route) {
            $trie->insert($route, RouteTrie::parsePattern($route->getPattern()));
        }

        // List users
        $r = $trie->match('GET', RouteTrie::splitUri('/api/users'));
        self::assertSame($routes[0], $r['route']);

        // Get user
        $r = $trie->match('GET', RouteTrie::splitUri('/api/users/5'));
        self::assertSame($routes[1], $r['route']);
        self::assertSame(['id' => '5'], $r['params']);

        // List posts
        $r = $trie->match('GET', RouteTrie::splitUri('/api/users/5/posts'));
        self::assertSame($routes[2], $r['route']);

        // Get post
        $r = $trie->match('GET', RouteTrie::splitUri('/api/users/5/posts/42'));
        self::assertSame($routes[3], $r['route']);
        self::assertSame(['id' => '5', 'postId' => '42'], $r['params']);

        // List products
        $r = $trie->match('GET', RouteTrie::splitUri('/api/products'));
        self::assertSame($routes[4], $r['route']);

        // Get product
        $r = $trie->match('GET', RouteTrie::splitUri('/api/products/widget'));
        self::assertSame($routes[5], $r['route']);
        self::assertSame(['slug' => 'widget'], $r['params']);

        // No match
        self::assertNull($trie->match('GET', RouteTrie::splitUri('/api/orders')));
    }

    // ── toArray / fromArray serialisation ────────────────────────

    #[Test]
    public function toArrayAndFromArrayRoundTrip(): void
    {
        $trie = new RouteTrie();

        $routes = [
            new Route('/about', ['GET'], ['C', 'm']),
            new Route('/users/{id:\d+}', ['GET'], ['C', 'm']),
            new Route('/posts/{year:\d{4}}/{slug}', ['GET'], ['C', 'm']),
        ];

        $routeIndexMap = [];
        foreach ($routes as $i => $route) {
            $routeIndexMap[spl_object_id($route)] = $i;
            $trie->insert($route, RouteTrie::parsePattern($route->getPattern()));
        }

        // Serialise
        $arrayData = $trie->toArray($routeIndexMap);

        // Deserialise
        $restored = RouteTrie::fromArray($arrayData, $routes);

        // Verify matching still works
        $result = $restored->match('GET', RouteTrie::splitUri('/about'));
        self::assertNotNull($result);
        self::assertSame($routes[0], $result['route']);

        $result = $restored->match('GET', RouteTrie::splitUri('/users/42'));
        self::assertNotNull($result);
        self::assertSame($routes[1], $result['route']);
        self::assertSame(['id' => '42'], $result['params']);

        $result = $restored->match('GET', RouteTrie::splitUri('/posts/2025/hello'));
        self::assertNotNull($result);
        self::assertSame($routes[2], $result['route']);
        self::assertSame(['year' => '2025', 'slug' => 'hello'], $result['params']);
    }

    #[Test]
    public function toArrayAndFromArrayWithDynamicChildren(): void
    {
        $trie = new RouteTrie();
        $route = new Route('/items/{id:\d+}', ['GET'], ['C', 'm']);
        $routeIndexMap = [spl_object_id($route) => 0];

        $trie->insert($route, RouteTrie::parsePattern('/items/{id:\d+}'));

        $arrayData = $trie->toArray($routeIndexMap);
        $restored = RouteTrie::fromArray($arrayData, [$route]);

        $result = $restored->match('GET', RouteTrie::splitUri('/items/7'));
        self::assertNotNull($result);
        self::assertSame(['id' => '7'], $result['params']);
    }

    // ── matchArray (Phase 2 array-based matching) ────────────────

    #[Test]
    public function matchArrayStaticRoute(): void
    {
        $trieData = $this->buildTrieData();
        $allowedMethods = [];

        $result = RouteTrie::matchArray(
            $trieData['trie'],
            $trieData['routes'],
            'GET',
            RouteTrie::splitUri('/about'),
            0,
            [],
            $allowedMethods,
        );

        self::assertNotNull($result);
        self::assertSame([], $result['params']);
    }

    #[Test]
    public function matchArrayDynamicRoute(): void
    {
        $trieData = $this->buildTrieData();
        $allowedMethods = [];

        $result = RouteTrie::matchArray(
            $trieData['trie'],
            $trieData['routes'],
            'GET',
            RouteTrie::splitUri('/users/42'),
            0,
            [],
            $allowedMethods,
        );

        self::assertNotNull($result);
        self::assertSame(['id' => '42'], $result['params']);
    }

    #[Test]
    public function matchArrayReturnsNullForNoMatch(): void
    {
        $trieData = $this->buildTrieData();
        $allowedMethods = [];

        $result = RouteTrie::matchArray(
            $trieData['trie'],
            $trieData['routes'],
            'GET',
            RouteTrie::splitUri('/nonexistent'),
            0,
            [],
            $allowedMethods,
        );

        self::assertNull($result);
    }

    #[Test]
    public function matchArrayCollectsAllowedMethods(): void
    {
        $trieData = $this->buildTrieData();
        $allowedMethods = [];

        $result = RouteTrie::matchArray(
            $trieData['trie'],
            $trieData['routes'],
            'DELETE',
            RouteTrie::splitUri('/about'),
            0,
            [],
            $allowedMethods,
        );

        self::assertNull($result);
        self::assertArrayHasKey('GET', $allowedMethods);
    }

    #[Test]
    public function matchArrayDynamicMethodMismatch(): void
    {
        $trieData = $this->buildTrieData();
        $allowedMethods = [];

        $result = RouteTrie::matchArray(
            $trieData['trie'],
            $trieData['routes'],
            'DELETE',
            RouteTrie::splitUri('/users/42'),
            0,
            [],
            $allowedMethods,
        );

        self::assertNull($result);
        self::assertArrayHasKey('GET', $allowedMethods);
    }

    #[Test]
    public function matchArrayRootRoute(): void
    {
        $route = new Route('/', ['GET'], ['C', 'm']);
        $route->compile();
        $trie = new RouteTrie();
        $routeIndexMap = [spl_object_id($route) => 0];
        $trie->insert($route, RouteTrie::parsePattern('/'));

        $trieArray = $trie->toArray($routeIndexMap);
        $routeData = [$route->toArray()];

        $allowedMethods = [];
        $result = RouteTrie::matchArray($trieArray, $routeData, 'GET', [], 0, [], $allowedMethods);

        self::assertNotNull($result);
        self::assertSame(0, $result['index']);
    }

    // ── matchArray with static + dynamic backtracking ─────────────

    #[Test]
    public function matchArrayPrefersStaticOverDynamic(): void
    {
        $staticRoute = new Route('/users/profile', ['GET'], ['C', 'static']);
        $dynamicRoute = new Route('/users/{name}', ['GET'], ['C', 'dynamic']);

        $staticRoute->compile();
        $dynamicRoute->compile();

        $trie = new RouteTrie();
        $routeIndexMap = [
            spl_object_id($dynamicRoute) => 0,
            spl_object_id($staticRoute) => 1,
        ];
        $trie->insert($dynamicRoute, RouteTrie::parsePattern('/users/{name}'));
        $trie->insert($staticRoute, RouteTrie::parsePattern('/users/profile'));

        $trieArray = $trie->toArray($routeIndexMap);
        $routeData = [$dynamicRoute->toArray(), $staticRoute->toArray()];

        $allowedMethods = [];
        $result = RouteTrie::matchArray($trieArray, $routeData, 'GET', ['users', 'profile'], 0, [], $allowedMethods);

        self::assertNotNull($result);
        self::assertSame(1, $result['index']); // Static wins
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @return array{trie: array<string, mixed>, routes: list<array<string, mixed>>}
     */
    private function buildTrieData(): array
    {
        $routes = [
            new Route('/about', ['GET'], ['C', 'm']),
            new Route('/users/{id:\d+}', ['GET'], ['C', 'm']),
        ];

        $trie = new RouteTrie();
        $routeIndexMap = [];

        foreach ($routes as $i => $route) {
            $route->compile();
            $routeIndexMap[spl_object_id($route)] = $i;
            $trie->insert($route, RouteTrie::parsePattern($route->getPattern()));
        }

        return [
            'trie' => $trie->toArray($routeIndexMap),
            'routes' => array_map(static fn (Route $r) => $r->toArray(), $routes),
        ];
    }
}
