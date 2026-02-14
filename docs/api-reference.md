---
title: API Reference
layout: default
nav_order: 6
---

# API Reference
{: .no_toc }

Complete reference for all public classes and methods.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Table of contents</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Router

The main entry point. Implements `Psr\Http\Server\RequestHandlerInterface`.

```php
use AsceticSoft\Waypoint\Router;

$router = new Router(ContainerInterface $container);
```

### Route Registration

| Method | Description |
|:-------|:------------|
| `addRoute(string $path, array\|Closure $handler, array $methods, array $middleware, string $name, int $priority): self` | Register a route for specific HTTP methods |
| `get(string $path, array\|Closure $handler, ...): self` | Register a GET route |
| `post(string $path, array\|Closure $handler, ...): self` | Register a POST route |
| `put(string $path, array\|Closure $handler, ...): self` | Register a PUT route |
| `delete(string $path, array\|Closure $handler, ...): self` | Register a DELETE route |
| `group(string $prefix, Closure $callback, array $middleware): self` | Group routes under a shared prefix |

### Middleware

| Method | Description |
|:-------|:------------|
| `addMiddleware(string\|MiddlewareInterface $middleware): void` | Add global middleware |

### Attribute Loading

| Method | Description |
|:-------|:------------|
| `loadAttributes(string ...$classes): self` | Load routes from `#[Route]` attributes |
| `scanDirectory(string $directory, string $namespace, string $filePattern = '*.php'): self` | Auto-discover routes by scanning a directory |

### Request Handling

| Method | Description |
|:-------|:------------|
| `handle(ServerRequestInterface $request): ResponseInterface` | PSR-15 request handler |

### URL Generation

| Method | Description |
|:-------|:------------|
| `generate(string $name, array $params = [], array $query = [], bool $absolute = false): string` | Generate URL from a named route |
| `setBaseUrl(string $baseUrl): self` | Set base URL for absolute URL generation |
| `getUrlGenerator(): UrlGenerator` | Get the URL generator instance (lazily created) |

### Caching

| Method | Description |
|:-------|:------------|
| `compileTo(string $file): void` | Compile routes to a cache file |
| `loadCache(string $file): void` | Load routes from a compiled cache file |

### Inspection

| Method | Description |
|:-------|:------------|
| `getRouteCollection(): RouteCollection` | Access the route collection |

---

## RouteCollection

Stores and matches routes. Uses `RouteTrie` for fast matching.

| Method | Description |
|:-------|:------------|
| `add(Route $route): void` | Add a route to the collection |
| `match(string $method, string $uri): RouteMatchResult` | Match HTTP method and URI to a route |
| `all(): Route[]` | Get all routes sorted by priority |
| `findByName(string $name): ?Route` | Find a route by its name |

---

## Route

Immutable value object representing a single route.

| Method | Description |
|:-------|:------------|
| `compile(): string` | Compile pattern to regex |
| `match(string $uri): ?array` | Match URI against route, returns parameters or null |
| `allowsMethod(string $method): bool` | Check if HTTP method is allowed |
| `toArray(): array` | Serialize for caching |
| `fromArray(array $data): static` | Deserialize from cache |

---

## UrlGenerator

Reverse routing — generates URLs from route names and parameters.

```php
use AsceticSoft\Waypoint\UrlGenerator;

$generator = new UrlGenerator(RouteCollection $routes, string $baseUrl = '');
```

| Method | Description |
|:-------|:------------|
| `generate(string $name, array $params = [], array $query = [], bool $absolute = false): string` | Generate a URL from a route name |

---

## AttributeRouteLoader

Reads `#[Route]` attributes from controller classes.

```php
use AsceticSoft\Waypoint\Loader\AttributeRouteLoader;

$loader = new AttributeRouteLoader();
```

| Method | Description |
|:-------|:------------|
| `loadFromClass(string $className): Route[]` | Load routes from a single class |
| `loadFromDirectory(string $directory, string $namespace, string $filePattern = '*.php'): Route[]` | Scan directory for controllers with `#[Route]` |

---

## RouteDiagnostics

Route conflict detection and reporting.

```php
use AsceticSoft\Waypoint\Diagnostic\RouteDiagnostics;

$diagnostics = new RouteDiagnostics(RouteCollection $routes);
```

| Method | Description |
|:-------|:------------|
| `listRoutes(): void` | Print formatted route table |
| `findConflicts(): DiagnosticReport` | Detect conflicts and return a report |
| `printReport(): void` | Print full diagnostic report |

---

## RouteCompiler

Compiles and loads route cache files.

```php
use AsceticSoft\Waypoint\Cache\RouteCompiler;
```

| Method | Description |
|:-------|:------------|
| `compile(RouteCollection $routes, string $file): void` | Compile routes to a PHP cache file (Phase 3 compiled matcher) |

---

## Exceptions

| Exception | When |
|:----------|:-----|
| `RouteNotFoundException` | No route matches the URI (HTTP 404) |
| `MethodNotAllowedException` | URI matches but method is not allowed (HTTP 405) |
| `RouteNameNotFoundException` | Route name not found during URL generation |
| `MissingParametersException` | Required route parameters missing during URL generation |
| `BaseUrlNotSetException` | Absolute URL requested but base URL not configured |

`MethodNotAllowedException` provides `getAllowedMethods(): array` to retrieve the list of allowed HTTP methods for the matched URI.
