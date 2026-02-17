<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Phase 2 matcher: pre-compiled array data with no object reconstruction.
 *
 * Works directly with serialised route arrays and a pre-built trie structure.
 * Routes are hydrated lazily into a {@see RouteCollection} only when needed
 * (e.g. for URL generation or diagnostics).
 *
 * @phpstan-import-type RouteDataArray from Route
 */
final class CompiledArrayMatcher extends AbstractUrlMatcher
{
    private RouteCollection $routes;

    /** Whether the RouteCollection has been hydrated from compiled data. */
    private bool $hydrated = false;

    /** @var array<int, Route> Lazily hydrated route cache keyed by compiled route index. */
    private array $routeCache = [];

    /**
     * Lazy fallback route map for compiled data.
     *
     * @var array<string, list<array{int, int}>>|null
     */
    private ?array $compiledFallbackMap = null;

    /**
     * Lightweight name-to-index map for compiled data.
     *
     * @var array<string, int>|null
     */
    private ?array $compiledNameMap = null;

    /**
     * @param array{routes: list<array<string, mixed>>, trie: list<mixed>, fallback: list<int>, staticTable: array<string, int>} $compiledData
     */
    public function __construct(
        private readonly array $compiledData,
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
        $this->buildCompiledNameMap();

        $idx = $this->compiledNameMap[$name] ?? null;

        return $idx !== null ? $this->getCachedRoute($idx) : null;
    }

    protected function performMatch(string $method, string $uri): RouteMatchResult
    {
        // 1. Static route hash table — O(1) lookup.
        $key = $method . ':' . $uri;
        if (isset($this->compiledData['staticTable'][$key])) {
            $idx = $this->compiledData['staticTable'][$key];

            return new RouteMatchResult(
                $this->getCachedRoute($idx),
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
                $this->getCachedRoute($result['index']),
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
     * Hydrate Route objects into the RouteCollection from compiled data.
     */
    private function hydrateIfNeeded(): void
    {
        if ($this->hydrated) {
            return;
        }

        $count = \count($this->compiledData['routes']);
        for ($i = 0; $i < $count; $i++) {
            $this->routes->add($this->getCachedRoute($i));
        }

        $this->hydrated = true;
    }

    /**
     * Get a cached Route instance by index from compiled route arrays.
     */
    private function getCachedRoute(int $idx): Route
    {
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
     * Get the fallback route map, building it lazily.
     *
     * @return array<string, list<array{int, int}>>
     */
    private function getCompiledFallbackMap(): array
    {
        if ($this->compiledFallbackMap !== null) {
            return $this->compiledFallbackMap;
        }

        $this->compiledFallbackMap = [];

        foreach ($this->compiledData['fallback'] as $seq => $idx) {
            /** @var string $pattern */
            $pattern = $this->compiledData['routes'][$idx]['path'];
            $key = self::fallbackPrefix($pattern);
            $this->compiledFallbackMap[$key][] = [$seq, $idx];
        }

        return $this->compiledFallbackMap;
    }

}
