<?php

declare(strict_types=1);

namespace App\Modules;

use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\ExtensionContext;
use Nafinity\Support\Resolver;

/**
 * Everything Nafinity itself contributes, registered exactly once.
 *
 * These run before any installed extension, so a plugin that replaces one of
 * them replaces something that already exists, and the last explicit
 * registration is the one that wins.
 */
final class NafinityDefaults implements ExtensionProviderInterface
{
    /** @var list<class-string<ExtensionProviderInterface>> */
    private const array PROVIDERS = [
        CorePermissions::class,
        CoreFieldTypes::class,
        CoreEstimation::class,
        CoreActivity::class,
        CoreNavigation::class,
        CoreSettings::class,
    ];

    public function register(ExtensionContext $context): void
    {
        foreach (self::PROVIDERS as $provider) {
            Resolver::service($context->container(), $provider)->register($context);
        }
    }
}
