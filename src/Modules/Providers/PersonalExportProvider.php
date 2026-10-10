<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Services\TimeExportService;
use Naf\Board\Support\UiContext;

use function Naf\Board\extensions;
use function Naf\I18n\t;

/** @internal */
final class PersonalExportProvider implements UiDataProviderInterface
{
    public function __construct(private TimeExportService $exports)
    {
    }

    public function data(UiContext $context): array
    {
        $formats = [];
        foreach (extensions()->exporters()->forSource('time') as $format) {
            $formats[$format->id] = t($format->label);
        }

        return ['exportFormats' => $formats, 'exportProjects' => $this->exports->availableProjects()];
    }
}
