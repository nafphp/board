<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

file_put_contents(dirname(__DIR__) . '/src/Resources/public/assets/tokens.css', \Naf\Board\Support\Themes::baseCss());
