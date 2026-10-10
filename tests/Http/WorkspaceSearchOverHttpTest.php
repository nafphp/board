<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use PDO;

use function Naf\app;

final class WorkspaceSearchOverHttpTest extends AcceptanceTestCase
{
    public function testMatchesAreGroupedByBoardAndNeverCrossMembershipBoundaries(): void
    {
        $a   = $this->createIn($this->alice, 1, 'Workspace-grouping-one');
        $b   = $this->createIn($this->bob, 2, 'Workspace-grouping-two');
        $all = $this->suggest($this->alice, 'Workspace-grouping');
        self::assertCount(2, $all['groups']);
        self::assertEqualsCanonicalizing([1, 2], array_column(array_column($all['groups'], 'project'), 'id'));
        foreach ($all['groups'] as $group) {
            self::assertCount(1, $group['tickets']);
            self::assertStringStartsWith('/projects/' . $group['project']['id'] . '/tickets/', $group['tickets'][0]['url']);
        }
        self::assertSame(2, substr_count($all['html'], 'class="workspace-result-group"'));
        $viewer = $this->suggest($this->viewer, 'Workspace-grouping');
        self::assertSame([1], array_column(array_column($viewer['groups'], 'project'), 'id'));
        self::assertStringContainsString($a, $viewer['html']);
        self::assertStringNotContainsString($b, $viewer['html']);
        self::assertSame([2], array_column(array_column($this->suggest($this->bob, 'Workspace-grouping')['groups'], 'project'), 'id'));
    }

    public function testNamesReferencesDescriptionsAndLiteralWildcardsAreSearchable(): void
    {
        $url       = $this->createIn($this->alice, 1, 'Workspace literal %_ needle');
        $reference = basename($url);
        $found     = $this->suggest($this->viewer, $reference);
        self::assertSame($reference, $found['groups'][0]['tickets'][0]['reference']);
        self::assertSame($url, $found['groups'][0]['tickets'][0]['url']);
        self::assertCount(1, $this->suggest($this->viewer, '%_')['groups'][0]['tickets']);
        self::assertNotEmpty($this->suggest($this->viewer, 'workspace-description-only')['groups']);
        $board = $this->suggest($this->bob, 'Studio Nord');
        self::assertSame('Studio Nord', $board['groups'][0]['project']['name']);
    }

    public function testSuggestionsAreBoundedAndTheNativeGetPageHasMoreRoom(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->createIn($this->alice, 1, 'Workspace-limit-' . $i);
        }
        $suggestions = $this->suggest($this->viewer, 'Workspace-limit-');
        self::assertCount(8, $suggestions['groups'][0]['tickets']);
        self::assertTrue($suggestions['more']);
        $page = $this->page($this->viewer, '/search?q=Workspace-limit-');
        self::assertSame(10, substr_count($page, 'class="workspace-result-ticket"'));
        self::assertStringContainsString('Ergebnisse für', $page);
        self::assertStringContainsString('role="search"', $page);
        self::assertStringNotContainsString('role="option"', $page);
    }

    public function testUntrustedBoardAndTicketNamesAreEscapedInBothPresentations(): void
    {
        $this->createIn($this->alice, 1, 'Workspace-escape <img src=x onerror=alert(1)>');
        $pdo  = app()->container()->get(PDO::class);
        $name = $pdo->query('SELECT name FROM projects WHERE id=1')->fetchColumn();
        $pdo->prepare('UPDATE projects SET name=? WHERE id=1')->execute(['Board <script>unsafe</script>']);

        try {
            foreach ([$this->suggest($this->viewer, 'Workspace-escape')['html'], $this->page($this->viewer, '/search?q=Workspace-escape')] as $html) {
                self::assertStringContainsString('&lt;img', $html);
                self::assertStringContainsString('&lt;script&gt;', $html);
                self::assertStringNotContainsString('<img src=x', $html);
                self::assertStringNotContainsString('<script>unsafe', $html);
            }
        } finally {
            $pdo->prepare('UPDATE projects SET name=? WHERE id=1')->execute([$name]);
        }
    }

    public function testRevokedMembershipIsRecheckedAndArchivedBoardsStayReadableWithoutCreation(): void
    {
        $this->createIn($this->alice, 1, 'Workspace-revoked-membership');
        $pdo    = app()->container()->get(PDO::class);
        $viewer = $pdo->query("SELECT id FROM users WHERE email='viewer@example.test'")->fetchColumn();
        $pdo->prepare('UPDATE project_members SET active=0 WHERE project_id=1 AND user_id=?')->execute([$viewer]);

        try {
            self::assertSame([], $this->suggest($this->viewer, 'Workspace-revoked')['groups']);
        } finally {
            $pdo->prepare('UPDATE project_members SET active=1 WHERE project_id=1 AND user_id=?')->execute([$viewer]);
        }
        $pdo->exec("UPDATE projects SET archived_at='2026-10-11 00:00:00' WHERE id=2");

        try {
            self::assertTrue($this->suggest($this->bob, 'Studio Nord')['groups'][0]['project']['archived']);
            self::assertStringNotContainsString('data-ticket-create-link', $this->page($this->bob, '/projects/2'));
            self::assertStringNotContainsString('data-create-picker', $this->page($this->bob, '/projects'));
        } finally {
            $pdo->exec('UPDATE projects SET archived_at=NULL WHERE id=2');
        }
    }

    public function testCreationLivesInTheCurrentBoardHeadingAndRequiresWriteAccess(): void
    {
        $board = $this->page($this->alice, '/projects/1');
        self::assertSame(1, substr_count($board, 'data-ticket-create-link'));
        $dom = new DOMDocument();
        @$dom->loadHTML($board);
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//section[@class="page-heading"]//a[@data-ticket-create-link]')->length);
        self::assertSame(0, $xpath->query('//div[@class="topbar-tools"]//*[@data-ticket-create-link]')->length);
        self::assertStringContainsString('href="/projects/1/tickets/new"', $board);
        self::assertStringNotContainsString('data-ticket-create-link', $this->page($this->viewer, '/projects/1'));
        self::assertStringNotContainsString('data-ticket-create-link', $this->page($this->alice, '/projects/1/tickets/new'));
        foreach (['/projects', '/settings', '/search?q=Studio'] as $path) {
            $workspace = $this->page($this->alice, $path);
            self::assertStringNotContainsString('data-ticket-create-link', $workspace);
            self::assertStringNotContainsString('data-create-picker', $workspace);
        }
        $global = $this->page($this->bob, '/projects');
        self::assertStringNotContainsString('data-ticket-create-link', $global);
        $dom = new DOMDocument();
        @$dom->loadHTML($global);
        self::assertSame(0, (new DOMXPath($dom))->query('//span[@class="topbar-context"]')->length);
        @$dom->loadHTML($board);
        $xpath = new DOMXPath($dom);
        self::assertSame(0, $xpath->query('//header[@class="topbar"]//span[@class="topbar-context"]')->length);
        self::assertSame(1, $xpath->query('//header[@class="topbar"]//input[@type="search" and @name="q"]')->length);
        self::assertSame(0, $xpath->query('//form[@class="filterbar"]//input[@name="q" and not(@type="hidden")]')->length);
    }

    public function testGuestsMalformedQueriesShortQueriesAndPrivateResponses(): void
    {
        foreach (['/search?q=NAF', '/search/suggestions?q=NAF'] as $path) {
            self::assertSame(303, $this->guest('workspace-search-guest')->request($path)['status']);
            $response = $this->viewer->request($path);
            self::assertSame(200, $response['status']);
            self::assertStringContainsString('Cache-Control: private, no-store', $response['headers']);
        }
        foreach (['q[]=x', 'q=' . str_repeat('x', 121)] as $query) {
            self::assertSame(422, $this->viewer->request('/search/suggestions?' . $query)['status']);
        }
        self::assertSame([], $this->suggest($this->viewer, 'a')['groups']);
        self::assertSame([], $this->suggest($this->viewer, 'workspace-no-such-ticket-92845')['groups']);
    }

    private function suggest(HttpClient $client, string $query): array
    {
        $response = $client->request('/search/suggestions?q=' . rawurlencode($query));
        self::assertSame(200, $response['status'], $response['body']);

        return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function createIn(HttpClient $client, int $project, string $title): string
    {
        $board    = $this->page($client, '/projects/' . $project);
        $state    = $this->boardState($client, $project);
        $response = $this->post($client, '/projects/' . $project . '/tickets', [
            'title'          => $title,
            'description'    => 'workspace-description-only',
            'priority'       => 'normal',
            'column_id'      => $state['column'],
            'swimlane_id'    => $state['lane'],
            'board_revision' => $state['revision'],
        ], $client->token($board));
        self::assertSame(200, $response['status'], $response['body']);

        return json_decode($response['body'], true)['url'];
    }
}
