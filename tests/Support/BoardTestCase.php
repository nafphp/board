<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Auth\Auth;
use Naf\Board\Models\User;
use Naf\Board\Rbac\Grants;
use Naf\Board\Services\Access;
use Naf\Board\Services\BoardQuery;
use Naf\Board\Services\CommentService;
use Naf\Board\Services\ProjectService;
use Naf\Board\Services\TicketService;
use Naf\ORM\Core\EntityManager;

use function Naf\app;

/**
 * The world most board tests need: two projects that must not see each other.
 *
 * Alice owns A and has a viewer and a member in it. Bob owns B and is in nothing
 * else. Almost every rule worth testing here is a rule about that boundary, so
 * building it once per test is cheaper to read than assembling it in each.
 *
 * It is built inside the surrounding transaction, which means every test gets its
 * own copy and none of them can leave a trace for the next. That is the part the
 * old runner could not do: there, one shared world grew through the whole file
 * and the order tests ran in was part of the result.
 */
abstract class BoardTestCase extends DatabaseTestCase
{
    use AssertsFailures;

    protected EntityManager $entityManager;
    protected Auth $auth;
    protected ProjectService $projects;
    protected TicketService $tickets;
    protected BoardQuery $query;
    protected CommentService $comments;
    protected Access $access;

    /** @var array<string,User> */
    protected array $users = [];

    protected User $alice;
    protected User $bob;
    protected User $viewer;
    protected User $member;

    protected int $projectA;
    protected int $projectB;
    /** @var array<string,mixed> */
    protected array $boardA;
    /** @var array<string,mixed> */
    protected array $boardB;
    protected int $labelB;
    protected int $columnA;
    protected int $laneA;

    private static ?string $passwordHash = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container           = app()->container();
        $this->entityManager = $container->get(EntityManager::class);
        $this->auth          = $container->get(Auth::class);
        $this->projects      = $container->make(ProjectService::class);
        $this->tickets       = $container->make(TicketService::class);
        $this->query         = $container->make(BoardQuery::class);
        $this->comments      = $container->make(CommentService::class);
        $this->access        = $container->make(Access::class);

        foreach (['alice', 'bob', 'viewer', 'member'] as $name) {
            $this->users[$name] = $this->createUser($name);
        }
        $this->alice  = $this->users['alice'];
        $this->bob    = $this->users['bob'];
        $this->viewer = $this->users['viewer'];
        $this->member = $this->users['member'];

        $this->actAs($this->alice);
        $this->projectA = $this->projects->create(['name' => 'A', 'description' => 'Private Alpha']);
        $this->projects->member($this->projectA, ['email' => 'viewer@example.test', 'role' => 'viewer']);
        $this->projects->member($this->projectA, ['email' => 'member@example.test', 'role' => 'member']);

        $this->actAs($this->bob);
        $this->projectB = $this->projects->create(['name' => 'B', 'description' => 'Private Beta']);
        $this->boardB   = $this->query->board($this->projectB);
        $this->projects->structure($this->projectB, ['kind' => 'label', 'name' => 'Secret Beta']);
        $this->labelB = (int) $this->scalar('SELECT id FROM labels WHERE project_id=?', [$this->projectB]);

        $this->actAs($this->alice);
        $this->boardA  = $this->query->board($this->projectA);
        $this->columnA = (int) $this->boardA['columns'][0]['id'];
        $this->laneA   = (int) $this->boardA['swimlanes'][0]['id'];
    }

    protected function tearDown(): void
    {
        // The identity outlives a single test otherwise: Auth holds it in the
        // container, which the suite shares, and the next test would start as
        // whoever the last one happened to end as.
        $this->auth->logout();
        parent::tearDown();

        // A world that was not built inside a transaction cannot be rolled back,
        // and the next test builds its own -- which the first Alice would refuse,
        // her address being unique. So the few classes that cannot run in a
        // transaction pay for a rebuilt schema between their tests.
        if (!$this->transactional) {
            self::clearAllRows();
        }
    }

    protected function createUser(string $name): User
    {
        $user = new User([
            'name'          => ucfirst($name),
            'email'         => $name . '@example.test',
            'password_hash' => self::passwordHash(),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->save($user);
        Grants::ensureDefault((int) $user->getId());

        return $user;
    }

    protected function actAs(User $user): void
    {
        $this->auth->setIdentity($user);
    }

    /**
     * One hash for the whole run.
     *
     * A password hash is deliberately expensive to compute -- that is the entire
     * point of one -- and every test here builds four users. Hashing the same
     * fixture password again for each of them cost more than everything else the
     * suite does put together. It is still a real hash of a real password, so
     * anything that checks one still gets a true answer.
     */
    private static function passwordHash(): string
    {
        return self::$passwordHash ??= password_hash('Test-Password-2026!', PASSWORD_DEFAULT);
    }

    /**
     * The payload for an ordinary ticket in project A.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function ticketData(array $overrides = []): array
    {
        return array_replace([
            'title'       => 'Searchable sunflower',
            'description' => 'Detailed work item',
            'priority'    => 'normal',
            'column_id'   => $this->columnA,
            'swimlane_id' => $this->laneA,
        ], $this->revision($this->projectA), $overrides);
    }

    /**
     * The board revision a write has to carry to be accepted.
     *
     * @return array{board_revision:mixed}
     */
    protected function revision(?int $project = null): array
    {
        return ['board_revision' => $this->tickets->board($project ?? $this->projectA)['revision']];
    }
}
