<?php

declare(strict_types=1);

namespace Naf\Board\Registry;

use Naf\Board\Definition\BulkProperty;

final class BulkPropertyRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(BulkProperty::class, 'Bulk property');
    }

    public function get(string $id): ?BulkProperty
    {
        return parent::get($id);
    }

    protected function identify(object $definition): string
    {
        return $definition->id();
    }

    /** @return array<string, BulkProperty> */
    public function forResource(string $resource): array
    {
        $properties = [];
        foreach ($this->all() as $property) {
            if ($property->resource === $resource) {
                $properties[$property->key] = $property;
            }
        }

        return $properties;
    }
}
