# Waypoint

[![CI](https://github.com/ascetic-soft/Waypoint/actions/workflows/ci.yml/badge.svg)](https://github.com/ascetic-soft/Waypoint/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/ascetic-soft/Waypoint/graph/badge.svg)](https://codecov.io/gh/ascetic-soft/Waypoint)
[![PHPStan Level 9](https://img.shields.io/badge/phpstan-level%209-brightgreen)](https://phpstan.org/)
[![Latest Stable Version](https://img.shields.io/packagist/v/ascetic-soft/Waypoint)](https://packagist.org/packages/ascetic-soft/Waypoint)
[![Total Downloads](https://img.shields.io/packagist/dt/ascetic-soft/Waypoint)](https://packagist.org/packages/ascetic-soft/Waypoint)
[![PHP Version](https://img.shields.io/packagist/dependency-v/ascetic-soft/Waypoint/php)](https://packagist.org/packages/ascetic-soft/Waypoint)
[![License](https://img.shields.io/packagist/l/ascetic-soft/Waypoint)](https://packagist.org/packages/ascetic-soft/Waypoint)

A lightweight PSR-15 compatible PHP router with attribute-based routing, middleware pipeline, prefix-trie matching, and route caching.

## Features

- **PSR-15 compliant** — implements `RequestHandlerInterface`, works with any PSR-7 / PSR-15 stack
- **Attribute-based routing** — declare routes with PHP 8 `#[Route]` attributes directly on controllers
- **Fast prefix-trie matching** — static segments resolved via O(1) hash-map lookups; dynamic segments tested only when necessary
- **Middleware pipeline** — global and per-route PSR-15 middleware with FIFO execution
- **Route groups** — shared path prefixes and middleware for related routes
- **Route caching** — compile routes to a PHP file for OPcache-friendly production loading
- **Automatic dependency injection** — route parameters, `ServerRequestInterface`, and container services injected into controller methods
- **URL generation** — reverse routing from named routes and parameters
- **Route diagnostics** — detect duplicate paths, duplicate names, and shadowed routes
- **Priority-based matching** — control which route wins when patterns overlap

## Requirements

- PHP >= 8.4
- `ext-mbstring`

## Installation

```bash
composer require ascetic-soft/waypoint
```

## Quick Start

```php
use AsceticSoft\Waypoint\Router;
use Nyholm\Psr7\ServerRequest;

$router = new Router($container); // any PSR-11 container

$router->get('/hello/{name}', function (string $name) use ($responseFactory) {
    $response = $responseFactory->createResponse();
    $response->getBody()->write("Hello, {$name}!");
    return $response;
});

$request  = new ServerRequest('GET', '/hello/world');
$response = $router->handle($request);
```

## Usage

### Manual Route Registration

Register routes with the fluent API. Shortcut methods are provided for common HTTP verbs:

```php
// Full form
$router->addRoute('/users', [UserController::class, 'list'], methods: ['GET']);

// Shortcuts
$router->get('/users',          [UserController::class, 'list']);
$router->post('/users',         [UserController::class, 'create']);
$router->put('/users/{id}',     [UserController::class, 'update']);
$router->delete('/users/{id}',  [UserController::class, 'destroy']);
```

Each method accepts optional parameters:

| Parameter    | Type       | Default   | Description                          |
|-------------|------------|-----------|--------------------------------------|
| `$path`     | `string`   | —         | Route pattern (e.g. `/users/{id}`)   |
| `$handler`  | `array\|Closure` | — | `[ClassName::class, 'method']` or a closure |
| `$middleware`| `string[]`| `[]`      | Route-specific middleware class names |
| `$name`     | `string`   | `''`      | Optional route name                  |
| `$priority` | `int`      | `0`       | Matching priority (higher = first)   |

### Route Parameters

Parameters use FastRoute-style placeholders:

```php
// Basic parameter — matches any non-slash segment
$router->get('/users/{id}', [UserController::class, 'show']);

// Constrained parameter — only digits
$router->get('/users/{id:\d+}', [UserController::class, 'show']);

// Multiple parameters
$router->get('/posts/{year:\d{4}}/{slug}', [PostController::class, 'show']);
```

Parameters are automatically injected into the handler by name, with type coercion for scalar types:

```php
$router->get('/users/{id:\d+}', function (int $id) {
    // $id is automatically cast to int
});
```

### Attribute-Based Routing

Declare routes directly on controller classes using the `#[Route]` attribute:

```php
use AsceticSoft\Waypoint\Attribute\Route;

#[Route('/api/users', middleware: [AuthMiddleware::class])]
class UserController
{
    #[Route('/', methods: ['GET'], name: 'users.list')]
    public function list(): ResponseInterface { /* ... */ }

    #[Route('/{id:\d+}', methods: ['GET'], name: 'users.show')]
    public function show(int $id): ResponseInterface { /* ... */ }

    #[Route('/', methods: ['POST'], name: 'users.create')]
    public function create(ServerRequestInterface $request): ResponseInterface { /* ... */ }

    #[Route('/{id:\d+}', methods: ['PUT'], name: 'users.update')]
    public function update(int $id, ServerRequestInterface $request): ResponseInterface { /* ... */ }

    #[Route('/{id:\d+}', methods: ['DELETE'], name: 'users.delete')]
    public function delete(int $id): ResponseInterface { /* ... */ }
}
```

The class-level `#[Route]` sets a path prefix and shared middleware. Method-level attributes define concrete routes. The attribute is repeatable, so a single method can handle multiple routes.

**Loading attributes:**

```php
// Load specific controller classes
$router->loadAttributes(
    UserController::class,
    PostController::class,
);

// Or scan an entire directory
$router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');
```

#### Attribute Parameters

| Parameter    | Type       | Default   | Description                                           |
|-------------|------------|-----------|-------------------------------------------------------|
| `$path`     | `string`   | `''`      | Path pattern (prefix on class, route on method)       |
| `$methods`  | `string[]` | `['GET']` | HTTP methods (ignored on class-level)                 |
| `$name`     | `string`   | `''`      | Route name                                            |
| `$middleware`| `string[]`| `[]`      | Middleware (class-level prepended to method-level)     |
| `$priority` | `int`      | `0`       | Matching priority (higher = first)                    |

### Route Groups

Group related routes under a shared prefix and middleware:

```php
$router->group('/api', function (Router $router) {

    $router->group('/v1', function (Router $router) {
        $router->get('/users', [UserController::class, 'list']);
        // Matches: /api/v1/users
    });

    $router->group('/v2', function (Router $router) {
        $router->get('/users', [UserV2Controller::class, 'list']);
        // Matches: /api/v2/users
    });

}, middleware: [ApiAuthMiddleware::class]);
```

Groups can be nested. Prefixes and middleware accumulate from outer to inner groups.

### Middleware

Waypoint supports PSR-15 middleware at two levels:

**Global middleware** — runs for every matched route:

```php
$router->addMiddleware(CorsMiddleware::class);
$router->addMiddleware(new RateLimitMiddleware(limit: 100));
```

**Route-level middleware** — applied to specific routes:

```php
$router->get('/admin/dashboard', [AdminController::class, 'dashboard'],
    middleware: [AdminAuthMiddleware::class],
);
```

Middleware is resolved from the PSR-11 container when provided as a class name string, or used directly when provided as an instance. Execution order is FIFO: global middleware first, then route-specific middleware, then the controller handler.

### Dependency Injection

The `RouteHandler` automatically resolves controller method parameters in the following order:

1. **`ServerRequestInterface`** — the current PSR-7 request
2. **Route parameters** — matched by parameter name, with type coercion (`int`, `float`, `bool`)
3. **Container services** — resolved from the PSR-11 container by type-hint
4. **Default values** — used if available
5. **Nullable parameters** — receive `null`

```php
#[Route('/orders/{id:\d+}', methods: ['GET'])]
public function show(
    int $id,                          // route parameter (auto-cast)
    ServerRequestInterface $request,  // current request
    OrderRepository $repo,            // resolved from container
    ?LoggerInterface $logger = null,  // container or default
): ResponseInterface {
    // ...
}
```

### Route Caching

Compile routes to a PHP file for zero-overhead loading in production:

```php
// During deployment / cache warm-up
$router->compileTo(__DIR__ . '/cache/routes.php');
```

```php
// At runtime — load from cache
$cacheFile = __DIR__ . '/cache/routes.php';

$router = new Router($container);

if (file_exists($cacheFile)) {
    $router->loadCache($cacheFile);
} else {
    $router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');
    $router->compileTo($cacheFile);
}
```

The cache file is a plain PHP array that loads instantly through OPcache, bypassing all Reflection and attribute parsing.

### Route Diagnostics

Inspect registered routes and detect potential issues:

```php
use AsceticSoft\Waypoint\Diagnostic\RouteDiagnostics;

$diagnostics = new RouteDiagnostics($router->getRouteCollection());

// Print a formatted route table
$diagnostics->listRoutes();

// Detect conflicts
$report = $diagnostics->findConflicts();

if ($report->hasIssues()) {
    $diagnostics->printReport();
}
```

The diagnostic report detects:

- **Duplicate paths** — routes with identical patterns and overlapping HTTP methods
- **Duplicate names** — multiple routes sharing the same name
- **Shadowed routes** — a more general pattern registered earlier hides a more specific one

### URL Generation

Generate URLs from named routes (reverse routing). Assign names when registering routes, then use `generate()` to build paths:

```php
// Register named routes
$router->get('/users',          [UserController::class, 'list'], name: 'users.list');
$router->get('/users/{id:\d+}', [UserController::class, 'show'], name: 'users.show');

// Generate URLs
$url = $router->generate('users.show', ['id' => 42]);
// => /users/42

$url = $router->generate('users.list', query: ['page' => 2, 'limit' => 10]);
// => /users?page=2&limit=10
```

Parameters are automatically URL-encoded. Extra parameters not present in the route pattern are ignored. Missing required parameters throw `MissingParametersException`.

You can also use the `UrlGenerator` directly:

```php
use AsceticSoft\Waypoint\UrlGenerator;

$generator = new UrlGenerator($router->getRouteCollection());
$url = $generator->generate('users.show', ['id' => 42]);
```

URL generation works with cached routes — route names and patterns are preserved in the cache file.

### Exception Handling

Waypoint throws specific exceptions for routing failures:

| Exception | HTTP Code | When |
|-----------|-----------|------|
| `RouteNotFoundException` | 404 | No route pattern matches the URI |
| `MethodNotAllowedException` | 405 | URI matches but HTTP method is not allowed |
| `RouteNameNotFoundException` | — | No route with the given name (URL generation) |
| `MissingParametersException` | — | Required route parameters not provided (URL generation) |

```php
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;

try {
    $response = $router->handle($request);
} catch (RouteNotFoundException $e) {
    // Return 404 response
} catch (MethodNotAllowedException $e) {
    // Return 405 response with Allow header
    $allowed = implode(', ', $e->getAllowedMethods());
}
```

## Architecture

```
Router  (PSR-15 RequestHandlerInterface)
├── RouteCollection
│   ├── RouteTrie           — prefix-tree for fast segment matching
│   └── Route[]             — fallback linear matching for complex patterns
├── AttributeRouteLoader    — reads #[Route] attributes via Reflection
├── MiddlewarePipeline      — FIFO PSR-15 middleware execution
├── RouteHandler            — invokes controller with DI
├── UrlGenerator            — reverse routing (name + params → URL)
├── RouteCompiler           — compiles/loads route cache
└── RouteDiagnostics        — conflict detection and reporting
```

The `RouteTrie` handles the majority of routes with O(1) per-segment lookups. Routes with patterns that cannot be expressed in the trie (mixed static/parameter segments like `prefix-{name}.txt`, or cross-segment captures) automatically fall back to linear regex matching.

## Development

The project includes a `Makefile` with common tasks:

```bash
make fix       # Auto-fix code style (PHP CS Fixer)
make cs-check  # Check code style (dry-run)
make stan      # Run PHPStan static analysis (level 9)
make test      # Run PHPUnit tests
make check     # Run all checks (cs-check + stan + test)
make all       # Fix code style, then run stan and tests
```

## License

MIT
