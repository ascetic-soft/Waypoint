---
title: Home
layout: home
nav_order: 1
---

# Waypoint

{: .fs-9 }

Lightweight PSR-15 PHP Router with Attribute Routing, Prefix-Trie Matching & Middleware Pipeline.
{: .fs-6 .fw-300 }

[![CI](https://github.com/ascetic-soft/Waypoint/actions/workflows/ci.yml/badge.svg)](https://github.com/ascetic-soft/Waypoint/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/ascetic-soft/Waypoint/graph/badge.svg?token=cK4v0Ph4ol)](https://codecov.io/gh/ascetic-soft/Waypoint)
[![PHPStan Level 9](https://img.shields.io/badge/phpstan-level%209-brightgreen)](https://phpstan.org/)
[![Latest Stable Version](https://img.shields.io/packagist/v/ascetic-soft/waypoint)](https://packagist.org/packages/ascetic-soft/waypoint)
[![PHP Version](https://img.shields.io/packagist/dependency-v/ascetic-soft/waypoint/php)](https://packagist.org/packages/ascetic-soft/waypoint)
[![License](https://img.shields.io/packagist/l/ascetic-soft/waypoint)](https://packagist.org/packages/ascetic-soft/waypoint)

[Get Started]({{ '/docs/getting-started.html' | relative_url }}){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[Русский]({{ '/ru/' | relative_url }}){: .btn .btn-outline .fs-5 .mb-4 .mb-md-0 .mr-2 }
[View on GitHub](https://github.com/ascetic-soft/Waypoint){: .btn .fs-5 .mb-4 .mb-md-0 }

---

## What is Waypoint?

Waypoint is a modern, lightweight **PSR-15 compatible PHP router** for PHP 8.4+. It combines attribute-based routing, a fast prefix-trie matching engine, and a PSR-15 middleware pipeline into a single, elegant package.

### Key Highlights

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

---

## Quick Example

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

That's it. A few lines to get a fully working router with parameter injection. No XML, no YAML, no boilerplate.

---

## Why Waypoint?

| Feature | Waypoint | Other routers |
|:--------|:---------|:--------------|
| PSR-15 `RequestHandlerInterface` | Yes | Not always |
| PHP 8 `#[Route]` attributes | Yes | Some |
| Prefix-trie O(1) matching | Yes | Linear scan |
| Per-route middleware | Yes | Some |
| Route caching (OPcache) | Yes | Some |
| Auto dependency injection | Yes | Rare |
| Route diagnostics | Yes | No |
| Minimal dependencies | PSR packages only | Often many |
| PHPStan Level 9 | Yes | Varies |

---

## Requirements

- **PHP** >= 8.4
- **ext-mbstring**

## Installation

```bash
composer require ascetic-soft/waypoint
```

---

## Documentation

<div class="grid-container">
  <div class="grid-item">
    <h3><a href="{{ '/docs/getting-started.html' | relative_url }}">Getting Started</a></h3>
    <p>Installation, quick start, and basic usage in 5 minutes.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/docs/routing.html' | relative_url }}">Routing</a></h3>
    <p>Route registration, parameters, groups, and attribute-based routing.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/docs/middleware.html' | relative_url }}">Middleware</a></h3>
    <p>Global and per-route PSR-15 middleware pipeline.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/docs/advanced.html' | relative_url }}">Advanced</a></h3>
    <p>Dependency injection, URL generation, route caching, and diagnostics.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/docs/api-reference.html' | relative_url }}">API Reference</a></h3>
    <p>Complete reference for all public classes and methods.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/docs/internals.html' | relative_url }}">Internals</a></h3>
    <p>Algorithms, data structures, and diagrams of the internal architecture.</p>
  </div>
</div>
