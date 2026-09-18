<?php

declare(strict_types=1);

use App\Commands\CheckAssetsCommand;
use App\Commands\PublishAssetsCommand;
use App\Commands\RemoveAssetsCommand;
use App\Commands\SeedCommand;
use App\Domain\Change;
use App\Domain\ProjectScope;
use App\Events\ActivityListener;
use App\Jobs\MaintenanceJob;
use App\Modules\CoreTicket;
use App\Modules\NafinityDefaults;
use App\Policies\ProjectPolicy;
use App\Support\AccountStateStore;
use App\Support\AttachmentStorage;
use App\Support\ContainerLogger;
use App\Support\ServiceDefaults;
use Naf\Auth\Auth;
use Naf\Auth\Ldap\LdapProvider;
use Naf\Auth\Ldap\NativeDirectory;
use Naf\Auth\Provider\OrmProvider;
use Naf\Auth\Session\StateStoreInterface;
use Naf\CLI\Support\CommandRegistry;
use Naf\Queue\Core\Queue;
use Naf\Queue\Drivers\PDODriver;
use Naf\Schedule\Core\JobRepository;
use Naf\Schedule\Core\Scheduler;
use Naf\Schedule\Support\CronParser;
use Nafinity\ExtensionContext;
use Nafinity\Support\Resolver;
use Psr\Log\LoggerInterface;

use function Naf\app;
use function Naf\config;
use function Naf\event;
use function Nafinity\extensions;

define('BASE_PATH', __DIR__);
require __DIR__ . '/vendor/autoload.php';

$container = app()->container();
$container->set(LoggerInterface::class, new ContainerLogger());
// Replaceable application services are bound before anything resolves them, and
// nothing here resolves one: an extension that rebinds a contract further down
// still reaches every consumer, including the worker.
ServiceDefaults::register($container);
// Nafinity requires a database; naf/database itself remains optional/nullable.
foreach (['host', 'database', 'username', 'password'] as $field) {
    if (!is_string(config('database:' . $field)) || config('database:' . $field) === '') {
        throw new RuntimeException('Nafinity requires database configuration: ' . $field);
    }
}
$container->set(StateStoreInterface::class, static fn() => $container->make(AccountStateStore::class));
$container->get(Auth::class)->policy(ProjectScope::class, new ProjectPolicy());
$container->set(ActivityListener::class, static fn() => $container->make(ActivityListener::class));
event()->listen(
    'nafinity.changed',
    static fn(Change $change) => $container
        ->get(ActivityListener::class)
        ->record($change),
);
$commands = $container->get(CommandRegistry::class);
$commands->add(SeedCommand::class);
$commands->add(PublishAssetsCommand::class);
$commands->add(CheckAssetsCommand::class);
$commands->add(RemoveAssetsCommand::class);
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
// Nafinity's own contribution definitions exist before any extension runs, so a
// plugin replaces something that is already there.
$extensions = extensions();
$context    = new ExtensionContext($container, $extensions);
Resolver::service($container, NafinityDefaults::class)->register($context);

// Extensions noted during Composer plugin boot run now, ascending by index and
// id. Nothing registered here is overwritten by an application default.
$extensions->initialize($container);

// The provider pass is over, so every group a ticket field points at must now
// have a panel. Saying which field and which group beats a later missing panel.
$extensions->ticketFields()->assertGroups(CoreTicket::groups($context));

// The host has the last word: an optional file that may replace or remove any
// definition, including one an extension just registered.
$hostOverrides = __DIR__ . '/app/extensions.php';
if (is_file($hostOverrides)) {
    require $hostOverrides;
}

app()->run();
