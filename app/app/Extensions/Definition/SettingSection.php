<?php

declare(strict_types=1);

namespace Nafinity\Definition;

use InvalidArgumentException;

/**
 * One card of the settings surface.
 *
 * A section without a template renders its registered fields with the generic
 * form; the existing special forms keep their own templates.
 */
final readonly class SettingSection
{
    /**
     * @param string      $id         Stable section id
     * @param string      $scope      Settings scope this card belongs to
     * @param string      $label      Translated card title
     * @param string|null $template   Logical view name, or null for the generic form
     * @param string|null $provider   Container id of a SettingSectionProviderInterface
     * @param int         $index      Sort value, ascending
     * @param string|null $permission Project action required, or null for none
     * @param string|null $icon       Material symbol name
     */
    public function __construct(
        public string $id,
        public string $scope,
        public string $label,
        public ?string $template = null,
        public ?string $provider = null,
        public int $index = 100,
        public ?string $permission = null,
        public ?string $icon = null,
    ) {
        if (!in_array($scope, SettingDefinition::SCOPES, true)) {
            throw new InvalidArgumentException(
                'Unknown settings scope "' . $scope . '" for section "' . $id . '".',
            );
        }
    }
}
