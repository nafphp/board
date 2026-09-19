<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Support\Locales;
use Naf\Board\Support\UiContext;

use function Naf\I18n\t;

/** What the personal card says it holds right now. *
 * @internal
 */
final class PersonalSectionProvider implements SettingSectionProviderInterface
{
    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $preferences = $page['preferences'] ?? [];
        $themes      = [
            'system' => t('Wie mein Gerät'),
            'light'  => t('Hell'),
            'dark'   => t('Dunkel'),
        ];

        return [
            'description' => implode(' · ', [
                $themes[$preferences['theme'] ?? 'system'] ?? ($preferences['theme'] ?? ''),
                Locales::name($preferences['locale'] ?? 'de'),
                $preferences['timezone'] ?? '',
            ]),
        ];
    }
}
