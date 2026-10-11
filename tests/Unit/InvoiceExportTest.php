<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Domain\Failure;
use Naf\Board\Export\ExportLine;
use Naf\Board\Export\Invoice\EasybillExporter;
use Naf\Board\Export\Invoice\FastbillExporter;
use Naf\Board\Export\Invoice\LexwareExporter;
use Naf\Board\Export\Invoice\SevdeskExporter;
use Naf\Board\Export\InvoiceExportOptions;
use Naf\Board\Export\InvoiceItems;
use PHPUnit\Framework\TestCase;

final class InvoiceExportTest extends TestCase
{
    private function options(array $overrides = []): InvoiceExportOptions
    {
        return InvoiceExportOptions::fromInput($overrides + ['project' => '1', 'format' => 'invoice.csv', 'rate' => '123,45', 'tax' => '19']);
    }

    private function booking(int $id, int $minutes, string $title = 'Überprüfung'): array
    {
        return ['record' => ['id' => $id, 'ticket_id' => 7], 'data' => [
            'project'     => 'Kunde', 'key' => 'NAF-7', 'title' => $title, 'user' => 'Alice',
            'recorded_at' => '2026-10-10 12:00:00', 'minutes' => $minutes,
        ]];
    }

    public function testIntegerPricingAndGroupingKeepExactMinutesAndTitleSnapshots(): void
    {
        $options = $this->options();
        $this->assertSame(12345, $options->rateCents);
        $this->assertSame(1900, $options->taxBasisPoints);
        $rows = iterator_to_array((new InvoiceItems())->rows([
            $this->booking(1, 1), $this->booking(2, 2), $this->booking(3, 90, 'Renamed ticket'),
        ], $options));
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], $rows[0]['record']['booking_ids']);
        $this->assertSame(3, $rows[0]['data']['minutes']);
        $this->assertSame(0.05, $rows[0]['data']['quantity']);
        $this->assertSame(1.5, $rows[1]['data']['quantity']);
        $this->assertSame('HUR', $rows[0]['data']['unit']);
        $this->assertSame(0, $this->options(['rate' => '0', 'tax' => '0'])->rateCents);
    }

    public function testMalformedAndMissingPricingCannotBecomeZeroOrAnAssumedTaxRate(): void
    {
        foreach (['', '-1', '1e2', 'NaN', 'Infinity', '01', '1.234', '100000', [], null] as $bad) {
            try {
                $this->options(['rate' => $bad]);
                $this->fail('Invalid rate was accepted');
            } catch (Failure $error) {
                $this->assertSame(422, $error->status);
            }
        }
        foreach ([['tax' => '100.01'], ['project' => 'all'], ['format' => []], ['from' => '2026-02-30'], ['format' => 'invoice.lexware', 'tax' => '18']] as $bad) {
            try {
                $this->options($bad);
                $this->fail('Invalid selection was accepted');
            } catch (Failure $error) {
                $this->assertSame(422, $error->status);
            }
        }
    }

    public function testSevdeskRequiresTheActualAccountsHourUnitId(): void
    {
        $this->expectException(Failure::class);
        $this->options(['format' => 'invoice.sevdesk']);
    }

    public function testLexwareRoundsOnlyTheOutgoingQuantityToItsFourDecimalContract(): void
    {
        $rows = iterator_to_array((new InvoiceItems())->rows([$this->booking(1, 1)], $this->options()));
        $this->assertSame(0.016667, $rows[0]['data']['quantity']);
        $writer = new LexwareExporter();
        $line   = new ExportLine('invoice.lexware', 1, [], $rows[0]['data'], 'invoice-items');
        $json   = $writer->open([]) . $writer->line($line, []) . $writer->close();
        $item   = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['lineItems'][0];
        $this->assertSame(0.0167, $item['quantity']);
        $this->assertStringContainsString('1 min', $item['description']);
    }

    public function testLexwareRefusesAnInvoiceAboveItsDocumentedPositionLimitAndResetsForTheNextFile(): void
    {
        $rows   = iterator_to_array((new InvoiceItems())->rows([$this->booking(1, 90)], $this->options()));
        $line   = new ExportLine('invoice.lexware', 1, [], $rows[0]['data'], 'invoice-items');
        $writer = new LexwareExporter();
        $json   = $writer->open([]);
        for ($position = 0; $position < 300; ++$position) {
            $json .= $writer->line($line, []);
        }
        $this->assertCount(300, json_decode($json . $writer->close(), true, flags: JSON_THROW_ON_ERROR)['lineItems']);

        try {
            $writer->line($line, []);
            $this->fail('Lexware accepted more than 300 invoice items');
        } catch (Failure $error) {
            $this->assertSame(422, $error->status);
        }
        $json = $writer->open([]) . $writer->line($line, []) . $writer->close();
        $this->assertCount(1, json_decode($json, true, flags: JSON_THROW_ON_ERROR)['lineItems']);
    }

    public function testNativeApiPositionFixturesAndEmptyFiles(): void
    {
        $options = $this->options(['sevdesk_unity' => '4242']);
        $rows    = iterator_to_array((new InvoiceItems())->rows([$this->booking(1, 90)], $options));
        $data    = $rows[0]['data'];
        foreach ([
            [new LexwareExporter(), 'lineItems', ['type' => 'custom', 'name' => $data['name'], 'description' => $data['description'], 'quantity' => 1.5, 'unitName' => 'Stunde', 'unitPrice' => ['currency' => 'EUR', 'netAmount' => 123.45, 'taxRatePercentage' => 19]]],
            [new SevdeskExporter(), 'invoicePosSave', ['objectName' => 'InvoicePos', 'mapAll' => true, 'name' => $data['name'], 'text' => $data['description'], 'quantity' => 1.5, 'price' => 123.45, 'taxRate' => 19, 'unity' => ['id' => 4242, 'objectName' => 'Unity'], 'positionNumber' => 0]],
            [new EasybillExporter(), 'items', ['type' => 'POSITION', 'itemType' => 'SERVICE', 'number' => 'NAF-7', 'description' => $data['name'] . "\n" . $data['description'], 'quantity' => 1.5, 'unit' => 'Stunde', 'single_price_net' => 12345, 'vat_percent' => 19]],
            [new FastbillExporter(), 'ITEMS', ['ARTICLE_NUMBER' => 'NAF-7', 'DESCRIPTION' => $data['name'] . "\n" . $data['description'], 'QUANTITY' => 1.5, 'UNIT_PRICE' => 123.45, 'VAT_PERCENT' => 19]],
        ] as [$writer, $property, $expected]) {
            $line  = new ExportLine('fixture', 1, [], $data, 'invoice-items');
            $json  = $writer->open([]) . $writer->line($line, []) . $writer->line($line, []) . $writer->close();
            $items = json_decode($json, true, flags: JSON_THROW_ON_ERROR)[$property];
            $this->assertEquals($expected, $items[0]);
            $this->assertCount(2, $items);
            if ($writer instanceof SevdeskExporter) {
                $this->assertSame(1, $items[1]['positionNumber']);
            }
            $this->assertSame([$property => []], json_decode($writer->open([]) . $writer->close(), true, flags: JSON_THROW_ON_ERROR));
        }
    }
}
