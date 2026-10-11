<?php

declare(strict_types=1);

namespace Naf\Board\Registry;

use LogicException;
use Naf\Board\Definition\SettingSection;

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
        $sections = array_filter($this->all(), fn(SettingSection $section) => $section->scope === $scope);
        foreach ($sections as $section) {
            $visited = [];
            while ($section !== null) {
                if (isset($visited[$section->id])) {
                    throw new LogicException('Settings section parent cycle at "' . $section->id . '".');
                }
                $visited[$section->id] = true;
                $section               = $sections[$section->parent ?? ''] ?? null;
            }
        }

        return $sections;
    }
}
