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
 *
 * Uses an index-based iteration instead of cloning: each call to
 * {@see handle()} advances the internal cursor, and a {@see finally}
 * block restores it so the pipeline stays in a consistent state
 * regardless of short-circuits or exceptions.
 */
final class MiddlewarePipeline implements RequestHandlerInterface
{
    private int $index = 0;

    /**
     * Cache of resolved middleware instances (class-string → MiddlewareInterface).
     *
     * @var array<string, MiddlewareInterface>
     */
    private array $resolvedMiddleware = [];

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
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->index >= \count($this->middlewares)) {
            return $this->handler->handle($request);
        }

        $middleware = $this->middlewares[$this->index];

        if (\is_string($middleware)) {
            if (!isset($this->resolvedMiddleware[$middleware])) {
                $resolved = $this->container->get($middleware);
                if (!$resolved instanceof MiddlewareInterface) {
                    throw new \RuntimeException(\sprintf(
                        'Middleware "%s" resolved from the container must implement %s.',
                        $middleware,
                        MiddlewareInterface::class,
                    ));
                }
                $this->resolvedMiddleware[$middleware] = $resolved;
            }
            $middleware = $this->resolvedMiddleware[$middleware];
        }

        // Advance index and pass $this as the next handler — no clone needed.
        // The finally block restores the index so the pipeline remains
        // consistent after short-circuits, exceptions, or normal returns.
        ++$this->index;

        try {
            return $middleware->process($request, $this);
        } finally {
            --$this->index;
        }
    }
}
