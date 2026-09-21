<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Auth\Auth;
use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Models\User;
use Naf\Board\Rbac\Grants;
use Naf\Board\Services\Access;
use Naf\Board\Services\AccountService;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use Naf\Mail\Core\TransportInterface;
use Naf\ORM\Core\EntityManager;
use Naf\Queue\Core\Queue;
use Naf\RateLimit\PdoLimiter;

use function Naf\app;

/**
 * An account of its own, for the questions that are about credentials rather
 * than about boards. None of this needs a project, so none of it builds one.
 *
 * Each account gets a fresh address, because several tests hold two at once and
 * the point of half of them is what one account may not do to another's.
 */
abstract class AccountTestCase extends DatabaseTestCase
{
    use AssertsFailures;

    protected const CURRENT_PASSWORD = 'Original account password!';

    /** @var array<string,string> */
    protected const NEW_PASSWORD = [
        'current_password'      => self::CURRENT_PASSWORD,
        'password'              => 'My changed account password!',
        'password_confirmation' => 'My changed account password!',
    ];

    /**
     * Not transactional: reauthenticating and sending a verification mail both
     * pass the rate limiter, and an attempt that a rollback could erase would be
     * no limit at all. The rows are cleared afterwards instead, which costs
     * milliseconds now that it empties tables rather than rebuilding them.
     */
    protected bool $transactional = false;

    protected Auth $auth;
    protected EntityManager $entityManager;
    protected PasswordHasher $hasher;

    private static ?string $originalHash = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth          = app()->container()->get(Auth::class);
        $this->entityManager = app()->container()->get(EntityManager::class);
        $this->hasher        = app()->container()->get(PasswordHasher::class);
    }

    protected function tearDown(): void
    {
        $this->auth->logout();
        parent::tearDown();
        self::clearAllRows();
    }

    /**
     * A signed-in account and the service acting for it.
     *
     * @return array{User,AccountService,DummyTransport|TransportInterface}
     */
    protected function newAccount(?TransportInterface $transport = null): array
    {
        $container = app()->container();
        $user      = new User([
            'name'  => 'Account test',
            'email' => 'account-' . bin2hex(random_bytes(8)) . '@example.test',
            // Hashed once for the whole run: a password hash is expensive on
            // purpose, and every account here uses the same fixture password.
            'password_hash' => self::$originalHash ??= $this->hasher->hash(self::CURRENT_PASSWORD),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->save($user);
        Grants::ensureDefault((int) $user->getId());
        $this->auth->setIdentity($user);

        $transport ??= new DummyTransport();
        $service = new AccountService(
            $this->pdo,
            $this->auth,
            $container->make(Access::class),
            $this->entityManager,
            $this->hasher,
            $container->get(PdoLimiter::class),
            new Mailer($transport),
            $container->get(Queue::class),
        );

        return [$user, $service, $transport];
    }

    /** @return array{email:string,current_password:string} */
    protected function emailRequest(string $email): array
    {
        return ['email' => $email, 'current_password' => self::CURRENT_PASSWORD];
    }

    /** The code out of the mail that was just sent. */
    protected function codeFrom(DummyTransport $transport): string
    {
        $messages = $transport->getMessages();
        $this->assertNotEmpty($messages, 'no verification mail was sent');
        $content = $messages[array_key_last($messages)]->getContent();

        $this->assertSame(
            1,
            preg_match('/Bestätigungscode: ([A-Z2-9-]+)/u', $content, $matches),
            'the verification mail carries no code',
        );

        return $matches[1];
    }
}
