<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\AuditLog;
use Naf\Board\Tests\Support\BoardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Naf\app;

/**
 * Keeping a history for a stated time, and not a day less by accident.
 *
 * Shortening a log is the one maintenance task that destroys something, so the
 * default has to be that it does nothing at all -- an installation that has said
 * nothing keeps everything, and a history that quietly trimmed itself would be
 * worse than none.
 */
final class RetentionTest extends BoardTestCase
{
    private AuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = app()->container()->make(AuditLog::class);
    }

    /** @return array<string, array{int}> */
    public static function nothingToDo(): array
    {
        return ['unset' => [0], 'negative' => [-1]];
    }

    /**
     * @param int $days The retention an installation has configured
     */
    #[DataProvider('nothingToDo')]
    public function testAnInstallationThatSaidNothingKeepsEverything(int $days): void
    {
        $this->entryAgedDays(400);
        $before = $this->entries();

        $this->assertSame(0, $this->audit->prune($days));
        $this->assertSame($before, $this->entries(), 'a log was shortened without being asked');
    }

    public function testOnlyWhatIsOlderThanTheStatedTimeGoes(): void
    {
        $old    = $this->entryAgedDays(120);
        $recent = $this->entryAgedDays(10);

        $this->assertGreaterThan(0, $this->audit->prune(90));
        $this->assertFalse($this->exists($old), 'an entry past the retention was kept');
        $this->assertTrue($this->exists($recent), 'an entry inside the retention was dropped');
    }

    /** Running it twice is running it once: there is nothing left to take. */
    public function testASecondPassFindsNothing(): void
    {
        $this->entryAgedDays(120);
        $this->audit->prune(90);

        $this->assertSame(0, $this->audit->prune(90));
    }

    /**
     * More than one batch, because the first time a year-old log is shortened is
     * the only time the batching matters -- and the only time it is not exercised
     * by any other test.
     */
    public function testALogLongerThanOneBatchIsFullyShortened(): void
    {
        for ($i = 0; $i < 1200; $i++) {
            $this->entryAgedDays(120);
        }

        $this->assertGreaterThanOrEqual(1200, $this->audit->prune(90));
        $this->assertSame(0, $this->audit->prune(90), 'something survived the batching');
    }

    private function entryAgedDays(int $days): int
    {
        $this->pdo
            ->prepare(
                'INSERT INTO activities(scope,project_id,ticket_id,actor_id,event_type,payload,created_at)'
                . " VALUES('',NULL,NULL,NULL,'test.retention','[]',?)",
            )
            ->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

        return (int) $this->pdo->lastInsertId();
    }

    private function exists(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM activities WHERE id=?');
        $statement->execute([$id]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function entries(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn();
    }
}
