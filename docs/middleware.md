---
title: Middleware
layout: default
nav_order: 4
---

# Middleware
{: .no_toc }

Global and per-route PSR-15 middleware pipeline.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Table of contents</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Overview

Waypoint supports PSR-15 middleware at two levels: **global** (runs for every matched route) and **route-level** (applied to specific routes).

Middleware execution order is FIFO: global middleware runs first, then route-specific middleware, then the controller handler.

---

## Global Middleware

Global middleware runs for every matched route:

```php
$router->addMiddleware(CorsMiddleware::class);
$router->addMiddleware(new RateLimitMiddleware(limit: 100));
```

When provided as a class name string, middleware is resolved from the PSR-11 container. When provided as an instance, it is used directly.

---

## Route-Level Middleware

Apply middleware to specific routes:

```php
$router->get('/admin/dashboard', [AdminController::class, 'dashboard'],
    middleware: [AdminAuthMiddleware::class],
);
```

Or via attributes:

```php
use AsceticSoft\Waypoint\Attribute\Route;

#[Route('/api/users', middleware: [AuthMiddleware::class])]
class UserController
{
    #[Route('/', methods: ['GET'])]
    public function list(): ResponseInterface { /* ... */ }
}
```

---

## Group Middleware

Apply middleware to a group of routes:

```php
$router->group('/api', function (Router $router) {
    $router->get('/users', [UserController::class, 'list']);
    $router->get('/posts', [PostController::class, 'list']);
}, middleware: [ApiAuthMiddleware::class, RateLimitMiddleware::class]);
```

Group middleware is prepended to route-level middleware. With nested groups, middleware accumulates from outer to inner groups.

---

## Execution Order

For a route with both global and route-level middleware, the execution order is:

1. **Global middleware** (in registration order)
2. **Group middleware** (outer to inner)
3. **Route-level middleware** (in declaration order)
4. **Controller handler**

```
Request → CorsMiddleware → AuthMiddleware → RouteMiddleware → Controller
                                                                    ↓
Response ← CorsMiddleware ← AuthMiddleware ← RouteMiddleware ← Controller
```

{: .note }
Each middleware can short-circuit the pipeline by returning a response without calling `$handler->handle($request)`. This is useful for authentication checks, rate limiting, etc.

---

## Writing Middleware

Middleware must implement `Psr\Http\Server\MiddlewareInterface`:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TimingMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $start = microtime(true);

        $response = $handler->handle($request);

        $duration = microtime(true) - $start;

        return $response->withHeader('X-Response-Time', round($duration * 1000) . 'ms');
    }
}
```
