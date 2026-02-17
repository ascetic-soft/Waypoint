<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Phase 1 matcher: runtime prefix-tree (trie) built lazily from a {@see RouteCollection}.
 *
 * Routes whose patterns cannot be expressed in the trie (e.g. cross-segment
 * parameters) fall back to linear regex matching with prefix-based grouping.
 */
final class TrieMatcher extends AbstractUrlMatcher
{
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
}
