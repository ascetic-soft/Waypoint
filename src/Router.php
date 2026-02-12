<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Cache\RouteCompiler;
use AsceticSoft\Waypoint\Loader\AttributeRouteLoader;
use AsceticSoft\Waypoint\Middleware\MiddlewarePipeline;
use AsceticSoft\Waypoint\Middleware\RouteHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Main router class implementing PSR-15 {@see RequestHandlerInterface}.
 *
 * Provides a fluent API for manual route registration, attribute-based loading,
 * middleware management, route groups, and cache compilation.
 */
final class Router implements RequestHandlerInterface
{
    private RouteCollection $routes;
    private AttributeRouteLoader $loader;

    /** @var list<string|MiddlewareInterface> Global middleware stack. */
    private array $globalMiddleware = [];

    /** Current group prefix (used inside {@see group()} callback). */
    private string $groupPrefix = '';

    /** @var list<string> Current group middleware (used inside {@see group()} callback). */
    private array $groupMiddleware = [];

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        $this->routes = new RouteCollection();
        $this->loader = new AttributeRouteLoader();
    }

    // ── Manual registration ──────────────────────────────────────

    /**
     * Register a route manually.
     *
     * @param string                                    $path       Route pattern.
     * @param array{0:class-string,1:string}|\Closure   $handler    Controller reference or closure.
     * @param list<string>                              $methods    HTTP methods.
     * @param list<string>                              $middleware Middleware class-strings.
     * @param string                                    $name       Route name.
     * @param int                                       $priority   Match priority.
     */
    public function addRoute(
        string $path,
        array|\Closure $handler,
        array $methods = ['GET'],
        array $middleware = [],
        string $name = '',
        int $priority = 0,
    ): static {
        $fullPath = $this->buildPath($path);
        $allMiddleware = array_merge($this->groupMiddleware, $middleware);

        $this->routes->add(new Route(
            pattern: $fullPath,
            methods: array_map('strtoupper', $methods),
            handler: $handler,
            middleware: $allMiddleware,
            name: $name,
            priority: $priority,
        ));

        return $this;
    }

    /**
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param list<string>                             $middleware
     */
    public function get(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): static
    {
        return $this->addRoute($path, $handler, ['GET'], $middleware, $name, $priority);
    }

    /**
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param list<string>                             $middleware
     */
    public function post(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): static
    {
        return $this->addRoute($path, $handler, ['POST'], $middleware, $name, $priority);
    }

    /**
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param list<string>                             $middleware
     */
    public function put(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): static
    {
        return $this->addRoute($path, $handler, ['PUT'], $middleware, $name, $priority);
    }

    /**
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param list<string>                             $middleware
     */
    public function delete(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): static
    {
        return $this->addRoute($path, $handler, ['DELETE'], $middleware, $name, $priority);
    }

    // ── Groups ───────────────────────────────────────────────────

    /**
     * Define a route group with a shared prefix and middleware.
     *
     * @param string   $prefix     URI prefix for all routes in the group.
     * @param \Closure $callback   Callback that registers routes (receives $this).
     * @param list<string> $middleware Middleware applied to all routes in the group.
     */
    public function group(string $prefix, \Closure $callback, array $middleware = []): static
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . '/' . ltrim($prefix, '/');
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    // ── Attribute loading ────────────────────────────────────────

    /**
     * Load routes from the given controller classes using `#[Route]` attributes.
     *
     * @param class-string ...$controllerClasses
     */
    public function loadAttributes(string ...$controllerClasses): static
    {
        foreach ($controllerClasses as $className) {
            foreach ($this->loader->loadFromClass($className) as $route) {
                $this->routes->add($route);
            }
        }

        return $this;
    }

    /**
     * Scan a directory for controller classes and load their `#[Route]` attributes.
     *
     * @param string $directory Absolute path to the directory.
     * @param string $namespace PSR-4 namespace prefix for the directory.
     */
    public function scanDirectory(string $directory, string $namespace): static
    {
        foreach ($this->loader->loadFromDirectory($directory, $namespace) as $route) {
            $this->routes->add($route);
        }

        return $this;
    }

    // ── Global middleware ─────────────────────────────────────────

    /**
     * Add global middleware that runs for every matched route.
     *
     * @param string|MiddlewareInterface ...$middleware
     */
    public function addMiddleware(string|MiddlewareInterface ...$middleware): static
    {
        array_push($this->globalMiddleware, ...$middleware);

        return $this;
    }

    // ── Cache ────────────────────────────────────────────────────

    /**
     * Compile the current route collection to a PHP cache file.
     */
    public function compileTo(string $cacheFilePath): void
    {
        $compiler = new RouteCompiler();
        $compiler->compile($this->routes, $cacheFilePath);
    }

    /**
     * Load routes from a compiled cache file, replacing the current collection.
     */
    public function loadCache(string $cacheFilePath): static
    {
        $compiler = new RouteCompiler();
        $this->routes = $compiler->load($cacheFilePath);

        return $this;
    }

    // ── PSR-15 handle ────────────────────────────────────────────

    /**
     * Handle an incoming PSR-7 request.
     *
     * Matches the request against registered routes, builds a middleware
     * pipeline (global + route-specific), and dispatches to the controller.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = $request->getUri()->getPath();

        $result = $this->routes->match($method, $uri);

        // Store route parameters in request attributes
        $routeRequest = $request;
        foreach ($result->parameters as $key => $value) {
            $routeRequest = $routeRequest->withAttribute($key, $value);
        }

        $route = $result->route;

        // Build the final handler
        $routeHandler = new RouteHandler(
            handler: $route->getHandler(),
            parameters: $result->parameters,
            container: $this->container,
        );

        // Merge global + route-specific middleware
        $allMiddleware = array_merge($this->globalMiddleware, $route->getMiddleware());

        if ($allMiddleware === []) {
            return $routeHandler->handle($routeRequest);
        }

        $pipeline = new MiddlewarePipeline(
            middlewares: $allMiddleware,
            handler: $routeHandler,
            container: $this->container,
        );

        return $pipeline->handle($routeRequest);
    }

    // ── Access to internals (for diagnostics, testing) ───────────

    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    // ── Private helpers ──────────────────────────────────────────

    private function buildPath(string $path): string
    {
        $full = $this->groupPrefix . '/' . ltrim($path, '/');
        $normalised = preg_replace('#/{2,}#', '/', $full) ?? $full;
        $full = '/' . ltrim($normalised, '/');

        return $full;
    }
}
