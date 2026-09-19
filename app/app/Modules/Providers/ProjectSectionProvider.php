<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Domain\Estimation;
use Naf\Board\Support\UiContext;

use function Naf\I18n\t;

/** What the project card says about the project it belongs to. */
final class ProjectSectionProvider implements SettingSectionProviderInterface
{
    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $project = $page['project'] ?? [];
        $scale   = Estimation::scale($project['estimation_scale'] ?? null);

        return [
            // Archiving lives in this card, so an owner of an archived project
            // still needs to reach it even though `manage` is barred there.
            'visible'     => $context->allows('manage') || $context->allows('restore'),
            'description' => implode(' · ', [
                ($project['archived_at'] ?? null) ? t('Archiviert') : t('Aktiv'),
                ($project['ticket_key'] ?? '') . '-1',
                t(Estimation::label($scale)),
            ]),
        ];
    }
}
