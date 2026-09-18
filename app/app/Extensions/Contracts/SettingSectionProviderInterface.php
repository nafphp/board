<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Nafinity\Definition\SettingSection;
use Nafinity\Support\UiContext;

/**
 * Supplies the summary line and the template data of one settings card.
 *
 * The data the page has already loaded is passed along, so a card that only
 * needs to phrase what is on screen does not query for it a second time.
 */
interface SettingSectionProviderInterface
{
    /**
     * @param SettingSection $section The card being rendered
     * @param UiContext      $context The authorized rendering context
     * @param array          $page    Data the settings page already loaded
     *
     * @return array Template data; a `description` key becomes the summary line
     */
    public function data(SettingSection $section, UiContext $context, array $page): array;
}
