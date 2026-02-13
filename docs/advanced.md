---
title: Advanced
layout: default
nav_order: 5
---

# Advanced Features
{: .no_toc }

Dependency injection, URL generation, route caching, and diagnostics.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Table of contents</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Dependency Injection

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

{: .tip }
Parameter resolution order means you can mix route parameters, the request object, and container services in any order in your method signature.

---

## URL Generation

Generate URLs from named routes (reverse routing):

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

Parameters are automatically URL-encoded. Extra parameters not in the route pattern are ignored. Missing required parameters throw `MissingParametersException`.

### Using UrlGenerator Directly

```php
use AsceticSoft\Waypoint\UrlGenerator;

$generator = new UrlGenerator($router->getRouteCollection());
$url = $generator->generate('users.show', ['id' => 42]);
```

### Absolute URLs

```php
$router->setBaseUrl('https://example.com');
$url = $router->generate('users.show', ['id' => 42]);
// => https://example.com/users/42
```

{: .note }
URL generation works with cached routes — route names and patterns are preserved in the cache file.

---

## Route Caching

Compile routes to a PHP file for zero-overhead loading in production:

### Compiling the Cache

```php
// During deployment / cache warm-up
$router->compileTo(__DIR__ . '/cache/routes.php');
```

### Loading from Cache

```php
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

{: .important }
Remember to clear the cache file after adding or modifying routes. During development, skip caching entirely.

---

## Route Diagnostics

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

### Detected Issues

| Issue | Description |
|:------|:------------|
| **Duplicate paths** | Routes with identical patterns and overlapping HTTP methods |
| **Duplicate names** | Multiple routes sharing the same name |
| **Shadowed routes** | A more general pattern registered earlier hides a more specific one |

{: .tip }
Run diagnostics during development or in your CI pipeline to catch routing conflicts early.

---

## Exception Handling

Waypoint throws specific exceptions for routing failures:

| Exception | HTTP Code | When |
|:----------|:----------|:-----|
| `RouteNotFoundException` | 404 | No route pattern matches the URI |
| `MethodNotAllowedException` | 405 | URI matches but HTTP method is not allowed |
| `RouteNameNotFoundException` | — | No route with the given name (URL generation) |
| `MissingParametersException` | — | Required route parameters not provided |

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

---

## Architecture

Waypoint is designed around a modular architecture where each component has a single responsibility:

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
