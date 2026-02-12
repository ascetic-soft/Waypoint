<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Exception;

/**
 * Thrown when no route matches the requested URI.
 */
final class RouteNotFoundException extends \RuntimeException
{
    public function __construct(string $uri, int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('No route found for URI "%s".', $uri),
            $code,
            $previous,
        );
    }
}
