<?php

declare(strict_types=1);

namespace App\Services;

use LogicException;
use Nafinity\Contracts\PageRendererInterface;
use Nafinity\Definition\NavigationItem;
use Nafinity\Definition\UiContribution;
use Nafinity\Support\SlotContextInterface;
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
     * The slot's own context is what a contribution is written against; it says
     * what this place offers. $extra carries whatever the surrounding view needs
     * for its own built-in templates, and a contribution's data provider still
     * wins over both.
     *
     * @param string               $slot    Slot name
     * @param SlotContextInterface $context What this slot hands its contributions
     * @param array                $extra   Data the surrounding view's own templates need
     */
    public function render(string $slot, SlotContextInterface $context, array $extra = []): string
    {
        $html = '';

        foreach ($this->items($slot, $context->ui()) as $entry) {
            $html .= $entry['kind'] === 'navigation'
                ? $this->navigation($entry['item'], $context->ui())
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

    private function template(
        UiContribution $contribution,
        array $data,
        SlotContextInterface $context,
    ): string {
        return $this->pages->fragment($contribution->template, [
            ...$data,
            'contribution' => $contribution,
            'slot'         => $context,
            'uiContext'    => $context->ui(),
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
