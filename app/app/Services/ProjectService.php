<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Change;
use App\Domain\Failure;
use App\Domain\ProjectScope;
use App\Support\Input;
use Naf\Auth\Auth;
use Naf\ORM\Core\EntityManager;
use PDO;
use Throwable;

use function Naf\event;

final class ProjectService
{
    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private Access $access,
        private EntityManager $entityManager,
    ) {
    }

    public function create(array $data): int
    {
        $this->auth->requirePermission('projects.create');
        $data  = $this->projectFields($data);
        $actor = $this->access->actor();
        $this->entityManager->begin();

        try {
            $id = $this->insert('projects', [...$data, 'created_by' => $actor]);
            $this->pdo
                ->prepare('INSERT INTO project_members(project_id,user_id,role) VALUES(?,?,?)')
                ->execute([$id, $actor, 'owner']);
            $board = $this->insert('boards', ['project_id' => $id, 'name' => 'Projektboard']);
            foreach (
                [
                    ['Offen', '#94a3b8', 0],
                    ['In Arbeit', '#6366f1', 0],
                    ['Review', '#f59e0b', 0],
                    ['Erledigt', '#10b981', 1],
                ] as $i => $column
            ) {
                $this->insert('board_columns', [
                    'project_id'     => $id,
                    'board_id'       => $board,
                    'name'           => $column[0],
                    'color'          => $column[1],
                    'position'       => ($i + 1) * 1024,
                    'closes_tickets' => $column[2],
                ]);
            }
            $this->insert('swimlanes', [
                'project_id' => $id,
                'board_id'   => $board,
                'name'       => 'Allgemein',
                'position'   => 1024,
                'is_default' => 1,
            ]);
            event()->dispatch(
                'nafinity.changed',
                new Change($id, null, $actor, 'project.created', ['name' => $data['name']]),
            );
            $this->entityManager->commit();

            return $id;
        } catch (Throwable $exception) {
            $this->entityManager->rollback();
            throw $exception;
        }
    }

    public function update(int $project, array $data): void
    {
        $fields = $this->projectFields($data);
        $this->access->write($project, 'manage', function () use ($project, $fields) {
            $this->pdo
                ->prepare('UPDATE projects SET name=?,description=?,color=?,icon=? WHERE id=?')
                ->execute([...array_values($fields), $project]);
            $this->changed($project, 'project.updated', ['name' => $fields['name']]);
        });
    }

    public function archive(int $project, bool $archived): void
    {
        $this->access->write($project, $archived ? 'archive' : 'restore', function () use (
            $project,
            $archived,
        ) {
            $this->pdo
                ->prepare('UPDATE projects SET archived_at=? WHERE id=?')
                ->execute([$archived ? gmdate('Y-m-d H:i:s') : null, $project]);
            $this->changed($project, $archived ? 'project.archived' : 'project.restored');
        });
    }

    public function member(int $project, array $data): void
    {
        $validated = Input::validate($data, ['email' => 'required|string|email|max:190']);
        $email     = strtolower(trim((string) $validated['email']));
        $role      = $data['role'] ?? 'member';
        if (
            !is_string($role)
            || !in_array($role, ['owner', 'manager', 'member', 'viewer', 'remove'], true)
        ) {
            throw new Failure('Ungültige Projektrolle.');
        }
        $this->access->write($project, 'members', function (ProjectScope $scope) use (
            $project,
            $email,
            $role,
        ) {
            $statement = $this->pdo->prepare('SELECT id FROM users WHERE email=? AND active=1');
            $statement->execute([$email]);
            $user = $statement->fetchColumn();
            if (!$user) {
                throw new Failure('Kein aktives Konto mit dieser E-Mail gefunden.');
            }
            $statement = $this->pdo->prepare(
                'SELECT role,active FROM project_members WHERE project_id=? AND user_id=?',
            );
            $statement->execute([$project, $user]);
            $old              = $statement->fetch();
            $grantsManagement = in_array($role, ['owner', 'manager'], true);
            $changesManager   = $old && in_array($old['role'], ['owner', 'manager'], true);

            if ($scope->role !== 'owner' && ($grantsManagement || $changesManager)) {
                throw new Failure('Diese Rolle kann nur ein Owner vergeben oder ändern.', 403);
            }
            if (
                $old
                && $old['role'] === 'owner'
                && (int) $old['active'] === 1
                && $role !== 'owner'
            ) {
                $statement = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM project_members WHERE project_id=? AND active=1 AND role='owner'",
                );
                $statement->execute([$project]);
                if ((int) $statement->fetchColumn() <= 1) {
                    throw new Failure('Das Projekt braucht mindestens einen aktiven Owner.');
                }
            }
            if ($old) {
                $this->pdo
                    ->prepare(
                        'UPDATE project_members SET role=?,active=? WHERE project_id=? AND user_id=?',
                    )
                    ->execute([
                        $role === 'remove' ? $old['role'] : $role,
                        $role === 'remove' ? 0 : 1,
                        $project,
                        $user,
                    ]);
            } elseif ($role !== 'remove') {
                $this->pdo
                    ->prepare('INSERT INTO project_members(project_id,user_id,role) VALUES(?,?,?)')
                    ->execute([$project, $user, $role]);
            }
            if ($role === 'remove') {
                $this->pdo
                    ->prepare('DELETE FROM ticket_assignees WHERE project_id=? AND user_id=?')
                    ->execute([$project, $user]);
            }
            $this->changed($project, 'project.member_changed', [
                'user_id' => (string) $user,
                'role'    => $role,
            ]);
        });
    }

    public function structure(int $project, array $data): void
    {
        $kind = $data['kind'] ?? '';
        if (!in_array($kind, ['column', 'swimlane', 'label'], true)) {
            throw new Failure('Ungültige Struktur.');
        }
        $table = ['column' => 'board_columns', 'swimlane' => 'swimlanes', 'label' => 'labels'][
            $kind
        ];
        $id     = empty($data['id']) ? null : Input::id($data['id']);
        $delete = ($data['action'] ?? 'save') === 'delete';
        $this->access->write($project, 'structure', function () use (
            $project,
            $kind,
            $table,
            $id,
            $data,
            $delete,
        ) {
            $statement = $this->pdo->prepare('SELECT * FROM boards WHERE project_id=?');
            $statement->execute([$project]);
            $board = $statement->fetch();
            $old   = null;
            if ($id !== null) {
                $statement = $this->pdo->prepare("SELECT * FROM $table WHERE project_id=? AND id=?");
                $statement->execute([$project, $id]);
                $old = $statement->fetch();
                if (!$old) {
                    throw new Failure('Eintrag nicht gefunden.', 404);
                }
            }
            if ($delete) {
                if (!$id) {
                    throw new Failure('Eintrag fehlt.');
                }
                if ($kind === 'label') {
                    $this->pdo
                        ->prepare('DELETE FROM ticket_labels WHERE project_id=? AND label_id=?')
                        ->execute([$project, $id]);
                } else {
                    if ($kind === 'swimlane' && (int) $old['is_default'] === 1) {
                        throw new Failure('Die Standard-Swimlane bleibt erhalten.');
                    }
                    $column    = $kind === 'column' ? 'column_id' : 'swimlane_id';
                    $statement = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM tickets WHERE project_id=? AND $column=?",
                    );
                    $statement->execute([$project, $id]);
                    if ((int) $statement->fetchColumn() > 0) {
                        throw new Failure('Verschiebe zuerst alle Tickets aus diesem Bereich.');
                    }
                    if ($kind === 'column') {
                        $statement = $this->pdo->prepare(
                            'SELECT COUNT(*) FROM board_columns WHERE project_id=?',
                        );
                        $statement->execute([$project]);
                        if ((int) $statement->fetchColumn() <= 1) {
                            throw new Failure('Mindestens eine Spalte bleibt erhalten.');
                        }
                    }
                }
                $this->pdo
                    ->prepare("DELETE FROM $table WHERE project_id=? AND id=?")
                    ->execute([$project, $id]);
            } else {
                $validated = Input::validate($data, ['name' => 'required|string|max:60']);
                $fields    = ['name' => trim($validated['name'])];
                if ($fields['name'] === '') {
                    throw new Failure('Ein Name wird benötigt.');
                }
                if ($kind !== 'swimlane') {
                    $fields['color'] = $this->color($data['color'] ?? '#6366f1');
                }
                if ($kind === 'column') {
                    $fields['closes_tickets'] = ($data['closes_tickets'] ?? '0') === '1' ? 1 : 0;
                    $fields['wip_limit']      = empty($data['wip_limit'])
                        ? null
                        : Input::id($data['wip_limit']);
                }
                if ($kind !== 'label') {
                    if ($id) {
                        $position = $old['position'];
                        if (
                            ($data['direction'] ?? '') === 'up'
                            || ($data['direction'] ?? '') === 'down'
                        ) {
                            $op        = $data['direction'] === 'up' ? '<' : '>';
                            $order     = $data['direction'] === 'up' ? 'DESC' : 'ASC';
                            $statement = $this->pdo->prepare(
                                "SELECT id,position FROM $table WHERE project_id=? AND position $op ? ORDER BY position $order,id LIMIT 1",
                            );
                            $statement->execute([$project, $position]);
                            $neighbor = $statement->fetch();
                            if ($neighbor) {
                                $this->pdo
                                    ->prepare(
                                        "UPDATE $table SET position=? WHERE project_id=? AND id=?",
                                    )
                                    ->execute([$position, $project, $neighbor['id']]);
                                $position = $neighbor['position'];
                            }
                        }
                    } else {
                        $statement = $this->pdo->prepare(
                            "SELECT COALESCE(MAX(position),0)+1024 FROM $table WHERE project_id=?",
                        );
                        $statement->execute([$project]);
                        $position = (int) $statement->fetchColumn();
                    }
                    $fields['position'] = $position;
                }
                if ($id) {
                    $assignments = implode(
                        ',',
                        array_map(static fn($key) => $key . '=?', array_keys($fields)),
                    );
                    $this->pdo
                        ->prepare("UPDATE $table SET $assignments WHERE project_id=? AND id=?")
                        ->execute([...array_values($fields), $project, $id]);
                } else {
                    $fields['project_id'] = $project;
                    if ($kind !== 'label') {
                        $fields['board_id'] = $board['id'];
                    }
                    $this->insert($table, $fields);
                }
            }
            $this->changed($project, 'board.structure_changed', [
                'kind'   => $kind,
                'id'     => $id === null ? null : (string) $id,
                'action' => $delete ? 'deleted' : 'saved',
            ]);
        });
    }

    public function changed(int $project, string $type, array $data = []): void
    {
        $this->pdo
            ->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')
            ->execute([$project]);
        event()->dispatch(
            'nafinity.changed',
            new Change($project, null, $this->access->actor(), $type, $data),
        );
    }

    private function projectFields(array $data): array
    {
        $validated = Input::validate($data, [
            'name'        => 'required|string|max:120',
            'description' => 'string|max:5000',
        ]);
        $name = trim($validated['name']);
        if ($name === '') {
            throw new Failure('Ein Projektname wird benötigt.');
        }
        $icon = $data['icon'] ?? mb_substr($name, 0, 1);
        if (!is_string($icon) || mb_strlen($icon) > 2) {
            throw new Failure('Das Icon darf höchstens zwei Zeichen haben.');
        }

        return [
            'name'        => $name,
            'description' => $validated['description'],
            'color'       => $this->color($data['color'] ?? '#6366f1'),
            'icon'        => $icon,
        ];
    }

    public function color(mixed $color): string
    {
        if (!is_string($color) || preg_match('/^#[a-fA-F0-9]{6}$/D', $color) !== 1) {
            throw new Failure('Ungültige Farbe.');
        }

        return strtolower($color);
    }

    private function insert(string $table, array $data): int
    {
        $columns      = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $sql          = "INSERT INTO $table($columns) VALUES($placeholders)";
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $sql .= ' RETURNING id';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($data));

        return (int) ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? $statement->fetchColumn()
            : $this->pdo->lastInsertId());
    }
}
