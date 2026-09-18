<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\SettingSection;

/**
 * The cards of the settings surface.
 */
final class SettingSectionRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(SettingSection::class, 'Settings section');
    }

    public function get(string $id): ?SettingSection
    {
        return parent::get($id);
    }

    /**
     * @param string $scope Settings scope
     *
     * @return array<string, SettingSection>
     */
    public function forScope(string $scope): array
    {
        return array_filter($this->all(), fn(SettingSection $section) => $section->scope === $scope);
    }
}
