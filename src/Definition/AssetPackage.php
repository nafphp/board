<?php

declare(strict_types=1);

namespace Naf\Board\Definition;

/**
 * A package directory whose public files may be published into app/public.
 */
final readonly class AssetPackage
{
    /**
     * A plugin's files are published under its own name, so two packages can
     * never claim one path. The application's own files are different: its
     * templates refer to them at the document root, and there is only ever one
     * of it -- that is what $root is for, and a plugin has no business setting it.
     *
     * @param string $packageName Composer package name, for example example/nafinity-extension-a
     * @param string $directory   Absolute source directory holding publishable files
     * @param int    $index       Sort value, ascending
     * @param bool   $root        Publish at the document root instead of under plugins/
     */
    public function __construct(
        public string $packageName,
        public string $directory,
        public int $index = 100,
        public bool $root = false,
    ) {
    }

    public function id(): string
    {
        return $this->packageName;
    }
}
