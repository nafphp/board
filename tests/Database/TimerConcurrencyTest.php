<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Closure;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Services\Access;
use Naf\Board\Services\ProjectAccess;
use Naf\Board\Services\TimerService;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\ORM\Core\EntityManager;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

final class TimerConcurrencyTest extends BoardTestCase
{
    protected bool $transactional = false;

    /** @return array<string, array{string}> */
    public static function actions(): array
    {
        return ['start' => ['start'], 'state' => ['state']];
    }

    #[DataProvider('actions')]
    public function testTimerActionsReadRunsCommittedBeforeThePersonLock(string $action): void
    {
        $first  = $this->tickets->create($this->projectA, $this->ticketData());
        $other  = $this->projects->create(['name' => 'Other timer', 'description' => 'Concurrent start']);
        $board  = $this->query->board($other);
        $second = $this->tickets->create($other, [
            'title'       => 'Second clock',
            'description' => '',
            'priority'    => 'normal',
            'column_id'   => $board['columns'][0]['id'],
            'swimlane_id' => $board['swimlanes'][0]['id'],
            ...$this->revision($other),
        ]);
        $peer = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $peerAccess  = new Access($peer, $this->auth, new EntityManager($peer), new ProjectAccess($peer));
        $peerTimers  = new TimerService($peer, $peerAccess);
        $interleaved = new TimerAccessHook($this->access, function () use ($peerTimers, $first): void {
            // Authorization has already opened a repeatable-read snapshot. A start in
            // another project commits before this request takes the person's lock.
            $peerTimers->act($this->projectA, $first, ['action' => 'start']);
        });

        $result = (new TimerService($this->pdo, $interleaved))->act($other, $second, ['action' => $action]);

        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM ticket_timers WHERE user_id=? AND state='running'", [$this->alice->getId()]));
        self::assertSame($action === 'start' ? 'paused' : 'running', $peerTimers->state($this->projectA, $first)['state']);
        self::assertSame($action === 'start' ? 'running' : 'stopped', $result['state']);
        if ($action === 'start') {
            self::assertNull($result['elsewhere']);
        } else {
            self::assertSame($this->projectA, $result['elsewhere']['project'] ?? null);
        }
    }
}

final readonly class TimerAccessHook implements AccessInterface
{
    public function __construct(private AccessInterface $inner, private Closure $beforeOperation)
    {
    }

    public function actor(): int
    {
        return $this->inner->actor();
    }

    public function project(int $id, string $action = 'read', bool $locked = false): ProjectScope
    {
        return $this->inner->project($id, $action, $locked);
    }

    public function permissions(int $project, string $role, ?int $customRoleId = null): array
    {
        return $this->inner->permissions($project, $role, $customRoleId);
    }

    public function write(int $projectId, string $action, callable $operation): mixed
    {
        return $this->inner->write($projectId, $action, function (ProjectScope $scope) use ($operation): mixed {
            ($this->beforeOperation)();

            return $operation($scope);
        });
    }
}
