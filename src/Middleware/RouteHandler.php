<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Final handler in the middleware pipeline — invokes the controller action.
 *
 * Resolves the controller from the PSR-11 container, analyses method parameters
 * via Reflection, and injects route parameters (with type coercion),
 * {@see ServerRequestInterface}, and container services by type-hint.
 */
final class RouteHandler implements RequestHandlerInterface
{
    /**
     * @param array{0:class-string,1:string}|\Closure $handler    Controller reference or closure.
     * @param array<string,string>                     $parameters Route parameters extracted from the URI.
     * @param ContainerInterface                       $container  PSR-11 container.
     */
    public function __construct(
        private readonly array|\Closure $handler,
        private readonly array $parameters,
        private readonly ContainerInterface $container,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->handler instanceof \Closure) {
            return $this->invokeClosure($this->handler, $request);
        }

        [$className, $methodName] = $this->handler;

        $controller = $this->container->get($className);
        \assert(\is_object($controller));

        $reflection = new \ReflectionMethod($controller, $methodName);

        $args = $this->resolveArguments($reflection, $request);

        $response = $reflection->invokeArgs($controller, $args);
        \assert($response instanceof ResponseInterface);

        return $response;
    }

    private function invokeClosure(\Closure $closure, ServerRequestInterface $request): ResponseInterface
    {
        $reflection = new \ReflectionFunction($closure);
        $args = $this->resolveArguments($reflection, $request);

        $response = $closure(...$args);
        \assert($response instanceof ResponseInterface);

        return $response;
    }

    /**
     * @return list<mixed>
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
            if (array_key_exists($name, $this->parameters)) {
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

            throw new \RuntimeException(sprintf(
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
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
