<?php

declare(strict_types=1);

use App\Domain\Estimation;
use App\Domain\Failure;
use App\Models\User;
use App\Modules\CoreTicket;
use App\Services\SlotRenderer;
use App\Support\Locales;
use Example\ExtensionA\ExtensionAProvider;
use Example\ExtensionA\Jobs\ReviewReminderJob;
use Example\ExtensionA\Migrations\M202609180101ExampleReports;
use Example\ExtensionB\Services\CountingTicketService;
use Naf\Auth\Auth;
use Naf\CLI\Core\Output;
use Naf\CLI\Support\CommandRegistry;
use Naf\Database\Core\MigrationRunner;
use Naf\Database\Support\MigrationRegistry;
use Naf\ORM\Core\EntityManager;
use Naf\Queue\Core\Queue;
use Naf\Schedule\Core\JobRepository;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AiServiceInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Contracts\PageRendererInterface;
use Nafinity\Contracts\ProjectServiceInterface;
use Nafinity\Contracts\RoleServiceInterface;
use Nafinity\Contracts\TicketMetadataReaderInterface;
use Nafinity\Contracts\TicketServiceInterface;
use Nafinity\Definition\TicketFieldDefinition;
use Nafinity\Definition\UiContribution;
use Nafinity\Definition\ViewOverride;
use Nafinity\ExtensionContext;
use Nafinity\ExtensionRegistry;
use Nafinity\Support\UiContext;

use function Naf\app;
use function Naf\I18n\translation_paths;
use function Naf\route;
use function Nafinity\extensions;
use function Nafinity\settings;

/**
 * What the two example extensions actually do to a real Nafinity boot.
 *
 * This runs inside a throwaway host that installed both of them as ordinary
 * Composer packages. It uses the application's own services and its own
 * disposable database; nothing here reimplements what it is checking.
 */
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    fwrite(STDERR, "Use APP_ENV=test DB_DATABASE=nafinity_test.\n");
    exit(2);
}

// The host that installed the extensions shares this file through a symlink, so
// it names its own bootstrap rather than the development installation's.
require getenv('NAFINITY_BOOTSTRAP') ?: __DIR__ . '/../bootstrap.php';

$passed = [];
$failed = [];

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function test(string $name, callable $body): void
{
    global $passed, $failed;

    try {
        $body();
        $passed[] = $name;
    } catch (Throwable $exception) {
        $failed[] = $name . ': ' . $exception->getMessage();
    }
}

function denied(int $status, callable $body): void
{
    try {
        $body();
    } catch (Failure $exception) {
        check(
            $exception->status === $status,
            'expected ' . $status . ', got ' . $exception->status . ': ' . $exception->getMessage(),
        );

        return;
    }

    throw new RuntimeException('expected rejection ' . $status);
}

/** A provider that fails on purpose, to show what a failure looks like. */
final class BrokenProvider implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        throw new RuntimeException('deliberately broken');
    }
}

$container     = app()->container();
$pdo           = $container->get(PDO::class);
$entityManager = $container->get(EntityManager::class);
$auth          = $container->get(Auth::class);
$runner        = new MigrationRunner($pdo);
$paths         = MigrationRegistry::getPaths();

$runner->run($paths, 'down');
$runner->run($paths, 'up');

$users = [];
foreach (['alice', 'reviewer', 'stranger'] as $name) {
    $user = new User([
        'name'          => ucfirst($name),
        'email'         => $name . '@example.test',
        'password_hash' => password_hash('Test-Password-2026!', PASSWORD_DEFAULT),
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ]);
    $entityManager->save($user);
    $users[$name] = $user;
}

$projects = $container->get(ProjectServiceInterface::class);
$tickets  = $container->get(TicketServiceInterface::class);
$query    = $container->get(BoardQueryInterface::class);
$access   = $container->get(AccessInterface::class);
$roles    = $container->get(RoleServiceInterface::class);
$metadata = $container->get(TicketMetadataReaderInterface::class);

$auth->setIdentity($users['alice']);
$project = $projects->create(['name' => 'Extensions', 'description' => 'Host for the examples']);
$projects->member($project, ['email' => 'reviewer@example.test', 'role' => 'member']);
$board   = $query->board($project);
$column  = (int) $board['columns'][0]['id'];
$lane    = (int) $board['swimlanes'][0]['id'];
$current = fn() => ['board_revision' => $tickets->board($project)['revision']];

test('T01 both extensions boot after the application defaults, in index order', function () {
    $executed = extensions()->executed();

    check($executed === ['example.reports', 'example.review'], 'order: ' . implode(', ', $executed));
    // Nafinity's own definitions exist and were not overwritten by the plugins.
    check(extensions()->permissions()->has('write'), 'core permission missing');
    check(extensions()->permissions()->has(ExtensionAProvider::PERMISSION), 'plugin permission missing');
});

test('T01 a second initialization does not run the providers again', function () use ($container) {
    $before = extensions()->executed();
    extensions()->initialize($container);

    check(extensions()->executed() === $before, 'providers ran twice');
});

test('T01 registering after the pass reports where it belongs', function () {
    try {
        extensions()->register('example.too.late', ExtensionAProvider::class);
    } catch (LogicException $exception) {
        check(
            str_contains($exception->getMessage(), 'example.too.late'),
            'diagnosis does not name the extension',
        );

        return;
    }

    throw new RuntimeException('late registration was accepted');
});

test('T01 a failing provider names itself and its cause', function () use ($container) {
    $registry = new ExtensionRegistry();
    $registry->register('example.broken', BrokenProvider::class);

    try {
        $registry->initialize($container);
    } catch (LogicException $exception) {
        check(str_contains($exception->getMessage(), 'example.broken'), 'no extension id');
        check(str_contains($exception->getMessage(), 'deliberately broken'), 'no cause');

        return;
    }

    throw new RuntimeException('a broken provider did not stop the boot');
});

test('T03 a plugin route exists and core routes still answer', function () {
    $routes = route()->all();

    check(isset($routes['example.reports']), 'plugin route missing');
    check(isset($routes['board']), 'core route lost');
    check(isset($routes['health.live']), 'core callable route lost');
    check(route('example.reports', ['project' => 7]) === '/projects/7/reports', 'url wrong');
});

test('T03 a later route of the same name replaces, and remove takes it back', function () {
    $before = route()->all()['board'];

    route()->add('GET', '/projects/{project}', static fn() => null, 'board');
    check(route()->all()['board']['action'] !== $before['action'], 'override did not apply');

    route()->add('GET', $before['path'], $before['action'], 'board');
    check(route()->all()['board']['action'] === $before['action'], 'restore failed');

    route()->add('GET', '/example/removable', static fn() => null, 'example.removable');
    check(route()->remove('example.removable') === true, 'remove reported nothing');
    check(!isset(route()->all()['example.removable']), 'route survived remove');
    check(route()->remove('example.removable') === false, 'unknown remove was true');
});

test('T05 extension B decorates the bound ticket service for every consumer', function () use (
    $container,
    $tickets,
) {
    check($tickets instanceof CountingTicketService, 'ticket service is not decorated');
    check(
        $container->get(TicketServiceInterface::class) === $tickets,
        'consumers get a different instance',
    );
    // The board query is built from the container, so it sees the decorator too.
    check(
        $container->get(BoardQueryInterface::class) !== null,
        'board query could not be resolved',
    );
});

$ticketId = $tickets->create($project, [
    'title'       => 'Reviewed by the example',
    'description' => 'Carries the extension metadata',
    'priority'    => 'normal',
    'column_id'   => $column,
    'swimlane_id' => $lane,
    'metadata'    => ['example.external_id' => 'CRM-42'],
    ...$current(),
]);

test('T15 a contributed field is stored, read back and reset', function () use (
    $tickets,
    $metadata,
    $project,
    $ticketId,
    $current,
) {
    check($metadata->get($project, $ticketId, 'example.external_id') === 'CRM-42', 'not stored');
    check($metadata->get($project, $ticketId, 'example.reviewed') === false, 'default lost');

    $version = $tickets->ticket($project, $ticketId)['version'];
    $tickets->update($project, $ticketId, [
        'metadata' => ['example.reviewed' => true],
        'version'  => $version,
        ...$current(),
    ]);
    check($metadata->get($project, $ticketId, 'example.reviewed') === true, 'update lost');

    $version = $tickets->ticket($project, $ticketId)['version'];
    $tickets->update($project, $ticketId, [
        'metadata_reset' => ['example.external_id'],
        'version'        => $version,
        ...$current(),
    ]);
    check($metadata->get($project, $ticketId, 'example.external_id') === null, 'reset failed');
});

test('T16 a metadata-only change raises the version exactly once', function () use (
    $tickets,
    $project,
    $ticketId,
    $current,
) {
    $before = (int) $tickets->ticket($project, $ticketId)['version'];
    $tickets->update($project, $ticketId, [
        'metadata' => ['example.external_id' => 'CRM-77'],
        'version'  => $before,
        ...$current(),
    ]);
    $after = (int) $tickets->ticket($project, $ticketId)['version'];

    check($after === $before + 1, 'version moved by ' . ($after - $before));
});

test('T16 a rejected value changes nothing at all', function () use (
    $pdo,
    $tickets,
    $project,
    $ticketId,
    $current,
) {
    $before = $tickets->ticket($project, $ticketId);
    $rows   = (int) $pdo->query('SELECT COUNT(*) FROM ticket_metadata')->fetchColumn();

    denied(422, fn() => $tickets->update($project, $ticketId, [
        'title'    => 'Changed alongside an invalid value',
        'metadata' => ['example.reviewed' => 'perhaps'],
        'version'  => $before['version'],
        ...$current(),
    ]));

    $after = $tickets->ticket($project, $ticketId);
    check($after['title'] === $before['title'], 'title changed anyway');
    check($after['version'] === $before['version'], 'version changed anyway');
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM ticket_metadata')->fetchColumn() === $rows,
        'metadata changed anyway',
    );
});

test('T17 an unknown field key is refused instead of stored', function () use (
    $tickets,
    $project,
    $ticketId,
    $current,
) {
    denied(422, fn() => $tickets->update($project, $ticketId, [
        'metadata' => ['example.invented' => 'x'],
        'version'  => $tickets->ticket($project, $ticketId)['version'],
        ...$current(),
    ]));
});

test('T17 a core attribute cannot be claimed as a contributed field', function () {
    foreach (['project_id', 'version', 'board_revision'] as $reserved) {
        try {
            new TicketFieldDefinition($reserved, 'X', 'text');
            throw new RuntimeException('accepted reserved key ' . $reserved);
        } catch (InvalidArgumentException) {
        }
    }
});

test('E the fixed ticket areas cannot be replaced or removed', function () {
    foreach (['core.ticket.title', 'core.ticket.description', 'core.ticket.comments'] as $id) {
        try {
            extensions()->ui()->add(
                new UiContribution($id, 'ticket.main.widgets', 'example-a/ticket-widget'),
                true,
            );
            throw new RuntimeException('accepted a replacement of ' . $id);
        } catch (LogicException) {
        }

        try {
            extensions()->ui()->remove($id);
            throw new RuntimeException('accepted a removal of ' . $id);
        } catch (LogicException) {
        }
    }

    foreach (['title', 'description', 'comments'] as $key) {
        try {
            extensions()->ticketFields()->add(
                new TicketFieldDefinition($key, 'X', 'text'),
            );
            throw new RuntimeException('accepted a field named ' . $key);
        } catch (LogicException) {
        }
    }
});

test('T18 two widgets share an index and are ordered by id', function () use ($project, $ticketId) {
    $scope   = app()->container()->get(AccessInterface::class)->project($project);
    $context = new UiContext(1, $scope, UiContext::MODE_DETAIL, 'ticket', $ticketId);
    $ids     = array_map(
        static fn(array $entry) => $entry['id'],
        app()->container()->get(SlotRenderer::class)->items('ticket.main.widgets', $context),
    );

    check($ids === [
        'core.ticket.links',
        'example.reports.widget',
        'example.review.notes',
        'core.ticket.attachments',
        'core.ticket.activity',
    ], 'order: ' . implode(', ', $ids));
});

test('T18 extension B replaced extension A\'s widget under the same id', function () {
    $widget = extensions()->ui()->get('example.reports.widget');

    check($widget !== null, 'widget missing');
    check($widget->template === 'example-b/ticket-widget', 'template: ' . $widget->template);
});

test('T07 a plugin grant is stored, loaded and refused without it', function () use (
    $pdo,
    $roles,
    $projects,
    $project,
    $access,
    $auth,
    $users,
) {
    $roles->save($project, [
        'name'        => 'Prüfer',
        'permissions' => [ExtensionAProvider::PERMISSION],
    ]);
    $roleId = (int) $pdo->query('SELECT id FROM project_roles ORDER BY id DESC')->fetchColumn();

    $granted = $access->permissions($project, 'viewer', $roleId);
    check(in_array(ExtensionAProvider::PERMISSION, $granted, true), 'grant did not load');

    // Assigned the way the member administration does it, not by hand.
    $projects->member($project, ['email' => 'reviewer@example.test', 'role' => 'custom:' . $roleId]);

    $auth->setIdentity($users['reviewer']);
    $scope = $access->project($project, ExtensionAProvider::PERMISSION);
    check($scope->allows(ExtensionAProvider::PERMISSION), 'right not effective');
    check(!$scope->allows('write'), 'unrelated right appeared');

    $auth->setIdentity($users['stranger']);
    denied(404, fn() => $access->project($project, ExtensionAProvider::PERMISSION));

    $auth->setIdentity($users['alice']);
});

test('T07 a grant of a missing extension survives a role save', function () use ($pdo, $roles, $project) {
    $roleId = (int) $pdo->query('SELECT id FROM project_roles ORDER BY id DESC')->fetchColumn();
    $pdo->prepare('INSERT INTO project_role_permissions(project_id,role_id,permission) VALUES(?,?,?)')
        ->execute([$project, $roleId, 'example.gone.right']);

    $version = (int) $pdo->query('SELECT version FROM project_roles ORDER BY id DESC')->fetchColumn();
    $roles->save($project, [
        'id'          => $roleId,
        'version'     => $version,
        'name'        => 'Prüfer',
        'permissions' => [ExtensionAProvider::PERMISSION],
    ]);

    $stored = $pdo->prepare('SELECT permission FROM project_role_permissions WHERE project_id=? AND role_id=?');
    $stored->execute([$project, $roleId]);
    $names = $stored->fetchAll(PDO::FETCH_COLUMN);

    check(in_array('example.gone.right', $names, true), 'unavailable grant was dropped');
});

test('T09 the extensions declare settings that read and write', function () use ($project) {
    check(settings()->has('example.reports.compact'), 'personal setting missing');
    check(settings()->get('example.reports.compact') === false, 'default wrong');

    settings()->save(['example.reports.compact' => true]);
    check(settings()->get('example.reports.compact') === true, 'write lost');

    // Reading the project value needs only membership.
    check(settings()->forProject($project)->get('example.reports.limit') === 25, 'project default wrong');

    // Writing needs the extension's own right, which the owner was not given.
    denied(403, fn() => settings()->forProject($project)->save(['example.reports.limit' => 50]));
});

test('T09 extension B moved one field to its own card', function () {
    $definition = extensions()->settings()->find('project', 'example.reports.limit');

    check($definition !== null, 'definition lost');
    check($definition->section === 'example.review', 'section: ' . $definition->section);
});

test('T10 values, presence and the collection snapshot agree', function () {
    $all = settings()->all();

    check(array_key_exists('theme', $all), 'core value missing from all()');
    check($all['example.reports.compact'] === true, 'contributed value missing from all()');
    check(settings()->collection()->all() === $all, 'snapshot differs');

    $snapshot = settings()->collection();
    $snapshot->add('example.reports.compact', false);
    check(settings()->get('example.reports.compact') === true, 'snapshot write persisted');
});

test('T11 contexts stay apart and a stranger reads nothing', function () use ($project, $auth, $users) {
    check(!array_key_exists('example.reports.limit', settings()->all()), 'project value leaked into user scope');
    check(settings()->forApplication()->get('mail_enabled') === false, 'application value wrong');

    $auth->setIdentity($users['stranger']);
    denied(404, fn() => settings()->forProject($project)->all());
    $auth->setIdentity($users['alice']);
});

test('T23 a contributed filter reaches the count and the cards', function () use (
    $query,
    $project,
    $tickets,
    $ticketId,
    $current,
) {
    $version = $tickets->ticket($project, $ticketId)['version'];
    $tickets->update($project, $ticketId, [
        'metadata' => ['example.reviewed' => true],
        'version'  => $version,
        ...$current(),
    ]);

    $reviewed = $query->board($project, ['filters' => ['example.reviewed' => '1']]);
    check($reviewed['total'] === 1, 'reviewed total: ' . $reviewed['total']);
    check(count($reviewed['cards']) === 1, 'reviewed cards: ' . count($reviewed['cards']));

    $open = $query->board($project, ['filters' => ['example.reviewed' => '0']]);
    check($open['total'] === 0, 'unreviewed total: ' . $open['total']);

    try {
        $query->board($project, ['filters' => ['example.invented' => '1']]);
        throw new RuntimeException('unknown filter was ignored');
    } catch (Failure $exception) {
        check($exception->status === 422, 'status ' . $exception->status);
    }
});

test('T08 a view override applies and the host wins last', function () use ($container) {
    $pages = $container->get(PageRendererInterface::class);

    check(
        extensions()->views()->resolve('example-a/reports') === 'example-b/reports',
        'plugin override did not apply',
    );

    extensions()->views()->add(new ViewOverride('example-a/reports', 'example-a/reports'), true);
    check(
        extensions()->views()->resolve('example-a/reports') === 'example-a/reports',
        'host override did not win',
    );

    extensions()->views()->add(new ViewOverride('example-a/reports', 'example-b/reports'), true);
    check($pages instanceof PageRendererInterface, 'renderer missing');
});

test('T08 a mapping cycle is reported', function () {
    extensions()->views()->add(new ViewOverride('example/cycle-one', 'example/cycle-two'));
    extensions()->views()->add(new ViewOverride('example/cycle-two', 'example/cycle-one'));

    try {
        extensions()->views()->resolve('example/cycle-one');
        throw new RuntimeException('a cycle resolved');
    } catch (LogicException $exception) {
        check(str_contains($exception->getMessage(), 'cycle'), 'no diagnosis');
    } finally {
        extensions()->views()->remove('example/cycle-one');
        extensions()->views()->remove('example/cycle-two');
    }
});

test('T26 the contributed tool appears only with its grant', function () use ($container, $project, $auth, $users) {
    $ai    = $container->get(AiServiceInterface::class);
    $names = array_column($ai->definitions($project), 'name');

    check(in_array('nafinity_board', $names, true), 'core tool missing');
    check(!in_array('example_reports', $names, true), 'tool appeared without the grant');

    $auth->setIdentity($users['reviewer']);
    $withGrant = array_column($ai->definitions($project), 'name');
    check(in_array('example_reports', $withGrant, true), 'tool missing with the grant');

    $auth->setIdentity($users['alice']);
});

test('T27 the plugin translation is available and the application wins', function () {
    $registered = translation_paths()->all();

    check(isset($registered['example.reports']), 'translation path not registered');
    check(
        array_key_exists('fr', Locales::available()),
        'French did not become selectable',
    );

    $translator = Naf\I18n\translator();
    $before     = $translator->getLanguage();
    $translator->setLanguage('fr');
    check($translator->translate('Prüfung') === 'Vérification', 'plugin translation not used');
    $translator->setLanguage((string) $before);
});

test('T25 a plugin listener enqueues in the same transaction, and a rollback takes it back', function () use (
    $pdo,
    $tickets,
    $project,
    $ticketId,
    $current,
) {
    $pdo->exec('DELETE FROM naf_queue_jobs');

    $version = $tickets->ticket($project, $ticketId)['version'];
    $tickets->update($project, $ticketId, [
        'metadata' => ['example.reviewed' => false],
        'version'  => $version,
        ...$current(),
    ]);

    $queued = $pdo->query(
        "SELECT COUNT(*) FROM naf_queue_jobs WHERE job_class LIKE '%ReviewNoticeJob%'",
    )->fetchColumn();
    check((int) $queued === 1, 'the listener enqueued ' . $queued . ' jobs');

    // A change that is refused must leave no job behind, because the listener
    // runs inside the very transaction that is rolled back.
    denied(422, fn() => $tickets->update($project, $ticketId, [
        'metadata' => ['example.reviewed' => 'perhaps'],
        'version'  => $tickets->ticket($project, $ticketId)['version'],
        ...$current(),
    ]));

    $after = $pdo->query(
        "SELECT COUNT(*) FROM naf_queue_jobs WHERE job_class LIKE '%ReviewNoticeJob%'",
    )->fetchColumn();
    check((int) $after === 1, 'a rejected change left ' . ($after - $queued) . ' extra jobs');
});

test('T29 the plugin command, migration, job and schedule entry are all there', function () use (
    $container,
    $pdo,
) {
    $commands = array_keys($container->get(CommandRegistry::class)->all());
    check(in_array('example:reports', $commands, true), 'command missing');

    $applied = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    check(
        in_array(M202609180101ExampleReports::class, $applied, true),
        'migration not applied',
    );
    check(
        $pdo->query('SELECT COUNT(*) FROM example_report_runs')->fetchColumn() !== false,
        'the extension table is missing',
    );

    $scheduled = $container->get(JobRepository::class)->all();
    check(
        array_key_exists(ReviewReminderJob::class, $scheduled),
        'schedule entry missing: ' . implode(', ', array_keys($scheduled)),
    );
});

test('T29 the queued plugin job runs and writes only its own table', function () use ($container, $pdo) {
    $queue = $container->get(Queue::class);
    $job   = $queue->pop();
    check($job !== null, 'nothing was queued');

    $before = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
    $output = new Output();
    $class  = $job['class'] ?? $job['job_class'] ?? null;
    check(is_string($class) && str_contains($class, 'ReviewNoticeJob'), 'wrong job: ' . json_encode($job));

    $payload  = is_string($job['payload'] ?? null) ? json_decode($job['payload'], true) : ($job['payload'] ?? []);
    $instance = app()->container()->make($class, is_array($payload) ? $payload : []);
    ob_start();
    $instance->execute($output);
    ob_end_clean();

    check(
        (int) $pdo->query('SELECT COUNT(*) FROM example_report_runs')->fetchColumn() === 1,
        'the job wrote nothing',
    );
    check(
        (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() === $before,
        'the job touched the tickets',
    );
});

test('T24 a contributed estimation scale is the same everywhere', function () use ($projects, $project) {
    check(extensions()->estimationScales()->has('example.tshirt'), 'scale not registered');
    check(Estimation::values('example.tshirt') === [1, 2, 3, 5, 8, 13], 'values differ');
    check(Estimation::unit('example.tshirt') === 'TS', 'unit differs');

    $projects->update($project, [
        'name'             => 'Extensions',
        'description'      => 'Host for the examples',
        'color'            => '#6366f1',
        'icon'             => 'N',
        'ticket_key'       => 'EXT',
        'estimation_scale' => 'example.tshirt',
    ]);

    check(
        Estimation::scale(
            app()->container()->get(AccessInterface::class)->project($project)->project['estimation_scale'],
        ) === 'example.tshirt',
        'the project did not keep the scale',
    );
});

test('T19 ticket field groups all point at a registered panel', function () {
    $groups = CoreTicket::groups(new ExtensionContext(app()->container(), extensions()));

    check(in_array('details', $groups, true), 'core group missing');
    extensions()->ticketFields()->assertGroups($groups);
});

echo json_encode(
    [
        'host'       => 'extension-host',
        'extensions' => extensions()->executed(),
        'passed'     => count($passed),
        'failed'     => $failed,
        'tests'      => $passed,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";

exit($failed === [] ? 0 : 1);
