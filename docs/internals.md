---
title: Internals
layout: default
nav_order: 7
---

# Internals
{: .no_toc }

Algorithms, data structures, and internal architecture of Waypoint.
{: .fs-6 .fw-300 }

<details open markdown="block">
  <summary>Table of contents</summary>
  {: .text-delta }
- TOC
{:toc}
</details>

---

## Request Lifecycle

When `Router::handle()` is called with an incoming PSR-7 request, the following sequence takes place:

```mermaid
sequenceDiagram
    participant Client
    participant Router
    participant UrlMatcher
    participant MiddlewarePipeline
    participant RouteHandler
    participant Controller

    Client->>Router: handle(request)
    Router->>UrlMatcher: match(method, uri)

    alt No match
        UrlMatcher-->>Router: RouteNotFoundException
    else Method not allowed
        UrlMatcher-->>Router: MethodNotAllowedException
    else Match found
        UrlMatcher-->>Router: RouteMatchResult(route, params)
    end

    Router->>MiddlewarePipeline: handle(request)
    loop For each middleware (FIFO)
        MiddlewarePipeline->>MiddlewarePipeline: process(request, next)
    end
    MiddlewarePipeline->>RouteHandler: handle(request)
    RouteHandler->>Controller: invoke(params)
    Controller-->>RouteHandler: response
    RouteHandler-->>MiddlewarePipeline: response
    MiddlewarePipeline-->>Router: response
    Router-->>Client: response
```

1. **Route matching** — `UrlMatcherInterface::match()` finds a matching route for the HTTP method and URI.
2. **Middleware pipeline** — global and route-level middleware execute in FIFO order.
3. **Controller invocation** — `RouteHandler` resolves parameters via DI and calls the controller.
4. **Response** — the response bubbles back up through the middleware stack.

---

## Route Matching: Three-Phase Strategy

Waypoint uses a three-phase matching strategy, going from fastest to slowest. Each phase is tried in order; as soon as a match is found, the search stops.

```mermaid
flowchart TD
    A["Incoming request: METHOD + URI"] --> B{"Phase 1: Static hash table"}
    B -->|"Hit (O(1))"| Z["Return RouteMatchResult"]
    B -->|"Miss"| C{"Phase 2: Prefix-trie walk"}
    C -->|"Match found"| Z
    C -->|"No match"| D{"Phase 3: Fallback regex scan"}
    D -->|"Match found"| Z
    D -->|"No match"| E{"Allowed methods collected?"}
    E -->|"Yes"| F["Throw MethodNotAllowedException (405)"]
    E -->|"No"| G["Throw RouteNotFoundException (404)"]

    style B fill:#d4edda,stroke:#28a745
    style C fill:#fff3cd,stroke:#ffc107
    style D fill:#f8d7da,stroke:#dc3545
```

### Phase 1: Static Hash Table — O(1)

Routes without parameter placeholders (e.g. `/about`, `/api/health`) are stored in a hash table keyed by `"METHOD:/path"`. This provides constant-time lookup for the most common case.

```
GET:/about        → Route(pattern="/about", ...)
GET:/api/health   → Route(pattern="/api/health", ...)
POST:/api/users   → Route(pattern="/api/users", ...)
```

### Phase 2: Prefix-Trie — O(k) where k = segment count

Routes with parameters (e.g. `/users/{id}`) are stored in a prefix-trie (also known as a segment tree). The trie is walked segment by segment, with O(1) hash lookups for static segments and regex matching only for dynamic segments.

### Phase 3: Fallback Regex Scan — O(n)

Routes with patterns that cannot be represented in the trie (e.g. mixed segments like `prefix-{name}.txt` or cross-segment captures) fall back to linear regex matching. These routes are grouped by their first URI segment for prefix-based filtering, reducing the number of regex tests.

---

## Prefix-Trie Algorithm

The `RouteTrie` is a tree where each node represents a single URI segment. Children are organized as a hash map (static segments) plus an ordered list (dynamic segments).

```mermaid
flowchart TD
    ROOT["(root)"] --> users["users"]
    ROOT --> posts["posts"]
    ROOT --> api["api"]

    users --> users_static["(leaf: GET /users)"]
    users --> users_id["{id:\d+}"]
    users_id --> users_id_leaf["(leaf: GET|PUT|DELETE /users/{id})"]
    users_id --> users_id_posts["posts"]
    users_id_posts --> users_id_posts_leaf["(leaf: GET /users/{id}/posts)"]

    posts --> posts_year["{year:\d{4}}"]
    posts_year --> posts_slug["{slug}"]
    posts_slug --> posts_slug_leaf["(leaf: GET /posts/{year}/{slug})"]

    api --> api_health["health"]
    api_health --> api_health_leaf["(leaf: GET /api/health)"]

    style ROOT fill:#e1ecf4,stroke:#0366d6
    style users_id fill:#fff3cd,stroke:#ffc107
    style posts_year fill:#fff3cd,stroke:#ffc107
    style posts_slug fill:#fff3cd,stroke:#ffc107
```

### Trie Walk Algorithm

```
function match(method, segments, depth, params, allowedMethods):
    if depth == len(segments):
        // Reached the end — check leaf routes
        for each leaf route at this node:
            if route.allowsMethod(method):
                return {route, params}
            else:
                collect allowedMethods
        return null

    segment = segments[depth]

    // 1. Try static children first (O(1) hash lookup)
    if staticChildren[segment] exists:
        result = staticChildren[segment].match(method, segments, depth+1, params, allowedMethods)
        if result != null:
            return result

    // 2. Try dynamic children (regex match per segment)
    for each dynamicChild:
        if dynamicChild.regex matches segment:
            params[dynamicChild.name] = segment
            result = dynamicChild.match(method, segments, depth+1, params, allowedMethods)
            if result != null:
                return result

    return null
```

**Key properties:**
- Static segments are resolved via O(1) hash-map lookup — no regex at all.
- Dynamic segments are tested only when static lookup fails.
- The trie naturally handles priority: routes are inserted in priority order, and the first match wins.
- The algorithm is depth-first — it fully explores each branch before backtracking.

### Trie Serialization (OPcache)

When routes are compiled via `RouteCompiler`, the trie is serialized using **integer-indexed tuples** instead of string-keyed associative arrays. This produces packed arrays that are stored more efficiently in OPcache shared memory.

HTTP methods at leaf nodes are stored as **hash-maps** (`['GET' => true, 'POST' => true]`) at compile time, enabling O(1) `isset()` checks instead of O(n) `in_array()` during the generated `walk()`.

---

## Middleware Pipeline

The `MiddlewarePipeline` implements PSR-15 `RequestHandlerInterface` and uses index-based iteration (no cloning):

```mermaid
flowchart LR
    subgraph Pipeline
        direction LR
        MW1["Global: CORS"] --> MW2["Global: RateLimit"]
        MW2 --> MW3["Route: Auth"]
        MW3 --> RH["RouteHandler"]
    end

    REQ(("Request")) --> MW1
    RH --> RESP(("Response"))

    style REQ fill:#d4edda,stroke:#28a745
    style RESP fill:#d4edda,stroke:#28a745
```

### Pipeline Algorithm

```
class MiddlewarePipeline:
    middlewares: list
    handler: RequestHandlerInterface  // final handler (RouteHandler)
    index: int = 0

    function handle(request):
        if index >= len(middlewares):
            return handler.handle(request)  // invoke controller

        middleware = resolve(middlewares[index])
        index++
        try:
            return middleware.process(request, this)
        finally:
            index--  // restore for reusability
```

**Key properties:**
- **FIFO order** — middleware executes in registration order.
- **Index-based** — avoids cloning the pipeline object for each middleware call.
- **`finally` block** — ensures the index is restored after exceptions or short-circuits, making the pipeline reusable.
- **Lazy resolution** — middleware class names are resolved from the PSR-11 container only when needed; resolved instances are cached.

---

## Dependency Injection: Parameter Resolution

The `RouteHandler` resolves controller method parameters using two strategies:

```mermaid
flowchart TD
    A["RouteHandler.handle(request)"] --> B{"argPlan available?"}
    B -->|"Yes (cached routes)"| C["Fast path: resolve from plan"]
    B -->|"No (runtime)"| D["Slow path: Reflection-based"]

    C --> C1["For each plan entry"]
    C1 --> C2{"source?"}
    C2 -->|"request"| C3["Inject ServerRequestInterface"]
    C2 -->|"param"| C4["Inject route parameter + type cast"]
    C2 -->|"container"| C5["Resolve from PSR-11 container"]
    C2 -->|"default"| C6["Use default value"]
    C3 --> CALL["Invoke controller method"]
    C4 --> CALL
    C5 --> CALL
    C6 --> CALL

    D --> D1["Reflect method parameters"]
    D1 --> D2["For each parameter"]
    D2 --> D3{"Type?"}
    D3 -->|"ServerRequestInterface"| D4["Inject request"]
    D3 -->|"Route param name match"| D5["Inject param + coerce"]
    D3 -->|"Class type-hint"| D6["Resolve from container"]
    D3 -->|"Has default"| D7["Use default"]
    D3 -->|"Nullable"| D8["Pass null"]
    D4 --> CALL
    D5 --> CALL
    D6 --> CALL
    D7 --> CALL
    D8 --> CALL

    style C fill:#d4edda,stroke:#28a745
    style D fill:#fff3cd,stroke:#ffc107
```

**Fast path (cached):** The `RouteCompiler` pre-computes an argument resolution plan for each route handler. At runtime, the plan is a simple array of `{source, name, cast, ...}` entries — no Reflection needed.

**Slow path (runtime):** When no plan is available (e.g. closure handlers, non-cached routes), `RouteHandler` uses PHP Reflection to inspect method parameters and resolve them dynamically.

### Type Coercion

Route parameters (extracted as strings from the URI) are automatically cast to the declared scalar type:

| Declared type | Coercion |
|:-------------|:---------|
| `int` | `(int) $value` |
| `float` | `(float) $value` |
| `bool` | `filter_var($value, FILTER_VALIDATE_BOOLEAN)` |
| `string` | No coercion (passthrough) |

---

## Route Caching: Compilation Pipeline

The `RouteCompiler` transforms the runtime route collection into an optimized PHP class file:

```mermaid
flowchart TD
    A["RouteCompiler::compile(routes, file)"] --> B["Sort routes by priority"]
    B --> C["Classify routes"]

    C --> D["Static routes → hash table"]
    C --> E["Trie-compatible → prefix-trie"]
    C --> F["Complex patterns → fallback list"]

    D --> G["Generate matchStatic() method"]
    E --> H["Generate matchDynamic() method"]
    F --> I["Serialize fallback indices"]

    G --> J["Generate PHP class file"]
    H --> J
    I --> J

    J --> K["Pre-compute argPlans (no Reflection at runtime)"]
    K --> L["Write file implementing CompiledMatcherInterface"]

    style L fill:#d4edda,stroke:#28a745
```

The generated class implements `CompiledMatcherInterface` with these key methods:

| Method | Purpose |
|:-------|:--------|
| `matchStatic($method, $uri)` | O(1) lookup via PHP `match` expression |
| `matchDynamic($method, $uri, &$allowed)` | Generated trie-traversal code |
| `staticMethods($uri)` | Collect allowed methods for static routes |
| `isStaticOnly($uri)` | Check if URI has only static routes (early 405) |
| `findByName($name)` | O(1) name → route index lookup |
| `getRoute($index)` | Retrieve route data by index |
| `getRouteCount()` | Total number of compiled routes |
| `getFallbackIndices()` | Indices of non-trie-compatible routes |

**Key optimizations:**
- **OPcache-friendly** — the file is a plain PHP class stored in shared memory.
- **Integer-indexed tuples** — trie nodes use packed integer-indexed arrays instead of string-keyed associative arrays, reducing memory footprint in shared memory.
- **Pre-computed method hash-maps** — HTTP methods stored as `['GET' => true]` hash-maps at compile time, enabling O(1) `isset()` checks instead of O(n) `in_array()`.
- **No Reflection** — argument resolution plans are pre-computed at compile time.
- **Lazy hydration** — `Route` objects are constructed only for matched routes, not all routes.
- **Match expressions** — PHP 8 `match` is used for static route dispatch (compiled to a hash table by the engine).

---

## Directory Scanning: Three-Stage Filter

The `AttributeRouteLoader::loadFromDirectory()` uses a three-stage filter to minimize expensive operations:

```mermaid
flowchart TD
    A["Iterate directory recursively"] --> B{"Stage 1: Filename pattern"}
    B -->|"No match (e.g. not *Controller.php)"| SKIP["Skip file"]
    B -->|"Match"| C{"Stage 2: Content pre-check"}
    C -->|"No '#[' in source"| SKIP
    C -->|"Contains '#['"| D{"Stage 3: Reflection gate"}
    D -->|"class_exists() fails"| SKIP
    D -->|"Abstract / Interface"| SKIP
    D -->|"No #[Route] attributes"| SKIP
    D -->|"Has #[Route]"| E["Load routes from class"]

    style SKIP fill:#f8d7da,stroke:#dc3545
    style E fill:#d4edda,stroke:#28a745
```

| Stage | Cost | What it filters |
|:------|:-----|:----------------|
| **Filename pattern** | Very low (string match) | Files not matching the glob (e.g. `*Controller.php`) |
| **Content pre-check** | Low (file read + `str_contains`) | Files without any PHP attribute syntax (`#[`) |
| **Reflection gate** | High (autoload + reflection) | Abstract classes, interfaces, classes without `#[Route]` |

Each stage is cheaper than the next. Most files are filtered in stages 1-2, so expensive autoloading and reflection only happen for files that are likely to contain routes.

---

## Architecture Overview

```mermaid
flowchart TB
    subgraph Registration["RouteRegistrar"]
        direction TB
        ARL["AttributeRouteLoader"]
        GROUPS["Group/Prefix support"]
    end

    subgraph Dispatch["Router (PSR-15 RequestHandlerInterface)"]
        direction TB
        RC["RouteCollection"]
        MP["MiddlewarePipeline"]
        RH["RouteHandler"]
        UG["UrlGenerator"]
        COMP["RouteCompiler"]
        DIAG["RouteDiagnostics"]
    end

    subgraph RouteCollection_internal["RouteCollection internals"]
        direction TB
        ST["Static Hash Table<br/>O(1) lookup"]
        TRIE["RouteTrie<br/>O(k) segment walk"]
        FB["Fallback Routes<br/>O(n) regex scan"]
    end

    Registration -->|"getRouteCollection()"| RC
    RC --> ST
    RC --> TRIE
    RC --> FB

    PSR7["PSR-7 Request"] --> Dispatch
    Dispatch --> PSR7R["PSR-7 Response"]
    COMP -->|"compile / load"| RC
    ARL -->|"load attributes"| RC
    DIAG -->|"inspect"| RC
    UG -->|"reverse lookup"| RC

    style Registration fill:#f0e6ff,stroke:#7c3aed
    style Dispatch fill:#e1ecf4,stroke:#0366d6
    style ST fill:#d4edda,stroke:#28a745
    style TRIE fill:#fff3cd,stroke:#ffc107
    style FB fill:#f8d7da,stroke:#dc3545
```

| Component | Responsibility |
|:----------|:---------------|
| **RouteRegistrar** | Fluent route registration, attribute loading, group prefixes and middleware |
| **Router** | PSR-15 request handler — matching, middleware execution, dispatching |
| **RouteCollection** | Stores routes, performs matching via three-phase strategy |
| **RouteTrie** | Prefix-tree for fast segment-by-segment matching |
| **AttributeRouteLoader** | Reads `#[Route]` attributes via Reflection |
| **MiddlewarePipeline** | Executes PSR-15 middleware in FIFO order |
| **RouteHandler** | Invokes controller methods with DI-resolved parameters |
| **UrlGenerator** | Reverse routing — generates URLs from named routes |
| **RouteCompiler** | Compiles routes to PHP class for OPcache |
| **RouteDiagnostics** | Detects conflicts and generates reports |
