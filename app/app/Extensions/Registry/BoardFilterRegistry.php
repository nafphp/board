<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\BoardFilterDefinition;

/**
 * Every board filter the count, the cards and the filter chips share.
 */
final class BoardFilterRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(BoardFilterDefinition::class, 'Board filter');
    }

    public function get(string $id): ?BoardFilterDefinition
    {
        return parent::get($id);
    }
}
