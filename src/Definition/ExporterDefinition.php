<?php

declare(strict_types=1);

namespace Naf\Board\Definition;

use InvalidArgumentException;

/** A file format and the datasets it can render, shared by menus and download endpoints. */
final readonly class ExporterDefinition
{
    /**
     * @param string       $id        Format id, namespaced for plugins, used in the URL
     * @param string       $label     Translated name, as the download menu says it
     * @param string       $extension File extension without the dot
     * @param string       $mimeType  Content type the download is served with
     * @param class-string $writer    ExporterInterface implementation doing the writing
     * @param int          $index     Sort value, ascending
     * @param list<string> $sources   Supported datasets; existing plugin writers default to tickets
     * @param class-string|null $draftAdapter Optional InvoiceDraftAdapterInterface for invoice-items delivery
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $extension,
        public string $mimeType,
        public string $writer,
        public int $index = 100,
        public array $sources = ['tickets'],
        public ?string $draftAdapter = null,
    ) {
        if ($sources === [] || array_filter($sources, static fn(mixed $source): bool => !is_string($source) || !preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/D', $source)) !== []) {
            throw new InvalidArgumentException('An exporter needs valid source ids.');
        }

        if ($id === '') {
            throw new InvalidArgumentException('An exporter needs a non-empty id.');
        }

        // The id reaches the filesystem through the download name and the router
        // through the URL. Both are somebody else's problem to escape; not
        // letting the character in is cheaper than remembering to.
        if (!preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $id)) {
            throw new InvalidArgumentException(
                'Exporter id "' . $id . '" may hold only lowercase letters, digits and . _ -',
            );
        }

        if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            throw new InvalidArgumentException(
                'Exporter "' . $id . '" needs a plain file extension, got "' . $extension . '".',
            );
        }
    }
}
