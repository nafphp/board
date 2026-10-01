<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Database\Core\MigrationRunner;
use Naf\Database\Support\MigrationRegistry;
use PDO;
use PDOException;
use RuntimeException;

use function Naf\app;
use function Naf\Rbac\rbac;

/**
 * The database the suite runs against: a second server of its own.
 *
 * Started from tests/compose.yaml when nothing answers on the configured port,
 * migrated from scratch once per process, and refused unless it is the
 * disposable nafinity_test schema -- a stray DB_DATABASE in a shell is the
 * likeliest way to point a suite at data somebody works with.
 */
final class TestDatabase
{
    private static bool $migrated = false;

    /** Make sure the server answers, starting it if it does not. */
    public static function ensureRunning(): void
    {
        if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
            throw new RuntimeException('The suite needs APP_ENV=test and DB_DATABASE=nafinity_test.');
        }

        if (self::answers()) {
            return;
        }

        $compose = dirname(__DIR__) . '/compose.yaml';
        passthru('docker compose -f ' . escapeshellarg($compose) . ' up -d --wait 2>&1', $status);

        if ($status !== 0 || !self::answers()) {
            throw new RuntimeException(
                'No test database on ' . getenv('DB_HOST') . ':' . getenv('DB_PORT')
                . ', and `docker compose -f tests/compose.yaml up` could not start one.',
            );
        }
    }

    /**
     * Build the schema once per process, from whatever state the last run left.
     *
     * Down first, then up: emptying tables cannot repair a schema an aborted run
     * left half-built, and running every migration in both directions is a test
     * of the migrations too.
     */
    public static function migrate(): void
    {
        if (self::$migrated) {
            return;
        }

        $runner = new MigrationRunner(app()->container()->get(PDO::class));
        $runner->run(MigrationRegistry::getPaths(), 'down');
        $runner->run(MigrationRegistry::getPaths(), 'up');
        self::restoreDeclaredRoles();
        self::$migrated = true;
    }

    /**
     * Empty every table, leaving the schema and the declared roles standing.
     *
     * For the classes that cannot run inside a transaction. The tables are read
     * from the database rather than listed, so this cannot fall behind a
     * migration; the migration record stays, because the schema it describes does.
     */
    public static function clearAllRows(): void
    {
        $pdo    = app()->container()->get(PDO::class);
        $tables = array_diff($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN), ['migrations']);

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

    /**
     * What `rbac:sync` writes, which an installation cannot work without.
     *
     * Reapplied rather than merged: a real installation keeps what it changed
     * about a declared role, a disposable schema exists to check what the code
     * declares now.
     */
    public static function restoreDeclaredRoles(): void
    {
        $rbac = rbac();
        $rbac->roles->syncDeclared($rbac->declared->all(), $rbac->declared, true);
        $rbac->forget();
    }

    private static function answers(): bool
    {
        try {
            new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
                (string) getenv('DB_USERNAME'),
                (string) getenv('DB_PASSWORD'),
                [PDO::ATTR_TIMEOUT => 2],
            );

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
