<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Example\WipPolicy\WipPolicyProvider;
use Naf\Board\Contracts\SettingsServiceInterface;
use Naf\Board\ExtensionContext;
use Naf\Board\Services\TicketSubscriptions;
use Naf\Board\Support\SettingsContext;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Core\EventManager;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\event;
use function Naf\I18n\translation_paths;

require_once dirname(__DIR__, 2) . '/examples/nafinity-wip-policy/src/WipPolicyProvider.php';
require_once dirname(__DIR__, 2) . '/examples/nafinity-wip-policy/src/WipLimit.php';

/** The optional package is exercised through the actual domain services and native event bus. */
final class WipPolicyTest extends BoardTestCase
{
    private EventManager $originalEvents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEvents = event();
        app()->container()->set(EventManager::class, clone $this->originalEvents);
        (new WipPolicyProvider())->register(new ExtensionContext(app()->container(), extensions()));
    }

    protected function tearDown(): void
    {
        app()->container()->set(EventManager::class, $this->originalEvents);
        extensions()->settings()->remove('project:' . WipPolicyProvider::KEY);
        extensions()->settingSections()->remove(WipPolicyProvider::SECTION);
        translation_paths()->remove('example.wip');
        parent::tearDown();
    }

    public function testFullColumnRefusesCreationAndRollsBackEveryNativeSideEffect(): void
    {
        $this->limit($this->columnA, 1);
        $this->tickets->create($this->projectA, $this->ticketData());
        $before = $this->snapshot();
        $this->assertDenied(409, fn() => $this->tickets->create($this->projectA, $this->ticketData()));
        self::assertSame($before, $this->snapshot());
    }

    public function testMovesIntoFullColumnsAreRefusedWhileReorderingEditsAndDeparturesRemainPossible(): void
    {
        $target = (int) $this->boardA['columns'][1]['id'];
        $this->tickets->create($this->projectA, $this->ticketData(['column_id' => $target]));
        $second = $this->tickets->create($this->projectA, $this->ticketData(['column_id' => $target]));
        $origin = $this->tickets->create($this->projectA, $this->ticketData());
        $this->limit($target, 1);
        $before = $this->snapshot();
        $this->assertDenied(409, fn() => $this->tickets->move($this->projectA, $origin, $this->move($target, 1)));
        self::assertSame($before, $this->snapshot());
        $this->tickets->move($this->projectA, $second, $this->move($target, 1));
        $this->tickets->update($this->projectA, $second, ['version' => 2, 'title' => 'Still editable']);
        $this->tickets->move($this->projectA, $second, $this->move($this->columnA, 3));
        self::assertSame($this->columnA, (int) $this->scalar('SELECT column_id FROM tickets WHERE id=?', [$second]));
    }

    public function testArchiveCloseRestoreAndReopenRespectActiveWorkAcrossEverySwimlane(): void
    {
        $this->limit($this->columnA, 1);
        $first = $this->tickets->create($this->projectA, $this->ticketData());
        $this->tickets->state($this->projectA, $first, ['version' => 1, 'action' => 'archive']);
        $this->projects->structure($this->projectA, ['kind' => 'swimlane', 'name' => 'Another lane']);
        $lane   = (int) $this->scalar('SELECT MAX(id) FROM swimlanes WHERE project_id=?', [$this->projectA]);
        $second = $this->tickets->create($this->projectA, $this->ticketData(['swimlane_id' => $lane]));
        $this->assertDenied(409, fn() => $this->tickets->state($this->projectA, $first, ['version' => 2, 'action' => 'restore']));
        $this->tickets->state($this->projectA, $second, ['version' => 1, 'action' => 'close']);
        $this->tickets->state($this->projectA, $first, ['version' => 2, 'action' => 'restore']);
        $this->assertDenied(409, fn() => $this->tickets->state($this->projectA, $second, ['version' => 2, 'action' => 'reopen']));
    }

    public function testManagersCanDisableTheOptionalPolicyAndReadersCannot(): void
    {
        $settings = app()->container()->get(SettingsServiceInterface::class);
        $this->actAs($this->viewer);
        $this->assertDenied(403, fn() => $settings->save(SettingsContext::project($this->projectA), [WipPolicyProvider::KEY => false]));
        $this->actAs($this->alice);
        $settings->save(SettingsContext::project($this->projectA), [WipPolicyProvider::KEY => false]);
        $this->limit($this->columnA, 1);
        $this->tickets->create($this->projectA, $this->ticketData());
        $this->tickets->create($this->projectA, $this->ticketData());
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM tickets WHERE project_id=?', [$this->projectA]));
    }

    public function testTransferIntoAFullProjectRollsBackTheOriginAndItsSubscriptions(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        app()->container()->make(TicketSubscriptions::class)->save($this->projectA, $ticket, true);
        $this->actAs($this->bob);
        $this->projects->member($this->projectB, ['email' => 'alice@example.test', 'role' => 'member']);
        $column = (int) $this->boardB['columns'][0]['id'];
        $this->tickets->create($this->projectB, $this->ticketData([
            'column_id' => $column, 'swimlane_id' => $this->boardB['swimlanes'][0]['id'],
            ...$this->revision($this->projectB),
        ]));
        $this->limit($column, 1);
        $this->actAs($this->alice);
        $before = $this->snapshot();
        $this->assertDenied(409, fn() => $this->tickets->transfer($this->projectA, $ticket, ['project_id' => $this->projectB, 'version' => 1]));
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM ticket_subscriptions WHERE project_id=? AND ticket_id=?', [$this->projectA, $ticket]));
    }

    private function limit(int $column, int $limit): void
    {
        $this->pdo->prepare('UPDATE board_columns SET wip_limit=? WHERE id=?')->execute([$limit, $column]);
    }

    private function move(int $column, int $version): array
    {
        return ['column_id' => $column, 'swimlane_id' => $this->laneA, 'version' => $version, ...$this->revision()];
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (['tickets', 'activities', 'notifications', 'naf_queue_jobs'] as $table) {
            $state[$table] = (int) $this->scalar('SELECT COUNT(*) FROM ' . $table);
        }
        $state['board'] = $this->pdo->query('SELECT revision,next_number FROM boards ORDER BY id')->fetchAll();

        return $state;
    }
}
