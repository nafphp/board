<?php

declare(strict_types=1);

use App\Domain\Failure;
use App\Support\Assets\AssetPublisher;

use function Nafinity\extensions;

/**
 * Publishing, checking and removing the example packages' public files.
 *
 * Assets are public data. Nothing here touches an upload, a private file or
 * anything outside a package's own declared directory, and nothing overwrites a
 * file this publisher did not write.
 */
if (getenv('APP_ENV') !== 'test') {
    fwrite(STDERR, "Use APP_ENV=test.\n");
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

$publicRoot   = BASE_PATH . '/public';
$manifestRoot = BASE_PATH . '/storage/plugin-assets';
$publisher    = new AssetPublisher($publicRoot, $manifestRoot);
$fileA        = $publicRoot . '/plugins/example/nafinity-extension-a/review.js';
$fileB        = $publicRoot . '/plugins/example/nafinity-extension-b/review.css';

// A clean start, so the checks describe this run and not a previous one.
$publisher->remove();

test('T28 both packages register a public directory', function () {
    $packages = array_keys(extensions()->assetPackages()->all());

    check($packages === [
        'example/nafinity-extension-a',
        'example/nafinity-extension-b',
    ], implode(', ', $packages));
});

test('T28 check reports what is missing before anything is published', function () use ($publisher, $fileA) {
    $result = $publisher->check();

    check(in_array($fileA, $result['missing'], true), 'the missing file was not reported');
    check($result['current'] === [], 'something was reported as current');
});

test('T28 publishing writes both packages and is idempotent', function () use (
    $publisher,
    $fileA,
    $fileB,
) {
    $first = $publisher->publish();

    check(in_array($fileA, $first['published'], true), 'extension A was not published');
    check(in_array($fileB, $first['published'], true), 'extension B was not published');
    check($first['conflicts'] === [], 'unexpected conflicts');
    check(is_file($fileA) && is_file($fileB), 'the files are not there');

    $second = $publisher->publish();
    check($second['published'] === [], 'a second run wrote again');
    check(count($second['unchanged']) === 2, 'unchanged: ' . count($second['unchanged']));

    $status = $publisher->check();
    check($status['missing'] === [] && $status['stale'] === [], 'check disagrees with publish');
});

test('T28 only declared public file types are published', function () use ($publicRoot) {
    $published = glob($publicRoot . '/plugins/example/*/*') ?: [];

    foreach ($published as $file) {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        check(in_array($extension, AssetPublisher::EXTENSIONS, true), 'published ' . $file);
    }

    check(!is_file($publicRoot . '/plugins/example/nafinity-extension-a/composer.json'), 'a manifest leaked');
});

test('T28 a file the host changed is neither overwritten nor removed', function () use (
    $publisher,
    $fileA,
) {
    file_put_contents($fileA, "// changed by hand\n");

    $conflicted = $publisher->publish('example/nafinity-extension-a');
    check(in_array($fileA, $conflicted['conflicts'], true), 'the conflict was not reported');
    check($conflicted['published'] === [], 'it wrote anyway');
    check(
        str_contains((string) file_get_contents($fileA), 'changed by hand'),
        'the host file was overwritten',
    );

    $removed = $publisher->remove('example/nafinity-extension-a');
    check(in_array($fileA, $removed['kept'], true), 'the changed file was not kept');
    check(is_file($fileA), 'the changed file was deleted');

    unlink($fileA);
});

test('T28 remove works from the record, without the package', function () use (
    $publisher,
    $manifestRoot,
    $fileB,
) {
    $publisher->publish();
    check(is_file($fileB), 'nothing to remove');

    // Pretend the package is gone: only the stored record is left.
    $registry = extensions()->assetPackages();
    $registry->remove('example/nafinity-extension-b');
    check($registry->get('example/nafinity-extension-b') === null, 'the package is still registered');

    $result = $publisher->remove('example/nafinity-extension-b');
    check(in_array($fileB, $result['removed'], true), 'the file was not removed');
    check(!is_file($fileB), 'the file is still there');
    check(
        !is_file($manifestRoot . '/example.nafinity-extension-b.json'),
        'the record was left behind',
    );
});

test('T28 an unknown package is named rather than guessed at', function () use ($publisher) {
    try {
        $publisher->publish('example/never-installed');
        throw new RuntimeException('an unknown package was accepted');
    } catch (Failure $exception) {
        check($exception->status === 404, 'status ' . $exception->status);
    }
});

echo json_encode(
    ['host' => 'extension-host-assets', 'passed' => count($passed), 'failed' => $failed, 'tests' => $passed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";

exit($failed === [] ? 0 : 1);
