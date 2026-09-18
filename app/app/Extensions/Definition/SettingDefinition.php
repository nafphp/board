<?php

declare(strict_types=1);

namespace Nafinity\Definition;

use InvalidArgumentException;

/**
 * One stored, typed and authorized setting.
 *
 * Identity is the pair of scope and key, so the same key may exist in several
 * scopes. The default is always stated explicitly; `null`, `false`, `0` and the
 * empty string are values, never a reason to fall back.
 */
final readonly class SettingDefinition
{
    public const array SCOPES = ['user', 'project', 'project_user', 'application'];

    /**
     * @param string      $key             Literal key, dots included, namespaced for plugins
     * @param string      $scope           One of user, project, project_user, application
     * @param string      $section         Id of the settings card this field belongs to
     * @param string      $label           Translated label
     * @param string      $type            Registered field type id
     * @param mixed       $default         Value used when nothing is stored
     * @param int         $index           Sort value, ascending
     * @param array       $options         Type options, for example min, max or choices
     * @param string|null $readPermission  Project action required to read
     * @param string|null $writePermission Project action required to write
     * @param bool        $sensitive       Whether the value must never leave the server
     * @param string|null $configKey       Declared NAF config key used before the default
     */
    public function __construct(
        public string $key,
        public string $scope,
        public string $section,
        public string $label,
        public string $type,
        public mixed $default,
        public int $index = 100,
        public array $options = [],
        public ?string $readPermission = null,
        public ?string $writePermission = null,
        public bool $sensitive = false,
        public ?string $configKey = null,
    ) {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException(
                'Unknown settings scope "' . $scope . '" for key "' . $key . '".',
            );
        }

        if ($key === '') {
            throw new InvalidArgumentException('A setting needs a non-empty key.');
        }
    }

    /** Registry identity: the same key may exist once per scope. */
    public function id(): string
    {
        return $this->scope . ':' . $this->key;
    }
}
