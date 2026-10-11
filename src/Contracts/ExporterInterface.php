<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use Naf\Board\Export\ExportLine;

/**
 * A writer receives normalized records from any supported source. It is created once per
 * file; open and close run once, and line runs after export listeners. Streaming formats
 * return each chunk immediately; document renderers such as PDF may buffer until close.
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
