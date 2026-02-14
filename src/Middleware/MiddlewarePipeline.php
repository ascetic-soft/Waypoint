<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 compatible middleware pipeline.
 *
 * Executes a stack of middleware in FIFO order, delegating to the
 * provided fallback handler when the stack is exhausted.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    private int $index = 0;

    /**
     * Shared cache of resolved middleware instances (class-string → MiddlewareInterface).
     *
     * Uses {@see \ArrayObject} so that the reference survives {@see clone}
     * (shallow copy keeps the same object) and every step in the pipeline
     * chain benefits from a single container lookup per class-string.
     *
     * @var \ArrayObject<string, MiddlewareInterface>
     */
    private \ArrayObject $resolvedMiddleware;

    /**
     * @param list<string|MiddlewareInterface> $middlewares  Middleware instances or class-strings resolved via container.
     * @param RequestHandlerInterface          $handler      Fallback handler (e.g. RouteHandler).
     * @param ContainerInterface               $container    PSR-11 container for resolving class-string middleware.
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly RequestHandlerInterface $handler,
        private readonly ContainerInterface $container,
    ) {
        $this->resolvedMiddleware = new \ArrayObject();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->index >= \count($this->middlewares)) {
            return $this->handler->handle($request);
        }

        $middleware = $this->middlewares[$this->index];

        if (\is_string($middleware)) {
            if (!isset($this->resolvedMiddleware[$middleware])) {
                /** @var MiddlewareInterface $resolved */
                $resolved = $this->container->get($middleware);
                $this->resolvedMiddleware[$middleware] = $resolved;
            }
            $middleware = $this->resolvedMiddleware[$middleware];
        }

        // Advance index for the next call in the chain
        $next = clone $this;
        $next->index = $this->index + 1;

        return $middleware->process($request, $next);
    }
}
