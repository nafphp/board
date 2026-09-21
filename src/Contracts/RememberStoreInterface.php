<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use Naf\Board\Support\RememberToken;

/**
 * Where remembered sign-ins are kept.
 *
 * Declared as a contract so the thing above it never learns what a row is, and
 * so the day this moves to naf/auth the package can declare the contract while
 * a host keeps the table. A remember-me that stores nothing cannot be revoked
 * and cannot be rotated, so there is always a store; what there is not always
 * is a database this package may assume.
 */
interface RememberStoreInterface
{
    /**
     * Keep a token for somebody, until it expires
     *
     * @param int           $user    The account being remembered
     * @param RememberToken $token   Its selector and validator; only the hash is kept
     * @param int           $version The account's security version at this moment
     * @param int           $days    How long it may be used for
     */
    public function remember(int $user, RememberToken $token, int $version, int $days): void;

    /**
     * The row a selector names, or null
     *
     * @param string $selector The public half of a presented cookie
     *
     * @return array{user_id:int,validator_hash:string,security_version:int,expired:bool}|null
     */
    public function find(string $selector): ?array;

    /**
     * Replace a token's validator with a fresh one, keeping its place
     *
     * Every use rotates. That is what makes a stolen cookie findable: the thief
     * and the owner cannot both hold the current validator, so whichever of them
     * arrives second presents one that no longer matches.
     */
    public function rotate(string $selector, RememberToken $fresh): void;

    /** Forget one token: a sign-out on this device. */
    public function forget(string $selector): void;

    /**
     * Forget every token of an account.
     *
     * Two callers, and they mean different things. Somebody signing out
     * everywhere, and a presented validator that does not match -- which means
     * the cookie was copied, and neither copy may be trusted from here.
     */
    public function forgetAll(int $user): void;

    /** Drop what has expired; a cron's business, and a count for it to report. */
    public function prune(): int;
}
