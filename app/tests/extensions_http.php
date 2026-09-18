<?php

declare(strict_types=1);

/**
 * The extension host over real HTTP.
 *
 * Everything here goes through the application's own front controller: the
 * router, the session, CSRF, the page renderer and the settings endpoints. A
 * contributed page that only works when called in-process would pass the other
 * probe and fail here.
 */
$base = 'http://127.0.0.1:8099';
$jar  = sys_get_temp_dir() . '/nafinity-extension-cookies.txt';
@unlink($jar);

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

/**
 * @param string     $path    Request path
 * @param array|null $body    Form fields, or null for GET
 * @param array      $headers Extra request headers
 *
 * @return array{status: int, body: string, headers: string}
 */
function request(string $path, ?array $body = null, array $headers = []): array
{
    global $base, $jar;

    $handle = curl_init($base . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt(
            $handle,
            CURLOPT_POSTFIELDS,
            isset($headers[0]) && str_contains(implode(' ', $headers), 'application/json')
                ? json_encode($body)
                : http_build_query($body),
        );
    }

    $response = curl_exec($handle);

    if ($response === false) {
        throw new RuntimeException('Request failed: ' . curl_error($handle));
    }

    $size   = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    return [
        'status'  => $status,
        'headers' => substr($response, 0, $size),
        'body'    => substr($response, $size),
    ];
}

function token(string $page): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $page, $found)) {
        return $found[1];
    }

    if (preg_match('/name="csrf-token" content="([^"]+)"/', $page, $found)) {
        return $found[1];
    }

    throw new RuntimeException('No CSRF token on the page.');
}

function login(string $email): string
{
    global $jar;

    @unlink($jar);
    $page  = request('/login');
    $entry = request('/login', [
        '_csrf'    => token($page['body']),
        'email'    => $email,
        'password' => 'Test-Password-2026!',
    ]);
    check($entry['status'] === 303, 'login status ' . $entry['status'] . ' for ' . $email);

    return token(request('/preferences')['body']);
}

$project = (int) getenv('NAFINITY_EXTENSION_PROJECT');

if ($project < 1) {
    // The probe created exactly one project; find it the way a person would.
    $page = request('/login');
    login('alice@example.test');
    $projects = request('/projects')['body'];

    if (preg_match('#/projects/(\d+)"#', $projects, $found)) {
        $project = (int) $found[1];
    }
}

check($project > 0, 'no project to work with');

test('T02 the contributed page answers over HTTP for someone with the right', function () use ($project) {
    login('reviewer@example.test');
    $page = request('/projects/' . $project . '/reports');

    check($page['status'] === 200, 'status ' . $page['status']);
    check(str_contains($page['body'], 'Berichte'), 'page title missing');
    // Extension B replaced the logical view; the shell is still Nafinity's.
    check(str_contains($page['body'], 'data-example-b-reports'), 'view override did not render');
    check(str_contains($page['body'], 'class="app-shell"'), 'application shell missing');
    check(str_contains($page['body'], 'brand-word'), 'sidebar missing');
});

test('T06 the contributed menu entry appears only with the right', function () use ($project) {
    login('reviewer@example.test');
    $board = request('/projects/' . $project)['body'];
    check(str_contains($board, 'data-contribution="example.reports.link"'), 'menu entry missing');

    login('alice@example.test');
    $withoutGrant = request('/projects/' . $project)['body'];
    check(
        !str_contains($withoutGrant, 'data-contribution="example.reports.link"'),
        'menu entry shown without the grant',
    );
});

test('T02 the same page is refused without the right and hidden from a stranger', function () use ($project) {
    login('alice@example.test');
    $denied = request('/projects/' . $project . '/reports');
    check($denied['status'] === 403, 'owner without the grant got ' . $denied['status']);

    login('stranger@example.test');
    $hidden = request('/projects/' . $project . '/reports');
    check($hidden['status'] === 404, 'stranger got ' . $hidden['status']);
});

test('T13 the settings endpoints answer with values only', function () use ($project) {
    $csrf = login('reviewer@example.test');
    $json = ['Accept: application/json', 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];

    $read = request('/api/settings/project/' . $project, null, $json);
    $read = request('/api/projects/' . $project . '/settings', null, $json);
    check($read['status'] === 200, 'read status ' . $read['status']);

    $values = json_decode($read['body'], true)['values'] ?? [];
    check(array_key_exists('example.reports.limit', $values), 'contributed value missing');
    check(!str_contains($read['body'], 'password'), 'the answer mentions a password');
    check(
        str_contains(strtolower($read['headers']), 'cache-control: private, no-store'),
        'the private no-store header is missing',
    );

    $write = request('/api/projects/' . $project . '/settings', ['values' => ['example.reports.limit' => 40]], $json);
    check($write['status'] === 200, 'write status ' . $write['status']);
    check(
        (json_decode($write['body'], true)['values']['example.reports.limit'] ?? null) === 40,
        'write did not take',
    );

    $invalid = request('/api/projects/' . $project . '/settings', ['values' => ['example.reports.limit' => 9999]], $json);
    check($invalid['status'] === 422, 'invalid value status ' . $invalid['status']);

    $unknown = request('/api/projects/' . $project . '/settings?key=example.invented', null, $json);
    check($unknown['status'] === 404, 'unknown key status ' . $unknown['status']);
});

test('T13 a settings write without a token is refused', function () use ($project) {
    login('reviewer@example.test');
    $json    = ['Accept: application/json', 'Content-Type: application/json'];
    $refused = request('/api/projects/' . $project . '/settings', ['values' => []], $json);

    check($refused['status'] === 400, 'status ' . $refused['status']);
});

test('T18 the contributed widgets render in the ticket, in order', function () use ($project) {
    login('reviewer@example.test');
    $board = request('/projects/' . $project)['body'];

    if (!preg_match('#href="(/projects/' . $project . '/tickets/[^"]+)"#', $board, $found)) {
        throw new RuntimeException('no ticket link on the board');
    }

    $ticket = request($found[1]);
    check($ticket['status'] === 200, 'ticket status ' . $ticket['status']);

    $positions = [];
    foreach ([
        'core.ticket.links',
        'example.reports.widget',
        'example.review.notes',
        'core.ticket.attachments',
        'core.ticket.activity',
    ] as $id) {
        $marker = 'data-extension-id="' . $id . '"';
        check(str_contains($ticket['body'], $marker), 'widget missing: ' . $id);
        $positions[$id] = strpos($ticket['body'], $marker);
    }

    $order = array_keys($positions);
    asort($positions);
    check($order === array_keys($positions), 'order: ' . implode(', ', array_keys($positions)));

    check(str_contains($ticket['body'], 'example.external_id'), 'contributed field missing');
    check(str_contains($ticket['body'], 'Erweiterung B'), 'the replacing widget did not render');
});

echo json_encode(
    ['host' => 'extension-host-http', 'passed' => count($passed), 'failed' => $failed, 'tests' => $passed],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";

exit($failed === [] ? 0 : 1);
