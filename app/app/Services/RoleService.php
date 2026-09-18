<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Support\Input;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\ProjectServiceInterface;
use Nafinity\Contracts\RoleServiceInterface;
use PDO;

use function Nafinity\extensions;

final class RoleService implements RoleServiceInterface
{
    public function __construct(private PDO $pdo, private AccessInterface $access, private ProjectServiceInterface $projects)
    {
    }

    public function list(int $project): array
    {
        $this->access->project($project);
        $statement = $this->pdo->prepare('SELECT r.*,
            (SELECT COUNT(*) FROM project_members m WHERE m.project_id=r.project_id AND m.custom_role_id=r.id AND m.active=1) AS member_count
            FROM project_roles r WHERE project_id=? ORDER BY name');
        $statement->execute([$project]);
        $roles     = $statement->fetchAll();
        $available = extensions()->permissions()->names();
        foreach ($roles as &$role) {
            $role['permissions'] = $this->access->permissions($project, 'viewer', (int) $role['id']);
            $role['unavailable'] = array_values(
                array_diff($this->stored($project, (int) $role['id']), $available),
            );
        }

        return $roles;
    }

    public function save(int $project, array $data): void
    {
        $this->access->write($project, 'roles', function () use ($project, $data) {
            $id = empty($data['id']) ? null : Input::id($data['id']);
            if ($id !== null) {
                $statement = $this->pdo->prepare('SELECT version FROM project_roles WHERE project_id=? AND id=?');
                $statement->execute([$project, $id]);
                $version = $statement->fetchColumn();
                if ($version === false) {
                    throw new Failure('Rolle nicht gefunden.', 404);
                }
                if (Input::id($data['version'] ?? null) !== (int) $version) {
                    throw new Failure('Diese Rolle wurde inzwischen geändert. Bitte lade den aktuellen Stand.', 409);
                }
            }
            if (($data['action'] ?? 'save') === 'delete') {
                if ($id === null) {
                    throw new Failure('Rolle fehlt.');
                }
                $statement = $this->pdo->prepare('SELECT COUNT(*) FROM project_members WHERE project_id=? AND custom_role_id=?');
                $statement->execute([$project, $id]);
                if ((int) $statement->fetchColumn() > 0) {
                    throw new Failure('Diese Rolle ist noch zugeordnet. Weise den Benutzern zuerst eine andere Rolle zu.');
                }
                $this->pdo->prepare('DELETE FROM project_role_permissions WHERE project_id=? AND role_id=?')->execute([$project, $id]);
                $this->pdo->prepare('DELETE FROM project_roles WHERE project_id=? AND id=?')->execute([$project, $id]);
                $this->projects->changed($project, 'project.role_deleted', ['id' => (string) $id]);

                return;
            }
            $fields      = Input::validate(['description' => '', ...$data], ['name' => 'required|string|max:60', 'description' => 'string|max:255']);
            $name        = trim($fields['name']);
            $permissions = $data['permissions'] ?? [];
            $assignable  = array_keys(extensions()->permissions()->assignable());
            if ($name === '' || in_array(strtolower($name), ['owner', 'manager', 'member', 'viewer'], true)) {
                throw new Failure('Bitte verwende einen eigenen Rollennamen.');
            }
            if (!is_array($permissions) || array_filter($permissions, static fn($value) => !is_string($value) || !in_array($value, $assignable, true))) {
                throw new Failure('Ungültiges Recht.');
            }
            if (in_array('moderate', $permissions, true) && !in_array('comment', $permissions, true)) {
                throw new Failure('Zum Moderieren wird auch das Recht zum Kommentieren benötigt.');
            }
            $statement = $this->pdo->prepare('SELECT id FROM project_roles WHERE project_id=? AND LOWER(name)=LOWER(?) AND id<>?');
            $statement->execute([$project, $name, $id ?? 0]);
            if ($statement->fetchColumn()) {
                throw new Failure('Eine Rolle mit diesem Namen existiert bereits.');
            }
            if ($id === null) {
                $postgres  = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
                $statement = $this->pdo->prepare('INSERT INTO project_roles(project_id,name,description) VALUES(?,?,?)' . ($postgres ? ' RETURNING id' : ''));
                $statement->execute([$project, $name, $fields['description']]);
                $id = (int) ($postgres ? $statement->fetchColumn() : $this->pdo->lastInsertId());
            } else {
                $this->pdo->prepare('UPDATE project_roles SET name=?,description=?,version=version+1 WHERE project_id=? AND id=?')->execute([$name, $fields['description'], $project, $id]);
            }
            // A grant whose plugin is currently missing is kept exactly as it is.
            // The form could not show it, so the form cannot be read as a wish to
            // drop it; reinstalling the plugin brings the right back.
            $kept = array_diff($this->stored($project, $id), array_keys(extensions()->permissions()->all()));
            $this->pdo->prepare('DELETE FROM project_role_permissions WHERE project_id=? AND role_id=?')->execute([$project, $id]);
            $statement = $this->pdo->prepare('INSERT INTO project_role_permissions(project_id,role_id,permission) VALUES(?,?,?)');
            foreach (array_unique([...$permissions, ...$kept]) as $permission) {
                $statement->execute([$project, $id, $permission]);
            }
            $this->projects->changed($project, 'project.role_saved', ['id' => (string) $id, 'name' => $name]);
        });
    }

    /**
     * The permission names stored for a role, whatever is defined right now
     *
     * @param int $project Project id
     * @param int $role    Custom role id
     *
     * @return list<string>
     */
    private function stored(int $project, int $role): array
    {
        $statement = $this->pdo->prepare(
            'SELECT permission FROM project_role_permissions WHERE project_id=? AND role_id=?',
        );
        $statement->execute([$project, $role]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
