<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Export\InvoiceItems;
use Naf\Board\Services\TimeExportService;
use Naf\Board\Support\UiContext;

use function Naf\Board\extensions;
use function Naf\I18n\t;

final class InvoiceExportProvider implements UiDataProviderInterface
{
    public function __construct(private TimeExportService $time)
    {
    }

    public function data(UiContext $context): array
    {
        $formats = [];
        foreach (extensions()->exporters()->forSource(InvoiceItems::SOURCE) as $format) {
            $formats[$format->id] = t($format->label);
        }

        return ['invoiceFormats' => $formats, 'invoiceProjects' => $this->time->availableProjects()];
    }
}
