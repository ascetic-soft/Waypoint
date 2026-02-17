<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

/**
 * Stores routes and provides access by name or as a sorted list.
 *
 * Routes are sorted by priority (descending), then by registration order.
 * This class does NOT perform URI matching — use {@see TrieMatcher} or other matchers for that.
 */
final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** Whether the routes have been sorted by priority. */
    private bool $sorted = false;

    /** @var array<string, Route>|null Lazy name-to-route index for reverse routing. */
    private ?array $nameIndex = null;

    /**
     * Add a route to the collection.
     */
    public function add(Route $route): void
    {
        $this->routes[] = $route;
        $this->sorted = false;
        $this->nameIndex = null;
    }

    /**
     * Get all routes, sorted by priority (descending).
     *
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
     * Sort routes by priority (descending). Stable sort preserves registration order.
     */
    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

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
