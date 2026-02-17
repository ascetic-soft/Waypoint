<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Cache;

use AsceticSoft\Waypoint\CompiledArrayMatcher;
use AsceticSoft\Waypoint\CompiledClassMatcher;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\RouteTrie;
use AsceticSoft\Waypoint\TrieMatcher;
use AsceticSoft\Waypoint\UrlMatcherInterface;

/**
 * Compiles a {@see RouteCollection} into a PHP cache file for fast loading via opcache.
 *
 * Delegates argument plan analysis to {@see ArgumentPlanBuilder} and PHP code
 * generation to {@see MatcherCodeGenerator}.
 */
final class RouteCompiler
{
    /**
     * Compile the route collection into a PHP file.
     *
     * Generates a named PHP class with all data as immutable class constants:
     * - `ROUTES`        — compact route data (opcache shared memory)
     * - `STATIC_TABLE`  — method:uri → index for O(1) static lookups
     * - `TRIE`          — serialised prefix trie for dynamic matching
     * - `NAME_INDEX`    — name → index for O(1) name lookups
     *
     * @param RouteCollection $routes        The collection to compile.
     * @param string          $cacheFilePath Absolute path for the generated PHP file.
     */
    public function compile(RouteCollection $routes, string $cacheFilePath): void
    {
        $allRoutes = $routes->all(); // sorted by priority (descending)

        // Build the trie at compile time and collect metadata.
        $routeIndexMap = [];
        $routeData = [];
        $trie = new RouteTrie();
        $fallbackIndices = [];

        foreach ($allRoutes as $index => $route) {
            $routeIndexMap[spl_object_id($route)] = $index;

            $route->compile();

            // Build compact route data.
            $compact = $this->buildCompactRouteData($route);

            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $trie->insert($route, $segments);
            } else {
                $fallbackIndices[] = $index;
            }

            $routeData[] = $compact;
        }

        // Build static route hash table for O(1) dispatch.
        $staticTable = $this->buildStaticTable($allRoutes);

        $trieArray = $trie->toArray($routeIndexMap);

        // Determine which static URIs are "purely static".
        $codeGenerator = new MatcherCodeGenerator();
        $staticOnlyUris = $codeGenerator->computeStaticOnlyUris(
            $trieArray,
            $staticTable,
            $allRoutes,
            $fallbackIndices,
        );

        // Generate PHP code (compiled matcher class).
        $content = $codeGenerator->generate(
            $routeData,
            $trieArray,
            $fallbackIndices,
            $staticTable,
            $staticOnlyUris,
        );

        $this->writeAtomically($cacheFilePath, $content);
    }

    /**
     * Load a matcher from a previously compiled cache file.
     *
     * Supports three formats:
     * 1. Compiled PHP matcher (named class) — Phase 3 format → {@see CompiledClassMatcher}
     * 2. Array with trie — Phase 2 format → {@see CompiledArrayMatcher}
     * 3. Flat route array — legacy format → {@see TrieMatcher}
     *
     * @param string $cacheFilePath Absolute path to the compiled PHP file.
     */
    public function load(string $cacheFilePath): UrlMatcherInterface
    {
        if (!is_file($cacheFilePath)) {
            throw new \RuntimeException(\sprintf(
                'Route cache file "%s" does not exist.',
                $cacheFilePath,
            ));
        }

        $data = include $cacheFilePath;

        // ── Phase 3: compiled PHP matcher (named class) ──
        if ($data instanceof CompiledMatcherInterface) {
            return new CompiledClassMatcher($data);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException('Expected array from cache file.');
        }

        // ── Phase 2: array with trie ──
        if (isset($data['trie'])) {
            /** @var array{routes: list<array<string, mixed>>, trie: list<mixed>, fallback: list<int>, staticTable: array<string, int>} $data */
            return new CompiledArrayMatcher($data);
        }

        // ── Legacy: flat array of route data ──
        $collection = new RouteCollection();

        foreach ($data as $item) {
            /** @var array{path: string, methods: list<string>, handler: array{0:class-string,1:string}|\Closure, middleware: list<string>, name: string, compiledRegex: string, parameterNames: list<string>, priority?: int, argPlan?: list<array<string, mixed>>} $item */
            $collection->add(Route::fromArray($item));
        }

        return new TrieMatcher($collection);
    }

    /**
     * Check whether the cache file exists.
     */
    public function isFresh(string $cacheFilePath): bool
    {
        return is_file($cacheFilePath);
    }

    // ── Private helpers ─────────────────────────────────────────

    /**
     * Build compact route data array for a single route.
     *
     * @return array<string, mixed>
     */
    private function buildCompactRouteData(Route $route): array
    {
        $compact = [
            'h' => $route->getHandler(),
            'M' => \array_fill_keys($route->getMethods(), true),
            'p' => $route->getPattern(),
        ];

        if ($route->getMiddleware() !== []) {
            $compact['w'] = $route->getMiddleware();
        }

        $argPlan = ArgumentPlanBuilder::build($route);
        if ($argPlan !== null) {
            $compact['a'] = $argPlan;
        }

        if ($route->getName() !== '') {
            $compact['n'] = $route->getName();
        }

        if ($route->getPriority() !== 0) {
            $compact['P'] = $route->getPriority();
        }

        // Include regex/param data for routes that have parameters.
        if ($route->getParameterNames() !== []) {
            $compact['r'] = $route->getCompiledRegex();
            $compact['N'] = $route->getParameterNames();
        }

        return $compact;
    }

    /**
     * Build static route hash table for O(1) dispatch.
     *
     * @param list<Route> $allRoutes
     *
     * @return array<string, int>
     */
    private function buildStaticTable(array $allRoutes): array
    {
        $staticTable = [];
        foreach ($allRoutes as $index => $route) {
            if ($route->getParameterNames() === []) {
                foreach ($route->getMethods() as $method) {
                    $key = $method . ':' . $route->getPattern();
                    if (!isset($staticTable[$key])) {
                        $staticTable[$key] = $index;
                    }
                }
            }
        }

        return $staticTable;
    }

    /**
     * Write content to a file atomically (temp file + rename).
     */
    private function writeAtomically(string $cacheFilePath, string $content): void
    {
        $dir = \dirname($cacheFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            // @codeCoverageIgnoreStart
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
            // @codeCoverageIgnoreEnd
        }

        $tmpFile = \sprintf('%s.%s.tmp', $cacheFilePath, uniqid('', true));
        file_put_contents($tmpFile, $content, LOCK_EX);
        rename($tmpFile, $cacheFilePath);

        // @codeCoverageIgnoreStart
        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFilePath, true);
        }
        // @codeCoverageIgnoreEnd
    }
}
