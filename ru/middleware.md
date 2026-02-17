---
title: Middleware
layout: default
nav_order: 4
parent: Русский
---

# Middleware
{: .no_toc }

Глобальные и маршрутные PSR-15 middleware.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Содержание</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Обзор

Waypoint поддерживает PSR-15 middleware на двух уровнях: **глобальные** (выполняются для каждого совпавшего маршрута) и **маршрутные** (применяются к конкретным маршрутам).

Порядок выполнения FIFO: сначала глобальные middleware, затем маршрутные, затем обработчик контроллера.

{: .note }
PSR-пакеты middleware (`psr/http-server-middleware`, `psr/http-server-handler`) — опциональные зависимости. Установите их для использования middleware: `composer require psr/http-server-middleware psr/http-server-handler`.

---

## Глобальные Middleware

Глобальные middleware добавляются к `Router` и выполняются для каждого совпавшего маршрута:

```php
$router->addMiddleware(CorsMiddleware::class);
$router->addMiddleware(new RateLimitMiddleware(limit: 100));
```

При передаче в виде строки с именем класса middleware разрешается из PSR-11 контейнера. При передаче экземпляра используется напрямую.

---

## Маршрутные Middleware

Применяйте middleware к конкретным маршрутам при регистрации:

```php
$registrar->get('/admin/dashboard', [AdminController::class, 'dashboard'],
    middleware: [AdminAuthMiddleware::class],
);
```

Или через атрибуты:

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

## Middleware групп

Применяйте middleware к группе маршрутов:

```php
$registrar->group('/api', function (RouteRegistrar $registrar) {
    $registrar->get('/users', [UserController::class, 'list']);
    $registrar->get('/posts', [PostController::class, 'list']);
}, middleware: [ApiAuthMiddleware::class, RateLimitMiddleware::class]);
```

Middleware группы добавляются перед маршрутными middleware. При вложенных группах middleware накапливаются от внешних к внутренним.

---

## Порядок выполнения

Для маршрута с глобальными и маршрутными middleware порядок выполнения:

1. **Глобальные middleware** (в порядке регистрации)
2. **Middleware групп** (от внешних к внутренним)
3. **Маршрутные middleware** (в порядке объявления)
4. **Обработчик контроллера**

```
Запрос → CorsMiddleware → AuthMiddleware → RouteMiddleware → Контроллер
                                                                  ↓
Ответ  ← CorsMiddleware ← AuthMiddleware ← RouteMiddleware ← Контроллер
```

{: .note }
Каждый middleware может прервать конвейер, вернув ответ без вызова `$handler->handle($request)`. Это полезно для проверки аутентификации, ограничения частоты запросов и т.д.

---

## Написание Middleware

Middleware должен реализовывать `Psr\Http\Server\MiddlewareInterface`:

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
