<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    // ── Construction & getters ────────────────────────────────────

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $handler = ['App\\Controller\\UserController', 'show'];
        $middleware = ['App\\Middleware\\AuthMiddleware'];

        $route = new Route(
            pattern: '/users/{id:\d+}',
            methods: ['GET', 'POST'],
            handler: $handler,
            middleware: $middleware,
            name: 'users.show',
            priority: 10,
        );

        self::assertSame('/users/{id:\d+}', $route->getPattern());
        self::assertSame(['GET', 'POST'], $route->getMethods());
        self::assertSame($handler, $route->getHandler());
        self::assertSame($middleware, $route->getMiddleware());
        self::assertSame('users.show', $route->getName());
        self::assertSame(10, $route->getPriority());
    }

    #[Test]
    public function defaultOptionalProperties(): void
    {
        $route = new Route(
            pattern: '/home',
            methods: ['GET'],
            handler: ['App\\Controller\\HomeController', 'index'],
        );

        self::assertSame([], $route->getMiddleware());
        self::assertSame('', $route->getName());
        self::assertSame(0, $route->getPriority());
    }

    // ── Compile ──────────────────────────────────────────────────

    #[Test]
    public function compileStaticPath(): void
    {
        $route = new Route('/about', ['GET'], ['C', 'm']);
        $route->compile();

        self::assertSame('#^/about$#', $route->getCompiledRegex());
        self::assertSame([], $route->getParameterNames());
    }

    #[Test]
    public function compilePlaceholderWithoutConstraint(): void
    {
        $route = new Route('/users/{name}', ['GET'], ['C', 'm']);
        $route->compile();

        self::assertSame('#^/users/(?P<name>[^/]+)$#', $route->getCompiledRegex());
        self::assertSame(['name'], $route->getParameterNames());
    }

    #[Test]
    public function compilePlaceholderWithConstraint(): void
    {
        $route = new Route('/users/{id:\d+}', ['GET'], ['C', 'm']);
        $route->compile();

        self::assertSame('#^/users/(?P<id>\d+)$#', $route->getCompiledRegex());
        self::assertSame(['id'], $route->getParameterNames());
    }

    #[Test]
    public function compileMultiplePlaceholders(): void
    {
        $route = new Route(
            '/api/{version:\d+}/users/{id:\d+}/posts/{slug}',
            ['GET'],
            ['C', 'm'],
        );
        $route->compile();

        self::assertSame(
            '#^/api/(?P<version>\d+)/users/(?P<id>\d+)/posts/(?P<slug>[^/]+)$#',
            $route->getCompiledRegex(),
        );
        self::assertSame(['version', 'id', 'slug'], $route->getParameterNames());
    }

    #[Test]
    public function compileIsIdempotent(): void
    {
        $route = new Route('/users/{id:\d+}', ['GET'], ['C', 'm']);

        $route->compile();
        $firstRegex = $route->getCompiledRegex();

        $route->compile();
        self::assertSame($firstRegex, $route->getCompiledRegex());
    }

    #[Test]
    public function compileReturnsSelf(): void
    {
        $route = new Route('/test', ['GET'], ['C', 'm']);
        self::assertSame($route, $route->compile());
    }

    // ── Match ────────────────────────────────────────────────────

    #[Test]
    public function matchStaticPathSucceeds(): void
    {
        $route = new Route('/about', ['GET'], ['C', 'm']);

        $params = $route->match('/about');

        self::assertSame([], $params);
    }

    #[Test]
    public function matchStaticPathFails(): void
    {
        $route = new Route('/about', ['GET'], ['C', 'm']);

        self::assertNull($route->match('/contact'));
    }

    #[Test]
    public function matchExtractsParameters(): void
    {
        $route = new Route('/users/{id:\d+}', ['GET'], ['C', 'm']);

        $params = $route->match('/users/42');

        self::assertSame(['id' => '42'], $params);
    }

    #[Test]
    public function matchConstraintRejectsInvalidInput(): void
    {
        $route = new Route('/users/{id:\d+}', ['GET'], ['C', 'm']);

        self::assertNull($route->match('/users/abc'));
    }

    #[Test]
    public function matchWithoutConstraintAcceptsAnySegment(): void
    {
        $route = new Route('/users/{name}', ['GET'], ['C', 'm']);

        $params = $route->match('/users/john-doe');

        self::assertSame(['name' => 'john-doe'], $params);
    }

    #[Test]
    public function matchMultipleParameters(): void
    {
        $route = new Route('/posts/{year:\d{4}}/{slug}', ['GET'], ['C', 'm']);

        $params = $route->match('/posts/2025/hello-world');

        self::assertSame(['year' => '2025', 'slug' => 'hello-world'], $params);
    }

    #[Test]
    public function matchAutoCompiles(): void
    {
        $route = new Route('/items/{id:\d+}', ['GET'], ['C', 'm']);
        // match() without explicit compile() should still work
        $params = $route->match('/items/7');

        self::assertSame(['id' => '7'], $params);
    }

    #[Test]
    public function matchDoesNotMatchPartialUri(): void
    {
        $route = new Route('/users', ['GET'], ['C', 'm']);

        self::assertNull($route->match('/users/extra'));
    }

    #[Test]
    public function matchRequiresFullUri(): void
    {
        $route = new Route('/users/{id:\d+}', ['GET'], ['C', 'm']);

        self::assertNull($route->match('/users/42/posts'));
    }

    // ── allowsMethod ─────────────────────────────────────────────

    #[Test]
    public function allowsMethodIsCaseInsensitive(): void
    {
        $route = new Route('/test', ['GET', 'POST'], ['C', 'm']);

        self::assertTrue($route->allowsMethod('GET'));
        self::assertTrue($route->allowsMethod('get'));
        self::assertTrue($route->allowsMethod('Post'));
        self::assertFalse($route->allowsMethod('DELETE'));
    }

    // ── Serialisation round-trip ─────────────────────────────────

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $route = new Route(
            pattern: '/users/{id:\d+}',
            methods: ['GET'],
            handler: ['App\\Controller\\UserController', 'show'],
            middleware: ['App\\Middleware\\Auth'],
            name: 'users.show',
            priority: 5,
        );

        $data = $route->toArray();

        self::assertSame('/users/{id:\d+}', $data['path']);
        self::assertSame(['GET'], $data['methods']);
        self::assertSame(['App\\Controller\\UserController', 'show'], $data['handler']);
        self::assertSame(['App\\Middleware\\Auth'], $data['middleware']);
        self::assertSame('users.show', $data['name']);
        self::assertSame('#^/users/(?P<id>\d+)$#', $data['compiledRegex']);
        self::assertSame(['id'], $data['parameterNames']);
        self::assertSame(5, $data['priority']);
    }

    #[Test]
    public function fromArrayRestoresRouteInCompiledState(): void
    {
        $original = new Route(
            pattern: '/posts/{year:\d{4}}/{slug}',
            methods: ['GET', 'HEAD'],
            handler: ['App\\Controller\\PostController', 'show'],
            middleware: ['App\\Middleware\\Cache'],
            name: 'posts.show',
            priority: 3,
        );

        $restored = Route::fromArray($original->toArray());

        self::assertSame($original->getPattern(), $restored->getPattern());
        self::assertSame($original->getMethods(), $restored->getMethods());
        self::assertSame($original->getHandler(), $restored->getHandler());
        self::assertSame($original->getMiddleware(), $restored->getMiddleware());
        self::assertSame($original->getName(), $restored->getName());
        self::assertSame($original->getPriority(), $restored->getPriority());
        self::assertSame($original->getCompiledRegex(), $restored->getCompiledRegex());
        self::assertSame($original->getParameterNames(), $restored->getParameterNames());
    }

    #[Test]
    public function fromArrayRestoredRouteCanMatch(): void
    {
        $data = [
            'path' => '/items/{id:\d+}',
            'methods' => ['GET'],
            'handler' => ['App\\Controller\\ItemController', 'show'],
            'middleware' => [],
            'name' => 'items.show',
            'compiledRegex' => '#^/items/(?P<id>\d+)$#',
            'parameterNames' => ['id'],
            'priority' => 0,
        ];

        $route = Route::fromArray($data);

        self::assertSame(['id' => '99'], $route->match('/items/99'));
        self::assertNull($route->match('/items/abc'));
    }

    #[Test]
    public function fromArrayHandlesMissingOptionalFields(): void
    {
        $data = [
            'path' => '/test',
            'methods' => ['GET'],
            'handler' => ['C', 'm'],
            'compiledRegex' => '#^/test$#',
            'parameterNames' => [],
        ];

        $route = Route::fromArray($data);

        self::assertSame([], $route->getMiddleware());
        self::assertSame('', $route->getName());
        self::assertSame(0, $route->getPriority());
    }

    // ── Closure handler ──────────────────────────────────────────

    #[Test]
    public function supportsClosureHandler(): void
    {
        $closure = static fn (): string => 'ok';

        $route = new Route('/callback', ['GET'], $closure);

        self::assertSame($closure, $route->getHandler());
        self::assertSame([], $route->match('/callback'));
        self::assertNull($route->match('/other'));
    }

    // ── Edge cases ───────────────────────────────────────────────

    #[Test]
    public function rootPathCompiles(): void
    {
        $route = new Route('/', ['GET'], ['C', 'm']);
        $route->compile();

        self::assertSame('#^/$#', $route->getCompiledRegex());
        self::assertSame([], $route->match('/'));
        self::assertNull($route->match('/anything'));
    }

    #[Test]
    public function complexRegexConstraint(): void
    {
        $route = new Route(
            '/files/{path:[a-zA-Z0-9_/.-]+}',
            ['GET'],
            ['C', 'm'],
        );
        $route->compile();

        self::assertSame(
            '#^/files/(?P<path>[a-zA-Z0-9_/.-]+)$#',
            $route->getCompiledRegex(),
        );
        self::assertSame(
            ['path' => 'docs/readme.md'],
            $route->match('/files/docs/readme.md'),
        );
    }

    // ── fromCompactArray ────────────────────────────────────────

    #[Test]
    public function fromCompactArrayRestoresBasicRoute(): void
    {
        $data = [
            'h' => ['App\\Controller\\UserController', 'show'],
            'M' => ['GET'],
            'p' => '/users/{id:\d+}',
        ];

        $route = Route::fromCompactArray($data);

        self::assertSame('/users/{id:\d+}', $route->getPattern());
        self::assertSame(['GET'], $route->getMethods());
        self::assertSame(['App\\Controller\\UserController', 'show'], $route->getHandler());
        self::assertSame([], $route->getMiddleware());
        self::assertSame('', $route->getName());
        self::assertSame(0, $route->getPriority());
    }

    #[Test]
    public function fromCompactArrayRestoresAllOptionalFields(): void
    {
        $data = [
            'h' => ['App\\Controller\\UserController', 'show'],
            'M' => ['GET', 'POST'],
            'p' => '/users/{id:\d+}',
            'w' => ['App\\Middleware\\Auth'],
            'n' => 'users.show',
            'P' => 5,
            'r' => '#^/users/(?P<id>\d+)$#',
            'N' => ['id'],
            'a' => [['source' => 'param', 'name' => 'id', 'cast' => 'int']],
        ];

        $route = Route::fromCompactArray($data);

        self::assertSame(['App\\Middleware\\Auth'], $route->getMiddleware());
        self::assertSame('users.show', $route->getName());
        self::assertSame(5, $route->getPriority());
        self::assertSame('#^/users/(?P<id>\d+)$#', $route->getCompiledRegex());
        self::assertSame(['id'], $route->getParameterNames());
        self::assertSame([['source' => 'param', 'name' => 'id', 'cast' => 'int']], $route->getArgPlan());
    }

    #[Test]
    public function fromCompactArrayWithPreCompiledRegexCanMatch(): void
    {
        $data = [
            'h' => ['C', 'm'],
            'M' => ['GET'],
            'p' => '/items/{id:\d+}',
            'r' => '#^/items/(?P<id>\d+)$#',
            'N' => ['id'],
        ];

        $route = Route::fromCompactArray($data);

        self::assertSame(['id' => '99'], $route->match('/items/99'));
        self::assertNull($route->match('/items/abc'));
    }

    #[Test]
    public function fromCompactArrayWithoutRegexRecompiles(): void
    {
        $data = [
            'h' => ['C', 'm'],
            'M' => ['GET'],
            'p' => '/items/{id:\d+}',
        ];

        $route = Route::fromCompactArray($data);

        // Without pre-compiled regex, compile() should be triggered by match()
        self::assertSame(['id' => '99'], $route->match('/items/99'));
        self::assertSame(['id'], $route->getParameterNames());
    }

    // ── getArgPlan ──────────────────────────────────────────────

    #[Test]
    public function getArgPlanReturnsNullByDefault(): void
    {
        $route = new Route('/test', ['GET'], ['C', 'm']);

        self::assertNull($route->getArgPlan());
    }

    #[Test]
    public function getArgPlanAfterFromArray(): void
    {
        $data = [
            'path' => '/test',
            'methods' => ['GET'],
            'handler' => ['C', 'm'],
            'compiledRegex' => '#^/test$#',
            'parameterNames' => [],
            'argPlan' => [['source' => 'request']],
        ];

        $route = Route::fromArray($data);

        self::assertSame([['source' => 'request']], $route->getArgPlan());
    }

    // ── toArray with argPlan ────────────────────────────────────

    #[Test]
    public function toArrayIncludesArgPlanWhenSet(): void
    {
        $data = [
            'path' => '/test',
            'methods' => ['GET'],
            'handler' => ['C', 'm'],
            'compiledRegex' => '#^/test$#',
            'parameterNames' => [],
            'argPlan' => [['source' => 'request']],
        ];

        $route = Route::fromArray($data);
        $exported = $route->toArray();

        self::assertArrayHasKey('argPlan', $exported);
        self::assertSame([['source' => 'request']], $exported['argPlan']);
    }

    #[Test]
    public function toArrayOmitsArgPlanWhenNull(): void
    {
        $route = new Route('/test', ['GET'], ['C', 'm']);
        $exported = $route->toArray();

        self::assertArrayNotHasKey('argPlan', $exported);
    }
}
