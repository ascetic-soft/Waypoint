<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Fixture;

use AsceticSoft\Waypoint\Attribute\Route;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller with class-level #[Route] for prefix and middleware testing.
 */
#[Route('/api/users', middleware: ['AsceticSoft\Waypoint\Tests\Fixture\DummyMiddleware'])]
class GroupedController
{
    #[Route('/', methods: ['GET'], name: 'users.list')]
    public function list(): ResponseInterface
    {
        return new Response(200, [], 'user-list');
    }

    #[Route('/{id:\d+}', methods: ['GET'], name: 'users.show')]
    public function show(int $id): ResponseInterface
    {
        return new Response(200, [], "user:$id");
    }

    #[Route('/{id:\d+}', methods: ['DELETE'], name: 'users.delete')]
    public function delete(int $id): ResponseInterface
    {
        return new Response(200, [], "deleted-user:$id");
    }
}
