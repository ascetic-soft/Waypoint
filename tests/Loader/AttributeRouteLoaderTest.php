<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Loader;

use AsceticSoft\Waypoint\Loader\AttributeRouteLoader;
use AsceticSoft\Waypoint\Tests\Fixture\GroupedController;
use AsceticSoft\Waypoint\Tests\Fixture\TestController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use AsceticSoft\Waypoint\Tests\Fixture\DummyMiddleware;

final class AttributeRouteLoaderTest extends TestCase
{
    private AttributeRouteLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new AttributeRouteLoader();
    }

    #[Test]
    public function loadFromClassWithoutPrefix(): void
    {
        $routes = $this->loader->loadFromClass(TestController::class);

        self::assertCount(5, $routes);

        $names = array_map(static fn ($r) => $r->getName(), $routes);
        self::assertContains('home', $names);
        self::assertContains('show', $names);
        self::assertContains('create', $names);
        self::assertContains('update', $names);
        self::assertContains('delete', $names);
    }

    #[Test]
    public function loadFromClassWithPrefix(): void
    {
        $routes = $this->loader->loadFromClass(GroupedController::class);

        self::assertCount(3, $routes);

        $patterns = array_map(static fn ($r) => $r->getPattern(), $routes);
        self::assertContains('/api/users/', $patterns);
        self::assertContains('/api/users/{id:\d+}', $patterns);
    }

    #[Test]
    public function classMiddlewareIsMergedWithMethodMiddleware(): void
    {
        $routes = $this->loader->loadFromClass(GroupedController::class);

        foreach ($routes as $route) {
            self::assertContains(
                DummyMiddleware::class,
                $route->getMiddleware(),
            );
        }
    }

    #[Test]
    public function loadFromClassSetsHandler(): void
    {
        $routes = $this->loader->loadFromClass(TestController::class);

        foreach ($routes as $route) {
            $handler = $route->getHandler();
            self::assertIsArray($handler);
            self::assertSame(TestController::class, $handler[0]);
        }
    }

    #[Test]
    public function loadFromClassSetsCorrectMethods(): void
    {
        $routes = $this->loader->loadFromClass(TestController::class);

        $byName = [];
        foreach ($routes as $r) {
            $byName[$r->getName()] = $r;
        }

        self::assertSame(['GET'], $byName['home']->getMethods());
        self::assertSame(['POST'], $byName['create']->getMethods());
        self::assertSame(['PUT'], $byName['update']->getMethods());
        self::assertSame(['DELETE'], $byName['delete']->getMethods());
    }

    #[Test]
    public function loadFromDirectoryFindsControllers(): void
    {
        $routes = $this->loader->loadFromDirectory(
            __DIR__ . '/../Fixture',
            'AsceticSoft\\Waypoint\\Tests\\Fixture',
        );

        // Should find routes from TestController and GroupedController
        self::assertGreaterThanOrEqual(8, \count($routes));
    }

    #[Test]
    public function loadFromDirectoryWithFilePatternFiltersFiles(): void
    {
        // Only files matching *Controller.php should be considered
        $routes = $this->loader->loadFromDirectory(
            __DIR__ . '/../Fixture',
            'AsceticSoft\\Waypoint\\Tests\\Fixture',
            '*Controller.php',
        );

        // Both TestController and GroupedController match the pattern
        self::assertGreaterThanOrEqual(8, \count($routes));

        // Verify names from both controllers are present
        $names = array_map(static fn ($r) => $r->getName(), $routes);
        self::assertContains('home', $names);           // TestController
        self::assertContains('users.list', $names);     // GroupedController
    }

    #[Test]
    public function loadFromDirectoryWithNonMatchingPatternReturnsEmpty(): void
    {
        // A pattern that matches no filenames in the fixture directory
        $routes = $this->loader->loadFromDirectory(
            __DIR__ . '/../Fixture',
            'AsceticSoft\\Waypoint\\Tests\\Fixture',
            '*Service.php',
        );

        self::assertCount(0, $routes);
    }
}
