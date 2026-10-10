<?php

declare(strict_types=1);

namespace Naf\Board\Export;

use Dompdf\Dompdf;
use Dompdf\Options;
use Naf\Board\Contracts\ExporterInterface;

use function Naf\I18n\t;

/** A local, Unicode PDF writer. No record is interpreted as HTML or fetched as a URL. */
final class PdfExporter implements ExporterInterface
{
    /** @var resource|null */
    private mixed $html  = null;
    private int $minutes = 0;
    private bool $time   = false;
    private int $written = 0;

    public function open(array $columns): string
    {
        $this->html = fopen('php://temp/maxmemory:4194304', 'w+b');
        fwrite($this->html, '<!doctype html><html><head><meta charset="utf-8"><style>
            @page { margin: 30pt 28pt 36pt; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #222; }
            h1 { font-size: 18pt; margin: 0 0 16pt; font-weight: normal; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            th { text-align: left; background: #eee; font-size: 7pt; }
            th, td { padding: 6pt 4pt; border-bottom: 1px solid #ddd; vertical-align: top; overflow-wrap: break-word; }
            thead { display: table-header-group; }
            .total { margin-top: 16pt; font-size: 11pt; }
            </style></head><body><h1>' . $this->escape(t('Datenexport')) . '</h1><table><thead><tr>');
        $weights = [];
        foreach ($columns as $key => $label) {
            $weights[$key] = match ($key) {
                'title'                  => 3,
                'project', 'recorded_at' => 2,
                'user'                   => 1.5,
                default                  => 1,
            };
        }
        $width = array_sum($weights);
        foreach ($columns as $key => $label) {
            fwrite($this->html, '<th style="width:' . (100 * $weights[$key] / $width) . '%">' . $this->escape($label) . '</th>');
        }
        fwrite($this->html, '</tr></thead><tbody>');

        return '';
    }

    public function line(ExportLine $line, array $columns): string
    {
        $this->written++;
        fwrite($this->html, '<tr>');
        foreach (array_keys($columns) as $key) {
            $value = $line->data[$key] ?? null;
            $value = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : (string) $value;
            fwrite($this->html, '<td>' . nl2br($this->escape($value)) . '</td>');
        }
        fwrite($this->html, '</tr>');
        if ($line->source === 'time') {
            $this->time = true;
            $this->minutes += (int) ($line->data['minutes'] ?? 0);
        }

        return '';
    }

    public function close(): string
    {
        fwrite($this->html, '</tbody></table>');
        if ($this->written === 0) {
            fwrite($this->html, '<p>' . $this->escape(t('Keine Datensätze im gewählten Zeitraum.')) . '</p>');
        }
        if ($this->time) {
            fwrite($this->html, '<p class="total">' . $this->escape(t('Gesamt: :hours Stunden (:minutes Minuten)', [
                'hours'   => number_format($this->minutes / 60, 2, '.', ''),
                'minutes' => (string) $this->minutes,
            ])) . '</p>');
        }
        fwrite($this->html, '</body></html>');
        rewind($this->html);
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        // Dompdf must not write font caches into a read-only Composer installation.
        $options->setFontCache(sys_get_temp_dir());
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'landscape');
        $pdf->loadHtml(stream_get_contents($this->html), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_text(760, 570, '{PAGE_NUM} / {PAGE_COUNT}', null, 8);

        return $pdf->output();
    }

    public function __destruct()
    {
        if (is_resource($this->html)) {
            fclose($this->html);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
