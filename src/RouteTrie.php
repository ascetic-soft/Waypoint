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
 */
final class RouteTrie
{
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
            throw new \LogicException('Dynamic segment is missing required keys "paramName" and/or "pattern".');
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
            'regex' => sprintf('#^%s$#', $pattern),
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

            if (@preg_match(sprintf('#^%s$#', $regex), '/') === 1) {
                return false;
            }
        }

        return true;
    }
}
