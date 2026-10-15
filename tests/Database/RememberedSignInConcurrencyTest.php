<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Closure;
use Naf\Board\Contracts\RememberStoreInterface;
use Naf\Board\Services\RememberService;
use Naf\Board\Services\RememberStore;
use Naf\Board\Support\RememberToken;
use Naf\Board\Tests\Support\AccountTestCase;

final class RememberedSignInConcurrencyTest extends AccountTestCase
{
    public function testAConcurrentRotationIsRecheckedAfterTheAccountLock(): void
    {
        [$user] = $this->newAccount();
        $store  = new RememberStore($this->pdo);
        (new RememberService($this->auth, $store, $this->pdo))->issue((int) $user->getId());
        $this->auth->logout();
        $spent       = RememberToken::parse($_COOKIE[RememberService::COOKIE]);
        $lookups     = 0;
        $interleaved = new RememberStoreHooks($store, static function () use ($store, $spent, &$lookups): void {
            if (++$lookups === 1) {
                $store->rotate($spent->selector, $spent->rotated());
            }
        });

        self::assertFalse((new RememberService($this->auth, $interleaved, $this->pdo))->resume());
        self::assertNull($this->auth->id());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM remembered_sign_ins'));
    }

    public function testPasswordResetWaitsForPublicationAndThenRevokesTheSession(): void
    {
        [$user] = $this->newAccount();
        $id     = (int) $user->getId();
        $store  = new RememberStore($this->pdo);
        (new RememberService($this->auth, $store, $this->pdo))->issue($id);
        $this->auth->logout();
        $hash        = $this->hasher->hash('A password changed during resume!');
        $process     = null;
        $pipes       = [];
        $interleaved = new RememberStoreHooks($store, null, static function () use ($id, $hash, &$process, &$pipes): void {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/../Fixtures/remember-mutation.php', (string) $id, $hash],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 5);
            self::assertSame("ready\n", fgets($pipes[1]));
            usleep(100000);
            self::assertTrue(proc_get_status($process)['running'], 'A password reset committed inside the publication window.');
        });

        try {
            self::assertTrue((new RememberService($this->auth, $interleaved, $this->pdo))->resume());
            self::assertSame("reset committed\n", stream_get_contents($pipes[1]));
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            $process = null;
            $this->auth->reset();

            self::assertNull($this->auth->id(), 'The session adopted credentials changed after its token was checked.');
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
    }
}

final readonly class RememberStoreHooks implements RememberStoreInterface
{
    public function __construct(private RememberStore $inner, private ?Closure $afterFind = null, private ?Closure $afterRotate = null)
    {
    }

    public function remember(int $user, RememberToken $token, int $version, int $days): void
    {
        $this->inner->remember($user, $token, $version, $days);
    }

    public function find(string $selector): ?array
    {
        $row = $this->inner->find($selector);
        ($this->afterFind ?? static fn() => null)();

        return $row;
    }

    public function rotate(string $selector, RememberToken $fresh): void
    {
        $this->inner->rotate($selector, $fresh);
        ($this->afterRotate ?? static fn() => null)();
    }

    public function forget(string $selector): void
    {
        $this->inner->forget($selector);
    }

    public function forgetAll(int $user): void
    {
        $this->inner->forgetAll($user);
    }

    public function prune(): int
    {
        return $this->inner->prune();
    }
}
