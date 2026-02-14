<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Cache\CompiledMatcherInterface;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Stores routes and provides URI + HTTP-method matching.
 *
 * Internally uses a prefix-tree ({@see RouteTrie}) for fast segment-by-segment
 * lookups.  Routes whose patterns cannot be expressed in the trie (e.g. cross-
 * segment parameters) fall back to linear regex matching.
 *
 * Routes are matched in priority order (descending), then in registration order.
 * If the URI matches at least one route but none of the matched routes allows
 * the requested HTTP method, {@see MethodNotAllowedException} is thrown.
 *
 * @phpstan-import-type RouteDataArray from Route
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** Whether the routes have been sorted by priority. */
    private bool $sorted = false;

    /** Prefix-tree root for trie-compatible routes (built lazily). */
    private ?RouteTrie $trie = null;

    /** @var list<Route> Routes that cannot be represented in the trie. */
    private array $fallbackRoutes = [];

    /** Whether the trie has been built from the current route set. */
    private bool $trieBuilt = false;

    /** @var array<string, Route>|null Lazy name-to-route index for reverse routing. */
    private ?array $nameIndex = null;

    /**
     * Raw compiled cache data — Phase 2 format (null when not loaded from Phase 2 cache).
     *
     * @var array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>}|null
     */
    private ?array $compiledData = null;

    /**
     * Compiled PHP matcher — Phase 3 format.
     *
     * When set, matching delegates to the compiled class with match expressions.
     * Only the matched route is instantiated as a {@see Route} object.
     */
    private ?CompiledMatcherInterface $compiledMatcher = null;

    /**
     * Create a collection from pre-compiled cache data.
     *
     * The trie and fallback routes are already built — no sorting or
     * trie construction is needed at runtime.
     *
     * @param list<Route> $routes         All routes (already sorted by priority).
     * @param RouteTrie   $trie           Pre-built prefix trie.
     * @param list<Route> $fallbackRoutes Non-trie-compatible routes.
     */
    public static function fromCompiled(array $routes, RouteTrie $trie, array $fallbackRoutes): self
    {
        $collection = new self();
        $collection->routes = $routes;
        $collection->sorted = true;
        $collection->trie = $trie;
        $collection->fallbackRoutes = $fallbackRoutes;
        $collection->trieBuilt = true;

        return $collection;
    }

    /**
     * Create a collection from raw cache data — Phase 2 format (no object reconstruction).
     *
     * @param array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>} $cacheData
     */
    public static function fromCompiledRaw(array $cacheData): self
    {
        $collection = new self();
        $collection->compiledData = $cacheData;
        $collection->trieBuilt = true;
        $collection->sorted = true;

        return $collection;
    }

    /**
     * Create a collection from a compiled PHP matcher — Phase 3 format.
     *
     * The matcher class contains match expressions for O(1) static route lookup
     * and generated trie-traversal methods for dynamic routes.
     */
    public static function fromCompiledMatcher(CompiledMatcherInterface $matcher): self
    {
        $collection = new self();
        $collection->compiledMatcher = $matcher;
        $collection->trieBuilt = true;
        $collection->sorted = true;

        return $collection;
    }

    /**
     * Add a route to the collection.
     */
    public function add(Route $route): void
    {
        $this->routes[] = $route;
        $this->sorted = false;
        $this->trieBuilt = false;
        $this->nameIndex = null;
    }

    /**
     * Match the given HTTP method and URI against the stored routes.
     *
     * @throws RouteNotFoundException      When no route pattern matches the URI.
     * @throws MethodNotAllowedException   When the URI matches but the method is not allowed.
     */
    public function match(string $method, string $uri): RouteMatchResult
    {
        // Fast path: compiled PHP matcher (Phase 3).
        if ($this->compiledMatcher !== null) {
            return $this->matchFromCompiledMatcher($method, $uri);
        }

        // Fast path: compiled cache data available — no objects needed.
        if ($this->compiledData !== null) {
            return $this->matchFromCompiled($method, $uri);
        }

        $this->buildTrie();

        if ($this->trie === null) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('RouteTrie was not initialized after buildTrie().');
            // @codeCoverageIgnoreEnd
        }

        $method = strtoupper($method);
        $allowedMethods = [];

        // 1. Try the prefix-tree (covers the majority of routes).
        $segments = RouteTrie::splitUri($uri);
        $result = $this->trie->match($method, $segments, 0, [], $allowedMethods);

        if ($result !== null) {
            return new RouteMatchResult($result['route'], $result['params']);
        }

        // 2. Fallback: linear scan for non-trie-compatible routes.
        foreach ($this->fallbackRoutes as $route) {
            $params = $route->match($uri);

            if ($params === null) {
                continue;
            }

            // URI matched — check HTTP method.
            if ($route->allowsMethod($method)) {
                return new RouteMatchResult($route, $params);
            }

            // Collect allowed methods for 405 response.
            foreach ($route->getMethods() as $m) {
                $allowedMethods[$m] = true;
            }
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException(
                array_keys($allowedMethods),
                $method,
                $uri,
            );
        }

        throw new RouteNotFoundException($uri);
    }

    /**
     * @return list<Route>
     */
    public function all(): array
    {
        $this->hydrateIfNeeded();
        $this->sort();

        return $this->routes;
    }

    /**
     * Find a route by its name.
     *
     * @return Route|null The route with the given name, or null if not found.
     */
    public function findByName(string $name): ?Route
    {
        // Fast path: compiled PHP matcher with O(1) name lookup.
        if ($this->compiledMatcher !== null && $this->nameIndex === null) {
            $idx = $this->compiledMatcher->findByName($name);

            if ($idx === null) {
                return null;
            }

            return Route::fromCompactArray($this->compiledMatcher->getRoute($idx));
        }

        // Fast path: search raw compiled data without full hydration.
        if ($this->compiledData !== null && $this->nameIndex === null) {
            $this->buildNameIndexFromCompiled();

            return $this->nameIndex[$name] ?? null;
        }

        $this->buildNameIndex();

        return $this->nameIndex[$name] ?? null;
    }

    // ── Internals ────────────────────────────────────────────────

    /**
     * Build the prefix-tree from the current (sorted) route set.
     */
    private function buildTrie(): void
    {
        if ($this->trieBuilt) {
            return;
        }

        $this->sort();

        $this->trie = new RouteTrie();
        $this->fallbackRoutes = [];

        foreach ($this->routes as $route) {
            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $this->trie->insert($route, $segments);
            } else {
                $this->fallbackRoutes[] = $route;
            }
        }

        $this->trieBuilt = true;
    }

    /**
     * Sort routes by priority (descending). Stable sort preserves registration order.
     */
    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        $index = 0;
        $indexed = [];
        foreach ($this->routes as $route) {
            $indexed[] = [$route, $index++];
        }

        usort($indexed, static function (array $a, array $b): int {
            $priorityCmp = $b[0]->getPriority() <=> $a[0]->getPriority();

            return $priorityCmp !== 0 ? $priorityCmp : $a[1] <=> $b[1];
        });

        $this->routes = array_column($indexed, 0);
        $this->sorted = true;
    }

    /**
     * Build the name-to-route index from the current route set.
     */
    private function buildNameIndex(): void
    {
        if ($this->nameIndex !== null) {
            return;
        }

        $this->hydrateIfNeeded();

        $this->nameIndex = [];

        foreach ($this->routes as $route) {
            $name = $route->getName();
            if ($name !== '') {
                $this->nameIndex[$name] = $route;
            }
        }
    }

    // ── Compiled matcher fast-path (Phase 3) ────────────────────

    /**
     * Match against a compiled PHP matcher object (Phase 3 format).
     *
     * @throws RouteNotFoundException    When no route pattern matches the URI.
     * @throws MethodNotAllowedException When the URI matches but the method is not allowed.
     */
    private function matchFromCompiledMatcher(string $method, string $uri): RouteMatchResult
    {
        \assert($this->compiledMatcher !== null);

        $method = strtoupper($method);

        // 1. Static route hash table — O(1) match expression.
        $result = $this->compiledMatcher->matchStatic($method, $uri);

        if ($result !== null) {
            [$idx, $params] = $result;

            return new RouteMatchResult(
                Route::fromCompactArray($this->compiledMatcher->getRoute($idx)),
                $params,
            );
        }

        // 2. Dynamic trie — generated segment-by-segment dispatch.
        $allowedMethods = [];
        $result = $this->compiledMatcher->matchDynamic($method, $uri, $allowedMethods);

        if ($result !== null) {
            [$idx, $params] = $result;

            return new RouteMatchResult(
                Route::fromCompactArray($this->compiledMatcher->getRoute($idx)),
                $params,
            );
        }

        // 3. Fallback routes (non-trie-compatible).
        foreach ($this->compiledMatcher->getFallbackIndices() as $idx) {
            $routeData = $this->compiledMatcher->getRoute($idx);
            $route = Route::fromCompactArray($routeData);
            $params = $route->match($uri);

            if ($params === null) {
                continue;
            }

            if ($route->allowsMethod($method)) {
                return new RouteMatchResult($route, $params);
            }

            foreach ($route->getMethods() as $m) {
                $allowedMethods[$m] = true;
            }
        }

        // 4. Collect allowed methods from static routes (for 405).
        $staticAllowed = $this->compiledMatcher->staticMethods($uri);
        foreach ($staticAllowed as $m) {
            $allowedMethods[$m] = true;
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException(array_keys($allowedMethods), $method, $uri);
        }

        throw new RouteNotFoundException($uri);
    }

    // ── Compiled data fast-path (Phase 2) ───────────────────────

    /**
     * Match against raw compiled cache data (Phase 2 — no object reconstruction).
     *
     * @throws RouteNotFoundException    When no route pattern matches the URI.
     * @throws MethodNotAllowedException When the URI matches but the method is not allowed.
     */
    private function matchFromCompiled(string $method, string $uri): RouteMatchResult
    {
        \assert($this->compiledData !== null);

        $method = strtoupper($method);

        // 1. Static route hash table — O(1) lookup.
        $key = $method . ':' . $uri;
        if (isset($this->compiledData['staticTable'][$key])) {
            $idx = $this->compiledData['staticTable'][$key];
            /** @var RouteDataArray $routeEntry */
            $routeEntry = $this->compiledData['routes'][$idx];

            return new RouteMatchResult(
                Route::fromArray($routeEntry),
                [],
            );
        }

        // 2. Array-based trie matching.
        $segments = RouteTrie::splitUri($uri);
        $allowedMethods = [];

        $result = RouteTrie::matchArray(
            $this->compiledData['trie'],
            $this->compiledData['routes'],
            $method,
            $segments,
            0,
            [],
            $allowedMethods,
        );

        if ($result !== null) {
            /** @var RouteDataArray $routeEntry */
            $routeEntry = $this->compiledData['routes'][$result['index']];

            return new RouteMatchResult(
                Route::fromArray($routeEntry),
                $result['params'],
            );
        }

        // 3. Fallback routes (non-trie-compatible).
        foreach ($this->compiledData['fallback'] as $idx) {
            /** @var RouteDataArray $routeData */
            $routeData = $this->compiledData['routes'][$idx];
            $route = Route::fromArray($routeData);
            $params = $route->match($uri);

            if ($params === null) {
                continue;
            }

            if ($route->allowsMethod($method)) {
                return new RouteMatchResult($route, $params);
            }

            foreach ($route->getMethods() as $m) {
                $allowedMethods[$m] = true;
            }
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException(array_keys($allowedMethods), $method, $uri);
        }

        throw new RouteNotFoundException($uri);
    }

    /**
     * Build the name index directly from compiled data without full hydration.
     */
    private function buildNameIndexFromCompiled(): void
    {
        \assert($this->compiledData !== null);

        $this->nameIndex = [];

        foreach ($this->compiledData['routes'] as $routeData) {
            /** @var RouteDataArray $routeData */
            $name = $routeData['name'] ?? '';
            if ($name !== '') {
                $this->nameIndex[$name] = Route::fromArray($routeData);
            }
        }
    }

    /**
     * Hydrate Route objects from compiled data if needed.
     */
    private function hydrateIfNeeded(): void
    {
        // Hydrate from compiled PHP matcher (Phase 3).
        if ($this->compiledMatcher !== null) {
            $count = $this->compiledMatcher->getRouteCount();
            for ($i = 0; $i < $count; $i++) {
                $this->routes[] = Route::fromCompactArray($this->compiledMatcher->getRoute($i));
            }

            $this->compiledMatcher = null;
            $this->sorted = true;
            $this->trieBuilt = false;

            return;
        }

        // Hydrate from compiled data (Phase 2).
        if ($this->compiledData !== null) {
            foreach ($this->compiledData['routes'] as $routeData) {
                /** @var RouteDataArray $routeData */
                $this->routes[] = Route::fromArray($routeData);
            }

            $this->compiledData = null;
            $this->sorted = true;
            $this->trieBuilt = false;
        }
    }
}
