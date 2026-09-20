<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\DatabaseTestCase;
use Naf\Database\Core\MigrationRunner;
use Naf\Database\Support\MigrationRegistry;

/**
 * The schema has to be reversible, not only creatable.
 *
 * An installation that cannot migrate down is an installation nobody can undo a
 * release on, and a migration that is not idempotent turns a repeated deploy
 * into an outage. Both are cheap to check and expensive to discover later.
 *
 * Not transactional: DDL commits of its own accord on MariaDB, so there would be
 * nothing left to roll back. The suite leaves the schema up, which is where it
 * found it.
 */
final class MigrationTest extends DatabaseTestCase
{
    protected bool $transactional = false;

    public function testMigratingUpWhenAlreadyUpChangesNothing(): void
    {
        $runner = new MigrationRunner($this->pdo);

        $this->assertSame(
            [],
            $runner->run(MigrationRegistry::getPaths(), 'up'),
            'a second up applied something, so a repeated deploy is not a no-op',
        );
    }

    public function testEveryMigrationCanBeRolledBackAndReapplied(): void
    {
        $runner  = new MigrationRunner($this->pdo);
        $applied = (int) $this->scalar('SELECT COUNT(*) FROM migrations');

        $this->assertGreaterThanOrEqual(4, $applied, 'the plugin migrations are missing');

        $this->assertCount(
            $applied,
            $runner->run(MigrationRegistry::getPaths(), 'down'),
            'some migration had no way back',
        );
        $this->assertCount(
            $applied,
            $runner->run(MigrationRegistry::getPaths(), 'up'),
            'the schema could not be built again from nothing',
        );
    }

    /**
     * Hand back an installation somebody could use.
     *
     * Going down and up again leaves the schema standing and everything in it
     * gone -- including the roles a host declares, which are written once and
     * are not test data. Without this the classes that run after this one fail
     * with "permission denied" in tests about something else entirely, which is
     * a long way from the cause.
     */
    protected function tearDown(): void
    {
        self::restoreDeclaredRoles();
        parent::tearDown();
    }
}
