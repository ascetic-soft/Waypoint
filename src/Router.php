<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Cache\RouteCompiler;
use AsceticSoft\Waypoint\Middleware\MiddlewarePipeline;
use AsceticSoft\Waypoint\Middleware\RouteHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 request handler that dispatches to matched routes through a middleware pipeline.
 *
 * Route registration is handled by {@see RouteRegistrar}; this class is
 * responsible only for matching, middleware execution, and dispatching.
 *
 * Dependencies can be injected via constructor for testability and flexibility:
 * - Pass a pre-built {@see RouteCollection} from a {@see RouteRegistrar}.
 * - Pass a pre-built {@see UrlMatcherInterface} from {@see RouteCompiler::load()}.
 */
final class Router implements RequestHandlerInterface
{
    private ?UrlGenerator $urlGenerator = null;

    /** @var list<string|MiddlewareInterface> Global middleware stack. */
    private array $globalMiddleware = [];

    /** Base URL (scheme + host) used for absolute URL generation. */
    private string $baseUrl = '';

    public function __construct(
        private readonly ContainerInterface $container,
        private RouteCollection $routes = new RouteCollection(),
        private ?UrlMatcherInterface $matcher = null,
    ) {
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
     * Load routes from a compiled cache file, replacing the current collection.
     *
     * Delegates to {@see RouteCompiler::load()} which supports all cache formats
     * (Phase 3 compiled matcher, Phase 2 array-with-trie, and legacy flat array).
     */
    public function loadCache(string $cacheFilePath): self
    {
        $compiler = new RouteCompiler();
        $this->matcher = $compiler->load($cacheFilePath);
        $this->routes = new RouteCollection();
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

        $result = $this->getMatcher()->match($method, $uri);

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

        // Merge global + route-specific middleware (avoid array_merge when either is empty).
        $routeMw = $route->getMiddleware();

        if ($this->globalMiddleware === [] && $routeMw === []) {
            return $routeHandler->handle($routeRequest);
        }
        if ($this->globalMiddleware === []) {
            $allMiddleware = $routeMw;
        } else {
            $allMiddleware = ($routeMw === [] ? $this->globalMiddleware : array_merge($this->globalMiddleware, $routeMw));
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
        $this->urlGenerator = null;

        return $this;
    }

    /**
     * Get the URL generator instance (lazily created).
     */
    public function getUrlGenerator(): UrlGenerator
    {
        return $this->urlGenerator ??= new UrlGenerator($this->getRouteCollection(), $this->baseUrl);
    }

    // ── Access to internals (for diagnostics, testing) ───────────

    /**
     * Get the route collection.
     *
     * When routes are loaded from cache, the collection is hydrated
     * lazily from the compiled matcher data.
     */
    public function getRouteCollection(): RouteCollection
    {
        if ($this->matcher instanceof AbstractUrlMatcher) {
            return $this->matcher->getRouteCollection();
        }

        return $this->routes;
    }

    /**
     * Get the URL matcher instance (lazily created from the route collection).
     */
    public function getMatcher(): UrlMatcherInterface
    {
        return $this->matcher ??= new TrieMatcher($this->routes);
    }
}
