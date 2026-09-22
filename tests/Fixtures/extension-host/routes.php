<?php

declare(strict_types=1);

use function Naf\Board\extensions;
use function Naf\json;
use function Naf\route;

// Replacing the same route once more proves that host routes are the last phase.
$before               = route()->all()['health.live']['action'];
$state                = json_decode((string) $before()->getBody(), true, flags: JSON_THROW_ON_ERROR);
$state['view']        = extensions()->views()->resolve('example-a/reports');
$state['initialized'] = extensions()->initialized();
$state['final_route'] = 'host-routes';

route()->add('GET', '/health/live', static fn() => json($state), 'health.live');
