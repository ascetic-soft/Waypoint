<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests\Fixture;

use AsceticSoft\Waypoint\Attribute\Route;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Simple controller for testing attribute-based routing.
 */
class TestController
{
    #[Route('/', methods: ['GET'], name: 'home')]
    public function index(): ResponseInterface
    {
        return new Response(200, [], 'index');
    }

    #[Route('/show/{id:\d+}', methods: ['GET'], name: 'show')]
    public function show(int $id): ResponseInterface
    {
        return new Response(200, [], "show:$id");
    }

    #[Route('/create', methods: ['POST'], name: 'create')]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(201, [], 'created');
    }

    #[Route('/update/{id:\d+}', methods: ['PUT'], name: 'update')]
    public function update(int $id, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], "updated:$id");
    }

    #[Route('/delete/{id:\d+}', methods: ['DELETE'], name: 'delete')]
    public function delete(int $id): ResponseInterface
    {
        return new Response(200, [], "deleted:$id");
    }
}
