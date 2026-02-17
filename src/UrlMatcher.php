<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Cache\CompiledMatcherInterface;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Standalone URI + HTTP-method matcher with no PSR dependencies.
 *
 * Internally uses a prefix-tree ({@see RouteTrie}) for fast segment-by-segment
 * lookups.  Routes whose patterns cannot be expressed in the trie (e.g. cross-
 * segment parameters) fall back to linear regex matching.
 *
 * Supports three matching strategies:
 * - Phase 1: Runtime trie built on-demand from a {@see RouteCollection}.
 * - Phase 2: Pre-compiled array data (no object reconstruction).
 * - Phase 3: Compiled PHP matcher class (opcache-optimized).
 *
 * @phpstan-import-type RouteDataArray from Route
 */
final class UrlMatcher implements UrlMatcherInterface
{
    /** Route collection for Phase 1 and hydration. */
    private RouteCollection $routes;

    /** Whether the RouteCollection has been hydrated from compiled data. */
    private bool $hydrated = false;

    // ── Phase 1 matching state ──────────────────────────────────

    /** Prefix-tree root for trie-compatible routes (built lazily). */
    private ?RouteTrie $trie = null;

    /** @var list<Route> Routes that cannot be represented in the trie. */
    private array $fallbackRoutes = [];

    /**
     * Fallback routes grouped by first URI segment for prefix-based filtering.
     *
     * @var array<string, list<array{int, Route}>>
     */
    private array $fallbackRouteMap = [];

    /** Whether the trie has been built from the current route set. */
    private bool $trieBuilt = false;

    /**
     * Static route hash table for O(1) lookup in non-compiled mode.
     *
     * @var array<string, Route>
     */
    private array $staticTable = [];

    // ── Phase 2 compiled data ───────────────────────────────────

    /**
     * Raw compiled cache data — Phase 2 format.
     *
     * @var array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>}|null
     */
    private ?array $compiledData = null;

    // ── Phase 3 compiled matcher ────────────────────────────────

    /**
     * Compiled PHP matcher — Phase 3 format.
     *
     * When set, matching delegates to the compiled class with match expressions.
     */
    private ?CompiledMatcherInterface $compiledMatcher = null;

    // ── Shared state ────────────────────────────────────────────

    /** @var array<int, Route> Lazily hydrated route cache keyed by compiled route index. */
    private array $routeCache = [];

    /**
     * Lazy fallback route map for Phase 2 compiled data.
     *
     * @var array<string, list<array{int, int}>>|null
     */
    private ?array $compiledFallbackMap = null;

    /**
     * Lazy fallback route map for Phase 3 compiled matcher.
     *
     * @var array<string, list<array{int, int}>>|null
     */
    private ?array $matcherFallbackMap = null;

    /**
     * Lightweight name-to-index map for Phase 2 compiled data.
     *
     * @var array<string, int>|null
     */
    private ?array $compiledNameMap = null;

    // ── Construction ────────────────────────────────────────────

    /**
     * Create a matcher from a route collection (Phase 1).
     *
     * The trie is built lazily on the first {@see match()} call.
     */
    public function __construct(RouteCollection $routes)
    {
        $this->routes = $routes;
    }

    /**
     * Create a matcher from raw compiled cache data (Phase 2).
     *
     * @param array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>} $cacheData
     */
    public static function fromCompiledRaw(array $cacheData): self
    {
        $matcher = new self(new RouteCollection());
        $matcher->compiledData = $cacheData;
        $matcher->trieBuilt = true;

        return $matcher;
    }

    /**
     * Create a matcher from a compiled PHP matcher object (Phase 3).
     */
    public static function fromCompiledMatcher(CompiledMatcherInterface $compiledMatcher): self
    {
        $matcher = new self(new RouteCollection());
        $matcher->compiledMatcher = $compiledMatcher;
        $matcher->trieBuilt = true;

        return $matcher;
    }

    // ── UrlMatcherInterface ─────────────────────────────────────

    /**
     * Match the given HTTP method and URI against the stored routes.
     *
     * Implements automatic HEAD→GET fallback per RFC 7231 §4.3.2:
     * if no route explicitly handles HEAD but a GET route exists for the
     * same URI, the GET route is returned.
     *
     * @throws RouteNotFoundException      When no route pattern matches the URI.
     * @throws MethodNotAllowedException   When the URI matches but the method is not allowed.
     */
    public function match(string $method, string $uri): RouteMatchResult
    {
        $method = strtoupper($method);

        try {
            return $this->performMatch($method, $uri);
        } catch (MethodNotAllowedException $e) {
            // RFC 7231 §4.3.2: HEAD must be handled identically to GET
            // (without the response body).  When no explicit HEAD route
            // exists but a GET route matches, fall back to GET.
            if ($method === 'HEAD' && \in_array('GET', $e->getAllowedMethods(), true)) {
                return $this->performMatch('GET', $uri);
            }

            throw $e;
        }
    }

    // ── Route access ────────────────────────────────────────────

    /**
     * Get the underlying route collection, hydrating from compiled data if needed.
     *
     * Useful for URL generation, diagnostics, and other non-matching operations.
     */
    public function getRouteCollection(): RouteCollection
    {
        $this->hydrateIfNeeded();

        return $this->routes;
    }

    /**
     * Find a route by its name.
     *
     * Uses optimized O(1) lookups for Phase 2/3 compiled data.
     *
     * @return Route|null The route with the given name, or null if not found.
     */
    public function findByName(string $name): ?Route
    {
        // Fast path: compiled PHP matcher with O(1) name lookup.
        if ($this->compiledMatcher !== null) {
            $idx = $this->compiledMatcher->findByName($name);

            if ($idx === null) {
                return null;
            }

            return Route::fromCompactArray($this->compiledMatcher->getRoute($idx));
        }

        // Fast path: search raw compiled data without full hydration.
        if ($this->compiledData !== null) {
            $this->buildCompiledNameMap();

            $idx = $this->compiledNameMap[$name] ?? null;

            return $idx !== null ? $this->getCachedCompiledRoute($idx) : null;
        }

        // Fall back to the route collection.
        return $this->routes->findByName($name);
    }

    // ── Internals ───────────────────────────────────────────────

    /**
     * Internal matching dispatcher — delegates to the appropriate matching
     * strategy (Phase 3 compiled matcher, Phase 2 compiled data, or Phase 1 trie).
     *
     * The $method parameter MUST already be upper-case.
     *
     * @throws RouteNotFoundException      When no route pattern matches the URI.
     * @throws MethodNotAllowedException   When the URI matches but the method is not allowed.
     */
    private function performMatch(string $method, string $uri): RouteMatchResult
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

        // 0. Static route hash table — O(1) lookup for parameter-less routes.
        $key = $method . ':' . $uri;
        if (isset($this->staticTable[$key])) {
            return new RouteMatchResult($this->staticTable[$key], []);
        }

        $allowedMethods = [];

        // 1. Try the prefix-tree (covers the majority of routes).
        // Inlined RouteTrie::splitUri() to avoid function-call overhead on every request.
        $trimmed = ltrim($uri, '/');
        $segments = $trimmed === '' ? [] : explode('/', $trimmed);
        $result = $this->trie->match($method, $segments, 0, [], $allowedMethods);

        if ($result !== null) {
            return new RouteMatchResult($result['route'], $result['params']);
        }

        // 2. Fallback: prefix-grouped scan for non-trie-compatible routes.
        $firstSeg = $segments[0] ?? '';
        $candidates = self::mergeFallbackGroups(
            $this->fallbackRouteMap[$firstSeg] ?? [],
            $this->fallbackRouteMap['*'] ?? [],
        );

        foreach ($candidates as [, $route]) {
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
     * Build the prefix-tree from the current (sorted) route set.
     */
    private function buildTrie(): void
    {
        if ($this->trieBuilt) {
            return;
        }

        $allRoutes = $this->routes->all();

        $this->trie = new RouteTrie();
        $this->fallbackRoutes = [];
        $this->staticTable = [];

        foreach ($allRoutes as $route) {
            // Populate static hash table for parameter-less routes (O(1) lookup).
            if (!str_contains($route->getPattern(), '{')) {
                foreach ($route->getMethods() as $m) {
                    $this->staticTable[$m . ':' . $route->getPattern()] ??= $route;
                }
            }

            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $this->trie->insert($route, $segments);
            } else {
                $this->fallbackRoutes[] = $route;
            }
        }

        $this->buildFallbackRouteMap();
        $this->trieBuilt = true;
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

        // 1. Static route hash table — O(1) match expression.
        $result = $this->compiledMatcher->matchStatic($method, $uri);

        if ($result !== null) {
            [$idx, $params] = $result;

            return new RouteMatchResult(
                $this->getCachedCompactRoute($idx),
                $params,
            );
        }

        // Early 405: collect static allowed methods before the expensive trie walk.
        $allowedMethods = [];
        $staticAllowed = $this->compiledMatcher->staticMethods($uri);

        if ($staticAllowed !== []) {
            if ($this->compiledMatcher->isStaticOnly($uri)) {
                throw new MethodNotAllowedException($staticAllowed, $method, $uri);
            }

            foreach ($staticAllowed as $m) {
                $allowedMethods[$m] = true;
            }
        }

        // 2. Dynamic trie — generated segment-by-segment dispatch.
        $result = $this->compiledMatcher->matchDynamic($method, $uri, $allowedMethods);

        if ($result !== null) {
            [$idx, $params] = $result;

            return new RouteMatchResult(
                $this->getCachedCompactRoute($idx),
                $params,
            );
        }

        // 3. Fallback routes (non-trie-compatible, prefix-grouped).
        $firstSeg = self::uriFirstSegment($uri);
        $fMap = $this->getMatcherFallbackMap();
        $fCandidates = self::mergeFallbackGroups(
            $fMap[$firstSeg] ?? [],
            $fMap['*'] ?? [],
        );

        foreach ($fCandidates as [, $idx]) {
            $route = $this->getCachedCompactRoute($idx);
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

        // 1. Static route hash table — O(1) lookup.
        $key = $method . ':' . $uri;
        if (isset($this->compiledData['staticTable'][$key])) {
            $idx = $this->compiledData['staticTable'][$key];

            return new RouteMatchResult(
                $this->getCachedCompiledRoute($idx),
                [],
            );
        }

        // 2. Array-based trie matching.
        $trimmed = ltrim($uri, '/');
        $segments = $trimmed === '' ? [] : explode('/', $trimmed);
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
            return new RouteMatchResult(
                $this->getCachedCompiledRoute($result['index']),
                $result['params'],
            );
        }

        // 3. Fallback routes (non-trie-compatible, prefix-grouped).
        $firstSeg = $segments[0] ?? '';
        $fMap = $this->getCompiledFallbackMap();
        $fCandidates = self::mergeFallbackGroups(
            $fMap[$firstSeg] ?? [],
            $fMap['*'] ?? [],
        );

        foreach ($fCandidates as [, $idx]) {
            $route = $this->getCachedCompiledRoute($idx);
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

    // ── Route hydration ─────────────────────────────────────────

    /**
     * Hydrate Route objects into the RouteCollection from compiled data.
     *
     * Compiled matching data is NOT cleared — matching continues to use
     * the optimal compiled path after hydration.
     */
    private function hydrateIfNeeded(): void
    {
        if ($this->hydrated) {
            return;
        }

        // Hydrate from compiled PHP matcher (Phase 3).
        if ($this->compiledMatcher !== null) {
            $count = $this->compiledMatcher->getRouteCount();
            for ($i = 0; $i < $count; $i++) {
                $this->routes->add($this->getCachedCompactRoute($i));
            }

            $this->hydrated = true;

            return;
        }

        // Hydrate from compiled data (Phase 2).
        if ($this->compiledData !== null) {
            $count = \count($this->compiledData['routes']);
            for ($i = 0; $i < $count; $i++) {
                $this->routes->add($this->getCachedCompiledRoute($i));
            }

            $this->hydrated = true;
        }
    }

    /**
     * Get a cached Route instance by index from Phase 3 compact compiled matcher data.
     */
    private function getCachedCompactRoute(int $idx): Route
    {
        \assert($this->compiledMatcher !== null);

        return $this->routeCache[$idx] ??= Route::fromCompactArray($this->compiledMatcher->getRoute($idx));
    }

    /**
     * Get a cached Route instance by index from Phase 2 compiled route arrays.
     */
    private function getCachedCompiledRoute(int $idx): Route
    {
        \assert($this->compiledData !== null);
        /** @var RouteDataArray $routeData */
        $routeData = $this->compiledData['routes'][$idx];

        return $this->routeCache[$idx] ??= Route::fromArray($routeData);
    }

    // ── Name lookup helpers ─────────────────────────────────────

    /**
     * Build the lightweight name → index map from compiled data.
     */
    private function buildCompiledNameMap(): void
    {
        if ($this->compiledNameMap !== null) {
            return;
        }

        \assert($this->compiledData !== null);

        $this->compiledNameMap = [];

        foreach ($this->compiledData['routes'] as $idx => $routeData) {
            /** @var RouteDataArray $routeData */
            $name = $routeData['name'] ?? '';
            if ($name !== '') {
                $this->compiledNameMap[$name] = $idx;
            }
        }
    }

    // ── Fallback prefix grouping ────────────────────────────────

    /**
     * Build the fallback route map from the current fallback route list.
     */
    private function buildFallbackRouteMap(): void
    {
        $this->fallbackRouteMap = [];

        foreach ($this->fallbackRoutes as $seq => $route) {
            $key = self::fallbackPrefix($route->getPattern());
            $this->fallbackRouteMap[$key][] = [$seq, $route];
        }
    }

    /**
     * Get the fallback route map for Phase 2 compiled data, building it lazily.
     *
     * @return array<string, list<array{int, int}>>
     */
    private function getCompiledFallbackMap(): array
    {
        if ($this->compiledFallbackMap !== null) {
            return $this->compiledFallbackMap;
        }

        \assert($this->compiledData !== null);

        $this->compiledFallbackMap = [];

        foreach ($this->compiledData['fallback'] as $seq => $idx) {
            /** @var string $pattern */
            $pattern = $this->compiledData['routes'][$idx]['path'];
            $key = self::fallbackPrefix($pattern);
            $this->compiledFallbackMap[$key][] = [$seq, $idx];
        }

        return $this->compiledFallbackMap;
    }

    /**
     * Get the fallback route map for Phase 3 compiled matcher, building it lazily.
     *
     * @return array<string, list<array{int, int}>>
     */
    private function getMatcherFallbackMap(): array
    {
        if ($this->matcherFallbackMap !== null) {
            return $this->matcherFallbackMap;
        }

        \assert($this->compiledMatcher !== null);

        $this->matcherFallbackMap = [];

        foreach ($this->compiledMatcher->getFallbackIndices() as $seq => $idx) {
            $routeData = $this->compiledMatcher->getRoute($idx);
            $key = self::fallbackPrefix($routeData['p']);
            $this->matcherFallbackMap[$key][] = [$seq, $idx];
        }

        return $this->matcherFallbackMap;
    }

    /**
     * Extract the first static URI segment from a route pattern.
     *
     * Returns '*' when the first segment contains a parameter placeholder.
     */
    private static function fallbackPrefix(string $pattern): string
    {
        $path = ltrim($pattern, '/');

        if ($path === '') {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }

        $slash = strpos($path, '/');
        $segment = $slash !== false ? substr($path, 0, $slash) : $path;

        return str_contains($segment, '{') ? '*' : $segment;
    }

    /**
     * Extract the first segment from a request URI.
     */
    private static function uriFirstSegment(string $uri): string
    {
        $path = ltrim($uri, '/');

        if ($path === '') {
            return '';
        }

        $slash = strpos($path, '/');

        return $slash !== false ? substr($path, 0, $slash) : $path;
    }

    /**
     * Merge two sequence-ordered fallback groups into a single list
     * maintaining global priority order.
     *
     * @template T
     *
     * @param list<array{int, T}> $a
     * @param list<array{int, T}> $b
     *
     * @return list<array{int, T}>
     */
    private static function mergeFallbackGroups(array $a, array $b): array
    {
        if ($b === []) {
            return $a;
        }

        if ($a === []) {
            return $b;
        }

        $result = [];
        $i = 0;
        $j = 0;
        $na = \count($a);
        $nb = \count($b);

        while ($i < $na && $j < $nb) {
            if ($a[$i][0] <= $b[$j][0]) {
                $result[] = $a[$i++];
            } else {
                $result[] = $b[$j++];
            }
        }

        while ($i < $na) {
            $result[] = $a[$i++];
        }

        while ($j < $nb) {
            $result[] = $b[$j++];
        }

        return $result;
    }
}
