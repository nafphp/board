<?php

declare(strict_types=1);

namespace App\Services;

use LogicException;
use Nafinity\Contracts\PageRendererInterface;
use Nafinity\Definition\NavigationItem;
use Nafinity\Definition\UiContribution;
use Nafinity\Support\UiContext;

use function Nafinity\extensions;

/**
 * Renders one named slot of the interface.
 *
 * Menu entries and templates are two ways of contributing to the same place,
 * so they are merged into one list, ordered by index and id together. Two
 * contributions cannot share an id, whichever kind they are.
 */
final class SlotRenderer
{
    public function __construct(private PageRendererInterface $pages)
    {
    }

    /**
     * The contributions of a slot the actor may see, in display order
     *
     * @param string    $slot    Slot name
     * @param UiContext $context The authorized rendering context
     *
     * @return list<array{id: string, index: int, kind: string, item: mixed, data: array}>
     */
    public function items(string $slot, UiContext $context): array
    {
        $entries = [];

        foreach (extensions()->navigation()->forSlot($slot, $context) as $item) {
            $entries[] = [
                'id'    => $item->id,
                'index' => $item->index,
                'kind'  => 'navigation',
                'item'  => $item,
                'data'  => [],
            ];
        }

        foreach (extensions()->ui()->forSlot($slot, $context) as $entry) {
            $contribution = $entry['contribution'];

            $entries[] = [
                'id'    => $contribution->id,
                'index' => $contribution->index,
                'kind'  => 'template',
                'item'  => $contribution,
                'data'  => $entry['data'],
            ];
        }

        $this->rejectCollisions($slot, $entries);

        usort(
            $entries,
            static fn(array $left, array $right) => $left['index'] <=> $right['index']
                ?: strcmp($left['id'], $right['id']),
        );

        return $entries;
    }

    /**
     * Render a slot into HTML
     *
     * A view that already holds the data its own contributions need passes it
     * as $extra; a contribution's own provider still wins over it.
     *
     * @param string    $slot    Slot name
     * @param UiContext $context The authorized rendering context
     * @param array     $extra   Data the surrounding view already has
     */
    public function render(string $slot, UiContext $context, array $extra = []): string
    {
        $html = '';

        foreach ($this->items($slot, $context) as $entry) {
            $html .= $entry['kind'] === 'navigation'
                ? $this->navigation($entry['item'], $context)
                : $this->template($entry['item'], [...$extra, ...$entry['data']], $context);
        }

        return $html;
    }

    private function navigation(NavigationItem $item, UiContext $context): string
    {
        return $this->pages->fragment('navigation/item', [
            'item'      => $item,
            'params'    => $item->parameters($context),
            'uiContext' => $context,
        ]);
    }

    private function template(UiContribution $contribution, array $data, UiContext $context): string
    {
        return $this->pages->fragment($contribution->template, [
            ...$data,
            'contribution' => $contribution,
            'uiContext'    => $context,
        ]);
    }

    /**
     * @param list<array{id: string, index: int, kind: string, item: mixed, data: array}> $entries
     */
    private function rejectCollisions(string $slot, array $entries): void
    {
        $seen = [];

        foreach ($entries as $entry) {
            if (isset($seen[$entry['id']])) {
                throw new LogicException(sprintf(
                    'Slot "%s" has two contributions with the id "%s": %s and %s.',
                    $slot,
                    $entry['id'],
                    $seen[$entry['id']],
                    $entry['kind'],
                ));
            }

            $seen[$entry['id']] = $entry['kind'];
        }
    }
}
