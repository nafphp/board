<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\AssetDefinition;
use Naf\Board\Definition\AssetPackage;
use Naf\Board\ExtensionContext;

/**
 * The assets Nafinity contributes through the registry.
 *
 * The application's own stylesheets and scripts keep their static tags and
 * their cache busters. What belongs here is what extensions rely on: the
 * browser side of the contribution API, which has to be on the page before a
 * contributed module can be mounted into it.
 *
 * @internal
 */
final class CoreAssets implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        // The board's stylesheets and scripts ship inside the package, and a host
        // serves files from its own document root. So they are published there
        // like any other package's -- but at the root rather than under
        // plugins/, because the templates address them as /assets/... and there
        // is only ever one application.
        $context->assetPackages()->add(new AssetPackage(
            'naf/board',
            // Inside src/, not a public/ of its own: a package has no document
            // root, it only contributes files to the host's.
            dirname(__DIR__) . '/Resources/public',
            0,
            true,
        ));

        $context->assets()->add(new AssetDefinition(
            'core.extensions',
            '/assets/extensions.js',
            'js',
            100,
            true,
        ));
    }
}
