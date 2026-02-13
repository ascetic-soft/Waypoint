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

Register routes with the fluent API. Shortcut methods are provided for common HTTP verbs:

```php
// Full form
$router->addRoute('/users', [UserController::class, 'list'], methods: ['GET']);

// Shortcuts
$router->get('/users',          [UserController::class, 'list']);
$router->post('/users',         [UserController::class, 'create']);
$router->put('/users/{id}',     [UserController::class, 'update']);
$router->delete('/users/{id}',  [UserController::class, 'destroy']);
$router->patch('/users/{id}',   [UserController::class, 'patch']);
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
// Load specific controller classes
$router->loadAttributes(
    UserController::class,
    PostController::class,
);

// Or scan an entire directory
$router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');
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

---

## Priority-Based Matching

When multiple routes could match the same URL, use the `$priority` parameter to control which one wins:

```php
// Higher priority = matched first
$router->get('/users/{action}',  [UserController::class, 'action'], priority: 0);
$router->get('/users/settings',  [UserController::class, 'settings'], priority: 10);
```

Routes with higher priority values are tested before routes with lower values. Routes with the same priority are matched in registration order.
