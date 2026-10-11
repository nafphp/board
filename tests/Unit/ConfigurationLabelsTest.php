<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Settings\ConfigurationLabels;
use PHPUnit\Framework\TestCase;

final class ConfigurationLabelsTest extends TestCase
{
    public function testKnownAbbreviationsAndOrdinaryWordsShareOneDisplayRule(): void
    {
        foreach ([
            'mcp'              => 'MCP', 'rBaC' => 'RBAC', 'mcp:server_url' => 'MCP · Server URL',
            'rbac:apiKey'      => 'RBAC · API key', 'oauth2:client_id' => 'OAuth2 · Client ID',
            'SMTP:tls_mode'    => 'SMTP · TLS mode', 'custom_module:maxRetries' => 'Custom module · Max retries',
            'custom:serverURL' => 'Custom · Server URL', 'public_url' => 'Public URL',
            'unknown'          => 'Unknown',
        ] as $path => $expected) {
            self::assertSame($expected, ConfigurationLabels::label($path), $path);
        }
    }
}
