<?php

declare(strict_types=1);

namespace Naf\Board\Mail\Jobs;

use Naf\Board\Mail\Poller;
use Naf\CLI\Core\Output;
use Naf\Schedule\Core\ScheduledJobInterface;

final class PollJob implements ScheduledJobInterface
{
    public function __construct(private Poller $poller)
    {
    }

    public function getCronExpression(): string
    {
        return '* * * * *';
    }

    public function execute(Output $output): void
    {
        $counts = $this->poller->poll();
        if (array_sum($counts) > 0) {
            $output->writeLine('Mail-to-Ticket: ' . json_encode($counts, JSON_THROW_ON_ERROR));
        }
    }
}
