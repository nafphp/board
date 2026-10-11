<?php

declare(strict_types=1);

namespace Naf\Board\Support\Settings;

use Naf\Contracts\ConfigurationSourceInterface;

/** Loaded by the framework before database, session, mail or auth boots. */
final class ApplicationConfiguration implements ConfigurationSourceInterface
{
    public function name(): string
    {
        return 'administration';
    }

    public function load(string $basePath): array
    {
        $enabled = $_ENV['NAFINITY_CONFIG_OVERRIDES'] ?? getenv('NAFINITY_CONFIG_OVERRIDES');
        if ($enabled !== false && !filter_var($enabled, FILTER_VALIDATE_BOOL)) {
            return [];
        }

        return (new FileConfigurationStore($basePath . '/storage/configuration'))->read();
    }
}
