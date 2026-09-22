<?php

declare(strict_types=1);

namespace Example\ExtensionA\Export;

use Naf\Board\Contracts\ExporterInterface;
use Naf\Board\Export\ExportLine;

/**
 * A third format, brought by a package the board has never heard of.
 *
 * It exists to show that the registry is the whole of what a format needs: this
 * class implements the same three calls the built-in two implement, is named in
 * one ExporterDefinition, and appears in the export settings with a working endpoint.
 * Nothing in naf/board mentions it.
 *
 * The format itself is deliberately dull -- one `key=value` line per ticket, as
 * an old system might want it. What matters is where the values come from.
 */
final class ExternalSystemExporter implements ExporterInterface
{
    public function open(array $columns): string
    {
        return "# nafinity export\n";
    }

    public function line(ExportLine $line, array $columns): string
    {
        $pairs = [];
        foreach (['key', 'status', 'title'] as $field) {
            $value = $line->data[$field] ?? '';
            $pairs[] = $field . '=' . (is_array($value) ? implode('|', $value) : (string) $value);
        }

        return implode(' ', $pairs) . "\n";
    }

    public function close(): string
    {
        return '';
    }
}
