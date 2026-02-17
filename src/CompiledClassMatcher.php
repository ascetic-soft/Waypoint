<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Cache\CompiledMatcherInterface;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Phase 3 matcher: compiled PHP matcher class (opcache-optimized).
 *
 * Delegates matching to a generated {@see CompiledMatcherInterface} instance
 * that uses PHP `match` expressions (hash-table lookups) and a data-driven
 * trie traversal for maximum performance.
 *
 * Routes are hydrated lazily into a {@see RouteCollection} only when needed
 * (e.g. for URL generation or diagnostics).
 */
final class CompiledClassMatcher extends AbstractUrlMatcher
{
    private RouteCollection $routes;

    /** Whether the RouteCollection has been hydrated from compiled data. */
    private bool $hydrated = false;

    /** @var array<int, Route> Lazily hydrated route cache keyed by compiled route index. */
    private array $routeCache = [];

    /**
     * Lazy fallback route map for the compiled matcher.
     *
     * @var array<string, list<array{int, int}>>|null
     */
    private ?array $matcherFallbackMap = null;

    public function __construct(
        private readonly CompiledMatcherInterface $compiledMatcher,
    ) {
        $this->routes = new RouteCollection();
    }

    public function getRouteCollection(): RouteCollection
    {
        $this->hydrateIfNeeded();

        return $this->routes;
    }

    public function findByName(string $name): ?Route
    {
        $idx = $this->compiledMatcher->findByName($name);

        if ($idx === null) {
            return null;
        }

        return Route::fromCompactArray($this->compiledMatcher->getRoute($idx));
    }

    protected function performMatch(string $method, string $uri): RouteMatchResult
    {
        // 1. Static route hash table — O(1) match expression.
        $result = $this->compiledMatcher->matchStatic($method, $uri);

        if ($result !== null) {
            [$idx, $params] = $result;

            return new RouteMatchResult(
                $this->getCachedRoute($idx),
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
                $this->getCachedRoute($idx),
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
            $route = $this->getCachedRoute($idx);
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
     * Hydrate Route objects into the RouteCollection from compiled matcher.
     */
    private function hydrateIfNeeded(): void
    {
        if ($this->hydrated) {
            return;
        }

        $count = $this->compiledMatcher->getRouteCount();
        for ($i = 0; $i < $count; $i++) {
            $this->routes->add($this->getCachedRoute($i));
        }

        $this->hydrated = true;
    }

    /**
     * Get a cached Route instance by index from compact compiled matcher data.
     */
    private function getCachedRoute(int $idx): Route
    {
        return $this->routeCache[$idx] ??= Route::fromCompactArray($this->compiledMatcher->getRoute($idx));
    }

    // ── Fallback prefix grouping ────────────────────────────────

    /**
     * Get the fallback route map, building it lazily.
     *
     * @return array<string, list<array{int, int}>>
     */
    private function getMatcherFallbackMap(): array
    {
        if ($this->matcherFallbackMap !== null) {
            return $this->matcherFallbackMap;
        }

        $this->matcherFallbackMap = [];

        foreach ($this->compiledMatcher->getFallbackIndices() as $seq => $idx) {
            $routeData = $this->compiledMatcher->getRoute($idx);
            $key = self::fallbackPrefix($routeData['p']);
            $this->matcherFallbackMap[$key][] = [$seq, $idx];
        }

        return $this->matcherFallbackMap;
    }
}
