<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Auth\Auth;
use Naf\Auth\Provider\OrmProvider;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\OAuth\Client\Account\Accounts;
use Naf\OAuth\Client\Account\PdoAccountLinks;
use Naf\OAuth\Client\Core\Callback;
use Naf\OAuth\Client\Exception\OAuthException;
use Naf\OAuth\Client\Identity\ExternalIdentity;

use function Naf\app;

/**
 * Linking an external identity to a local account.
 *
 * A matching, even verified, email address is not proof of the same person:
 * whoever controls an address at the provider could otherwise walk into the
 * local account that happens to use it. So a link is only ever made by someone
 * already signed in locally, and only for themselves.
 */
final class OAuthAccountLinkTest extends BoardTestCase
{
    private Auth $local;
    private Accounts $accounts;
    private ExternalIdentity $identity;
    private Callback $login;

    protected function setUp(): void
    {
        parent::setUp();

        // An Auth of its own, so signing in here does not disturb the identity
        // the rest of the suite acts under.
        $this->local = new Auth();
        $this->local->addProvider('users', app()->container()->get(OrmProvider::class));
        $this->accounts = new Accounts(new PdoAccountLinks($this->pdo), $this->local, 'users', false);

        $this->identity = new ExternalIdentity(
            'fixture',
            'https://issuer.example.test',
            'fixture-alice',
            email: 'alice@example.test',
            emailVerified: true,
        );
        $this->login = new Callback($this->identity, 'login', null, '/');
    }

    public function testAVerifiedEmailAloneDoesNotLinkAnAccount(): void
    {
        try {
            $this->accounts->complete($this->login);
            $this->fail('the external identity was linked on a matching email address alone');
        } catch (OAuthException $exception) {
            $this->assertSame('not_linked', $exception->reason);
        }
    }

    public function testALinkStartedByOneUserCannotBeFinishedByAnother(): void
    {
        $this->local->setIdentity($this->alice, 'users');
        $link = new Callback(
            $this->identity,
            'link',
            ['provider' => 'users', 'id' => $this->alice->getIdentifier()],
            '/',
        );

        $this->local->setIdentity($this->bob, 'users');

        try {
            $this->accounts->complete($link);
            $this->fail('Bob completed a link Alice had started');
        } catch (OAuthException $exception) {
            $this->assertSame('initiator_mismatch', $exception->reason);
        }
    }

    public function testOnceLinkedDeliberatelyTheIdentitySignsIn(): void
    {
        $link = new Callback(
            $this->identity,
            'link',
            ['provider' => 'users', 'id' => $this->alice->getIdentifier()],
            '/',
        );
        $this->local->setIdentity($this->alice, 'users');
        $this->accounts->complete($link);
        $this->local->logout();

        $this->assertSame(
            $this->alice->getIdentifier(),
            $this->accounts->complete($this->login)->getIdentifier(),
        );
    }
}
