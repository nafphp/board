<?php

declare(strict_types=1);

namespace Nafinity\Definition;

use InvalidArgumentException;

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
        if (!in_array($kind, ['css', 'js'], true)) {
            throw new InvalidArgumentException(
                'Asset "' . $id . '" must be css or js, not "' . $kind . '".',
            );
        }

        // The asset service reads the type from the path's extension, and a
        // query string hides it. A published plugin asset carries its version in
        // its name or its directory instead.
        if (str_contains($publicPath, '?')) {
            throw new InvalidArgumentException(
                'Asset "' . $id . '" must not carry a query string: ' . $publicPath,
            );
        }
    }
}
