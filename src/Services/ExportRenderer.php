<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\ExporterInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Export\ExportFinished;
use Naf\Board\Export\ExportLine;
use Naf\Board\Export\ExportStarted;
use Naf\Board\Support\Resolver;
use Throwable;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\event;
use function Naf\I18n\t;

/** Shared file lifecycle for authorized datasets, independent of their source. */
final class ExportRenderer
{
    /**
     * Sources authorize every project before calling this method; writers never query data.
     *
     * @param array<string, string> $columns
     * @param array<int, iterable<array{record: array, data: array}>> $groups Records by project
     * @return array{stream: resource, filename: string, mime: string}
     */
    public function write(string $format, string $source, array $columns, array $groups, string $basename): array
    {
        $definition = extensions()->exporters()->get($format);
        if ($definition === null || !in_array($source, $definition->sources, true)) {
            throw new Failure(t('Dieses Exportformat gibt es nicht: :format', ['format' => $format]), 404);
        }
        $writer = Resolver::build(app()->container(), $definition->writer);
        if (!$writer instanceof ExporterInterface) {
            throw new Failure(t('Das Exportformat :format kann nicht schreiben.', ['format' => $format]), 500);
        }
        $out = fopen('php://temp/maxmemory:' . (4 * 1024 * 1024), 'w+b');

        try {
            fwrite($out, $writer->open($columns));
            $counts = [];
            foreach ($groups as $project => $records) {
                event()->dispatch(new ExportStarted($format, $project, $columns, $source));
                $counts[$project] = 0;
                foreach ($records as $record) {
                    $line = new ExportLine($format, $project, $record['record'], $record['data'], $source);
                    event()->dispatch($line);
                    fwrite($out, $writer->line($line, $columns));
                    $counts[$project]++;
                }
            }
            fwrite($out, $writer->close());
            // No source reports success before the complete document was finalized.
            foreach ($counts as $project => $written) {
                event()->dispatch(new ExportFinished($format, $project, $columns, $written, $source));
            }
            rewind($out);
        } catch (Throwable $error) {
            fclose($out);
            throw $error;
        }

        return [
            'stream'   => $out,
            'mime'     => $definition->mimeType,
            'filename' => preg_replace('/[^a-z0-9-]+/', '-', strtolower($basename)) . '.' . $definition->extension,
        ];
    }
}
