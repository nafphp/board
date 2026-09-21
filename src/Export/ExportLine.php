<?php

declare(strict_types=1);

namespace Naf\Board\Export;

/**
 * One record on its way out, and the one thing a listener may change about it.
 *
 * The ticket is the ticket as it is stored, and it is readonly. What a listener
 * edits is `$data` -- the row that will be written, which exists only for the
 * length of this export. So a plugin that reports a ticket differently to an
 * external system reports it differently; it does not quietly change what the
 * board says, which is the whole reason these are two things and not one.
 *
 * `$exporter` is here because "differently" is usually only true of one format.
 * A listener that rewrote a value for every export would also rewrite it for the
 * spreadsheet the team reads, and that is not what anybody asked for.
 *
 * Mutable on purpose, and the only mutable event payload in this application.
 * The alternative -- a listener returning a new line, the dispatcher threading
 * the result through -- would need the event system to care about return values
 * in a way it deliberately does not.
 */
final class ExportLine
{
    /**
     * @param string $exporter Id of the format being written
     * @param int    $project  The project being exported
     * @param array  $ticket   The stored ticket, joined with its column and swimlane
     * @param array  $data     Column key to value, as it will be written
     */
    public function __construct(
        public readonly string $exporter,
        public readonly int $project,
        public readonly array $ticket,
        public array $data,
    ) {
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
