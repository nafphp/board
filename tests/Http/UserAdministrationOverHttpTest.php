<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Rbac\Grants;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use PDO;

use function Naf\app;

final class UserAdministrationOverHttpTest extends AcceptanceTestCase
{
    private PDO $pdo;
    private int $id;
    private string $email;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo   = app()->container()->get(PDO::class);
        $this->email = 'admin-target-' . bin2hex(random_bytes(6)) . '@example.test';
        $this->pdo->prepare('INSERT INTO users(name,email,password_hash,created_at,email_verified_at) VALUES(?,?,?,?,?)')
            ->execute(['Account target', $this->email, app()->container()->get(PasswordHasher::class)->hash(self::PASSWORD), gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
        $this->id = (int) $this->pdo->lastInsertId();
        Grants::ensureDefault($this->id);
        $this->csrf = $this->alice->token($this->page($this->alice, '/settings'));
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM account_password_resets WHERE user_id=?')->execute([$this->id]);
        $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->id]);
        parent::tearDown();
    }

    public function testAccountEditorAndWritesRequirePermissionAndCsrf(): void
    {
        $path   = '/settings/users/' . $this->id;
        $editor = $this->page($this->alice, $path);
        self::assertStringContainsString('data-person-account', $editor);
        self::assertStringContainsString('data-person-reset', $editor);
        self::assertStringContainsString('person-roles', $editor);
        self::assertStringNotContainsString('name="password"', $editor);
        $input = $this->input();
        self::assertSame(400, $this->alice->request($path, $input, ['Accept: application/json'])['status']);
        self::assertSame(403, $this->post($this->viewer, $path, $input)['status']);
        self::assertSame(403, $this->post($this->viewer, $path . '/password-reset', ['revision' => $input['revision']])['status']);
        self::assertSame(400, $this->alice->request($path . '/password-reset', ['revision' => $input['revision']], ['Accept: application/json'])['status']);
    }

    public function testNameChangesPreserveSessionsAndStaleWritesAreRejected(): void
    {
        $target   = $this->freshClientFor($this->email, 'name-target');
        $input    = $this->input();
        $response = $this->post($this->alice, '/settings/users/' . $this->id, [...$input, 'name' => 'Renamed account'], $this->csrf);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('Renamed account', json_decode($response['body'], true)['editor']);
        self::assertSame(200, $target->request('/profile')['status']);
        self::assertSame(409, $this->post($this->alice, '/settings/users/' . $this->id, [...$input, 'name' => 'Stale account'], $this->csrf)['status']);
        self::assertSame('Renamed account', $this->pdo->query('SELECT name FROM users WHERE id=' . $this->id)->fetchColumn());
        self::assertMatchesRegularExpression('/cache-control:[^\n]*no-store/i', $response['headers']);
    }

    public function testCredentialAndStatusChangesEndEveryTargetSession(): void
    {
        $first    = $this->freshClientFor($this->email, 'email-first');
        $second   = $this->freshClientFor($this->email, 'email-second');
        $response = $this->post($this->alice, '/settings/users/' . $this->id, [...$this->input(), 'email' => 'changed-' . $this->email], $this->csrf);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame(401, $first->request('/profile')['status']);
        self::assertSame(401, $second->request('/profile')['status']);
        self::assertNull($this->pdo->query('SELECT email_verified_at FROM users WHERE id=' . $this->id)->fetchColumn());
        $current = $this->freshClientFor('changed-' . $this->email, 'inactive-target');
        self::assertSame(200, $this->post($this->alice, '/settings/users/' . $this->id, [...$this->input(), 'active' => '0'], $this->csrf)['status']);
        self::assertSame(401, $current->request('/profile')['status']);
        self::assertSame(409, $this->post($this->alice, '/settings/users/' . $this->id . '/password-reset', ['revision' => $this->input()['revision']], $this->csrf)['status']);
    }

    public function testResetIsPrivateCsrfProtectedAndOnlyOneConcurrentConsumerWins(): void
    {
        $target = $this->freshClientFor($this->email, 'reset-target');
        $issued = $this->post($this->alice, '/settings/users/' . $this->id . '/password-reset', ['revision' => $this->input()['revision']], $this->csrf);
        self::assertSame(200, $issued['status'], $issued['body']);
        self::assertMatchesRegularExpression('/cache-control:[^\n]*no-store/i', $issued['headers']);
        $path   = parse_url(json_decode($issued['body'], true)['reset_url'], PHP_URL_PATH);
        $first  = $this->guest('reset-first');
        $second = $this->guest('reset-second');
        $page   = $first->request($path);
        self::assertSame(200, $page['status']);
        self::assertMatchesRegularExpression('/referrer-policy: no-referrer/i', $page['headers']);
        self::assertMatchesRegularExpression('/cache-control:[^\n]*no-store/i', $page['headers']);
        self::assertStringNotContainsString($this->email, $page['body']);
        $password = ['password' => 'HTTP reset account password!', 'password_confirmation' => 'HTTP reset account password!'];
        self::assertSame(400, $first->request($path, $password)['status']);
        $answers = HttpClient::together([
            $first->prepare($path, [...$password, '_csrf' => $first->token($page['body'])]),
            $second->prepare($path, [...$password, '_csrf' => $second->token($this->page($second, $path))]),
        ]);
        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame([303, 410], $statuses);
        self::assertSame(401, $target->request('/profile')['status']);
        self::assertSame(401, $first->request('/profile')['status']);
        self::assertSame(410, $second->request($path)['status']);
        $signedIn = $this->guest('reset-new-login');
        $signedIn->login($this->email, $password['password']);
        self::assertSame(200, $signedIn->request('/profile')['status']);
    }

    public function testEditingYourOwnEmailRequiresAFreshLogin(): void
    {
        Grants::makeAdmin($this->id);
        $first   = $this->freshClientFor($this->email, 'self-admin-first');
        $second  = $this->freshClientFor($this->email, 'self-admin-second');
        $token   = $first->token($this->page($first, '/settings'));
        $changed = $this->post($first, '/settings/users/' . $this->id, [...$this->input(), 'email' => 'own-' . $this->email], $token);
        self::assertSame(200, $changed['status'], $changed['body']);
        self::assertSame('/login', json_decode($changed['body'], true)['url']);
        self::assertSame(401, $first->request('/profile')['status']);
        self::assertSame(401, $second->request('/profile')['status']);
    }

    public function testPlainFormsRedirectToAOneTimeResultPage(): void
    {
        $response = $this->alice->request('/settings/users/' . $this->id . '/password-reset', ['revision' => $this->input()['revision'], '_csrf' => $this->csrf]);
        self::assertSame(303, $response['status'], $response['body']);
        $path   = '/settings/users/' . $this->id . '/password-reset/created';
        $result = $this->page($this->alice, $path);
        self::assertStringContainsString('/password-reset/', $result);
        self::assertSame(303, $this->alice->request($path)['status']);
        self::assertSame(403, $this->viewer->request($path)['status']);
    }

    private function input(): array
    {
        $page   = $this->page($this->alice, '/settings/users/' . $this->id);
        $person = $this->pdo->query('SELECT * FROM users WHERE id=' . $this->id)->fetch(PDO::FETCH_ASSOC);

        return ['name' => $person['name'], 'email' => $person['email'], 'active' => $person['active'], 'revision' => $this->firstMatch('/name="revision" value="([^"]+)"/', $page, 'the account revision')];
    }
}
