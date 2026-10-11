<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use PDO;

use function Naf\app;
use function Naf\Rbac\rbac;

final class PeopleOverHttpTest extends AcceptanceTestCase
{
    public function testTheCardRendersATableAndPrimaryActionWithoutEagerGrantEditors(): void
    {
        $page = $this->page($this->alice, '/settings');
        self::assertStringContainsString('settings-people-table', $page);
        self::assertStringContainsString('ui-notice--info', $page);
        self::assertStringNotContainsString('class="settings-hint"', $page);
        self::assertStringContainsString('class="button primary" data-people-dialog="people-invite"', $page);
        self::assertStringContainsString('data-bulk-target', $page);
        self::assertStringNotContainsString('class="rbac-grants"', $page);
        $id       = app()->container()->get(PDO::class)->query("SELECT id FROM users WHERE email='bob@example.test'")->fetchColumn();
        $fragment = $this->page($this->alice, '/settings/users/' . $id);
        self::assertStringContainsString('class="rbac-grants"', $fragment);
        self::assertStringContainsString('bob@example.test', $fragment);
    }

    public function testListSearchAndPaginationAreBoundedAndMalformedFiltersAreRejected(): void
    {
        $pdo    = app()->container()->get(PDO::class);
        $insert = $pdo->prepare('INSERT INTO users(name,email,password_hash,created_at) VALUES(?,?,?,?)');
        $ids    = [];

        try {
            for ($i = 0; $i < 55; ++$i) {
                $insert->execute(['Paging Person ' . sprintf('%02d', $i), 'paging-' . $i . '@example.test', 'unused', gmdate('Y-m-d H:i:s')]);
                $ids[] = (int) $pdo->lastInsertId();
            }
            $first = $this->page($this->alice, '/settings/users?users_q=Paging');
            self::assertSame(50, substr_count($first, '<tr data-person-row>'));
            self::assertStringNotContainsString('Paging Person 50', $first);
            $second = $this->page($this->alice, '/settings/users?users_q=Paging&users_page=2');
            self::assertSame(5, substr_count($second, '<tr data-person-row>'));
            self::assertStringContainsString('Paging Person 50', $second);
            self::assertSame(422, $this->alice->request('/settings/users?users_q[]=x')['status']);
            self::assertSame(422, $this->alice->request('/settings/users?users_page[]=2')['status']);
            self::assertStringContainsString('Keine Konten gefunden.', $this->page($this->alice, '/settings/users?users_q=%25'));
        } finally {
            foreach ($ids as $id) {
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            }
        }
    }

    public function testUnauthorizedReadsAndWritesAndMissingCsrfAreRejected(): void
    {
        self::assertSame(403, $this->viewer->request('/settings/users')['status']);
        self::assertSame(403, $this->viewer->request('/settings/users/1')['status']);
        $token = $this->alice->token($this->page($this->alice, '/settings'));
        self::assertSame(422, $this->post($this->alice, '/settings/users/bulk', ['targets' => [2], 'values' => ['password_hash' => 'x']], $token)['status']);
        self::assertSame(403, $this->post($this->viewer, '/settings/users/bulk', ['targets' => [2], 'values' => ['add_role' => '1']])['status']);
        $response = $this->alice->request('/settings/users/bulk', ['targets' => [2], 'values' => ['add_role' => '1']], ['Accept: application/json']);
        self::assertSame(400, $response['status']);
    }

    public function testBulkRoleChangeRoundTripsAndSelfSelectionRollsBackTheBatch(): void
    {
        $pdo    = app()->container()->get(PDO::class);
        $ids    = $pdo->query("SELECT email,id FROM users WHERE email IN ('alice@example.test','bob@example.test','viewer@example.test')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $admin  = (string) rbac()->roles->idOf('admin');
        $token  = $this->alice->token($this->page($this->alice, '/settings'));
        $before = rbac()->assignments->grantsOf((int) $ids['viewer@example.test']);
        $denied = $this->post($this->alice, '/settings/users/bulk', [
            'targets' => [(int) $ids['viewer@example.test'], (int) $ids['alice@example.test']],
            'values'  => ['add_role' => $admin],
        ], $token);
        self::assertSame(403, $denied['status']);
        self::assertSame($before, rbac()->assignments->grantsOf((int) $ids['viewer@example.test']));
        $success = $this->post($this->alice, '/settings/users/bulk', [
            'targets' => [(int) $ids['viewer@example.test']], 'values' => ['add_role' => $admin],
        ], $token);

        try {
            self::assertSame(200, $success['status'], $success['body']);
            self::assertContains('admin', rbac()->assignments->rolesOf((int) $ids['viewer@example.test']));
        } finally {
            $this->post($this->alice, '/settings/users/bulk', [
                'targets' => [(int) $ids['viewer@example.test']], 'values' => ['remove_role' => $admin],
            ], $token);
        }
    }
}
