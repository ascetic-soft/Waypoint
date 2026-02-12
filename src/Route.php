<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint;

/**
 * Immutable value object representing a single route.
 *
 * Holds the route pattern, allowed HTTP methods, handler reference,
 * middleware stack, optional name, priority, and the compiled regex
 * produced by {@see compile()}.
 */
final class Route
{
    /** Compiled regular expression (populated after {@see compile()}). */
    private string $compiledRegex = '';

    /** @var list<string> Parameter names extracted from the pattern (populated after {@see compile()}). */
    private array $parameterNames = [];

    /** Whether the route has been compiled. */
    private bool $compiled = false;

    /**
     * @param string               $pattern    Route path pattern (e.g. '/users/{id:\d+}').
     * @param list<string>         $methods    Allowed HTTP methods (upper-case, e.g. ['GET', 'POST']).
     * @param array{0:class-string,1:string}|\Closure $handler Controller reference ([class, method]) or a closure.
     * @param list<string>         $middleware Middleware class-strings applied to this route.
     * @param string               $name       Optional route name for diagnostics and URL generation.
     * @param int                  $priority   Matching priority — higher values are matched first.
     */
    public function __construct(
        private readonly string $pattern,
        private readonly array $methods,
        private readonly array|\Closure $handler,
        private readonly array $middleware = [],
        private readonly string $name = '',
        private readonly int $priority = 0,
    ) {}

    /**
     * Compile the route pattern into a regular expression.
     *
     * Placeholders use FastRoute-style syntax:
     *  - `{name}`        → `(?P<name>[^/]+)`
     *  - `{name:regex}`  → `(?P<name>regex)`
     *
     * The compiled regex is anchored (`#^ … $#`) and cached on the object.
     *
     * @return self Returns $this for fluent usage.
     */
    public function compile(): self
    {
        if ($this->compiled) {
            return $this;
        }

        $this->parameterNames = [];

        $regex = preg_replace_callback(
            '#\{(\w+)(?::([^{}]*(?:\{[^}]*}[^{}]*)*))?}#',
            function (array $matches): string {
                $this->parameterNames[] = $matches[1];

                $constraint = ($matches[2] ?? '') !== '' ? $matches[2] : '[^/]+';

                return '(?P<' . $matches[1] . '>' . $constraint . ')';
            },
            $this->pattern,
        );

        $this->compiledRegex = '#^' . $regex . '$#';
        $this->compiled = true;

        return $this;
    }

    /**
     * Try to match the given URI against this route's compiled regex.
     *
     * @param string $uri The request URI path to match.
     *
     * @return array<string, string>|null Matched parameters keyed by name, or null on no match.
     */
    public function match(string $uri): ?array
    {
        $this->compile();

        if (!preg_match($this->compiledRegex, $uri, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    /**
     * Check whether the given HTTP method is allowed for this route.
     */
    public function allowsMethod(string $method): bool
    {
        return \in_array(strtoupper($method), $this->methods, true);
    }

    // ── Getters ──────────────────────────────────────────────────

    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * @return list<string>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * @return array{0:class-string,1:string}|\Closure
     */
    public function getHandler(): array|\Closure
    {
        return $this->handler;
    }

    /**
     * @return list<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getCompiledRegex(): string
    {
        $this->compile();

        return $this->compiledRegex;
    }

    /**
     * @return list<string>
     */
    public function getParameterNames(): array
    {
        $this->compile();

        return $this->parameterNames;
    }

    // ── Serialisation (for route cache) ──────────────────────────

    /**
     * Export the route to an array suitable for {@see RouteCompiler} caching.
     *
     * @return array{
     *     path: string,
     *     methods: list<string>,
     *     handler: array{0:class-string,1:string}|\Closure,
     *     middleware: list<string>,
     *     name: string,
     *     compiledRegex: string,
     *     parameterNames: list<string>,
     *     priority: int,
     * }
     */
    public function toArray(): array
    {
        $this->compile();

        return [
            'path' => $this->pattern,
            'methods' => $this->methods,
            'handler' => $this->handler,
            'middleware' => $this->middleware,
            'name' => $this->name,
            'compiledRegex' => $this->compiledRegex,
            'parameterNames' => $this->parameterNames,
            'priority' => $this->priority,
        ];
    }

    /**
     * Restore a Route from a cached array (produced by {@see toArray()}).
     *
     * The returned route is already in the compiled state, so no reflection
     * or pattern parsing is required at runtime.
     *
     * @param array{
     *     path: string,
     *     methods: list<string>,
     *     handler: array{0:class-string,1:string}|\Closure,
     *     middleware?: list<string>,
     *     name?: string,
     *     compiledRegex: string,
     *     parameterNames: list<string>,
     *     priority?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $route = new self(
            pattern: $data['path'],
            methods: $data['methods'],
            handler: $data['handler'],
            middleware: $data['middleware'] ?? [],
            name: $data['name'] ?? '',
            priority: $data['priority'] ?? 0,
        );

        $route->compiledRegex = $data['compiledRegex'];
        $route->parameterNames = $data['parameterNames'];
        $route->compiled = true;

        return $route;
    }
}
