<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\RememberStoreInterface;
use Naf\Board\Support\RememberToken;
use PDO;

/**
 * Remembered sign-ins, in this installation's database.
 *
 * Expiry is decided here rather than in SQL, and read back as a flag. Comparing
 * a stored timestamp to `NOW()` inside the query would compare the database's
 * clock to itself, which is fine until the row was written by a process on
 * another machine -- and a remember-me that silently disagrees about when it
 * ends is worse than one that ends too early.
 *
 * @internal
 */
final class RememberStore implements RememberStoreInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function remember(int $user, RememberToken $token, int $version, int $days): void
    {
        $this->pdo
            ->prepare(
                'INSERT INTO remembered_sign_ins'
                . '(user_id,selector,validator_hash,security_version,issued_at,expires_at)'
                . ' VALUES(?,?,?,?,?,?)',
            )
            ->execute([
                $user,
                $token->selector,
                $token->hash(),
                $version,
                gmdate('Y-m-d H:i:s'),
                gmdate('Y-m-d H:i:s', time() + $days * 86400),
            ]);
    }

    public function find(string $selector): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, validator_hash, security_version, expires_at'
            . ' FROM remembered_sign_ins WHERE selector=?',
        );
        $statement->execute([$selector]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'user_id'          => (int) $row['user_id'],
            'validator_hash'   => (string) $row['validator_hash'],
            'security_version' => (int) $row['security_version'],
            'expired'          => strtotime((string) $row['expires_at'] . ' UTC') <= time(),
        ];
    }

    public function rotate(string $selector, RememberToken $fresh): void
    {
        $this->pdo
            ->prepare(
                'UPDATE remembered_sign_ins SET validator_hash=?, last_used_at=? WHERE selector=?',
            )
            ->execute([$fresh->hash(), gmdate('Y-m-d H:i:s'), $selector]);
    }

    public function forget(string $selector): void
    {
        $this->pdo->prepare('DELETE FROM remembered_sign_ins WHERE selector=?')->execute([$selector]);
    }

    public function forgetAll(int $user): void
    {
        $this->pdo->prepare('DELETE FROM remembered_sign_ins WHERE user_id=?')->execute([$user]);
    }

    public function prune(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM remembered_sign_ins WHERE expires_at <= ?');
        $statement->execute([gmdate('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }
}
