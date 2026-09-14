<?php
declare(strict_types=1);
if (getenv('APP_ENV') !== 'test' || getenv('DB_DATABASE') !== 'nafinity_test') {
    throw new RuntimeException('This process probe requires nafinity_test.');
}
require dirname(__DIR__).'/bootstrap.php';

final class NafinityCrashProbe implements \Naf\Queue\Core\QueueJobInterface
{
    public function execute(\Naf\CLI\Core\Output $output): void
    {
        $marker=BASE_PATH.'/storage/queue-crash.started';
        if (!is_file($marker)) {
            file_put_contents($marker, (string)getmypid());
            sleep(30);
        }
        file_put_contents(BASE_PATH.'/storage/queue-crash.finished', "completed\n", FILE_APPEND);
    }
}
$c=\Naf\app()->container();
$settings=$c->get(\Naf\Core\Config::class)->all();
$settings['queue']['max_attempts']=1;
$settings['queue']['retry_delay']=0;
$c->set(\Naf\Core\Config::class,new \Naf\Core\Config($settings));
$pdo=$c->get(PDO::class);
$driver=\Naf\Queue\queue()->driver();
$command=$c->make(\Naf\Queue\Commands\QueueConsumeCommand::class);
$once=fn()=>$command->run(new \Naf\CLI\Core\Input(['--once'],$command->getDefinition()),new \Naf\CLI\Core\Output());
if (($argv[1]??'')==='worker') exit($once());
$started=BASE_PATH.'/storage/queue-crash.started';
$finished=BASE_PATH.'/storage/queue-crash.finished';
foreach([$started,$finished] as $file)if(is_file($file))unlink($file);
$pdo->exec('DELETE FROM naf_queue_jobs');
$driver->enqueue(NafinityCrashProbe::class, ['_job_id'=>'process-crash-probe']);
$process=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Cannot start test worker.');
try {
    $deadline=microtime(true)+8;
    while(!is_file($started)&&microtime(true)<$deadline)usleep(50000);
    if(!is_file($started))throw new RuntimeException('Test worker did not enter its job.');
    proc_terminate($process,9);
    proc_close($process);
    $process=null;
    if((int)$pdo->query('SELECT COUNT(*) FROM naf_queue_jobs WHERE attempts=1')->fetchColumn()!==1)throw new RuntimeException('Killed worker lost its durable reservation.');
    // Advance the lease deadline instead of waiting five minutes in the test.
    $pdo->exec('UPDATE naf_queue_jobs SET lease_until=0');
    $retry=proc_open([PHP_BINARY,__FILE__,'worker'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    if(proc_close($retry)!==0)throw new RuntimeException('Replacement worker failed.');
    if((int)$pdo->query('SELECT COUNT(*) FROM naf_queue_jobs')->fetchColumn()!==0||file_get_contents($finished)!=="completed\n")throw new RuntimeException('Replacement did not execute and acknowledge once.');
    $driver->enqueue('MissingNafinityProbeClass',[]);
    if($once()===0)throw new RuntimeException('Missing class returned success.');
    if((int)$pdo->query("SELECT COUNT(*) FROM naf_queue_jobs WHERE state='failed'")->fetchColumn()!==1)throw new RuntimeException('Missing class did not deadletter.');
    echo json_encode(['passed'=>['actual worker SIGKILL preserves reservation','fresh worker reclaims expired lease and acknowledges','missing job class returns failure and enters deadletter'],'lease_deadline_advanced_for_test'=>true],JSON_PRETTY_PRINT)."\n";
} finally {
    if(is_resource($process)){proc_terminate($process,9);proc_close($process);}
    foreach([$started,$finished] as $file)if(is_file($file))unlink($file);
    $pdo->exec('DELETE FROM naf_queue_jobs');
}
