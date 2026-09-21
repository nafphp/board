<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Auth\Credentials\PasswordCredentials;
use Naf\Board\Contracts\AccountServiceInterface;
use Naf\Board\Events\SignIn;
use Naf\Board\Models\User;
use Naf\Board\Modules\CoreSignInAudit;
use Naf\Board\Services\AuditLog;
use Naf\Board\Tests\Support\AccountTestCase;

use function Naf\app;
use function Naf\event;

/**
 * The one thing that happens without anything being written.
 *
 * Signing in changes no row, so the change event cannot carry it, and until
 * this existed a plugin that wanted to notice a sign-in -- from a new country,
 * after a run of failures, into an account somebody is watching -- had nowhere
 * to stand.
 *
 * Both outcomes are announced from the same place, which is the point: there is
 * one method that decides either, for the password provider and the directory
 * one alike, so there is no second path where somebody could forget.
 */
final class SignInEventTest extends AccountTestCase
{
    /** @var list<SignIn> */
    private array $heard = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->heard = [];
        event()->listen(SignIn::class, function (SignIn $attempt): void {
            $this->heard[] = $attempt;
        });
    }

    public function testASuccessfulSignInIsAnnouncedWithWhoItTurnedOutToBe(): void
    {
        [$user] = $this->newAccount();
        $this->auth->logout();

        $this->assertTrue($this->attempt($this->emailOf($user), self::CURRENT_PASSWORD));
        $this->assertCount(1, $this->heard);

        $attempt = $this->heard[0];
        $this->assertTrue($attempt->succeeded);
        $this->assertFalse($attempt->failed());
        $this->assertSame((int) $user->getId(), $attempt->accountId);
        $this->assertSame('users', $attempt->provider);
    }

    /**
     * The interesting one. A failure has no account to name, and the address is
     * carried as it was typed, because that is what a listener watching for a
     * run of them has to group by.
     */
    public function testAFailedSignInIsAnnouncedWithNoAccountAndTheAddressAsTyped(): void
    {
        [$user] = $this->newAccount();
        $this->auth->logout();

        $this->assertFalse($this->attempt($this->emailOf($user), 'not the password'));
        $this->assertCount(1, $this->heard);

        $attempt = $this->heard[0];
        $this->assertFalse($attempt->succeeded);
        $this->assertTrue($attempt->failed());
        $this->assertNull($attempt->accountId, 'a refused sign-in named an account anyway');
        $this->assertSame($this->emailOf($user), $attempt->email);
    }

    /** An address nobody has is still an attempt, and still worth hearing about. */
    public function testAnAttemptOnAnAccountThatDoesNotExistIsAnnouncedToo(): void
    {
        $this->auth->logout();

        $this->assertFalse($this->attempt('nobody@example.test', 'whatever'));
        $this->assertCount(1, $this->heard);
        $this->assertFalse($this->heard[0]->succeeded);
        $this->assertSame('nobody@example.test', $this->heard[0]->email);
    }

    /**
     * The entry the log did not have: somebody came in.
     *
     * Recorded from outside the transaction that published the session, in one
     * of its own -- a sign-in that already happened must not be undone by the
     * writing down of it.
     */
    public function testASuccessfulSignInIsWrittenToTheLog(): void
    {
        [$user] = $this->newAccount();
        $this->auth->logout();
        $this->attempt($this->emailOf($user), self::CURRENT_PASSWORD);

        $entry = $this->lastEntry(CoreSignInAudit::SUCCEEDED);

        $this->assertNotNull($entry, 'a sign-in left no trace');
        $this->assertSame((int) $user->getId(), (int) $entry['actor_id']);
        $this->assertSame('', (string) $entry['scope'], 'a sign-in was filed under a project');
        $this->assertSame('users', json_decode((string) $entry['payload'], true)['provider']);
    }

    /**
     * And the one it needed more: somebody tried and did not get in.
     *
     * With no actor, on purpose. Naming one would mean asserting who it was on
     * the strength of a password that did not match; the address as typed is
     * what a run of attempts is grouped by, and it is kept whether or not any
     * account answers to it.
     */
    public function testARefusedSignInIsWrittenWithNoActorAndTheAddressAsTyped(): void
    {
        [$user] = $this->newAccount();
        $this->auth->logout();
        $email = $this->emailOf($user);
        $this->attempt($email, 'not the password');

        $entry = $this->lastEntry(CoreSignInAudit::REFUSED);

        $this->assertNotNull($entry, 'a refused sign-in left no trace');
        $this->assertNull($entry['actor_id'], 'a refused sign-in named somebody');
        $this->assertSame($email, json_decode((string) $entry['payload'], true)['person']);
    }

    /** Both kinds are account business, behind the permission that exists for it. */
    public function testSignInEntriesAreOnlyVisibleWithThePersonalPermission(): void
    {
        $this->assertContains(CoreSignInAudit::SUCCEEDED, AuditLog::PERSONAL);
        $this->assertContains(CoreSignInAudit::REFUSED, AuditLog::PERSONAL);
    }

    /** @return array<string,mixed>|null */
    private function lastEntry(string $type): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT actor_id, scope, payload FROM activities WHERE event_type=? ORDER BY id DESC LIMIT 1',
        );
        $statement->execute([$type]);

        return $statement->fetch() ?: null;
    }

    /** The model is built from a row and exposes no accessor for this. */
    private function emailOf(User $user): string
    {
        $statement = $this->pdo->prepare('SELECT email FROM users WHERE id=?');
        $statement->execute([$user->getId()]);

        return (string) $statement->fetchColumn();
    }

    private function attempt(string $email, string $password): bool
    {
        return app()->container()->get(AccountServiceInterface::class)->authenticate(
            new PasswordCredentials($email, $password),
            'users',
        );
    }
}
