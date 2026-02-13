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
    private ?AttributeRouteLoader $loader = null;
    private ?UrlGenerator $urlGenerator = null;

    /** @var list<string|MiddlewareInterface> Global middleware stack. */
    private array $globalMiddleware = [];

    /** Current group prefix (used inside {@see group()} callback). */
    private string $groupPrefix = '';

    /** @var list<string> Current group middleware (used inside {@see group()} callback). */
    private array $groupMiddleware = [];

    /** Base URL (scheme + host) used for absolute URL generation. */
    private string $baseUrl = '';

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        $this->routes = new RouteCollection();
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
    ): self {
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
    public function get(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): self
    {
        return $this->addRoute($path, $handler, ['GET'], $middleware, $name, $priority);
    }

    /**
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param list<string>                             $middleware
     */
    public function post(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): self
    {
        return $this->addRoute($path, $handler, ['POST'], $middleware, $name, $priority);
    }

    /**
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param list<string>                             $middleware
     */
    public function put(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): self
    {
        return $this->addRoute($path, $handler, ['PUT'], $middleware, $name, $priority);
    }

    /**
     * @param array{0:class-string,1:string}|\Closure $handler
     * @param list<string>                             $middleware
     */
    public function delete(string $path, array|\Closure $handler, array $middleware = [], string $name = '', int $priority = 0): self
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
    public function group(string $prefix, \Closure $callback, array $middleware = []): self
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
    public function loadAttributes(string ...$controllerClasses): self
    {
        foreach ($controllerClasses as $className) {
            foreach ($this->getLoader()->loadFromClass($className) as $route) {
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
    public function scanDirectory(string $directory, string $namespace): self
    {
        foreach ($this->getLoader()->loadFromDirectory($directory, $namespace) as $route) {
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
    public function addMiddleware(string|MiddlewareInterface ...$middleware): self
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
    public function loadCache(string $cacheFilePath): self
    {
        $compiler = new RouteCompiler();
        $this->routes = $compiler->load($cacheFilePath);
        $this->urlGenerator = null;

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

        // Store route parameters in request attributes (skip when empty).
        $routeRequest = $request;
        if ($result->parameters !== []) {
            foreach ($result->parameters as $key => $value) {
                $routeRequest = $routeRequest->withAttribute($key, $value);
            }
        }

        $route = $result->route;

        // Build the final handler
        $routeHandler = new RouteHandler(
            handler: $route->getHandler(),
            parameters: $result->parameters,
            container: $this->container,
            argPlan: $route->getArgPlan(),
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

    // ── URL generation ──────────────────────────────────────────

    /**
     * Set the base URL (scheme + host) for absolute URL generation.
     *
     * @param string $baseUrl e.g. "https://example.com"
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        $this->urlGenerator = null; // reset so the new baseUrl takes effect

        return $this;
    }

    /**
     * Generate a URL for the given named route.
     *
     * Convenience shortcut for {@see UrlGenerator::generate()}.
     *
     * @param string                          $name       The route name.
     * @param array<string,string|int|float>  $parameters Route parameter values keyed by name.
     * @param array<string,mixed>             $query      Optional query-string parameters.
     * @param bool                            $absolute   When true, prepend scheme and host.
     */
    public function generate(string $name, array $parameters = [], array $query = [], bool $absolute = false): string
    {
        return $this->getUrlGenerator()->generate($name, $parameters, $query, $absolute);
    }

    /**
     * Get the URL generator instance (lazily created).
     */
    public function getUrlGenerator(): UrlGenerator
    {
        return $this->urlGenerator ??= new UrlGenerator($this->routes, $this->baseUrl);
    }

    // ── Access to internals (for diagnostics, testing) ───────────

    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * Lazily create the attribute route loader.
     *
     * The loader is only needed when routes are registered via attributes —
     * not when the router loads exclusively from cache.
     */
    private function getLoader(): AttributeRouteLoader
    {
        return $this->loader ??= new AttributeRouteLoader();
    }

    private function buildPath(string $path): string
    {
        $full = $this->groupPrefix . '/' . ltrim($path, '/');
        $normalised = preg_replace('#/{2,}#', '/', $full) ?? $full;
        return '/' . ltrim($normalised, '/');
    }
}
