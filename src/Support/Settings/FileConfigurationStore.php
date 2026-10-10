<?php

declare(strict_types=1);

namespace Naf\Board\Support\Settings;

use Naf\Board\Domain\Failure;
use Naf\OAuth\Client\Token\Cipher;
use RuntimeException;
use Throwable;

use function Naf\I18n\t;

/** Private, encrypted bootstrap settings, independent of the configured database. */
final class FileConfigurationStore
{
    public function __construct(private string $directory)
    {
    }

    public function read(): array
    {
        $path = $this->directory . '/values.enc';
        if (!is_file($path)) {
            return [];
        }

        try {
            $cipher = Cipher::fromKey(file_get_contents($this->directory . '/key'));
            $values = json_decode(
                $cipher->decrypt(file_get_contents($path), 'nafinity.configuration.v1'),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            if (!is_array($values) || array_is_list($values) && $values !== []) {
                throw new RuntimeException('Invalid configuration document.');
            }

            return $values;
        } catch (Throwable) {
            throw new RuntimeException(
                'Cannot read administration configuration. Restore storage/configuration with its key, '
                . 'or set NAFINITY_CONFIG_OVERRIDES=0 to recover using config.php.',
            );
        }
    }

    /** Lock, re-read and replace atomically; concurrent edits retain unrelated keys. */
    public function signature(array $values): string
    {
        if ($values === []) {
            return 'none';
        }

        return hash_hmac(
            'sha256',
            json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            file_get_contents($this->directory . '/key'),
        );
    }

    public function update(callable $change, ?string $expectedRevision = null): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create private configuration storage.');
        }
        if (!chmod($this->directory, 0700)) {
            throw new RuntimeException('Cannot protect configuration storage.');
        }
        $lock = fopen($this->directory . '/lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot lock configuration storage.');
        }
        chmod($this->directory . '/lock', 0600);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock configuration storage.');
            }
            $current = $this->read();
            if ($expectedRevision !== null && !hash_equals($this->signature($current), $expectedRevision)) {
                throw new Failure(t('Die Konfiguration wurde inzwischen geändert. Bitte lade die Seite neu.'), 409);
            }
            $values = $change($current);
            if ($values === $current) {
                return $current;
            }
            $keyPath = $this->directory . '/key';
            if (!is_file($keyPath)) {
                $this->atomicWrite($keyPath, Cipher::generateKey());
            }
            $cipher  = Cipher::fromKey(file_get_contents($keyPath));
            $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($encoded) > 1048576) {
                throw new RuntimeException('Configuration overrides exceed 1 MiB.');
            }
            $this->atomicWrite(
                $this->directory . '/values.enc',
                $cipher->encrypt($encoded, 'nafinity.configuration.v1'),
            );

            return $values;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = tempnam($this->directory, '.write-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create configuration file.');
        }

        try {
            if (!chmod($temporary, 0600)
                || file_put_contents($temporary, $contents) !== strlen($contents)
                || !rename($temporary, $path)) {
                throw new RuntimeException('Cannot replace configuration file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
