<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\PermissionDefinition;

/**
 * Every project permission a role may grant.
 */
final class PermissionRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(PermissionDefinition::class, 'Permission');
    }

    public function get(string $id): ?PermissionDefinition
    {
        return parent::get($id);
    }

    /**
     * Permissions a custom role may contain: everything except owner actions
     *
     * @return array<string, PermissionDefinition>
     */
    public function assignable(): array
    {
        return array_filter($this->all(), fn(PermissionDefinition $item) => !$item->ownerOnly);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
