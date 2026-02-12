<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

/**
 * Immutable value object holding the result of a successful route match.
 */
final class RouteMatchResult
{
    /**
     * @param Route                $route      The matched route.
     * @param array<string,string> $parameters Extracted URI parameters keyed by name.
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $parameters,
    ) {
    }
}
