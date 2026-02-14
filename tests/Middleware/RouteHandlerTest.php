<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Middleware;

use AsceticSoft\Waypoint\Middleware\RouteHandler;
use AsceticSoft\Waypoint\Tests\Fixture\SimpleContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouteHandlerTest extends TestCase
{
    private SimpleContainer $container;

    protected function setUp(): void
    {
        $this->container = new SimpleContainer();
    }

    #[Test]
    public function closureHandlerIsInvoked(): void
    {
        $handler = new RouteHandler(
            handler: static fn (): ResponseInterface => new Response(200, [], 'closure-ok'),
            parameters: [],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('closure-ok', (string) $response->getBody());
    }

    #[Test]
    public function coercesFloatParameter(): void
    {
        $handler = new RouteHandler(
            handler: static fn (float $price): ResponseInterface => new Response(200, [], "price=$price"),
            parameters: ['price' => '9.99'],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('price=9.99', (string) $response->getBody());
    }

    #[Test]
    public function coercesBoolParameter(): void
    {
        $handler = new RouteHandler(
            handler: static fn (bool $active): ResponseInterface => new Response(200, [], $active ? 'yes' : 'no'),
            parameters: ['active' => 'true'],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('yes', (string) $response->getBody());
    }

    #[Test]
    public function coercesStringParameterViaDefaultMatchArm(): void
    {
        $handler = new RouteHandler(
            handler: static fn (string $slug): ResponseInterface => new Response(200, [], "slug=$slug"),
            parameters: ['slug' => 'hello-world'],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('slug=hello-world', (string) $response->getBody());
    }

    #[Test]
    public function routeParameterWithoutTypeHintPassesAsRawString(): void
    {
        $handler = new RouteHandler(
            handler: static fn ($id): ResponseInterface => new Response(200, [], "id=$id"),
            parameters: ['id' => '42'],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('id=42', (string) $response->getBody());
    }

    #[Test]
    public function injectsServiceFromContainerByTypeHint(): void
    {
        $svc = new \stdClass();
        $svc->value = 'injected';
        $this->container->set(\stdClass::class, $svc);

        $handler = new RouteHandler(
            handler: static fn (\stdClass $svc): ResponseInterface => new Response(200, [], $svc->value),
            parameters: [],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('injected', (string) $response->getBody());
    }

    #[Test]
    public function usesDefaultValueWhenParameterNotProvided(): void
    {
        $handler = new RouteHandler(
            handler: static fn (string $name = 'default'): ResponseInterface => new Response(200, [], $name),
            parameters: [],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('default', (string) $response->getBody());
    }

    #[Test]
    public function injectsNullForNullableParameter(): void
    {
        $handler = new RouteHandler(
            handler: static fn (?string $value): ResponseInterface => new Response(200, [], $value ?? 'null'),
            parameters: [],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));

        self::assertSame('null', (string) $response->getBody());
    }

    #[Test]
    public function throwsForUnresolvableParameter(): void
    {
        $handler = new RouteHandler(
            handler: static fn (string $required): ResponseInterface => new Response(200),
            parameters: [],
            container: $this->container,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve parameter "$required"');

        $handler->handle(new ServerRequest('GET', '/'));
    }

    #[Test]
    public function controllerMethodReceivesResolvedArguments(): void
    {
        $controller = new class () {
            public function action(int $id, ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], "action:$id:" . $request->getMethod());
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: ['id' => '7'],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('PUT', '/'));

        self::assertSame('action:7:PUT', (string) $response->getBody());
    }

    // ── argPlan: request source ─────────────────────────────────

    #[Test]
    public function argPlanInjectsRequest(): void
    {
        $controller = new class () {
            public function action(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], $request->getMethod());
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: [],
            container: $this->container,
            argPlan: [['source' => 'request']],
        );

        $response = $handler->handle(new ServerRequest('POST', '/'));
        self::assertSame('POST', (string) $response->getBody());
    }

    // ── argPlan: param source with int cast ─────────────────────

    #[Test]
    public function argPlanInjectsParamWithIntCast(): void
    {
        $controller = new class () {
            public function action(int $id): ResponseInterface
            {
                return new Response(200, [], "id=$id");
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: ['id' => '42'],
            container: $this->container,
            argPlan: [['source' => 'param', 'name' => 'id', 'cast' => 'int']],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('id=42', (string) $response->getBody());
    }

    // ── argPlan: param source with float cast ───────────────────

    #[Test]
    public function argPlanInjectsParamWithFloatCast(): void
    {
        $controller = new class () {
            public function action(float $price): ResponseInterface
            {
                return new Response(200, [], "price=$price");
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: ['price' => '9.99'],
            container: $this->container,
            argPlan: [['source' => 'param', 'name' => 'price', 'cast' => 'float']],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('price=9.99', (string) $response->getBody());
    }

    // ── argPlan: param source with bool cast ────────────────────

    #[Test]
    public function argPlanInjectsParamWithBoolCast(): void
    {
        $controller = new class () {
            public function action(bool $active): ResponseInterface
            {
                return new Response(200, [], $active ? 'yes' : 'no');
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: ['active' => 'true'],
            container: $this->container,
            argPlan: [['source' => 'param', 'name' => 'active', 'cast' => 'bool']],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('yes', (string) $response->getBody());
    }

    // ── argPlan: param source with string (default) cast ────────

    #[Test]
    public function argPlanInjectsParamWithStringCast(): void
    {
        $controller = new class () {
            public function action(string $slug): ResponseInterface
            {
                return new Response(200, [], "slug=$slug");
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: ['slug' => 'hello-world'],
            container: $this->container,
            argPlan: [['source' => 'param', 'name' => 'slug', 'cast' => 'string']],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('slug=hello-world', (string) $response->getBody());
    }

    // ── argPlan: param source without cast (null) ───────────────

    #[Test]
    public function argPlanInjectsParamWithoutCast(): void
    {
        $controller = new class () {
            public function action(string $slug): ResponseInterface
            {
                return new Response(200, [], "slug=$slug");
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: ['slug' => 'test'],
            container: $this->container,
            argPlan: [['source' => 'param', 'name' => 'slug', 'cast' => null]],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('slug=test', (string) $response->getBody());
    }

    // ── argPlan: container source ───────────────────────────────

    #[Test]
    public function argPlanInjectsServiceFromContainer(): void
    {
        $svc = new \stdClass();
        $svc->value = 'from-plan';
        $this->container->set(\stdClass::class, $svc);

        $controller = new class () {
            public function action(\stdClass $svc): ResponseInterface
            {
                return new Response(200, [], $svc->value);
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: [],
            container: $this->container,
            argPlan: [['source' => 'container', 'class' => \stdClass::class]],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('from-plan', (string) $response->getBody());
    }

    // ── argPlan: default source ─────────────────────────────────

    #[Test]
    public function argPlanUsesDefaultValue(): void
    {
        $controller = new class () {
            public function action(string $name): ResponseInterface
            {
                return new Response(200, [], "name=$name");
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: [],
            container: $this->container,
            argPlan: [['source' => 'default', 'value' => 'fallback']],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('name=fallback', (string) $response->getBody());
    }

    #[Test]
    public function argPlanUsesNullDefault(): void
    {
        $controller = new class () {
            public function action(?string $value): ResponseInterface
            {
                return new Response(200, [], $value ?? 'null');
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: [],
            container: $this->container,
            argPlan: [['source' => 'default', 'value' => null]],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('null', (string) $response->getBody());
    }

    // ── argPlan: combined sources ───────────────────────────────

    #[Test]
    public function argPlanCombinesMultipleSources(): void
    {
        $svc = new \stdClass();
        $svc->tag = 'svc';
        $this->container->set(\stdClass::class, $svc);

        $controller = new class () {
            public function action(
                ServerRequestInterface $request,
                int $id,
                \stdClass $svc,
                string $label,
            ): ResponseInterface {
                return new Response(200, [], "$id:{$svc->tag}:$label:{$request->getMethod()}");
            }
        };

        $this->container->set($controller::class, $controller);

        $handler = new RouteHandler(
            handler: [$controller::class, 'action'],
            parameters: ['id' => '7'],
            container: $this->container,
            argPlan: [
                ['source' => 'request'],
                ['source' => 'param', 'name' => 'id', 'cast' => 'int'],
                ['source' => 'container', 'class' => \stdClass::class],
                ['source' => 'default', 'value' => 'test'],
            ],
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('7:svc:test:GET', (string) $response->getBody());
    }

    // ── Reflection: service not in container falls through ──────

    #[Test]
    public function reflectionSkipsServiceNotInContainer(): void
    {
        // Interface not registered in container, but has default
        $handler = new RouteHandler(
            handler: static fn (?\DateTimeInterface $dt = null): ResponseInterface => new Response(
                200,
                [],
                $dt === null ? 'null' : 'has-value',
            ),
            parameters: [],
            container: $this->container,
        );

        $response = $handler->handle(new ServerRequest('GET', '/'));
        self::assertSame('null', (string) $response->getBody());
    }
}
