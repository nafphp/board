<?php

declare(strict_types=1);
use Naf\Core\App;

/**
 * Boot the throwaway host that installed the example extensions.
 *
 * These tests run inside a host built by bin/check-extensions: a real
 * installation that requires the example packages through Composer and shares
 * this package's source. It names its own bootstrap through NAFINITY_BOOTSTRAP,
 * because the point is what that installation boots -- not what the development
 * one does.
 */

if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    fwrite(STDERR, "The extension checks need APP_ENV=test and DB_DATABASE=nafinity_test.\n");
    exit(2);
}

require getenv('NAFINITY_BOOTSTRAP') ?: dirname(__DIR__) . '/bootstrap.php';

// Rendering is part of what an extension does, and the guards a request would
// install are not there on the command line.
(new ReflectionMethod(App::class, 'loadGuards'))->invoke(Naf\app());

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
