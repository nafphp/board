<?php

declare(strict_types=1);

use Naf\Auth\Auth;
use Naf\Board\Domain\Failure;
use Naf\Board\Models\User;
use Naf\Board\Services\Access;
use Naf\Board\Services\BoardQuery;
use Naf\Board\Services\CommentService;
use Naf\Board\Services\ProjectService;
use Naf\Board\Services\TicketService;
use Naf\Database\Core\MigrationRunner;
use Naf\Database\Support\MigrationRegistry;
use Naf\ORM\Core\EntityManager;
use Naf\Session\Storage\DatabaseSessionHandler;

use function Naf\app;

// Destructive schema checks are confined to an explicitly selected disposable DB.
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    fwrite(
        STDERR,
        "Use APP_ENV=test DB_DATABASE=nafinity_test. Demo data is never a test target.\n",
    );
    exit(2);
}
require __DIR__ . '/bootstrap.php';


$c             = app()->container();
$pdo           = $c->get(PDO::class);
$entityManager = $c->get(EntityManager::class);
$auth          = $c->get(Auth::class);
$runner        = new MigrationRunner($pdo);
$paths         = MigrationRegistry::getPaths();
$passed        = [];
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function test(string $name, callable $fn): void
{
    global $passed;
    $fn();
    $passed[] = $name;
}
function denied(int $status, callable $fn): void
{
    try {
        $fn();
    } catch (Failure $exception) {
        check(
            $exception->status === $status,
            'Expected ' . $status . ', received ' . $exception->status . ': ' . $exception->getMessage(),
        );

        return;
    }
    throw new RuntimeException('Expected rejection ' . $status);
}
function scalar(string $sql, array $params = []): mixed
{
    global $pdo;
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchColumn();
}

try {
    $runner->run($paths, 'down');
    test('fresh migration and repeated up', function () use ($runner, $paths) {
        check(count($runner->run($paths, 'up')) >= 4, 'plugin migrations missing');
        check($runner->run($paths, 'up') === [], 'second up changed schema');
    });
    $users = [];
    foreach (['alice', 'bob', 'viewer', 'member'] as $name) {
        $user = new User([
            'name'          => ucfirst($name),
            'email'         => $name . '@example.test',
            'password_hash' => password_hash('Test-Password-2026!', PASSWORD_DEFAULT),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        $entityManager->save($user);
        $users[$name] = $user;
    }
    $projects = $c->make(ProjectService::class);
    $tickets  = $c->make(TicketService::class);
    $query    = $c->make(BoardQuery::class);
    $comments = $c->make(CommentService::class);
    $access   = $c->make(Access::class);
    $auth->setIdentity($users['alice']);
    $a = $projects->create(['name' => 'A', 'description' => 'Private Alpha']);
    $projects->member($a, ['email' => 'viewer@example.test', 'role' => 'viewer']);
    $projects->member($a, ['email' => 'member@example.test', 'role' => 'member']);
    $auth->setIdentity($users['bob']);
    $b      = $projects->create(['name' => 'B', 'description' => 'Private Beta']);
    $boardB = $query->board($b);
    $projects->structure($b, ['kind' => 'label', 'name' => 'Secret Beta']);
    $labelB = (int) scalar('SELECT id FROM labels WHERE project_id=?', [$b]);
    $auth->setIdentity($users['alice']);
    $boardA = $query->board($a);
    $col    = (int) $boardA['columns'][0]['id'];
    $lane   = (int) $boardA['swimlanes'][0]['id'];
    $data   = [
        'title'       => 'Searchable sunflower',
        'description' => 'Detailed work item',
        'priority'    => 'normal',
        'column_id'   => $col,
        'swimlane_id' => $lane,
    ];
    $current = fn() => ['board_revision' => $tickets->board($a)['revision']];
    test('project list and direct read isolation', function () use ($query, $a, $b) {
        check(array_column($query->projects(), 'id') == [$a], 'Project list leaked');
        denied(404, fn() => $query->board($b));
        denied(404, fn() => $query->activity($b));
    });
    $id = $tickets->create($a, $data + $current());
    test('ORM persisted ticket and transactional activity', function () use (
        $pdo,
        $id,
        $query,
        $a,
    ) {
        check(
            $query->detail($a, $id)['ticket']['title'] === 'Searchable sunflower',
            'ticket missing',
        );
        check(
            (int) scalar(
                "SELECT COUNT(*) FROM activities WHERE ticket_id=? AND event_type='ticket.created'",
                [$id],
            ) === 1,
            'activity missing',
        );
    });
    test('cross-project column/lane/assignee/label reject with rollback', function () use (
        $tickets,
        $a,
        $data,
        $current,
        $boardB,
        $users,
        $labelB,
    ) {
        $before   = scalar('SELECT COUNT(*) FROM tickets');
        $activity = scalar('SELECT COUNT(*) FROM activities');
        foreach (
            [
                'column_id'    => $boardB['columns'][0]['id'],
                'swimlane_id'  => $boardB['swimlanes'][0]['id'],
                'assignee_ids' => [$users['bob']->getId()],
                'label_ids'    => [$labelB],
            ] as $key => $value
        ) {
            denied(
                422,
                fn() => $tickets->create($a, array_replace($data, $current(), [$key => $value])),
            );
        }
        check(scalar('SELECT COUNT(*) FROM tickets') === $before, 'failed insert persisted');
        check(scalar('SELECT COUNT(*) FROM activities') === $activity, 'failed activity persisted');
    });
    test('payload types and invalid date rejected', function () use (
        $tickets,
        $a,
        $data,
        $current,
    ) {
        foreach (
            [
                'title'        => [],
                'priority'     => [],
                'due_date'     => '2026-02-30',
                'assignee_ids' => '1,2',
                'label_ids'    => [[]],
            ] as $key => $value
        ) {
            denied(
                422,
                fn() => $tickets->create($a, array_replace($data, $current(), [$key => $value])),
            );
        }
    });
    test('viewer writes denied', function () use (
        $auth,
        $users,
        $tickets,
        $a,
        $data,
        $current,
        $projects,
        $comments,
        $id,
    ) {
        $auth->setIdentity($users['viewer']);
        foreach (
            [
                fn() => $tickets->create($a, $data + $current()),
                fn() => $tickets->move($a, $id, []),
                fn() => $projects->archive($a, true),
                fn() => $comments->save($a, $id, ['body' => 'No']),
            ] as $fn
        ) {
            denied(403, $fn);
        }
        $auth->setIdentity($users['alice']);
    });
    test('move persists and stale version is 409 without activity', function () use (
        $tickets,
        $a,
        $id,
        $boardA,
        $lane,
        $current,
    ) {
        $before = scalar('SELECT COUNT(*) FROM activities');
        $move   = ['version' => 1, 'column_id' => $boardA['columns'][1]['id'], 'swimlane_id' => $lane]
            + $current();
        $tickets->move($a, $id, $move);
        check(
            (int) $tickets->ticket($a, $id)['column_id'] === (int) $boardA['columns'][1]['id'],
            'move lost',
        );
        denied(409, fn() => $tickets->move($a, $id, $move));
        check(
            (int) scalar('SELECT COUNT(*) FROM activities') === (int) $before + 1,
            'rejected move logged',
        );
    });
    test('stale board revision rejected', function () use ($tickets, $a, $data) {
        denied(409, fn() => $tickets->create($a, $data + ['board_revision' => 1]));
    });
    test('nonmember cannot mutate or read ticket', function () use (
        $auth,
        $users,
        $tickets,
        $query,
        $a,
        $id,
    ) {
        $auth->setIdentity($users['bob']);
        denied(404, fn() => $tickets->move($a, $id, []));
        denied(404, fn() => $query->detail($a, $id));
        $auth->setIdentity($users['alice']);
    });
    $first  = $tickets->create($a, $data + $current());
    $second = $tickets->create($a, $data + $current());
    test('dense position rebalance preserves unique order', function () use (
        $pdo,
        $tickets,
        $a,
        $id,
        $first,
        $second,
        $col,
        $lane,
        $current,
    ) {
        $pdo->prepare('UPDATE tickets SET position=1 WHERE id=?')->execute([$first]);
        $pdo->prepare('UPDATE tickets SET position=2 WHERE id=?')->execute([$second]);
        $tickets->move(
            $a,
            $id,
            [
                'version'     => 2,
                'column_id'   => $col,
                'swimlane_id' => $lane,
                'placement'   => 'between',
                'left_id'     => $first,
                'right_id'    => $second,
            ] + $current(),
        );
        $statement = $pdo->prepare('SELECT id FROM tickets WHERE project_id=? ORDER BY position');
        $statement->execute([$a]);
        check(
            array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)) === [$first, $id, $second],
            'wrong order after rebalance',
        );
    });
    test('foreign/nonadjacent neighbors rejected', function () use (
        $tickets,
        $a,
        $id,
        $col,
        $lane,
        $second,
        $first,
        $current,
    ) {
        denied(
            409,
            fn() => $tickets->move(
                $a,
                $id,
                [
                    'version'     => 3,
                    'column_id'   => $col,
                    'swimlane_id' => $lane,
                    'placement'   => 'between',
                    'left_id'     => $second,
                    'right_id'    => $first,
                ] + $current(),
            ),
        );
    });
    test('ORM external transaction remains owned by caller', function () use (
        $pdo,
        $tickets,
        $a,
        $data,
        $current,
    ) {
        $before = scalar('SELECT COUNT(*) FROM tickets');
        $pdo->beginTransaction();
        $tickets->create($a, $data + $current());
        check($pdo->inTransaction(), 'service committed external transaction');
        $pdo->rollBack();
        check(scalar('SELECT COUNT(*) FROM tickets') === $before, 'rollback did not remove ticket');
    });
    test('comment authorship and versions', function () use ($auth, $users, $comments, $a, $id) {
        $comments->save($a, $id, ['body' => 'Alice note']);
        $comment = (int) scalar('SELECT MAX(id) FROM comments');
        $auth->setIdentity($users['member']);
        denied(
            403,
            fn() => $comments->save($a, $id, [
                'id'      => $comment,
                'version' => 1,
                'body'    => 'Hijack',
            ]),
        );
        $auth->setIdentity($users['alice']);
        $comments->save($a, $id, ['id' => $comment, 'version' => 1, 'body' => 'Revised']);
        denied(
            409,
            fn() => $comments->save($a, $id, [
                'id'      => $comment,
                'version' => 1,
                'body'    => 'Lost update',
            ]),
        );
        $comments->save($a, $id, ['id' => $comment, 'version' => 2, 'action' => 'delete']);
        check(
            scalar('SELECT deleted_at FROM comments WHERE id=?', [$comment]) !== null,
            'comment not soft deleted',
        );
    });
    test('last owner and role delegation protected', function () use (
        $projects,
        $a,
        $auth,
        $users,
    ) {
        denied(
            422,
            fn() => $projects->member($a, ['email' => 'alice@example.test', 'role' => 'remove']),
        );
        $projects->member($a, ['email' => 'member@example.test', 'role' => 'manager']);
        $auth->setIdentity($users['member']);
        denied(
            403,
            fn() => $projects->member($a, ['email' => 'viewer@example.test', 'role' => 'owner']),
        );
        denied(
            403,
            fn() => $projects->member($a, ['email' => 'alice@example.test', 'role' => 'viewer']),
        );
        $auth->setIdentity($users['alice']);
    });
    test('active membership revoked immediately', function () use (
        $projects,
        $a,
        $auth,
        $users,
        $query,
    ) {
        $projects->member($a, ['email' => 'viewer@example.test', 'role' => 'remove']);
        $auth->setIdentity($users['viewer']);
        denied(404, fn() => $query->board($a));
        check($query->projects() === [], 'removed project listed');
        $auth->setIdentity($users['alice']);
    });
    test('nonempty column and default lane cannot be removed', function () use (
        $projects,
        $a,
        $col,
        $lane,
    ) {
        denied(
            422,
            fn() => $projects->structure($a, [
                'kind'   => 'column',
                'id'     => $col,
                'action' => 'delete',
            ]),
        );
        denied(
            422,
            fn() => $projects->structure($a, [
                'kind'   => 'swimlane',
                'id'     => $lane,
                'action' => 'delete',
            ]),
        );
    });
    test('fulltext and combined filters stay scoped', function () use ($query, $a, $labelB) {
        check($query->board($a, ['q' => 'sunflower'])['total'] === 3, 'real fulltext failed');
        check($query->board($a, ['label' => $labelB])['total'] === 0, 'foreign filter leaked');
        check(
            $query->board($a, ['q' => 'sunflower', 'priority' => 'urgent'])['total'] === 0,
            'filters not combined',
        );
    });
    test('archive is read-only and restorable', function () use (
        $projects,
        $tickets,
        $query,
        $a,
        $data,
        $current,
    ) {
        $projects->archive($a, true);
        check($query->board($a)['project']['archived_at'] !== null, 'archive absent');
        denied(403, fn() => $tickets->create($a, $data + $current()));
        $projects->archive($a, false);
        check($query->board($a)['project']['archived_at'] === null, 'restore failed');
    });
    test('native database sessions insert update read and destroy', function () use ($pdo) {
        $session = new DatabaseSessionHandler($pdo, 'sessions');
        check($session->write('regression-session', 'first'), 'insert failed');
        check($session->write('regression-session', 'second'), 'upsert failed');
        check($session->read('regression-session') === 'second', 'read failed');
        check($session->destroy('regression-session'), 'destroy failed');
        check($session->read('regression-session') === '', 'session persisted');
    });
    // Additional plugin/application cases are loaded here as integrations are added.
    foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $case) {
        require $case;
    }
    $auth->logout();
    test('all app/plugin migrations down and fresh up', function () use ($runner, $paths) {
        $up = (int) scalar('SELECT COUNT(*) FROM migrations');
        check(count($runner->run($paths, 'down')) === $up, 'down missing migrations');
        check(count($runner->run($paths, 'up')) === $up, 'reinstall failed');
    });
    echo json_encode(
        [
            'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'passed' => count($passed),
            'tests'  => $passed,
        ],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ) . "\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
}
