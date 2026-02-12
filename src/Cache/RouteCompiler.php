<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Cache;

use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;

/**
 * Compiles a {@see RouteCollection} into a PHP cache file for fast loading via opcache.
 *
 * The generated file returns a plain array of route data that can be restored
 * into Route objects without any Reflection or attribute parsing.
 */
final class RouteCompiler
{
    /**
     * Compile the route collection into a PHP file.
     *
     * @param RouteCollection $routes        The collection to compile.
     * @param string          $cacheFilePath Absolute path for the generated PHP file.
     */
    public function compile(RouteCollection $routes, string $cacheFilePath): void
    {
        $data = [];

        foreach ($routes->all() as $route) {
            $data[] = $route->toArray();
        }

        $dir = \dirname($cacheFilePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir));
            }
        }

        $content = '<?php return ' . var_export($data, true) . ";\n";

        // Atomic write: write to temp file then rename
        $tmpFile = $cacheFilePath . '.' . uniqid('', true) . '.tmp';
        file_put_contents($tmpFile, $content, LOCK_EX);
        rename($tmpFile, $cacheFilePath);

        // Invalidate opcache for the old file if opcache is available
        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFilePath, true);
        }
    }

    /**
     * Load a {@see RouteCollection} from a previously compiled cache file.
     *
     * @param string $cacheFilePath Absolute path to the compiled PHP file.
     */
    public function load(string $cacheFilePath): RouteCollection
    {
        if (!is_file($cacheFilePath)) {
            throw new \RuntimeException(\sprintf(
                'Route cache file "%s" does not exist.',
                $cacheFilePath,
            ));
        }

        /** @var list<array> $data */
        $data = include $cacheFilePath;

        $collection = new RouteCollection();

        foreach ($data as $item) {
            $collection->add(Route::fromArray($item));
        }

        return $collection;
    }

    /**
     * Check whether the cache file exists.
     */
    public function isFresh(string $cacheFilePath): bool
    {
        return is_file($cacheFilePath);
    }
}
