---
title: Маршрутизация
layout: default
nav_order: 3
parent: Русский
---

# Маршрутизация
{: .no_toc }

Регистрация маршрутов, параметры, группы и маршрутизация через атрибуты.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Содержание</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Ручная регистрация маршрутов

Используйте `RouteRegistrar` для регистрации маршрутов с помощью fluent API. Доступны сокращённые методы для основных HTTP-глаголов:

```php
use AsceticSoft\Waypoint\RouteRegistrar;

$registrar = new RouteRegistrar();

// Полная форма
$registrar->addRoute('/users', [UserController::class, 'list'], methods: ['GET']);

// Сокращения
$registrar->get('/users',          [UserController::class, 'list']);
$registrar->post('/users',         [UserController::class, 'create']);
$registrar->put('/users/{id}',     [UserController::class, 'update']);
$registrar->delete('/users/{id}',  [UserController::class, 'destroy']);

// Любой другой HTTP-метод (PATCH, OPTIONS и т.д.)
$registrar->addRoute('/users/{id}', [UserController::class, 'patch'], methods: ['PATCH']);
```

После регистрации передайте коллекцию в `Router`:

```php
$router = new Router($container, $registrar->getRouteCollection());
```

### Параметры методов

| Параметр | Тип | По умолчанию | Описание |
|:---------|:----|:-------------|:---------|
| `$path` | `string` | — | Шаблон маршрута (например, `/users/{id}`) |
| `$handler` | `array\|Closure` | — | `[Class::class, 'method']` или замыкание |
| `$middleware` | `string[]` | `[]` | Middleware для конкретного маршрута |
| `$name` | `string` | `''` | Необязательное имя маршрута |
| `$priority` | `int` | `0` | Приоритет сопоставления (чем выше, тем раньше) |

---

## Параметры маршрутов

Параметры используют плейсхолдеры в стиле FastRoute:

```php
// Базовый параметр — соответствует любому сегменту без слеша
$registrar->get('/users/{id}', [UserController::class, 'show']);

// Ограниченный параметр — только цифры
$registrar->get('/users/{id:\d+}', [UserController::class, 'show']);

// Несколько параметров
$registrar->get('/posts/{year:\d{4}}/{slug}', [PostController::class, 'show']);
```

Параметры автоматически внедряются в обработчик по имени с приведением типов:

```php
$registrar->get('/users/{id:\d+}', function (int $id) {
    // $id автоматически приведён к int
});
```

{: .tip }
Используйте regex-ограничения вроде `\d+` для ограничения формата параметров. Это предотвращает неоднозначные совпадения и улучшает производительность.

---

## Маршрутизация через атрибуты

Объявляйте маршруты прямо на классах контроллеров с помощью атрибута `#[Route]`:

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

Атрибут `#[Route]` на уровне класса задаёт префикс пути и общие middleware. Атрибуты на методах определяют конкретные маршруты. Атрибут является **повторяемым** — один метод может обрабатывать несколько маршрутов.

### Загрузка атрибутов

```php
$registrar = new RouteRegistrar();

// Загрузить конкретные классы контроллеров
$registrar->loadAttributes(
    UserController::class,
    PostController::class,
);

// Или просканировать директорию
$registrar->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers');

// С фильтрацией по имени файла
$registrar->scanDirectory(__DIR__ . '/Controllers', 'App\\Controllers', '*Controller.php');
```

### Параметры атрибута

| Параметр | Тип | По умолчанию | Описание |
|:---------|:----|:-------------|:---------|
| `$path` | `string` | `''` | Шаблон пути (префикс на классе, маршрут на методе) |
| `$methods` | `string[]` | `['GET']` | HTTP-методы (игнорируется на уровне класса) |
| `$name` | `string` | `''` | Имя маршрута |
| `$middleware` | `string[]` | `[]` | Middleware (на уровне класса добавляются перед уровнем метода) |
| `$priority` | `int` | `0` | Приоритет сопоставления (чем выше, тем раньше) |

---

## Группы маршрутов

Объединяйте связанные маршруты под общим префиксом и middleware:

```php
$registrar->group('/api', function (RouteRegistrar $registrar) {

    $registrar->group('/v1', function (RouteRegistrar $registrar) {
        $registrar->get('/users', [UserController::class, 'list']);
        // Совпадение: /api/v1/users
    });

    $registrar->group('/v2', function (RouteRegistrar $registrar) {
        $registrar->get('/users', [UserV2Controller::class, 'list']);
        // Совпадение: /api/v2/users
    });

}, middleware: [ApiAuthMiddleware::class]);
```

Группы могут быть вложенными. Префиксы и middleware накапливаются от внешних к внутренним группам.

---

## Приоритетное сопоставление

Когда несколько маршрутов могут совпасть с одним URL, используйте параметр `$priority` для управления:

```php
// Более высокий приоритет = проверяется первым
$registrar->get('/users/{action}',  [UserController::class, 'action'], priority: 0);
$registrar->get('/users/settings',  [UserController::class, 'settings'], priority: 10);
```

Маршруты с более высоким значением приоритета проверяются раньше. Маршруты с одинаковым приоритетом сопоставляются в порядке регистрации.
