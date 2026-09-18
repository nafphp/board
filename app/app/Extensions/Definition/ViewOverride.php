<?php

declare(strict_types=1);

namespace Nafinity\Definition;

/**
 * An explicit mapping from a logical view name to a different template.
 *
 * The id is the logical name Nafinity uses, for example `ticket` or
 * `ticket/field`; the template is what is rendered in its place.
 */
final readonly class ViewOverride
{
    /**
     * @param string $id       Logical view name being replaced
     * @param string $template Template rendered instead
     * @param int    $index    Sort value, ascending
     */
    public function __construct(public string $id, public string $template, public int $index = 100)
    {
    }
}
