<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Example\ExtensionA\ExtensionAProvider;
use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\ProjectServiceInterface;
use Naf\Board\Contracts\RoleServiceInterface;
use Naf\Board\Contracts\TicketMetadataReaderInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Models\User;
use Naf\Board\Rbac\Grants;
use Naf\Database\Core\MigrationRunner;
use Naf\Database\Support\MigrationRegistry;
use Naf\ORM\Core\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

use function Naf\app;
use function Naf\Rbac\rbac;

/**
 * The throwaway host with both example extensions installed.
 *
 * Services come from the container through their interfaces, never their
 * classes: extension B replaces the ticket service, and a test that reached past
 * the container would quietly check the wrong object.
 *
 * The world is built once for the whole class rather than per test. These tests
 * are about registrations -- what booted, what replaced what, which routes exist
 * -- and those live in the process, not in a row that could be rolled back. The
 * few that write build what they write.
 */
abstract class ExtensionInstalledTestCase extends TestCase
{
    use AssertsFailures;

    protected PDO $pdo;
    protected EntityManager $entityManager;
    protected Auth $auth;
    protected ProjectServiceInterface $projects;
    protected TicketServiceInterface $tickets;
    protected BoardQueryInterface $query;
    protected AccessInterface $access;
    protected RoleServiceInterface $roles;
    protected TicketMetadataReaderInterface $metadata;

    /** @var array<string,User> */
    protected array $users = [];
    protected int $project;
    protected int $column;
    protected int $lane;

    private static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        $container           = app()->container();
        $this->pdo           = $container->get(PDO::class);
        $this->entityManager = $container->get(EntityManager::class);
        $this->auth          = $container->get(Auth::class);
        $this->projects      = $container->get(ProjectServiceInterface::class);
        $this->tickets       = $container->get(TicketServiceInterface::class);
        $this->query         = $container->get(BoardQueryInterface::class);
        $this->access        = $container->get(AccessInterface::class);
        $this->roles         = $container->get(RoleServiceInterface::class);
        $this->metadata      = $container->get(TicketMetadataReaderInterface::class);

        $this->prepareHost();
        $this->loadWorld();
    }

    /**
     * Build the schema and the world once for the whole run.
     *
     * Once, because the later phases of bin/check-extensions work on what this
     * one left behind: the host without the extensions is asked about rows the
     * host with them wrote.
     */
    private function prepareHost(): void
    {
        if (self::$prepared) {
            return;
        }
        $runner = new MigrationRunner($this->pdo);
        $runner->run(MigrationRegistry::getPaths(), 'down');
        $runner->run(MigrationRegistry::getPaths(), 'up');

        // Rebuilding takes the declared roles with it, and an account without
        // one may do nothing at all. This case builds its own schema rather than
        // going through DatabaseTestCase, so it writes them back itself.
        //
        // Reapplied, unlike in a real installation. There the rule is that what
        // an installation changed about a role is its own and survives an
        // upgrade -- which also means a permission declared after the roles were
        // written reaches nobody until somebody grants it. A throwaway host has
        // nothing of its own to protect and everything to gain from carrying
        // exactly what the packages declare, including whatever was declared
        // since the last run.
        $rbac = rbac();
        $rbac->roles->syncDeclared($rbac->declared->all(), $rbac->declared, true);
        $rbac->forget();

        $hash = password_hash('Test-Password-2026!', PASSWORD_DEFAULT);
        foreach (['alice', 'reviewer', 'stranger'] as $name) {
            $account = new User([
                'name'          => ucfirst($name),
                'email'         => $name . '@example.test',
                'password_hash' => $hash,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);
            $this->entityManager->save($account);
            Grants::ensureDefault((int) $account->getId());
        }

        $this->auth->setIdentity($this->userNamed('alice'));
        $project = $this->projects->create([
            'name'        => 'Extensions',
            'description' => 'Host for the examples',
        ]);
        $this->projects->member($project, ['email' => 'reviewer@example.test', 'role' => 'member']);

        self::$prepared = true;
    }

    private function loadWorld(): void
    {
        foreach (['alice', 'reviewer', 'stranger'] as $name) {
            $this->users[$name] = $this->userNamed($name);
        }
        $this->auth->setIdentity($this->users['alice']);

        $this->project = (int) $this->pdo->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
        $board         = $this->query->board($this->project);
        $this->column  = (int) $board['columns'][0]['id'];
        $this->lane    = (int) $board['swimlanes'][0]['id'];
    }

    protected function userNamed(string $name): User
    {
        $row = $this->pdo->prepare('SELECT * FROM users WHERE email=?');
        $row->execute([$name . '@example.test']);

        return new User($row->fetch(PDO::FETCH_ASSOC));
    }

    protected function actAs(string $name): void
    {
        $this->auth->setIdentity($this->users[$name]);
    }

    /** The board revision a write has to carry. */
    protected function revision(): array
    {
        return ['board_revision' => $this->tickets->board($this->project)['revision']];
    }

    /**
     * A ticket in the host's project, with whatever a test needs on it.
     *
     * @param array<string,mixed> $overrides
     */
    protected function createTicket(array $overrides = []): int
    {
        return $this->tickets->create($this->project, array_replace([
            'title'       => 'Reviewed by the example',
            'description' => 'Carries the extension metadata',
            'priority'    => 'normal',
            'column_id'   => $this->column,
            'swimlane_id' => $this->lane,
            'metadata'    => ['example.external_id' => 'CRM-42'],
        ], $this->revision(), $overrides));
    }

    /** The version a write on this ticket has to carry, read now. */
    protected function ticketVersion(int $ticket): mixed
    {
        return $this->tickets->ticket($this->project, $ticket)['version'];
    }

    /**
     * The custom role that carries extension A's right, and the reviewer in it.
     *
     * Created once for the host and shared: the later phases read it too -- the
     * HTTP checks sign in as the reviewer and expect the contributed page to
     * answer, which is this grant taking effect through a real request.
     */
    protected function reviewerRole(): int
    {
        $existing = $this->scalar(
            "SELECT id FROM project_roles WHERE project_id=? AND name='Prüfer'",
            [$this->project],
        );
        if ($existing !== false) {
            return (int) $existing;
        }

        $this->roles->save($this->project, [
            'name'        => 'Prüfer',
            'permissions' => [ExtensionAProvider::PERMISSION],
        ]);
        $role = (int) $this->scalar(
            "SELECT id FROM project_roles WHERE project_id=? AND name='Prüfer'",
            [$this->project],
        );
        $this->projects->member($this->project, [
            'email' => 'reviewer@example.test',
            'role'  => 'custom:' . $role,
        ]);

        return $role;
    }

    protected function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }
}
