<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use PDO;

use function Naf\app;

final class InvitationOverHttpTest extends AcceptanceTestCase
{
    public function testTheCentralAndBoardMemberSurfacesUseTheSameInvitationEndpoint(): void
    {
        $board = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');
        $this->assertStringContainsString('data-account-search="/projects/1/accounts"', $board);
        $this->assertStringContainsString('action="/invitations"', $board);
        $this->assertStringContainsString('id="member-account"', $board);
        $this->assertStringContainsString('id="invite-email"', $board);
        $this->assertStringContainsString('id="membership-role"', $board);
        $this->assertStringContainsString('id="invite-role"', $board);
        $this->assertStringNotContainsString('Die Person braucht bereits ein Konto.', $board);
        $central = $this->page($this->alice, '/settings');
        $this->assertStringContainsString('action="/invitations"', $central);
        $this->assertStringNotContainsString('Erstes Passwort', $central);
    }

    public function testARecipientRegistersThroughTheLinkAndReachesOnlyTheInvitingBoard(): void
    {
        $email   = 'invite-' . bin2hex(random_bytes(5)) . '@example.test';
        $created = $this->post($this->alice, '/invitations', ['email' => $email, 'project_id' => self::PROJECT]);
        $this->assertSame(200, $created['status'], $created['body']);
        $data  = json_decode($created['body'], true);
        $path  = parse_url($data['invitation_url'], PHP_URL_PATH);
        $guest = $this->guest('invitation-registration');
        $page  = $guest->request($path);
        $this->assertSame(200, $page['status'], $page['body']);
        $this->assertStringContainsString('no-store', $page['headers']);
        $this->assertStringContainsString('Referrer-Policy: no-referrer', $page['headers']);
        $this->assertStringContainsString($email, $page['body']);
        $bad = $guest->request($path, ['name' => 'Missing csrf', 'password' => 'Invitation-Password-2026!']);
        $this->assertSame(400, $bad['status']);
        $body     = ['_csrf' => $guest->token($page['body']), 'name' => 'Invited HTTP Person', 'password' => 'Invitation-Password-2026!', 'password_confirmation' => 'Invitation-Password-2026!'];
        $accepted = $guest->request($path, $body);
        $this->assertSame(303, $accepted['status'], $accepted['body']);
        $this->assertStringContainsString('Location: /projects/1', $accepted['headers']);
        $this->page($guest, '/projects/1');
        $this->assertSame(404, $guest->request('/projects/2')['status']);
        $projects = $this->page($guest, '/projects');
        $this->assertStringNotContainsString('action="/projects"', $projects);
        $this->assertSame(410, $guest->request($path)['status']);
        $search = $this->alice->request('/projects/1/accounts?q=Invited%20HTTP');
        $this->assertSame(200, $search['status']);
        $this->assertContains($email, array_column(json_decode($search['body'], true)['accounts'], 'email'));
        $bobToken = $this->bob->token($this->page($this->bob, '/projects/2'));
        $added    = $this->post($this->bob, '/projects/2/members', ['email' => $email, 'role' => 'viewer'], $bobToken);
        $this->assertSame(200, $added['status'], $added['body']);
        $this->page($guest, '/projects/2');
    }

    public function testNativeInvitationCreationAndRevocation(): void
    {
        $email   = 'invite-native-' . bin2hex(random_bytes(5)) . '@example.test';
        $token   = $this->alice->token($this->page($this->alice, '/projects/1/settings'));
        $created = $this->alice->request('/invitations', ['_csrf' => $token, 'project_id' => 1, 'email' => $email, 'role' => 'viewer']);
        $this->assertSame(303, $created['status'], $created['body']);
        $result = $this->page($this->alice, '/invitations/created');
        $link   = html_entity_decode($this->firstMatch('/id="invitation-link"[^>]+value="([^"]+)"/', $result, 'invitation link'));
        $this->assertStringContainsString($email, $result);
        $settings = $this->page($this->alice, '/projects/1/settings');
        $revoke   = $this->firstMatch('#action="(/projects/1/invitations/[0-9]+/revoke)"#', $settings, 'revoke endpoint');
        $saved    = $this->post($this->alice, $revoke, []);
        $this->assertSame(200, $saved['status'], $saved['body']);
        $this->assertSame(410, $this->guest('revoked-invitation')->request(parse_url($link, PHP_URL_PATH))['status']);
    }

    public function testConcurrentRegistrationConsumesTheInvitationOnlyOnce(): void
    {
        $email   = 'invite-race-' . bin2hex(random_bytes(5)) . '@example.test';
        $created = $this->post($this->alice, '/invitations', ['project_id' => 1, 'email' => $email]);
        $this->assertSame(200, $created['status']);
        $path         = parse_url(json_decode($created['body'], true)['invitation_url'], PHP_URL_PATH);
        $first        = $this->guest('invite-race-first');
        $second       = $this->guest('invite-race-second');
        $registration = ['name' => 'Race recipient', 'password' => 'Invitation-Password-2026!', 'password_confirmation' => 'Invitation-Password-2026!'];
        $firstToken   = $first->token($this->page($first, $path));
        $secondToken  = $second->token($this->page($second, $path));
        $answers      = HttpClient::together([
            $first->prepare($path, [...$registration, '_csrf' => $firstToken]),
            $second->prepare($path, [...$registration, '_csrf' => $secondToken]),
        ]);
        $statuses = array_column($answers, 'status');
        sort($statuses);
        $this->assertSame([303, 410], $statuses, json_encode($answers));
        $pdo   = app()->container()->get(PDO::class);
        $users = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');
        $users->execute([$email]);
        $this->assertSame(1, (int) $users->fetchColumn());
        $members = $pdo->prepare('SELECT COUNT(*) FROM project_members m JOIN users u ON u.id=m.user_id WHERE u.email=?');
        $members->execute([$email]);
        $this->assertSame(1, (int) $members->fetchColumn());
    }

    public function testAnAccountCreatedAfterIssuingTheLinkSignsInAndReturnsToItsInvitation(): void
    {
        $email   = 'invite-existing-' . bin2hex(random_bytes(5)) . '@example.test';
        $created = $this->post($this->alice, '/invitations', ['project_id' => 1, 'email' => $email]);
        $this->assertSame(200, $created['status']);
        $path = parse_url(json_decode($created['body'], true)['invitation_url'], PHP_URL_PATH);
        $pdo  = app()->container()->get(PDO::class);
        $pdo->prepare('INSERT INTO users(name,email,password_hash,created_at) VALUES(?,?,?,?)')
            ->execute(['Existing recipient', $email, password_hash('Invitation-Password-2026!', PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s')]);
        $client  = $this->guest('invitation-existing-login');
        $preview = $this->page($client, $path);
        $this->assertStringNotContainsString('name="password"', $preview);
        $loginUrl = '/login?invitation=' . basename($path);
        $login    = $this->page($client, $loginUrl);
        $failed   = $client->request('/login', ['_csrf' => $client->token($login), 'email' => $email, 'password' => 'Wrong password']);
        $this->assertSame(401, $failed['status']);
        $signedIn = $client->request('/login', ['_csrf' => $client->token($failed['body']), 'email' => $email, 'password' => 'Invitation-Password-2026!']);
        $this->assertSame(303, $signedIn['status']);
        $this->assertStringContainsString('Location: ' . $path, $signedIn['headers']);
        $join = $this->page($client, $path);
        $this->assertStringContainsString('Board beitreten', $join);
        $this->assertStringNotContainsString('name="password"', $join);
        $accepted = $client->request($path, ['_csrf' => $client->token($join)]);
        $this->assertSame(303, $accepted['status']);
        $this->page($client, '/projects/1');
        $this->assertSame(404, $client->request('/projects/2')['status']);
    }

    public function testInvitesAndDirectoryEnforceCsrfAndProjectMemberManagement(): void
    {
        $denied = $this->post($this->viewer, '/invitations', ['project_id' => 1, 'email' => 'blocked@example.test']);
        $this->assertSame(403, $denied['status']);
        $missing = $this->alice->request('/invitations', ['project_id' => 1, 'email' => 'blocked@example.test'], ['Accept: application/json', 'Content-Type: application/json']);
        $this->assertSame(400, $missing['status']);
        $this->assertSame(403, $this->viewer->request('/projects/1/accounts?q=alice')['status']);
        $this->assertSame(404, $this->bob->request('/projects/1/accounts?q=alice')['status']);
        $invalid = $this->guest('invalid-invitation')->request('/invitations/' . str_repeat('0', 64));
        $this->assertSame(410, $invalid['status']);
        $this->assertStringNotContainsString('name="password"', $invalid['body']);
    }
}
