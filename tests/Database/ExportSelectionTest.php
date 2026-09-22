<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Contracts\RoleServiceInterface;
use Naf\Board\Definition\TicketFieldDefinition;
use Naf\Board\Export\ExportFinished;
use Naf\Board\Export\ExportOptions;
use Naf\Board\Services\ExportService;
use Naf\Board\Tests\Support\BoardTestCase;
use PDO;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\event;

final class ExportSelectionTest extends BoardTestCase
{
    private function exports(): ExportService
    {
        return app()->container()->make(ExportService::class);
    }

    private function rows(array $options = [], ?array $projects = null): array
    {
        $file = $this->exports()->writeSelected($projects ?? [$this->projectA], 'json', ExportOptions::fromInput($options));

        try {
            return json_decode(stream_get_contents($file['stream']), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($file['stream']);
        }
    }

    public function testStatusAndArchiveAreIndependentFilters(): void
    {
        foreach (['open', 'closed'] as $status) {
            foreach ([null, '2026-09-20 12:00:00'] as $archived) {
                $ticket = $this->tickets->create($this->projectA, $this->ticketData());
                $this->pdo->prepare('UPDATE tickets SET status=?, archived_at=? WHERE id=?')->execute([$status, $archived, $ticket]);
            }
        }
        $this->assertCount(4, $this->rows());
        $this->assertCount(2, $this->rows(['archive' => 'exclude']));
        $rows = $this->rows(['status' => 'closed', 'archive' => 'only']);
        $this->assertCount(1, $rows);
        $this->assertSame('closed', $rows[0]['status']);
        $this->assertNotNull($rows[0]['archived_at']);
        $this->assertArrayNotHasKey('description', $rows[0]);
        $this->assertNotEmpty($this->rows(['description' => '1'])[0]['description']);
    }

    public function testDatesIncludeBothDaysAndAllowOpenEndedRanges(): void
    {
        foreach (['2026-09-20 23:59:59', '2026-09-21 00:00:00', '2026-09-22 23:59:59', '2026-09-23 00:00:00'] as $at) {
            $ticket = $this->tickets->create($this->projectA, $this->ticketData());
            $this->pdo->prepare('UPDATE tickets SET updated_at=? WHERE id=?')->execute([$at, $ticket]);
        }
        $this->assertCount(2, $this->rows(['updated_from' => '2026-09-21', 'updated_until' => '2026-09-22']));
        $this->assertCount(3, $this->rows(['updated_from' => '2026-09-21']));
        $this->assertCount(3, $this->rows(['updated_until' => '2026-09-22']));
        $this->assertSame([], $this->rows(['updated_from' => '2026-09-24']));
    }

    public function testEverySelectedBoardMustBeAuthorizedBeforeWriting(): void
    {
        $this->tickets->create($this->projectA, $this->ticketData());
        $this->assertDenied(404, fn() => $this->rows([], [$this->projectA, $this->projectB]));
        $this->actAs($this->viewer);
        $this->assertDenied(403, fn() => $this->rows());
        $this->assertSame([], $this->exports()->availableProjects($this->query->projects()));
    }

    public function testArchivedBoardsAreNotOfferedAndExplicitRequestsAreRejected(): void
    {
        $this->pdo->prepare('UPDATE projects SET archived_at=? WHERE id=?')->execute(['2026-09-20 12:00:00', $this->projectA]);
        $this->assertSame([], $this->exports()->availableProjects($this->query->projects()));
        $this->assertDenied(403, fn() => $this->rows());
        $this->assertDenied(422, fn() => $this->rows([], []));
    }

    public function testCombinedColumnsKeepEachBoardsMetadataPermissions(): void
    {
        $fields = extensions()->ticketFields();
        $fields->add(new TicketFieldDefinition('export.test', 'Private value', 'text', readPermission: 'manage'));

        try {
            $this->tickets->create($this->projectA, $this->ticketData(['metadata' => ['export.test' => 'visible alpha']]));
            $this->actAs($this->bob);
            $role = $this->rolesForExport();
            $this->projects->member($this->projectB, ['email' => 'alice@example.test', 'role' => 'custom:' . $role]);
            $this->tickets->create($this->projectB, $this->ticketData([
                'column_id'   => $this->boardB['columns'][0]['id'],
                'swimlane_id' => $this->boardB['swimlanes'][0]['id'],
                'metadata'    => ['export.test' => 'secret beta'],
                ...$this->revision($this->projectB),
            ]));
            $this->actAs($this->alice);
            $finished = [];
            event()->listen(ExportFinished::class, static function (ExportFinished $event) use (&$finished): void {
                $finished[$event->project] = $event->written;
            });
            $rows = $this->rows([], [$this->projectA, $this->projectB, $this->projectA]);
            $this->assertCount(2, $rows);
            $this->assertSame('visible alpha', $rows[0]['export.test']);
            $this->assertNull($rows[1]['export.test']);
            $this->assertSame($this->projectB, $rows[1]['project_id']);
            $this->assertSame('B', $rows[1]['project']);
            $this->assertSame([$this->projectA => 1, $this->projectB => 1], $finished);
            $this->assertArrayNotHasKey('export.test', $this->rows(['metadata' => '0'])[0]);
        } finally {
            $fields->remove('export.test');
        }
    }

    public function testFilteredExportsContinuePastThePageBoundaryWithoutDuplicates(): void
    {
        $ticket    = $this->tickets->create($this->projectA, $this->ticketData());
        $statement = $this->pdo->prepare('SELECT * FROM tickets WHERE id=?');
        $statement->execute([$ticket]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        unset($row['id']);
        $insert = $this->pdo->prepare('INSERT INTO tickets (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        for ($number = 2; $number <= 1003; $number++) {
            $row['number']   = $number;
            $row['position'] = $number * 1024;
            $row['status']   = $number % 2 === 0 ? 'closed' : 'open';
            $insert->execute(array_values($row));
        }
        $rows = $this->rows(['status' => 'open', 'archive' => 'exclude']);
        $this->assertCount(502, $rows);
        $this->assertCount(502, array_unique(array_column($rows, 'key')));
        $this->assertStringEndsWith('-1003', $rows[501]['key']);
    }

    private function rolesForExport(): int
    {
        $roles = app()->container()->get(RoleServiceInterface::class);

        $roles->save($this->projectB, ['name' => 'Export only', 'permissions' => ['export']]);

        return (int) $this->scalar('SELECT id FROM project_roles WHERE project_id=? AND name=?', [$this->projectB, 'Export only']);
    }
}
