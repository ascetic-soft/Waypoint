<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Phase 1 matcher: runtime prefix-tree (trie) built lazily from a {@see RouteCollection}.
 *
 * The trie is built from RouteTrie objects during initialisation, then
 * converted to a flat PHP array for matching.  This avoids keeping the
 * RouteTrie object graph (and its Route references) in memory.
 *
 * Routes whose patterns cannot be expressed in the trie (e.g. cross-segment
 * parameters) fall back to linear regex matching with prefix-based grouping.
 */
final class TrieMatcher extends AbstractUrlMatcher
{
    /**
     * Array-based trie for trie-compatible routes (built lazily).
     *
     * @var array{static: array<string, mixed>, param: list<array<string, mixed>>, routes: list<int>}|null
     */
    private ?array $trieArray = null;

    /**
     * Minimal route data needed for matching — no handlers or closures.
     *
     * Each entry contains only the HTTP methods (as a hash-map for O(1)
     * lookup), pattern, compiled regex, and parameter names.  Keeping
     * handlers/closures out of this array avoids duplicating references
     * and allows the GC to collect unused Route objects held only by the
     * {@see RouteCollection}.
     *
     * @var list<array{methods: array<string, true>, path: string, compiledRegex: string, parameterNames: list<string>}>
     */
    private array $routeData = [];

    /**
     * Pre-sorted Route objects from the collection.
     *
     * Stored once during {@see buildTrie()} so that matched routes can be
     * returned without re-sorting or re-fetching from the collection.
     *
     * @var list<Route>
     */
    private array $allRoutes = [];

    /** @var list<int> Non-trie-compatible route indices. */
    private array $fallbackIndices = [];

    /**
     * Fallback routes grouped by first URI segment for prefix-based filtering.
     *
     * @var array<string, list<array{int, int}>>
     */
    private array $fallbackRouteMap = [];

    /** Whether the trie has been built from the current route set. */
    private bool $trieBuilt = false;

    /**
     * Static route hash table for O(1) lookup in non-compiled mode.
     *
     * Maps "METHOD:/path" → route index (integer, not a Route object).
     *
     * @var array<string, int>
     */
    private array $staticTable = [];

    public function __construct(
        private readonly RouteCollection $routes,
    ) {
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    public function findByName(string $name): ?Route
    {
        return $this->routes->findByName($name);
    }

    protected function performMatch(string $method, string $uri): RouteMatchResult
    {
        $this->buildTrie();

        // 0. Static route hash table — O(1) lookup for parameter-less routes.
        $key = $method . ':' . $uri;
        if (isset($this->staticTable[$key])) {
            return new RouteMatchResult($this->allRoutes[$this->staticTable[$key]], []);
        }

        $allowedMethods = [];

        // 1. Try the array-based prefix-tree (covers the majority of routes).
        // Inlined RouteTrie::splitUri() to avoid function-call overhead on every request.
        $trimmed = ltrim($uri, '/');
        $segments = $trimmed === '' ? [] : explode('/', $trimmed);

        /** @var array{static: array<string, mixed>, param: list<array<string, mixed>>, routes: list<int>} $trieArray */
        $trieArray = $this->trieArray;

        $result = RouteTrie::matchArray(
            $trieArray,
            $this->routeData,
            $method,
            $segments,
            0,
            [],
            $allowedMethods,
        );

        if ($result !== null) {
            return new RouteMatchResult(
                $this->allRoutes[$result['index']],
                $result['params'],
            );
        }

        // 2. Fallback: prefix-grouped scan for non-trie-compatible routes.
        $firstSeg = $segments[0] ?? '';
        $candidates = self::mergeFallbackGroups(
            $this->fallbackRouteMap[$firstSeg] ?? [],
            $this->fallbackRouteMap['*'] ?? [],
        );

        foreach ($candidates as [, $idx]) {
            $rd = $this->routeData[$idx];

            // Inline regex match — avoids Route object method calls on the hot path.
            if (!preg_match($rd['compiledRegex'], $uri, $matches)) {
                continue;
            }

            $params = [];
            foreach ($rd['parameterNames'] as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = $matches[$name];
                }
            }

            // URI matched — check HTTP method (hash-map O(1) lookup).
            if (isset($rd['methods'][$method])) {
                return new RouteMatchResult($this->allRoutes[$idx], $params);
            }

            // Merge allowed methods for 405 response — single array union.
            $allowedMethods += $rd['methods'];
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
     * Build the array-based trie from the current (sorted) route set.
     *
     * The RouteTrie object graph is used only during construction: once
     * {@see RouteTrie::toArray()} serialises it to a plain PHP array the
     * object nodes become unreferenced and eligible for garbage collection.
     */
    private function buildTrie(): void
    {
        if ($this->trieBuilt) {
            return;
        }

        $this->allRoutes = $this->routes->all();

        $trie = new RouteTrie();
        $routeIndexMap = [];
        $this->routeData = [];
        $this->staticTable = [];
        $this->fallbackIndices = [];

        foreach ($this->allRoutes as $index => $route) {
            $routeIndexMap[spl_object_id($route)] = $index;

            $route->compile();

            // Store only matching-critical data (no handlers/closures).
            // Methods stored as hash-map for O(1) lookup in matchArray and fallback.
            $this->routeData[$index] = [
                'methods' => \array_fill_keys($route->getMethods(), true),
                'path' => $route->getPattern(),
                'compiledRegex' => $route->getCompiledRegex(),
                'parameterNames' => $route->getParameterNames(),
            ];

            // Populate static hash table with integer indices (not Route objects).
            if (!str_contains($route->getPattern(), '{')) {
                foreach ($route->getMethods() as $m) {
                    $this->staticTable[$m . ':' . $route->getPattern()] ??= $index;
                }
            }

            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $trie->insert($route, $segments);
            } else {
                $this->fallbackIndices[] = $index;
            }
        }

        // Convert object trie → flat array trie; RouteTrie objects become
        // unreferenced after this point and are freed by the GC.
        $this->trieArray = $trie->toArray($routeIndexMap);

        $this->buildFallbackRouteMap();
        $this->trieBuilt = true;
    }

    /**
     * Build the fallback route map from fallback indices.
     */
    private function buildFallbackRouteMap(): void
    {
        $this->fallbackRouteMap = [];

        foreach ($this->fallbackIndices as $seq => $idx) {
            $key = self::fallbackPrefix($this->routeData[$idx]['path']);
            $this->fallbackRouteMap[$key][] = [$seq, $idx];
        }
    }
}
