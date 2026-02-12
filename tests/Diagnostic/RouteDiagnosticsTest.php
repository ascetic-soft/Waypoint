<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Diagnostic;

use AsceticSoft\Waypoint\Diagnostic\DiagnosticReport;
use AsceticSoft\Waypoint\Diagnostic\RouteDiagnostics;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteDiagnosticsTest extends TestCase
{
    #[Test]
    public function listRoutesOutputsTable(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(
            '/users',
            ['GET'],
            ['App\\Controller\\UserController', 'list'],
            name: 'users.list',
        ));
        $collection->add(new Route(
            '/users/{id:\d+}',
            ['GET'],
            ['App\\Controller\\UserController', 'show'],
            ['App\\Middleware\\Auth'],
            'users.show',
        ));

        $diagnostics = new RouteDiagnostics($collection);
        $output = fopen('php://memory', 'r+');
        $diagnostics->listRoutes($output);

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        self::assertStringContainsString('Method', $content);
        self::assertStringContainsString('Path', $content);
        self::assertStringContainsString('/users', $content);
        self::assertStringContainsString('/users/{id:\d+}', $content);
        self::assertStringContainsString('users.list', $content);
        self::assertStringContainsString('users.show', $content);
        self::assertStringContainsString('UserController::list', $content);
        self::assertStringContainsString('Auth', $content);
    }

    #[Test]
    public function listRoutesHandlesEmptyCollection(): void
    {
        $collection = new RouteCollection();
        $diagnostics = new RouteDiagnostics($collection);

        $output = fopen('php://memory', 'r+');
        $diagnostics->listRoutes($output);

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        self::assertStringContainsString('No routes registered', $content);
    }

    #[Test]
    public function findConflictsDetectsDuplicatePaths(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users', ['GET'], ['App\\A\\UserController', 'list'], name: 'a'));
        $collection->add(new Route('/users', ['GET'], ['App\\B\\AdminController', 'users'], name: 'b'));

        $diagnostics = new RouteDiagnostics($collection);
        $report = $diagnostics->findConflicts();

        self::assertInstanceOf(DiagnosticReport::class, $report);
        self::assertTrue($report->hasIssues());
        self::assertCount(1, $report->duplicatePaths);
    }

    #[Test]
    public function findConflictsDetectsDuplicateNames(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/a', ['GET'], ['C', 'a'], name: 'same.name'));
        $collection->add(new Route('/b', ['GET'], ['C', 'b'], name: 'same.name'));

        $diagnostics = new RouteDiagnostics($collection);
        $report = $diagnostics->findConflicts();

        self::assertTrue($report->hasIssues());
        self::assertArrayHasKey('same.name', $report->duplicateNames);
        self::assertCount(2, $report->duplicateNames['same.name']);
    }

    #[Test]
    public function findConflictsDetectsShadowedRoutes(): void
    {
        $collection = new RouteCollection();
        // Unconstrained placeholder — more general
        $collection->add(new Route('/users/{name}', ['GET'], ['C', 'byName'], priority: 10));
        // Constrained — more specific, but shadowed because it comes after the general one
        $collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'byId'], priority: 0));

        $diagnostics = new RouteDiagnostics($collection);
        $report = $diagnostics->findConflicts();

        self::assertTrue($report->hasIssues());
        self::assertNotEmpty($report->shadowedRoutes);
    }

    #[Test]
    public function noIssuesReportWhenClean(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/users', ['GET'], ['C', 'list'], name: 'users.list'));
        $collection->add(new Route('/posts', ['GET'], ['C', 'list'], name: 'posts.list'));

        $diagnostics = new RouteDiagnostics($collection);
        $report = $diagnostics->findConflicts();

        self::assertFalse($report->hasIssues());
    }

    #[Test]
    public function printReportIncludesWarnings(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/a', ['GET'], ['C', 'a'], name: 'dup'));
        $collection->add(new Route('/b', ['GET'], ['C', 'b'], name: 'dup'));

        $diagnostics = new RouteDiagnostics($collection);

        $output = fopen('php://memory', 'r+');
        $diagnostics->printReport($output);

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        self::assertStringContainsString('[WARNING]', $content);
        self::assertStringContainsString('Duplicate names', $content);
        self::assertStringContainsString('"dup"', $content);
    }

    #[Test]
    public function printReportShowsNoIssuesWhenClean(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route('/only', ['GET'], ['C', 'm'], name: 'only'));

        $diagnostics = new RouteDiagnostics($collection);

        $output = fopen('php://memory', 'r+');
        $diagnostics->printReport($output);

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        self::assertStringContainsString('No issues found', $content);
    }
}
