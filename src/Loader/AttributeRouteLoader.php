<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Loader;

use AsceticSoft\Waypoint\Attribute\Route as RouteAttribute;
use AsceticSoft\Waypoint\Route;

/**
 * Loads routes from `#[Route]` attributes on controller classes and methods using Reflection.
 */
final class AttributeRouteLoader
{
    /**
     * Load routes from a single controller class.
     *
     * @param class-string $className Fully-qualified class name.
     *
     * @return list<Route>
     */
    public function loadFromClass(string $className): array
    {
        return $this->loadFromReflection(new \ReflectionClass($className));
    }

    /**
     * Scan a directory for PHP files and load routes from all classes found.
     *
     * Three-stage filter minimises expensive autoloading:
     *  1. **Filename pattern** — skip files that don't match `$filePattern` (e.g. `*Controller.php`).
     *  2. **Content pre-check** — skip files whose source doesn't contain a PHP attribute marker (`#[`),
     *     so `class_exists()` (and its autoload side-effect) is never triggered for them.
     *  3. **Reflection gate** — after autoload, skip abstract classes, interfaces, and classes
     *     without any `#[Route]` attributes.
     *
     * @param string $directory   Absolute path to the directory to scan.
     * @param string $namespace   Base namespace mapping to the directory (e.g. 'App\\Controllers\\').
     * @param string $filePattern Glob pattern applied to each filename (e.g. '*Controller.php').
     *                            Defaults to '*.php' (all PHP files).
     *
     * @return list<Route>
     */
    public function loadFromDirectory(
        string $directory,
        string $namespace,
        string $filePattern = '*.php',
    ): array {
        $routes = [];
        $namespace = rtrim($namespace, '\\') . '\\';
        $directory = rtrim($directory, '/\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            // ── Stage 1: filename pattern ────────────────────────────
            if ($filePattern !== '*.php' && !fnmatch($filePattern, $file->getFilename())) {
                continue;
            }

            // ── Stage 2: cheap content pre-check ─────────────────────
            // If the source file doesn't contain the PHP attribute opener `#[`
            // it cannot declare any #[Route] attributes, so skip it to avoid
            // the expensive class_exists() autoload.
            $contents = file_get_contents($file->getPathname());
            if ($contents === false || !str_contains($contents, '#[')) {
                continue;
            }

            // ── Stage 3: autoload + reflection gate ──────────────────
            // Build FQCN: namespace + relative path (without .php extension)
            $relativePath = substr($file->getPathname(), \strlen($directory) + 1);
            $relativePath = str_replace(['/', '\\'], '\\', $relativePath);
            $className = $namespace . substr($relativePath, 0, -4); // strip .php

            if (!class_exists($className)) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $reflection = new \ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            // Check that the class actually has #[Route] attributes before
            // doing the full (more expensive) route extraction.
            if (!$this->hasRouteAttributes($reflection)) {
                continue;
            }

            // Reuse the ReflectionClass we already have (avoids creating a
            // second instance inside loadFromClass).
            $classRoutes = $this->loadFromReflection($reflection);
            array_push($routes, ...$classRoutes);
        }

        return $routes;
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Extract routes from an already-reflected class.
     *
     * Shared implementation used by both {@see loadFromClass} and
     * {@see loadFromDirectory} to avoid creating duplicate ReflectionClass instances.
     *
     * @param \ReflectionClass<object> $reflection
     * @return list<Route>
     */
    private function loadFromReflection(\ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        $routes = [];

        // Read class-level #[Route] attributes (prefix + middleware)
        $classAttributes = $reflection->getAttributes(RouteAttribute::class);
        $classPrefix = '';
        $classMiddleware = [];
        $classPriority = 0;

        if ($classAttributes !== []) {
            // Use the first class-level attribute for prefix/middleware
            /** @var RouteAttribute $classRoute */
            $classRoute = $classAttributes[0]->newInstance();
            $classPrefix = rtrim($classRoute->path, '/');
            $classMiddleware = $classRoute->middleware;
            $classPriority = $classRoute->priority;
        }

        // Read method-level #[Route] attributes
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methodAttributes = $method->getAttributes(RouteAttribute::class);

            foreach ($methodAttributes as $attribute) {
                /** @var RouteAttribute $methodRoute */
                $methodRoute = $attribute->newInstance();

                $path = $classPrefix . '/' . ltrim($methodRoute->path, '/');
                // Normalise double slashes, but keep leading slash
                $normalised = preg_replace('#/{2,}#', '/', $path) ?? $path;
                $path = '/' . ltrim($normalised, '/');

                $routes[] = new Route(
                    pattern: $path,
                    methods: array_map('strtoupper', $methodRoute->methods),
                    handler: [$className, $method->getName()],
                    middleware: array_merge($classMiddleware, $methodRoute->middleware),
                    name: $methodRoute->name,
                    priority: $methodRoute->priority !== 0 ? $methodRoute->priority : $classPriority,
                );
            }
        }

        return $routes;
    }

    /**
     * Check whether a class or any of its public methods carry a `#[Route]` attribute.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function hasRouteAttributes(\ReflectionClass $reflection): bool
    {
        if ($reflection->getAttributes(RouteAttribute::class) !== []) {
            return true;
        }

        return array_any(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn ($method) => $method->getAttributes(RouteAttribute::class) !== []
        );

    }
}
