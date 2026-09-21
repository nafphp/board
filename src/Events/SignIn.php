<?php

declare(strict_types=1);

namespace Naf\Board\Events;

/**
 * Somebody tried to sign in, and whether it worked.
 *
 * The one thing that happens to an installation without anything being written,
 * and therefore the one thing `nafinity.changed` structurally cannot carry. A
 * plugin that wants to notice a sign-in from a new country, lock an account
 * after a run of failures, or keep its own record of who was here had nowhere
 * to stand until this existed.
 *
 * Both outcomes travel on one event rather than two, for the reason the change
 * event carries twenty-four kinds: the listeners that care mostly care about
 * both, and a plugin interested in only one writes one line.
 *
 * Dispatched from AccountService::authenticate(), which is the single place
 * either outcome is decided -- for the password provider and the directory one
 * alike. That is what makes it worth relying on: there is no second path where
 * somebody could forget to fire it.
 */
final readonly class SignIn
{
    /**
     * @param string   $email      The address that was offered, as typed
     * @param string   $provider   Which provider answered: `users` or `ldap`
     * @param bool     $succeeded  Whether the credentials were accepted
     * @param int|null $accountId  Who it turned out to be, null when it failed
     */
    public function __construct(
        public string $email,
        public string $provider,
        public bool $succeeded,
        public ?int $accountId = null,
    ) {
    }

    public function failed(): bool
    {
        return !$this->succeeded;
    }
}
