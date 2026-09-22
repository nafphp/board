<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Rbac\Installation;
use Naf\Board\Services\ExportService;
use Naf\Board\Support\UiContext;

use function Naf\Board\extensions;
use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** @internal */
final class ExportSectionProvider implements SettingSectionProviderInterface
{
    public function __construct(private ExportService $exports)
    {
    }

    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $installation = $section->scope === 'application';
        if ($installation && !rbac()->allows($context->actorId, Installation::MANAGE_SETTINGS)) {
            return ['visible' => false];
        }
        $formats = [];
        foreach (extensions()->exporters()->all() as $exporter) {
            $formats[$exporter->id] = t($exporter->label);
        }

        return [
            'visible'     => $formats !== [],
            'description' => $installation
                ? t('Tickets aus einem oder allen Boards herunterladen.')
                : t('Tickets dieses Boards herunterladen.'),
            'exportFormats'      => $formats,
            'exportProjects'     => $installation ? $this->exports->availableProjects($page['projects']) : [],
            'installationExport' => $installation,
        ];
    }
}
