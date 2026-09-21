<?php

declare(strict_types=1);

namespace Example\ExtensionA\Export;

use Naf\Board\Export\ExportLine;

/**
 * The case an export event exists for.
 *
 * This package keeps a state of its own on a ticket -- `example.reviewed`, which
 * is its business and not the board's. An external system knows nothing about
 * that state and has exactly two words for a ticket. So on the way out, and only
 * on the way out, a reviewed ticket is reported as done.
 *
 * Three things this does not do, and each of them is the point:
 *
 * It does not change the ticket. `$line->ticket` is readonly and the board still
 * says whatever the board said; what changes is one value in a row that exists
 * for the length of one download.
 *
 * It does not change every export. The spreadsheet the team reads still shows
 * the real status, because the first line here asks which format is being
 * written. A transformation that was true everywhere would not be a
 * transformation, it would be a lie about the data.
 *
 * And it needs nothing from naf/board but the event. No transformer registry, no
 * hook manager, no export extension point -- a listener on a documented event,
 * which is what this application means by an extension point.
 */
final class StatusForExternalSystem
{
    public const string FORMAT = 'example.external';

    /** The state this package puts on a ticket, and what the far end calls it. */
    private const string REVIEWED = 'example.reviewed';
    private const string DONE     = 'done';

    public function __invoke(ExportLine $line): void
    {
        if (!$line->isFor(self::FORMAT)) {
            return;
        }

        if (($line->data[self::REVIEWED] ?? false) === true) {
            $line->data['status'] = self::DONE;
        }
    }
}
