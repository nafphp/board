<?php

declare(strict_types=1);


use function Naf\app;
use function Nafinity\extensions;

/**
 * The same two extensions, booted in the opposite order.
 *
 * NAF boots Composer plugins in the order an application lists them, and this
 * host lists extension B first. What must not change is the order the providers
 * run in: that is decided by their index and their id, so extension B still
 * replaces what extension A registered rather than racing it.
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

test('A7 the application really did boot the plugins the other way round', function () {
    $booted = array_keys(app()->getPlugins());
    $a      = array_search('example/nafinity-extension-a', $booted, true);
    $b      = array_search('example/nafinity-extension-b', $booted, true);

    check($a !== false && $b !== false, 'an extension was not booted at all');
    check($b < $a, 'the listing was not swapped: ' . implode(', ', $booted));
});

test('A7 the providers still run in index and id order', function () {
    check(
        extensions()->executed() === ['example.reports', 'example.review'],
        'provider order: ' . implode(', ', extensions()->executed()),
    );
});

test('A7 what B replaced is still replaced', function () {
    $widget = extensions()->ui()->get('example.reports.widget');

    check($widget !== null, 'the widget is gone');
    check($widget->template === 'example-b/ticket-widget', 'template: ' . $widget->template);
    check(
        extensions()->settings()->find('project', 'example.reports.limit')?->section === 'example.review',
        'the settings field went back to A\'s card',
    );
    check(
        extensions()->views()->resolve('example-a/reports') === 'example-b/reports',
        'the view override is gone',
    );
});

test('A7 what only A registers is still there', function () {
    check(extensions()->permissions()->has('example.reports.view'), 'permission missing');
    check(extensions()->ticketFields()->get('example.external_id') !== null, 'field missing');
    check(extensions()->boardFilters()->get('example.reviewed') !== null, 'filter missing');
    check(extensions()->navigation()->get('example.reports.link') !== null, 'menu entry missing');
    check(extensions()->estimationScales()->has('example.tshirt'), 'scale missing');
});

echo json_encode(
    [
        'host'    => 'extension-host-swapped',
        'plugins' => array_keys(app()->getPlugins()),
        'passed'  => count($passed),
        'failed'  => $failed,
        'tests'   => $passed,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";

exit($failed === [] ? 0 : 1);
