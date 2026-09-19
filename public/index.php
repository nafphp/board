<?php

declare(strict_types=1);

use function Naf\app;

// The entry point is the host's, and running the application is a host's
// decision. A skeleton's index.php says exactly this much.
require dirname(__DIR__) . '/bootstrap.php';

app()->run();
