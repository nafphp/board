<?php

declare(strict_types=1);

namespace Naf\Board\Registry;

use Naf\Board\Definition\ExporterDefinition;

/**
 * The formats a board can be exported in.
 *
 * Nafinity ships two. A plugin that adds a third writes one line and gets an
 * entry in the download menu, a working URL and the same rows the other two
 * see, without touching anything here.
 */
final class ExporterRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(ExporterDefinition::class, 'Exporter');
    }

    public function get(string $id): ?ExporterDefinition
    {
        return parent::get($id);
    }
}
