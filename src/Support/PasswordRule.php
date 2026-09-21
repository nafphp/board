<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use SensitiveParameter;

/**
 * What counts as a password here, in one place.
 *
 * It used to sit inside the one method that changed a password. A second way to
 * set one -- the command line -- would have restated it, and two statements of
 * the same rule drift: the day the length changes, one of them keeps accepting
 * what the other refuses.
 *
 * The upper bound is bcrypt's, not a policy: it silently truncates beyond 72
 * bytes, so a longer password would be accepted and then be a different one.
 *
 * @internal
 */
final class PasswordRule
{
    public const int MINIMUM_CHARACTERS = 15;
    public const int MAXIMUM_BYTES      = 72;

    /** @return string|null what is wrong with it, or null when nothing is */
    public static function complaint(#[SensitiveParameter] string $password): ?string
    {
        if (
            mb_strlen($password) < self::MINIMUM_CHARACTERS
            || strlen($password) > self::MAXIMUM_BYTES
            || str_contains($password, "\0")
        ) {
            return sprintf(
                'Ein Passwort braucht mindestens %d Zeichen und darf höchstens %d Bytes lang sein.',
                self::MINIMUM_CHARACTERS,
                self::MAXIMUM_BYTES,
            );
        }

        return null;
    }
}
