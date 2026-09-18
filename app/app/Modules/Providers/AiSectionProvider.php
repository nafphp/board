<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use Nafinity\Contracts\SettingSectionProviderInterface;
use Nafinity\Definition\SettingSection;
use Nafinity\Support\UiContext;

use function Naf\I18n\t;

/**
 * The local AI card.
 *
 * Its model, prompts and history live in the browser, so the server can only
 * say that it does not know them.
 */
final class AiSectionProvider implements SettingSectionProviderInterface
{
    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        return ['description' => t('Noch nicht eingerichtet')];
    }
}
