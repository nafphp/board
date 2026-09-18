<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Nafinity\Support\SettingsContext;

/**
 * Reading and writing declared settings under their own authorization.
 */
interface SettingsServiceInterface
{
    /**
     * Every readable value of one scope, with its effective typed value
     *
     * @param SettingsContext $context Scope and owner
     *
     * @return array<string, mixed>
     */
    public function all(SettingsContext $context): array;

    /**
     * One value; an unknown key falls back to the caller's default
     *
     * @param SettingsContext $context Scope and owner
     * @param string          $key     Literal key
     * @param mixed           $default Returned for an unknown key only
     */
    public function get(SettingsContext $context, string $key, mixed $default = null): mixed;

    /**
     * Whether a key is registered and readable here, even when its value is null
     *
     * @param SettingsContext $context Scope and owner
     * @param string          $key     Literal key
     */
    public function has(SettingsContext $context, string $key): bool;

    /**
     * Validate and store a subset; one error prevents every write of the request
     *
     * @param SettingsContext      $context   Scope and owner
     * @param array<string, mixed> $values    Values to store
     * @param list<string>         $resetKeys Keys reset to their definition default
     */
    public function save(SettingsContext $context, array $values, array $resetKeys = []): void;
}
