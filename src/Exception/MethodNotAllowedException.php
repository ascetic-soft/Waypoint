<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Exception;

/**
 * Thrown when the URI matches a route but the HTTP method is not allowed.
 */
final class MethodNotAllowedException extends \RuntimeException
{
    /**
     * @param list<string> $allowedMethods Upper-case HTTP methods that are allowed for the matched URI.
     */
    public function __construct(
        private readonly array $allowedMethods,
        string $method,
        string $uri,
        int $code = 405,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Method "%s" is not allowed for URI "%s". Allowed: %s.',
                $method,
                $uri,
                implode(', ', $allowedMethods),
            ),
            $code,
            $previous,
        );
    }

    /**
     * @return list<string>
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
