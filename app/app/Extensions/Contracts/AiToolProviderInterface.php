<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Nafinity\Support\AiToolContext;

/**
 * Supplies tools for one request of the local AI chat.
 */
interface AiToolProviderInterface
{
    /**
     * @param AiToolContext $context The authorized actor and optional project
     *
     * @return iterable<ProjectToolInterface>
     */
    public function tools(AiToolContext $context): iterable;
}
