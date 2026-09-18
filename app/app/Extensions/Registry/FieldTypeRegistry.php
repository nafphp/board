<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Contracts\FieldTypeInterface;

/**
 * The value types settings and ticket metadata share.
 *
 * The registry id is what the type itself reports, so a replacement always
 * answers under the same name a definition refers to.
 */
final class FieldTypeRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(FieldTypeInterface::class, 'Field type');
    }

    public function get(string $id): ?FieldTypeInterface
    {
        return parent::get($id);
    }

    protected function identify(object $definition): string
    {
        return $definition->id();
    }

    protected function indexOf(object $definition): int
    {
        return 100;
    }
}
