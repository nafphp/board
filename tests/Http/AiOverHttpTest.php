<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

/**
 * The assistant's endpoints are ordinary endpoints.
 *
 * They need a session, they answer 404 for a project the caller is not in, and
 * a call needs a token like any other write. What the catalog contains is
 * decided per request, so a right withdrawn during a conversation takes effect
 * on the next question rather than at the next sign-in.
 */
final class AiOverHttpTest extends AcceptanceTestCase
{
    private const TOOLS = '/ai/tools?project=1';

    private int $role;

    protected function setUp(): void
    {
        parent::setUp();

        // A role of its own for each test, so revoking in one does not decide
        // what the next one sees.
        $created = $this->post($this->alice, '/projects/' . self::PROJECT . '/roles', [
            'name'        => 'HTTP Redaktion',
            'description' => 'Only comments',
            'permissions' => ['comment'],
        ]);
        $this->assertSame(200, $created['status'], 'the role could not be created');

        $settings    = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');
        $roleSection = explode('data-settings-content="roles"', $settings, 2)[1] ?? '';
        $this->role  = (int) $this->firstMatch('/name="id" value="([0-9]+)"/', $roleSection, 'the new role');

        $this->assertSame(200, $this->post($this->alice, '/projects/' . self::PROJECT . '/members', [
            'email' => 'viewer@example.test',
            'role'  => 'custom:' . $this->role,
        ])['status'], 'the role could not be assigned');
    }

    protected function tearDown(): void
    {
        $this->post($this->alice, '/projects/' . self::PROJECT . '/members', [
            'email' => 'viewer@example.test',
            'role'  => 'viewer',
        ]);
        $version = (int) $this->scalarVersion();
        $this->post($this->alice, '/projects/' . self::PROJECT . '/roles', [
            'id'      => $this->role,
            'version' => $version,
            'action'  => 'delete',
        ]);
        parent::tearDown();
    }

    public function testTheToolsNeedASession(): void
    {
        $anonymous = new HttpClient(self::BASE, 'anonymous', self::AUTHORITY);

        $this->assertSame(401, $anonymous->request(self::TOOLS)['status']);
    }

    public function testTheToolsDoNotLeakAForeignProject(): void
    {
        $this->assertSame(404, $this->bob->request(self::TOOLS)['status']);
    }

    public function testACallNeedsAToken(): void
    {
        $response = $this->alice->request(
            '/ai/tools/call',
            ['project' => self::PROJECT, 'name' => 'nafinity_board', 'arguments' => []],
            ['Content-Type: application/json', 'Accept: application/json'],
        );

        $this->assertSame(400, $response['status']);
    }

    public function testTheCatalogFollowsTheCustomRole(): void
    {
        $names = $this->toolNames();

        $this->assertContains('nafinity_comment', $names, 'a tool the role allows is missing');
        $this->assertNotContains('nafinity_ticket_create', $names, 'a write tool leaked');
    }

    /** No new sign-in: the catalog is decided when it is asked for. */
    public function testWithdrawingARightTakesEffectOnTheNextRequest(): void
    {
        $this->assertContains('nafinity_comment', $this->toolNames(), 'nothing to withdraw');

        $revoked = $this->post($this->alice, '/projects/' . self::PROJECT . '/roles', [
            'id'          => $this->role,
            'version'     => 1,
            'name'        => 'HTTP Redaktion',
            'description' => 'Read only',
            'permissions' => [],
        ]);
        $this->assertSame(200, $revoked['status']);

        $this->assertNotContains('nafinity_comment', $this->toolNames());
    }

    /** @return list<string> */
    private function toolNames(): array
    {
        $tools = json_decode($this->viewer->request(self::TOOLS)['body'], true)['tools'] ?? [];

        return array_column($tools, 'name');
    }

    /** The role's version as it stands, which an edit may have moved. */
    private function scalarVersion(): int
    {
        $settings    = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');
        $roleSection = explode('data-settings-content="roles"', $settings, 2)[1] ?? '';

        return (int) ($this->tryMatch('/name="version" value="([0-9]+)"/', $roleSection) ?? 1);
    }

    private function tryMatch(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $found) ? $found[1] : null;
    }
}
