<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Export\ExportLine;
use Naf\Board\Export\Invoice\Api;
use Naf\Board\Export\Invoice\EasybillDraftAdapter;
use Naf\Board\Export\Invoice\FastbillDraftAdapter;
use Naf\Board\Export\Invoice\LexwareDraftAdapter;
use Naf\Board\Export\Invoice\SevdeskDraftAdapter;
use Naf\Board\Rbac\Grants;
use Naf\Board\Rbac\Installation;
use Naf\Board\Services\InvoiceConnections;
use Naf\Board\Services\InvoiceDraftService;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Board\Tests\Support\InvoiceApiTransport;
use Naf\Client\Core\Client;
use Naf\Core\Config;
use Naf\Database\Core\Database;
use Naf\OAuth\Client\Token\Cipher;
use Naf\Rbac\Scope;

use function Naf\app;
use function Naf\config;
use function Naf\event;
use function Naf\Rbac\rbac;

final class InvoiceDraftServiceTest extends BoardTestCase
{
    protected bool $transactional = false;
    private InvoiceConnections $connections;
    private InvoiceDraftService $drafts;
    private InvoiceApiTransport $transport;
    private Client $originalClient;
    private Config $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $container                              = app()->container();
        $this->originalClient                   = $container->get(Client::class);
        $this->originalConfig                   = $container->get(Config::class);
        $settings                               = $this->originalConfig->all();
        $settings['nafinity']['exports']['key'] = Cipher::generateKey();
        $container->set(Config::class, new Config($settings));
        $this->transport = new InvoiceApiTransport();
        $container->set(Client::class, new Client([$this->transport]));
        $this->resetAdapters();
        $this->connections = $container->make(InvoiceConnections::class);
        $this->drafts      = $container->make(InvoiceDraftService::class);
    }

    protected function tearDown(): void
    {
        app()->container()->set(Client::class, $this->originalClient);
        app()->container()->set(Config::class, $this->originalConfig);
        $this->resetAdapters();
        parent::tearDown();
    }

    private function resetAdapters(): void
    {
        foreach ([Api::class, LexwareDraftAdapter::class, SevdeskDraftAdapter::class, EasybillDraftAdapter::class, FastbillDraftAdapter::class] as $class) {
            app()->container()->reset($class);
        }
    }

    private function connection(string $provider = 'invoice.easybill', string $scope = 'personal'): int
    {
        return $this->connections->save(['provider' => $provider, 'scope' => $scope, 'company_name' => 'Sender GmbH', 'company_reference' => 'Company 42', 'api_key' => 'test-api-key', 'email' => 'api@example.test']);
    }

    private function booking(int $minutes = 90): int
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->pdo->prepare('INSERT INTO ticket_time_entries(project_id,ticket_id,user_id,ticket_key,ticket_title,minutes,recorded_at) VALUES(?,?,?,?,?,?,?)')
            ->execute([$this->projectA, $ticket, $this->access->actor(), 'A-' . $ticket, 'Invoice work', $minutes, '2026-10-10 12:00:00']);

        return (int) $this->pdo->lastInsertId();
    }

    private function input(int $connection, array $changes = []): array
    {
        return $changes + ['connection' => (string) $connection, 'project' => (string) $this->projectA, 'rate' => '80', 'tax' => '19',
            'customer_id'               => '42', 'invoice_date' => '2026-10-10', 'service_from' => '2026-10-01', 'service_until' => '2026-10-09',
            'tax_case'                  => 'standard', 'title' => 'October work', 'sevdesk_unity' => '45', 'sevdesk_user' => '23', 'sevdesk_country' => '1'];
    }

    public function testEveryCoreAdapterUsesTheExistingWriterAndOnlyUnbilledPersonalBookings(): void
    {
        foreach (['invoice.lexware', 'invoice.easybill', 'invoice.sevdesk', 'invoice.fastbill'] as $provider) {
            $this->booking();
            $id      = $this->connection($provider);
            $changes = $provider === 'invoice.lexware' ? ['customer_id' => '12345678-1234-1234-1234-123456789012'] : [];
            $draft   = $this->drafts->prepare($this->input($id, $changes));
            $preview = $this->drafts->get($draft);
            $this->assertCount(1, $preview['snapshot']['rows']);
            $this->assertSame(90, $preview['snapshot']['rows'][0]['data']['minutes']);
            $this->assertSame('Example Customer GmbH', $preview['snapshot']['customer']);
            $before = count($this->transport->calls);
            $this->assertSame('created', $this->drafts->send($draft)['state']);
            $this->assertSame('created', $this->drafts->send($draft)['state']);
            $this->assertCount($before + 1, $this->transport->calls, 'A repeat confirmation must not replay the write.');
        }
        $this->assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM invoice_booking_claims'));
    }

    public function testCompanyNeedsInstallationAndBoardRightsAndHasASeparateBillingPurpose(): void
    {
        $personal = $this->connection();
        $this->booking(60);
        $this->actAs($this->member);
        $this->booking(30);
        $this->assertDenied(403, fn() => $this->connection(scope: 'company'));
        $role = rbac()->roles->create('invoice-operator', 'Invoice operator', '', [Installation::MANAGE_SETTINGS]);
        rbac()->assignments->assign((int) $this->member->getId(), [$role], Scope::everywhere());
        rbac()->forget();
        $company = $this->connection(scope: 'company');
        $this->assertDenied(403, fn() => $this->drafts->prepare($this->input($company)));
        $this->actAs($this->alice);
        $this->assertCount(1, $this->connections->available(), 'An ordinary user must not see shared company credentials.');
        $this->assertDenied(404, fn() => $this->connections->get($company));
        $own = $this->drafts->prepare($this->input($personal));
        $this->assertSame(60, $this->drafts->get($own)['snapshot']['rows'][0]['data']['minutes']);
        $this->drafts->send($own);
        rbac()->assignments->assign((int) $this->alice->getId(), [$role], Scope::everywhere());
        rbac()->forget();
        $all  = $this->drafts->prepare($this->input($company));
        $rows = $this->drafts->get($all)['snapshot']['rows'];
        $this->assertCount(2, $rows);
        $this->assertSame(90, array_sum(array_column(array_column($rows, 'data'), 'minutes')));
        $this->assertSame('created', $this->drafts->send($all)['state']);
        $this->assertDenied(404, fn() => $this->drafts->prepare($this->input($company, ['project' => (string) $this->projectB])));
        $this->actAs($this->bob);
        $this->assertDenied(404, fn() => $this->drafts->get($own));
    }

    public function testReservationIsCommittedBeforeNetworkAndOverlappingPreviewsCannotSendTwice(): void
    {
        $this->booking();
        $connection                = $this->connection();
        $first                     = $this->drafts->prepare($this->input($connection));
        $second                    = $this->drafts->prepare($this->input($connection));
        $reader                    = (new Database(config('database')))->getConnection();
        $this->transport->onCreate = function () use ($reader, $second): void {
            $this->assertFalse($this->pdo->inTransaction());
            $this->assertSame(1, (int) $reader->query('SELECT COUNT(*) FROM invoice_booking_claims')->fetchColumn());
            $this->assertDenied(409, fn() => $this->drafts->send($second));
        };
        $this->assertSame('created', $this->drafts->send($first)['state']);
        $this->assertCount(3, $this->transport->calls, 'Two customer lookups and just one create.');
        $this->assertDenied(422, fn() => $this->drafts->prepare($this->input($connection)));
    }

    public function testUncertainResultsStayReservedUntilAnExplicitCheckedResolution(): void
    {
        $this->booking();
        $connection                  = $this->connection();
        $first                       = $this->drafts->prepare($this->input($connection));
        $second                      = $this->drafts->prepare($this->input($connection));
        $this->transport->failCreate = true;
        $this->assertSame('uncertain', $this->drafts->send($first)['state']);
        $this->assertDenied(409, fn() => $this->drafts->send($first));
        $this->assertDenied(409, fn() => $this->drafts->send($second));
        $this->assertDenied(422, fn() => $this->drafts->resolve($first, ['resolution' => 'not_created']));
        $this->assertDenied(409, fn() => $this->drafts->resolve($first, ['confirmation' => 'checked', 'resolution' => 'not_created']));
        $this->pdo->prepare('UPDATE invoice_drafts SET attempted_at=? WHERE id=?')->execute([time() - 90, $first]);
        $this->drafts->resolve($first, ['confirmation' => 'checked', 'resolution' => 'not_created']);
        $this->assertSame('released', $this->drafts->get($first)['state']);
        $this->transport->failCreate = false;
        $this->assertSame('created', $this->drafts->send($second)['state']);
        $this->assertCount(4, $this->transport->calls);
    }

    public function testSecretsAreEncryptedBoundToTheRowAndNeverReturnedInConnectionListings(): void
    {
        $first  = $this->connection();
        $second = $this->connection();
        $stored = $this->scalar('SELECT credentials FROM invoice_connections WHERE id=?', [$first]);
        $this->assertStringNotContainsString('test-api-key', $stored);
        $this->assertStringStartsWith('x1:', $stored);
        $this->assertStringNotContainsString('credentials', json_encode($this->connections->available()));
        $this->pdo->prepare('UPDATE invoice_connections SET credentials=? WHERE id=?')->execute([$stored, $second]);
        $this->assertDenied(503, fn() => $this->connections->credentials($second));
        $this->booking();
        $draft = $this->drafts->prepare($this->input($first));
        $this->connections->revoke($first);
        $this->assertSame('', $this->scalar('SELECT credentials FROM invoice_connections WHERE id=?', [$first]));
        $this->assertDenied(404, fn() => $this->drafts->send($draft));
        $this->actAs($this->bob);
        $this->assertDenied(404, fn() => $this->connections->get($second));
        $this->assertSame([], $this->connections->available());
    }

    public function testPreviewUsesSharedExportLineChangesAndSendRechecksCurrentProjectAccess(): void
    {
        $this->booking();
        $connection = $this->connection();
        event()->listen(ExportLine::class, static function (ExportLine $line): void {
            if ($line->source === 'invoice-items') {
                $line->data['name'] = 'Extension corrected title';
            }
        });
        $draft    = $this->drafts->prepare($this->input($connection));
        $snapshot = $this->drafts->get($draft)['snapshot'];
        $this->assertSame('Extension corrected title', $snapshot['rows'][0]['data']['name']);
        $this->assertStringContainsString('Extension corrected title', $snapshot['payload']['items'][0]['description']);
        $this->pdo->prepare('DELETE FROM project_members WHERE project_id=? AND user_id=?')->execute([$this->projectA, $this->alice->getId()]);
        Grants::inProject((int) $this->alice->getId(), $this->projectA, null);
        $this->assertDenied(404, fn() => $this->drafts->send($draft));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_booking_claims'));
    }

    public function testMissingEncryptionKeyAndInvalidInvoiceDetailsFailBeforeProviderWrites(): void
    {
        $this->booking();
        $connection = $this->connection();
        foreach ([['service_from' => '2026-02-30'], ['service_until' => '2026-09-01'], ['tax_case' => 'small_business'], ['tax' => '0'], ['customer_id' => '../../evil']] as $changes) {
            $this->assertDenied(422, fn() => $this->drafts->prepare($this->input($connection, $changes)));
        }
        $this->assertCount(0, $this->transport->calls);
        $draft = $this->drafts->prepare($this->input($connection));
        $this->pdo->prepare('UPDATE invoice_drafts SET created_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 3700), $draft]);
        $this->assertDenied(409, fn() => $this->drafts->send($draft));
        $settings = app()->container()->get(Config::class)->all();
        unset($settings['nafinity']['exports']['key']);
        app()->container()->set(Config::class, new Config($settings));
        $this->assertFalse($this->connections->ready());
        $this->assertDenied(503, fn() => $this->connection());
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoice_connections'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_booking_claims'));
    }
}
