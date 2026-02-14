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
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
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

    #[Test]
    public function classStringMiddlewareIsResolvedOncePerPipeline(): void
    {
        $dummyInstance = new DummyMiddleware();
        $resolveCount = 0;

        $container = $this->createMock(ContainerInterface::class);
        $container
            ->method('get')
            ->with(DummyMiddleware::class)
            ->willReturnCallback(static function () use ($dummyInstance, &$resolveCount) {
                ++$resolveCount;

                return $dummyInstance;
            });

        $handler = $this->createFallbackHandler(new Response(200, [], 'ok'));

        // Same class-string middleware appears twice in the stack.
        $pipeline = new MiddlewarePipeline(
            [DummyMiddleware::class, DummyMiddleware::class],
            $handler,
            $container,
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
        self::assertSame(1, $resolveCount, 'Container::get() must be called only once for the same class-string.');
    }

    #[Test]
    public function classStringCacheIsSharedAcrossPipelineSteps(): void
    {
        $dummyInstance = new DummyMiddleware();
        $anotherInstance = new AnotherMiddleware();
        $resolvedClasses = [];

        $container = $this->createMock(ContainerInterface::class);
        $container
            ->method('get')
            ->willReturnCallback(static function (string $id) use ($dummyInstance, $anotherInstance, &$resolvedClasses) {
                $resolvedClasses[] = $id;

                return match ($id) {
                    DummyMiddleware::class => $dummyInstance,
                    AnotherMiddleware::class => $anotherInstance,
                };
            });

        $handler = $this->createFallbackHandler(new Response(200, [], 'ok'));

        // Interleave: Dummy, Another, Dummy again — Dummy should be resolved only once.
        $pipeline = new MiddlewarePipeline(
            [DummyMiddleware::class, AnotherMiddleware::class, DummyMiddleware::class],
            $handler,
            $container,
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame('applied', $response->getHeaderLine('X-Dummy-Middleware'));
        self::assertSame('applied', $response->getHeaderLine('X-Another-Middleware'));

        // DummyMiddleware::class resolved once, AnotherMiddleware::class resolved once.
        self::assertCount(2, $resolvedClasses);
        self::assertContains(DummyMiddleware::class, $resolvedClasses);
        self::assertContains(AnotherMiddleware::class, $resolvedClasses);
    }

    #[Test]
    public function indexIsRestoredAfterMiddlewareThrowsException(): void
    {
        $container = new SimpleContainer();
        $handler = $this->createFallbackHandler(new Response(200, [], 'fallback'));

        $throwingMiddleware = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \RuntimeException('middleware error');
            }
        };

        $pipeline = new MiddlewarePipeline([$throwingMiddleware], $handler, $container);

        try {
            $pipeline->handle(new ServerRequest('GET', '/'));
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('middleware error', $e->getMessage());
        }

        // Pipeline must be reusable after an exception — index was restored by finally.
        $pipeline2 = new MiddlewarePipeline([], $handler, $container);
        $response = $pipeline2->handle(new ServerRequest('GET', '/'));
        self::assertSame('fallback', (string) $response->getBody());
    }

    #[Test]
    public function pipelineIsReusableAcrossMultipleCalls(): void
    {
        $container = new SimpleContainer();
        $handler = $this->createFallbackHandler(new Response(200, [], 'ok'));

        $pipeline = new MiddlewarePipeline(
            [new DummyMiddleware()],
            $handler,
            $container,
        );

        $first = $pipeline->handle(new ServerRequest('GET', '/first'));
        $second = $pipeline->handle(new ServerRequest('GET', '/second'));

        self::assertSame('applied', $first->getHeaderLine('X-Dummy-Middleware'));
        self::assertSame('applied', $second->getHeaderLine('X-Dummy-Middleware'));
    }

    #[Test]
    public function shortCircuitMiddlewareSkipsRemainingStack(): void
    {
        $container = new SimpleContainer();
        $handler = $this->createFallbackHandler(new Response(200, [], 'should-not-reach'));

        $shortCircuit = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(403, [], 'forbidden');
            }
        };

        $pipeline = new MiddlewarePipeline(
            [$shortCircuit, new DummyMiddleware()],
            $handler,
            $container,
        );

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', (string) $response->getBody());
        // DummyMiddleware was never reached, so its header must be absent.
        self::assertSame('', $response->getHeaderLine('X-Dummy-Middleware'));
    }

    #[Test]
    public function throwsWhenContainerResolvesNonMiddlewareInstance(): void
    {
        $container = new SimpleContainer();
        $container->set('not_a_middleware', new \stdClass());

        $handler = $this->createFallbackHandler(new Response(200));

        $pipeline = new MiddlewarePipeline(
            ['not_a_middleware'],
            $handler,
            $container,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        $pipeline->handle(new ServerRequest('GET', '/'));
    }

    private function createFallbackHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new readonly class ($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
