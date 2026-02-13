---
title: Быстрый старт
layout: default
nav_order: 2
parent: Русский
---

# Быстрый старт
{: .no_toc }

Начните работу с Waypoint за 5 минут.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Содержание</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Установка

Установите Waypoint через Composer:

```bash
composer require ascetic-soft/waypoint
```

**Требования:**
- PHP >= 8.4
- ext-mbstring

---

## Быстрый старт

### Шаг 1: Создайте роутер

```php
use AsceticSoft\Waypoint\Router;

$router = new Router($container); // любой PSR-11 контейнер
```

### Шаг 2: Зарегистрируйте маршруты

```php
$router->get('/hello/{name}', function (string $name) use ($responseFactory) {
    $response = $responseFactory->createResponse();
    $response->getBody()->write("Hello, {$name}!");
    return $response;
});
```

### Шаг 3: Обработайте запрос

```php
use Nyholm\Psr7\ServerRequest;

$request  = new ServerRequest('GET', '/hello/world');
$response = $router->handle($request);
```

Waypoint автоматически извлекает `{name}` из URL и внедряет его в обработчик. Ручной разбор не нужен.

---

## Использование контроллеров

Для реальных приложений используйте классы контроллеров вместо замыканий:

```php
$router->get('/users',             [UserController::class, 'list']);
$router->post('/users',            [UserController::class, 'create']);
$router->get('/users/{id:\d+}',    [UserController::class, 'show']);
$router->put('/users/{id:\d+}',    [UserController::class, 'update']);
$router->delete('/users/{id:\d+}', [UserController::class, 'destroy']);
```

Методы контроллера получают параметры маршрута автоматически с приведением типов:

```php
class UserController
{
    public function show(int $id, UserRepository $repo): ResponseInterface
    {
        // $id автоматически приведён к int
        // $repo разрешён из PSR-11 контейнера
        $user = $repo->find($id);
        // ...
    }
}
```

{: .note }
Параметры маршрута сопоставляются по имени и автоматически приводятся к типу, объявленному в сигнатуре метода (`int`, `float`, `bool`). Сервисы контейнера разрешаются по type-hint.

---

## Использование атрибутов

Вместо ручной регистрации объявляйте маршруты прямо на контроллерах:

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

Затем загрузите их:

```php
// Загрузить конкретные классы
$router->loadAttributes(UserController::class, PostController::class);

// Или просканировать директорию
$router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');
```

---

## Полный пример запуска

```php
<?php
// bootstrap.php

use AsceticSoft\Waypoint\Router;
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;

require_once __DIR__ . '/vendor/autoload.php';

$router = new Router($container);

// Глобальные middleware
$router->addMiddleware(CorsMiddleware::class);

// Загрузка маршрутов из атрибутов контроллеров
$router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');

// Или использование кэша в продакшене
$cacheFile = __DIR__ . '/cache/routes.php';
if (file_exists($cacheFile)) {
    $router->loadCache($cacheFile);
} else {
    $router->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');
    $router->compileTo($cacheFile);
}

// Обработка запроса
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

## Что дальше?

- [Маршрутизация]({{ '/ru/routing.html' | relative_url }}) — Регистрация маршрутов, параметры, группы и атрибуты
- [Middleware]({{ '/ru/middleware.html' | relative_url }}) — Глобальные и маршрутные PSR-15 middleware
- [Продвинутое]({{ '/ru/advanced.html' | relative_url }}) — Внедрение зависимостей, генерация URL, кэширование и диагностика
- [Справочник API]({{ '/ru/api-reference.html' | relative_url }}) — Полный справочник по всем публичным классам и методам
