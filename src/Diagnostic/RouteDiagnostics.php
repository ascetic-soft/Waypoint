<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Diagnostic;

use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;

/**
 * Standalone diagnostics utility for inspecting routes and detecting conflicts.
 */
final readonly class RouteDiagnostics
{
    public function __construct(
        private RouteCollection $routes,
    ) {}

    /**
     * Print a human-readable table of all registered routes.
     *
     * @param resource $output Output stream (defaults to STDOUT).
     */
    public function listRoutes($output = null): void
    {
        $output ??= \STDOUT;
        $routes = $this->routes->all();

        if ($routes === []) {
            fwrite($output, "No routes registered.\n");

            return;
        }

        // Prepare rows
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                'Method' => implode('|', $route->getMethods()),
                'Path' => $route->getPattern(),
                'Name' => $route->getName(),
                'Handler' => $this->formatHandler($route),
                'Middleware' => $this->formatMiddleware($route),
            ];
        }

        // Calculate column widths
        $headers = ['Method', 'Path', 'Name', 'Handler', 'Middleware'];
        $widths = [];
        foreach ($headers as $h) {
            $widths[$h] = mb_strlen($h);
        }
        foreach ($rows as $row) {
            foreach ($headers as $h) {
                $widths[$h] = max($widths[$h], mb_strlen($row[$h]));
            }
        }

        // Build separator
        $sep = '+';
        foreach ($headers as $h) {
            $sep .= '-' . str_repeat('-', $widths[$h]) . '-+';
        }

        // Print table
        fwrite($output, $sep . "\n");

        // Header row
        $line = '|';
        foreach ($headers as $h) {
            $line .= ' ' . str_pad($h, $widths[$h]) . ' |';
        }
        fwrite($output, $line . "\n");
        fwrite($output, $sep . "\n");

        // Data rows
        foreach ($rows as $row) {
            $line = '|';
            foreach ($headers as $h) {
                $line .= ' ' . str_pad($row[$h], $widths[$h]) . ' |';
            }
            fwrite($output, $line . "\n");
        }

        fwrite($output, $sep . "\n");
    }

    /**
     * Detect duplicate paths, duplicate names, and shadowed routes.
     */
    public function findConflicts(): DiagnosticReport
    {
        $routes = $this->routes->all();

        $duplicatePaths = $this->findDuplicatePaths($routes);
        $duplicateNames = $this->findDuplicateNames($routes);
        $shadowedRoutes = $this->findShadowedRoutes($routes);

        return new DiagnosticReport($duplicatePaths, $duplicateNames, $shadowedRoutes);
    }

    /**
     * Print the full report: route list + conflicts.
     *
     * @param resource $output Output stream (defaults to STDOUT).
     */
    public function printReport($output = null): void
    {
        $output ??= \STDOUT;

        $this->listRoutes($output);
        fwrite($output, "\n");

        $report = $this->findConflicts();

        if (!$report->hasIssues()) {
            fwrite($output, "No issues found.\n");

            return;
        }

        if ($report->duplicatePaths !== []) {
            fwrite($output, "[WARNING] Duplicate paths:\n");
            foreach ($report->duplicatePaths as [$routeA, $routeB]) {
                fwrite($output, \sprintf(
                    "  - %s %s  →  %s  vs  %s\n",
                    implode('|', $routeA->getMethods()),
                    $routeA->getPattern(),
                    $this->formatHandler($routeA),
                    $this->formatHandler($routeB),
                ));
            }
            fwrite($output, "\n");
        }

        if ($report->duplicateNames !== []) {
            fwrite($output, "[WARNING] Duplicate names:\n");
            foreach ($report->duplicateNames as $name => $namedRoutes) {
                $handlers = array_map($this->formatHandler(...), $namedRoutes);
                fwrite($output, \sprintf(
                    "  - \"%s\"  →  %s\n",
                    $name,
                    implode('  vs  ', $handlers),
                ));
            }
            fwrite($output, "\n");
        }

        if ($report->shadowedRoutes !== []) {
            fwrite($output, "[WARNING] Shadowed routes:\n");
            foreach ($report->shadowedRoutes as $entry) {
                fwrite($output, \sprintf(
                    "  - %s %s  shadowed by  %s %s\n",
                    implode('|', $entry['shadowed']->getMethods()),
                    $entry['shadowed']->getPattern(),
                    implode('|', $entry['by']->getMethods()),
                    $entry['by']->getPattern(),
                ));
            }
            fwrite($output, "\n");
        }
    }

    // ── Private helpers ──────────────────────────────────────────

    private function formatHandler(Route $route): string
    {
        $handler = $route->getHandler();

        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        $class = $handler[0];
        // Show short class name
        $parts = explode('\\', $class);

        return end($parts) . '::' . $handler[1];
    }

    private function formatMiddleware(Route $route): string
    {
        $middleware = $route->getMiddleware();

        if ($middleware === []) {
            return '';
        }

        return implode(', ', array_map(static function (string $class): string {
            $parts = explode('\\', $class);

            return end($parts);
        }, $middleware));
    }

    /**
     * Find route pairs that share the same compiled regex and at least one HTTP method.
     *
     * @param list<Route> $routes
     *
     * @return list<array{0:Route,1:Route}>
     */
    private function findDuplicatePaths(array $routes): array
    {
        $duplicates = [];
        $count = \count($routes);

        foreach ($routes as $i => $iValue) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $iValue;
                $b = $routes[$j];

                if ($a->getCompiledRegex() !== $b->getCompiledRegex()) {
                    continue;
                }

                // Check for overlapping methods
                $sharedMethods = array_intersect($a->getMethods(), $b->getMethods());
                if ($sharedMethods !== []) {
                    $duplicates[] = [$a, $b];
                }
            }
        }

        return $duplicates;
    }

    /**
     * Find routes sharing the same non-empty name.
     *
     * @param list<Route> $routes
     *
     * @return array<string,list<Route>>
     */
    private function findDuplicateNames(array $routes): array
    {
        $byName = [];

        foreach ($routes as $route) {
            $name = $route->getName();
            if ($name !== '') {
                $byName[$name][] = $route;
            }
        }

        return array_filter($byName, static fn(array $group): bool => \count($group) > 1);
    }

    /**
     * Find routes that are shadowed by a more general pattern earlier in the list.
     *
     * A route is considered "shadowed" if an earlier route (higher or equal priority)
     * has a pattern that accepts a superset of the later route's matches and shares
     * at least one HTTP method.
     *
     * @param list<Route> $routes Already sorted by priority.
     *
     * @return list<array{shadowed:Route,by:Route}>
     */
    private function findShadowedRoutes(array $routes): array
    {
        $shadowed = [];
        $count = \count($routes);

        foreach ($routes as $i => $iValue) {
            for ($j = $i + 1; $j < $count; $j++) {
                $earlier = $iValue;
                $later = $routes[$j];

                // Must share at least one method
                if (array_intersect($earlier->getMethods(), $later->getMethods()) === []) {
                    continue;
                }

                // Check if the earlier pattern can match everything the later pattern matches
                // A simple heuristic: if both have the same number of segments and
                // the earlier one uses unconstrained placeholders where the later uses constrained ones
                if ($this->isShadowedBy($later, $earlier)) {
                    $shadowed[] = ['shadowed' => $later, 'by' => $earlier];
                }
            }
        }

        return $shadowed;
    }

    /**
     * Heuristic check: does route $a get shadowed by route $b?
     *
     * Route $b shadows $a when they have the same segment structure but $b uses
     * unconstrained placeholders where $a uses constrained ones.
     */
    private function isShadowedBy(Route $shadowed, Route $blocker): bool
    {
        $segmentsA = explode('/', trim($shadowed->getPattern(), '/'));
        $segmentsB = explode('/', trim($blocker->getPattern(), '/'));

        if (\count($segmentsA) !== \count($segmentsB)) {
            return false;
        }

        $hasWiderPlaceholder = false;

        foreach ($segmentsB as $i => $segB) {
            $segA = $segmentsA[$i];

            // Both static and equal — fine
            if ($segA === $segB) {
                continue;
            }

            $aIsParam = str_starts_with($segA, '{');
            $bIsParam = str_starts_with($segB, '{');

            // Blocker has an unconstrained placeholder, shadowed has a constrained one
            if ($bIsParam && $aIsParam) {
                $bHasConstraint = str_contains($segB, ':');
                $aHasConstraint = str_contains($segA, ':');

                if (!$bHasConstraint && $aHasConstraint) {
                    $hasWiderPlaceholder = true;
                    continue;
                }

                // Both unconstrained or both constrained differently — not a clear shadow
                if ($bHasConstraint === $aHasConstraint) {
                    continue;
                }

                // Blocker is constrained but shadowed is not — blocker is narrower
                return false;
            }

            // Blocker has a placeholder where shadowed has a static segment — blocker is wider
            if ($bIsParam && !$aIsParam) {
                $hasWiderPlaceholder = true;
                continue;
            }

            // Different static segments — no shadow
            return false;
        }

        return $hasWiderPlaceholder;
    }
}
