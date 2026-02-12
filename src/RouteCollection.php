<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Stores routes and provides URI + HTTP-method matching.
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

    /**
     * Add a route to the collection.
     */
    public function add(Route $route): void
    {
        $this->routes[] = $route;
        $this->sorted = false;
    }

    /**
     * Match the given HTTP method and URI against the stored routes.
     *
     * @throws RouteNotFoundException      When no route pattern matches the URI.
     * @throws MethodNotAllowedException   When the URI matches but the method is not allowed.
     */
    public function match(string $method, string $uri): RouteMatchResult
    {
        $this->sort();

        $method = strtoupper($method);
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $route->match($uri);

            if ($params === null) {
                continue;
            }

            // URI matched — check HTTP method
            if ($route->allowsMethod($method)) {
                return new RouteMatchResult($route, $params);
            }

            // Collect allowed methods for 405 response
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
}
