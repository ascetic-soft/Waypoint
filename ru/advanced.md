---
title: Продвинутое
layout: default
nav_order: 5
parent: Русский
---

# Продвинутые возможности
{: .no_toc }

Внедрение зависимостей, генерация URL, кэширование маршрутов и диагностика.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Содержание</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Внедрение зависимостей

`RouteHandler` автоматически разрешает параметры метода контроллера в следующем порядке:

1. **`ServerRequestInterface`** — текущий PSR-7 запрос
2. **Параметры маршрута** — сопоставляются по имени с приведением типов (`int`, `float`, `bool`)
3. **Сервисы контейнера** — разрешаются из PSR-11 контейнера по type-hint
4. **Значения по умолчанию** — используются, если доступны
5. **Nullable-параметры** — получают `null`

```php
#[Route('/orders/{id:\d+}', methods: ['GET'])]
public function show(
    int $id,                          // параметр маршрута (авто-приведение)
    ServerRequestInterface $request,  // текущий запрос
    OrderRepository $repo,            // из контейнера
    ?LoggerInterface $logger = null,  // контейнер или значение по умолчанию
): ResponseInterface {
    // ...
}
```

{: .tip }
Порядок разрешения параметров позволяет смешивать параметры маршрута, объект запроса и сервисы контейнера в любом порядке в сигнатуре метода.

---

## Генерация URL

Генерируйте URL из именованных маршрутов (обратная маршрутизация):

```php
// Регистрация именованных маршрутов
$router->get('/users',          [UserController::class, 'list'], name: 'users.list');
$router->get('/users/{id:\d+}', [UserController::class, 'show'], name: 'users.show');

// Генерация URL
$url = $router->generate('users.show', ['id' => 42]);
// => /users/42

$url = $router->generate('users.list', query: ['page' => 2, 'limit' => 10]);
// => /users?page=2&limit=10
```

Параметры автоматически URL-кодируются. Лишние параметры игнорируются. Недостающие обязательные параметры вызывают `MissingParametersException`.

### Прямое использование UrlGenerator

```php
use AsceticSoft\Waypoint\UrlGenerator;

$generator = new UrlGenerator($router->getRouteCollection());
$url = $generator->generate('users.show', ['id' => 42]);
```

### Абсолютные URL

```php
$router->setBaseUrl('https://example.com');
$url = $router->generate('users.show', ['id' => 42]);
// => https://example.com/users/42
```

{: .note }
Генерация URL работает с кэшированными маршрутами — имена и шаблоны маршрутов сохраняются в файле кэша.

---

## Кэширование маршрутов

Скомпилируйте маршруты в PHP-файл для мгновенной загрузки в продакшене:

### Компиляция кэша

```php
// Во время деплоя / прогрева кэша
$router->compileTo(__DIR__ . '/cache/routes.php');
```

### Загрузка из кэша

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

Файл кэша — обычный PHP-массив, который загружается мгновенно через OPcache, минуя Reflection и парсинг атрибутов.

{: .important }
Не забывайте очищать файл кэша после добавления или изменения маршрутов. Во время разработки пропускайте кэширование.

---

## Диагностика маршрутов

Инспектируйте зарегистрированные маршруты и выявляйте проблемы:

```php
use AsceticSoft\Waypoint\Diagnostic\RouteDiagnostics;

$diagnostics = new RouteDiagnostics($router->getRouteCollection());

// Вывести форматированную таблицу маршрутов
$diagnostics->listRoutes();

// Обнаружить конфликты
$report = $diagnostics->findConflicts();

if ($report->hasIssues()) {
    $diagnostics->printReport();
}
```

### Обнаруживаемые проблемы

| Проблема | Описание |
|:---------|:---------|
| **Дублирующиеся пути** | Маршруты с одинаковыми шаблонами и пересекающимися HTTP-методами |
| **Дублирующиеся имена** | Несколько маршрутов с одним и тем же именем |
| **Перекрытые маршруты** | Более общий шаблон скрывает более конкретный |

{: .tip }
Запускайте диагностику во время разработки или в CI-конвейере для раннего обнаружения конфликтов.

---

## Обработка исключений

Waypoint выбрасывает специфические исключения при ошибках маршрутизации:

| Исключение | HTTP-код | Когда |
|:-----------|:---------|:------|
| `RouteNotFoundException` | 404 | Ни один шаблон маршрута не совпал с URI |
| `MethodNotAllowedException` | 405 | URI совпал, но HTTP-метод не разрешён |
| `RouteNameNotFoundException` | — | Маршрут с указанным именем не найден |
| `MissingParametersException` | — | Не предоставлены обязательные параметры маршрута |

```php
use AsceticSoft\Waypoint\Exception\RouteNotFoundException;
use AsceticSoft\Waypoint\Exception\MethodNotAllowedException;

try {
    $response = $router->handle($request);
} catch (RouteNotFoundException $e) {
    // Вернуть ответ 404
} catch (MethodNotAllowedException $e) {
    // Вернуть ответ 405 с заголовком Allow
    $allowed = implode(', ', $e->getAllowedMethods());
}
```

---

## Архитектура

Waypoint построен на модульной архитектуре, где каждый компонент имеет единственную ответственность:

```
Router  (PSR-15 RequestHandlerInterface)
├── RouteCollection
│   ├── RouteTrie           — префиксное дерево для быстрого сопоставления
│   └── Route[]             — линейное regex-сопоставление для сложных шаблонов
├── AttributeRouteLoader    — чтение атрибутов #[Route] через Reflection
├── MiddlewarePipeline      — FIFO-выполнение PSR-15 middleware
├── RouteHandler            — вызов контроллера с внедрением зависимостей
├── UrlGenerator            — обратная маршрутизация (имя + параметры → URL)
├── RouteCompiler           — компиляция/загрузка кэша маршрутов
└── RouteDiagnostics        — обнаружение конфликтов и отчётность
```

`RouteTrie` обрабатывает большинство маршрутов с O(1) поиском на сегмент. Маршруты с шаблонами, которые невозможно выразить в дереве (смешанные сегменты вроде `prefix-{name}.txt` или захваты через несколько сегментов), автоматически используют линейное regex-сопоставление.
