<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\AssetPackage;

/**
 * The package directories whose public files may be published.
 */
final class AssetPackageRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(AssetPackage::class, 'Asset package');
    }

    public function get(string $id): ?AssetPackage
    {
        return parent::get($id);
    }

    protected function identify(object $definition): string
    {
        return $definition->packageName;
    }
}
