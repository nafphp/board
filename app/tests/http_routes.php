<?php

// Loaded only by APP_ENV=test in the disposable app-test service.
use function Naf\{route,json,response};

route()->add('GET', '/__test/source', static fn () => json(['value' => \Naf\Support\NafinitySourceProbe::VALUE,'file' => (new ReflectionClass(\Naf\Support\NafinitySourceProbe::class))->getFileName()]), 'test.source');
route()->add('GET', '/__test/stream', static function () {
    $file = BASE_PATH.'/storage/stream-fixture';
    $handle = fopen($file, 'w+b');
    ftruncate($handle, 64 * 1024 * 1024);
    rewind($handle);
    $start = memory_get_usage(true);
    register_shutdown_function(static function () use ($start, $file) {
        file_put_contents(BASE_PATH.'/storage/stream-evidence.json', json_encode(['bytes' => 64 * 1024 * 1024,'peak_delta' => memory_get_peak_usage(true) - $start]));
        unlink($file);
    });
    return response()->withBody(\Nyholm\Psr7\Stream::create($handle))->withHeader('Content-Type', 'application/octet-stream')->withHeader('Content-Length', (string)(64 * 1024 * 1024));
}, 'test.stream');
