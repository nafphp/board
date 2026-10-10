<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Grants;
use Naf\Board\Support\ActivityDetail;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\event;

final class ProjectDeletionTest extends BoardTestCase
{
    public function testDeletionRemovesAllProjectDataAndScopedGrantsButKeepsOtherProjectsAndHistory(): void
    {
        $this->furnishProject();
        // RBAC can also hold a grant for somebody outside the membership list.
        Grants::inProject((int) $this->bob->getId(), $this->projectA, 'manager');
        $history = (int) $this->scalar('SELECT COUNT(*) FROM activities WHERE project_id=?', [$this->projectA]);
        $other   = $this->fetchOne('SELECT * FROM projects WHERE id=?', [$this->projectB]);
        $grants  = $this->fetchRows('SELECT * FROM rbac_user_roles WHERE scope<>? ORDER BY user_id,role_id,scope', ['project:' . $this->projectA]);

        $this->projects->delete($this->projectA, ['confirmation' => 'A']);

        $tables = $this->fetchRows("SELECT TABLE_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='project_id'");
        $this->assertNotEmpty($tables);
        foreach ($tables as $table) {
            $name = $table['TABLE_NAME'];
            $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM `$name` WHERE project_id=?", [$this->projectA]), $name);
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM projects WHERE id=?', [$this->projectA]));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM notification_deliveries'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM rbac_user_roles WHERE scope=?', ['project:' . $this->projectA]));
        $this->assertSame($other, $this->fetchOne('SELECT * FROM projects WHERE id=?', [$this->projectB]));
        $this->assertSame($grants, $this->fetchRows('SELECT * FROM rbac_user_roles WHERE scope<>? ORDER BY user_id,role_id,scope', ['project:' . $this->projectA]));
        $this->assertSame('"keep"', $this->scalar("SELECT value_json FROM user_settings WHERE user_id=? AND setting_key='test.keep'", [$this->alice->getId()]));
        $this->assertSame($history + 1, (int) $this->scalar('SELECT COUNT(*) FROM activities WHERE scope=?', ['project:' . $this->projectA]));
        $entry = $this->fetchOne("SELECT * FROM activities WHERE event_type='project.deleted' AND scope=?", ['project:' . $this->projectA]);
        $this->assertNull($entry['project_id']);
        $this->assertNull($entry['ticket_id']);
        $this->assertSame('„A“', ActivityDetail::of($entry));
        $this->assertDenied(404, fn() => $this->query->board($this->projectA));
    }

    public function testTheCurrentExactNameIsRequiredBeforeAnythingChanges(): void
    {
        foreach ([[], ['confirmation' => ''], ['confirmation' => 'a'], ['confirmation' => ['A']]] as $data) {
            $this->assertDenied(422, fn() => $this->projects->delete($this->projectA, $data));
            $this->assertSame('A', $this->scalar('SELECT name FROM projects WHERE id=?', [$this->projectA]));
        }
        $this->projects->update($this->projectA, ['name' => 'Renamed', 'description' => '']);
        $this->assertDenied(422, fn() => $this->projects->delete($this->projectA, ['confirmation' => 'A']));
        $this->projects->delete($this->projectA, ['confirmation' => 'Renamed']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM projects WHERE id=?', [$this->projectA]));
    }

    public function testManagersWithTicketDeletionRightsCannotDeleteTheProject(): void
    {
        $this->projects->member($this->projectA, ['email' => 'member@example.test', 'role' => 'manager']);
        foreach ([$this->member, $this->viewer, $this->bob] as $user) {
            $this->actAs($user);
            $this->assertDenied($user === $this->bob ? 404 : 403, fn() => $this->projects->delete($this->projectA, ['confirmation' => 'A']));
        }
        $this->assertSame('A', $this->scalar('SELECT name FROM projects WHERE id=?', [$this->projectA]));
    }

    public function testAFormerOwnerCannotDeleteWithAnAlreadyLoadedIdentity(): void
    {
        $this->projects->member($this->projectA, ['email' => 'bob@example.test', 'role' => 'owner']);
        $this->projects->member($this->projectA, ['email' => 'alice@example.test', 'role' => 'manager']);
        $this->assertDenied(403, fn() => $this->projects->delete($this->projectA, ['confirmation' => 'A']));
        $this->assertSame('A', $this->scalar('SELECT name FROM projects WHERE id=?', [$this->projectA]));
    }

    public function testAnOwnerCanDeleteAnArchivedProject(): void
    {
        $this->projects->archive($this->projectA, true);
        $this->projects->delete($this->projectA, ['confirmation' => 'A']);
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM projects WHERE id=?', [$this->projectA]));
    }

    public function testAnExtensionCanRefuseDeletionInsideTheTransaction(): void
    {
        $enabled = true;
        $pdo     = $this->pdo;
        event()->listen(Change::class, static function (Change $change) use (&$enabled, $pdo): void {
            if (!$enabled || $change->type !== 'project.deleted') {
                return;
            }
            $pdo->prepare("UPDATE projects SET description='listener change' WHERE id=?")->execute([$change->projectId]);
            throw new Failure('Extension refused deletion.', 409);
        });

        try {
            $this->assertDenied(409, fn() => $this->projects->delete($this->projectA, ['confirmation' => 'A']));
        } finally {
            $enabled = false;
        }
        $this->assertSame('Private Alpha', $this->scalar('SELECT description FROM projects WHERE id=?', [$this->projectA]));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM activities WHERE event_type='project.deleted'"));
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM rbac_user_roles WHERE scope=?', ['project:' . $this->projectA]));
    }

    private function furnishProject(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $second = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Related']));
        $this->comments->save($this->projectA, $ticket, ['body' => 'Parent']);
        $parent = $this->scalar('SELECT id FROM comments WHERE ticket_id=?', [$ticket]);
        $this->comments->save($this->projectA, $ticket, ['body' => 'Reply', 'parent_id' => $parent]);
        $now = gmdate('Y-m-d H:i:s');
        $uid = (int) $this->alice->getId();
        $this->insertRow('labels', ['project_id' => $this->projectA, 'name' => 'Label', 'color' => '#6366f1']);
        $label = (int) $this->pdo->lastInsertId();
        $this->insertRow('ticket_labels', ['project_id' => $this->projectA, 'ticket_id' => $ticket, 'label_id' => $label]);
        $this->insertRow('ticket_assignees', ['project_id' => $this->projectA, 'ticket_id' => $ticket, 'user_id' => $uid]);
        $this->insertRow('ticket_links', ['project_id' => $this->projectA, 'ticket_id' => min($ticket, $second), 'related_id' => max($ticket, $second)]);
        $this->insertRow('ticket_metadata', ['project_id' => $this->projectA, 'ticket_id' => $ticket, 'meta_key' => 'test.field', 'value_json' => 'true', 'updated_at' => $now]);
        $this->insertRow('ticket_timers', ['project_id' => $this->projectA, 'ticket_id' => $ticket, 'user_id' => $uid, 'state' => 'running', 'started_at' => $now, 'updated_at' => $now]);
        $this->insertRow('attachments', ['project_id' => $this->projectA, 'ticket_id' => $ticket, 'uploaded_by' => $uid, 'storage_key' => str_repeat('a', 64), 'original_name' => 'note.txt', 'mime_type' => 'text/plain', 'byte_size' => 1, 'sha256' => str_repeat('b', 64), 'state' => 'ready', 'created_at' => $now]);
        $this->insertRow('project_preferences', ['project_id' => $this->projectA, 'user_id' => $uid, 'muted' => 1]);
        $this->insertRow('project_settings', ['project_id' => $this->projectA, 'setting_key' => 'test.project', 'value_json' => 'true', 'updated_at' => $now]);
        $this->insertRow('project_user_settings', ['project_id' => $this->projectA, 'user_id' => $uid, 'setting_key' => 'test.personal', 'value_json' => 'true', 'updated_at' => $now]);
        $this->insertRow('user_settings', ['user_id' => $uid, 'setting_key' => 'test.keep', 'value_json' => '"keep"', 'updated_at' => $now]);
        $this->insertRow('project_roles', ['project_id' => $this->projectA, 'name' => 'Custom']);
        $role = (int) $this->pdo->lastInsertId();
        $this->insertRow('project_role_permissions', ['project_id' => $this->projectA, 'role_id' => $role, 'permission' => 'read']);
        $this->pdo->prepare("UPDATE project_members SET custom_role_id=? WHERE project_id=? AND role='viewer'")->execute([$role, $this->projectA]);
        $notification = $this->scalar('SELECT id FROM notifications WHERE project_id=? LIMIT 1', [$this->projectA]);
        $this->assertNotFalse($notification);
        $this->insertRow('notification_deliveries', ['notification_id' => $notification, 'channel' => 'mail', 'state' => 'pending']);
    }

    /** @param array<string,mixed> $data */
    private function insertRow(string $table, array $data): void
    {
        $columns = implode(',', array_keys($data));
        $marks   = implode(',', array_fill(0, count($data), '?'));
        $this->pdo->prepare("INSERT INTO $table ($columns) VALUES ($marks)")->execute(array_values($data));
    }
}
