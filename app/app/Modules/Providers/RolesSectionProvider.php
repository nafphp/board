<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use App\Support\Format;
use Nafinity\Contracts\SettingSectionProviderInterface;
use Nafinity\Definition\SettingSection;
use Nafinity\Support\UiContext;

use function Naf\I18n\t;

/** How many roles a project actually has. */
final class RolesSectionProvider implements SettingSectionProviderInterface
{
    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $custom = count($page['customRoles'] ?? []);

        return [
            'description' => $custom
                ? Format::count($custom, 'eigene Rolle', 'eigene Rollen')
                    . ' · ' . t('vier Standardrollen')
                : t('Vier Standardrollen, keine eigenen'),
        ];
    }
}
