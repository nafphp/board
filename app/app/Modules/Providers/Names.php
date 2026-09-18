<?php

declare(strict_types=1);

namespace App\Modules\Providers;

/** The short "a, b, c +2" summary the settings cards use. */
final class Names
{
    /**
     * @param array $items Rows with a `name` column
     */
    public static function of(array $items): string
    {
        $names = array_column($items, 'name');

        return implode(', ', array_slice($names, 0, 4))
            . (count($names) > 4 ? ' +' . (count($names) - 4) : '');
    }
}
