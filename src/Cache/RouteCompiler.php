<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Cache;

use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\RouteTrie;
use AsceticSoft\Waypoint\UrlMatcher;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Compiles a {@see RouteCollection} into a PHP cache file for fast loading via opcache.
 *
 * Generates a **named PHP class** with `match` expressions and route data stored
 * as immutable class constants.  PHP opcache caches the class definition and its
 * constants in shared memory — the route table is loaded once per worker process,
 * not allocated on every request.  Match expressions compile to hash-table
 * lookups (O(1)).
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
            $compact = [
                'h' => $route->getHandler(),
                'M' => $route->getMethods(),
                'p' => $route->getPattern(),
            ];

            if ($route->getMiddleware() !== []) {
                $compact['w'] = $route->getMiddleware();
            }

            $argPlan = $this->buildArgPlan($route);
            if ($argPlan !== null) {
                $compact['a'] = $argPlan;
            }

            if ($route->getName() !== '') {
                $compact['n'] = $route->getName();
            }

            if ($route->getPriority() !== 0) {
                $compact['P'] = $route->getPriority();
            }

            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $trie->insert($route, $segments);
            } else {
                $fallbackIndices[] = $index;
            }

            // Include regex/param data for routes that have parameters.
            if ($route->getParameterNames() !== []) {
                $compact['r'] = $route->getCompiledRegex();
                $compact['N'] = $route->getParameterNames();
            }

            $routeData[] = $compact;
        }

        // Build static route hash table for O(1) dispatch.
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

        $trieArray = $trie->toArray($routeIndexMap);

        // Determine which static URIs are "purely static" — no dynamic
        // (parameterised) trie route and no fallback route can match them.
        // These URIs qualify for an early 405 response without traversing
        // the dynamic trie or scanning fallback routes.
        $staticOnlyUris = $this->computeStaticOnlyUris(
            $trieArray,
            $staticTable,
            $allRoutes,
            $fallbackIndices,
        );

        // Generate PHP code (compiled matcher class).
        $content = $this->generateCompiledMatcher(
            $routeData,
            $trieArray,
            $fallbackIndices,
            $staticTable,
            $staticOnlyUris,
        );

        $dir = \dirname($cacheFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            // @codeCoverageIgnoreStart
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
            // @codeCoverageIgnoreEnd
        }

        // Atomic write: write to temp file then rename
        $tmpFile = \sprintf('%s.%s.tmp', $cacheFilePath, uniqid('', true));
        file_put_contents($tmpFile, $content, LOCK_EX);
        rename($tmpFile, $cacheFilePath);

        // @codeCoverageIgnoreStart
        // Invalidate opcache for the old file if opcache is available
        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFilePath, true);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Load a {@see UrlMatcher} from a previously compiled cache file.
     *
     * Supports three formats:
     * 1. Compiled PHP matcher (named class) — Phase 3 format
     * 2. Array with trie — Phase 2 format
     * 3. Flat route array — legacy format
     *
     * @param string $cacheFilePath Absolute path to the compiled PHP file.
     */
    public function load(string $cacheFilePath): UrlMatcher
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
            return UrlMatcher::fromCompiledMatcher($data);
        }

        \assert(\is_array($data));

        // ── Phase 2: array with trie ──
        if (isset($data['trie'])) {
            /** @var array{routes: list<array<string, mixed>>, trie: array<string, mixed>, fallback: list<int>, staticTable: array<string, int>} $data */
            return UrlMatcher::fromCompiledRaw($data);
        }

        // ── Legacy: flat array of route data ──
        $collection = new RouteCollection();

        foreach ($data as $item) {
            /** @var array{path: string, methods: list<string>, handler: array{0:class-string,1:string}|\Closure, middleware: list<string>, name: string, compiledRegex: string, parameterNames: list<string>, priority?: int, argPlan?: list<array<string, mixed>>} $item */
            $collection->add(Route::fromArray($item));
        }

        return new UrlMatcher($collection);
    }

    /**
     * Check whether the cache file exists.
     */
    public function isFresh(string $cacheFilePath): bool
    {
        return is_file($cacheFilePath);
    }

    // ── Code generation ────────────────────────────────────────

    /**
     * Generate the compiled PHP matcher class as a string.
     *
     * Uses a **named class** so that PHP opcache can cache:
     * - the class definition (bytecode) in shared memory,
     * - class constants (ROUTES, TRIE, STATIC_TABLE, etc.) as immutable arrays
     *   in opcache shared memory — zero per-request allocation.
     *
     * A `class_exists` guard ensures the class is defined only once per process,
     * so subsequent `include` calls (same worker) skip class definition entirely
     * and just return `new ClassName()` — near-zero per-request cost.
     *
     * @param list<array<string, mixed>> $routeData       Compact route data for each route.
     * @param array<string, mixed>       $trieArray       Serialised trie (from RouteTrie::toArray).
     * @param list<int>                  $fallbackIndices Non-trie-compatible route indices.
     * @param array<string, int>         $staticTable     method:uri → route index.
     * @param array<string, true>        $staticOnlyUris  URIs that can only match via the static table.
     */
    private function generateCompiledMatcher(
        array $routeData,
        array $trieArray,
        array $fallbackIndices,
        array $staticTable,
        array $staticOnlyUris,
    ): string {
        // Content-based hash → unique class name per route set (avoids stale
        // definitions when the cache is regenerated in the same process).
        $hash = substr(hash('xxh128', serialize($routeData)), 0, 16);
        $className = "WaypointCompiledMatcher_$hash";

        // Build name index (name → route index).
        $nameIndex = [];
        foreach ($routeData as $index => $data) {
            $name = $data['n'] ?? '';
            if (\is_string($name) && $name !== '' && !isset($nameIndex[$name])) {
                $nameIndex[$name] = $index;
            }
        }

        // Build URI → allowed methods map (for 405 on static routes).
        $uriMethods = [];
        foreach ($staticTable as $key => $index) {
            [$method, $uri] = explode(':', $key, 2);
            $uriMethods[$uri][] = $method;
        }

        // ── Emit PHP ──
        $code = "<?php\n\n";
        $code .= "// Auto-generated by Waypoint RouteCompiler. Do not edit.\n\n";
        $code .= "if (!\\class_exists('{$className}', false)) {\n\n";
        $code .= "class $className implements \\AsceticSoft\\Waypoint\\Cache\\CompiledMatcherInterface\n{\n";

        // ── Immutable class constants (opcache shared memory) ──
        $code .= "    /** @var list<array<string, mixed>> Compact route data. */\n";
        $code .= "    private const ROUTES = [\n";
        foreach ($routeData as $index => $data) {
            $code .= \sprintf("        %d => %s,\n", $index, $this->exportValue($data));
        }
        $code .= "    ];\n\n";

        $code .= \sprintf("    /** @var array<string, int> method:uri → route index. */\n");
        $code .= \sprintf("    private const STATIC_TABLE = %s;\n\n", $this->exportValue($staticTable));

        $code .= \sprintf("    /** @var array<string, list<string>> uri → allowed methods. */\n");
        $code .= \sprintf("    private const URI_METHODS = %s;\n\n", $this->exportValue($uriMethods));

        $code .= "    /** @var array<string, mixed> Serialised prefix trie. */\n";
        $code .= \sprintf("    private const TRIE = %s;\n\n", $this->exportValue($trieArray));

        $code .= \sprintf("    /** @var list<int> Non-trie-compatible route indices. */\n");
        $code .= \sprintf("    private const FALLBACK = %s;\n\n", $this->exportValue($fallbackIndices));

        $code .= \sprintf("    /** @var array<string, int> name → route index. */\n");
        $code .= \sprintf("    private const NAME_INDEX = %s;\n\n", $this->exportValue($nameIndex));

        $code .= \sprintf("    /** @var array<string, true> URIs that can only match via the static table (no dynamic/fallback overlap). */\n");
        $code .= \sprintf("    private const STATIC_ONLY_URIS = %s;\n\n", $this->exportValue($staticOnlyUris));

        // ── matchStatic — O(1) hash table lookup via const array ──
        $code .= "    public function matchStatic(string \$method, string \$uri): ?array\n";
        $code .= "    {\n";
        $code .= "        \$key = \$method . ':' . \$uri;\n";
        $code .= "        return isset(self::STATIC_TABLE[\$key]) ? [self::STATIC_TABLE[\$key], []] : null;\n";
        $code .= "    }\n\n";

        // ── staticMethods — for 405 responses ──
        $code .= "    public function staticMethods(string \$uri): array\n";
        $code .= "    {\n";
        $code .= "        return self::URI_METHODS[\$uri] ?? [];\n";
        $code .= "    }\n\n";

        // ── isStaticOnly — early 405 guard ──
        $code .= "    public function isStaticOnly(string \$uri): bool\n";
        $code .= "    {\n";
        $code .= "        return isset(self::STATIC_ONLY_URIS[\$uri]);\n";
        $code .= "    }\n\n";

        // ── matchDynamic — data-driven trie traversal ──
        $code .= "    public function matchDynamic(string \$method, string \$uri, array &\$allowedMethods = []): ?array\n";
        $code .= "    {\n";
        $code .= "        \$path = ltrim(\$uri, '/');\n";
        $code .= "        \$segments = \$path === '' ? [] : explode('/', \$path);\n";
        $code .= "        return \$this->walk(self::TRIE, \$method, \$segments, \\count(\$segments), 0, [], \$allowedMethods);\n";
        $code .= "    }\n\n";

        // ── walk — generic trie traversal (single method replaces N generated methods) ──
        $code .= "    /** @param array<string, mixed> \$node */\n";
        $code .= "    private function walk(array \$node, string \$m, array \$s, int \$n, int \$d, array \$p, array &\$am): ?array\n";
        $code .= "    {\n";
        $code .= "        if (\$d === \$n) {\n";
        $code .= "            foreach (\$node['routes'] ?? [] as \$idx) {\n";
        $code .= "                if (\\in_array(\$m, self::ROUTES[\$idx]['M'], true)) {\n";
        $code .= "                    return [\$idx, \$p];\n";
        $code .= "                }\n";
        $code .= "                foreach (self::ROUTES[\$idx]['M'] as \$method) {\n";
        $code .= "                    \$am[\$method] = true;\n";
        $code .= "                }\n";
        $code .= "            }\n";
        $code .= "            return null;\n";
        $code .= "        }\n";
        $code .= "        \$seg = \$s[\$d];\n";
        // Static child — hash lookup
        $code .= "        if (isset(\$node['static'][\$seg])) {\n";
        $code .= "            \$r = \$this->walk(\$node['static'][\$seg], \$m, \$s, \$n, \$d + 1, \$p, \$am);\n";
        $code .= "            if (\$r !== null) { return \$r; }\n";
        $code .= "        }\n";
        // Param children — regex
        $code .= "        foreach (\$node['param'] ?? [] as \$child) {\n";
        $code .= "            if (preg_match(\$child['regex'], \$seg)) {\n";
        $code .= "                \$r = \$this->walk(\$child['node'], \$m, \$s, \$n, \$d + 1, \$p + [\$child['paramName'] => \$seg], \$am);\n";
        $code .= "                if (\$r !== null) { return \$r; }\n";
        $code .= "            }\n";
        $code .= "        }\n";
        $code .= "        return null;\n";
        $code .= "    }\n\n";

        // ── getRoute — simple const access ──
        $code .= "    public function getRoute(int \$idx): array\n";
        $code .= "    {\n";
        $code .= "        return self::ROUTES[\$idx];\n";
        $code .= "    }\n\n";

        // ── getRouteCount ──
        $code .= "    public function getRouteCount(): int\n";
        $code .= "    {\n";
        $code .= \sprintf("        return %d;\n", \count($routeData));
        $code .= "    }\n\n";

        // ── getFallbackIndices ──
        $code .= "    public function getFallbackIndices(): array\n";
        $code .= "    {\n";
        $code .= "        return self::FALLBACK;\n";
        $code .= "    }\n\n";

        // ── findByName — O(1) const array lookup ──
        $code .= "    public function findByName(string \$name): ?int\n";
        $code .= "    {\n";
        $code .= "        return self::NAME_INDEX[\$name] ?? null;\n";
        $code .= "    }\n";

        $code .= "}\n\n"; // end class
        $code .= "}\n\n"; // end if (!class_exists)
        $code .= "return new $className();\n";

        return $code;
    }

    // ── Static-only URI analysis ─────────────────────────────

    /**
     * Determine which static route URIs can only be matched via the static table.
     *
     * A static URI qualifies as "static-only" when:
     *  1. No parameterised trie child exists at any depth along the URI's path
     *     (otherwise a dynamic route could match the same URI).
     *  2. No fallback (non-trie-compatible) route regex matches the URI
     *     (otherwise a fallback route could match it).
     *
     * For static-only URIs, the router can throw 405 immediately when the
     * method is wrong — without traversing the dynamic trie or scanning
     * fallback routes.
     *
     * @param array<string, mixed> $trieArray       Serialised trie.
     * @param array<string, int>   $staticTable     method:uri → route index.
     * @param list<Route>          $allRoutes       All routes (compiled).
     * @param list<int>            $fallbackIndices Non-trie-compatible route indices.
     *
     * @return array<string, true>  Set of purely-static URIs.
     */
    private function computeStaticOnlyUris(
        array $trieArray,
        array $staticTable,
        array $allRoutes,
        array $fallbackIndices,
    ): array {
        // Collect unique URIs from the static table.
        $staticUris = [];
        foreach ($staticTable as $key => $idx) {
            [, $uri] = explode(':', $key, 2);
            $staticUris[$uri] = true;
        }

        $result = [];

        foreach ($staticUris as $uri => $_) {
            // Check 1: no param children along the trie path for this URI.
            if (!$this->hasNoParamChildrenAlongPath($trieArray, $uri)) {
                continue;
            }

            // Check 2: no fallback route matches this URI.
            $matchesFallback = false;
            foreach ($fallbackIndices as $idx) {
                if ($allRoutes[$idx]->match($uri) !== null) {
                    $matchesFallback = true;

                    break;
                }
            }

            if (!$matchesFallback) {
                $result[$uri] = true;
            }
        }

        return $result;
    }

    /**
     * Walk the serialised trie along a URI's segments and check that no
     * parameterised children exist at any intermediate depth.
     *
     * Param children at the leaf node (the final node for this URI) are NOT
     * checked — they belong to deeper URIs and do not affect matching of
     * the current URI.
     *
     * @param array<string, mixed> $trieNode Root trie node.
     * @param string               $uri      Static URI (e.g. "/about").
     */
    private function hasNoParamChildrenAlongPath(array $trieNode, string $uri): bool
    {
        $path = ltrim($uri, '/');
        $segments = $path === '' ? [] : explode('/', $path);

        /** @var array{static: array<string, mixed>, param: list<mixed>, routes: list<int>} $node */
        $node = $trieNode;

        foreach ($segments as $segment) {
            // Param children at this depth mean a dynamic route could match
            // the current segment — the URI is not purely static.
            if ($node['param'] !== []) {
                return false;
            }

            /** @var array<string, array{static: array<string, mixed>, param: list<mixed>, routes: list<int>}> $staticChildren */
            $staticChildren = $node['static'];

            if (!isset($staticChildren[$segment])) {
                // @codeCoverageIgnoreStart
                return false; // Should not happen for a valid static route.
                // @codeCoverageIgnoreEnd
            }

            $node = $staticChildren[$segment];
        }

        return true;
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Export a PHP value as compact code (short array syntax, minimal whitespace).
     */
    private function exportValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_string($value)) {
            return var_export($value, true);
        }

        if (\is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $isList = array_is_list($value);
            $parts = [];

            foreach ($value as $k => $v) {
                if ($isList) {
                    $parts[] = $this->exportValue($v);
                } else {
                    $parts[] = $this->exportValue($k) . ' => ' . $this->exportValue($v);
                }
            }

            return '[' . implode(', ', $parts) . ']';
        }

        // @codeCoverageIgnoreStart
        return var_export($value, true);
        // @codeCoverageIgnoreEnd
    }

    // ── Argument plan builder ──────────────────────────────────

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
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
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
