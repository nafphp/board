<?php

declare(strict_types=1);

namespace Naf\Board\Export;

use Naf\Board\Contracts\ExporterInterface;

/** Human-readable UTF-8 records, with the same columns as every other format. */
final class TextExporter implements ExporterInterface
{
    public function open(array $columns): string
    {
        return '';
    }

    public function line(ExportLine $line, array $columns): string
    {
        $text = '';
        foreach ($columns as $key => $label) {
            $value = $line->data[$key] ?? null;
            $value = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (string) $value;
            $text .= $label . ': ' . str_replace(["\r\n", "\r", "\n"], "\n    ", $value) . "\n";
        }

        return $text . "\n";
    }

    public function close(): string
    {
        return '';
    }
}
