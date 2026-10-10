<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

use Naf\Board\Contracts\ExporterInterface;
use Naf\Board\Export\ExportLine;

/** Stream a provider's native positions property, without inventing invoice metadata. */
abstract class JsonItemsExporter implements ExporterInterface
{
    private bool $first = true;

    abstract protected function property(): string;

    abstract protected function item(array $data): array;

    public function open(array $columns): string
    {
        $this->first = true;

        return '{' . json_encode($this->property(), JSON_THROW_ON_ERROR) . ':[';
    }

    public function line(ExportLine $line, array $columns): string
    {
        $prefix      = $this->first ? '' : ',';
        $this->first = false;

        return $prefix . json_encode($this->item($line->data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function close(): string
    {
        return "]}\n";
    }
}
