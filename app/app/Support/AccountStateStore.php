<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Auth\Session\SessionStateStore;
use Naf\Auth\Session\StateStoreInterface;
use Naf\Session\Core\Session;
use PDO;
use RuntimeException;

/** Adds account-wide revocation to NAF's rotating session store. */
final class AccountStateStore implements StateStoreInterface
{
    private const VERSION_KEY = 'account.security_version';

    public function __construct(
        private SessionStateStore $store,
        private Session $session,
        private PDO $pdo,
    ) {
    }

    public function read(): ?array
    {
        $identity = $this->store->read();
        if ($identity === null) {
            return null;
        }

        $version = $this->version($identity['identifier']);
        // Existing sessions predate this migration and belong to revision zero.
        if ($version === null || $version !== $this->session->get(self::VERSION_KEY, 0)) {
            $this->clear();

            return null;
        }

        return $identity;
    }

    public function write(string $provider, string $identifier): void
    {
        $version = $this->version($identifier);
        if ($version === null) {
            $this->clear();
            throw new RuntimeException('Account is unavailable.');
        }
        $this->store->write($provider, $identifier);
        $this->session->set(self::VERSION_KEY, $version);
    }

    public function clear(): void
    {
        $this->session->forget(self::VERSION_KEY);
        $this->store->clear();
    }

    private function version(string $identifier): ?int
    {
        $statement = $this->pdo->prepare('SELECT security_version FROM users WHERE id=? AND active=1');
        $statement->execute([$identifier]);
        $version = $statement->fetchColumn();

        return $version === false ? null : (int) $version;
    }
}
