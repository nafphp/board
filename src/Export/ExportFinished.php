<?php

declare(strict_types=1);

namespace Naf\Board\Export;

/**
 * An export has been written, and this is how much of it there was.
 *
 * The other end of ExportStarted, dispatched after the last record and before
 * the file is handed over. What makes it worth having rather than leaving a
 * plugin to notice the last line is the count: a package reporting a transfer
 * elsewhere needs it, and counting lines itself would mean listening to every
 * one of them for a number the export already knows.
 *
 * It fires only for an export that finished. A failure raises, the download
 * never happens, and a listener that opened something at the start closes it
 * the way it closes anything else that can throw.
 */
final readonly class ExportFinished
{
    /**
     * @param string $exporter Id of the format that was written
     * @param int    $project  The project that was exported
     * @param array  $columns  Column key to translated heading, as decided for this run
     * @param int    $written  How many records went out
     */
    public function __construct(
        public string $exporter,
        public int $project,
        public array $columns,
        public int $written,
    ) {
    }

    public function isFor(string $exporter): bool
    {
        return $this->exporter === $exporter;
    }
}
