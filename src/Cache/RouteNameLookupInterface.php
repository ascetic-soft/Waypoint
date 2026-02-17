<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Cache;

/**
 * Provides route-name → index lookup for compiled route data.
 *
 * Segregated from {@see CompiledMatcherInterface} so that consumers needing
 * only name lookup (e.g. URL generators) do not depend on matching-related
 * methods.
 */
interface RouteNameLookupInterface
{
    /**
     * Find a route index by name — O(1) lookup via match expression.
     *
     * @return int|null Route index, or null if not found.
     */
    public function findByName(string $name): ?int;
}
