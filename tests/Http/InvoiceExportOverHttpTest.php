<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use PDO;

use function Naf\app;

final class InvoiceExportOverHttpTest extends AcceptanceTestCase
{
    public function testCoreAddsTheCardAndDownloadFormatsOnlyToItsOwnSource(): void
    {
        $page = $this->page($this->alice, '/preferences');
        $this->assertStringContainsString('data-profile-section-id="invoice_export"', $page);
        $this->assertStringContainsString('action="/profile/invoice-export"', $page);
        $this->assertStringContainsString('API-Positionen (JSON)', $page);
        $this->assertStringContainsString('nicht im Datei-Import', $page);
        $this->assertSame(404, $this->alice->request('/profile/export?format=invoice.lexware')['status']);
    }

    public function testHttpDownloadPreservesActualAuthorAndRequiresExplicitPricing(): void
    {
        $url = $this->createTicket(['title' => 'Invoice HTTP hours']);
        $this->assertSame(200, $this->post($this->alice, $url . '/timer', ['action' => 'start'])['status']);
        app()->container()->get(PDO::class)->exec("UPDATE ticket_timers SET started_at=NULL,tracked_seconds=5400,state='paused' WHERE ticket_id=(SELECT id FROM tickets WHERE title='Invoice HTTP hours' AND project_id=1)");
        $this->assertSame(200, $this->post($this->alice, $url . '/timer', ['action' => 'stop'])['status']);
        $base   = '/profile/invoice-export?project=1&format=invoice.lexware&rate=80&tax=19';
        $answer = $this->alice->request($base);
        $this->assertSame(200, $answer['status']);
        $this->assertStringContainsString('application/json', $answer['headers']);
        $this->assertStringContainsString('attachment; filename="invoice-items-', $answer['headers']);
        $this->assertStringContainsString('private, no-store', $answer['headers']);
        $this->assertStringContainsString('nosniff', $answer['headers']);
        $items = json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR)['lineItems'];
        $this->assertCount(1, $items);
        $this->assertSame(1.5, $items[0]['quantity']);
        $this->assertSame(80, $items[0]['unitPrice']['netAmount']);
        $other = $this->viewer->request($base . '&user_id=1');
        $this->assertSame(['lineItems' => []], json_decode($other['body'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(404, $this->bob->request($base)['status']);
        foreach (['project=all&format=invoice.csv&rate=80&tax=19', 'project=1&format=invoice.csv&tax=19',
            'project=1&format=invoice.csv&rate=80', 'project=1&format=invoice.csv&rate[]=80&tax=19',
            'project=1&format=invoice.sevdesk&rate=80&tax=19'] as $invalid) {
            $bad = $this->alice->request('/profile/invoice-export?' . $invalid);
            $this->assertSame(422, $bad['status']);
            $this->assertStringNotContainsString('attachment;', $bad['headers']);
        }
        $this->assertSame(303, $this->guest('invoice-export-guest')->request($base)['status']);
    }
}
