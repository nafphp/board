<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Support\Branding;
use Naf\Board\Support\UiContext;

use function Naf\config;
use function Naf\I18n\t;

/** @internal */
final class BrandingSectionProvider implements SettingSectionProviderInterface
{
    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $home = t('Projektübersicht');
        foreach ($page['projects'] ?? [] as $project) {
            if ((int) $project['id'] === (int) config(Branding::HOME_PATH, 0) && empty($project['archived_at'])) {
                $home = $project['name'];
            }
        }

        return ['description' => Branding::name() . ' · ' . $home];
    }
}
