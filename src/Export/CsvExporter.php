<?php

declare(strict_types=1);

namespace Naf\Board\Export;

use Naf\Board\Contracts\ExporterInterface;

/**
 * The spreadsheet format, written the way spreadsheets actually read it.
 *
 * Two concessions to Excel, which is where these files go. A byte order mark,
 * without which a German ticket title arrives as MojibakeÃ¤. And a semicolon,
 * because Excel picks its separator from the locale and a comma-separated file
 * opened in a German Windows lands entirely in column A.
 *
 * A value that begins with =, +, - or @ is prefixed with a quote. A spreadsheet
 * reads those as formulas, and a ticket titled `=cmd|...` is a known way to run
 * something on the machine of whoever opens the export. Quoting it makes it text
 * again, which is what it always was.
 */
final class CsvExporter implements ExporterInterface
{
    private const string SEPARATOR = ';';

    /** Characters that turn a cell into a formula in every spreadsheet there is. */
    private const string DANGEROUS = "=+-@\t\r";

    public function open(array $columns): string
    {
        return "\u{FEFF}" . $this->row(array_values($columns));
    }

    public function line(ExportLine $line, array $columns): string
    {
        $cells = [];
        foreach (array_keys($columns) as $key) {
            $cells[] = $this->flatten($line->data[$key] ?? null);
        }

        return $this->row($cells);
    }

    public function close(): string
    {
        return '';
    }

    /** @param list<string> $cells */
    private function row(array $cells): string
    {
        return implode(self::SEPARATOR, array_map($this->quote(...), $cells)) . "\r\n";
    }

    private function quote(string $value): string
    {
        if ($value !== '' && str_contains(self::DANGEROUS, $value[0])) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * A cell holds one value, so anything else has to become one
     *
     * A list of labels or assignees is the common case and reads best comma
     * separated; the separator of the file itself is a semicolon precisely so
     * that this one does not have to be escaped away.
     */
    private function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map($this->flatten(...), $value));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) ($value ?? '');
    }
}
