<?php

declare(strict_types=1);

namespace Nafinity\Support;

use InvalidArgumentException;
use Nafinity\Definition\SettingDefinition;

/**
 * Which values a settings operation is about.
 *
 * A context names the scope and its owner. It is created from an already
 * authorized actor; it is never derived from a URL parameter.
 */
final readonly class SettingsContext
{
    /**
     * @param string   $scope     One of user, project, project_user, application
     * @param int|null $userId    Owning user, for user and project_user
     * @param int|null $projectId Owning project, for project and project_user
     */
    public function __construct(
        public string $scope,
        public ?int $userId = null,
        public ?int $projectId = null,
    ) {
        if (!in_array($scope, SettingDefinition::SCOPES, true)) {
            throw new InvalidArgumentException('Unknown settings scope "' . $scope . '".');
        }

        if (in_array($scope, ['user', 'project_user'], true) && $userId === null) {
            throw new InvalidArgumentException('Scope "' . $scope . '" needs a user.');
        }

        if (in_array($scope, ['project', 'project_user'], true) && $projectId === null) {
            throw new InvalidArgumentException('Scope "' . $scope . '" needs a project.');
        }
    }

    public static function user(int $userId): self
    {
        return new self('user', $userId);
    }

    public static function project(int $projectId): self
    {
        return new self('project', null, $projectId);
    }

    public static function projectUser(int $projectId, int $userId): self
    {
        return new self('project_user', $userId, $projectId);
    }

    public static function application(): self
    {
        return new self('application');
    }
}
