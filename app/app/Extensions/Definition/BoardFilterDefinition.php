<?php

declare(strict_types=1);

namespace Nafinity\Definition;

use Closure;
use Nafinity\Support\BoardFilterContext;
use Nafinity\Support\SqlCondition;

/**
 * One board filter, core or contributed.
 *
 * The normalized value is what the card query, the count and the filter chips
 * all use, so a filter can never mean one thing in the list and another in the
 * summary.
 */
final readonly class BoardFilterDefinition
{
    /**
     * @param string      $id        Filter id; plugin ids arrive as filters[<id>]
     * @param string      $label     Translated label for the filter chip
     * @param Closure     $normalize fn(mixed $value): mixed, throws Failure when invalid
     * @param Closure     $condition fn(mixed $value, BoardFilterContext $context): SqlCondition
     * @param int         $index     Sort value, ascending
     * @param string|null $view      Logical view name rendering the filter control
     */
    public function __construct(
        public string $id,
        public string $label,
        public Closure $normalize,
        public Closure $condition,
        public int $index = 100,
        public ?string $view = null,
    ) {
    }

    public function normalize(mixed $value): mixed
    {
        return ($this->normalize)($value);
    }

    public function condition(mixed $value, BoardFilterContext $context): SqlCondition
    {
        return ($this->condition)($value, $context);
    }
}
