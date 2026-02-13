---
title: Главная
layout: default
nav_order: 1
parent: Русский
permalink: /ru/
---

# Waypoint

{: .fs-9 }

Легковесный PSR-15 PHP-роутер с маршрутизацией на атрибутах, prefix-trie сопоставлением и конвейером middleware.
{: .fs-6 .fw-300 }

[![CI](https://github.com/ascetic-soft/Waypoint/actions/workflows/ci.yml/badge.svg)](https://github.com/ascetic-soft/Waypoint/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/ascetic-soft/Waypoint/graph/badge.svg?token=cK4v0Ph4ol)](https://codecov.io/gh/ascetic-soft/Waypoint)
[![PHPStan Level 9](https://img.shields.io/badge/phpstan-level%209-brightgreen)](https://phpstan.org/)
[![Latest Stable Version](https://img.shields.io/packagist/v/ascetic-soft/waypoint)](https://packagist.org/packages/ascetic-soft/waypoint)
[![PHP Version](https://img.shields.io/packagist/dependency-v/ascetic-soft/waypoint/php)](https://packagist.org/packages/ascetic-soft/waypoint)
[![License](https://img.shields.io/packagist/l/ascetic-soft/waypoint)](https://packagist.org/packages/ascetic-soft/waypoint)

[Быстрый старт]({{ '/ru/getting-started.html' | relative_url }}){: .btn .btn-primary .fs-5 .mb-4 .mb-md-0 .mr-2 }
[English]({{ '/' | relative_url }}){: .btn .fs-5 .mb-4 .mb-md-0 }

---

## Что такое Waypoint?

Waypoint — это современный, легковесный **PSR-15 совместимый PHP-роутер** для PHP 8.4+. Он объединяет маршрутизацию на атрибутах, быстрый движок сопоставления на prefix-trie и конвейер PSR-15 middleware в единый элегантный пакет.

### Ключевые особенности

- **PSR-15 совместимый** — реализует `RequestHandlerInterface`, работает с любым PSR-7 / PSR-15 стеком
- **Маршрутизация на атрибутах** — объявляйте маршруты с помощью PHP 8 атрибутов `#[Route]` прямо на контроллерах
- **Быстрое prefix-trie сопоставление** — статические сегменты разрешаются через O(1) хеш-таблицу; динамические проверяются только при необходимости
- **Конвейер middleware** — глобальные и маршрутные PSR-15 middleware с FIFO-порядком выполнения
- **Группы маршрутов** — общие префиксы путей и middleware для связанных маршрутов
- **Кэширование маршрутов** — компиляция в PHP-файл для мгновенной загрузки через OPcache
- **Автоматическое внедрение зависимостей** — параметры маршрута, `ServerRequestInterface` и сервисы контейнера внедряются в методы контроллеров
- **Генерация URL** — обратная маршрутизация из именованных маршрутов
- **Диагностика маршрутов** — обнаружение дублей, конфликтов имён и перекрытых маршрутов
- **Приоритетное сопоставление** — управление тем, какой маршрут побеждает при пересечении шаблонов

---

## Быстрый пример

```php
use AsceticSoft\Waypoint\Router;
use Nyholm\Psr7\ServerRequest;

$router = new Router($container); // любой PSR-11 контейнер

$router->get('/hello/{name}', function (string $name) use ($responseFactory) {
    $response = $responseFactory->createResponse();
    $response->getBody()->write("Hello, {$name}!");
    return $response;
});

$request  = new ServerRequest('GET', '/hello/world');
$response = $router->handle($request);
```

Несколько строк — и роутер с внедрением параметров готов. Никакого XML, YAML или шаблонного кода.

---

## Почему Waypoint?

| Возможность | Waypoint | Другие роутеры |
|:------------|:---------|:---------------|
| PSR-15 `RequestHandlerInterface` | Да | Не всегда |
| PHP 8 атрибуты `#[Route]` | Да | Некоторые |
| Prefix-trie O(1) сопоставление | Да | Линейный перебор |
| Маршрутные middleware | Да | Некоторые |
| Кэширование маршрутов (OPcache) | Да | Некоторые |
| Авто-внедрение зависимостей | Да | Редко |
| Диагностика маршрутов | Да | Нет |
| Минимум зависимостей | Только PSR-пакеты | Часто много |
| PHPStan Level 9 | Да | По-разному |

---

## Требования

- **PHP** >= 8.4
- **ext-mbstring**

## Установка

```bash
composer require ascetic-soft/waypoint
```

---

## Документация

<div class="grid-container">
  <div class="grid-item">
    <h3><a href="{{ '/ru/getting-started.html' | relative_url }}">Быстрый старт</a></h3>
    <p>Установка, первый роутер и базовое использование за 5 минут.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/ru/routing.html' | relative_url }}">Маршрутизация</a></h3>
    <p>Регистрация маршрутов, параметры, группы и атрибуты.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/ru/middleware.html' | relative_url }}">Middleware</a></h3>
    <p>Глобальные и маршрутные PSR-15 middleware.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/ru/advanced.html' | relative_url }}">Продвинутое</a></h3>
    <p>Внедрение зависимостей, генерация URL, кэширование и диагностика.</p>
  </div>
  <div class="grid-item">
    <h3><a href="{{ '/ru/api-reference.html' | relative_url }}">Справочник API</a></h3>
    <p>Полный справочник по всем публичным классам и методам.</p>
  </div>
</div>
