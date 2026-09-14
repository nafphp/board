<?php

declare(strict_types=1);

use App\Commands\SeedCommand;
use App\Domain\Change;
use App\Domain\ProjectScope;
use App\Events\ActivityListener;
use App\Jobs\MaintenanceJob;
use App\Policies\ProjectPolicy;
use App\Support\AttachmentStorage;
use App\Support\ContainerLogger;
use Naf\Auth\Auth;
use Naf\Auth\Ldap\LdapProvider;
use Naf\Auth\Ldap\NativeDirectory;
use Naf\Auth\Provider\OrmProvider;
use Naf\CLI\Support\CommandRegistry;
use Naf\Queue\Core\Queue;
use Naf\Queue\Drivers\PDODriver;
use Naf\Schedule\Core\JobRepository;
use Naf\Schedule\Core\Scheduler;
use Naf\Schedule\Support\CronParser;
use Psr\Log\LoggerInterface;

use function Naf\app;
use function Naf\config;
use function Naf\event;

define('BASE_PATH', __DIR__);
require __DIR__ . '/vendor/autoload.php';

$container = app()->container();
$container->set(LoggerInterface::class, new ContainerLogger());
// Nafinity requires a database; naf/database itself remains optional/nullable.
foreach (['host', 'database', 'username', 'password'] as $field) {
    if (!is_string(config('database:' . $field)) || config('database:' . $field) === '') {
        throw new RuntimeException('Nafinity requires database configuration: ' . $field);
    }
}
$container->get(Auth::class)->policy(ProjectScope::class, new ProjectPolicy());
$container->set(ActivityListener::class, static fn() => $container->make(ActivityListener::class));
event()->listen(
    'nafinity.changed',
    static fn(Change $change) => $container
        ->get(ActivityListener::class)
        ->record($change),
);
$container->get(CommandRegistry::class)->add(SeedCommand::class);
$container->set(
    AttachmentStorage::class,
    static fn() => new AttachmentStorage(
        \Naf\Storage\storage('attachments'),
        BASE_PATH . '/storage/attachments',
    ),
);
$container->set(
    Queue::class,
    static fn() => new Queue(
        new PDODriver($container->get(PDO::class), 300),
    ),
);
$container->set(
    Scheduler::class,
    static fn() => new Scheduler(
        $container->get(Queue::class),
        $container->get(JobRepository::class),
        $container->get(CronParser::class),
        BASE_PATH . '/storage/schedule/state.json',
    ),
);
$container->get(JobRepository::class)->add(MaintenanceJob::class, []);
if (config('ldap:enabled', false)) {
    $container->set(LdapProvider::class, static function () use ($container) {
        $pdo           = $container->get(PDO::class);
        $directoryName = config('ldap:directory');
        $lookup        = static function ($column, $value, $result) use ($pdo, $directoryName) {
            $statement = $pdo->prepare(
                "SELECT $result FROM ldap_identities WHERE directory=? AND $column=?",
            );
            $statement->execute([$directoryName, $value]);
            $found = $statement->fetchColumn();

            return $found === false ? null : (string) $found;
        };

        return new LdapProvider(
            new NativeDirectory(...config('ldap:connection')),
            static fn($subject) => $lookup('subject', $subject, 'user_id'),
            static fn($id) => $lookup('user_id', $id, 'subject'),
            $container->get(OrmProvider::class),
        );
    });
    $container->get(Auth::class)->addProvider('ldap', LdapProvider::class);
}
app()->run();
