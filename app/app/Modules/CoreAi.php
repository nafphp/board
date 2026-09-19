<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Ai\CoreToolProvider;
use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\AiToolProviderDefinition;
use Naf\Board\ExtensionContext;

/**
 * Nafinity's own tools for the local chat, as one provider among others.
 *
 * @internal
 */
final class CoreAi implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $context->aiTools()->add(new AiToolProviderDefinition(
            'core.tools',
            CoreToolProvider::class,
            100,
        ));
    }
}
