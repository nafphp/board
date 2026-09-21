<?php

declare(strict_types=1);
use Naf\Core\App;

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

/*
 * Booting for the command line leaves out the guards a request would install,
 * and the escaping guard among them is what turns text into safe HTML. Anything
 * that renders -- a description, a mention -- would otherwise be tested against
 * a different set of rules than the one that runs in front of people.
 */
(new ReflectionMethod(App::class, 'loadGuards'))->invoke(Naf\app());

/*
 * The test classes, which the host's autoloader knows nothing about: it maps
 * Naf\Board\ to this package's src/, and autoload-dev belongs to a root package
 * rather than to an installed one. Prepended so the lookup does not first go
 * looking for src/Tests/, which is not where any of this lives.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Naf\\Board\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, prepend: true);
