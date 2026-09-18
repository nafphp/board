<?php

declare(strict_types=1);

namespace Nafinity\Definition;

/**
 * A template contributed to a named slot of the existing interface.
 *
 * Visibility is filtered before the data provider is resolved, so an entry the
 * actor may not see never runs code. Being visible is never a write permission.
 */
final readonly class UiContribution
{
    /**
     * @param string        $id         Stable contribution id, namespaced for plugins
     * @param string        $slot       One of the fixed slot names
     * @param string        $template   Logical view name rendered for this slot
     * @param int           $index      Sort value, ascending
     * @param string|null   $provider   Container id of a UiDataProviderInterface
     * @param string|null   $permission Project action required, or null for none
     * @param list<string>  $modes      Modes this contribution appears in
     * @param string|null   $module     Public URL of a browser module to mount
     */
    public function __construct(
        public string $id,
        public string $slot,
        public string $template,
        public int $index = 100,
        public ?string $provider = null,
        public ?string $permission = 'read',
        public array $modes = ['detail'],
        public ?string $module = null,
    ) {
    }

    public function appearsIn(string $mode): bool
    {
        return in_array($mode, $this->modes, true);
    }
}
