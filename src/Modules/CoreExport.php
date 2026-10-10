<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\AssetDefinition;
use Naf\Board\Definition\ExporterDefinition;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Definition\UiContribution;
use Naf\Board\Export\CsvExporter;
use Naf\Board\Export\Invoice\EasybillDraftAdapter;
use Naf\Board\Export\Invoice\EasybillExporter;
use Naf\Board\Export\Invoice\FastbillDraftAdapter;
use Naf\Board\Export\Invoice\FastbillExporter;
use Naf\Board\Export\Invoice\LexwareDraftAdapter;
use Naf\Board\Export\Invoice\LexwareExporter;
use Naf\Board\Export\Invoice\SevdeskDraftAdapter;
use Naf\Board\Export\Invoice\SevdeskExporter;
use Naf\Board\Export\InvoiceItems;
use Naf\Board\Export\JsonExporter;
use Naf\Board\Export\PdfExporter;
use Naf\Board\Export\TextExporter;
use Naf\Board\ExtensionContext;
use Naf\Board\Modules\Providers\ExportSectionProvider;
use Naf\Board\Modules\Providers\InvoiceExportProvider;
use Naf\Board\Modules\Providers\PersonalExportProvider;
use Naf\Board\Support\UiContext;

/**
 * Shared formats and export surfaces. Each source supplies authorized rows to the same writers.
 *
 * @internal
 */
final class CoreExport implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $context->assets()->add(new AssetDefinition('core.invoice-drafts', '/assets/invoice-drafts.css', 'css', 105));
        $context->settingSections()->add(new SettingSection(
            id: 'project_export',
            scope: 'project',
            label: 'Export',
            template: 'settings/export',
            provider: ExportSectionProvider::class,
            index: 850,
            permission: 'export',
            icon: 'download',
        ));
        $context->settingSections()->add(new SettingSection(
            id: 'installation_export',
            scope: 'application',
            label: 'Export',
            template: 'settings/export',
            provider: ExportSectionProvider::class,
            index: 250,
            icon: 'download',
        ));

        $context->ui()->add(new UiContribution(
            id: 'core.personal_export',
            slot: 'profile.panels',
            template: 'profile/export',
            provider: PersonalExportProvider::class,
            index: 100,
            permission: null,
            modes: [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));

        $context->ui()->add(new UiContribution(
            id: 'core.invoice_export',
            slot: 'profile.panels',
            template: 'profile/invoice-export',
            provider: InvoiceExportProvider::class,
            index: 110,
            permission: null,
            modes: [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));

        $context->settingSections()->add(new SettingSection(
            id: 'invoice_drafts',
            scope: 'application',
            label: 'Rechnungsentwürfe',
            template: 'settings/invoice-drafts',
            index: 260,
            icon: 'download',
        ));

        $draftAdapters = [
            'invoice.lexware'  => LexwareDraftAdapter::class,
            'invoice.sevdesk'  => SevdeskDraftAdapter::class,
            'invoice.easybill' => EasybillDraftAdapter::class,
            'invoice.fastbill' => FastbillDraftAdapter::class,
        ];
        $exporters = $context->exporters();

        $exporters->add(new ExporterDefinition(
            id: 'csv',
            label: 'CSV-Tabelle',
            extension: 'csv',
            mimeType: 'text/csv; charset=utf-8',
            writer: CsvExporter::class,
            index: 100,
            sources: ['tickets', 'time'],
        ));

        $exporters->add(new ExporterDefinition(
            id: 'json',
            label: 'JSON',
            extension: 'json',
            mimeType: 'application/json; charset=utf-8',
            writer: JsonExporter::class,
            index: 200,
            sources: ['tickets', 'time'],
        ));

        $exporters->add(new ExporterDefinition(
            id: 'txt',
            label: 'Text',
            extension: 'txt',
            mimeType: 'text/plain; charset=utf-8',
            writer: TextExporter::class,
            index: 300,
            sources: ['tickets', 'time'],
        ));
        $exporters->add(new ExporterDefinition(
            id: 'pdf',
            label: 'PDF',
            extension: 'pdf',
            mimeType: 'application/pdf',
            writer: PdfExporter::class,
            index: 400,
            sources: ['time'],
        ));

        foreach ([
            ['invoice.csv', 'Rechnungspositionen – CSV', CsvExporter::class, 'csv', 'text/csv; charset=utf-8'],
            ['invoice.lexware', 'Lexware Office – API-Positionen (JSON)', LexwareExporter::class, 'json', 'application/json; charset=utf-8'],
            ['invoice.sevdesk', 'sevdesk – API-Positionen (JSON)', SevdeskExporter::class, 'json', 'application/json; charset=utf-8'],
            ['invoice.easybill', 'easybill – API-Positionen (JSON)', EasybillExporter::class, 'json', 'application/json; charset=utf-8'],
            ['invoice.fastbill', 'FastBill – API-Positionen (JSON)', FastbillExporter::class, 'json', 'application/json; charset=utf-8'],
        ] as $index => [$id, $label, $writer, $extension, $mime]) {
            $exporters->add(new ExporterDefinition($id, $label, $extension, $mime, $writer, 100 + $index, [InvoiceItems::SOURCE], $draftAdapters[$id] ?? null));
        }
    }
}
