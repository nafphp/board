<?php

declare(strict_types=1);

namespace Naf\Board\Export;

/**
 * An export is about to be written.
 *
 * Dispatched after the format and the columns are settled and before the first
 * record, which is the moment a plugin can open whatever it needs open -- a
 * connection to the system the records are going to, a file, a run in somebody
 * else's ledger -- and know which format and which columns it is about to see.
 *
 * It cannot refuse. By the time this fires, the reader's permission has been
 * checked and the format exists; refusing here would be a rule somewhere no
 * rule belongs. Nothing here changes what is written either: a record is an
 * ExportLine, and that is where a value is changed.
 */
final readonly class ExportStarted
{
    /**
     * @param string $exporter Id of the format being written
     * @param int    $project  The project being exported
     * @param array  $columns  Column key to translated heading, as decided for this run
     */
    public function __construct(
        public string $exporter,
        public int $project,
        public array $columns,
    ) {
    }

    public function isFor(string $exporter): bool
    {
        return $this->exporter === $exporter;
    }
}
