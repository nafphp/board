<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Support\UiContext;

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
