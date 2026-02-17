<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Loader\AttributeRouteLoader;

/**
 * Standalone route registration builder.
 *
 * Provides a fluent API for manual route registration, attribute-based loading,
 * and route groups with shared prefixes and middleware.
 *
 * Once routes are registered, use {@see getRouteCollection()} to pass the
 * collection to a {@see Router} or {@see Cache\RouteCompiler}.
 */
final class RouteRegistrar
{
    private RouteCollection $routes;
    private ?AttributeRouteLoader $loader = null;

    /** Current group prefix (used inside {@see group()} callback). */
    private string $groupPrefix = '';

    /** @var list<string> Current group middleware (used inside {@see group()} callback). */
    private array $groupMiddleware = [];

    public function __construct(?RouteCollection $routes = null)
    {
        $this->routes = $routes ?? new RouteCollection();
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
     * @param string $directory   Absolute path to the directory.
     * @param string $namespace   PSR-4 namespace prefix for the directory.
     * @param string $filePattern Glob pattern applied to filenames (e.g. '*Controller.php').
     *                            Defaults to '*.php' (all PHP files).
     */
    public function scanDirectory(string $directory, string $namespace, string $filePattern = '*.php'): self
    {
        foreach ($this->getLoader()->loadFromDirectory($directory, $namespace, $filePattern) as $route) {
            $this->routes->add($route);
        }

        return $this;
    }

    // ── Access ──────────────────────────────────────────────────

    /**
     * Get the route collection built by this registrar.
     */
    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    // ── Private helpers ──────────────────────────────────────────

    /**
     * Lazily create the attribute route loader.
     */
    private function getLoader(): AttributeRouteLoader
    {
        return $this->loader ??= new AttributeRouteLoader();
    }

    private function buildPath(string $path): string
    {
        // Fast path: no group prefix — skip regex normalisation entirely.
        if ($this->groupPrefix === '') {
            return '/' . ltrim($path, '/');
        }

        $full = $this->groupPrefix . '/' . ltrim($path, '/');
        $normalised = preg_replace('#/{2,}#', '/', $full) ?? $full;

        return '/' . ltrim($normalised, '/');
    }
}
