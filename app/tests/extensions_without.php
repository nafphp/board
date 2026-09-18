<?php

declare(strict_types=1);

use App\Models\User;
use Naf\Auth\Auth;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\TicketMetadataReaderInterface;
use Nafinity\Contracts\TicketServiceInterface;

use function Naf\app;
use function Nafinity\extensions;
use function Nafinity\settings;

/**
 * The same installation, booted without the two example extensions.
 *
 * Uninstalling a package takes its contributions away. It does not take away
 * what people stored while it was there: the values, the files, the grants and
 * the history all stay, and come back when the package does.
 */
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    fwrite(STDERR, "Use APP_ENV=test DB_DATABASE=nafinity_test.\n");
    exit(2);
}

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

$container = app()->container();
$pdo       = $container->get(PDO::class);
$auth      = $container->get(Auth::class);

$row = $pdo->query("SELECT * FROM users WHERE email='alice@example.test'")->fetch(PDO::FETCH_ASSOC);
$auth->setIdentity(new User($row));

$project  = (int) $pdo->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
$ticket   = (int) $pdo->query('SELECT id FROM tickets ORDER BY id LIMIT 1')->fetchColumn();
$query    = $container->get(BoardQueryInterface::class);
$metadata = $container->get(TicketMetadataReaderInterface::class);
$access   = $container->get(AccessInterface::class);

test('T30 no extension is registered any more', function () {
    check(extensions()->executed() === [], 'a provider still ran');
    check(!extensions()->permissions()->has('example.reports.view'), 'permission still defined');
    check(extensions()->ui()->get('example.reports.widget') === null, 'widget still defined');
    check(extensions()->boardFilters()->get('example.reviewed') === null, 'filter still defined');
    check(extensions()->settings()->find('project', 'example.reports.limit') === null, 'setting still defined');
});

test('T30 the stored values are still there', function () use ($pdo, $project, $ticket) {
    $stored = $pdo->prepare('SELECT meta_key FROM ticket_metadata WHERE project_id=? AND ticket_id=?');
    $stored->execute([$project, $ticket]);
    $keys = $stored->fetchAll(PDO::FETCH_COLUMN);

    check(in_array('example.reviewed', $keys, true), 'metadata was deleted');

    $grants = $pdo->query(
        "SELECT COUNT(*) FROM project_role_permissions WHERE permission='example.reports.view'",
    )->fetchColumn();
    check((int) $grants === 1, 'the grant was deleted');

    $personal = $pdo->query(
        "SELECT COUNT(*) FROM user_settings WHERE setting_key='example.reports.compact'",
    )->fetchColumn();
    check((int) $personal === 1, 'the personal value was deleted');

    check(
        $pdo->query('SELECT COUNT(*) FROM example_report_runs')->fetchColumn() !== false,
        'the extension table was dropped',
    );
});

test('T30 values of a missing extension are not authorized or shown', function () use (
    $access,
    $metadata,
    $project,
    $ticket,
) {
    $scope = $access->project($project);
    check(!$scope->allows('example.reports.view'), 'a missing permission still authorizes');
    check($metadata->get($project, $ticket, 'example.reviewed') === null, 'an unknown key answered');
    check($metadata->all($project, $ticket) === [], 'unknown values were listed');
});

test('T30 the ticket reports that something is missing, without its values', function () use (
    $query,
    $project,
    $ticket,
) {
    $detail = $query->detail($project, $ticket);

    check($detail['metadata'] === [], 'raw values were handed out');
    check($detail['metaDefinitions'] === [], 'definitions appeared');
    check(in_array('example.reviewed', $detail['metaUnknown'], true), 'the missing key was not reported');
});

test('T30 a core ticket change leaves unknown values untouched', function () use (
    $container,
    $pdo,
    $project,
    $ticket,
) {
    $tickets = $container->get(TicketServiceInterface::class);
    $before  = (int) $pdo->query('SELECT COUNT(*) FROM ticket_metadata')->fetchColumn();
    $row     = $tickets->ticket($project, $ticket);

    $tickets->update($project, $ticket, [
        'title'          => 'Renamed without the extension',
        'version'        => $row['version'],
        'board_revision' => $tickets->board($project)['revision'],
    ]);

    check(
        (int) $pdo->query('SELECT COUNT(*) FROM ticket_metadata')->fetchColumn() === $before,
        'metadata changed',
    );
});

test('T30 a personal value of a missing extension is neither read nor lost', function () use ($pdo) {
    check(!settings()->has('example.reports.compact'), 'a missing key is still readable');
    check(!array_key_exists('example.reports.compact', settings()->all()), 'value leaked into all()');

    settings()->save(['theme' => 'dark']);

    $kept = $pdo->query(
        "SELECT COUNT(*) FROM user_settings WHERE setting_key='example.reports.compact'",
    )->fetchColumn();
    check((int) $kept === 1, 'an unrelated save deleted it');
});

echo json_encode(
    [
        'host'   => 'extension-host-without',
        'passed' => count($passed),
        'failed' => $failed,
        'tests'  => $passed,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";

exit($failed === [] ? 0 : 1);
