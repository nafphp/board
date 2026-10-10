<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Export\InvoiceExportOptions;
use Naf\Board\Export\InvoiceItems;

final class InvoiceExportService
{
    public function __construct(private TimeExportService $time, private ExportRenderer $renderer, private InvoiceItems $items)
    {
    }

    public function write(InvoiceExportOptions $options): array
    {
        $groups = $this->time->bookings([$options->project], $options->dates);
        foreach ($groups as $project => $bookings) {
            $groups[$project] = $this->items->rows($bookings, $options);
        }

        // Stable machine headers; the same CSV writer also supplies formula protection.
        $columns = array_combine(
            ['project', 'ticket', 'name', 'description', 'person', 'booked_from', 'booked_until', 'minutes', 'quantity', 'unit', 'net_rate', 'tax_rate', 'currency'],
            ['project', 'ticket', 'name', 'description', 'person', 'booked_from', 'booked_until', 'minutes', 'quantity', 'unit', 'net_rate', 'tax_rate', 'currency'],
        );

        return $this->renderer->write($options->format, InvoiceItems::SOURCE, $columns, $groups, 'invoice-items-' . $options->project . '-' . $options->format . '-' . gmdate('Y-m-d'));
    }
}
