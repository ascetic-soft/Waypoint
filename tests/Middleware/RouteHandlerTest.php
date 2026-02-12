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
}
