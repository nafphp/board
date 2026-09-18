<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Nafinity\ExtensionContext;

/**
 * A unit of extension definitions that runs after Nafinity's own defaults.
 *
 * Providers are trusted code from installed packages. They describe what a
 * plugin contributes; they never read request data or authorize anything.
 */
interface ExtensionProviderInterface
{
    /**
     * Register definitions for this extension
     *
     * @param ExtensionContext $context Container and registries of the booting application
     */
    public function register(ExtensionContext $context): void;
}
