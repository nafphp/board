<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Export\ExportFinished;
use Naf\Board\Export\ExportLine;
use Naf\Board\Export\InvoiceExportOptions;
use Naf\Board\Services\InvoiceExportService;
use Naf\Board\Services\TimerService;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;
use function Naf\event;

final class InvoiceExportServiceTest extends BoardTestCase
{
    private function download(string $format = 'invoice.lexware', array $input = []): array
    {
        $file = app()->container()->make(InvoiceExportService::class)->write(InvoiceExportOptions::fromInput($input + [
            'project' => (string) $this->projectA, 'format' => $format, 'rate' => '80', 'tax' => '19', 'sevdesk_unity' => '4242',
        ]));

        try {
            return json_decode(stream_get_contents($file['stream']), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($file['stream']);
        }
    }

    private function book(int $ticket, int $minutes, string $date): void
    {
        $timers = app()->container()->make(TimerService::class);
        $timers->act($this->projectA, $ticket, ['action' => 'start']);
        $this->pdo->prepare("UPDATE ticket_timers SET started_at=NULL,tracked_seconds=?,state='paused' WHERE ticket_id=? AND user_id=?")
            ->execute([$minutes * 60, $ticket, $this->access->actor()]);
        $timers->act($this->projectA, $ticket, ['action' => 'stop']);
        $this->pdo->prepare('UPDATE ticket_time_entries SET recorded_at=? WHERE id=(SELECT max_id FROM (SELECT MAX(id) AS max_id FROM ticket_time_entries) latest)')->execute([$date]);
    }

    public function testEveryAdapterUsesTheSamePersonalScopeDatesAndWholeMinuteAggregation(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Invoice work']));
        $this->book($ticket, 60, '2026-10-10 00:00:00');
        $this->book($ticket, 30, '2026-10-10 23:59:59');
        $this->book($ticket, 120, '2026-10-11 00:00:00');
        $this->actAs($this->member);
        $this->book($ticket, 240, '2026-10-10 12:00:00');
        $this->actAs($this->alice);
        foreach (['invoice.lexware' => ['lineItems', 'quantity'], 'invoice.sevdesk' => ['invoicePosSave', 'quantity'],
            'invoice.easybill'      => ['items', 'quantity'], 'invoice.fastbill' => ['ITEMS', 'QUANTITY']] as $format => [$property, $quantity]) {
            $rows = $this->download($format, ['from' => '2026-10-10', 'until' => '2026-10-10'])[$property];
            $this->assertCount(1, $rows);
            $this->assertSame(1.5, $rows[0][$quantity]);
        }
        $this->assertDenied(404, fn() => $this->download(input: ['project' => (string) $this->projectB]));
        $this->assertDenied(404, fn() => $this->download('pdf'));
        $this->assertDenied(404, fn() => $this->download('invoice.missing'));
        $this->assertDenied(404, fn() => $this->download('datev'));
    }

    public function testCsvUsesStableHeadersFormulaProtectionAndSharedExportEvents(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->book($ticket, 90, '2026-10-10 12:00:00');
        $seen = [];
        event()->listen(ExportLine::class, static function (ExportLine $line) use (&$seen): void {
            if ($line->source === 'invoice-items') {
                $seen[]             = $line->record['booking_ids'];
                $line->data['name'] = '=1+1';
            }
        });
        $finished = [];
        event()->listen(ExportFinished::class, static function (ExportFinished $export) use (&$finished): void {
            if ($export->source === 'invoice-items') {
                $finished[] = $export->written;
            }
        });
        $file = app()->container()->make(InvoiceExportService::class)->write(InvoiceExportOptions::fromInput([
            'project' => (string) $this->projectA, 'format' => 'invoice.csv', 'rate' => '80', 'tax' => '0',
        ]));
        $csv = stream_get_contents($file['stream']);
        fclose($file['stream']);
        $this->assertStringContainsString('"minutes";"quantity";"unit";"net_rate";"tax_rate";"currency"', $csv);
        $this->assertStringContainsString('"\'=1+1"', $csv);
        $this->assertStringContainsString('"90";"1.5";"HUR";"80";"0";"EUR"', $csv);
        $this->assertCount(1, $seen);
        $this->assertCount(1, $seen[0]);
        $this->assertSame([1], $finished);
    }
}
