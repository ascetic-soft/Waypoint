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
     * @param list<string|MiddlewareInterface> $middlewares  Middleware instances or class-strings resolved via container.
     * @param RequestHandlerInterface          $handler      Fallback handler (e.g. RouteHandler).
     * @param ContainerInterface               $container    PSR-11 container for resolving class-string middleware.
     */
    public function __construct(
        private readonly array $middlewares,
        private readonly RequestHandlerInterface $handler,
        private readonly ContainerInterface $container,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->index >= \count($this->middlewares)) {
            return $this->handler->handle($request);
        }

        $middleware = $this->middlewares[$this->index];

        if (\is_string($middleware)) {
            /** @var MiddlewareInterface $middleware */
            $middleware = $this->container->get($middleware);
        }

        // Advance index for the next call in the chain
        $next = clone $this;
        $next->index = $this->index + 1;

        return $middleware->process($request, $next);
    }
}
