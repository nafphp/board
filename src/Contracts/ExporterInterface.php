<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use Naf\Board\Export\ExportLine;

/**
 * What a format has to be able to do.
 *
 * Three calls rather than one, because an export is written while it is
 * produced: a board with fifty thousand tickets should cost what one ticket
 * costs plus a file, not what fifty thousand tickets cost in memory. So a writer
 * is handed one line at a time and returns the text for it.
 *
 * A writer is built once per export and may keep state for that export -- which
 * is how a format that needs to know whether it has written a row yet knows it,
 * without a flag being passed into every call.
 */
interface ExporterInterface
{
    /**
     * Whatever comes before the rows: a header line, an opening bracket, nothing
     *
     * @param array<string, string> $columns Column key to translated heading
     */
    public function open(array $columns): string;

    /**
     * One record, after every listener has had its say about it
     *
     * @param ExportLine            $line    The record to write
     * @param array<string, string> $columns Column key to translated heading
     */
    public function line(ExportLine $line, array $columns): string;

    /** Whatever closes the document. */
    public function close(): string;
}
