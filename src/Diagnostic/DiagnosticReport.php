<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Diagnostic;

use AsceticSoft\Waypoint\Route;

/**
 * Immutable value object holding the results of route diagnostics.
 */
final class DiagnosticReport
{
    /**
     * @param list<array{0:Route,1:Route}>        $duplicatePaths   Pairs of routes with identical pattern + method.
     * @param array<string,list<Route>>            $duplicateNames   Routes sharing the same non-empty name.
     * @param list<array{shadowed:Route,by:Route}> $shadowedRoutes   Routes that will never match because a more general pattern precedes them.
     */
    public function __construct(
        public readonly array $duplicatePaths = [],
        public readonly array $duplicateNames = [],
        public readonly array $shadowedRoutes = [],
    ) {
    }

    /**
     * Whether any issues were detected.
     */
    public function hasIssues(): bool
    {
        return $this->duplicatePaths !== []
            || $this->duplicateNames !== []
            || $this->shadowedRoutes !== [];
    }
}
