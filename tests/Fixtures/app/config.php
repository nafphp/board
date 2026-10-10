<?php

declare(strict_types=1);

use Naf\Board\Models\User;
use Naf\Board\Tests\Support\Outbox;
use Naf\Storage\Adapters\LocalAdapter;

/**
 * The installation the suite boots: this repository is the host, and this file
 * says what a host has to say for the board to run.
 *
 * Connection details come from the environment -- phpunit.xml supplies the
 * defaults for tests/compose.yaml, and anything set beforehand wins.
 */
return [
    'app'        => ['name' => 'Nafinity', 'url' => 'ENV:APP_URL'],
    'public_url' => 'ENV:APP_URL',

    // Host-defined settings are discovered without a Board schema or provider.
    'custom_settings' => ['enabled' => false, 'limit' => 0, 'steps' => ['first', 'second']],

    'database' => [
        'driver'   => 'mysql',
        'host'     => 'ENV:DB_HOST',
        'port'     => 'ENV:DB_PORT',
        'database' => 'ENV:DB_DATABASE',
        'username' => 'ENV:DB_USERNAME',
        'password' => 'ENV:DB_PASSWORD',
        'charset'  => 'utf8mb4',
    ],

    'session'         => ['storage' => 'default', 'trust_proxy_headers' => false],
    'csrf_validation' => true,

    'nafinity' => ['mail_enabled' => false, 'mail_from' => 'notifications@example.test', 'exports' => ['key' => 'ENV:NAFINITY_TEST_EXPORT_KEY']],
    'mail'     => ['transport' => Outbox::class],

    // Tokens are issued by the web process alone, so the suite checks the whole
    // issuing path with no socket server anywhere. The key signs nothing that
    // leaves this machine.
    'websocket' => [
        'enabled' => true,
        'key'     => 'a-key-for-the-test-suite-only',
        'url'     => 'ws://127.0.0.1:8091',
    ],

    'oauth' => [
        'accounts'    => ['provider' => 'users', 'auto_register' => false],
        'error_route' => '/login',
        'tokens'      => ['store' => false],
    ],

    'auth' => [
        'users' => [
            'model'          => User::class,
            'username_field' => 'email',
            'password_field' => 'password_hash',
        ],
        'session' => true,
    ],

    'storage' => [
        'disks' => [
            'attachments' => [
                'adapter' => LocalAdapter::class,
                'root'    => BASE_PATH . '/storage/attachments',
            ],
        ],
    ],

    'queue'    => ['heartbeat_file' => BASE_PATH . '/storage/queue/worker-heartbeat'],
    'schedule' => ['heartbeat_file' => BASE_PATH . '/storage/schedule/ticker-heartbeat'],
];
