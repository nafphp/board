<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Host;

use Naf\Board\Tests\Support\HttpClient;
use PHPUnit\Framework\TestCase;

use function Naf\route;

final class HostOverridesTest extends TestCase
{
    private const EXPECTED = [
        'providers'     => ['example.reports', 'example.review'],
        'provider_view' => 'example-b/reports',
        'route'         => 'host-extensions',
        'view'          => 'host/reports',
        'initialized'   => true,
        'final_route'   => 'host-routes',
    ];

    public function testCliBootCompletesProvidersBeforeHostOverridesAndRoutes(): void
    {
        $action = route()->all()['health.live']['action'];

        $this->assertSame(self::EXPECTED, json_decode((string) $action()->getBody(), true));
    }

    public function testHttpBootHasTheSameOrderAndEmitsTheHostRoute(): void
    {
        $response = (new HttpClient('http://127.0.0.1:8099', 'host-overrides'))->request('/health/live');

        $this->assertSame(200, $response['status']);
        $this->assertSame(self::EXPECTED, json_decode($response['body'], true));
    }
}
