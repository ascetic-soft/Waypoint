<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Middleware;

use AsceticSoft\Waypoint\Middleware\MiddlewarePipeline;
use AsceticSoft\Waypoint\Tests\Fixture\AnotherMiddleware;
use AsceticSoft\Waypoint\Tests\Fixture\DummyMiddleware;
use AsceticSoft\Waypoint\Tests\Fixture\SimpleContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function emptyPipelineDelegatesToHandler(): void
    {
        $container = new SimpleContainer();
        $handler = $this->createFallbackHandler(new Response(200, [], 'fallback'));

        $pipeline = new MiddlewarePipeline([], $handler, $container);

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fallback', (string) $response->getBody());
    }

    #[Test]
    public function middlewareInstanceIsExecuted(): void
    {
        $container = new SimpleContainer();
        $handler = $this->createFallbackHandler(new Response(200, [], 'ok'));

        $pipeline = new MiddlewarePipeline(
            [new DummyMiddleware()],
            $handler,
            $container,
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    #[Test]
    public function middlewareClassStringIsResolvedFromContainer(): void
    {
        $container = new SimpleContainer();
        $container->set(DummyMiddleware::class, new DummyMiddleware());
        $handler = $this->createFallbackHandler(new Response(200));

        $pipeline = new MiddlewarePipeline(
            [DummyMiddleware::class],
            $handler,
            $container,
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    #[Test]
    public function multipleMiddlewareExecuteInOrder(): void
    {
        $container = new SimpleContainer();
        $handler = $this->createFallbackHandler(new Response(200, [], 'final'));

        $pipeline = new MiddlewarePipeline(
            [new DummyMiddleware(), new AnotherMiddleware()],
            $handler,
            $container,
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
        self::assertSame('applied', $response->getHeaderLine('X-Another-Middleware'));
    }

    private function createFallbackHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
