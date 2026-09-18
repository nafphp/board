<?php

declare(strict_types=1);

namespace Nafinity\Definition;

use InvalidArgumentException;

/**
 * A selectable estimation scale.
 */
final readonly class EstimationScale
{
    /**
     * @param string     $id     Stored scale id
     * @param string     $label  Translated label
     * @param string     $unit   Short unit shown next to a value
     * @param list<int>  $values Allowed values, unique and ascending
     * @param int        $index  Sort value, ascending
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $unit,
        public array $values,
        public int $index = 100,
    ) {
        $unique = array_values(array_unique($values, SORT_NUMERIC));
        sort($unique, SORT_NUMERIC);

        if ($unique !== $values) {
            throw new InvalidArgumentException(
                'Estimation scale "' . $id . '" needs unique values in ascending order.',
            );
        }
    }
}
