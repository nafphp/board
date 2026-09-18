<?php

declare(strict_types=1);

namespace Nafinity\Definition;

/**
 * A package directory whose public files may be published into app/public.
 */
final readonly class AssetPackage
{
    /**
     * @param string $packageName Composer package name, for example example/nafinity-extension-a
     * @param string $directory   Absolute source directory holding publishable files
     * @param int    $index       Sort value, ascending
     */
    public function __construct(
        public string $packageName,
        public string $directory,
        public int $index = 100,
    ) {
    }

    public function id(): string
    {
        return $this->packageName;
    }
}
