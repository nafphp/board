<?php

declare(strict_types=1);

namespace Nafinity\Definition;

/**
 * A stylesheet or script the layout renders for every page.
 */
final readonly class AssetDefinition
{
    /**
     * @param string $id         Stable asset id, namespaced for plugins
     * @param string $publicPath Absolute public URL below the document root
     * @param string $kind       Either `css` or `js`
     * @param int    $index      Sort value, ascending
     * @param bool   $module     Whether a script is an ES module
     */
    public function __construct(
        public string $id,
        public string $publicPath,
        public string $kind,
        public int $index = 100,
        public bool $module = false,
    ) {
    }
}
