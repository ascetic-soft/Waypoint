<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Cache;

use AsceticSoft\Waypoint\Cache\RouteCompiler;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteCompilerTest extends TestCase
{
    private string $cacheDir;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/waypoint_test_cache_' . uniqid();
        mkdir($this->cacheDir, 0o755, true);
        $this->cacheFile = $this->cacheDir . '/routes.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFile)) {
            unlink($this->cacheFile);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    #[Test]
    public function compileAndLoadRoundTrip(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/users/{id:\d+}',
            ['GET'],
            ['App\\Controller\\UserController', 'show'],
            ['App\\Middleware\\Auth'],
            'users.show',
            5,
        ));
        $collection->add(new Route(
            '/posts',
            ['GET', 'POST'],
            ['App\\Controller\\PostController', 'index'],
            name: 'posts.index',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        self::assertFileExists($this->cacheFile);

        $loaded = $compiler->load($this->cacheFile);
        $routes = $loaded->all();

        self::assertCount(2, $routes);

        // Higher priority first
        self::assertSame('users.show', $routes[0]->getName());
        self::assertSame('/users/{id:\d+}', $routes[0]->getPattern());
        self::assertSame(['GET'], $routes[0]->getMethods());
        self::assertSame(['App\\Controller\\UserController', 'show'], $routes[0]->getHandler());
        self::assertSame(['App\\Middleware\\Auth'], $routes[0]->getMiddleware());
        self::assertSame(5, $routes[0]->getPriority());

        self::assertSame('posts.index', $routes[1]->getName());
    }

    #[Test]
    public function loadedRoutesCanMatch(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/items/{id:\d+}',
            ['GET'],
            ['C', 'm'],
            name: 'items.show',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $loaded = $compiler->load($this->cacheFile);
        $result = $loaded->match('GET', '/items/99');

        self::assertSame(['id' => '99'], $result->parameters);
    }

    #[Test]
    public function isFreshReturnsTrueWhenFileExists(): void
    {
        file_put_contents($this->cacheFile, '<?php return [];');

        $compiler = new RouteCompiler();

        self::assertTrue($compiler->isFresh($this->cacheFile));
    }

    #[Test]
    public function isFreshReturnsFalseWhenFileMissing(): void
    {
        $compiler = new RouteCompiler();

        self::assertFalse($compiler->isFresh($this->cacheFile));
    }

    #[Test]
    public function loadThrowsWhenFileMissing(): void
    {
        $compiler = new RouteCompiler();

        $this->expectException(\RuntimeException::class);

        $compiler->load('/nonexistent/routes.php');
    }

    #[Test]
    public function compileCreatesDirectoryIfNeeded(): void
    {
        $nestedFile = $this->cacheDir . '/sub/dir/routes.php';

        $collection = new RouteCollection();
        $collection->add(new Route('/test', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $nestedFile);

        self::assertFileExists($nestedFile);

        // Cleanup
        unlink($nestedFile);
        rmdir($this->cacheDir . '/sub/dir');
        rmdir($this->cacheDir . '/sub');
    }
}
