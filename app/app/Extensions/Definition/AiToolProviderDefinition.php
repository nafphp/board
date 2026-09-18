<?php

declare(strict_types=1);

namespace Nafinity\Definition;

/**
 * A source of AI tools for the session chat.
 */
final readonly class AiToolProviderDefinition
{
    /**
     * @param string       $id           Stable provider id, namespaced for plugins
     * @param string       $provider     Container id of an AiToolProviderInterface
     * @param int          $index        Sort value, ascending
     * @param list<string> $replaceNames Tool names this provider may replace
     */
    public function __construct(
        public string $id,
        public string $provider,
        public int $index = 100,
        public array $replaceNames = [],
    ) {
    }
}
