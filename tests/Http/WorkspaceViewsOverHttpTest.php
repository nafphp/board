<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use PDO;

use function Naf\app;

final class WorkspaceViewsOverHttpTest extends AcceptanceTestCase
{
    public function testNamedViewsSurviveReloadRemainPersonalAndTheirLinksCanBeShared(): void
    {
        $page  = $this->page($this->alice, '/projects/1?priority=urgent&status=open');
        $saved = $this->post($this->alice, '/projects/1/views', [
            'revision' => $this->revision($page),
            'name'     => 'Urgent <personal> view',
            'query'    => ['priority' => 'urgent', 'status' => 'open'],
        ]);
        self::assertSame(200, $saved['status'], $saved['body']);
        $url = json_decode($saved['body'], true)['url'];
        self::assertStringContainsString('priority=urgent', $url);
        self::assertStringContainsString('status=open', $url);
        self::assertStringContainsString('Urgent &lt;personal&gt; view', $this->page($this->alice, $url));
        self::assertStringNotContainsString('Urgent &lt;personal&gt; view', $this->page($this->viewer, $url));
        self::assertSame(404, $this->bob->request($url)['status']);

        $page    = $this->page($this->alice, '/projects/1');
        $id      = $this->firstMatch('/name="remove" value="([a-f0-9]+)"/', $page, 'saved view id');
        $removed = $this->post($this->alice, '/projects/1/views', ['revision' => $this->revision($page), 'remove' => $id]);
        self::assertSame(200, $removed['status']);
        self::assertStringNotContainsString('Urgent &lt;personal&gt; view', $this->page($this->alice, '/projects/1'));
    }

    public function testViewsRejectStaleWritesInvalidFiltersAndMissingCsrf(): void
    {
        $page = $this->page($this->viewer, '/projects/1');
        $body = ['revision' => $this->revision($page), 'name' => 'Viewer view', 'query' => ['status' => 'archived']];
        self::assertSame(200, $this->post($this->viewer, '/projects/1/views', $body)['status']);
        self::assertSame(409, $this->post($this->viewer, '/projects/1/views', $body)['status']);
        $body['revision'] = $this->revision($this->page($this->viewer, '/projects/1'));
        $body['query']    = ['filters' => ['missing.plugin' => 'value']];
        self::assertSame(422, $this->post($this->viewer, '/projects/1/views', $body)['status']);
        $body['query'] = ['priority' => ['unexpected']];
        self::assertSame(422, $this->post($this->viewer, '/projects/1/views', $body)['status']);
        self::assertSame(400, $this->viewer->request('/projects/1/views', $body, ['Accept: application/json'])['status']);
        self::assertSame(404, $this->post($this->bob, '/projects/1/views', $body, $this->bob->token($this->page($this->bob, '/projects/2')))['status']);
    }

    public function testMyTicketsUsesCurrentAssignmentsAndAccessAndExcludesArchives(): void
    {
        $mine = $this->createTicket(['title' => 'My-list mine <safe>', 'assignee_ids' => [1], 'due_date' => '2030-01-01']);
        $this->createTicket(['title' => 'My-list someone else', 'assignee_ids' => [3]]);
        $page = $this->page($this->alice, '/my-tickets');
        self::assertStringContainsString('My-list mine &lt;safe&gt;', $page);
        self::assertStringNotContainsString('My-list someone else', $page);
        self::assertStringContainsString('datetime="2030-01-01"', $page);
        self::assertStringNotContainsString('My-list mine &lt;safe&gt;', $this->page($this->viewer, '/my-tickets'));

        $pdo    = app()->container()->get(PDO::class);
        $number = (int) substr(basename($mine), 4);
        $id     = (int) $pdo->query('SELECT id FROM tickets WHERE project_id=1 AND number=' . $number)->fetchColumn();
        $pdo->prepare('UPDATE tickets SET archived_at=NOW() WHERE id=?')->execute([$id]);
        self::assertStringNotContainsString('My-list mine &lt;safe&gt;', $this->page($this->alice, '/my-tickets?status=all'));
        $pdo->prepare('UPDATE tickets SET archived_at=NULL WHERE id=?')->execute([$id]);

        // Retain an assignment after membership is revoked: it must not confer read access.
        $viewer = (int) $pdo->query("SELECT id FROM users WHERE email='viewer@example.test'")->fetchColumn();
        $pdo->prepare('INSERT INTO ticket_assignees(project_id,ticket_id,user_id) VALUES(1,?,?)')->execute([$id, $viewer]);
        self::assertStringContainsString('My-list mine &lt;safe&gt;', $this->page($this->viewer, '/my-tickets'));
        $pdo->prepare('UPDATE project_members SET active=0 WHERE project_id=1 AND user_id=?')->execute([$viewer]);

        try {
            self::assertStringNotContainsString('My-list mine &lt;safe&gt;', $this->page($this->viewer, '/my-tickets'));
        } finally {
            $pdo->prepare('UPDATE project_members SET active=1 WHERE project_id=1 AND user_id=?')->execute([$viewer]);
        }
    }

    public function testListFiltersAreValidatedAndGuestsMustSignIn(): void
    {
        foreach (['status[]=open', 'sort=unsafe', 'page=-1', 'page=1 OR 1=1'] as $query) {
            self::assertSame(422, $this->alice->request('/my-tickets?' . str_replace(' ', '%20', $query))['status']);
        }
        self::assertSame(200, $this->alice->request('/my-tickets?status=closed&sort=updated&page=999999')['status']);
        self::assertSame(303, $this->guest('my-tickets-guest')->request('/my-tickets')['status']);
    }

    public function testPersonalListSpansProjectsAndKeepsLaterPagesReachable(): void
    {
        $board   = $this->page($this->alice, '/projects/3');
        $state   = $this->boardState($this->alice, 3);
        $created = $this->post($this->alice, '/projects/3/tickets', [
            'title'          => 'My-list another project', 'description' => '', 'priority' => 'normal',
            'column_id'      => $state['column'], 'swimlane_id' => $state['lane'],
            'board_revision' => $state['revision'], 'assignee_ids' => [1],
        ], $this->alice->token($board));
        self::assertSame(200, $created['status'], $created['body']);
        self::assertStringContainsString('My-list another project', $this->page($this->alice, '/my-tickets'));

        for ($i = 0; $i < 51; ++$i) {
            $this->createTicket(['title' => 'My-list pagination ' . $i, 'assignee_ids' => [1], 'due_date' => '1999-01-01']);
        }
        $first  = $this->page($this->alice, '/my-tickets');
        $second = $this->page($this->alice, '/my-tickets?page=2');
        self::assertSame(50, substr_count($first, 'class="my-ticket-reference"'));
        self::assertStringContainsString('rel="next"', $first);
        self::assertStringContainsString('My-list pagination 0', $second);
        self::assertStringNotContainsString('My-list pagination 0</strong>', $first);
        self::assertStringContainsString('rel="prev"', $second);
    }

    public function testReadOnlyMembersCanFollowTicketsAndSaveTheNotificationMode(): void
    {
        $url  = $this->createTicket(['title' => 'Follow over HTTP']);
        $page = $this->page($this->viewer, $url);
        self::assertStringContainsString('Ticket folgen', $page);
        self::assertSame(200, $this->post($this->viewer, $url . '/subscription', ['subscribed' => true])['status']);
        self::assertStringContainsString('aria-pressed="true"', $this->page($this->viewer, $url));
        self::assertSame(200, $this->post($this->viewer, '/api/settings/user', ['values' => ['notify_scope' => 'relevant']])['status']);
        $settings = json_decode($this->viewer->request('/api/settings/user')['body'], true);
        self::assertSame('relevant', $settings['values']['notify_scope']);
        self::assertSame(422, $this->post($this->viewer, '/api/settings/user', ['values' => ['notify_scope' => 'unsafe']])['status']);
        self::assertSame(422, $this->post($this->viewer, $url . '/subscription', ['subscribed' => 'maybe'])['status']);
        self::assertSame(404, $this->post($this->bob, $url . '/subscription', ['subscribed' => true], $this->bob->token($this->page($this->bob, '/projects/2')))['status']);
        self::assertSame(200, $this->post($this->viewer, $url . '/subscription', ['subscribed' => false])['status']);
    }

    private function revision(string $page): string
    {
        return $this->firstMatch('/name="revision" value="([a-f0-9]{64})"/', $page, 'saved views revision');
    }
}
