<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Definition\SettingDefinition;
use Naf\Board\ExtensionContext;
use Naf\Board\ExtensionRegistry;
use Naf\Board\Modules\CoreConfiguration;
use Naf\Core\Config;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class CoreConfigurationTest extends TestCase
{
    public function testNewHostEntriesBecomeTypedAdvancedFieldsWithoutSchemaRegistration(): void
    {
        $configuration = new Config([
            'custom_module' => ['enabled' => false, 'limit' => 0, 'steps' => ['one', 'two'], 'api_key' => 'private'],
            'mcp'           => ['server_url' => 'https://example.test/mcp'],
            'rbac'          => ['enabled' => true],
            'app'           => ['name' => 'Custom installation'],
        ]);
        $configuration->overlay('administration', ['custom_module:limit' => 42]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(Config::class)->willReturn($configuration);
        $registry = new ExtensionRegistry();
        $declared = new SettingDefinition('custom.enabled', 'application', 'application', 'Custom switch', 'boolean', true, configKey: 'custom_module:enabled');
        $registry->settings()->add($declared);

        (new CoreConfiguration())->register(new ExtensionContext($container, $registry));

        self::assertSame($declared, $registry->settings()->find('application', 'custom.enabled'));
        self::assertFalse($declared->advanced);
        self::assertNull($registry->settings()->find('application', 'config.custom_module:enabled'));
        $integer = $registry->settings()->find('application', 'config.custom_module:limit');
        self::assertSame('integer', $integer->type);
        self::assertSame(0, $integer->default);
        self::assertTrue($integer->advanced);
        self::assertSame('custom_module:limit', $integer->configKey);
        $list = $registry->settings()->find('application', 'config.custom_module:steps');
        self::assertSame('configuration_json', $list->type);
        self::assertSame(['one', 'two'], $list->default);
        self::assertTrue($registry->settings()->find('application', 'config.custom_module:api_key')->sensitive);
        self::assertSame('MCP', $registry->settingSections()->forScope('application')['configuration_mcp']->label);
        self::assertSame('RBAC', $registry->settingSections()->forScope('application')['configuration_rbac']->label);
        self::assertSame('Custom module', $registry->settingSections()->forScope('application')['configuration_custom_module']->label);
        self::assertSame('MCP · Server URL', $registry->settings()->find('application', 'config.mcp:server_url')->label);
        self::assertSame('boolean', $registry->settings()->find('application', 'config.rbac:enabled')->type);
        self::assertSame('Name der Anwendung', $registry->settings()->find('application', 'config.app:name')->label);
    }
}
