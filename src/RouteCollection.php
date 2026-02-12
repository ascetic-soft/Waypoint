<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Stores routes and provides URI + HTTP-method matching.
 *
 * Internally uses a prefix-tree ({@see RouteTrie}) for fast segment-by-segment
 * lookups.  Routes whose patterns cannot be expressed in the trie (e.g. cross-
 * segment parameters) fall back to linear regex matching.
 *
 * Routes are matched in priority order (descending), then in registration order.
 * If the URI matches at least one route but none of the matched routes allows
 * the requested HTTP method, {@see MethodNotAllowedException} is thrown.
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** Whether the routes have been sorted by priority. */
    private bool $sorted = false;

    /** Prefix-tree root for trie-compatible routes (built lazily). */
    private ?RouteTrie $trie = null;

    /** @var list<Route> Routes that cannot be represented in the trie. */
    private array $fallbackRoutes = [];

    /** Whether the trie has been built from the current route set. */
    private bool $trieBuilt = false;

    /** @var array<string, Route>|null Lazy name-to-route index for reverse routing. */
    private ?array $nameIndex = null;

    /**
     * Add a route to the collection.
     */
    public function add(Route $route): void
    {
        $this->routes[] = $route;
        $this->sorted = false;
        $this->trieBuilt = false;
        $this->nameIndex = null;
    }

    /**
     * Match the given HTTP method and URI against the stored routes.
     *
     * @throws RouteNotFoundException      When no route pattern matches the URI.
     * @throws MethodNotAllowedException   When the URI matches but the method is not allowed.
     */
    public function match(string $method, string $uri): RouteMatchResult
    {
        $this->buildTrie();

        if ($this->trie === null) {
            throw new \LogicException('RouteTrie was not initialized after buildTrie().');
        }

        $method = strtoupper($method);
        $allowedMethods = [];

        // 1. Try the prefix-tree (covers the majority of routes).
        $segments = RouteTrie::splitUri($uri);
        $result = $this->trie->match($method, $segments, 0, [], $allowedMethods);

        if ($result !== null) {
            return new RouteMatchResult($result['route'], $result['params']);
        }

        // 2. Fallback: linear scan for non-trie-compatible routes.
        foreach ($this->fallbackRoutes as $route) {
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
     * @return list<Route>
     */
    public function all(): array
    {
        $this->sort();

        return $this->routes;
    }

    /**
     * Find a route by its name.
     *
     * @return Route|null The route with the given name, or null if not found.
     */
    public function findByName(string $name): ?Route
    {
        $this->buildNameIndex();

        return $this->nameIndex[$name] ?? null;
    }

    // ── Internals ────────────────────────────────────────────────

    /**
     * Build the prefix-tree from the current (sorted) route set.
     *
     * Each route is either inserted into the trie or placed in the fallback
     * list for linear matching.  The trie is invalidated whenever a new route
     * is added via {@see add()}.
     */
    private function buildTrie(): void
    {
        if ($this->trieBuilt) {
            return;
        }

        $this->sort();

        $this->trie = new RouteTrie();
        $this->fallbackRoutes = [];

        foreach ($this->routes as $route) {
            if (RouteTrie::isCompatible($route->getPattern())) {
                $segments = RouteTrie::parsePattern($route->getPattern());
                $this->trie->insert($route, $segments);
            } else {
                $this->fallbackRoutes[] = $route;
            }
        }

        $this->trieBuilt = true;
    }

    /**
     * Sort routes by priority (descending). Stable sort preserves registration order
     * for routes with equal priority.
     */
    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        // Stable sort: preserve insertion order for equal priorities
        $index = 0;
        $indexed = [];
        foreach ($this->routes as $route) {
            $indexed[] = [$route, $index++];
        }

        usort($indexed, static function (array $a, array $b): int {
            $priorityCmp = $b[0]->getPriority() <=> $a[0]->getPriority();

            return $priorityCmp !== 0 ? $priorityCmp : $a[1] <=> $b[1];
        });

        $this->routes = array_column($indexed, 0);
        $this->sorted = true;
    }

    /**
     * Build the name-to-route index from the current route set.
     *
     * When multiple routes share the same name, the last one registered wins.
     * The index is invalidated whenever a new route is added via {@see add()}.
     */
    private function buildNameIndex(): void
    {
        if ($this->nameIndex !== null) {
            return;
        }

        $this->nameIndex = [];

        foreach ($this->routes as $route) {
            $name = $route->getName();
            if ($name !== '') {
                $this->nameIndex[$name] = $route;
            }
        }
    }
}
