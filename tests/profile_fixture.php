<?php

declare(strict_types=1);

use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Jobs\AccountSecurityNoticeJob;
use Naf\Board\Models\User;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\ORM\Core\EntityManager;
use Naf\Queue\Commands\QueueConsumeCommand;

use function Naf\app;

if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    fwrite(STDERR, "Profile fixtures require the isolated nafinity_test database.\n");
    exit(2);
}
require dirname(__DIR__) . '/bootstrap.php';
$container = app()->container();
if (($argv[1] ?? '') === 'deliver') {
    $pdo     = $container->get(PDO::class);
    $command = $container->make(QueueConsumeCommand::class);
    $pending = $pdo->prepare('SELECT COUNT(*) FROM naf_queue_jobs WHERE job_class=?');
    for ($attempt = 0; $attempt < 20; ++$attempt) {
        $pending->execute([AccountSecurityNoticeJob::class]);
        if ((int) $pending->fetchColumn() === 0) {
            exit(0);
        }
        $exit = $command->run(
            new Input(['--once'], $command->getDefinition()),
            new Output(),
        );
        if ($exit !== 0) {
            throw new RuntimeException('Account notice worker failed.');
        }
    }
    throw new RuntimeException('Account notices did not drain.');
}

$prefix = 'profile-http-' . bin2hex(random_bytes(8));
$users  = [];
foreach (['password', 'email', 'other'] as $kind) {
    $user = new User([
        'name'          => 'Profile HTTP test ' . $kind,
        'email'         => $prefix . '-' . $kind . '@example.test',
        'password_hash' => $container->get(PasswordHasher::class)->hash('Profile test original password!'),
        'created_at'    => gmdate('Y-m-d H:i:s'),
    ]);
    $container->get(EntityManager::class)->save($user);
    $users[$kind] = ['id' => $user->getId(), 'email' => $user->getProfile()->email];
}
echo json_encode($users, JSON_THROW_ON_ERROR) . "\n";
