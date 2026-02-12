<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Exception;

/**
 * Thrown when required route parameters are missing during URL generation.
 */
final class MissingParametersException extends \InvalidArgumentException
{
    /**
     * @param string       $routeName Route name for which URL generation was attempted.
     * @param list<string> $missing   Parameter names that were not provided.
     */
    public function __construct(
        string $routeName,
        private readonly array $missing,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'Missing required parameter(s) "%s" for route "%s".',
                implode('", "', $missing),
                $routeName,
            ),
            $code,
            $previous,
        );
    }

    /**
     * @return list<string>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }
}
