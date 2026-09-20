<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Http;

use Naf\Board\Tests\Support\HttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The extension host over real HTTP.
 *
 * Everything here goes through the application's own front controller: the
 * router, the session, CSRF, the page renderer and the settings endpoints. A
 * contributed page that only works when it is called in-process passes the
 * in-process checks and fails here, which is why both exist.
 */
final class ExtensionOverHttpTest extends TestCase
{
    private HttpClient $http;
    private int $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http    = new HttpClient('http://127.0.0.1:8099', 'extensions');
        $this->project = $this->findProject();
    }

    public function testTheContributedPageAnswersForSomeoneWithTheRight(): void
    {
        $this->http->login('reviewer@example.test');

        $page = $this->http->request('/projects/' . $this->project . '/reports');

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Berichte', $page['body'], 'the page title is missing');
        // Extension B replaced the logical view; the shell around it is still
        // Nafinity's, which is the point of a view override rather than a page.
        $this->assertStringContainsString('data-example-b-reports', $page['body'], 'the override did not render');
        $this->assertStringContainsString('class="app-shell"', $page['body'], 'the application shell is missing');
        $this->assertStringContainsString('brand-word', $page['body'], 'the sidebar is missing');
    }

    public function testTheSamePageIsRefusedWithoutTheRightAndHiddenFromAStranger(): void
    {
        $this->http->login('alice@example.test');
        $this->assertSame(
            403,
            $this->http->request('/projects/' . $this->project . '/reports')['status'],
            'the owner has the project but not this grant',
        );

        $this->http->login('stranger@example.test');
        $this->assertSame(
            404,
            $this->http->request('/projects/' . $this->project . '/reports')['status'],
            'a stranger was told the page exists',
        );
    }

    public function testTheContributedMenuEntryAppearsOnlyWithTheRight(): void
    {
        $this->http->login('reviewer@example.test');
        $this->assertStringContainsString(
            'data-contribution="example.reports.link"',
            $this->http->request('/projects/' . $this->project)['body'],
        );

        $this->http->login('alice@example.test');
        $this->assertStringNotContainsString(
            'data-contribution="example.reports.link"',
            $this->http->request('/projects/' . $this->project)['body'],
            'the menu entry was shown without the grant',
        );
    }

    public function testTheSettingsEndpointAnswersWithValuesAndNothingElse(): void
    {
        $token = $this->http->login('reviewer@example.test');
        $json  = $this->http->jsonHeaders($token);

        $read = $this->http->request('/api/projects/' . $this->project . '/settings', null, $json);

        $this->assertSame(200, $read['status']);
        $values = json_decode($read['body'], true)['values'] ?? [];
        $this->assertArrayHasKey('example.reports.limit', $values, 'the contributed value is missing');
        $this->assertStringNotContainsString('password', $read['body'], 'the answer mentions a password');
        $this->assertStringContainsString(
            'cache-control: private, no-store',
            strtolower($read['headers']),
            'settings were allowed to be cached',
        );
    }

    public function testAContributedSettingIsWrittenAndValidated(): void
    {
        $token = $this->http->login('reviewer@example.test');
        $json  = $this->http->jsonHeaders($token);
        $path  = '/api/projects/' . $this->project . '/settings';

        $write = $this->http->request($path, ['values' => ['example.reports.limit' => 40]], $json);

        $this->assertSame(200, $write['status']);
        $this->assertSame(40, json_decode($write['body'], true)['values']['example.reports.limit'] ?? null);

        // The extension declared the range, and the host enforces it.
        $this->assertSame(
            422,
            $this->http->request($path, ['values' => ['example.reports.limit' => 9999]], $json)['status'],
        );
        $this->assertSame(
            404,
            $this->http->request($path . '?key=example.invented', null, $json)['status'],
            'a key nobody declared was answered',
        );
    }

    /**
     * A form with nothing ticked sends only its empty clear field, so the
     * controller is handed a string where the type wants a list. That is the
     * ordinary way to empty a multiselect, not a malformed request.
     */
    public function testAFormClearsAMultiselectWithItsEmptyFieldAlone(): void
    {
        $token = $this->http->login('reviewer@example.test');
        $json  = $this->http->jsonHeaders($token);
        $path  = '/api/projects/' . $this->project . '/settings';

        $set = $this->http->request($path, ['values' => ['example.reports.columns' => ['due', 'points']]], $json);
        $this->assertSame(200, $set['status']);

        $cleared = $this->http->request(
            $path,
            ['_csrf' => $token, 'values' => ['example.reports.columns' => '']],
            ['Content-Type: application/x-www-form-urlencoded'],
        );
        $this->assertContains($cleared['status'], [200, 303]);

        $values = json_decode(
            $this->http->request($path . '?key=example.reports.columns', null, $json)['body'],
            true,
        )['values'] ?? [];
        $this->assertSame([], $values['example.reports.columns'] ?? null, 'the empty submission did not clear the list');
    }

    public function testASettingsWriteWithoutATokenIsRefused(): void
    {
        $this->http->login('reviewer@example.test');

        $refused = $this->http->request(
            '/api/projects/' . $this->project . '/settings',
            ['values' => []],
            ['Accept: application/json', 'Content-Type: application/json'],
        );

        $this->assertSame(400, $refused['status']);
    }

    /**
     * Also the proof that the ticket slot context carries what it must: the
     * built-in widgets and panels read nothing else any more, so if the context
     * were missing something they would not render at all.
     */
    public function testTheContributedWidgetsRenderInTheTicketInOrder(): void
    {
        $this->http->login('reviewer@example.test');
        $board = $this->http->request('/projects/' . $this->project)['body'];

        $this->assertSame(
            1,
            preg_match('#href="(/projects/' . $this->project . '/tickets/[^"]+)"#', $board, $found),
            'there is no ticket on the board to open',
        );
        $ticket = $this->http->request($found[1]);
        $this->assertSame(200, $ticket['status']);

        $expected = [
            'core.ticket.links',
            'example.reports.widget',
            'example.review.notes',
            'core.ticket.attachments',
            'core.ticket.activity',
        ];
        $positions = [];
        foreach ($expected as $id) {
            $marker = 'data-extension-id="' . $id . '"';
            $this->assertStringContainsString($marker, $ticket['body'], 'widget missing: ' . $id);
            $positions[$id] = strpos($ticket['body'], $marker);
        }
        asort($positions);
        $this->assertSame($expected, array_keys($positions), 'the widgets rendered in the wrong order');

        $this->assertStringContainsString('example.external_id', $ticket['body'], 'the contributed field is missing');
        $this->assertStringContainsString('Erweiterung B', $ticket['body'], 'the replacing widget did not render');
    }

    /** The probe host has exactly one project; find it the way a person would. */
    private function findProject(): int
    {
        $given = (int) getenv('NAFINITY_EXTENSION_PROJECT');
        if ($given > 0) {
            return $given;
        }

        $this->http->login('alice@example.test');
        $projects = $this->http->request('/projects')['body'];

        if (!preg_match('#/projects/(\d+)"#', $projects, $found)) {
            throw new RuntimeException('No project to work with in this host.');
        }

        return (int) $found[1];
    }
}
