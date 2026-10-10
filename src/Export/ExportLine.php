<?php

declare(strict_types=1);

namespace Naf\Board\Export;

/**
 * One source record and its mutable outgoing values. The source distinguishes datasets;
 * the format distinguishes writers. Listeners should check both before mapping values.
 */
final class ExportLine
{
    /** The original source row, independent of the dataset. */
    public readonly array $record;

    /**
     * @param string $exporter Id of the format being written
     * @param int    $project  The project being exported
     * @param array  $ticket   Legacy alias of record; a ticket row for source=tickets, a booking for source=time
     * @param array  $data     Column key to value, as it will be written
     * @param string $source   Dataset id, defaulting to tickets for existing callers
     */
    public function __construct(
        public readonly string $exporter,
        public readonly int $project,
        public readonly array $ticket,
        public array $data,
        public readonly string $source = 'tickets',
    ) {
        $this->record = $ticket;
    }

    /**
     * Whether this line belongs to the named format
     *
     * A listener's first line is nearly always this, so it is offered rather
     * than left to everybody to write `$line->exporter === '...'` themselves.
     *
     * @param string $exporter Format id to compare against
     */
    public function isFor(string $exporter): bool
    {
        return $this->exporter === $exporter;
    }
}
