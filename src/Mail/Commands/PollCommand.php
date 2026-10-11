<?php

declare(strict_types=1);

namespace Naf\Board\Mail\Commands;

use Naf\Board\Mail\Poller;
use Naf\Board\Support\Resolver;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;

use function Naf\app;

final class PollCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:mail:poll';

    protected function configure(): void
    {
        $this->setTitle('Import IMAP replies')
            ->setDescription('Append authorized replies to known cases; --rescan revisits retained messages.')
            ->addOption('rescan');
    }

    public function run(Input $input, Output $output): int
    {
        $counts = Resolver::service(app()->container(), Poller::class)->poll($input->getOption('rescan') === true);
        $output->writeLine(json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
