<?php

declare(strict_types=1);

use Naf\Core\App;

/**
 * Boot the board the way an installation does, with this repository as host.
 *
 * Composer lists the root package among the installed naf-plugins, so NAF
 * discovers the board here exactly as it does under a host's vendor/ and loads
 * its routes, views and bootstrap.php itself. tests/Fixtures is the host: its
 * app/config.php is what an installation decides, its storage/ is scratch space.
 *
 * Booting needs no database, so unit tests run without one. The first test that
 * does need it starts its own server from tests/compose.yaml; see TestDatabase.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/Fixtures');
}

foreach (['sessions', 'logs', 'attachments', 'queue', 'schedule', 'oauth', 'mail'] as $directory) {
    if (!is_dir(BASE_PATH . '/storage/' . $directory)) {
        mkdir(BASE_PATH . '/storage/' . $directory, 0777, true);
    }
}

/*
 * Booting for the command line leaves out the guards a request would install,
 * and the escaping guard among them is what turns text into safe HTML. Anything
 * that renders -- a description, a mention -- would otherwise be tested against
 * a different set of rules than the one that runs in front of people.
 */
(new ReflectionMethod(App::class, 'loadGuards'))->invoke(Naf\app());
