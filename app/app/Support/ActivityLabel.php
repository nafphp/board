<?php

declare(strict_types=1);

namespace App\Support;

use function Naf\I18n\t;
use function Nafinity\extensions;

/**
 * What a recorded change is called in the history.
 *
 * A type nobody registered keeps its own name instead of being presented as
 * "project updated", which would be a confident wrong answer.
 */
final class ActivityLabel
{
    public static function for(string $type): string
    {
        $definition = extensions()->activityTypes()->get($type);

        return $definition === null ? $type : t($definition->label);
    }

    /** The Material symbol for a type, or none when it is unknown. */
    public static function icon(string $type): ?string
    {
        return extensions()->activityTypes()->get($type)?->icon;
    }
}
