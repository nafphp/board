<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Database\Core\MigrationRunner;
use Naf\Database\Support\MigrationRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Naf\app;
use function Naf\Rbac\rbac;

/**
 * A test that talks to the database an installation actually uses.
 *
 * Storage is not an implementation detail a fake could stand in for here: what
 * these tests are about is what MariaDB and PostgreSQL really do -- fulltext
 * search, foreign keys, migrations in both directions. So the suite runs against
 * the disposable nafinity_test schema, on whichever driver the run selected, and
 * refuses to start anywhere else.
 *
 * Each test runs inside a transaction that is rolled back afterwards, so the
 * order tests run in cannot matter and none of them has to undo its own work.
 * A test whose subject opens a transaction of its own -- mail delivery does, and
 * so does anything that migrates -- sets $transactional to false and cleans up
 * after itself; PDO has no second transaction to hand out.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    /** @var bool Wrap this test in a transaction and roll it back afterwards. */
    protected bool $transactional = true;

    private static bool $schemaReady = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::guardTargetDatabase();
        self::prepareSchema();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = app()->container()->get(PDO::class);
        if ($this->transactional) {
            $this->pdo->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if ($this->transactional && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        parent::tearDown();
    }

    /**
     * The one schema the suite is allowed to destroy.
     *
     * Every test here drops and recreates tables or writes rows, so pointing the
     * run at a database someone works in would cost them that work. The name is
     * checked rather than trusted because a stray DB_DATABASE in a shell is the
     * likeliest way for that to happen.
     */
    private static function guardTargetDatabase(): void
    {
        if (getenv('APP_ENV') === 'test' && getenv('DB_DATABASE') === 'nafinity_test') {
            return;
        }

        throw new RuntimeException(
            'Database tests need APP_ENV=test and DB_DATABASE=nafinity_test. '
            . 'Demo data is never a test target.',
        );
    }

    /**
     * Bring the schema up once per process, from whatever state the last run left.
     *
     * Migrating down first is what makes a run repeatable: a schema half-built by
     * an aborted run is otherwise indistinguishable from a correct one until a
     * column is missing somewhere far away.
     */
    private static function prepareSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        // The real thing, not clearAllRows(): emptying tables cannot repair a
        // schema an aborted run left half-built, and that is exactly the state
        // this exists to rule out.
        $runner = new MigrationRunner(app()->container()->get(PDO::class));
        $runner->run(MigrationRegistry::getPaths(), 'down');
        $runner->run(MigrationRegistry::getPaths(), 'up');
        // Rebuilding drops the declared roles with everything else, and an
        // installation without them is one where nobody may do anything.
        self::restoreDeclaredRoles();
        self::$schemaReady = true;
    }

    /**
     * Empty every table, leaving the schema standing, and put back what is not
     * test data.
     *
     * For the few classes that cannot run inside a transaction and therefore
     * leave their rows behind. The tables are read out of the database rather
     * than listed here, so this cannot fall behind a migration -- and it costs a
     * few milliseconds where rebuilding the schema costs well over a second.
     *
     * The record of applied migrations is the one thing kept: the schema those
     * rows describe is still there, and claiming otherwise would make the next
     * run migrate over the top of it.
     *
     * The declared roles are reference data: they are written once by
     * `rbac:sync` and the application needs them to function at all. Truncating
     * them leaves an installation where nobody can be given anything, and the
     * failure surfaces far away -- as "permission denied" in a test about
     * something else entirely.
     */
    protected static function clearAllRows(): void
    {
        $pdo    = app()->container()->get(PDO::class);
        $tables = array_diff(self::tableNames($pdo), ['migrations']);
        if ($tables === []) {
            return;
        }

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            // One statement, because the tables reference each other and CASCADE
            // only reaches what is named alongside them.
            $pdo->exec('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');
            self::restoreDeclaredRoles();

            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        self::restoreDeclaredRoles();
    }

    /** What rbac:sync writes, which an installation cannot work without. */
    protected static function restoreDeclaredRoles(): void
    {
        $rbac = rbac();
        // Like the host test-up command, disposable fixtures use current declarations.
        // The historical migration deliberately preserves its original role grants.
        $rbac->roles->syncDeclared($rbac->declared->all(), $rbac->declared, true);
        $rbac->forget();
    }

    /** @return list<string> */
    private static function tableNames(PDO $pdo): array
    {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? 'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'
            : 'SHOW TABLES';

        return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** The single value of the first column, for the counting assertions. */
    protected function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    protected function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    protected function fetchRows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
