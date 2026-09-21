<?php

declare(strict_types=1);

namespace Naf\Board\Definition;

use InvalidArgumentException;

/**
 * One way of getting a board's tickets out of Nafinity.
 *
 * A format is a definition rather than a branch in a controller, for the reason
 * every registry here exists: the page that offers the formats and the endpoint
 * that serves them both read this list, so a format cannot be offered and
 * missing, or present and unreachable.
 *
 * It also settles the question a second exporter usually raises. Whatever the
 * format, the rows are built once, in one service, and every format sees the
 * same rows -- so a listener that changes something for an export changes it
 * for all of them, rather than for whichever one the author remembered.
 */
final readonly class ExporterDefinition
{
    /**
     * @param string       $id        Format id, namespaced for plugins, used in the URL
     * @param string       $label     Translated name, as the download menu says it
     * @param string       $extension File extension without the dot
     * @param string       $mimeType  Content type the download is served with
     * @param class-string $writer    ExporterInterface implementation doing the writing
     * @param int          $index     Sort value, ascending
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $extension,
        public string $mimeType,
        public string $writer,
        public int $index = 100,
    ) {
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
