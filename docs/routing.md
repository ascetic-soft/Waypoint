---
title: Routing
layout: default
nav_order: 3
---

# Routing
{: .no_toc }

Route registration, parameters, groups, and attribute-based routing.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Table of contents</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Manual Route Registration

Use `RouteRegistrar` to register routes with a fluent API. Shortcut methods are provided for common HTTP verbs:

```php
use AsceticSoft\Waypoint\RouteRegistrar;

$registrar = new RouteRegistrar();

// Full form
$registrar->addRoute('/users', [UserController::class, 'list'], methods: ['GET']);

// Shortcuts
$registrar->get('/users',          [UserController::class, 'list']);
$registrar->post('/users',         [UserController::class, 'create']);
$registrar->put('/users/{id}',     [UserController::class, 'update']);
$registrar->delete('/users/{id}',  [UserController::class, 'destroy']);

// Any other HTTP method (PATCH, OPTIONS, etc.)
$registrar->addRoute('/users/{id}', [UserController::class, 'patch'], methods: ['PATCH']);
```

Once routes are registered, pass the collection to `Router`:

```php
$router = new Router($container, $registrar->getRouteCollection());
```

### Method Parameters

| Parameter | Type | Default | Description |
|:----------|:-----|:--------|:------------|
| `$path` | `string` | — | Route pattern (e.g. `/users/{id}`) |
| `$handler` | `array\|Closure` | — | `[Class::class, 'method']` or a closure |
| `$middleware` | `string[]` | `[]` | Route-specific middleware class names |
| `$name` | `string` | `''` | Optional route name |
| `$priority` | `int` | `0` | Matching priority (higher = first) |

---

## Route Parameters

Parameters use FastRoute-style placeholders:

```php
// Basic parameter — matches any non-slash segment
$registrar->get('/users/{id}', [UserController::class, 'show']);

// Constrained parameter — only digits
$registrar->get('/users/{id:\d+}', [UserController::class, 'show']);

// Multiple parameters
$registrar->get('/posts/{year:\d{4}}/{slug}', [PostController::class, 'show']);
```

Parameters are automatically injected into the handler by name, with type coercion for scalar types:

```php
$registrar->get('/users/{id:\d+}', function (int $id) {
    // $id is automatically cast to int
});
```

{: .tip }
Use regex constraints like `\d+` to restrict parameter formats. This prevents ambiguous matches and improves performance.

---

## Attribute-Based Routing

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

The class-level `#[Route]` sets a path prefix and shared middleware. Method-level attributes define concrete routes. The attribute is **repeatable**, so a single method can handle multiple routes.

### Loading Attributes

```php
$registrar = new RouteRegistrar();

// Load specific controller classes
$registrar->loadAttributes(
    UserController::class,
    PostController::class,
);

// Or scan an entire directory
$registrar->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');

// Optionally filter by filename pattern
$registrar->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers', '*Controller.php');
```

### Attribute Parameters

| Parameter | Type | Default | Description |
|:----------|:-----|:--------|:------------|
| `$path` | `string` | `''` | Path pattern (prefix on class, route on method) |
| `$methods` | `string[]` | `['GET']` | HTTP methods (ignored on class-level) |
| `$name` | `string` | `''` | Route name |
| `$middleware` | `string[]` | `[]` | Middleware (class-level prepended to method-level) |
| `$priority` | `int` | `0` | Matching priority (higher = first) |

---

## Route Groups

Group related routes under a shared prefix and middleware:

```php
$registrar->group('/api', function (RouteRegistrar $registrar) {

    $registrar->group('/v1', function (RouteRegistrar $registrar) {
        $registrar->get('/users', [UserController::class, 'list']);
        // Matches: /api/v1/users
    });

    $registrar->group('/v2', function (RouteRegistrar $registrar) {
        $registrar->get('/users', [UserV2Controller::class, 'list']);
        // Matches: /api/v2/users
    });

}, middleware: [ApiAuthMiddleware::class]);
```

Groups can be nested. Prefixes and middleware accumulate from outer to inner groups.

---

## Priority-Based Matching

When multiple routes could match the same URL, use the `$priority` parameter to control which one wins:

```php
// Higher priority = matched first
$registrar->get('/users/{action}',  [UserController::class, 'action'], priority: 0);
$registrar->get('/users/settings',  [UserController::class, 'settings'], priority: 10);
```

Routes with higher priority values are tested before routes with lower values. Routes with the same priority are matched in registration order.
