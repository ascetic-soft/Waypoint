<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Exception;

/**
 * Thrown when no route is found with the given name during URL generation.
 */
final class RouteNameNotFoundException extends \InvalidArgumentException
{
    public function __construct(string $name, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            \sprintf('No route found with name "%s".', $name),
            $code,
            $previous,
        );
    }
}
