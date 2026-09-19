<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\AssetDefinition;
use Naf\Board\ExtensionContext;

/**
 * The assets Nafinity contributes through the registry.
 *
 * The application's own stylesheets and scripts keep their static tags and
 * their cache busters. What belongs here is what extensions rely on: the
 * browser side of the contribution API, which has to be on the page before a
 * contributed module can be mounted into it.
 */
final class CoreAssets implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $context->assets()->add(new AssetDefinition(
            'core.extensions',
            '/assets/extensions.js',
            'js',
            100,
            true,
        ));
    }
}
