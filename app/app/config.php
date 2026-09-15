<?php

declare(strict_types=1);
use App\Models\User;
use Naf\Mail\Core\Transport\DummyTransport;
use Naf\Storage\Adapters\LocalAdapter;

$settings = [
    'app'      => ['name' => 'Nafinity', 'url' => 'ENV:APP_URL'],
    'database' => [
        'driver'   => 'ENV:DB_DRIVER',
        'host'     => 'ENV:DB_HOST',
        'port'     => 'ENV:DB_PORT',
        'database' => 'ENV:DB_DATABASE',
        'username' => 'ENV:DB_USERNAME',
        'password' => 'ENV:DB_PASSWORD',
        'charset'  => 'utf8mb4',
    ],
    'public_url' => 'ENV:APP_URL',
    'oauth'      => [
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
    'session'  => ['storage' => 'default', 'trust_proxy_headers' => false],
    'nafinity' => ['mail_enabled' => false, 'mail_from' => 'notifications@example.test'],
    'storage'  => [
        'disks' => [
            'attachments' => [
                'adapter' => LocalAdapter::class,
                'root'    => BASE_PATH . '/storage/attachments',
            ],
        ],
    ],
    'queue'           => ['heartbeat_file' => '/tmp/nafinity-worker-heartbeat'],
    'schedule'        => ['heartbeat_file' => '/tmp/nafinity-ticker-heartbeat'],
    'csrf_validation' => true,
    'mail'            => ['transport' => DummyTransport::class],
    'view'            => ['paths' => ['app/views']],
];

$identityConfig = dirname(__DIR__) . '/identity.local.php';
if (is_file($identityConfig)) {
    $settings = array_replace_recursive($settings, require $identityConfig);
}

return $settings;
