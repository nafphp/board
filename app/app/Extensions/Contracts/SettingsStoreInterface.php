<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Nafinity\Support\SettingsContext;

/**
 * Where declared settings values actually live.
 *
 * The store is the persistence boundary only: definition, type and permission
 * checks stay in the settings service. A stored value and a missing value are
 * always distinguishable, whatever the value is.
 */
interface SettingsStoreInterface
{
    /**
     * @param SettingsContext $context Scope and owner
     * @param list<string>    $keys    Literal keys to read
     *
     * @return array<string, mixed> Only the keys that are actually stored
     */
    public function read(SettingsContext $context, array $keys): array;

    /**
     * Write a subset atomically, inside the caller's transaction
     *
     * @param SettingsContext      $context   Scope and owner
     * @param array<string, mixed> $values    Values to store
     * @param list<string>         $resetKeys Keys whose stored value is removed
     */
    public function write(SettingsContext $context, array $values, array $resetKeys = []): void;
}
