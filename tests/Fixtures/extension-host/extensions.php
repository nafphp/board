<?php

declare(strict_types=1);

use Naf\Board\Definition\ViewOverride;

use function Naf\Board\extensions;
use function Naf\json;
use function Naf\route;

// Capture boot-time state, before the host replaces an extension's definition.
$providers = extensions()->executed();
$view      = extensions()->views()->resolve('example-a/reports');
extensions()->views()->add(new ViewOverride('example-a/reports', 'host/reports'), replace: true);

route()->add('GET', '/health/live', static fn() => json([
    'providers'     => $providers,
    'provider_view' => $view,
    'route'         => 'host-extensions',
]), 'health.live');
