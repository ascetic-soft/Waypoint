<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Cache\RouteCompiler;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\RouteRegistrar;
use AsceticSoft\Waypoint\Router;
use AsceticSoft\Waypoint\Tests\Fixture\AnotherMiddleware;
use AsceticSoft\Waypoint\Tests\Fixture\DummyMiddleware;
use AsceticSoft\Waypoint\Tests\Fixture\GroupedController;
use AsceticSoft\Waypoint\Tests\Fixture\SimpleContainer;
use AsceticSoft\Waypoint\Tests\Fixture\TestController;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouterTest extends TestCase
{
    private SimpleContainer $container;

    protected function setUp(): void
    {
        $this->container = new SimpleContainer();
    }

    /**
     * Build a Router from a configured RouteRegistrar.
     */
    private function buildRouter(RouteRegistrar $registrar): Router
    {
        return new Router($this->container, $registrar->getRouteCollection());
    }

    // ── Manual registration ──────────────────────────────────────

    #[Test]
    public function manualGetRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/hello', static fn (): ResponseInterface => new Response(200, [], 'hello'));

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello', (string) $response->getBody());
    }

    #[Test]
    public function manualPostRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->post('/items', static fn (): ResponseInterface => new Response(201, [], 'created'));

        $response = $this->buildRouter($reg)->handle(new ServerRequest('POST', '/items'));

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function manualPutRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->put('/items/{id:\d+}', static fn (int $id): ResponseInterface => new Response(200, [], "updated:$id"));

        $response = $this->buildRouter($reg)->handle(new ServerRequest('PUT', '/items/5'));

        self::assertSame('updated:5', (string) $response->getBody());
    }

    #[Test]
    public function manualDeleteRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->delete('/items/{id:\d+}', static fn (int $id): ResponseInterface => new Response(200, [], "deleted:$id"));

        $response = $this->buildRouter($reg)->handle(new ServerRequest('DELETE', '/items/3'));

        self::assertSame('deleted:3', (string) $response->getBody());
    }

    #[Test]
    public function controllerHandlerFromContainer(): void
    {
        $this->container->set(TestController::class, new TestController());

        $reg = new RouteRegistrar();
        $reg->get('/show/{id:\d+}', [TestController::class, 'show']);

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/show/42'));

        self::assertSame('show:42', (string) $response->getBody());
    }

    // ── Route parameters autowiring ──────────────────────────────

    #[Test]
    public function routeParametersAreInjectedByName(): void
    {
        $reg = new RouteRegistrar();
        $reg->get(
            '/users/{id:\d+}',
            static fn (int $id): ResponseInterface => new Response(200, [], "id=$id"),
        );

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/users/99'));

        self::assertSame('id=99', (string) $response->getBody());
    }

    #[Test]
    public function serverRequestIsInjected(): void
    {
        $reg = new RouteRegistrar();
        $reg->post(
            '/echo',
            static fn (ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                $request->getMethod(),
            ),
        );

        $response = $this->buildRouter($reg)->handle(new ServerRequest('POST', '/echo'));

        self::assertSame('POST', (string) $response->getBody());
    }

    // ── Groups ───────────────────────────────────────────────────

    #[Test]
    public function groupAppliesPrefix(): void
    {
        $reg = new RouteRegistrar();
        $reg->group('/api', function (RouteRegistrar $r): void {
            $r->get('/users', static fn (): ResponseInterface => new Response(200, [], 'api-users'));
        });

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/api/users'));

        self::assertSame('api-users', (string) $response->getBody());
    }

    #[Test]
    public function nestedGroupsStackPrefixes(): void
    {
        $reg = new RouteRegistrar();
        $reg->group('/api', function (RouteRegistrar $r): void {
            $r->group('/v1', function (RouteRegistrar $r): void {
                $r->get('/users', static fn (): ResponseInterface => new Response(200, [], 'v1-users'));
            });
        });

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/api/v1/users'));

        self::assertSame('v1-users', (string) $response->getBody());
    }

    #[Test]
    public function groupAppliesMiddleware(): void
    {
        $reg = new RouteRegistrar();
        $reg->group('/admin', function (RouteRegistrar $r): void {
            $r->get('/dashboard', static fn (): ResponseInterface => new Response(200, [], 'dashboard'));
        }, [DummyMiddleware::class]);

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/admin/dashboard'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    // ── Attribute loading ────────────────────────────────────────

    #[Test]
    public function loadAttributesRegistersRoutes(): void
    {
        $this->container->set(TestController::class, new TestController());

        $reg = new RouteRegistrar();
        $reg->loadAttributes(TestController::class);

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/'));

        self::assertSame('index', (string) $response->getBody());
    }

    #[Test]
    public function loadAttributesWithGroupedController(): void
    {
        $this->container->set(GroupedController::class, new GroupedController());

        $reg = new RouteRegistrar();
        $reg->loadAttributes(GroupedController::class);

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/api/users/42'));

        self::assertSame('user:42', (string) $response->getBody());
        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    // ── Global middleware ─────────────────────────────────────────

    #[Test]
    public function globalMiddlewareRunsOnAllRoutes(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/test', static fn (): ResponseInterface => new Response(200, [], 'ok'));

        $router = $this->buildRouter($reg);
        $router->addMiddleware(DummyMiddleware::class);

        $response = $router->handle(new ServerRequest('GET', '/test'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    #[Test]
    public function multipleGlobalMiddleware(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/test', static fn (): ResponseInterface => new Response(200, [], 'ok'));

        $router = $this->buildRouter($reg);
        $router->addMiddleware(DummyMiddleware::class, AnotherMiddleware::class);

        $response = $router->handle(new ServerRequest('GET', '/test'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
        self::assertSame('applied', $response->getHeaderLine('X-Another-Middleware'));
    }

    // ── Cache ────────────────────────────────────────────────────

    #[Test]
    public function compileThenLoadCache(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_router_test_' . uniqid() . '.php';

        try {
            $reg = new RouteRegistrar();
            $reg->get('/cached', [TestController::class, 'index'], name: 'cached');

            $compiler = new RouteCompiler();
            $compiler->compile($reg->getRouteCollection(), $cacheFile);

            // New router instance loading from cache
            $this->container->set(TestController::class, new TestController());
            $router = new Router($this->container);
            $router->loadCache($cacheFile);

            $response = $router->handle(new ServerRequest('GET', '/cached'));

            self::assertSame('index', (string) $response->getBody());
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    // ── Error handling ───────────────────────────────────────────

    #[Test]
    public function throws404ForUnknownRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/home', static fn (): ResponseInterface => new Response(200));

        $this->expectException(RouteNotFoundException::class);

        $this->buildRouter($reg)->handle(new ServerRequest('GET', '/nonexistent'));
    }

    #[Test]
    public function throws405ForDisallowedMethod(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/resource', static fn (): ResponseInterface => new Response(200));

        $router = $this->buildRouter($reg);

        try {
            $router->handle(new ServerRequest('POST', '/resource'));
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(405, $e->getCode());
            self::assertContains('GET', $e->getAllowedMethods());
        }
    }

    // ── Directory scanning ───────────────────────────────────────

    #[Test]
    public function scanDirectoryLoadsRoutes(): void
    {
        $this->container->set(TestController::class, new TestController());
        $this->container->set(GroupedController::class, new GroupedController());

        $reg = new RouteRegistrar();
        $reg->scanDirectory(
            __DIR__ . '/Fixture',
            'AsceticSoft\\Waypoint\\Tests\\Fixture',
        );

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/'));

        self::assertSame('index', (string) $response->getBody());
    }

    // ── Access to internals ──────────────────────────────────────

    #[Test]
    public function getRouteCollectionReturnsCollection(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/a', static fn (): ResponseInterface => new Response(200));
        $reg->get('/b', static fn (): ResponseInterface => new Response(200));

        $router = $this->buildRouter($reg);
        $collection = $router->getRouteCollection();
        $all = $collection->all();

        self::assertCount(2, $all);
    }

    // ── loadCache ───────────────────────────────────────────────

    #[Test]
    public function loadCacheThrowsForNonexistentFile(): void
    {
        $router = new Router($this->container);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $router->loadCache('/nonexistent/path/routes.php');
    }

    #[Test]
    public function loadCacheLegacyFormat(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_router_legacy_' . uniqid() . '.php';

        try {
            $legacyData = [
                [
                    'path' => '/legacy',
                    'methods' => ['GET'],
                    'handler' => [TestController::class, 'index'],
                    'middleware' => [],
                    'name' => 'legacy',
                    'compiledRegex' => '#^/legacy$#',
                    'parameterNames' => [],
                    'priority' => 0,
                ],
            ];

            file_put_contents($cacheFile, '<?php return ' . var_export($legacyData, true) . ';');

            $this->container->set(TestController::class, new TestController());
            $router = new Router($this->container);
            $router->loadCache($cacheFile);

            $response = $router->handle(new ServerRequest('GET', '/legacy'));
            self::assertSame('index', (string) $response->getBody());
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    #[Test]
    public function loadCachePhase2Format(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_router_p2_' . uniqid() . '.php';

        try {
            // Build Phase 2 format cache manually
            $source = new \AsceticSoft\Waypoint\RouteCollection();
            $source->add(new \AsceticSoft\Waypoint\Route(
                '/p2',
                ['GET'],
                [TestController::class, 'index'],
                name: 'p2',
            ));

            $allRoutes = $source->all();
            $routeIndexMap = [];
            $routeData = [];
            $trie = new \AsceticSoft\Waypoint\RouteTrie();

            foreach ($allRoutes as $i => $route) {
                $routeIndexMap[spl_object_id($route)] = $i;
                $route->compile();
                $routeData[] = $route->toArray();
                $segments = \AsceticSoft\Waypoint\RouteTrie::parsePattern($route->getPattern());
                $trie->insert($route, $segments);
            }

            $phase2Data = [
                'routes' => $routeData,
                'trie' => $trie->toArray($routeIndexMap),
                'fallback' => [],
                'staticTable' => ['GET:/p2' => 0],
            ];

            file_put_contents($cacheFile, '<?php return ' . var_export($phase2Data, true) . ';');

            $this->container->set(TestController::class, new TestController());
            $router = new Router($this->container);
            $router->loadCache($cacheFile);

            $response = $router->handle(new ServerRequest('GET', '/p2'));
            self::assertSame('index', (string) $response->getBody());
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    #[Test]
    public function loadCachePhase3Format(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_router_p3_' . uniqid() . '.php';

        try {
            $this->container->set(TestController::class, new TestController());

            // Compile first
            $reg = new RouteRegistrar();
            $reg->get('/p3', [TestController::class, 'index'], name: 'p3');
            $compiler = new RouteCompiler();
            $compiler->compile($reg->getRouteCollection(), $cacheFile);

            // Load from compiled cache (Phase 3)
            $router = new Router($this->container);
            $router->loadCache($cacheFile);

            $response = $router->handle(new ServerRequest('GET', '/p3'));
            self::assertSame('index', (string) $response->getBody());
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    #[Test]
    public function loadCacheResetsUrlGenerator(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_router_url_' . uniqid() . '.php';

        try {
            $reg = new RouteRegistrar();
            $reg->get('/cached-url', [TestController::class, 'index'], name: 'cached.url');

            $compiler = new RouteCompiler();
            $compiler->compile($reg->getRouteCollection(), $cacheFile);

            $router = $this->buildRouter($reg);
            $gen1 = $router->getUrlGenerator();
            $router->loadCache($cacheFile);
            $gen2 = $router->getUrlGenerator();

            self::assertNotSame($gen1, $gen2);
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    // ── Handle middleware combinations ───────────────────────────

    #[Test]
    public function handleWithOnlyRouteMiddleware(): void
    {
        $reg = new RouteRegistrar();
        $reg->get(
            '/mw-only',
            static fn (): ResponseInterface => new Response(200, [], 'ok'),
            middleware: [DummyMiddleware::class],
        );

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/mw-only'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    #[Test]
    public function handleWithBothGlobalAndRouteMiddleware(): void
    {
        $reg = new RouteRegistrar();
        $reg->get(
            '/both-mw',
            static fn (): ResponseInterface => new Response(200, [], 'ok'),
            middleware: [DummyMiddleware::class],
        );

        $router = $this->buildRouter($reg);
        $router->addMiddleware(AnotherMiddleware::class);

        $response = $router->handle(new ServerRequest('GET', '/both-mw'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
        self::assertSame('applied', $response->getHeaderLine('X-Another-Middleware'));
    }

    #[Test]
    public function handleWithNoMiddleware(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/no-mw', static fn (): ResponseInterface => new Response(200, [], 'no-mw'));

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/no-mw'));

        self::assertSame('no-mw', (string) $response->getBody());
    }

    // ── Handle with route parameters ────────────────────────────

    #[Test]
    public function handleStoresRouteParametersInRequestAttributes(): void
    {
        $reg = new RouteRegistrar();
        $reg->get(
            '/params/{id:\d+}/{name}',
            static fn (ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                $request->getAttribute('id') . ':' . $request->getAttribute('name'),
            ),
        );

        $response = $this->buildRouter($reg)->handle(new ServerRequest('GET', '/params/42/john'));

        self::assertSame('42:john', (string) $response->getBody());
    }

    // ── Handle with cached route (argPlan) ──────────────────────

    #[Test]
    public function handleWithCachedRouteUsesArgPlan(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_router_argplan_' . uniqid() . '.php';

        try {
            $this->container->set(TestController::class, new TestController());

            $reg = new RouteRegistrar();
            $reg->get('/show/{id:\d+}', [TestController::class, 'show'], name: 'show');

            $compiler = new RouteCompiler();
            $compiler->compile($reg->getRouteCollection(), $cacheFile);

            $router = new Router($this->container);
            $router->loadCache($cacheFile);

            $response = $router->handle(new ServerRequest('GET', '/show/42'));

            self::assertSame('show:42', (string) $response->getBody());
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    // ── HEAD method automatic fallback ─────────────────────────

    #[Test]
    public function headRequestMatchesGetRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/hello', static fn (): ResponseInterface => new Response(200, [], 'hello'));

        $response = $this->buildRouter($reg)->handle(new ServerRequest('HEAD', '/hello'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function headRequestMatchesDynamicGetRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->get(
            '/users/{id:\d+}',
            static fn (int $id): ResponseInterface => new Response(200, [], "user:$id"),
        );

        $response = $this->buildRouter($reg)->handle(new ServerRequest('HEAD', '/users/42'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function headRequestPrefersExplicitHeadRoute(): void
    {
        $reg = new RouteRegistrar();
        $reg->addRoute(
            '/info',
            static fn (): ResponseInterface => new Response(204),
            ['HEAD'],
        );
        $reg->get('/info', static fn (): ResponseInterface => new Response(200, [], 'get-body'));

        $response = $this->buildRouter($reg)->handle(new ServerRequest('HEAD', '/info'));

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function headRequestThrows405WhenNoGetRouteExists(): void
    {
        $reg = new RouteRegistrar();
        $reg->post('/items', static fn (): ResponseInterface => new Response(201));

        $router = $this->buildRouter($reg);

        try {
            $router->handle(new ServerRequest('HEAD', '/items'));
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(405, $e->getCode());
            self::assertContains('POST', $e->getAllowedMethods());
        }
    }

    #[Test]
    public function headRequestPreservesOriginalMethodInRequest(): void
    {
        $reg = new RouteRegistrar();
        $reg->get(
            '/method-check',
            static fn (ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                $request->getMethod(),
            ),
        );

        $response = $this->buildRouter($reg)->handle(new ServerRequest('HEAD', '/method-check'));

        // The handler receives the original HEAD method, not the fallback GET
        self::assertSame('HEAD', (string) $response->getBody());
    }

    // ── addRoute method ─────────────────────────────────────────

    #[Test]
    public function addRouteWithMultipleMethods(): void
    {
        $reg = new RouteRegistrar();
        $reg->addRoute(
            '/multi',
            static fn (): ResponseInterface => new Response(200, [], 'multi'),
            ['GET', 'post'],
        );

        $router = $this->buildRouter($reg);

        $response = $router->handle(new ServerRequest('GET', '/multi'));
        self::assertSame('multi', (string) $response->getBody());

        $response = $router->handle(new ServerRequest('POST', '/multi'));
        self::assertSame('multi', (string) $response->getBody());
    }

    #[Test]
    public function addRouteReturnsSelf(): void
    {
        $reg = new RouteRegistrar();

        $result = $reg->addRoute('/test', static fn (): ResponseInterface => new Response(200));

        self::assertSame($reg, $result);
    }

    #[Test]
    public function groupReturnsSelf(): void
    {
        $reg = new RouteRegistrar();

        $result = $reg->group('/prefix', static function (): void {
        });

        self::assertSame($reg, $result);
    }

    #[Test]
    public function addMiddlewareReturnsSelf(): void
    {
        $router = new Router($this->container);

        $result = $router->addMiddleware(DummyMiddleware::class);

        self::assertSame($router, $result);
    }

    // ── Constructor with injected matcher ────────────────────────

    #[Test]
    public function constructorAcceptsPreBuiltMatcher(): void
    {
        $reg = new RouteRegistrar();
        $reg->get('/injected', static fn (): ResponseInterface => new Response(200, [], 'injected'));

        $matcher = new \AsceticSoft\Waypoint\TrieMatcher($reg->getRouteCollection());

        $router = new Router($this->container, matcher: $matcher);

        $response = $router->handle(new ServerRequest('GET', '/injected'));
        self::assertSame('injected', (string) $response->getBody());
    }
}
