<?php

declare(strict_types=1);

namespace Naf\Board\Export;

/** The authorized project dataset is about to be written; an exception aborts the file. */
final readonly class ExportStarted
{
    /**
     * @param string $exporter Id of the format being written
     * @param int    $project  The project being exported
     * @param array  $columns  Column key to translated heading, as decided for this run
     * @param string $source   Dataset id
     */
    public function __construct(
        public string $exporter,
        public int $project,
        public array $columns,
        public string $source = 'tickets',
    ) {
    }

    public function isFor(string $exporter): bool
    {
        return $this->exporter === $exporter;
    }
}
