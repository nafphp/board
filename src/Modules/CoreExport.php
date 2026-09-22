<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\ExporterDefinition;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Export\CsvExporter;
use Naf\Board\Export\JsonExporter;
use Naf\Board\ExtensionContext;
use Naf\Board\Modules\Providers\ExportSectionProvider;

/**
 * The two formats Nafinity itself can write.
 *
 * One for a person to open and one for a program to read, which between them
 * cover what an export is usually for. A third is a plugin writing one of these
 * lines; it gets the same rows, the same columns and the same export format
 * option without anything here changing.
 *
 * @internal
 */
final class CoreExport implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
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

        $exporters = $context->exporters();

        $exporters->add(new ExporterDefinition(
            id: 'csv',
            label: 'CSV-Tabelle',
            extension: 'csv',
            mimeType: 'text/csv; charset=utf-8',
            writer: CsvExporter::class,
            index: 100,
        ));

        $exporters->add(new ExporterDefinition(
            id: 'json',
            label: 'JSON',
            extension: 'json',
            mimeType: 'application/json; charset=utf-8',
            writer: JsonExporter::class,
            index: 200,
        ));
    }
}
