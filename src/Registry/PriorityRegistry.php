<?php

declare(strict_types=1);

namespace Naf\Board\Registry;

use Naf\Board\Definition\PriorityDefinition;

use function Naf\I18n\t;

/**
 * Every priority a ticket may be given.
 *
 * The list used to be written out in seven places -- the validation, the two
 * views, the filters, the tool schema the assistant reads, the seed and a CHECK
 * constraint. Adding one meant finding all seven, and the database knew better
 * than the code in one of them.
 */
final class PriorityRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(PriorityDefinition::class, 'Priority');
    }

    public function get(string $id): ?PriorityDefinition
    {
        return parent::get($id);
    }

    /** @return list<string> the ids, least urgent first */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * id => label, for a picker.
     *
     * Translated here rather than by each caller. A definition stores its label
     * untranslated, the way every other definition does, because registration
     * happens at boot and the locale is a property of the request -- but this
     * method exists to fill a menu, and an untranslated menu is not something a
     * caller ever wanted. Whoever needs the raw label asks `all()`.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(static fn(PriorityDefinition $item) => t($item->label), $this->all());
    }
}
