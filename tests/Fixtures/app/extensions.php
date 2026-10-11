<?php

declare(strict_types=1);
use Naf\Board\Tests\Support\InvoiceApiTransport;
use Naf\Client\Core\Client;

// This fixture is not shipped as an installation. Explicit opt-in keeps every
// invoice acceptance test on synthetic accounts while other HTTP tests use NAF.
if (getenv('APP_ENV') === 'test' && getenv('NAFINITY_TEST_INVOICE_API') === '1') {
    \Naf\app()->container()->set(Client::class, new Client([
        new InvoiceApiTransport(),
    ]));
}
