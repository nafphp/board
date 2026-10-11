<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Definition\ExporterDefinition;
use Naf\Board\Export\CsvExporter;
use Naf\Board\Export\ExportLine;
use Naf\Board\Export\TextExporter;
use Naf\Board\Registry\ExporterRegistry;
use PHPUnit\Framework\TestCase;

final class ExportFormatsTest extends TestCase
{
    public function testExistingPluginsRemainTicketOnlyUntilTheyOptIntoAnotherDataset(): void
    {
        $registry = new ExporterRegistry();
        $registry->add(new ExporterDefinition('example.old', 'Old', 'csv', 'text/csv', CsvExporter::class));
        $registry->add(new ExporterDefinition('example.time', 'Time', 'csv', 'text/csv', CsvExporter::class, sources: ['time']));
        $this->assertSame(['example.old'], array_column($registry->forSource('tickets'), 'id'));
        $this->assertSame(['example.time'], array_column($registry->forSource('time'), 'id'));
    }

    public function testTextKeepsUnicodeAndIndentsMultilineValues(): void
    {
        $writer = new TextExporter();
        $line   = new ExportLine('txt', 1, ['id' => 7], ['title' => "Überprüfung\nzweite Zeile"], 'time');
        $this->assertSame('', $writer->open(['title' => 'Titel']));
        $this->assertSame("Titel: Überprüfung\n    zweite Zeile\n\n", $writer->line($line, ['title' => 'Titel']));
        $this->assertSame('', $writer->close());
        $this->assertSame($line->ticket, $line->record);
    }

    public function testTimeCsvRetainsTheExistingFormulaProtection(): void
    {
        $writer = new CsvExporter();
        $line   = new ExportLine('csv', 1, [], ['title' => '=1+1', 'minutes' => 90, 'hours' => 1.5], 'time');
        $this->assertSame("\"'=1+1\";\"90\";\"1.5\"\r\n", $writer->line($line, ['title' => 'Title', 'minutes' => 'Minutes', 'hours' => 'Hours']));
    }
}
