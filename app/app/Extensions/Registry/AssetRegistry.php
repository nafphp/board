<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\AssetDefinition;

/**
 * Stylesheets and scripts the layout renders.
 */
final class AssetRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(AssetDefinition::class, 'Asset');
    }

    public function get(string $id): ?AssetDefinition
    {
        return parent::get($id);
    }

    /**
     * @param string $kind Either `css` or `js`
     *
     * @return array<string, AssetDefinition>
     */
    public function forKind(string $kind): array
    {
        return array_filter($this->all(), fn(AssetDefinition $asset) => $asset->kind === $kind);
    }
}
