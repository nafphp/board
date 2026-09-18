<?php

declare(strict_types=1);

use Example\ExtensionA\ExtensionAProvider;
use Naf\Database\Support\MigrationRegistry;

use function Nafinity\extensions;

/**
 * Composer plugins boot before Nafinity registers its own defaults, so nothing
 * is registered here — the provider is only noted, and Nafinity runs it once
 * its own definitions exist. That is what makes an override from a package
 * actually survive.
 */
extensions()->register('example.reports', ExtensionAProvider::class, 100);

// The migration registry is a plain static list with no boot order of its own,
// so the path can be named here; everything that needs a service waits for the
// provider.
MigrationRegistry::addPath(__DIR__ . '/src/Migrations');
