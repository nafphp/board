<?php

declare(strict_types=1);

namespace Naf\Board\Definition;

/**
 * One priority a ticket may carry.
 *
 * Registering one makes it choosable, filterable and storable. What it does not
 * do is change any ticket: the ones already there keep what they have, and a
 * priority whose extension is gone stays on its tickets and simply has no
 * label until it is back.
 */
final readonly class PriorityDefinition
{
    /**
     * @param string $id    Stored in tickets.priority, e.g. urgent
     * @param string $label Shown in the picker and on the card
     * @param string $icon  Material symbol drawn beside it
     * @param int    $index Sort value, ascending: least urgent first
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $icon = 'remove',
        public int $index = 100,
    ) {
    }
}
