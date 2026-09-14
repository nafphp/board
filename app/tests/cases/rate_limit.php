<?php

$limiter = $c->make(\Naf\RateLimit\PdoLimiter::class);
test('atomic login counters isolate keys and expire', function () use ($limiter) {
    check($limiter->consume('test-account', 2, 60, 120)['allowed'], 'first denied');
    check($limiter->consume('test-account', 2, 60, 121)['allowed'], 'second denied');
    $blocked = $limiter->consume('test-account', 2, 60, 122);
    check(!$blocked['allowed'] && $blocked['retry_after'] === 58, 'limit did not block');
    check($limiter->consume('different-account', 2, 60, 122)['allowed'], 'keys mixed');
    check($limiter->consume('test-account', 2, 60, 180)['allowed'], 'window not reset');
});
