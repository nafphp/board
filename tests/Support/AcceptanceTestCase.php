<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Naf\app;

/**
 * The application as it is actually served: over HTTPS, through nginx, with a
 * certificate that is verified against the authority that issued it.
 *
 * Everything else in the suite reaches the code directly. This reaches the
 * installation, which is a different question -- headers, cookies, the session,
 * CSRF and the router only exist here. It runs inside the container for the same
 * reason: that is where the application is, and asking the host to have PHP to
 * test a containerised application would defeat the container.
 *
 * It works on the seeded demo data rather than fixtures of its own, because that
 * data is what `make seed` gives a new installation -- so this is also a check
 * that the seed produces something the application can serve.
 */
abstract class AcceptanceTestCase extends TestCase
{
    protected const BASE      = 'https://localhost:8443';
    protected const AUTHORITY = '/etc/nginx/ssl/ca.pem';
    protected const PASSWORD  = 'Nafinity-Demo-2026!';

    /** The seeded projects: Alice owns the first, Bob the second. */
    protected const PROJECT       = 1;
    protected const OTHER_PROJECT = 2;

    protected HttpClient $alice;
    protected HttpClient $bob;
    protected HttpClient $viewer;

    /** @var array<string,HttpClient> */
    private static array $sessions = [];

    private static bool $limiterCleared = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLoginLimiter();
        $this->alice  = $this->clientFor('alice@example.test');
        $this->bob    = $this->clientFor('bob@example.test');
        $this->viewer = $this->clientFor('viewer@example.test');
    }

    /**
     * A signed-in browser, kept for the whole run.
     *
     * Shared on purpose, and not only to be quick: signing in is rate limited
     * per account, and a suite that opened a new session for every test would be
     * testing that limiter rather than whatever it meant to test. A browser
     * keeps one session too.
     */
    protected function clientFor(string $email, string $name = ''): HttpClient
    {
        $key = $email . '|' . $name;

        return self::$sessions[$key] ??= $this->freshClientFor($email, $name);
    }

    /** A session of its own, for the few tests that are about signing in. */
    protected function freshClientFor(string $email, string $name = ''): HttpClient
    {
        $client = new HttpClient(
            self::BASE,
            $name !== '' ? $name : str_replace(['@', '.'], '-', $email),
            self::AUTHORITY,
        );
        $client->login($email, self::PASSWORD);

        return $client;
    }

    /**
     * Forget what earlier runs counted against these accounts.
     *
     * Signing in is rate limited per account, which is the point of it -- and a
     * suite that signs a handful of people in is indistinguishable from someone
     * guessing, unless the counter starts where a real day would. Cleared once
     * per run, never per test: the tests below still have to live inside it.
     */
    private function clearLoginLimiter(): void
    {
        if (self::$limiterCleared) {
            return;
        }
        app()->container()->get(PDO::class)->exec('DELETE FROM naf_rate_limits');
        self::$limiterCleared = true;
    }

    /** A page that must answer, returned as text. */
    protected function page(HttpClient $client, string $path): string
    {
        $response = $client->request($path);
        $this->assertSame(200, $response['status'], $path . ' answered ' . $response['status']);

        return $response['body'];
    }

    /**
     * A write, asked the way the page's own script asks: JSON with the token.
     *
     * A browser form would be answered with a redirect, which says nothing about
     * whether the write took.
     *
     * @param array<string,mixed> $body
     * @return array{status:int,body:string,headers:string}
     */
    protected function post(HttpClient $client, string $path, array $body, ?string $token = null): array
    {
        $token ??= $client->token($this->page($client, '/projects/' . self::PROJECT));

        return $client->request($path, $body, $client->jsonHeaders($token));
    }

    /**
     * Create a ticket over HTTP and return the address it now answers at.
     *
     * @param array<string,mixed> $overrides
     */
    protected function createTicket(array $overrides = []): string
    {
        $state = $this->boardState($this->alice);
        $body  = array_replace([
            'title'          => 'HTTP acceptance ticket',
            'description'    => 'HTTP roundtrip sunflower',
            'priority'       => 'normal',
            'column_id'      => $state['column'],
            'swimlane_id'    => $state['lane'],
            'board_revision' => $state['revision'],
        ], $overrides);

        $created = $this->post($this->alice, '/projects/' . self::PROJECT . '/tickets', $body);
        $this->assertSame(200, $created['status'], 'the ticket could not be created');

        return '/projects/' . self::PROJECT . '/tickets/' . json_decode($created['body'], true)['id'];
    }

    /**
     * The version and board revision the ticket page is showing right now.
     *
     * Read back before every write rather than remembered: the page is the only
     * thing that knows, and that is exactly what the optimistic lock is for.
     *
     * @return array{version:int,board_revision:int}
     */
    protected function ticketState(string $url): array
    {
        $page = $this->page($this->alice, $url);

        return [
            'version'        => (int) $this->firstMatch('/data-version="(\d+)"/', $page, 'the version'),
            'board_revision' => (int) $this->firstMatch('/data-revision="(\d+)"/', $page, 'the revision'),
        ];
    }

    /** The first capture of $pattern in $subject, which has to be there. */
    protected function firstMatch(string $pattern, string $subject, string $what): string
    {
        if (!preg_match($pattern, $subject, $found)) {
            throw new RuntimeException('Could not find ' . $what . ' in the page.');
        }

        return $found[1];
    }

    /** @return array{column:int,lane:int,revision:int} the board as it stands now */
    protected function boardState(HttpClient $client, int $project = self::PROJECT): array
    {
        $board = $this->page($client, '/projects/' . $project);
        $state = json_decode($client->request('/projects/' . $project . '/state')['body'], true);

        return [
            'column'   => (int) $this->firstMatch('/data-column="(\d+)"/', $board, 'a column'),
            'lane'     => (int) $this->firstMatch('/data-lane="(\d+)"/', $board, 'a swimlane'),
            'revision' => (int) $state['revision'],
        ];
    }
}
