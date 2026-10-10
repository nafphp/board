<?php

declare(strict_types=1);

namespace Naf\Board\Registry;

use Naf\Board\Definition\ExporterDefinition;

/** Registered formats, filtered by the datasets their writers support. */
final class ExporterRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(ExporterDefinition::class, 'Exporter');
    }

    /** @return list<ExporterDefinition> */
    public function forSource(string $source): array
    {
        return array_values(array_filter($this->all(), static fn(ExporterDefinition $format): bool => in_array($source, $format->sources, true)));
    }

    public function get(string $id): ?ExporterDefinition
    {
        return parent::get($id);
    }
}
