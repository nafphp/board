<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use LogicException;
use Nafinity\Definition\TicketFieldDefinition;

/**
 * Every ticket field, core adapters and contributed metadata alike.
 *
 * Title, description and comments are fixed product areas and are deliberately
 * absent here; trying to define them away is an error, not a contribution.
 */
final class TicketFieldRegistry extends DefinitionRegistry
{
    /** Ticket areas that are never fields. */
    public const array FIXED_AREAS = ['title', 'description', 'comments'];

    public function __construct()
    {
        parent::__construct(TicketFieldDefinition::class, 'Ticket field');
    }

    public function get(string $id): ?TicketFieldDefinition
    {
        return parent::get($id);
    }

    protected function identify(object $definition): string
    {
        return $definition->key;
    }

    protected function guardReserved(string $id, ?object $definition): void
    {
        if (in_array($id, self::FIXED_AREAS, true)) {
            throw new LogicException(sprintf(
                'The ticket area "%s" is a fixed part of Nafinity and cannot be defined, '
                . 'replaced or removed as a field.',
                $id,
            ));
        }
    }

    /**
     * Every field of one group, ordered by index and key
     *
     * @param string $group Group id
     *
     * @return array<string, TicketFieldDefinition>
     */
    public function forGroup(string $group): array
    {
        return array_filter(
            $this->all(),
            fn(TicketFieldDefinition $field) => $field->group === $group,
        );
    }

    /**
     * Only the fields stored in ticket_metadata
     *
     * @return array<string, TicketFieldDefinition>
     */
    public function metadata(): array
    {
        return array_filter($this->all(), fn(TicketFieldDefinition $field) => $field->isMetadata());
    }

    /**
     * Fail loudly when a field points at a group no panel provides
     *
     * @param list<string> $knownGroups Group ids backed by a registered panel
     */
    public function assertGroups(array $knownGroups): void
    {
        foreach ($this->all() as $field) {
            if (!in_array($field->group, $knownGroups, true)) {
                throw new LogicException(sprintf(
                    'Ticket field "%s" points at unknown group "%s". Register a panel for it '
                    . 'in the UI registry, known groups are: %s.',
                    $field->key,
                    $field->group,
                    implode(', ', $knownGroups),
                ));
            }
        }
    }
}
