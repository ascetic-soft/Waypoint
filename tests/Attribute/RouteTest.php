<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Attribute;

use AsceticSoft\Waypoint\Attribute\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $route = new Route();

        self::assertSame('', $route->path);
        self::assertSame(['GET'], $route->methods);
        self::assertSame('', $route->name);
        self::assertSame([], $route->middleware);
        self::assertSame(0, $route->priority);
    }

    #[Test]
    public function customValues(): void
    {
        $route = new Route(
            path: '/api/users',
            methods: ['POST', 'PUT'],
            name: 'users.store',
            middleware: ['AuthMiddleware'],
            priority: 10,
        );

        self::assertSame('/api/users', $route->path);
        self::assertSame(['POST', 'PUT'], $route->methods);
        self::assertSame('users.store', $route->name);
        self::assertSame(['AuthMiddleware'], $route->middleware);
        self::assertSame(10, $route->priority);
    }

    #[Test]
    public function isRepeatableAttribute(): void
    {
        $reflection = new \ReflectionClass(Route::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        self::assertCount(1, $attributes);

        /** @var \Attribute $attr */
        $attr = $attributes[0]->newInstance();

        self::assertTrue(($attr->flags & \Attribute::TARGET_CLASS) !== 0);
        self::assertTrue(($attr->flags & \Attribute::TARGET_METHOD) !== 0);
        self::assertTrue(($attr->flags & \Attribute::IS_REPEATABLE) !== 0);
    }
}
