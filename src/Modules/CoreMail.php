<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\TicketFieldDefinition;
use Naf\Board\Domain\Change;
use Naf\Board\ExtensionContext;
use Naf\Board\Mail\CaseListener;
use Naf\Board\Mail\Commands\CheckCommand;
use Naf\Board\Mail\Commands\PollCommand;
use Naf\Board\Mail\Commands\SmtpCheckCommand;
use Naf\Board\Mail\EmailType;
use Naf\Board\Mail\ImapInbox;
use Naf\Board\Mail\Inbox;
use Naf\Board\Mail\Jobs\PollJob;
use Naf\Board\Mail\Settings;
use Naf\Board\Support\Resolver;
use Naf\CLI\Support\CommandRegistry;
use Naf\Schedule\Core\JobRepository;

use function Naf\config;
use function Naf\event;

final class CoreMail implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $container = $context->container();
        $container->set(Settings::class, static fn() => Settings::fromMailConfiguration((array) config('mail', []), (array) config('board_mail', [])));
        $container->set(Inbox::class, static fn() => new ImapInbox($container->get(Settings::class)));
        $container->get(CommandRegistry::class)->add(CheckCommand::class);
        $container->get(CommandRegistry::class)->add(PollCommand::class);
        $container->get(CommandRegistry::class)->add(SmtpCheckCommand::class);
        $context->fieldTypes()->add(new EmailType());
        $context->ticketFields()->add(new TicketFieldDefinition(
            key: 'board_mail.requester',
            label: 'Anfragende E-Mail-Adresse',
            type: 'board_mail.email',
            index: 800,
            options: ['max' => 254],
        ));
        $context->ticketFields()->add(new TicketFieldDefinition(
            key: 'board_mail.reference',
            label: 'Vorgangsnummer',
            type: 'text',
            group: 'information',
            index: 800,
            readOnly: true,
            showOnCreate: false,
        ));
        if ($container->get(Settings::class)->enabled) {
            $container->get(JobRepository::class)->add(PollJob::class, []);
        }
        event()->listen(Change::class, static fn(Change $change) => Resolver::service($container, CaseListener::class)->record($change));
    }
}
