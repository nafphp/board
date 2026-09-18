<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\NavigationItem;
use Nafinity\Support\UiContext;

/**
 * The semantic menu entries of the sidebar slots.
 */
final class NavigationRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(NavigationItem::class, 'Navigation item');
    }

    public function get(string $id): ?NavigationItem
    {
        return parent::get($id);
    }

    /**
     * The entries of one slot the actor may actually see
     *
     * @param string    $slot    Slot name
     * @param UiContext $context The authorized rendering context
     *
     * @return list<NavigationItem>
     */
    public function forSlot(string $slot, UiContext $context): array
    {
        $visible = [];

        foreach ($this->all() as $item) {
            if ($item->slot === $slot && $context->allows($item->permission)) {
                $visible[] = $item;
            }
        }

        return $visible;
    }
}
