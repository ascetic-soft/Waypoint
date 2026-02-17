<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Cache;

use AsceticSoft\Waypoint\Cache\CompiledMatcherInterface;
use AsceticSoft\Waypoint\Cache\RouteCompiler;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\UrlMatcher;
use AsceticSoft\Waypoint\Tests\Fixture\TestController;
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
        self::assertInstanceOf(UrlMatcher::class, $loaded);

        $routes = $loaded->getRouteCollection()->all();

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

    // ── Phase 3: compiled matcher direct usage ──────────────────

    #[Test]
    public function compiledFileReturnsCompiledMatcherInterface(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/test', ['GET'], ['C', 'm'], name: 'test'));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;

        self::assertInstanceOf(CompiledMatcherInterface::class, $matcher);
    }

    #[Test]
    public function compiledMatcherMatchStaticRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $result = $matcher->matchStatic('GET', '/about');
        self::assertNotNull($result);
        self::assertSame(0, $result[0]);
    }

    #[Test]
    public function compiledMatcherMatchStaticReturnsNullForMiss(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        self::assertNull($matcher->matchStatic('POST', '/about'));
        self::assertNull($matcher->matchStatic('GET', '/nonexistent'));
    }

    #[Test]
    public function compiledMatcherMatchDynamicRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $allowedMethods = [];
        $result = $matcher->matchDynamic('GET', '/users/42', $allowedMethods);
        self::assertNotNull($result);
        self::assertSame(['id' => '42'], $result[1]);
    }

    #[Test]
    public function compiledMatcherDynamicReturnsNullForMiss(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $allowedMethods = [];
        self::assertNull($matcher->matchDynamic('GET', '/nonexistent', $allowedMethods));
    }

    #[Test]
    public function compiledMatcherDynamicCollectsAllowedMethods(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm']));
        $collection->add(new Route('/users/{id:\d+}', ['PUT'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $allowedMethods = [];
        $result = $matcher->matchDynamic('DELETE', '/users/42', $allowedMethods);
        self::assertNull($result);
        self::assertArrayHasKey('GET', $allowedMethods);
        self::assertArrayHasKey('PUT', $allowedMethods);
    }

    #[Test]
    public function compiledMatcherStaticMethods(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm']));
        $collection->add(new Route('/about', ['POST'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $methods = $matcher->staticMethods('/about');
        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);
        self::assertSame([], $matcher->staticMethods('/nonexistent'));
    }

    #[Test]
    public function compiledMatcherGetRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertSame(['C', 'm'], $data['h']);
        self::assertSame(['GET'], $data['M']);
        self::assertSame('/about', $data['p']);
        self::assertSame('about', $data['n']);
    }

    #[Test]
    public function compiledMatcherGetRouteCount(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/a', ['GET'], ['C', 'm']));
        $collection->add(new Route('/b', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        self::assertSame(2, $matcher->getRouteCount());
    }

    #[Test]
    public function compiledMatcherGetFallbackIndices(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm']));
        $collection->add(new Route('/files/prefix-{name}.txt', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $fallback = $matcher->getFallbackIndices();
        self::assertNotEmpty($fallback);
    }

    #[Test]
    public function compiledMatcherFindByName(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $collection->add(new Route('/contact', ['GET'], ['C', 'm'], name: 'contact'));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        self::assertSame(0, $matcher->findByName('about'));
        self::assertSame(1, $matcher->findByName('contact'));
        self::assertNull($matcher->findByName('nonexistent'));
    }

    // ── Load formats ────────────────────────────────────────────

    #[Test]
    public function loadPhase2Format(): void
    {
        // Build Phase 2 cache data (array with trie key)
        $source = new RouteCollection();
        $source->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        $source->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));

        $allRoutes = $source->all();
        $routeIndexMap = [];
        $routeData = [];
        $trie = new \AsceticSoft\Waypoint\RouteTrie();
        $fallback = [];

        foreach ($allRoutes as $i => $route) {
            $routeIndexMap[spl_object_id($route)] = $i;
            $route->compile();
            $routeData[] = $route->toArray();
            if (\AsceticSoft\Waypoint\RouteTrie::isCompatible($route->getPattern())) {
                $segments = \AsceticSoft\Waypoint\RouteTrie::parsePattern($route->getPattern());
                $trie->insert($route, $segments);
            } else {
                $fallback[] = $i;
            }
        }

        $staticTable = [];
        foreach ($allRoutes as $i => $route) {
            if ($route->getParameterNames() === []) {
                foreach ($route->getMethods() as $method) {
                    $key = $method . ':' . $route->getPattern();
                    if (!isset($staticTable[$key])) {
                        $staticTable[$key] = $i;
                    }
                }
            }
        }

        $phase2Data = [
            'routes' => $routeData,
            'trie' => $trie->toArray($routeIndexMap),
            'fallback' => $fallback,
            'staticTable' => $staticTable,
        ];

        file_put_contents($this->cacheFile, '<?php return ' . var_export($phase2Data, true) . ';');

        $compiler = new RouteCompiler();
        $loaded = $compiler->load($this->cacheFile);
        self::assertInstanceOf(UrlMatcher::class, $loaded);

        $result = $loaded->match('GET', '/about');
        self::assertSame('/about', $result->route->getPattern());
    }

    #[Test]
    public function loadLegacyFormat(): void
    {
        $legacyData = [
            [
                'path' => '/test',
                'methods' => ['GET'],
                'handler' => ['C', 'm'],
                'middleware' => [],
                'name' => 'test',
                'compiledRegex' => '#^/test$#',
                'parameterNames' => [],
                'priority' => 0,
            ],
        ];

        file_put_contents($this->cacheFile, '<?php return ' . var_export($legacyData, true) . ';');

        $compiler = new RouteCompiler();
        $loaded = $compiler->load($this->cacheFile);
        self::assertInstanceOf(UrlMatcher::class, $loaded);

        $all = $loaded->getRouteCollection()->all();
        self::assertCount(1, $all);
        self::assertSame('/test', $all[0]->getPattern());
    }

    // ── Compile with real controllers (buildArgPlan) ──────────────

    #[Test]
    public function compileWithRealControllerBuildsArgPlan(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/show/{id:\d+}',
            ['GET'],
            [TestController::class, 'show'],
            name: 'test.show',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        // argPlan should be present as 'a' key
        self::assertArrayHasKey('a', $data);
        self::assertIsArray($data['a']);
    }

    #[Test]
    public function compileWithRequestParameterInArgPlan(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/create',
            ['POST'],
            [TestController::class, 'create'],
            name: 'test.create',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        // First param is ServerRequestInterface → source = 'request'
        self::assertSame('request', $data['a'][0]['source']);
    }

    #[Test]
    public function compileWithMixedParamsAndRequest(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/update/{id:\d+}',
            ['PUT'],
            [TestController::class, 'update'],
            name: 'test.update',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        // id param → source = 'param'
        // request → source = 'request'
        $sources = array_column($data['a'], 'source');
        self::assertContains('param', $sources);
        self::assertContains('request', $sources);
    }

    #[Test]
    public function compileWithNonAutoloadableClassSkipsArgPlan(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/nonexist',
            ['GET'],
            ['App\\Nonexistent\\Controller', 'action'],
            name: 'nonexist',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayNotHasKey('a', $data);
    }

    #[Test]
    public function compileWithRouteWithoutMiddleware(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/bare', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        // 'w' key should not exist when middleware is empty
        self::assertArrayNotHasKey('w', $data);
    }

    #[Test]
    public function compileWithRouteWithMiddleware(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/mw', ['GET'], ['C', 'm'], ['App\\MW\\Auth']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertSame(['App\\MW\\Auth'], $data['w']);
    }

    #[Test]
    public function compileWithPriority(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/pri', ['GET'], ['C', 'm'], priority: 10));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertSame(10, $data['P']);
    }

    #[Test]
    public function compileWithoutPriorityOmitsKey(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/nopri', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayNotHasKey('P', $data);
    }

    #[Test]
    public function compileWithoutNameOmitsKey(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/noname', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayNotHasKey('n', $data);
    }

    #[Test]
    public function compileStaticRouteWithoutParamsOmitsRegexKeys(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/static', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayNotHasKey('r', $data);
        self::assertArrayNotHasKey('N', $data);
    }

    #[Test]
    public function compileDynamicRouteIncludesRegexKeys(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('r', $data);
        self::assertArrayHasKey('N', $data);
        self::assertSame(['id'], $data['N']);
    }

    #[Test]
    public function compileMatcherMatchDynamicRootRoute(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $allowedMethods = [];
        $result = $matcher->matchDynamic('GET', '/', $allowedMethods);
        // Root is a static route, so matchDynamic returns null and matchStatic handles it
        $staticResult = $matcher->matchStatic('GET', '/');
        self::assertNotNull($staticResult);
    }

    #[Test]
    public function compiledMatcherIncludesTwiceReturnsSameClass(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/test', ['GET'], ['C', 'm']));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher1 = include $this->cacheFile;
        $matcher2 = include $this->cacheFile;

        // Both should be CompiledMatcherInterface (class_exists guard)
        self::assertInstanceOf(CompiledMatcherInterface::class, $matcher1);
        self::assertInstanceOf(CompiledMatcherInterface::class, $matcher2);
    }

    // ── exportValue coverage ────────────────────────────────────

    #[Test]
    public function compileExportsNullValues(): void
    {
        // Create a controller with a nullable default value to test null export
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/',
            ['GET'],
            [TestController::class, 'index'],
            name: 'index',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        // If compilation succeeds, exportValue handled all types correctly
        self::assertFileExists($this->cacheFile);
    }

    #[Test]
    public function compileExportsTrueValue(): void
    {
        $controller = new class () {
            public function action(bool $flag = true): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/bool-true',
            ['GET'],
            [$controller::class, 'action'],
            name: 'bool-true',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        self::assertSame('default', $data['a'][0]['source']);
        self::assertTrue($data['a'][0]['value']);
    }

    #[Test]
    public function compileExportsFalseValue(): void
    {
        $controller = new class () {
            public function action(bool $flag = false): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/bool-false',
            ['GET'],
            [$controller::class, 'action'],
            name: 'bool-false',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        self::assertSame('default', $data['a'][0]['source']);
        self::assertFalse($data['a'][0]['value']);
    }

    #[Test]
    public function compileExportsFloatValues(): void
    {
        $controller = new class () {
            public function action(float $rate = 1.5): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/float',
            ['GET'],
            [$controller::class, 'action'],
            name: 'float',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        self::assertFileExists($this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        self::assertSame(1.5, $data['a'][0]['value']);
    }

    // ── buildArgPlan edge cases ─────────────────────────────────

    #[Test]
    public function compileWithContainerServiceParam(): void
    {
        $controller = new class () {
            public function action(\stdClass $service): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/svc',
            ['GET'],
            [$controller::class, 'action'],
            name: 'svc',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        self::assertSame('container', $data['a'][0]['source']);
        self::assertSame(\stdClass::class, $data['a'][0]['class']);
    }

    #[Test]
    public function compileWithNullableServiceParamSkipsArgPlan(): void
    {
        $controller = new class () {
            public function action(?\stdClass $service): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/nullable-svc',
            ['GET'],
            [$controller::class, 'action'],
            name: 'nsvc',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        // argPlan should be null (not compiled) because of nullable service
        self::assertArrayNotHasKey('a', $data);
    }

    #[Test]
    public function compileWithDefaultServiceParamSkipsArgPlan(): void
    {
        $controller = new class () {
            public function action(\stdClass $service = new \stdClass()): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/default-svc',
            ['GET'],
            [$controller::class, 'action'],
            name: 'dsvc',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        // Ambiguous — fall back to Reflection at runtime
        self::assertArrayNotHasKey('a', $data);
    }

    #[Test]
    public function compileWithNullableParamDefaultsToNull(): void
    {
        $controller = new class () {
            public function action(?int $value): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/nullable',
            ['GET'],
            [$controller::class, 'action'],
            name: 'nullable',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        self::assertSame('default', $data['a'][0]['source']);
        self::assertNull($data['a'][0]['value']);
    }

    #[Test]
    public function compileWithUnresolvableParamSkipsArgPlan(): void
    {
        $controller = new class () {
            public function action(string $required): void
            {
            }
        };

        $collection = new RouteCollection();
        $collection->add(new Route(
            '/unresolvable',
            ['GET'],
            [$controller::class, 'action'],
            name: 'unresolvable',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayNotHasKey('a', $data);
    }

    #[Test]
    public function compileWithNonexistentClassHandlerSkipsArgPlan(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/nonexistent',
            ['GET'],
            ['App\\Nonexistent\\Controller', 'action'],
            name: 'nonexistent',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayNotHasKey('a', $data);
    }

    #[Test]
    public function compileWithParamCastToInt(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/show/{id:\d+}',
            ['GET'],
            [TestController::class, 'show'],
            name: 'show',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        $paramEntry = $data['a'][0]; // first param is int $id
        self::assertSame('param', $paramEntry['source']);
        self::assertSame('id', $paramEntry['name']);
        self::assertSame('int', $paramEntry['cast']);
    }

    // ── computeStaticOnlyUris / hasNoParamChildrenAlongPath coverage ──

    #[Test]
    public function compileStaticRouteWithOverlappingDynamicIsNotStaticOnly(): void
    {
        $collection = new RouteCollection();
        // Static route.
        $collection->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));
        // Dynamic route with param child at the same trie level.
        $collection->add(new Route('/{page}', ['GET'], ['C', 'm'], name: 'page'));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        // /about should NOT be static-only because /{page} has param children at root.
        self::assertFalse($matcher->isStaticOnly('/about'));
    }

    #[Test]
    public function compileStaticRouteWithMatchingFallbackIsNotStaticOnly(): void
    {
        $collection = new RouteCollection();
        // Static route — the fallback regex below also matches this URI.
        $collection->add(new Route('/files/readme.txt', ['GET'], ['C', 'm'], name: 'readme'));
        // Non-trie-compatible fallback route that matches /files/readme.txt (name = 'me').
        $collection->add(new Route('/files/read{name}.txt', ['GET'], ['C', 'm'], name: 'readfile'));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        // /files/readme.txt should NOT be static-only because the fallback route matches it.
        self::assertFalse($matcher->isStaticOnly('/files/readme.txt'));
    }

    #[Test]
    public function compileWithNoParamsHandler(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/',
            ['GET'],
            [TestController::class, 'index'],
            name: 'home',
        ));

        $compiler = new RouteCompiler();
        $compiler->compile($collection, $this->cacheFile);

        $matcher = include $this->cacheFile;
        \assert($matcher instanceof CompiledMatcherInterface);

        $data = $matcher->getRoute(0);
        self::assertArrayHasKey('a', $data);
        self::assertSame([], $data['a']); // No params
    }
}
