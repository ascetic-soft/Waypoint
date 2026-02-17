<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Cache;

/**
 * Provides access to compiled route data by index.
 *
 * Segregated from {@see CompiledMatcherInterface} so that consumers needing
 * only route data (e.g. URL generators, diagnostics) do not depend on
 * matching-related methods.
 */
interface CompiledRouteProviderInterface
{
    /**
     * Get compact route data by index.
     *
     * @return array{h: array{0:class-string,1:string}|\Closure, M: array<string, true>, p: string, w?: list<string>, n?: string, P?: int, r?: string, N?: list<string>, a?: list<array<string, mixed>>|null}
     */
    public function getRoute(int $idx): array;

    /**
     * Total number of compiled routes.
     */
    public function getRouteCount(): int;

    /**
     * Indices of non-trie-compatible (fallback) routes.
     *
     * @return list<int>
     */
    public function getFallbackIndices(): array;
}
