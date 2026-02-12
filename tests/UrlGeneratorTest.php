<?php

declare(strict_types=1);

namespace AsceticSoft\Waypoint\Tests;

use AsceticSoft\Waypoint\Exception\MissingParametersException;
use AsceticSoft\Waypoint\Exception\RouteNameNotFoundException;
use AsceticSoft\Waypoint\Route;
use AsceticSoft\Waypoint\RouteCollection;
use AsceticSoft\Waypoint\Router;
use AsceticSoft\Waypoint\UrlGenerator;
use AsceticSoft\Waypoint\Tests\Fixture\SimpleContainer;
use AsceticSoft\Waypoint\Tests\Fixture\TestController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class UrlGeneratorTest extends TestCase
{
    private RouteCollection $collection;
    private UrlGenerator $generator;

    protected function setUp(): void
    {
        $this->collection = new RouteCollection();
        $this->generator = new UrlGenerator($this->collection);
    }

    // ── Basic generation ────────────────────────────────────────

    #[Test]
    public function generateSimpleRouteWithoutParameters(): void
    {
        $this->collection->add(new Route('/about', ['GET'], ['C', 'm'], name: 'about'));

        $url = $this->generator->generate('about');

        self::assertSame('/about', $url);
    }

    #[Test]
    public function generateRouteWithOneParameter(): void
    {
        $this->collection->add(new Route('/users/{id}', ['GET'], ['C', 'm'], name: 'users.show'));

        $url = $this->generator->generate('users.show', ['id' => 42]);

        self::assertSame('/users/42', $url);
    }

    #[Test]
    public function generateRouteWithConstrainedParameter(): void
    {
        $this->collection->add(new Route('/users/{id:\d+}', ['GET'], ['C', 'm'], name: 'users.show'));

        $url = $this->generator->generate('users.show', ['id' => 99]);

        self::assertSame('/users/99', $url);
    }

    #[Test]
    public function generateRouteWithMultipleParameters(): void
    {
        $this->collection->add(new Route('/posts/{year:\d{4}}/{slug}', ['GET'], ['C', 'm'], name: 'posts.show'));

        $url = $this->generator->generate('posts.show', ['year' => 2025, 'slug' => 'hello-world']);

        self::assertSame('/posts/2025/hello-world', $url);
    }

    // ── Query string ────────────────────────────────────────────

    #[Test]
    public function generateRouteWithQueryString(): void
    {
        $this->collection->add(new Route('/users', ['GET'], ['C', 'm'], name: 'users.list'));

        $url = $this->generator->generate('users.list', query: ['page' => 2, 'limit' => 10]);

        self::assertSame('/users?page=2&limit=10', $url);
    }

    #[Test]
    public function generateRouteWithParametersAndQueryString(): void
    {
        $this->collection->add(new Route('/users/{id:\d+}/posts', ['GET'], ['C', 'm'], name: 'users.posts'));

        $url = $this->generator->generate('users.posts', ['id' => 5], ['sort' => 'date']);

        self::assertSame('/users/5/posts?sort=date', $url);
    }

    // ── URL encoding ────────────────────────────────────────────

    #[Test]
    public function parameterValuesAreUrlEncoded(): void
    {
        $this->collection->add(new Route('/search/{query}', ['GET'], ['C', 'm'], name: 'search'));

        $url = $this->generator->generate('search', ['query' => 'hello world']);

        self::assertSame('/search/hello%20world', $url);
    }

    #[Test]
    public function specialCharactersAreEncoded(): void
    {
        $this->collection->add(new Route('/tags/{tag}', ['GET'], ['C', 'm'], name: 'tags.show'));

        $url = $this->generator->generate('tags.show', ['tag' => 'c++']);

        self::assertSame('/tags/c%2B%2B', $url);
    }

    // ── Extra parameters are ignored ────────────────────────────

    #[Test]
    public function extraParametersAreIgnored(): void
    {
        $this->collection->add(new Route('/users/{id}', ['GET'], ['C', 'm'], name: 'users.show'));

        $url = $this->generator->generate('users.show', ['id' => 1, 'extra' => 'ignored']);

        self::assertSame('/users/1', $url);
    }

    // ── Error handling ──────────────────────────────────────────

    #[Test]
    public function throwsRouteNameNotFoundForUnknownName(): void
    {
        $this->expectException(RouteNameNotFoundException::class);
        $this->expectExceptionMessage('No route found with name "nonexistent".');

        $this->generator->generate('nonexistent');
    }

    #[Test]
    public function throwsMissingParametersWhenRequired(): void
    {
        $this->collection->add(new Route('/users/{id}/posts/{postId}', ['GET'], ['C', 'm'], name: 'user.post'));

        try {
            $this->generator->generate('user.post', ['id' => 1]);
            self::fail('Expected MissingParametersException');
        } catch (MissingParametersException $e) {
            self::assertSame(['postId'], $e->getMissing());
            self::assertStringContainsString('postId', $e->getMessage());
            self::assertStringContainsString('user.post', $e->getMessage());
        }
    }

    #[Test]
    public function throwsMissingParametersForAllMissing(): void
    {
        $this->collection->add(new Route('/users/{id}/posts/{postId}', ['GET'], ['C', 'm'], name: 'user.post'));

        try {
            $this->generator->generate('user.post');
            self::fail('Expected MissingParametersException');
        } catch (MissingParametersException $e) {
            self::assertCount(2, $e->getMissing());
            self::assertContains('id', $e->getMissing());
            self::assertContains('postId', $e->getMissing());
        }
    }

    // ── Router integration ──────────────────────────────────────

    #[Test]
    public function routerGenerateShortcut(): void
    {
        $container = new SimpleContainer();
        $router = new Router($container);
        $router->get('/users/{id:\d+}', static fn (): ResponseInterface => throw new \LogicException(), name: 'users.show');

        $url = $router->generate('users.show', ['id' => 42]);

        self::assertSame('/users/42', $url);
    }

    #[Test]
    public function routerGetUrlGeneratorReturnsSameInstance(): void
    {
        $container = new SimpleContainer();
        $router = new Router($container);

        $gen1 = $router->getUrlGenerator();
        $gen2 = $router->getUrlGenerator();

        self::assertSame($gen1, $gen2);
    }

    #[Test]
    public function generateWorksAfterLoadCache(): void
    {
        $cacheFile = sys_get_temp_dir() . '/waypoint_url_gen_test_' . uniqid() . '.php';

        try {
            $container = new SimpleContainer();
            $container->set(TestController::class, new TestController());

            // Build and compile
            $router1 = new Router($container);
            $router1->get('/users/{id:\d+}', [TestController::class, 'show'], name: 'users.show');
            $router1->compileTo($cacheFile);

            // Load from cache and generate URL
            $router2 = new Router($container);
            $router2->loadCache($cacheFile);

            $url = $router2->generate('users.show', ['id' => 7]);

            self::assertSame('/users/7', $url);
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }
}
