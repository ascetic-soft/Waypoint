# Changelog

All notable changes to the **Waypoint** router will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] - 2026-02-18

### Changed

- **Trie serialization optimized for OPcache** — string-keyed trie nodes replaced with integer-indexed tuples for packed array representation in shared memory; HTTP methods stored as hash-maps at compile time, enabling O(1) `isset()` checks instead of O(n) `in_array()` in the generated `walk()`.
- **TrieMatcher / RouteTrie refactored** — improved internal structure, inlined hot-path logic, reduced function-call overhead.
- **RouteHandler** and **CompiledArrayMatcher** internal cleanup and simplification.

## [1.2.0] - 2026-02-17

### Changed

- **PSR dependencies are now optional** — `psr/http-message`, `psr/http-server-handler`, `psr/http-server-middleware`, and `psr/container` moved from `require` to `suggest`. The core matching engine (route registration, compilation, trie matching, URL generation, diagnostics) works as pure PHP without any PSR packages installed.
- `ArgumentPlanBuilder` no longer imports `ServerRequestInterface` directly; uses a string constant for PSR-7 detection, making it resilient to missing `psr/http-message`.

## [1.1.5] - 2026-02-15

### Fixed

- **AttributeRouteLoader**: empty or root (`/`) method path no longer produces a trailing slash when combined with a class prefix (e.g. `/api/users/` → `/api/users`).

### Changed

- `Route` attribute class promoted to `final readonly class` (individual property `readonly` modifiers removed).

## [1.1.4] - 2026-02-14

### Added

- **100% line/method/class test coverage** — 7 new tests covering previously uncovered edge cases:
  - `MiddlewarePipeline`: container returning non-`MiddlewareInterface` instance throws `RuntimeException`.
  - `AttributeRouteLoader`: directory scan skips classes with PHP attributes but no `#[Route]`.
  - `RouteCompiler`: static routes overlapping with dynamic or fallback routes are correctly excluded from the static-only optimisation.
  - `RouteCollection`: Phase 1 fallback candidate skip, Phase 3 static-method pre-population for non-static-only URIs, root URI matching.
- `NonRouteAttributeClass` test fixture for attribute loader coverage.

### Changed

- Added `@codeCoverageIgnore` for three unreachable/defensive code paths:
  - `RouteCompiler::hasNoParamChildrenAlongPath()` — segment-not-found guard.
  - `RouteTrie::isCompatible()` — invalid regex guard.
  - `RouteCollection::fallbackPrefix()` — empty-pattern guard (root `/` is always trie-compatible).

## [1.1.3] - 2026-02-14

### Added

- **HEAD→GET automatic fallback** in `RouteCollection::match()` per RFC 7231 §4.3.2 — if no explicit HEAD route exists but a GET route matches, the GET route is used.
- **Directory scanning** in `AttributeRouteLoader::loadFromDirectory()` with a three-stage filter (filename pattern, content pre-check, reflection gate) to minimise expensive autoloading.
- `Router::scanDirectory()` now accepts an optional `$filePattern` parameter for filtering controller files (e.g. `*Controller.php`).
- **Runtime type-check** for middleware resolved from the PSR-11 container — throws a clear `RuntimeException` if the resolved object does not implement `MiddlewareInterface`.
- New tests for `RouteCollection`, `Router`, `AttributeRouteLoader`, and `MiddlewarePipeline` (exception recovery, reusability, short-circuit behaviour).

### Changed

- **MiddlewarePipeline** refactored from clone-based to index-based iteration with a `finally` block — eliminates object cloning and ensures consistent state after exceptions or short-circuits.
- **Middleware caching** — resolved middleware instances are now cached to avoid redundant container lookups.
- **Static route hash table** — O(1) lookup for parameter-less routes in `RouteCollection`.
- **Prefix-based fallback route grouping** and lazy hydration for compiled route data in `RouteCollection`.
- `Router::buildPath()` fast-path optimisation when no group prefix is set.
- Inlined `RouteTrie::splitUri()` in the matching hot path to reduce function-call overhead.

## [1.1.2] - 2026-02-14

### Added

- **100% test coverage** across all components (`Router`, `Route`, `RouteCollection`, `RouteTrie`, `RouteCompiler`, `RouteHandler`, `RouteDiagnostics`).
- `CompiledMatcherInterface` for compiled route matching with OPcache support.
- Extended `RouteCompiler` with full compiled matcher generation for static and dynamic routes.
- Extended `RouteCollection` with advanced route management capabilities.
- Extended `RouteTrie` with improved prefix-tree operations and edge-case handling.
- Extended `Route` with additional configuration options.
- Extended `RouteHandler` with enhanced middleware resolution.
- Documentation links to README (Packagist, CI, coverage badges).

### Changed

- Optimized `RouteTrie` prefix-tree matching performance.
- Optimized `RouteCollection` internals for faster lookups.
- Optimized `Router` dispatch pipeline.
- Improved `RouteCompiler` code generation for better OPcache utilization.

### Fixed

- Fixed Codecov badge URL in README.

## [1.1.1] - 2026-02-12

### Added

- Base URL support in `UrlGenerator` for generating absolute URLs.
- `BaseUrlNotSetException` for cases when base URL is required but not configured.
- `Router::generate()` `$absolute` parameter and `Router::setBaseUrl()` for absolute URL generation.
- Tests for absolute URL generation.

## [1.1.0] - 2026-02-12

### Added

- **URL Generator** (`UrlGenerator`) for reverse routing — generate URLs from named routes with parameter substitution.
- `MissingParametersException` for missing required route parameters during URL generation.
- `RouteNameNotFoundException` for referencing undefined route names.
- `Router::generate()` convenience method for URL generation.
- `RouteCollection::findByName()` for looking up routes by name.
- Tests for `UrlGenerator`, `RouteCollection`, `RouteDiagnostics`, and `RouteHandler`.

## [1.0.0] - 2026-02-12

### Added

- **Initial release** of Waypoint — a PSR-15 compatible PHP router.
- `#[Route]` attribute for declaring routes directly on controller methods.
- `AttributeRouteLoader` for automatic route discovery from controller classes.
- **Prefix-trie** (`RouteTrie`) for fast route matching with support for:
  - Static segments.
  - Dynamic parameters (`{id}`).
  - Optional parameters (`{id?}`).
  - Wildcard catch-all segments (`{path*}`).
  - Regex constraints on parameters.
- `RouteCollection` for managing and organizing routes.
- **Route groups** with shared prefixes and middleware.
- `MiddlewarePipeline` — PSR-15 compliant middleware pipeline (global and per-route).
- `RouteHandler` — PSR-15 request handler that resolves controllers via PSR-11 container.
- `RouteCompiler` — route caching / compilation to plain PHP arrays for OPcache.
- `RouteDiagnostics` — detect duplicate paths, duplicate names, and shadowed routes.
- `MethodNotAllowedException` with `Allow` header support (405 responses).
- `RouteNotFoundException` (404 responses).
- PHPStan static analysis at **level 9**.
- PHP CS Fixer configuration.
- Makefile with `fix`, `cs-check`, `stan`, `test`, and `check` targets.
- GitHub Actions CI workflow (tests, static analysis, code coverage).
- Comprehensive README with usage examples.
- PHPUnit test suite covering core components.

[Unreleased]: https://github.com/ascetic-soft/Waypoint/compare/v1.2.1...HEAD
[1.2.1]: https://github.com/ascetic-soft/Waypoint/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/ascetic-soft/Waypoint/compare/v1.1.5...v1.2.0
[1.1.5]: https://github.com/ascetic-soft/Waypoint/compare/v1.1.4...v1.1.5
[1.1.4]: https://github.com/ascetic-soft/Waypoint/compare/v1.1.3...v1.1.4
[1.1.3]: https://github.com/ascetic-soft/Waypoint/compare/v1.1.2...v1.1.3
[1.1.2]: https://github.com/ascetic-soft/Waypoint/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/ascetic-soft/Waypoint/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/ascetic-soft/Waypoint/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ascetic-soft/Waypoint/releases/tag/v1.0.0
