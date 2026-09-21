<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Services\RememberService;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

/**
 * Closing the browser and coming back.
 *
 * The database tests hold the rules; this holds the wiring, which is the half
 * they cannot see. Resuming happens on `request.start`, before anything asks
 * whether there is a session, and therefore for every route at once rather than
 * for the ones a controller remembered.
 */
final class RememberedSignInOverHttpTest extends AcceptanceTestCase
{
    public function testSomebodyWhoAskedToBeRememberedComesBackAfterTheSessionIsGone(): void
    {
        $client = new HttpClient(self::BASE, 'remembered', self::AUTHORITY);
        $client->login('alice@example.test', self::PASSWORD, remember: true);

        $this->assertStringContainsString(
            RememberService::COOKIE,
            $client->loginHeaders(),
            'asking to be remembered set no cookie',
        );

        // What closing a browser does: the session cookie goes, the persistent
        // one stays.
        $client->keepOnly(RememberService::COOKIE);
        $answer = $client->request('/projects/' . self::PROJECT);

        $this->assertSame(200, $answer['status'], 'a remembered visitor was sent to the sign-in page');
        // Something only a signed-in board carries. The password field is no use
        // as a marker: the profile dialog on every page has one.
        $this->assertStringContainsString('data-cards', $answer['body'], 'that was not the board');
    }

    /** Not asking means not remembered, which is what an unchecked box has to mean. */
    public function testSomebodyWhoDidNotAskIsNotRemembered(): void
    {
        $client = new HttpClient(self::BASE, 'not-remembered', self::AUTHORITY);
        $client->login('alice@example.test', self::PASSWORD);

        $this->assertStringNotContainsString(RememberService::COOKIE, $client->loginHeaders());

        $client->keepOnly(RememberService::COOKIE);

        $this->assertTrue(
            $this->sentToSignIn($client),
            'a session that was never persistent outlived itself',
        );
    }

    /** Signing out ends it on this device, cookie and row together. */
    public function testSigningOutEndsTheRememberedSignIn(): void
    {
        $client = new HttpClient(self::BASE, 'remember-logout', self::AUTHORITY);
        $token  = $client->login('alice@example.test', self::PASSWORD, remember: true);
        $client->request('/logout', ['_csrf' => $token]);

        $client->keepOnly(RememberService::COOKIE);

        $this->assertTrue(
            $this->sentToSignIn($client),
            'signing out left a cookie that still worked',
        );
    }

    /**
     * Whether this client is a guest.
     *
     * Redirects are not followed here, so being sent away is a 303 and nothing
     * else. Anything that is not the board answered with 200 means the same
     * thing for this test's purposes: they did not get in.
     */
    private function sentToSignIn(HttpClient $client): bool
    {
        $answer = $client->request('/projects/' . self::PROJECT);

        return $answer['status'] !== 200 || !str_contains($answer['body'], 'data-cards');
    }
}
