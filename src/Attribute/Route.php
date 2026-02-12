<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Attribute;

use Attribute;

/**
 * Unified route attribute for class-level prefixes/middleware and method-level route definitions.
 *
 * On a **class**: defines a path prefix and/or class-level middleware.
 * The `methods` property is ignored when applied to a class.
 *
 * On a **method**: defines a concrete route. The final path is the class prefix
 * concatenated with the method path. Middleware is merged: class-level first, then method-level.
 *
 * The attribute is repeatable, allowing multiple routes to be mapped to the same method.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string        $path       Route path pattern (e.g. '/', '/{id:\d+}'). On a class, acts as a prefix.
     * @param list<string>  $methods    HTTP methods (e.g. ['GET', 'POST']). Ignored on class-level attributes.
     * @param string        $name       Optional route name for URL generation and diagnostics.
     * @param list<string>  $middleware Middleware class-strings. Class-level middleware is prepended to method-level.
     * @param int           $priority   Route matching priority. Higher values are matched first.
     */
    public function __construct(
        public readonly string $path = '',
        public readonly array $methods = ['GET'],
        public readonly string $name = '',
        public readonly array $middleware = [],
        public readonly int $priority = 0,
    ) {}
}
