<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Support\Format;
use Naf\Board\Support\UiContext;

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
