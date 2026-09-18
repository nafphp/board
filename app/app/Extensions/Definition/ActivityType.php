<?php

declare(strict_types=1);

namespace Nafinity\Definition;

/**
 * A readable presentation for one recorded change type.
 */
final readonly class ActivityType
{
    /**
     * @param string      $id       Event type as stored in activities
     * @param string      $label    Translated sentence shown in the history
     * @param string|null $icon     Material symbol name
     * @param int         $index    Sort value, ascending
     * @param string|null $provider Container id of an ActivityDataProviderInterface
     * @param string|null $view     Logical view name rendering the entry
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $icon = null,
        public int $index = 100,
        public ?string $provider = null,
        public ?string $view = null,
    ) {
    }
}
