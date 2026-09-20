<?php

declare(strict_types=1);

/**
 * One turn of the queue worker, as its own process.
 *
 * The crash test needs a worker it can really kill, and a process is the only
 * honest way to test surviving the loss of one. Retries are switched off so a
 * job that fails is set aside immediately instead of being handed back.
 */

use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\Core\Config;
use Naf\Queue\Commands\QueueConsumeCommand;

require __DIR__ . '/../bootstrap.php';

$container                         = Naf\app()->container();
$settings                          = $container->get(Config::class)->all();
$settings['queue']['max_attempts'] = 1;
$settings['queue']['retry_delay']  = 0;
$container->set(Config::class, new Config($settings));

$command = $container->make(QueueConsumeCommand::class);

exit($command->run(new Input(['--once'], $command->getDefinition()), new Output()));
