<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

/**
 * Prefix-tree (trie) node for fast segment-by-segment route matching.
 *
 * Static path segments are resolved via hash-map lookup (O(1) per segment).
 * Dynamic segments are tested with a per-segment regex only when necessary.
 *
 * Routes whose parameter regex can match '/' (cross-segment captures) or
 * that contain mixed static/parameter segments (e.g. `prefix-{name}.txt`)
 * are NOT trie-compatible and must fall back to linear matching.
 *
 * @internal Used by {@see RouteCollection}.
 *
 * @phpstan-import-type RouteDataArray from Route
 */
final class RouteTrie
{
    // ── Compact trie-array tuple indices (for opcache-friendly serialisation) ──

    /** Trie node tuple index: static children (`array<string, list<mixed>>`). */
    public const IDX_STATIC = 0;

    /** Trie node tuple index: param children (`list<list<mixed>>`). */
    public const IDX_PARAM = 1;

    /** Trie node tuple index: route indices (`list<int>`). */
    public const IDX_ROUTES = 2;

    /** Param-child tuple index: parameter name (`string`). */
    public const PARAM_NAME = 0;

    /** Param-child tuple index: anchored regex (`string`). */
    public const PARAM_REGEX = 1;

    /** Param-child tuple index: child trie node (`list<mixed>`). */
    public const PARAM_NODE = 2;

    /** @var array<string, self> Static children keyed by literal segment value. */
    private array $staticChildren = [];

    /**
     * Dynamic (parameterised) children, tried in insertion order.
     *
     * Insertion order equals priority-desc order because the trie is built
     * from a pre-sorted route list (see {@see RouteCollection::buildTrie()}).
     *
     * @var list<array{node: self, paramName: string, pattern: string, regex: string}>
     */
    private array $paramChildren = [];

    /** @var list<Route> Routes terminating at this node (pre-sorted by priority desc, then insertion order). */
    private array $routes = [];

    // ── Building ─────────────────────────────────────────────────

    /**
     * Insert a route into the trie.
     *
     * Routes MUST be inserted in priority-desc / insertion-order so that
     * leaf-node route lists and paramChildren ordering reflect global priority.
     *
     * @param Route $route    The route to insert.
     * @param list<array{type: 'static'|'param', value: string, paramName?: string, pattern?: string}> $segments
     *        Parsed segments produced by {@see parsePattern()}.
     * @param int   $depth    Current depth in the segments array (0-based).
     */
    public function insert(Route $route, array $segments, int $depth = 0): void
    {
        if ($depth === \count($segments)) {
            // Leaf — store route (order already correct, no re-sort needed).
            $this->routes[] = $route;

            return;
        }

        $seg = $segments[$depth];

        if ($seg['type'] === 'static') {
            $key = $seg['value'];

            if (!isset($this->staticChildren[$key])) {
                $this->staticChildren[$key] = new self();
            }

            $this->staticChildren[$key]->insert($route, $segments, $depth + 1);

            return;
        }

        // Dynamic segment — reuse an existing child with the same (paramName, pattern).
        if (!isset($seg['paramName'], $seg['pattern'])) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('Dynamic segment is missing required keys "paramName" and/or "pattern".');
            // @codeCoverageIgnoreEnd
        }

        $paramName = $seg['paramName'];
        $pattern = $seg['pattern'];

        foreach ($this->paramChildren as $child) {
            if ($child['paramName'] === $paramName && $child['pattern'] === $pattern) {
                $child['node']->insert($route, $segments, $depth + 1);

                return;
            }
        }

        // No matching child yet — create one.
        $node = new self();
        $this->paramChildren[] = [
            'node' => $node,
            'paramName' => $paramName,
            'pattern' => $pattern,
            'regex' => \sprintf('#^%s$#', $pattern),
        ];
        $node->insert($route, $segments, $depth + 1);
    }

    // ── Matching ─────────────────────────────────────────────────

    /**
     * Find a matching route for the given HTTP method and URI segments.
     *
     * The search favours static children (O(1) lookup) over dynamic ones.
     * If a static or dynamic branch leads to a dead-end the algorithm
     * backtracks and tries the next candidate.
     *
     * @param string               $method         Upper-case HTTP method.
     * @param list<string>         $segments        URI path segments.
     * @param int                  $depth           Current depth in the segment list.
     * @param array<string,string> $params          Parameters collected so far.
     * @param array<string,true>   $allowedMethods  Collected allowed methods (for 405), passed by reference.
     *
     * @return array{route: Route, params: array<string,string>}|null
     */
    public function match(
        string $method,
        array $segments,
        int $depth = 0,
        array $params = [],
        array &$allowedMethods = [],
    ): ?array {
        // All segments consumed — check routes at this node.
        if ($depth === \count($segments)) {
            foreach ($this->routes as $route) {
                if ($route->allowsMethod($method)) {
                    return ['route' => $route, 'params' => $params];
                }

                // URI matched but method did not — collect for 405.
                foreach ($route->getMethods() as $m) {
                    $allowedMethods[$m] = true;
                }
            }

            return null;
        }

        $segment = $segments[$depth];

        // 1. Static child — O(1) hash-map lookup.
        if (isset($this->staticChildren[$segment])) {
            $result = $this->staticChildren[$segment]->match(
                $method,
                $segments,
                $depth + 1,
                $params,
                $allowedMethods,
            );

            if ($result !== null) {
                return $result;
            }
        }

        // 2. Dynamic children — tried in priority-desc order (by construction).
        foreach ($this->paramChildren as $child) {
            if (preg_match($child['regex'], $segment)) {
                $childParams = $params;
                $childParams[$child['paramName']] = $segment;

                $result = $child['node']->match(
                    $method,
                    $segments,
                    $depth + 1,
                    $childParams,
                    $allowedMethods,
                );

                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    // ── Serialisation ────────────────────────────────────────────

    /**
     * Serialize the trie node (and all descendants) to a compact PHP array.
     *
     * The output uses integer-indexed tuples (packed arrays) for maximum
     * opcache efficiency.  String keys are avoided so that PHP can use its
     * packed array representation, which is both smaller in shared memory
     * and faster to access at runtime.
     *
     * Node format:  `[IDX_STATIC, IDX_PARAM, IDX_ROUTES]`
     * Param child:  `[PARAM_NAME, PARAM_REGEX, PARAM_NODE]`
     *
     * The compile-time-only `pattern` field is intentionally omitted from
     * the serialised output — only the anchored `regex` is needed at runtime.
     *
     * @param array<int, int> $routeIndexMap Map of `spl_object_id(Route)` → integer index.
     *
     * @return array{0: array<string, list<mixed>>, 1: list<list<mixed>>, 2: list<int>}
     */
    public function toArray(array $routeIndexMap): array
    {
        $staticChildren = array_map(static fn ($child) => $child->toArray($routeIndexMap), $this->staticChildren);

        $paramChildren = [];
        foreach ($this->paramChildren as $child) {
            $paramChildren[] = [
                $child['paramName'],
                $child['regex'],
                $child['node']->toArray($routeIndexMap),
            ];
        }

        $routeIndices = [];
        foreach ($this->routes as $route) {
            $routeIndices[] = $routeIndexMap[spl_object_id($route)];
        }

        return [
            $staticChildren,
            $paramChildren,
            $routeIndices,
        ];
    }

    /**
     * Restore a trie node (and all descendants) from a compact cached array.
     *
     * Accepts the integer-indexed tuple format produced by {@see toArray()}.
     *
     * @param array{0: array<string, list<mixed>>, 1: list<list<mixed>>, 2: list<int>} $data
     * @param list<Route> $routeObjects Flat list of Route objects, indexed by cache position.
     */
    public static function fromArray(array $data, array $routeObjects): self
    {
        $node = new self();

        /** @var array<string, list<mixed>> $staticChildren */
        $staticChildren = $data[self::IDX_STATIC];

        foreach ($staticChildren as $key => $childData) {
            /** @var array{0: array<string, list<mixed>>, 1: list<list<mixed>>, 2: list<int>} $childData */
            $node->staticChildren[$key] = self::fromArray($childData, $routeObjects);
        }

        /** @var list<list<mixed>> $paramEntries */
        $paramEntries = $data[self::IDX_PARAM];

        foreach ($paramEntries as $childData) {
            /** @var string $paramName */
            $paramName = $childData[self::PARAM_NAME];
            /** @var string $regex */
            $regex = $childData[self::PARAM_REGEX];
            /** @var array{0: array<string, list<mixed>>, 1: list<list<mixed>>, 2: list<int>} $childNode */
            $childNode = $childData[self::PARAM_NODE];
            $node->paramChildren[] = [
                'paramName' => $paramName,
                'pattern'   => '',
                'regex'     => $regex,
                'node'      => self::fromArray($childNode, $routeObjects),
            ];
        }

        foreach ($data[self::IDX_ROUTES] as $index) {
            $node->routes[] = $routeObjects[$index];
        }

        return $node;
    }

    // ── Array-based matching (no object reconstruction) ─────────

    /**
     * Match against a trie stored as a compact PHP array (no RouteTrie objects).
     *
     * Uses an iterative depth-first search with an explicit stack instead of
     * recursion.  This eliminates per-level function-call overhead and the
     * associated parameter-array copies on the call stack.
     *
     * The trie uses integer-indexed tuples (see {@see IDX_STATIC}, etc.)
     * for maximum opcache efficiency and minimal per-lookup overhead.
     *
     * Route data entries MUST store methods as a hash-map (`array<string, true>`)
     * under the `'methods'` key for O(1) method checks via `isset()`.
     *
     * @param list<mixed>                $trieNode        Compact trie node: [static, param, routes]
     * @param list<array<string, mixed>> $routeData       Flat array of route data from cache
     * @param string                     $method          Upper-case HTTP method
     * @param list<string>               $segments        URI segments
     * @param int                        $depth           Current depth in segments
     * @param array<string,string>       $params          Collected parameters
     * @param array<string,true>         &$allowedMethods For 405 response
     *
     * @return array{index: int, params: array<string,string>}|null
     */
    public static function matchArray(
        array $trieNode,
        array $routeData,
        string $method,
        array $segments,
        int $depth,
        array $params,
        array &$allowedMethods,
    ): ?array {
        $segCount = \count($segments);

        // Explicit DFS stack: each entry is [node, depth, params].
        $stack = [[$trieNode, $depth, $params]];

        while ($stack !== []) {
            $entry = \array_pop($stack);
            /** @var array{0: array<string, list<mixed>>, 1: list<list<mixed>>, 2: list<int>} $node */
            $node = $entry[0];
            $d = $entry[1];
            /** @var array<string, string> $p */
            $p = $entry[2];

            // All segments consumed — check routes at this node.
            if ($d === $segCount) {
                /** @var list<int> $routeIndices */
                $routeIndices = $node[self::IDX_ROUTES];

                foreach ($routeIndices as $routeIndex) {
                    /** @var array<string, true> $methods */
                    $methods = $routeData[$routeIndex]['methods'];

                    if (isset($methods[$method])) {
                        return ['index' => $routeIndex, 'params' => $p];
                    }

                    // Merge allowed methods for 405 — single array union.
                    $allowedMethods += $methods;
                }

                continue;
            }

            $seg = $segments[$d];
            $nextDepth = $d + 1;

            // Push dynamic children in REVERSE order so that the first
            // (highest-priority) child is popped from the stack first.
            /** @var list<list<mixed>> $paramChildren */
            $paramChildren = $node[self::IDX_PARAM];

            for ($i = \count($paramChildren) - 1; $i >= 0; $i--) {
                $child = $paramChildren[$i];
                /** @var string $childRegex */
                $childRegex = $child[self::PARAM_REGEX];
                /** @var string $childName */
                $childName = $child[self::PARAM_NAME];

                if (preg_match($childRegex, $seg)) {
                    $cp = $p;
                    $cp[$childName] = $seg;
                    $stack[] = [$child[self::PARAM_NODE], $nextDepth, $cp];
                }
            }

            // Push the static child AFTER dynamic ones so it is popped
            // FIRST — static matches always have higher priority.
            /** @var array<string, list<mixed>> $staticChildren */
            $staticChildren = $node[self::IDX_STATIC];

            if (isset($staticChildren[$seg])) {
                $stack[] = [$staticChildren[$seg], $nextDepth, $p];
            }
        }

        return null;
    }

    // ── Segment helpers (static) ─────────────────────────────────

    /**
     * Parse a route pattern into typed segments.
     *
     * Each segment is either:
     *  - `['type' => 'static', 'value' => '...']`
     *  - `['type' => 'param',  'value' => '...', 'paramName' => '...', 'pattern' => '...']`
     *
     * @return list<array{type: 'static'|'param', value: string, paramName?: string, pattern?: string}>
     */
    public static function parsePattern(string $pattern): array
    {
        $path = ltrim($pattern, '/');

        if ($path === '') {
            return [];
        }

        $parts = explode('/', $path);
        $segments = [];

        foreach ($parts as $part) {
            // Whole segment is a single {name} or {name:regex}.
            if (preg_match('#^\{(\w+)(?::([^{}]*(?:\{[^}]*}[^{}]*)*))?}$#', $part, $m)) {
                $segments[] = [
                    'type' => 'param',
                    'value' => $part,
                    'paramName' => $m[1],
                    'pattern' => ($m[2] ?? '') !== '' ? $m[2] : '[^/]+',
                ];
            } else {
                $segments[] = [
                    'type' => 'static',
                    'value' => $part,
                ];
            }
        }

        return $segments;
    }

    /**
     * Split a request URI into segments consistent with {@see parsePattern()}.
     *
     * Leading slash is stripped; trailing slash produces an extra empty segment
     * so that `/users/` does NOT match a route registered as `/users`.
     *
     * @return list<string>
     */
    public static function splitUri(string $uri): array
    {
        $path = ltrim($uri, '/');

        return $path === '' ? [] : explode('/', $path);
    }

    /**
     * Determine whether a route pattern can be represented in the trie.
     *
     * A pattern is NOT compatible when:
     *  1. A segment mixes static text with a parameter (e.g. `prefix-{name}.txt`).
     *  2. A parameter regex can match `/` (cross-segment capture).
     */
    public static function isCompatible(string $pattern): bool
    {
        $path = ltrim($pattern, '/');

        if ($path === '') {
            return true;
        }

        foreach (explode('/', $path) as $part) {
            // Empty segment (trailing slash) or pure static — always OK.
            if ($part === '' || !str_contains($part, '{')) {
                continue;
            }

            // Must be a single, complete parameter placeholder.
            if (!preg_match('#^\{(\w+)(?::([^{}]*(?:\{[^}]*}[^{}]*)*))?}$#', $part, $m)) {
                return false;
            }

            // Parameter regex must not match '/'.
            $regex = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';

            $fullRegex = \sprintf('#^%s$#', $regex);

            // Validate the regex first — avoid @ error suppression overhead.
            if (preg_match($fullRegex, '') === false) {
                // @codeCoverageIgnoreStart
                // Invalid regex — treat as incompatible.
                return false;
                // @codeCoverageIgnoreEnd
            }

            if (preg_match($fullRegex, '/') === 1) {
                return false;
            }
        }

        return true;
    }
}
