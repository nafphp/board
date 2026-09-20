<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Jobs\AccountSecurityNoticeJob;
use Naf\Board\Models\User;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use Naf\Board\Tests\Support\Mailpit;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\ORM\Core\EntityManager;
use Naf\Queue\Commands\QueueConsumeCommand;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

use function Naf\app;

/**
 * Changing a password or an address, through the endpoints a browser uses.
 *
 * Both end every session the account has, including the one asking -- that is
 * the point of them, and a change that left other browsers signed in would be a
 * change that protects nobody. Both need the current password, and both are
 * written only once: a code that could be redeemed twice is a code that could be
 * redeemed by somebody else.
 *
 * Accounts of its own, because these tests end sessions and rotate credentials,
 * and doing that to the seeded demo accounts would take the rest of the suite
 * with it.
 */
final class AccountSecurityOverHttpTest extends AcceptanceTestCase
{
    private const ORIGINAL = 'Profile test original password!';
    private const CHANGED  = 'Profile test changed password!';

    private Mailpit $mailpit;
    /** @var array<string,string> */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailpit = new Mailpit();
        $this->mailpit->forget();
        $this->accounts = $this->createAccounts();
    }

    public function testTheProfileNeedsASessionAndIsNeverCached(): void
    {
        $guest = new HttpClient(self::BASE, 'guest-profile', self::AUTHORITY);
        $this->assertSame(401, $guest->request('/profile')['status']);

        $client   = $this->signIn($this->accounts['password']);
        $response = $client->request('/profile', null, ['Accept: application/json']);

        $this->assertSame(200, $response['status']);
        $this->assertSame(
            $this->accounts['password'],
            json_decode($response['body'], true)['profile']['email'],
            'the profile of somebody else was returned',
        );
        $this->assertMatchesRegularExpression(
            '/cache-control:[^\n]*no-store/i',
            $response['headers'],
            'the profile may be cached',
        );
    }

    public function testAPasswordChangeNeedsATokenAndTheCurrentPassword(): void
    {
        $client = $this->signIn($this->accounts['password']);
        $change = [
            'current_password'      => self::ORIGINAL,
            'password'              => self::CHANGED,
            'password_confirmation' => self::CHANGED,
        ];

        $this->assertSame(
            400,
            $client->request('/profile/password', $change, ['Content-Type: application/json', 'Accept: application/json'])['status'],
            'a password change went through without a token',
        );
        $this->assertSame(
            403,
            $this->send($client, '/profile/password', [...$change, 'current_password' => 'wrong'])['status'],
        );
    }

    public function testAPasswordChangeEndsEverySessionAndTheOldPasswordStopsWorking(): void
    {
        $email  = $this->accounts['password'];
        $first  = $this->signIn($email, 'pw-first');
        $second = $this->signIn($email, 'pw-second');

        $changed = $this->send($first, '/profile/password', [
            'current_password'      => self::ORIGINAL,
            'password'              => self::CHANGED,
            'password_confirmation' => self::CHANGED,
        ]);

        $this->assertSame(200, $changed['status']);
        $this->assertSame('/login', json_decode($changed['body'], true)['url'], 'it did not ask for a fresh login');
        $this->assertSame(401, $first->request('/profile')['status'], 'the session that changed it survived');
        $this->assertSame(401, $second->request('/profile')['status'], 'another browser stayed signed in');
        $this->assertNull($this->trySignIn($email, self::ORIGINAL), 'the old password still works');
        $this->assertNotNull($this->trySignIn($email, self::CHANGED), 'the new password does not work');
    }

    /**
     * @return array<string,array{string}>
     */
    public static function tokenProtectedEndpoints(): array
    {
        return [
            'requesting a change' => ['/profile/email'],
            'confirming one'      => ['/profile/email/confirm'],
            'cancelling one'      => ['/profile/email/cancel'],
        ];
    }

    #[DataProvider('tokenProtectedEndpoints')]
    public function testEveryAddressEndpointNeedsAToken(string $endpoint): void
    {
        $client = $this->signIn($this->accounts['email']);

        $response = $client->request(
            $endpoint,
            ['email' => 'anything@example.test', 'current_password' => self::ORIGINAL],
            ['Content-Type: application/json', 'Accept: application/json'],
        );

        $this->assertSame(400, $response['status']);
    }

    public function testTheCodeReallyArrivesAndTheAddressWaitsForIt(): void
    {
        $owner  = $this->signIn($this->accounts['email'], 'mail-owner');
        $second = $this->signIn($this->accounts['email'], 'mail-second');
        $wanted = str_replace('-email@', '-verified@', $this->accounts['email']);

        $requested = $this->send($owner, '/profile/email', [
            'email'            => $wanted,
            'current_password' => self::ORIGINAL,
        ]);
        $this->assertSame(200, $requested['status']);
        $pending = json_decode($requested['body'], true)['pending'];

        $code = $this->mailpit->codeFor($wanted);
        $this->assertSame(14, strlen($code), 'the delivered code is not a code');
        $this->assertSame(
            $this->accounts['email'],
            $this->profile($owner)['email'],
            'the address changed before it was proved',
        );
        $this->assertSame(
            $pending['request_id'],
            $this->profile($second)['pending']['request_id'],
            'the pending change is invisible to the account\'s other browser',
        );
    }

    public function testOnlyTheRightCodeFromTheRightAccountConfirmsIt(): void
    {
        $owner  = $this->signIn($this->accounts['email'], 'confirm-owner');
        $other  = $this->signIn($this->accounts['other'], 'confirm-other');
        $wanted = str_replace('-email@', '-strict@', $this->accounts['email']);

        $pending = json_decode($this->send($owner, '/profile/email', [
            'email'            => $wanted,
            'current_password' => self::ORIGINAL,
        ])['body'], true)['pending'];
        $code = $this->mailpit->codeFor($wanted);

        $this->assertSame(422, $this->send($other, '/profile/email/confirm', [
            'request_id' => $pending['request_id'],
            'code'       => $code,
            'user_id'    => $this->idOf($this->accounts['email']),
        ])['status'], 'another account confirmed the code');

        $this->assertSame(422, $this->send($owner, '/profile/email/confirm', [
            'request_id' => $pending['request_id'],
            'code'       => 'AAAAAAAAAAAA',
        ])['status'], 'a wrong code was accepted');
    }

    /**
     * Two browsers of one account redeem the same code at the same moment. One
     * wins; the other is told its session is gone, because the winner's change
     * ended it. A code that both could redeem would be a code somebody else
     * could redeem too.
     */
    public function testTwoSimultaneousConfirmationsRedeemTheCodeExactlyOnce(): void
    {
        $owner  = $this->signIn($this->accounts['email'], 'race-owner');
        $second = $this->signIn($this->accounts['email'], 'race-second');
        $wanted = str_replace('-email@', '-race@', $this->accounts['email']);

        $pending = json_decode($this->send($owner, '/profile/email', [
            'email'            => $wanted,
            'current_password' => self::ORIGINAL,
        ])['body'], true)['pending'];
        $code    = $this->mailpit->codeFor($wanted);
        $confirm = ['request_id' => $pending['request_id'], 'code' => $code];

        $answers = HttpClient::together([
            $owner->prepare('/profile/email/confirm', $confirm, $owner->jsonHeaders($this->tokenOf($owner))),
            $second->prepare('/profile/email/confirm', $confirm, $second->jsonHeaders($this->tokenOf($second))),
        ]);
        $statuses = array_map(static fn(array $answer) => $answer['status'], $answers);
        sort($statuses);

        $this->assertSame([200, 401], $statuses);
        $this->assertSame(401, $owner->request('/profile')['status'], 'a session survived the change');
        $this->assertSame(401, $second->request('/profile')['status']);
        $this->assertNull($this->trySignIn($this->accounts['email'], self::ORIGINAL), 'the old address still signs in');
        $this->assertNotNull($this->trySignIn($wanted, self::ORIGINAL), 'the new address does not sign in');
    }

    /**
     * The notice goes to the address that was replaced, through the queue.
     *
     * Through the queue because sending must not hold up the request, and to the
     * old address because that is the one the person still reads if somebody
     * else made the change. It is not a notification anyone can switch off.
     */
    public function testASecurityNoticeReachesTheAddressThatWasReplaced(): void
    {
        $email  = $this->accounts['password'];
        $client = $this->signIn($email, 'notice');

        $this->send($client, '/profile/password', [
            'current_password'      => self::ORIGINAL,
            'password'              => self::CHANGED,
            'password_confirmation' => self::CHANGED,
        ]);
        $this->drainTheQueue();

        $this->assertNotSame(
            [],
            $this->mailpit->messagesTo($email),
            'nothing was sent to the address whose password changed',
        );
    }

    /**
     * Run the worker here until the notices are out, rather than waiting for the
     * one in the container.
     *
     * Until they are out, not a fixed number of turns: the disposable queue
     * carries whatever earlier tests left in it, and a worker that stopped after
     * twenty would still be somewhere in that backlog.
     */
    private function drainTheQueue(): void
    {
        $container = app()->container();
        $command   = $container->make(QueueConsumeCommand::class);
        $pending   = $this->pdo()->prepare('SELECT COUNT(*) FROM naf_queue_jobs WHERE job_class=?');

        ob_start();

        try {
            for ($turn = 0; $turn < 300; ++$turn) {
                $pending->execute([AccountSecurityNoticeJob::class]);
                if ((int) $pending->fetchColumn() === 0) {
                    return;
                }
                $command->run(
                    new Input(['--once'], $command->getDefinition()),
                    new Output(),
                );
            }
        } finally {
            ob_end_clean();
        }

        $this->fail('The security notices never left the queue.');
    }

    private function pdo(): PDO
    {
        return app()->container()->get(PDO::class);
    }

    public function testAnotherAccountIsUntouchedByAllOfThis(): void
    {
        $other = $this->signIn($this->accounts['other'], 'untouched');

        $this->assertSame($this->accounts['other'], $this->profile($other)['email']);
    }

    /** Three accounts of this test's own, with addresses nothing else uses. */
    private function createAccounts(): array
    {
        $container = app()->container();
        $hasher    = $container->get(PasswordHasher::class);
        $entities  = $container->get(EntityManager::class);
        $stamp     = bin2hex(random_bytes(4));
        $accounts  = [];

        foreach (['password', 'email', 'other'] as $purpose) {
            $address = 'profile-' . $stamp . '-' . $purpose . '@example.test';
            $entities->save(new User([
                'name'          => 'Profile ' . $purpose,
                'email'         => $address,
                'password_hash' => $hasher->hash(self::ORIGINAL),
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]));
            $accounts[$purpose] = $address;
        }

        return $accounts;
    }

    private function signIn(string $email, string $name = ''): HttpClient
    {
        $client = $this->trySignIn($email, self::ORIGINAL, $name);
        $this->assertNotNull($client, 'could not sign in as ' . $email);

        return $client;
    }

    private function trySignIn(string $email, string $password, string $name = ''): ?HttpClient
    {
        $client = new HttpClient(
            self::BASE,
            $name !== '' ? $name : 'profile-' . substr(md5($email . $password), 0, 8),
            self::AUTHORITY,
        );

        try {
            $client->login($email, $password);
        } catch (RuntimeException) {
            return null;
        }

        return $client;
    }

    /** @param array<string,mixed> $body */
    private function send(HttpClient $client, string $path, array $body): array
    {
        return $client->request($path, $body, $client->jsonHeaders($this->tokenOf($client)));
    }

    private function tokenOf(HttpClient $client): string
    {
        return $client->token($client->request('/preferences')['body']);
    }

    /** @return array<string,mixed> */
    private function profile(HttpClient $client): array
    {
        $response = $client->request('/profile', null, ['Accept: application/json']);
        $this->assertSame(200, $response['status'], 'the profile did not answer');

        return json_decode($response['body'], true)['profile'];
    }

    private function idOf(string $email): int
    {
        $statement = $this->pdo()->prepare('SELECT id FROM users WHERE email=?');
        $statement->execute([$email]);

        return (int) $statement->fetchColumn();
    }
}
