<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use PDO;

use function Naf\app;

final class InvoiceDraftsOverHttpTest extends AcceptanceTestCase
{
    private array $createdConnections = [];
    private array $createdBookings    = [];

    protected function tearDown(): void
    {
        $pdo = app()->container()->get(PDO::class);
        foreach ($this->createdConnections as $connection) {
            $pdo->prepare('DELETE FROM invoice_drafts WHERE connection_id=?')->execute([$connection]);
            $pdo->prepare('DELETE FROM invoice_connections WHERE id=?')->execute([$connection]);
        }
        foreach ($this->createdBookings as $booking) {
            $pdo->prepare('DELETE FROM ticket_time_entries WHERE id=?')->execute([$booking]);
        }
        parent::tearDown();
    }

    private function connect(): int
    {
        $answer = $this->post($this->alice, '/exports/invoices/connections', [
            'provider'          => 'invoice.easybill', 'scope' => 'personal', 'company_name' => 'HTTP Sender GmbH',
            'company_reference' => 'Company HTTP', 'api_key' => 'test-api-key',
        ]);
        $this->assertSame(200, $answer['status'], $answer['body']);
        parse_str(parse_url(json_decode($answer['body'], true)['url'], PHP_URL_QUERY), $query);

        $this->createdConnections[] = (int) $query['connection'];

        return (int) $query['connection'];
    }

    private function book(string $date): void
    {
        $url    = $this->createTicket(['title' => 'Direct invoice HTTP']);
        $ticket = (int) basename($url);
        app()->container()->get(PDO::class)->prepare('INSERT INTO ticket_time_entries(project_id,ticket_id,user_id,ticket_key,ticket_title,minutes,recorded_at) VALUES(1,?,1,?,?,90,?)')
            ->execute([$ticket, 'NAF-' . $ticket, 'Direct invoice HTTP', $date . ' 12:00:00']);
        $this->createdBookings[] = (int) app()->container()->get(PDO::class)->lastInsertId();
    }

    private function preview(int $connection, string $date): string
    {
        $answer = $this->post($this->alice, '/exports/invoices/preview', [
            'connection'  => (string) $connection, 'project' => '1', 'rate' => '80', 'tax' => '19', 'tax_case' => 'standard',
            'customer_id' => '42', 'invoice_date' => '2026-10-10', 'service_from' => '2026-10-01', 'service_until' => '2026-10-09',
            'title'       => 'October HTTP', 'from' => $date, 'until' => $date,
        ]);
        $this->assertSame(200, $answer['status'], $answer['body']);
        parse_str(parse_url(json_decode($answer['body'], true)['url'], PHP_URL_QUERY), $query);

        return $query['draft'];
    }

    public function testRealFormsRequireCsrfAndPreserveOwnerPrivacyAndImmutablePreview(): void
    {
        $page = $this->page($this->alice, '/exports/invoices');
        $this->assertStringContainsString('Rechnungszugang hinzufügen', $page);
        $this->assertStringContainsString('Rechnungsentwürfe öffnen', $this->page($this->alice, '/preferences'));
        $this->assertSame(400, $this->alice->request('/exports/invoices/connections', ['scope' => 'personal'])['status']);
        $this->assertSame(303, $this->guest('invoice-api-guest')->request('/exports/invoices')['status']);
        $connection = $this->connect();
        $this->book('2026-09-20');
        $draft = $this->preview($connection, '2026-09-20');
        $page  = $this->page($this->alice, '/exports/invoices?draft=' . $draft);
        $this->assertStringContainsString('Example Customer GmbH', $page);
        $this->assertStringContainsString('HTTP Sender GmbH', $page);
        $this->assertStringContainsString('Rechnungsentwurf erstellen', $page);
        $this->assertStringNotContainsString('test-api-key', $page);
        $this->assertSame(404, $this->bob->request('/exports/invoices?connection=' . $connection)['status']);
        $this->assertSame(404, $this->bob->request('/exports/invoices?draft=' . $draft)['status']);
        $answer = $this->post($this->alice, '/exports/invoices/send', ['draft' => $draft, 'customer_id' => '999', 'rate' => '1', 'project' => '2']);
        $this->assertSame(200, $answer['status']);
        $this->assertStringContainsString('private, no-store', $answer['headers']);
        $created = $this->page($this->alice, '/exports/invoices?draft=' . $draft);
        $this->assertStringContainsString('Entwurf erstellt', $created);
        $this->assertStringContainsString('Entwurfs-ID im Rechnungstool', $created);
        $this->assertStringNotContainsString('Rechnungsentwurf erstellen', $created);
        $snapshot = app()->container()->get(PDO::class)->query("SELECT snapshot FROM invoice_drafts WHERE id='$draft'")->fetchColumn();
        $payload  = json_decode($snapshot, true)['payload'];
        $this->assertSame(42, $payload['customer_id']);
        $this->assertSame(8000, $payload['items'][0]['single_price_net']);
        $this->assertSame(1.5, $payload['items'][0]['quantity']);
        $this->assertStringNotContainsString('test-api-key', $snapshot);
    }

    public function testConcurrentDifferentPreviewsClaimTheSameBookingOnlyOnce(): void
    {
        $connection = $this->connect();
        $this->book('2026-09-21');
        $first     = $this->preview($connection, '2026-09-21');
        $second    = $this->preview($connection, '2026-09-21');
        $twin      = $this->guest('invoice-api-alice-twin');
        $twinToken = $twin->login('alice@example.test', self::PASSWORD);
        $token     = $this->alice->token($this->page($this->alice, '/projects/1'));
        $log       = BASE_PATH . '/storage/invoice-api-calls.jsonl';
        $before    = is_file($log) ? count(file($log)) : 0;
        $answers   = HttpClient::together([
            $this->alice->prepare('/exports/invoices/send', ['draft' => $first], $this->alice->jsonHeaders($token)),
            $twin->prepare('/exports/invoices/send', ['draft' => $second], $twin->jsonHeaders($twinToken)),
        ]);
        $statuses = array_column($answers, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($answers));
        $this->assertCount($before + 1, file($log));
    }
}
