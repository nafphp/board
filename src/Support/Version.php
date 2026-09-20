<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * Which build of the board this is.
 *
 * Asked of Composer rather than written into a constant here, because a constant
 * in a package is a number somebody has to remember to change and nobody does.
 * What Composer knows is what was actually installed, which is the only answer
 * worth putting on a page.
 *
 * The board and not the installation around it: a skeleton carries no version at
 * all -- Composer calls it "1.0.0+no-version-set" -- while the package people
 * upgrade is this one.
 */
final class Version
{
    public const string PACKAGE = 'naf/board';

    /** Empty when Composer cannot say, which is worth showing nothing at all for. */
    public static function current(): string
    {
        try {
            if (!InstalledVersions::isInstalled(self::PACKAGE)) {
                return '';
            }

            return (string) InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (OutOfBoundsException) {
            return '';
        }
    }
}
