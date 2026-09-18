<?php

declare(strict_types=1);

namespace App\Modules;

use App\Ai\CoreToolProvider;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\AiToolProviderDefinition;
use Nafinity\ExtensionContext;

/**
 * Nafinity's own tools for the local chat, as one provider among others.
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
