<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
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
    private Router $router;

    protected function setUp(): void
    {
        $this->container = new SimpleContainer();
        $this->router = new Router($this->container);
    }

    // ── Manual registration ──────────────────────────────────────

    #[Test]
    public function manualGetRoute(): void
    {
        $this->router->get('/hello', static fn (): ResponseInterface => new Response(200, [], 'hello'));

        $response = $this->router->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello', (string) $response->getBody());
    }

    #[Test]
    public function manualPostRoute(): void
    {
        $this->router->post('/items', static fn (): ResponseInterface => new Response(201, [], 'created'));

        $response = $this->router->handle(new ServerRequest('POST', '/items'));

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function manualPutRoute(): void
    {
        $this->router->put('/items/{id:\d+}', static fn (int $id): ResponseInterface => new Response(200, [], "updated:$id"));

        $response = $this->router->handle(new ServerRequest('PUT', '/items/5'));

        self::assertSame('updated:5', (string) $response->getBody());
    }

    #[Test]
    public function manualDeleteRoute(): void
    {
        $this->router->delete('/items/{id:\d+}', static fn (int $id): ResponseInterface => new Response(200, [], "deleted:$id"));

        $response = $this->router->handle(new ServerRequest('DELETE', '/items/3'));

        self::assertSame('deleted:3', (string) $response->getBody());
    }

    #[Test]
    public function controllerHandlerFromContainer(): void
    {
        $this->container->set(TestController::class, new TestController());

        $this->router->get('/show/{id:\d+}', [TestController::class, 'show']);

        $response = $this->router->handle(new ServerRequest('GET', '/show/42'));

        self::assertSame('show:42', (string) $response->getBody());
    }

    // ── Route parameters autowiring ──────────────────────────────

    #[Test]
    public function routeParametersAreInjectedByName(): void
    {
        $this->router->get(
            '/users/{id:\d+}',
            static fn (int $id): ResponseInterface => new Response(200, [], "id=$id"),
        );

        $response = $this->router->handle(new ServerRequest('GET', '/users/99'));

        self::assertSame('id=99', (string) $response->getBody());
    }

    #[Test]
    public function serverRequestIsInjected(): void
    {
        $this->router->post(
            '/echo',
            static fn (ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                $request->getMethod(),
            ),
        );

        $response = $this->router->handle(new ServerRequest('POST', '/echo'));

        self::assertSame('POST', (string) $response->getBody());
    }

    // ── Groups ───────────────────────────────────────────────────

    #[Test]
    public function groupAppliesPrefix(): void
    {
        $this->router->group('/api', function (Router $r): void {
            $r->get('/users', static fn (): ResponseInterface => new Response(200, [], 'api-users'));
        });

        $response = $this->router->handle(new ServerRequest('GET', '/api/users'));

        self::assertSame('api-users', (string) $response->getBody());
    }

    #[Test]
    public function nestedGroupsStackPrefixes(): void
    {
        $this->router->group('/api', function (Router $r): void {
            $r->group('/v1', function (Router $r): void {
                $r->get('/users', static fn (): ResponseInterface => new Response(200, [], 'v1-users'));
            });
        });

        $response = $this->router->handle(new ServerRequest('GET', '/api/v1/users'));

        self::assertSame('v1-users', (string) $response->getBody());
    }

    #[Test]
    public function groupAppliesMiddleware(): void
    {
        $this->router->group('/admin', function (Router $r): void {
            $r->get('/dashboard', static fn (): ResponseInterface => new Response(200, [], 'dashboard'));
        }, [DummyMiddleware::class]);

        $response = $this->router->handle(new ServerRequest('GET', '/admin/dashboard'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    // ── Attribute loading ────────────────────────────────────────

    #[Test]
    public function loadAttributesRegistersRoutes(): void
    {
        $this->container->set(TestController::class, new TestController());

        $this->router->loadAttributes(TestController::class);

        $response = $this->router->handle(new ServerRequest('GET', '/'));

        self::assertSame('index', (string) $response->getBody());
    }

    #[Test]
    public function loadAttributesWithGroupedController(): void
    {
        $this->container->set(GroupedController::class, new GroupedController());

        $this->router->loadAttributes(GroupedController::class);

        $response = $this->router->handle(new ServerRequest('GET', '/api/users/42'));

        self::assertSame('user:42', (string) $response->getBody());
        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    // ── Global middleware ─────────────────────────────────────────

    #[Test]
    public function globalMiddlewareRunsOnAllRoutes(): void
    {
        $this->router->addMiddleware(DummyMiddleware::class);
        $this->router->get('/test', static fn (): ResponseInterface => new Response(200, [], 'ok'));

        $response = $this->router->handle(new ServerRequest('GET', '/test'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    #[Test]
    public function multipleGlobalMiddleware(): void
    {
        $this->router->addMiddleware(DummyMiddleware::class, AnotherMiddleware::class);
        $this->router->get('/test', static fn (): ResponseInterface => new Response(200, [], 'ok'));

        $response = $this->router->handle(new ServerRequest('GET', '/test'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
        self::assertSame('applied', $response->getHeaderLine('X-Another-Middleware'));
    }

    // ── Cache ────────────────────────────────────────────────────

    #[Test]
    public function compileThenLoadCache(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_router_test_' . uniqid() . '.php';

        try {
            $this->router->get('/cached', [TestController::class, 'index'], name: 'cached');
            $this->router->compileTo($cacheFile);

            // New router instance loading from cache
            $router2 = new Router($this->container);
            $this->container->set(TestController::class, new TestController());
            $router2->loadCache($cacheFile);

            $response = $router2->handle(new ServerRequest('GET', '/cached'));

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
        $this->router->get('/home', static fn (): ResponseInterface => new Response(200));

        $this->expectException(RouteNotFoundException::class);

        $this->router->handle(new ServerRequest('GET', '/nonexistent'));
    }

    #[Test]
    public function throws405ForDisallowedMethod(): void
    {
        $this->router->get('/resource', static fn (): ResponseInterface => new Response(200));

        try {
            $this->router->handle(new ServerRequest('POST', '/resource'));
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

        $this->router->scanDirectory(
            __DIR__ . '/Fixture',
            'AsceticSoft\\Waypoint\\Tests\\Fixture',
        );

        $response = $this->router->handle(new ServerRequest('GET', '/'));

        self::assertSame('index', (string) $response->getBody());
    }

    // ── Access to internals ──────────────────────────────────────

    #[Test]
    public function getRouteCollectionReturnsCollection(): void
    {
        $this->router->get('/a', static fn (): ResponseInterface => new Response(200));
        $this->router->get('/b', static fn (): ResponseInterface => new Response(200));

        $collection = $this->router->getRouteCollection();
        $all = $collection->all();

        self::assertCount(2, $all);
    }
}
