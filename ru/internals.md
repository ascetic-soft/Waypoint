---
title: Внутреннее устройство
layout: default
nav_order: 7
parent: Русский
---

# Внутреннее устройство
{: .no_toc }

Алгоритмы, структуры данных и внутренняя архитектура Waypoint.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Содержание</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Жизненный цикл запроса

При вызове `Router::handle()` с входящим PSR-7 запросом выполняется следующая последовательность:

```mermaid
sequenceDiagram
    participant Client as Клиент
    participant Router as Router
    participant RouteCollection as RouteCollection
    participant MiddlewarePipeline as MiddlewarePipeline
    participant RouteHandler as RouteHandler
    participant Controller as Контроллер

    Client->>Router: handle(request)
    Router->>RouteCollection: match(method, uri)

    alt Нет совпадений
        RouteCollection-->>Router: RouteNotFoundException
    else Метод не разрешён
        RouteCollection-->>Router: MethodNotAllowedException
    else Найдено совпадение
        RouteCollection-->>Router: RouteMatchResult(route, params)
    end

    Router->>MiddlewarePipeline: handle(request)
    loop Для каждого middleware (FIFO)
        MiddlewarePipeline->>MiddlewarePipeline: process(request, next)
    end
    MiddlewarePipeline->>RouteHandler: handle(request)
    RouteHandler->>Controller: invoke(params)
    Controller-->>RouteHandler: response
    RouteHandler-->>MiddlewarePipeline: response
    MiddlewarePipeline-->>Router: response
    Router-->>Client: response
```

1. **Сопоставление маршрута** — `RouteCollection::match()` находит подходящий маршрут для HTTP-метода и URI.
2. **Конвейер middleware** — глобальные и маршрутные middleware выполняются в порядке FIFO.
3. **Вызов контроллера** — `RouteHandler` разрешает параметры через DI и вызывает контроллер.
4. **Ответ** — ответ поднимается обратно через стек middleware.

---

## Сопоставление маршрутов: трёхфазная стратегия

Waypoint использует трёхфазную стратегию сопоставления — от самого быстрого к самому медленному. Каждая фаза проверяется по порядку; как только совпадение найдено, поиск прекращается.

```mermaid
flowchart TD
    A["Входящий запрос: METHOD + URI"] --> B{"Фаза 1: Статическая хеш-таблица"}
    B -->|"Попадание (O(1))"| Z["Вернуть RouteMatchResult"]
    B -->|"Промах"| C{"Фаза 2: Обход prefix-trie"}
    C -->|"Найдено совпадение"| Z
    C -->|"Нет совпадения"| D{"Фаза 3: Fallback regex-перебор"}
    D -->|"Найдено совпадение"| Z
    D -->|"Нет совпадения"| E{"Собраны разрешённые методы?"}
    E -->|"Да"| F["MethodNotAllowedException (405)"]
    E -->|"Нет"| G["RouteNotFoundException (404)"]

    style B fill:#d4edda,stroke:#28a745
    style C fill:#fff3cd,stroke:#ffc107
    style D fill:#f8d7da,stroke:#dc3545
```

### Фаза 1: Статическая хеш-таблица — O(1)

Маршруты без параметров (например, `/about`, `/api/health`) хранятся в хеш-таблице с ключом `"METHOD:/path"`. Это обеспечивает поиск за константное время для самого распространённого случая.

```
GET:/about        → Route(pattern="/about", ...)
GET:/api/health   → Route(pattern="/api/health", ...)
POST:/api/users   → Route(pattern="/api/users", ...)
```

### Фаза 2: Prefix-Trie — O(k), где k = количество сегментов

Маршруты с параметрами (например, `/users/{id}`) хранятся в prefix-trie (дереве сегментов). Дерево обходится посегментно: для статических сегментов используется O(1) хеш-поиск, а regex применяется только для динамических сегментов.

### Фаза 3: Fallback Regex-перебор — O(n)

Маршруты с шаблонами, которые невозможно представить в trie (например, смешанные сегменты вроде `prefix-{name}.txt`), используют линейный regex-перебор. Эти маршруты группируются по первому сегменту URI для префиксной фильтрации, что сокращает количество regex-проверок.

---

## Алгоритм Prefix-Trie

`RouteTrie` — это дерево, где каждый узел представляет один сегмент URI. Дочерние узлы организованы как хеш-таблица (статические сегменты) плюс упорядоченный список (динамические сегменты).

```mermaid
flowchart TD
    ROOT["(корень)"] --> users["users"]
    ROOT --> posts["posts"]
    ROOT --> api["api"]

    users --> users_static["(лист: GET /users)"]
    users --> users_id["{id:\d+}"]
    users_id --> users_id_leaf["(лист: GET|PUT|DELETE /users/{id})"]
    users_id --> users_id_posts["posts"]
    users_id_posts --> users_id_posts_leaf["(лист: GET /users/{id}/posts)"]

    posts --> posts_year["{year:\d{4}}"]
    posts_year --> posts_slug["{slug}"]
    posts_slug --> posts_slug_leaf["(лист: GET /posts/{year}/{slug})"]

    api --> api_health["health"]
    api_health --> api_health_leaf["(лист: GET /api/health)"]

    style ROOT fill:#e1ecf4,stroke:#0366d6
    style users_id fill:#fff3cd,stroke:#ffc107
    style posts_year fill:#fff3cd,stroke:#ffc107
    style posts_slug fill:#fff3cd,stroke:#ffc107
```

### Алгоритм обхода Trie

```
function match(method, segments, depth, params, allowedMethods):
    if depth == len(segments):
        // Дошли до конца — проверяем листовые маршруты
        for each leaf route at this node:
            if route.allowsMethod(method):
                return {route, params}
            else:
                collect allowedMethods
        return null

    segment = segments[depth]

    // 1. Сначала статические дочерние узлы (O(1) хеш-поиск)
    if staticChildren[segment] exists:
        result = staticChildren[segment].match(method, segments, depth+1, params, allowedMethods)
        if result != null:
            return result

    // 2. Затем динамические дочерние узлы (regex-проверка на сегмент)
    for each dynamicChild:
        if dynamicChild.regex matches segment:
            params[dynamicChild.name] = segment
            result = dynamicChild.match(method, segments, depth+1, params, allowedMethods)
            if result != null:
                return result

    return null
```

**Ключевые свойства:**
- Статические сегменты разрешаются через O(1) хеш-поиск — без regex.
- Динамические сегменты проверяются только при неудаче статического поиска.
- Trie естественно учитывает приоритет: маршруты вставляются в порядке приоритета, и первое совпадение побеждает.
- Алгоритм обхода — в глубину (depth-first): каждая ветвь исследуется полностью перед возвратом.

---

## Конвейер Middleware

`MiddlewarePipeline` реализует PSR-15 `RequestHandlerInterface` и использует индексную итерацию (без клонирования):

```mermaid
flowchart LR
    subgraph Pipeline["Конвейер"]
        direction LR
        MW1["Глобальный: CORS"] --> MW2["Глобальный: RateLimit"]
        MW2 --> MW3["Маршрутный: Auth"]
        MW3 --> RH["RouteHandler"]
    end

    REQ(("Запрос")) --> MW1
    RH --> RESP(("Ответ"))

    style REQ fill:#d4edda,stroke:#28a745
    style RESP fill:#d4edda,stroke:#28a745
```

### Алгоритм конвейера

```
class MiddlewarePipeline:
    middlewares: list
    handler: RequestHandlerInterface  // финальный обработчик (RouteHandler)
    index: int = 0

    function handle(request):
        if index >= len(middlewares):
            return handler.handle(request)  // вызов контроллера

        middleware = resolve(middlewares[index])
        index++
        try:
            return middleware.process(request, this)
        finally:
            index--  // восстановление для повторного использования
```

**Ключевые свойства:**
- **Порядок FIFO** — middleware выполняются в порядке регистрации.
- **Индексная итерация** — исключает клонирование объекта конвейера для каждого вызова middleware.
- **Блок `finally`** — гарантирует восстановление индекса после исключений или коротких замыканий, делая конвейер переиспользуемым.
- **Ленивое разрешение** — имена классов middleware разрешаются из PSR-11 контейнера только при необходимости; разрешённые экземпляры кэшируются.

---

## Внедрение зависимостей: разрешение параметров

`RouteHandler` разрешает параметры методов контроллера по двум стратегиям:

```mermaid
flowchart TD
    A["RouteHandler.handle(request)"] --> B{"argPlan доступен?"}
    B -->|"Да (кэшированные маршруты)"| C["Быстрый путь: разрешение по плану"]
    B -->|"Нет (runtime)"| D["Медленный путь: через Reflection"]

    C --> C1["Для каждой записи плана"]
    C1 --> C2{"source?"}
    C2 -->|"request"| C3["Внедрить ServerRequestInterface"]
    C2 -->|"param"| C4["Внедрить параметр маршрута + приведение типа"]
    C2 -->|"container"| C5["Разрешить из PSR-11 контейнера"]
    C2 -->|"default"| C6["Использовать значение по умолчанию"]
    C3 --> CALL["Вызвать метод контроллера"]
    C4 --> CALL
    C5 --> CALL
    C6 --> CALL

    D --> D1["Reflection параметров метода"]
    D1 --> D2["Для каждого параметра"]
    D2 --> D3{"Тип?"}
    D3 -->|"ServerRequestInterface"| D4["Внедрить request"]
    D3 -->|"Совпадение по имени параметра"| D5["Внедрить параметр + приведение"]
    D3 -->|"Класс type-hint"| D6["Разрешить из контейнера"]
    D3 -->|"Есть значение по умолчанию"| D7["Использовать default"]
    D3 -->|"Nullable"| D8["Передать null"]
    D4 --> CALL
    D5 --> CALL
    D6 --> CALL
    D7 --> CALL
    D8 --> CALL

    style C fill:#d4edda,stroke:#28a745
    style D fill:#fff3cd,stroke:#ffc107
```

**Быстрый путь (кэш):** `RouteCompiler` предвычисляет план разрешения аргументов для каждого обработчика маршрута. Во время выполнения план — это простой массив записей `{source, name, cast, ...}` — Reflection не нужен.

**Медленный путь (runtime):** Когда план недоступен (например, для замыканий или некэшированных маршрутов), `RouteHandler` использует PHP Reflection для анализа параметров метода и их динамического разрешения.

### Приведение типов

Параметры маршрута (извлечённые из URI как строки) автоматически приводятся к объявленному скалярному типу:

| Объявленный тип | Приведение |
|:---------------|:-----------|
| `int` | `(int) $value` |
| `float` | `(float) $value` |
| `bool` | `filter_var($value, FILTER_VALIDATE_BOOLEAN)` |
| `string` | Без приведения (passthrough) |

---

## Кэширование маршрутов: конвейер компиляции

`RouteCompiler` преобразует коллекцию маршрутов в оптимизированный PHP-файл класса:

```mermaid
flowchart TD
    A["Router::compileTo(file)"] --> B["RouteCompiler::compile()"]
    B --> C["Сортировка маршрутов по приоритету"]
    C --> D["Классификация маршрутов"]

    D --> E["Статические → хеш-таблица"]
    D --> F["Trie-совместимые → prefix-trie"]
    D --> G["Сложные шаблоны → fallback-список"]

    E --> H["Генерация метода matchStatic()"]
    F --> I["Генерация метода matchDynamic()"]
    G --> J["Сериализация fallback-индексов"]

    H --> K["Генерация PHP-класса"]
    I --> K
    J --> K

    K --> L["Предвычисление argPlan (без Reflection в runtime)"]
    L --> M["Запись файла, реализующего CompiledMatcherInterface"]

    style M fill:#d4edda,stroke:#28a745
```

Сгенерированный класс реализует `CompiledMatcherInterface` со следующими ключевыми методами:

| Метод | Назначение |
|:------|:-----------|
| `matchStatic($method, $uri)` | O(1) поиск через PHP `match`-выражение |
| `matchDynamic($method, $uri, &$allowed)` | Сгенерированный код обхода trie |
| `staticMethods($uri)` | Сбор разрешённых методов для статических маршрутов |
| `isStaticOnly($uri)` | Проверка: есть ли у URI только статические маршруты (ранний 405) |
| `findByName($name)` | O(1) поиск имя -> индекс маршрута |
| `getRoute($index)` | Получение данных маршрута по индексу |
| `getRouteCount()` | Общее количество скомпилированных маршрутов |
| `getFallbackIndices()` | Индексы не-trie-совместимых маршрутов |

**Ключевые оптимизации:**
- **OPcache-совместимость** — файл представляет собой обычный PHP-класс, хранимый в разделяемой памяти.
- **Без Reflection** — планы разрешения аргументов предвычислены на этапе компиляции.
- **Ленивая гидратация** — объекты `Route` создаются только для совпавших маршрутов, а не для всех.
- **Match-выражения** — PHP 8 `match` используется для диспетчеризации статических маршрутов (компилируется движком в хеш-таблицу).

---

## Сканирование директорий: трёхстадийный фильтр

`AttributeRouteLoader::loadFromDirectory()` использует трёхстадийный фильтр для минимизации дорогих операций:

```mermaid
flowchart TD
    A["Рекурсивный обход директории"] --> B{"Стадия 1: Шаблон имени файла"}
    B -->|"Не совпадает (не *Controller.php)"| SKIP["Пропуск файла"]
    B -->|"Совпадает"| C{"Стадия 2: Предварительная проверка содержимого"}
    C -->|"Нет '#[' в исходнике"| SKIP
    C -->|"Содержит '#['"| D{"Стадия 3: Проверка через Reflection"}
    D -->|"class_exists() не удалось"| SKIP
    D -->|"Абстрактный / Интерфейс"| SKIP
    D -->|"Нет атрибутов #[Route]"| SKIP
    D -->|"Есть #[Route]"| E["Загрузка маршрутов из класса"]

    style SKIP fill:#f8d7da,stroke:#dc3545
    style E fill:#d4edda,stroke:#28a745
```

| Стадия | Стоимость | Что фильтрует |
|:-------|:----------|:--------------|
| **Шаблон имени** | Очень низкая (сравнение строк) | Файлы, не подходящие под glob (например, `*Controller.php`) |
| **Предварительная проверка** | Низкая (чтение файла + `str_contains`) | Файлы без синтаксиса PHP-атрибутов (`#[`) |
| **Проверка через Reflection** | Высокая (автозагрузка + reflection) | Абстрактные классы, интерфейсы, классы без `#[Route]` |

Каждая стадия дешевле следующей. Большинство файлов отфильтровываются на стадиях 1-2, поэтому дорогая автозагрузка и reflection выполняются только для файлов, которые скорее всего содержат маршруты.

---

## Обзор архитектуры

```mermaid
flowchart TB
    subgraph Router["Router (PSR-15 RequestHandlerInterface)"]
        direction TB
        RC["RouteCollection"]
        ARL["AttributeRouteLoader"]
        MP["MiddlewarePipeline"]
        RH["RouteHandler"]
        UG["UrlGenerator"]
        COMP["RouteCompiler"]
        DIAG["RouteDiagnostics"]
    end

    subgraph RouteCollection_internal["Внутренности RouteCollection"]
        direction TB
        ST["Статическая хеш-таблица<br/>O(1) поиск"]
        TRIE["RouteTrie<br/>O(k) обход по сегментам"]
        FB["Fallback-маршруты<br/>O(n) regex-перебор"]
    end

    RC --> ST
    RC --> TRIE
    RC --> FB

    PSR7["PSR-7 запрос"] --> Router
    Router --> PSR7R["PSR-7 ответ"]
    COMP -->|"компиляция / загрузка"| RC
    ARL -->|"загрузка атрибутов"| RC
    DIAG -->|"инспекция"| RC
    UG -->|"обратный поиск"| RC

    style Router fill:#e1ecf4,stroke:#0366d6
    style ST fill:#d4edda,stroke:#28a745
    style TRIE fill:#fff3cd,stroke:#ffc107
    style FB fill:#f8d7da,stroke:#dc3545
```

| Компонент | Ответственность |
|:----------|:----------------|
| **Router** | Точка входа — регистрация маршрутов, middleware, кэширование, диспетчеризация запросов |
| **RouteCollection** | Хранение маршрутов, сопоставление через трёхфазную стратегию |
| **RouteTrie** | Prefix-tree для быстрого посегментного сопоставления |
| **AttributeRouteLoader** | Чтение атрибутов `#[Route]` через Reflection |
| **MiddlewarePipeline** | Выполнение PSR-15 middleware в порядке FIFO |
| **RouteHandler** | Вызов методов контроллера с DI-разрешёнными параметрами |
| **UrlGenerator** | Обратная маршрутизация — генерация URL из именованных маршрутов |
| **RouteCompiler** | Компиляция маршрутов в PHP-класс для OPcache |
| **RouteDiagnostics** | Обнаружение конфликтов и формирование отчётов |
