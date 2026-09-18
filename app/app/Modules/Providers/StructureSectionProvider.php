<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use Nafinity\Contracts\SettingSectionProviderInterface;
use Nafinity\Definition\SettingSection;
use Nafinity\Support\UiContext;

use function Naf\I18n\t;

/**
 * The three board structure cards.
 *
 * They share one template and differ only in what they list, so the card id is
 * also the kind of item it edits.
 */
final class StructureSectionProvider implements SettingSectionProviderInterface
{
    /** Card id to the page key holding its items. */
    private const array ITEMS = [
        'column'   => 'columns',
        'swimlane' => 'swimlanes',
        'label'    => 'labels',
    ];

    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $items = $page[self::ITEMS[$section->id] ?? ''] ?? [];

        return [
            'kind'        => $section->id,
            'items'       => $items,
            'description' => $items ? Names::of($items) : t('Noch keine angelegt'),
        ];
    }
}
