<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\AiToolProviderDefinition;

/**
 * The sources of AI tools for the session chat.
 */
final class AiToolRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(AiToolProviderDefinition::class, 'AI tool provider');
    }

    public function get(string $id): ?AiToolProviderDefinition
    {
        return parent::get($id);
    }
}
