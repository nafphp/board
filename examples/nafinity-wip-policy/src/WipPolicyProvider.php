<?php

declare(strict_types=1);

namespace Example\WipPolicy;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\SettingDefinition;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Domain\Change;
use Naf\Board\ExtensionContext;
use Naf\Board\Support\Resolver;

use function Naf\event;
use function Naf\I18n\translation_paths;

/** Registration only; the listener makes the decision inside each native write. */
final class WipPolicyProvider implements ExtensionProviderInterface
{
    public const string KEY     = 'example.wip.enforce';
    public const string SECTION = 'example.wip';

    public function register(ExtensionContext $context): void
    {
        $context->settingSections()->add(new SettingSection(
            self::SECTION,
            'project',
            'WIP-Regeln',
            'settings/fields',
            index: 850,
            permission: 'manage',
            icon: 'view_week',
        ));
        $context->settings()->add(new SettingDefinition(
            self::KEY,
            'project',
            self::SECTION,
            'WIP-Grenzen verbindlich einhalten',
            'boolean',
            true,
            writePermission: 'manage',
        ));
        translation_paths()->add('example.wip', __DIR__ . '/lang');
        $container = $context->container();
        event()->listen(Change::class, static fn(Change $change) => Resolver::service($container, WipLimit::class)->check($change));
    }
}
