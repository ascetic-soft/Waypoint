---
title: Справочник API
layout: default
nav_order: 6
parent: Русский
---

# Справочник API
{: .no_toc }

Полный справочник по всем публичным классам и методам.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Содержание</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## RouteRegistrar

Самостоятельный строитель маршрутов. Предоставляет fluent API для ручной регистрации, загрузки атрибутов и групп маршрутов.

```php
use AsceticSoft\Waypoint\RouteRegistrar;

$registrar = new RouteRegistrar();
```

### Регистрация маршрутов

| Метод | Описание |
|:------|:---------|
| `addRoute(string $path, array\|Closure $handler, array $methods, array $middleware, string $name, int $priority): self` | Зарегистрировать маршрут для указанных HTTP-методов |
| `get(string $path, array\|Closure $handler, ...): self` | Зарегистрировать GET-маршрут |
| `post(string $path, array\|Closure $handler, ...): self` | Зарегистрировать POST-маршрут |
| `put(string $path, array\|Closure $handler, ...): self` | Зарегистрировать PUT-маршрут |
| `delete(string $path, array\|Closure $handler, ...): self` | Зарегистрировать DELETE-маршрут |
| `group(string $prefix, Closure $callback, array $middleware): self` | Сгруппировать маршруты под общим префиксом |

### Загрузка атрибутов

| Метод | Описание |
|:------|:---------|
| `loadAttributes(string ...$classes): self` | Загрузить маршруты из атрибутов `#[Route]` |
| `scanDirectory(string $directory, string $namespace, string $filePattern = '*.php'): self` | Автоматически обнаружить маршруты, сканируя директорию |

### Инспекция

| Метод | Описание |
|:------|:---------|
| `getRouteCollection(): RouteCollection` | Получить коллекцию маршрутов, построенную регистратором |

---

## Router

PSR-15 обработчик запросов, выполняющий диспетчеризацию через конвейер middleware. Реализует `Psr\Http\Server\RequestHandlerInterface`.

```php
use AsceticSoft\Waypoint\Router;

$router = new Router(
    ContainerInterface $container,
    RouteCollection $routes = new RouteCollection(),
    ?UrlMatcherInterface $matcher = null,
);
```

### Middleware

| Метод | Описание |
|:------|:---------|
| `addMiddleware(string\|MiddlewareInterface ...$middleware): self` | Добавить глобальный middleware |

### Кэширование

| Метод | Описание |
|:------|:---------|
| `loadCache(string $cacheFilePath): self` | Загрузить маршруты из файла кэша |

### Обработка запросов

| Метод | Описание |
|:------|:---------|
| `handle(ServerRequestInterface $request): ResponseInterface` | PSR-15 обработчик запроса |

### Генерация URL

| Метод | Описание |
|:------|:---------|
| `setBaseUrl(string $baseUrl): self` | Установить базовый URL для абсолютных URL |
| `getUrlGenerator(): UrlGenerator` | Получить экземпляр генератора URL (создаётся лениво) |

### Инспекция

| Метод | Описание |
|:------|:---------|
| `getRouteCollection(): RouteCollection` | Получить коллекцию маршрутов |
| `getMatcher(): UrlMatcherInterface` | Получить матчер URL (создаётся лениво из коллекции) |

---

## RouteCollection

Хранит и сопоставляет маршруты. Использует `RouteTrie` для быстрого поиска.

| Метод | Описание |
|:------|:---------|
| `add(Route $route): void` | Добавить маршрут в коллекцию |
| `match(string $method, string $uri): RouteMatchResult` | Сопоставить HTTP-метод и URI с маршрутом |
| `all(): Route[]` | Получить все маршруты, отсортированные по приоритету |
| `findByName(string $name): ?Route` | Найти маршрут по имени |

---

## Route

Неизменяемый объект-значение, представляющий один маршрут.

| Метод | Описание |
|:------|:---------|
| `compile(): string` | Скомпилировать шаблон в regex |
| `match(string $uri): ?array` | Сопоставить URI с маршрутом, возвращает параметры или null |
| `allowsMethod(string $method): bool` | Проверить, разрешён ли HTTP-метод |
| `toArray(): array` | Сериализовать для кэширования |
| `fromArray(array $data): static` | Десериализовать из кэша |

---

## UrlGenerator

Обратная маршрутизация — генерация URL из имён маршрутов и параметров.

```php
use AsceticSoft\Waypoint\UrlGenerator;

$generator = new UrlGenerator(RouteCollection $routes, string $baseUrl = '');
```

| Метод | Описание |
|:------|:---------|
| `generate(string $name, array $params = [], array $query = [], bool $absolute = false): string` | Сгенерировать URL из имени маршрута |

---

## AttributeRouteLoader

Чтение атрибутов `#[Route]` из классов контроллеров.

```php
use AsceticSoft\Waypoint\Loader\AttributeRouteLoader;

$loader = new AttributeRouteLoader();
```

| Метод | Описание |
|:------|:---------|
| `loadFromClass(string $className): Route[]` | Загрузить маршруты из одного класса |
| `loadFromDirectory(string $directory, string $namespace, string $filePattern = '*.php'): Route[]` | Просканировать директорию на контроллеры с `#[Route]` |

---

## RouteDiagnostics

Обнаружение конфликтов маршрутов и формирование отчётов.

```php
use AsceticSoft\Waypoint\Diagnostic\RouteDiagnostics;

$diagnostics = new RouteDiagnostics(RouteCollection $routes);
```

| Метод | Описание |
|:------|:---------|
| `listRoutes(): void` | Вывести форматированную таблицу маршрутов |
| `findConflicts(): DiagnosticReport` | Обнаружить конфликты и вернуть отчёт |
| `printReport(): void` | Вывести полный диагностический отчёт |

---

## RouteCompiler

Компиляция и загрузка файлов кэша маршрутов.

```php
use AsceticSoft\Waypoint\Cache\RouteCompiler;

$compiler = new RouteCompiler();
```

| Метод | Описание |
|:------|:---------|
| `compile(RouteCollection $routes, string $cacheFilePath): void` | Скомпилировать маршруты в PHP-файл кэша (compiled matcher class) |
| `load(string $cacheFilePath): UrlMatcherInterface` | Загрузить матчер из файла кэша |
| `isFresh(string $cacheFilePath): bool` | Проверить, существует ли файл кэша |

---

## Исключения

| Исключение | Когда |
|:-----------|:------|
| `RouteNotFoundException` | Ни один маршрут не совпал с URI (HTTP 404) |
| `MethodNotAllowedException` | URI совпал, но метод не разрешён (HTTP 405) |
| `RouteNameNotFoundException` | Имя маршрута не найдено при генерации URL |
| `MissingParametersException` | Отсутствуют обязательные параметры при генерации URL |
| `BaseUrlNotSetException` | Запрошен абсолютный URL, но базовый URL не настроен |

`MethodNotAllowedException` предоставляет метод `getAllowedMethods(): array` для получения списка разрешённых HTTP-методов.
