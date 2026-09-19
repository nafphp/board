<?php

declare(strict_types=1);

namespace Naf\Board\Definition;

use InvalidArgumentException;

/**
 * A selectable estimation scale.
 */
final readonly class EstimationScale
{
    /**
     * A value is stored as a number whatever the scale calls it, so a column can
     * still be summed and a ticket keeps its estimate when a project switches
     * scales. $names is what the number is shown as -- a scale that counts in
     * numbers leaves it empty and the number speaks for itself.
     *
     * @param string          $id     Stored scale id
     * @param string          $label  Translated label
     * @param string          $unit   Short unit shown next to a value
     * @param list<int>       $values Allowed values, unique and ascending
     * @param int             $index  Sort value, ascending
     * @param array<int, string> $names Value => what to show instead of the number
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $unit,
        public array $values,
        public int $index = 100,
        public array $names = [],
    ) {
        $unique = array_values(array_unique($values, SORT_NUMERIC));
        sort($unique, SORT_NUMERIC);

        if ($unique !== $values) {
            throw new InvalidArgumentException(
                'Estimation scale "' . $id . '" needs unique values in ascending order.',
            );
        }

        $unknown = array_diff(array_keys($names), $values);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Estimation scale "' . $id . '" names values it does not offer: '
                . implode(', ', $unknown),
            );
        }
    }
}
