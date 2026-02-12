<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\BaseUrlNotSetException;
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
    /**
     * @param RouteCollection $routes   The route collection used for name lookups.
     * @param string          $baseUrl  Base URL with scheme and host (e.g. "https://example.com").
     *                                  Used when generating absolute URLs.
     */
    public function __construct(
        private RouteCollection $routes,
        private string $baseUrl = '',
    ) {
    }

    /**
     * Generate a URL for the given named route.
     *
     * @param string                          $name       The route name.
     * @param array<string,string|int|float>  $parameters Route parameter values keyed by name.
     * @param array<string,mixed>             $query      Optional query-string parameters.
     * @param bool                            $absolute   When true, prepend scheme and host from {@see $baseUrl}.
     *
     * @return string The generated URL path (or absolute URL) with optional query string.
     *
     * @throws RouteNameNotFoundException  When no route with the given name exists.
     * @throws MissingParametersException  When required route parameters are not provided.
     * @throws BaseUrlNotSetException      When $absolute is true but no base URL is configured.
     */
    public function generate(string $name, array $parameters = [], array $query = [], bool $absolute = false): string
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

        // Prepend base URL (scheme + host) when an absolute URL is requested.
        if ($absolute) {
            if ($this->baseUrl === '') {
                throw new BaseUrlNotSetException();
            }

            $url = rtrim($this->baseUrl, '/') . $url;
        }

        return $url;
    }
}
