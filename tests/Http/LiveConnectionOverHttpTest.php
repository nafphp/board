<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Support\LiveConnection;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use Naf\Websocket\Token;

use function Naf\config;

/**
 * Where a browser asks for another token once the one in its page has expired.
 *
 * The socket server has no session and cannot decide who may hear what, so this
 * endpoint decides it -- every time, through the same membership check the board
 * itself goes through. What these tests hold is that a token says exactly one
 * board, that somebody without access is not given one and is not told the board
 * is there, and that a session which has ended cannot renew itself.
 */
final class LiveConnectionOverHttpTest extends AcceptanceTestCase
{
    /** Always true, flag or no flag: access is settled before a token is thought about. */
    public function testAStrangerIsNotToldTheBoardExists(): void
    {
        $this->assertSame(404, $this->socket($this->bob)['status']);
    }

    public function testAMemberIsGivenSomewhereToConnectAndSomewhereToAskAgain(): void
    {
        $body = $this->live()['body'];

        $this->assertNotSame('', $body['url']);
        $this->assertSame('/projects/' . self::PROJECT . '/socket', $body['refresh']);
    }

    public function testTheTokenNamesThisBoardAndNothingElse(): void
    {
        $token = Token::verify((string) config('websocket:key', ''), $this->live()['body']['token']);

        $this->assertNotNull($token, 'the endpoint issued something this installation cannot verify');
        $this->assertTrue($token->mayJoin('project:' . self::PROJECT));
        $this->assertFalse(
            $token->mayJoin('project:' . self::OTHER_PROJECT),
            'a token issued for one board opened another',
        );
    }

    /**
     * What renewing actually buys: an expiry counted from the asking rather than
     * from whenever the page happened to be rendered. Two tokens issued in the
     * same second are the same string, and that is not what is being tested --
     * this is, because it is the reason the endpoint exists.
     */
    public function testATokenIsGoodFromTheMomentItIsAskedFor(): void
    {
        $token    = Token::verify((string) config('websocket:key', ''), $this->live()['body']['token']);
        $lifetime = (int) config('websocket:token_lifetime', 60);

        $this->assertNotNull($token);
        $this->assertGreaterThan(time(), $token->expires, 'the endpoint issued an expired token');
        $this->assertLessThanOrEqual(time() + $lifetime, $token->expires);
    }

    /**
     * A session that has ended cannot renew itself, and the answer is the
     * sign-in page rather than a refusal -- which is why the client checks the
     * shape of what comes back and not only its status.
     */
    public function testSomebodySignedOutIsSentToTheSignInPage(): void
    {
        $anonymous = new HttpClient(self::BASE, 'socket-anonymous', self::AUTHORITY);

        $this->assertArrayNotHasKey(
            'token',
            $this->socket($anonymous)['body'],
            'a browser with no session was given a token',
        );
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function live(): array
    {
        if (!function_exists('Naf\Websocket\live') || !\Naf\Websocket\live()) {
            $this->markTestSkipped('This installation issues no tokens: naf/websocket is off or absent.');
        }

        $answer = $this->socket($this->alice);
        $this->assertSame(200, $answer['status']);
        $this->assertArrayHasKey('token', $answer['body'], 'the endpoint answered without a token');

        return $answer;
    }

    /**
     * Switched off, a page is not told where to connect and is not given a token
     * to connect with. Both, because either one on its own would leave a tab
     * that had been open since before the decision still listening.
     */
    public function testSomebodyWhoDoesNotWantLiveUpdatesIsNotGivenOne(): void
    {
        if (!LiveConnection::running()) {
            $this->markTestSkipped('This installation issues no tokens.');
        }

        $this->liveUpdatesFor($this->viewer, false);

        try {
            $page = $this->viewer->request('/projects/' . self::PROJECT)['body'];

            $this->assertStringNotContainsString('data-naf-websocket', $page, 'the page still connects');
            $this->assertStringNotContainsString('data-live-status', $page);
            $this->assertArrayNotHasKey('token', $this->socket($this->viewer)['body']);
        } finally {
            $this->liveUpdatesFor($this->viewer, true);
        }

        $this->assertStringContainsString(
            'data-naf-websocket',
            $this->viewer->request('/projects/' . self::PROJECT)['body'],
            'switching it back on did not bring it back',
        );
    }

    private function liveUpdatesFor(HttpClient $client, bool $on): void
    {
        // save() writes the whole form, so everything it governs travels with it.
        $body = [
            '_csrf'         => $client->token($client->request('/preferences')['body']),
            'theme'         => 'system',
            'locale'        => 'de',
            'timezone'      => 'Europe/Berlin',
            'notify_in_app' => '1',
        ];
        if ($on) {
            $body['live_updates'] = '1';
        }

        $response = $client->request('/preferences', $body);
        $this->assertContains($response['status'], [200, 303], 'the preference did not save');
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function socket(HttpClient $client): array
    {
        $response = $client->request('/projects/' . self::PROJECT . '/socket');

        return [
            'status' => $response['status'],
            'body'   => json_decode($response['body'], true) ?? [],
        ];
    }
}
