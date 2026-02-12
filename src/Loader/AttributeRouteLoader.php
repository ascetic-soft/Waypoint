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
        $reflection = new \ReflectionClass($className);
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
     * Scan a directory for PHP files and load routes from all classes found.
     *
     * @param string $directory Absolute path to the directory to scan.
     * @param string $namespace Base namespace mapping to the directory (e.g. 'App\\Controllers\\').
     *
     * @return list<Route>
     */
    public function loadFromDirectory(string $directory, string $namespace): array
    {
        $routes = [];
        $namespace = rtrim($namespace, '\\') . '\\';
        $directory = rtrim($directory, '/\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Build FQCN: namespace + relative path (without .php extension)
            $relativePath = substr($file->getPathname(), strlen($directory) + 1);
            $relativePath = str_replace(['/', '\\'], '\\', $relativePath);
            $className = $namespace . substr($relativePath, 0, -4); // strip .php

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $classRoutes = $this->loadFromClass($className);
            array_push($routes, ...$classRoutes);
        }

        return $routes;
    }
}
