<?php

declare(strict_types=1);

namespace App\Support\Assets;

use App\Domain\Failure;
use FilesystemIterator;
use Nafinity\Definition\AssetPackage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Nafinity\extensions;

/**
 * Copies a package's public files into the document root.
 *
 * Assets are public data: stylesheets, scripts, fonts and pictures a browser
 * loads. Uploads are not assets and never travel this way.
 *
 * Every published file is recorded with its hash, so a later run can tell its
 * own work from a file the host has edited. It never overwrites or deletes
 * something it did not write, and running it twice changes nothing.
 */
final class AssetPublisher
{
    /** What a browser may be served from a package. */
    public const array EXTENSIONS = [
        'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'woff', 'woff2', 'ico',
    ];

    public function __construct(
        private string $publicRoot,
        private string $manifestRoot,
    ) {
    }

    /**
     * Publish one or every registered package
     *
     * @param string|null $package Composer package name, or null for all
     *
     * @return array{published: list<string>, unchanged: list<string>, conflicts: list<string>}
     */
    public function publish(?string $package = null): array
    {
        $result = ['published' => [], 'unchanged' => [], 'conflicts' => []];

        foreach ($this->packages($package) as $definition) {
            $manifest = $this->manifest($definition->packageName);
            $planned  = [];

            foreach ($this->sources($definition) as $relative => $source) {
                $target = $this->target($definition->packageName, $relative);
                $hash   = hash_file('sha256', $source);

                if (is_file($target) && !$this->owns($manifest, $target, $relative)) {
                    $result['conflicts'][] = $target;
                    continue;
                }

                $planned[$relative] = ['source' => $source, 'target' => $target, 'hash' => $hash];
            }

            // Nothing is written until every target is known to be ours.
            if ($result['conflicts'] !== []) {
                continue;
            }

            $written = [];

            foreach ($planned as $relative => $file) {
                $unchanged = is_file($file['target'])
                    && hash_file('sha256', $file['target']) === $file['hash'];

                if (!$unchanged) {
                    $this->write($file['source'], $file['target']);
                    $result['published'][] = $file['target'];
                } else {
                    $result['unchanged'][] = $file['target'];
                }

                $written[$relative] = $file['hash'];
            }

            $this->removeGone($definition->packageName, $manifest, $written);
            $this->storeManifest($definition->packageName, $written);
        }

        return $result;
    }

    /**
     * Report what publishing would do, without touching anything
     *
     * @param string|null $package Composer package name, or null for all
     *
     * @return array{missing: list<string>, stale: list<string>, conflicts: list<string>, current: list<string>}
     */
    public function check(?string $package = null): array
    {
        $result = ['missing' => [], 'stale' => [], 'conflicts' => [], 'current' => []];

        foreach ($this->packages($package) as $definition) {
            $manifest = $this->manifest($definition->packageName);

            foreach ($this->sources($definition) as $relative => $source) {
                $target = $this->target($definition->packageName, $relative);

                if (!is_file($target)) {
                    $result['missing'][] = $target;
                    continue;
                }

                if (!$this->owns($manifest, $target, $relative)) {
                    $result['conflicts'][] = $target;
                    continue;
                }

                if (hash_file('sha256', $target) !== hash_file('sha256', $source)) {
                    $result['stale'][] = $target;
                    continue;
                }

                $result['current'][] = $target;
            }
        }

        return $result;
    }

    /**
     * Remove what this publisher wrote, from the stored record
     *
     * This works without the package being installed, which is what makes it
     * useful after an uninstall. A file the host has changed is kept.
     *
     * @param string|null $package Composer package name, or null for all records
     *
     * @return array{removed: list<string>, kept: list<string>}
     */
    public function remove(?string $package = null): array
    {
        $result = ['removed' => [], 'kept' => []];
        $names  = $package === null ? $this->recorded() : [$package];

        foreach ($names as $name) {
            foreach ($this->manifest($name) as $relative => $hash) {
                $target = $this->target($name, $relative);

                if (!is_file($target)) {
                    continue;
                }

                if (hash_file('sha256', $target) !== $hash) {
                    $result['kept'][] = $target;
                    continue;
                }

                unlink($target);
                $result['removed'][] = $target;
            }

            $this->pruneDirectories($this->packageRoot($name));
            $file = $this->manifestFile($name);

            if ($result['kept'] === [] && is_file($file)) {
                unlink($file);
            }
        }

        return $result;
    }

    /**
     * @param string|null $package Composer package name, or null for all
     *
     * @return list<AssetPackage>
     */
    private function packages(?string $package): array
    {
        $registered = extensions()->assetPackages()->all();

        if ($package === null) {
            return array_values($registered);
        }

        if (!isset($registered[$package])) {
            throw new Failure('Für "' . $package . '" ist kein Asset-Verzeichnis registriert.', 404);
        }

        return [$registered[$package]];
    }

    /**
     * The publishable files of a package, keyed by their relative path
     *
     * @param AssetPackage $package The registered source directory
     *
     * @return array<string, string>
     */
    private function sources(AssetPackage $package): array
    {
        $root = realpath($package->directory);

        if ($root === false || !is_dir($root)) {
            throw new Failure(
                'Das Asset-Verzeichnis von "' . $package->packageName . '" fehlt: '
                . $package->directory,
                404,
            );
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $real = $file->getRealPath();

            // A symbolic link that points out of the source root would publish
            // something the package does not own, so it is simply not a source.
            if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }

            $files[substr($real, strlen($root) + 1)] = $real;
        }

        ksort($files);

        return $files;
    }

    /**
     * @param array<string, string> $manifest Recorded relative path to hash
     */
    private function owns(array $manifest, string $target, string $relative): bool
    {
        if (!isset($manifest[$relative])) {
            return false;
        }

        return hash_file('sha256', $target) === $manifest[$relative];
    }

    /**
     * @param array<string, string> $manifest Previous record
     * @param array<string, string> $written  Current record
     */
    private function removeGone(string $package, array $manifest, array $written): void
    {
        foreach ($manifest as $relative => $hash) {
            if (isset($written[$relative])) {
                continue;
            }

            $target = $this->target($package, $relative);

            if (is_file($target) && hash_file('sha256', $target) === $hash) {
                unlink($target);
            }
        }
    }

    private function write(string $source, string $target): void
    {
        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new Failure('Zielverzeichnis lässt sich nicht anlegen: ' . $directory, 500);
        }

        if (!copy($source, $target)) {
            throw new Failure('Datei lässt sich nicht veröffentlichen: ' . $target, 500);
        }

        chmod($target, 0664);
    }

    private function target(string $package, string $relative): string
    {
        return $this->packageRoot($package) . '/' . $relative;
    }

    private function packageRoot(string $package): string
    {
        return $this->publicRoot . '/plugins/' . $this->safeName($package);
    }

    /**
     * @return array<string, string>
     */
    private function manifest(string $package): array
    {
        $file = $this->manifestFile($package);

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data['files'] ?? null) ? $data['files'] : [];
    }

    /**
     * @param array<string, string> $files Relative path to hash
     */
    private function storeManifest(string $package, array $files): void
    {
        if (!is_dir($this->manifestRoot) && !mkdir($this->manifestRoot, 0775, true)) {
            throw new Failure('Manifestverzeichnis fehlt: ' . $this->manifestRoot, 500);
        }

        file_put_contents(
            $this->manifestFile($package),
            json_encode(
                ['package' => $package, 'files' => $files],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );
    }

    private function manifestFile(string $package): string
    {
        return $this->manifestRoot . '/' . str_replace('/', '.', $this->safeName($package)) . '.json';
    }

    /** @return list<string> */
    private function recorded(): array
    {
        $names = [];

        foreach (glob($this->manifestRoot . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (is_string($data['package'] ?? null)) {
                $names[] = $data['package'];
            }
        }

        return $names;
    }

    private function safeName(string $package): string
    {
        if (!preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#D', $package)) {
            throw new Failure('Ungültiger Paketname: ' . $package, 422);
        }

        return $package;
    }

    private function pruneDirectories(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }

        @rmdir($root);
        @rmdir(dirname($root));
    }
}
