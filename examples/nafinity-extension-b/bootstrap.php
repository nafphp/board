<?php

declare(strict_types=1);

use Example\ExtensionB\ExtensionBProvider;

use function Nafinity\extensions;

// Index 200 runs after extension A's 100, which is what lets this package
// replace A's contributions instead of racing them.
extensions()->register('example.review', ExtensionBProvider::class, 200);
