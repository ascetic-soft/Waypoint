<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Exception;

/**
 * Thrown when an absolute URL is requested but no base URL has been configured.
 */
final class BaseUrlNotSetException extends \LogicException
{
    public function __construct(int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            'Cannot generate an absolute URL: base URL is not set. '
            . 'Provide a base URL via the UrlGenerator constructor or Router::setBaseUrl().',
            $code,
            $previous,
        );
    }
}
