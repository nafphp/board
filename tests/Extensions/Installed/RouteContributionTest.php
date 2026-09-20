<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\route;

/**
 * Routes an extension adds, and what that does to the ones already there.
 *
 * A name is the identity of a route: adding another under the same name replaces
 * it, and that is the only promise. The same path under a different name is a
 * second route that will never be matched, because NAF matches in registration
 * order -- reachable by name and by url(), which is worth knowing rather than
 * discovering.
 */
final class RouteContributionTest extends ExtensionInstalledTestCase
{
    public function testAPluginRouteExistsAndTheCoreRoutesStillAnswer(): void
    {
        $routes = route()->all();

        $this->assertArrayHasKey('example.reports', $routes, 'the plugin route is missing');
        $this->assertArrayHasKey('board', $routes, 'a core route was lost');
        $this->assertArrayHasKey('health.live', $routes, 'a core callable route was lost');
        $this->assertSame('/projects/7/reports', route('example.reports', ['project' => 7]));
    }

    public function testAddingUnderTheSameNameReplacesAndRemoveTakesItBack(): void
    {
        $before = route()->all()['board'];

        try {
            route()->add('GET', '/projects/{project}', static fn() => null, 'board');
            $this->assertNotSame(
                $before['action'],
                route()->all()['board']['action'],
                'the override did not apply',
            );
        } finally {
            route()->add('GET', $before['path'], $before['action'], 'board');
        }
        $this->assertSame($before['action'], route()->all()['board']['action'], 'the restore failed');
    }

    public function testRemovingReportsWhetherThereWasAnythingToRemove(): void
    {
        route()->add('GET', '/example/removable', static fn() => null, 'example.removable');

        $this->assertTrue(route()->remove('example.removable'));
        $this->assertArrayNotHasKey('example.removable', route()->all(), 'the route survived remove');
        $this->assertFalse(route()->remove('example.removable'), 'removing nothing reported success');
    }

    public function testTheSamePathUnderAnotherNameDoesNotReplaceAnything(): void
    {
        $board = route()->all()['board'];
        route()->add('GET', $board['path'], static fn() => null, 'example.shadow');

        try {
            $this->assertSame(
                'board',
                route()->find('/projects/1', 'GET')['name'],
                'a later route took over the path',
            );
            $this->assertSame(
                '/projects/1',
                route('example.shadow', ['project' => 1]),
                'the shadowing route has no url of its own',
            );
        } finally {
            route()->add('GET', $board['path'], $board['action'], 'board');
            route()->remove('example.shadow');
        }
    }
}
