<?php

declare(strict_types=1);

use Example\WipPolicy\WipPolicyProvider;

use function Naf\Board\extensions;

extensions()->register('example.wip_policy', WipPolicyProvider::class);
