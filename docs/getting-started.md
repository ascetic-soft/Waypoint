---
title: Getting Started
layout: default
nav_order: 2
---

# Getting Started
{: .no_toc }

Get up and running with Waypoint in under 5 minutes.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Table of contents</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Installation

Install Waypoint via Composer:

```bash
composer require ascetic-soft/waypoint
```

**Requirements:**
- PHP >= 8.4
- ext-mbstring

---

## Quick Start

### Step 1: Create a router

```php
use AsceticSoft\Waypoint\Router;

$router = new Router($container); // any PSR-11 container
```

### Step 2: Register routes

```php
$router->get('/hello/{name}', function (string $name) use ($responseFactory) {
    $response = $responseFactory->createResponse();
    $response->getBody()->write("Hello, {$name}!");
    return $response;
});
```

### Step 3: Handle requests

```php
use Nyholm\Psr7\ServerRequest;

$request  = new ServerRequest('GET', '/hello/world');
$response = $router->handle($request);
```

Waypoint automatically extracts `{name}` from the URL and injects it into your handler. No manual parsing required.

---

## Using Controllers

For real applications, use controller classes instead of closures:

```php
$router->get('/users',          [UserController::class, 'list']);
$router->post('/users',         [UserController::class, 'create']);
$router->get('/users/{id:\d+}', [UserController::class, 'show']);
$router->put('/users/{id:\d+}', [UserController::class, 'update']);
$router->delete('/users/{id:\d+}', [UserController::class, 'destroy']);
```

Controller methods receive route parameters automatically with type coercion:

```php
class UserController
{
    public function show(int $id, UserRepository $repo): ResponseInterface
    {
        // $id is automatically cast to int
        // $repo is resolved from the PSR-11 container
        $user = $repo->find($id);
        // ...
    }
}
```

{: .note }
Route parameters are matched by name and automatically cast to the type declared in the method signature (`int`, `float`, `bool`). Container services are resolved by type-hint.

---

## Using Attributes

Instead of manual registration, declare routes directly on your controllers:

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
}
```

Then load them:

```php
// Load specific classes
$router->loadAttributes(UserController::class, PostController::class);

// Or scan an entire directory
$router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');

// Optionally filter by filename pattern
$router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers', '*Controller.php');
```

---

## A Complete Bootstrap Example

```php
<?php
// bootstrap.php

use AsceticSoft\Waypoint\Router;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;

require_once __DIR__ . '/vendor/autoload.php';

$router = new Router($container);

// Global middleware
$router->addMiddleware(CorsMiddleware::class);

// Load routes from controller attributes
$router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');

// Or use cache in production
$cacheFile = __DIR__ . '/cache/routes.php';
if (file_exists($cacheFile)) {
    $router->loadCache($cacheFile);
} else {
    $router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');
    $router->compileTo($cacheFile);
}

// Handle request
try {
    $response = $router->handle($request);
} catch (RouteNotFoundException $e) {
    // 404 Not Found
} catch (MethodNotAllowedException $e) {
    // 405 Method Not Allowed
    $allowed = $e->getAllowedMethods();
}
```

---

## What's Next?

- [Routing]({{ '/docs/routing.html' | relative_url }}) — Route registration, parameters, groups, and attribute-based routing
- [Middleware]({{ '/docs/middleware.html' | relative_url }}) — Global and per-route PSR-15 middleware
- [Advanced]({{ '/docs/advanced.html' | relative_url }}) — Dependency injection, URL generation, caching, and diagnostics
- [Internals]({{ '/docs/internals.html' | relative_url }}) — Algorithms, data structures, and architecture diagrams
- [API Reference]({{ '/docs/api-reference.html' | relative_url }}) — Complete reference for all public classes and methods
