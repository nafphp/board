<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Domain\Failure;
use Naf\Board\Export\InvoiceExportOptions;
use Naf\Board\Export\InvoiceItems;

final class InvoiceExportService
{
    public function __construct(private TimeExportService $time, private ExportRenderer $renderer, private InvoiceItems $items)
    {
    }

    public function write(InvoiceExportOptions $options): array
    {
        return $this->render($options, $this->rows($options));
    }

    public function rows(InvoiceExportOptions $options, bool $company = false, array $excluded = [], ?int $limit = null): array
    {
        $groups = $this->time->bookings([$options->project], $options->dates, $company);
        foreach ($groups as $project => $bookings) {
            $available = (static function () use ($bookings, $excluded, $limit): iterable {
                $count = 0;
                foreach ($bookings as $booking) {
                    if (!isset($excluded[(int) $booking['record']['id']])) {
                        if ($limit !== null && ++$count > $limit) {
                            throw new Failure(\Naf\I18n\t('Bitte grenze den Export auf höchstens 300 Positionen und 5000 Buchungen ein.'), 422);
                        }
                        yield $booking;
                    }
                }
            })();
            $groups[$project] = $this->items->rows($available, $options);
        }

        return $groups;
    }

    /** The API and downloads use the same writers and ExportLine transformations. */
    public function render(InvoiceExportOptions $options, array $groups, ?callable $observe = null): array
    {

        // Stable machine headers; the same CSV writer also supplies formula protection.
        $columns = array_combine(
            ['project', 'ticket', 'name', 'description', 'person', 'booked_from', 'booked_until', 'minutes', 'quantity', 'unit', 'net_rate', 'tax_rate', 'currency'],
            ['project', 'ticket', 'name', 'description', 'person', 'booked_from', 'booked_until', 'minutes', 'quantity', 'unit', 'net_rate', 'tax_rate', 'currency'],
        );

        return $this->renderer->write($options->format, InvoiceItems::SOURCE, $columns, $groups, 'invoice-items-' . $options->project . '-' . $options->format . '-' . gmdate('Y-m-d'), $observe);
    }
}
