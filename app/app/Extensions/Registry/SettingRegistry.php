<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\SettingDefinition;

/**
 * Every declared setting, across all four scopes.
 *
 * Identity is the pair of scope and key, so `muted` may exist as a personal and
 * as a per-project value without the two ever meeting.
 */
final class SettingRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(SettingDefinition::class, 'Setting');
    }

    public function get(string $id): ?SettingDefinition
    {
        return parent::get($id);
    }

    protected function identify(object $definition): string
    {
        return $definition->id();
    }

    /**
     * @param string $scope Settings scope
     * @param string $key   Literal setting key
     */
    public function find(string $scope, string $key): ?SettingDefinition
    {
        return $this->get($scope . ':' . $key);
    }

    /**
     * Every definition of one scope, ordered by index and key
     *
     * @param string $scope Settings scope
     *
     * @return array<string, SettingDefinition> Keyed by literal key
     */
    public function forScope(string $scope): array
    {
        $definitions = [];

        foreach ($this->all() as $definition) {
            if ($definition->scope === $scope) {
                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Every definition of one scope inside one section
     *
     * @param string $scope   Settings scope
     * @param string $section Section id
     *
     * @return array<string, SettingDefinition> Keyed by literal key
     */
    public function forSection(string $scope, string $section): array
    {
        return array_filter(
            $this->forScope($scope),
            fn(SettingDefinition $definition) => $definition->section === $section,
        );
    }
}
