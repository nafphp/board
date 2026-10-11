<?php

declare(strict_types=1);

namespace Naf\Board\Mail\Commands;

use Naf\Board\Support\Resolver;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Transport\SmtpTransport;

use function Naf\app;
use function Naf\config;

final class SmtpCheckCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:mail:smtp:check';

    protected function configure(): void
    {
        $this->setTitle('Check SMTP')
            ->setDescription('Verify the configured naf/mail SMTP TLS connection and login without sending mail.');
    }

    public function run(Input $input, Output $output): int
    {
        if (config('mail:transport') !== SmtpTransport::class) {
            $output->writeLine('This installation uses a custom mail transport; SMTP checking is unavailable.', 'error');

            return self::ERROR;
        }
        Resolver::service(app()->container(), SmtpTransport::class)->checkConnection();
        $output->writeLine('SMTP connection, TLS policy and configured authentication: OK. No mail was sent.', 'success');

        return self::SUCCESS;
    }
}
