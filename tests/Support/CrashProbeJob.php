<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;

/**
 * A job that hangs the first time it runs and completes the second.
 *
 * The first worker is killed while it sits in the sleep, which is how a real
 * crash looks from the outside: the process is gone without the job having been
 * acknowledged or released. The marker file is what makes it deterministic --
 * the test waits for the job to have actually started before pulling the plug,
 * rather than guessing how long that takes.
 */
final class CrashProbeJob implements QueueJobInterface
{
    public const STARTED  = '/storage/queue-crash.started';
    public const FINISHED = '/storage/queue-crash.finished';

    public function execute(Output $output): void
    {
        $started = BASE_PATH . self::STARTED;
        if (!is_file($started)) {
            file_put_contents($started, (string) getmypid());
            sleep(30);
        }
        // Appended, so a replacement that ran the job twice would say so.
        file_put_contents(BASE_PATH . self::FINISHED, "completed\n", FILE_APPEND);
    }
}
