<?php

declare(strict_types=1);

namespace Nafinity\Definition;

use Closure;
use Nafinity\Support\UiContext;

/**
 * A semantic menu entry for one of the sidebar slots.
 *
 * Route parameters may be a closure so that project and ticket ids are read
 * from the current authorized context instead of being frozen during boot.
 */
final readonly class NavigationItem
{
    /**
     * @param string               $id           Stable item id, namespaced for plugins
     * @param string               $slot         Sidebar slot name
     * @param string               $label        Translated label
     * @param string               $routeName    Registered route name
     * @param array|Closure        $routeParams  Parameters, or fn(UiContext): array
     * @param string|null          $icon         Material symbol name
     * @param list<string>         $activeRoutes Route names that mark this item active
     * @param int                  $index        Sort value, ascending
     * @param string|null          $permission   Project action required, or null for none
     */
    public function __construct(
        public string $id,
        public string $slot,
        public string $label,
        public string $routeName,
        public array|Closure $routeParams = [],
        public ?string $icon = null,
        public array $activeRoutes = [],
        public int $index = 100,
        public ?string $permission = null,
    ) {
    }

    /**
     * Resolve the route parameters for an already authorized context
     *
     * @param UiContext $context The authorized rendering context
     *
     * @return array<string, int|string>
     */
    public function parameters(UiContext $context): array
    {
        $parameters = $this->routeParams;

        return $parameters instanceof Closure ? $parameters($context) : $parameters;
    }
}
