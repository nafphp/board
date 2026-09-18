<?php

declare(strict_types=1);

namespace Nafinity\Support;

/**
 * One board filter fragment with its positional parameters.
 *
 * The fragment is trusted definition code; every value a person typed belongs
 * in the parameters. BoardQuery brackets the fragment and combines it with AND,
 * so a filter can never widen the project predicate.
 */
final readonly class SqlCondition
{
    /**
     * @param string $sql        SQL fragment using positional placeholders
     * @param array  $parameters Values bound to the placeholders, in order
     */
    public function __construct(public string $sql, public array $parameters = [])
    {
    }
}
