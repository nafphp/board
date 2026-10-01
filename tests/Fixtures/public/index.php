<?php

declare(strict_types=1);

// The front controller of the test installation, for PHP's built-in server.
define('BASE_PATH', dirname(__DIR__));

require dirname(__DIR__, 3) . '/vendor/autoload.php';

Naf\app()->run();
