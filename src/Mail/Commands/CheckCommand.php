<?php

declare(strict_types=1);

namespace Naf\Board\Mail\Commands;

use Naf\Board\Mail\Inbox;
use Naf\Board\Mail\Settings;
use Naf\Board\Support\Resolver;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use PDO;

use function Naf\app;

final class CheckCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:mail:check';

    protected function configure(): void
    {
        $this->setTitle('Check Mail-to-Ticket')
            ->setDescription('Check IMAP TLS/login/folder and the integration account without importing or sending mail.');
    }

    public function run(Input $input, Output $output): int
    {
        $container = app()->container();
        $settings  = Resolver::service($container, Settings::class);
        if (!$settings->enabled) {
            $output->writeLine('Mail-to-Ticket is disabled.');

            return self::SUCCESS;
        }

        $query = $container->get(PDO::class)->prepare('SELECT id FROM users WHERE email=? AND active=1');
        $query->execute([$settings->actor]);
        if (!$query->fetchColumn()) {
            $output->writeLine('The integration account is missing or inactive.', 'error');

            return self::ERROR;
        }

        $inbox = $container->get(Inbox::class);

        try {
            $inbox->open();
            $output->writeLine('IMAP TLS, login, folder and integration account: OK. Grant the account comment access in each participating project.', 'success');
        } finally {
            $inbox->close();
        }

        return self::SUCCESS;
    }
}
