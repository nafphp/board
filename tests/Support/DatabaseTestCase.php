<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

use function Naf\app;

/**
 * A test that talks to the database an installation actually uses.
 *
 * Storage is not an implementation detail a fake could stand in for here: what
 * these tests are about is what MariaDB really does -- fulltext search, foreign
 * keys, migrations in both directions. So the suite runs against the disposable
 * nafinity_test schema that TestDatabase provides.
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

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        TestDatabase::migrate();
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

    /** Empty every table, for the classes that cannot run inside a transaction. */
    protected static function clearAllRows(): void
    {
        TestDatabase::clearAllRows();
    }

    /** What rbac:sync writes, which an installation cannot work without. */
    protected static function restoreDeclaredRoles(): void
    {
        TestDatabase::restoreDeclaredRoles();
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
