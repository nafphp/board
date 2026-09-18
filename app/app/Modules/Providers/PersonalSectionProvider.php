<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use App\Support\Locales;
use Nafinity\Contracts\SettingSectionProviderInterface;
use Nafinity\Definition\SettingSection;
use Nafinity\Support\UiContext;

use function Naf\I18n\t;

/** What the personal card says it holds right now. */
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
