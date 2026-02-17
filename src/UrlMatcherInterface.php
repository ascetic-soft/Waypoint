<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;

/**
 * Pure-PHP interface for URI + HTTP-method matching.
 *
 * This interface has no PSR dependencies, allowing route matching
 * to be used independently of PSR-7/PSR-15.
 */
interface UrlMatcherInterface
{
    /**
     * Match the given HTTP method and URI against registered routes.
     *
     * @param string $method HTTP method (e.g. 'GET', 'POST').
     * @param string $uri    Request URI path (e.g. '/users/42').
     *
     * @throws RouteNotFoundException    When no route pattern matches the URI.
     * @throws MethodNotAllowedException When the URI matches but the method is not allowed.
     */
    public function match(string $method, string $uri): RouteMatchResult;
}
