<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;

/**
 * Base class for URL matchers providing shared HEAD→GET fallback
 * (RFC 7231 section 4.3.2) and utility methods.
 *
 * Concrete subclasses implement {@see performMatch()} for their
 * specific matching strategy (trie, compiled array, compiled class).
 */
abstract class AbstractUrlMatcher implements UrlMatcherInterface
{
    /**
     * Match the given HTTP method and URI against the stored routes.
     *
     * Implements automatic HEAD→GET fallback per RFC 7231 section 4.3.2:
     * if no route explicitly handles HEAD but a GET route exists for the
     * same URI, the GET route is returned.
     *
     * @throws Exception\RouteNotFoundException    When no route pattern matches the URI.
     * @throws MethodNotAllowedException           When the URI matches but the method is not allowed.
     */
    public function match(string $method, string $uri): RouteMatchResult
    {
        $method = strtoupper($method);

        try {
            return $this->performMatch($method, $uri);
        } catch (MethodNotAllowedException $e) {
            // RFC 7231 §4.3.2: HEAD must be handled identically to GET
            // (without the response body).  When no explicit HEAD route
            // exists but a GET route matches, fall back to GET.
            if ($method === 'HEAD' && \in_array('GET', $e->getAllowedMethods(), true)) {
                return $this->performMatch('GET', $uri);
            }

            throw $e;
        }
    }

    /**
     * Strategy-specific matching implementation.
     *
     * The $method parameter is already upper-case.
     *
     * @throws Exception\RouteNotFoundException    When no route pattern matches the URI.
     * @throws MethodNotAllowedException           When the URI matches but the method is not allowed.
     */
    abstract protected function performMatch(string $method, string $uri): RouteMatchResult;

    /**
     * Get the underlying route collection, hydrating from compiled data if needed.
     */
    abstract public function getRouteCollection(): RouteCollection;

    /**
     * Find a route by its name.
     *
     * @return Route|null The route with the given name, or null if not found.
     */
    abstract public function findByName(string $name): ?Route;

    // ── Shared utility methods ─────────────────────────────────

    /**
     * Extract the first static URI segment from a route pattern.
     *
     * Returns '*' when the first segment contains a parameter placeholder.
     */
    protected static function fallbackPrefix(string $pattern): string
    {
        $path = ltrim($pattern, '/');

        if ($path === '') {
            // @codeCoverageIgnoreStart
            return '';
            // @codeCoverageIgnoreEnd
        }

        $slash = strpos($path, '/');
        $segment = $slash !== false ? substr($path, 0, $slash) : $path;

        return str_contains($segment, '{') ? '*' : $segment;
    }

    /**
     * Extract the first segment from a request URI.
     */
    protected static function uriFirstSegment(string $uri): string
    {
        $path = ltrim($uri, '/');

        if ($path === '') {
            return '';
        }

        $slash = strpos($path, '/');

        return $slash !== false ? substr($path, 0, $slash) : $path;
    }

    /**
     * Merge two sequence-ordered fallback groups into a single list
     * maintaining global priority order.
     *
     * @template T
     *
     * @param list<array{int, T}> $a
     * @param list<array{int, T}> $b
     *
     * @return list<array{int, T}>
     */
    protected static function mergeFallbackGroups(array $a, array $b): array
    {
        if ($b === []) {
            return $a;
        }

        if ($a === []) {
            return $b;
        }

        $result = [];
        $i = 0;
        $j = 0;
        $na = \count($a);
        $nb = \count($b);

        while ($i < $na && $j < $nb) {
            if ($a[$i][0] <= $b[$j][0]) {
                $result[] = $a[$i++];
            } else {
                $result[] = $b[$j++];
            }
        }

        while ($i < $na) {
            $result[] = $a[$i++];
        }

        while ($j < $nb) {
            $result[] = $b[$j++];
        }

        return $result;
    }
}
