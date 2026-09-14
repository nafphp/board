<?php
declare(strict_types=1);
define('BASE_PATH', __DIR__);
require __DIR__.'/vendor/autoload.php';

use App\Domain\ProjectScope;
use App\Events\ActivityListener;
use App\Policies\ProjectPolicy;
use App\Commands\SeedCommand;
use Naf\Auth\Auth;
use Naf\CLI\Support\CommandRegistry;
use function Naf\{app, event, config};

$container=app()->container();
$container->set(\Psr\Log\LoggerInterface::class, new \App\Support\ContainerLogger());
// Nafinity requires a database; naf/database itself remains optional/nullable.
foreach (['host','database','username','password'] as $field) {
    if (!is_string(config('database:'.$field)) || config('database:'.$field)==='') {
        throw new RuntimeException('Nafinity requires database configuration: '.$field);
    }
}
$container->get(Auth::class)->policy(ProjectScope::class, new ProjectPolicy());
$container->set(ActivityListener::class, static fn()=>$container->make(ActivityListener::class));
event()->listen('nafinity.changed', static fn(\App\Domain\Change $change)=>$container->get(ActivityListener::class)->record($change));
$container->get(CommandRegistry::class)->add(SeedCommand::class);
$container->set(\App\Support\AttachmentStorage::class, static fn()=>new \App\Support\AttachmentStorage(\Naf\Storage\storage('attachments'), BASE_PATH.'/storage/attachments'));
$container->set(\Naf\Queue\Core\Queue::class, static fn()=>new \Naf\Queue\Core\Queue(new \Naf\Queue\Drivers\PDODriver($container->get(PDO::class),300)));
$container->set(\Naf\Schedule\Core\Scheduler::class, static fn()=>new \Naf\Schedule\Core\Scheduler($container->get(\Naf\Queue\Core\Queue::class),$container->get(\Naf\Schedule\Core\JobRepository::class),$container->get(\Naf\Schedule\Support\CronParser::class),BASE_PATH.'/storage/schedule/state.json'));
$container->get(\Naf\Schedule\Core\JobRepository::class)->add(\App\Jobs\MaintenanceJob::class,[]);
if (config('ldap:enabled',false)) {
    $container->set(\Naf\Auth\Ldap\LdapProvider::class,static function()use($container){
        $pdo=$container->get(PDO::class);$directoryName=config('ldap:directory');
        $lookup=static function($column,$value,$result)use($pdo,$directoryName){$q=$pdo->prepare("SELECT $result FROM ldap_identities WHERE directory=? AND $column=?");$q->execute([$directoryName,$value]);$found=$q->fetchColumn();return $found===false?null:(string)$found;};
        return new \Naf\Auth\Ldap\LdapProvider(new \Naf\Auth\Ldap\NativeDirectory(...config('ldap:connection')),static fn($subject)=>$lookup('subject',$subject,'user_id'),static fn($id)=>$lookup('user_id',$id,'subject'),$container->get(\Naf\Auth\Provider\OrmProvider::class));
    });
    $container->get(Auth::class)->addProvider('ldap',\Naf\Auth\Ldap\LdapProvider::class);
}
app()->run();
