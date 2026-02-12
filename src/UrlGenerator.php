<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MissingParametersException;
use AsceticSoft\Waypoint\Exception\RouteNameNotFoundException;

/**
 * Generates URLs from named routes and parameters (reverse routing).
 *
 * Uses the {@see RouteCollection} name index to look up route patterns and
 * substitutes placeholders with the provided parameter values.
 */
final readonly class UrlGenerator
{
    public function __construct(
        private RouteCollection $routes,
    ) {
    }

    /**
     * Generate a URL path for the given named route.
     *
     * @param string                            $name       The route name.
     * @param array<string,string|int|float>  $parameters Route parameter values keyed by name.
     * @param array<string,mixed>              $query      Optional query-string parameters.
     *
     * @return string The generated URL path (with optional query string).
     *
     * @throws RouteNameNotFoundException  When no route with the given name exists.
     * @throws MissingParametersException  When required route parameters are not provided.
     */
    public function generate(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->routes->findByName($name);

        if ($route === null) {
            throw new RouteNameNotFoundException($name);
        }

        // Validate that all required parameters are provided.
        $required = $route->getParameterNames();
        $missing = array_diff($required, array_keys($parameters));

        if ($missing !== []) {
            throw new MissingParametersException($name, array_values($missing));
        }

        // Build a string-keyed map for placeholder substitution.
        $stringParams = array_map(static fn ($value) => (string)$value, $parameters);

        $url = (string) preg_replace_callback(
            '#\{(\w+)(?::([^{}]*(?:\{[^}]*}[^{}]*)*))?}#',
            static fn (array $matches): string => rawurlencode($stringParams[$matches[1]] ?? ''),
            $route->getPattern(),
        );

        // Append query string if provided.
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
