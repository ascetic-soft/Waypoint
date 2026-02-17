<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Cache;

use AsceticSoft\Waypoint\Route;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Analyses controller method signatures and builds pre-computed argument plans.
 *
 * The plan describes how each parameter should be resolved at dispatch time
 * without any Reflection calls, enabling zero-Reflection argument injection
 * for compiled routes.
 */
final class ArgumentPlanBuilder
{
    /**
     * Build an argument resolution plan for the given route's handler.
     *
     * Returns `null` when the handler is a Closure, the class is not
     * autoloadable, or a parameter cannot be unambiguously resolved at
     * compile time.
     *
     * @return list<array{source: string, name?: string, cast?: string|null, class?: string, value?: mixed}>|null
     */
    public static function build(Route $route): ?array
    {
        $handler = $route->getHandler();

        if ($handler instanceof \Closure) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        [$className, $methodName] = $handler;

        try {
            $reflection = new \ReflectionMethod($className, $methodName);
        } catch (\ReflectionException) {
            return null; // Class or method not autoloadable — skip.
        }

        $routeParamNames = array_flip($route->getParameterNames());
        $plan = [];

        foreach ($reflection->getParameters() as $param) {
            $entry = self::resolveParameter($param, $routeParamNames);

            if ($entry === false) {
                // Unresolvable — cannot build a complete plan.
                return null;
            }

            $plan[] = $entry;
        }

        return $plan;
    }

    /**
     * Resolve a single method parameter into a plan entry.
     *
     * @param \ReflectionParameter    $param          The parameter to resolve.
     * @param array<string, int>      $routeParamNames Flipped parameter name → index map.
     *
     * @return array{source: string, name?: string, cast?: string|null, class?: string, value?: mixed}|false
     *         An entry array on success, or false if the parameter cannot be resolved.
     */
    private static function resolveParameter(\ReflectionParameter $param, array $routeParamNames): array|false
    {
        $name = $param->getName();
        $type = $param->getType();

        // 1. Inject ServerRequestInterface
        if (
            $type instanceof \ReflectionNamedType
            && !$type->isBuiltin()
            && is_a($type->getName(), ServerRequestInterface::class, true)
        ) {
            return ['source' => 'request'];
        }

        // 2. Inject route parameter by name
        if (isset($routeParamNames[$name])) {
            $cast = null;
            if ($type instanceof \ReflectionNamedType && $type->isBuiltin()) {
                $cast = $type->getName();
            }

            return ['source' => 'param', 'name' => $name, 'cast' => $cast];
        }

        // 3. Inject service from container by type-hint.
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            if ($param->isDefaultValueAvailable() || $type->allowsNull()) {
                return false; // Ambiguous — fall back to Reflection at runtime.
            }

            return ['source' => 'container', 'class' => $type->getName()];
        }

        // 4. Use default value
        if ($param->isDefaultValueAvailable()) {
            return ['source' => 'default', 'value' => $param->getDefaultValue()];
        }

        // 5. Nullable parameter — pass null
        if ($type !== null && $type->allowsNull()) {
            return ['source' => 'default', 'value' => null];
        }

        // Unresolvable
        return false;
    }
}
