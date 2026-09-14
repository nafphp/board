<?php

declare(strict_types=1);
use App\Models\User;

$settings = [
    'app' => ['name' => 'Nafinity', 'url' => getenv('APP_URL') ?: 'http://localhost:8088'],
    'database' => [
        'driver' => getenv('DB_DRIVER') ?: 'mysql', 'host' => getenv('DB_HOST') ?: 'db',
        'port' => getenv('DB_PORT') ?: '3306', 'database' => getenv('DB_DATABASE') ?: 'nafinity',
        'username' => getenv('DB_USERNAME') ?: 'nafinity', 'password' => getenv('DB_PASSWORD') ?: '', 'charset' => 'utf8mb4',
    ],
    'public_url' => getenv('APP_URL') ?: 'http://localhost:8088',
    'oauth' => ['accounts' => ['provider' => 'users','auto_register' => false],'error_route' => '/login','tokens' => ['store' => false]],
    'auth' => ['users' => ['model' => User::class,'username_field' => 'email','password_field' => 'password_hash'], 'session' => true],
    'session' => ['storage' => 'default','trust_proxy_headers' => false],
    'nafinity' => ['mail_enabled' => false,'mail_from' => 'notifications@example.test'],
    'storage' => ['disks' => ['attachments' => ['adapter' => \Naf\Storage\Adapters\LocalAdapter::class, 'root' => BASE_PATH.'/storage/attachments']]],
    'queue' => ['heartbeat_file' => '/tmp/nafinity-worker-heartbeat'],
    'schedule' => ['heartbeat_file' => '/tmp/nafinity-ticker-heartbeat'],
    'csrf_validation' => true,
    'mail' => ['transport' => \Naf\Mail\Core\Transport\DummyTransport::class],
    'view' => ['paths' => ['app/views']],
];

$identityConfig = dirname(__DIR__).'/identity.local.php';
if (is_file($identityConfig)) {
    $settings = array_replace_recursive($settings, require $identityConfig);
}
return $settings;
