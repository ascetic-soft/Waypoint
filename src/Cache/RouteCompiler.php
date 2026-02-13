<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Cache;

use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\RouteTrie;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Compiles a {@see RouteCollection} into a PHP cache file for fast loading via opcache.
 *
 * The generated file contains the route data **and** a pre-built prefix-trie,
 * so that `load()` can restore the dispatch-ready state without sorting,
 * pattern parsing, or trie construction at runtime.
 */
final class RouteCompiler
{
    /**
     * Compile the route collection into a PHP file.
     *
     * Builds the prefix-trie at compile time and serialises it together
     * with the route definitions and the list of non-trie-compatible
     * (fallback) route indices.
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

            $data = $route->toArray();
            $data['argPlan'] = $this->buildArgPlan($route);
            $routeData[] = $data;

            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $trie->insert($route, $segments);
            } else {
                $fallbackIndices[] = $index;
            }
        }

        // Build static route hash table for O(1) dispatch.
        $staticTable = [];
        foreach ($allRoutes as $index => $route) {
            if ($route->getParameterNames() === []) {
                foreach ($route->getMethods() as $method) {
                    $key = $method . ':' . $route->getPattern();
                    // First match wins (routes are sorted by priority).
                    if (!isset($staticTable[$key])) {
                        $staticTable[$key] = $index;
                    }
                }
            }
        }

        $cacheData = [
            'routes'      => $routeData,
            'trie'        => $trie->toArray($routeIndexMap),
            'fallback'    => $fallbackIndices,
            'staticTable' => $staticTable,
        ];

        $dir = \dirname($cacheFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
        }

        $content = \sprintf("<?php return %s;\n", var_export($cacheData, true));

        // Atomic write: write to temp file then rename
        $tmpFile = \sprintf('%s.%s.tmp', $cacheFilePath, uniqid('', true));
        file_put_contents($tmpFile, $content, LOCK_EX);
        rename($tmpFile, $cacheFilePath);

        // Invalidate opcache for the old file if opcache is available
        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFilePath, true);
        }
    }

    /**
     * Load a {@see RouteCollection} from a previously compiled cache file.
     *
     * When the cache contains a pre-built trie the raw array data is kept
     * as-is — no Route or RouteTrie objects are constructed.  This reduces
     * per-request overhead to a single `include` + one `RouteCollection`.
     *
     * Legacy (flat-array) caches are still supported via a fallback path.
     *
     * @param string $cacheFilePath Absolute path to the compiled PHP file.
     */
    public function load(string $cacheFilePath): RouteCollection
    {
        if (!is_file($cacheFilePath)) {
            throw new \RuntimeException(\sprintf(
                'Route cache file "%s" does not exist.',
                $cacheFilePath,
            ));
        }

        /** @var array<string|int, mixed> $data */
        $data = include $cacheFilePath;

        // ── New format: keep raw arrays, no object reconstruction ──
        if (isset($data['trie'])) {
            return RouteCollection::fromCompiledRaw($data);
        }

        // ── Legacy format: flat array of route data ─────────────────
        /** @var list<array{path: string, methods: list<string>, handler: array{0:class-string,1:string}|\Closure, middleware: list<string>, name: string, compiledRegex: string, parameterNames: list<string>, priority?: int}> $data */
        $collection = new RouteCollection();

        foreach ($data as $item) {
            $collection->add(Route::fromArray($item));
        }

        return $collection;
    }

    /**
     * Check whether the cache file exists.
     */
    public function isFresh(string $cacheFilePath): bool
    {
        return is_file($cacheFilePath);
    }

    // ── Argument plan builder (Optimisation 2) ───────────────────

    /**
     * Analyse a handler's method signature and build a resolution plan.
     *
     * The plan describes how each parameter should be resolved at dispatch
     * time without any Reflection calls.  Returns `null` when the handler
     * is a Closure, the class is not autoloadable, or a parameter cannot
     * be unambiguously resolved at compile time.
     *
     * @return list<array{source: string, name?: string, cast?: string|null, class?: string, value?: mixed}>|null
     */
    private function buildArgPlan(Route $route): ?array
    {
        $handler = $route->getHandler();

        if ($handler instanceof \Closure) {
            return null;
        }

        [$className, $methodName] = $handler;

        try {
            $reflection = new \ReflectionMethod($className, $methodName);
        } catch (\ReflectionException) {
            return null; // Class or method not autoloadable — skip.
        }

        $routeParamNames = array_flip($route->getParameterNames());
        $plan = [];

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // 1. Inject ServerRequestInterface
            if (
                $type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), ServerRequestInterface::class, true)
            ) {
                $plan[] = ['source' => 'request'];

                continue;
            }

            // 2. Inject route parameter by name
            if (isset($routeParamNames[$name])) {
                $cast = null;
                if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                    $cast = $type->getName();
                }
                $plan[] = ['source' => 'param', 'name' => $name, 'cast' => $cast];

                continue;
            }

            // 3. Inject service from container by type-hint.
            //    If the parameter also has a default or is nullable the runtime
            //    behaviour depends on container state — bail out.
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                if ($param->isDefaultValueAvailable() || $type->allowsNull()) {
                    return null; // Ambiguous — fall back to Reflection at runtime.
                }
                $plan[] = ['source' => 'container', 'class' => $type->getName()];

                continue;
            }

            // 4. Use default value
            if ($param->isDefaultValueAvailable()) {
                $plan[] = ['source' => 'default', 'value' => $param->getDefaultValue()];

                continue;
            }

            // 5. Nullable parameter — pass null
            if ($type !== null && $type->allowsNull()) {
                $plan[] = ['source' => 'default', 'value' => null];

                continue;
            }

            // Unresolvable — cannot build a complete plan.
            return null;
        }

        return $plan;
    }
}
