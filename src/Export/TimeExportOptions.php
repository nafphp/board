<?php

declare(strict_types=1);

namespace Naf\Board\Export;

/** UTC booking dates use the same strict inclusive-day validation as ticket exports. */
final readonly class TimeExportOptions
{
    private function __construct(public ?string $from, public ?string $before)
    {
    }

    public static function fromInput(array $input): self
    {
        $dates = ExportOptions::fromInput([
            'updated_from'  => $input['from'] ?? '',
            'updated_until' => $input['until'] ?? '',
        ]);

        return new self($dates->updatedFrom, $dates->updatedBefore);
    }
}
