<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Domain\Failure;
use Naf\Board\Export\Invoice\Api;
use Naf\Board\Export\Invoice\EasybillDraftAdapter;
use Naf\Board\Export\Invoice\FastbillDraftAdapter;
use Naf\Board\Export\Invoice\LexwareDraftAdapter;
use Naf\Board\Export\Invoice\SevdeskDraftAdapter;
use Naf\Board\Export\InvoiceExportOptions;
use Naf\Board\Export\InvoiceItems;
use Naf\Board\Tests\Support\InvoiceApiTransport;
use Naf\Client\Core\Client;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InvoiceDraftAdapterTest extends TestCase
{
    private function input(): array
    {
        return ['customer_id' => '42', 'invoice_date' => '2026-10-10', 'service_from' => '2026-10-01', 'service_until' => '2026-10-09',
            'tax'             => '19', 'tax_case' => 'standard', 'title' => 'October work', 'sevdesk_user' => '23', 'sevdesk_country' => '1'];
    }

    public function testAdaptersUseOnlyDocumentedDraftOperationsAndNativeAuthorization(): void
    {
        $transport   = new InvoiceApiTransport();
        $api         = new Api(new Client([$transport]));
        $credentials = ['api_key' => 'test-api-key', 'email' => 'api@example.test'];
        $cases       = [
            [new LexwareDraftAdapter($api), ['lineItems' => [['quantity' => 1.5]]], 'https://api.lexware.io/v1/invoices?finalize=false', 'Bearer test-api-key'],
            [new EasybillDraftAdapter($api), ['items' => [['quantity' => 1.5, 'single_price_net' => 8000]]], 'https://api.easybill.de/rest/v1/documents', 'Bearer test-api-key'],
            [new SevdeskDraftAdapter($api), ['invoicePosSave' => [['quantity' => 1.5]]], 'https://my.sevdesk.de/api/v1/Invoice/Factory/saveInvoice', 'test-api-key'],
            [new FastbillDraftAdapter($api), ['ITEMS' => [['QUANTITY' => 1.5, 'UNIT_PRICE' => 80]]], 'https://my.fastbill.com/api/1.0/api.php', 'Basic ' . base64_encode('api@example.test:test-api-key')],
        ];
        foreach ($cases as [$adapter, $positions, $url, $auth]) {
            $input = $this->input();
            if ($adapter instanceof LexwareDraftAdapter) {
                $input['customer_id'] = '12345678-1234-1234-1234-123456789012';
            }
            $this->assertSame('Example Customer GmbH', $adapter->customer($input['customer_id'], $credentials));
            $payload = $adapter->payload($positions, $input, 'Nafinity reference');
            $this->assertNotEmpty($adapter->create($payload, $credentials));
            $call = $transport->calls[array_key_last($transport->calls)];
            $this->assertSame($url, $call['url']);
            $this->assertSame('POST', $call['method']);
            $this->assertContains('Authorization: ' . $auth, $call['headerLines']);
            $this->assertSame(0, $call['config']['retries']);
            $this->assertSame(0, $call['config']['max_redirects']);
            $this->assertTrue($call['config']['ssl_verify']);
            if ($adapter instanceof SevdeskDraftAdapter) {
                $this->assertSame('100', $payload['invoice']['status']);
                $this->assertSame(['id' => '1', 'objectName' => 'TaxRule'], $payload['invoice']['taxRule']);
                $this->assertArrayNotHasKey('taxType', $payload['invoice']);
                $this->assertTrue($payload['takeDefaultAddress']);
            } elseif ($adapter instanceof EasybillDraftAdapter) {
                $this->assertArrayNotHasKey('is_draft', $payload, 'This field is read-only; POST creates drafts.');
                $this->assertSame('SERVICE', $payload['service_date']['type']);
                $this->assertSame(8000, $payload['items'][0]['single_price_net']);
            } elseif ($adapter instanceof FastbillDraftAdapter) {
                $this->assertSame('invoice.create', $payload['SERVICE']);
                $this->assertSame(0, $payload['DATA']['IS_GROSS']);
                $this->assertSame(80, $payload['DATA']['ITEMS'][0]['UNIT_PRICE']);
            } else {
                $this->assertSame('serviceperiod', $payload['shippingConditions']['shippingType']);
                $this->assertSame('net', $payload['taxConditions']['taxType']);
            }
        }
    }

    public function testNetworkFailuresRedirectsAndMalformedConfirmationsNeverReplayOrLeakSecrets(): void
    {
        foreach ([new RuntimeException('TLS timed out secret-key'), ['{}', ['HTTP/1.1 302 Found', 'Location: https://evil.example']], ['not json', ['HTTP/1.1 201 Created']], ['{"ERRORS":["secret-key"]}', ['HTTP/1.1 200 OK']]] as $response) {
            $transport            = new InvoiceApiTransport();
            $transport->responses = [$response];
            $adapter              = new FastbillDraftAdapter(new Api(new Client([$transport])));

            try {
                $adapter->create(['SERVICE' => 'invoice.create'], ['api_key' => 'secret-key', 'email' => 'api@example.test']);
                $this->fail('An ambiguous response was accepted');
            } catch (Failure $failure) {
                $this->assertSame(502, $failure->status);
                $this->assertStringNotContainsString('secret-key', $failure->getMessage());
                $this->assertNull($failure->getPrevious());
            }
            $this->assertCount(1, $transport->calls);
        }
    }

    public function testSmallBusinessRuleIsExplicitForEveryProvider(): void
    {
        $api   = new Api(new Client([new InvoiceApiTransport()]));
        $input = [...$this->input(), 'tax' => '0', 'tax_case' => 'small_business'];
        $this->assertSame('smallBusiness', (new EasybillDraftAdapter($api))->payload([], $input, 'ref')['vat_option']);
        $this->assertSame('small_business_regulation', (new FastbillDraftAdapter($api))->payload([], $input, 'ref')['DATA']['VAT_CASE']);
        $this->assertSame('11', (new SevdeskDraftAdapter($api))->payload([], $input, 'ref')['invoice']['taxRule']['id']);
        $input['customer_id'] = '12345678-1234-1234-1234-123456789012';
        $this->assertSame('vatfree', (new LexwareDraftAdapter($api))->payload([], $input, 'ref')['taxConditions']['taxType']);
    }

    public function testCompanyItemsKeepTwoAuthorsOnTheSameTicketSeparate(): void
    {
        $bookings = [];
        foreach ([1 => 'Alice', 2 => 'Bob'] as $user => $name) {
            $bookings[] = ['record' => ['id' => $user, 'user_id' => $user, 'ticket_id' => 10],
                'data'              => ['key' => 'NAF-10', 'title' => 'Shared task', 'project' => 'Company', 'user' => $name, 'minutes' => 60, 'recorded_at' => '2026-10-10 12:00:00']];
        }
        $options = InvoiceExportOptions::fromInput(['project' => '1', 'format' => 'invoice.easybill', 'rate' => '80', 'tax' => '19']);
        $rows    = iterator_to_array((new InvoiceItems())->rows($bookings, $options));
        $this->assertCount(2, $rows);
        $this->assertSame(['Alice', 'Bob'], array_column(array_column($rows, 'data'), 'person'));
        $this->assertSame([[1], [2]], array_column(array_column($rows, 'record'), 'booking_ids'));
    }
}
