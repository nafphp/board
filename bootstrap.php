<?php

declare(strict_types=1);

use Naf\Auth\Auth;
use Naf\Auth\Ldap\LdapProvider;
use Naf\Auth\Ldap\NativeDirectory;
use Naf\Auth\Provider\OrmProvider;
use Naf\Auth\Session\StateStoreInterface;
use Naf\Board\Commands\AdminCommand;
use Naf\Board\Commands\CheckAssetsCommand;
use Naf\Board\Commands\CreateUserCommand;
use Naf\Board\Commands\GrantDefaultCommand;
use Naf\Board\Commands\PublishAssetsCommand;
use Naf\Board\Commands\RemoveAssetsCommand;
use Naf\Board\Commands\RenameMigrationNamespaceCommand;
use Naf\Board\Commands\SeedCommand;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\RememberStoreInterface;
use Naf\Board\Domain\Change;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Events\ActivityListener;
use Naf\Board\ExtensionContext;
use Naf\Board\Jobs\MaintenanceJob;
use Naf\Board\Modules\CoreTicket;
use Naf\Board\Modules\NafinityDefaults;
use Naf\Board\Policies\ProjectPolicy;
use Naf\Board\Rbac\Boards;
use Naf\Board\Rbac\Installation;
use Naf\Board\Rbac\Project;
use Naf\Board\Services\RememberService;
use Naf\Board\Services\RememberStore;
use Naf\Board\Support\AccountStateStore;
use Naf\Board\Support\AttachmentStorage;
use Naf\Board\Support\ContainerLogger;
use Naf\Board\Support\Resolver;
use Naf\Board\Support\ServiceDefaults;
use Naf\CLI\Support\CommandRegistry;
use Naf\Database\Support\MigrationRegistry;
use Naf\Queue\Core\Queue;
use Naf\Queue\Drivers\PDODriver;
use Naf\Schedule\Core\JobRepository;
use Naf\Schedule\Core\Scheduler;
use Naf\Schedule\Support\CronParser;
use Psr\Log\LoggerInterface;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\config;
use function Naf\event;
use function Naf\I18n\translation_paths;
use function Naf\Rbac\permissions;
use function Naf\Rbac\roles;

// Installed in a host, this file is the board's plugin bootstrap: NAF loads it
// through CoreFileLoader::BOOTSTRAP_FILES once the host has defined BASE_PATH
// and started the autoloader, so both steps are already done.
//
// Being the project under development is the other case. Nothing has booted a
// host, and a package is not loaded as a plugin from inside its own vendor, so
// the board stands in for one here. That is the only difference between the two
// situations; everything below is the same either way.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);

    require __DIR__ . '/vendor/autoload.php';
}

// naf/database registers BASE_PATH/app/Migrations by convention, which is meant
// for the host application. The board is a package, so it names its own the way
// every other package does -- through __DIR__, which holds whether it is the
// project being developed or a directory under a host's vendor.
MigrationRegistry::addPath(__DIR__ . '/src/Migrations');

// The board's own translations, registered the way a package registers
// anything else it contributes. Locales::available() lists them from disk, so
// a language could be offered in the picker while the translator had never
// been told where to load it from.
translation_paths()->add('naf.board', __DIR__ . '/src/Resources/lang', 0);

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
$container->set(
    RememberStoreInterface::class,
    static fn() => $container->make(RememberStore::class),
);
/*
 * The language of the whole answer, not only of a rendered page.
 *
 * It used to be set while a page was being drawn, so a JSON endpoint answered in
 * whatever language the process happened to start in -- the same message came
 * back German to somebody reading the application in English. It is a property
 * of the request and belongs where the request begins.
 *
 * Quietly ignored when it cannot be answered: a request without a session, a
 * command line with no person behind it, an installation whose database is not
 * up yet. None of those is a reason to refuse a request.
 */
/*
 * Somebody who asked to be remembered, coming back after their session is gone.
 *
 * Here rather than in a controller because it has to happen before anything
 * asks whether there is a session -- request.start is the last moment that is
 * true of, and it is true of every route at once, which is what keeps this from
 * being a thing each controller could forget.
 *
 * Quiet on every failure. A cookie that has expired, been revoked, or been
 * copied is not a reason to refuse a request; it is a reason to arrive as a
 * guest, which is what the sign-in page is for.
 */
event()->listen('request.start', static function () use ($container): void {
    try {
        if (!$container->get(Auth::class)->check()) {
            // make(), not get(): the service is not bound, and get() answers
            // only for what is -- a distinction this silent catch would hide.
            $container->make(RememberService::class)->resume();
        }
    } catch (Throwable) {
        // An installation whose database is not up yet, or a table that is one
        // migration behind. Neither is worth a broken request.
    }
});

event()->listen('request.start', static function () use ($container): void {
    try {
        $language = $container->get(BoardQueryInterface::class)->preferences()['locale'] ?? null;
        if (is_string($language) && $language !== '') {
            \Naf\I18n\translator()->setLanguage($language);
        }
    } catch (Throwable) {
        // Whatever this installation's default is, it stays.
    }
});

event()->listen(
    Change::class,
    static fn(Change $change) => $container
        ->get(ActivityListener::class)
        ->record($change),
);
$commands = $container->get(CommandRegistry::class);
$commands->add(AdminCommand::class);
$commands->add(CreateUserCommand::class);
$commands->add(GrantDefaultCommand::class);
$commands->add(SeedCommand::class);
$commands->add(RenameMigrationNamespaceCommand::class);
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

/*
 * What this installation can grant, and the one role that can grant it.
 *
 * Declared during plugin boot, which is when the registry is still being
 * filled. Nothing is written to the database here -- "naf rbac:sync" does
 * that, so installing a package never quietly widens anybody's access.
 */
Installation::declare(permissions(), roles());
Project::declare(permissions(), roles());
roles()->scope(new Boards(static fn(): PDO => $container->get(PDO::class)));

// The host has the last word: an optional file that may replace or remove any
// definition, including one an extension just registered.
// Both spellings, because NAF itself accepts either for plugins.php: a host
// laid out as app/ and one laid out as src/ are equally ordinary.
foreach (['/app/extensions.php', '/src/extensions.php'] as $hostOverrides) {
    if (is_file(BASE_PATH . $hostOverrides)) {
        require BASE_PATH . $hostOverrides;

        break;
    }
}
