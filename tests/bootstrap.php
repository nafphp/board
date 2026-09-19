<?php

declare(strict_types=1);

/**
 * Boot an installation, not the package.
 *
 * The board has no host of its own -- it is a library, and its suite runs inside
 * a skeleton that installs it. That is also the honest thing to test: what a
 * person gets is the board as some installation serves it, not the board on its
 * own.
 *
 * So BASE_PATH is the host's, the autoloader is the host's, and the board
 * arrives the ordinary way: NAF discovers it as a naf-plugin and loads this
 * package's bootstrap.php itself.
 *
 * NAF_HOST is how the host says where it is, because a development install
 * symlinks this package and __DIR__ then resolves to the working copy rather
 * than to vendor/. The fallback is the installed layout,
 * <host>/vendor/naf/board/tests.
 */

$host = getenv('NAF_HOST') ?: dirname(__DIR__, 4);

if (!is_file($host . '/vendor/autoload.php')) {
    fwrite(STDERR, "No host application at $host. Set NAF_HOST to an installation that requires naf/board.\n");
    exit(2);
}

require_once $host . '/vendor/autoload.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', $host);
}
