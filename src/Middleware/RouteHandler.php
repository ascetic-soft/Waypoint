<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Middleware;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Final handler in the middleware pipeline — invokes the controller action.
 *
 * When a pre-computed argument plan is available (from the route cache),
 * parameters are resolved without any Reflection calls.  Otherwise the
 * handler falls back to the traditional Reflection-based resolution.
 */
final readonly class RouteHandler implements RequestHandlerInterface
{
    /**
     * @param array{0:class-string,1:string}|\Closure $handler    Controller reference or closure.
     * @param array<string,string>                     $parameters Route parameters extracted from the URI.
     * @param ContainerInterface                       $container  PSR-11 container.
     * @param list<array<string, mixed>>|null          $argPlan    Pre-computed argument resolution plan.
     */
    public function __construct(
        private array|\Closure     $handler,
        private array              $parameters,
        private ContainerInterface $container,
        private ?array             $argPlan = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->handler instanceof \Closure) {
            return $this->invokeClosure($this->handler, $request);
        }

        [$className, $methodName] = $this->handler;

        $controller = $this->container->get($className);
        if (!\is_object($controller)) {
            throw new \RuntimeException(\sprintf('Expected object from container for "%s".', $className));
        }

        if ($this->argPlan !== null) {
            // Fast path: use pre-computed argument plan — no Reflection.
            $args = $this->resolveFromPlan($this->argPlan, $request);

            $response = $controller->$methodName(...$args);
            if (!$response instanceof ResponseInterface) {
                throw new \RuntimeException('Controller action must return a ResponseInterface instance.');
            }

            return $response;
        }

        // Slow path: Reflection-based resolution.
        $reflection = new \ReflectionMethod($controller, $methodName);
        $args = $this->resolveArguments($reflection, $request);

        // Direct method call instead of $reflection->invokeArgs().
        $response = $controller->$methodName(...$args);
        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('Controller action must return a ResponseInterface instance.');
        }

        return $response;
    }

    private function invokeClosure(\Closure $closure, ServerRequestInterface $request): ResponseInterface
    {
        $reflection = new \ReflectionFunction($closure);
        $args = $this->resolveArguments($reflection, $request);

        $response = $closure(...$args);
        if (!$response instanceof ResponseInterface) {
            throw new \RuntimeException('Closure handler must return a ResponseInterface instance.');
        }

        return $response;
    }

    // ── Fast path: pre-computed plan ─────────────────────────────

    /**
     * Resolve handler arguments from a pre-computed plan (no Reflection).
     *
     * @param list<array<string, mixed>> $plan Each entry has a 'source' key and source-specific keys.
     * @return list<mixed>
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function resolveFromPlan(array $plan, ServerRequestInterface $request): array
    {
        $args = [];

        foreach ($plan as $entry) {
            /** @var array{source: string, name?: string, cast?: string|null, class?: string, value?: mixed} $entry */
            $source = $entry['source'];

            $args[] = match ($source) {
                'request'   => $request,
                'param'     => $this->coercePlanValue(
                    $this->parameters[$entry['name'] ?? ''],
                    $entry['cast'] ?? null,
                ),
                'container' => $this->container->get($entry['class'] ?? ''),
                'default'   => $entry['value'] ?? null,
                // @codeCoverageIgnoreStart
                default     => throw new \RuntimeException(\sprintf('Unknown argPlan source "%s".', $source)),
                // @codeCoverageIgnoreEnd
            };
        }

        return $args;
    }

    /**
     * Coerce a string route parameter to the target scalar type (plan path).
     */
    private function coercePlanValue(string $value, ?string $cast): mixed
    {
        return match ($cast) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    // ── Slow path: Reflection-based resolution ───────────────────

    /**
     * @return list<mixed>
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function resolveArguments(
        \ReflectionFunctionAbstract $reflection,
        ServerRequestInterface $request,
    ): array {
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // 1. Inject ServerRequestInterface
            if ($type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), ServerRequestInterface::class, true)
            ) {
                $args[] = $request;

                continue;
            }

            // 2. Inject route parameter by name
            if (\array_key_exists($name, $this->parameters)) {
                $args[] = $this->coerceValue($this->parameters[$name], $type);

                continue;
            }

            // 3. Inject service from container by type-hint
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->container->has($typeName)) {
                    $args[] = $this->container->get($typeName);

                    continue;
                }
            }

            // 4. Use default value if available
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }

            // 5. Nullable parameter — pass null
            if ($type !== null && $type->allowsNull()) {
                $args[] = null;

                continue;
            }

            throw new \RuntimeException(\sprintf(
                'Cannot resolve parameter "$%s" for handler.',
                $name,
            ));
        }

        return $args;
    }

    /**
     * Coerce a string route parameter to the declared scalar type.
     */
    private function coerceValue(string $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
