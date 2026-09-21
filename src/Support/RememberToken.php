<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use SensitiveParameter;

/**
 * The two halves of a remember-me cookie.
 *
 * A selector to find the row by and a validator to prove it with. One token
 * would mean either looking a secret up -- which needs it indexed, and therefore
 * stored as itself -- or scanning every row and comparing. Splitting it lets the
 * lookup be an index hit and the proof be a constant-time comparison against a
 * hash, which are two different jobs wanting two different treatments.
 *
 * Nothing here knows about this application. It is written so that the day it
 * belongs to naf/auth it moves as a file, with its namespace as the only edit --
 * so no board type appears in it, and none should be added.
 */
final readonly class RememberToken
{
    /** Long enough that two selectors never collide; short enough to index. */
    private const int SELECTOR_BYTES = 16;

    /**
     * 256 bits, which is what makes a fast hash the right one at rest: there is
     * no dictionary to run against randomness of this size, so the slowness a
     * password hash buys would protect nobody and be paid on every request.
     */
    private const int VALIDATOR_BYTES = 32;

    public function __construct(
        public string $selector,
        #[SensitiveParameter]
        public string $validator,
    ) {
    }

    public static function issue(): self
    {
        return new self(
            bin2hex(random_bytes(self::SELECTOR_BYTES)),
            bin2hex(random_bytes(self::VALIDATOR_BYTES)),
        );
    }

    /**
     * Read a cookie back, or null when it is not one of ours
     *
     * Everything about the shape is checked here rather than by whoever stored
     * it: a cookie is something a stranger writes, and the first thing done with
     * it is a database lookup.
     *
     * @param string $cookie The raw cookie value
     */
    public static function parse(#[SensitiveParameter] string $cookie): ?self
    {
        if (substr_count($cookie, '.') !== 1) {
            return null;
        }

        [$selector, $validator] = explode('.', $cookie);

        $wellFormed = strlen($selector) === self::SELECTOR_BYTES * 2
            && strlen($validator) === self::VALIDATOR_BYTES * 2
            && ctype_xdigit($selector)
            && ctype_xdigit($validator);

        return $wellFormed ? new self($selector, $validator) : null;
    }

    /**
     * The same series, with a new secret.
     *
     * The selector stays, and that is what makes a copied cookie findable at
     * all: a rotation that changed both halves would leave the old cookie naming
     * no row, which is indistinguishable from a cookie out of thin air. Keeping
     * the selector means the stale one still arrives at its row and fails there,
     * which is a fact about this account rather than about a stranger.
     */
    public function rotated(): self
    {
        return new self($this->selector, bin2hex(random_bytes(self::VALIDATOR_BYTES)));
    }

    public function cookie(): string
    {
        return $this->selector . '.' . $this->validator;
    }

    /** What is stored: never the validator, always this. */
    public function hash(): string
    {
        return hash('sha256', $this->validator);
    }

    /**
     * Whether this token proves the stored row
     *
     * Constant time, because the alternative leaks how much of a guess was
     * right, and a validator can be guessed one character at a time by anybody
     * who can measure that.
     *
     * @param string $storedHash The hash held in the row this selector found
     */
    public function proves(#[SensitiveParameter] string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash());
    }
}
