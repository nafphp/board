<?php

declare(strict_types=1);

namespace Naf\Board\Export;

use Naf\Board\Contracts\ExporterInterface;

/**
 * The format for another program to read.
 *
 * Written one record at a time rather than encoded in one go, so that exporting
 * a large board costs one ticket in memory and not all of them. That is the only
 * reason the brackets and commas are assembled by hand here; each record itself
 * goes through json_encode like anything else.
 *
 * Types survive, which is the point of offering this beside the spreadsheet: a
 * list of labels stays a list, a number stays a number, and an absent value
 * stays null rather than becoming an empty cell.
 */
final class JsonExporter implements ExporterInterface
{
    private bool $written = false;

    public function open(array $columns): string
    {
        return '[';
    }

    public function line(ExportLine $line, array $columns): string
    {
        $record = [];
        foreach (array_keys($columns) as $key) {
            $record[$key] = $line->data[$key] ?? null;
        }

        $text = ($this->written ? ",\n  " : "\n  ")
            . json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->written = true;

        return $text;
    }

    public function close(): string
    {
        return ($this->written ? "\n" : '') . "]\n";
    }
}
