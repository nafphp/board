<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Jobs\AccountSecurityNoticeJob;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use PHPUnit\Framework\TestCase;

use function Naf\I18n\t;

/**
 * The notice that an account's address has changed goes to the *old* address --
 * the one the person still reads if somebody else made the change. It is
 * therefore not a notification anyone can switch off. Its plain-text alternative
 * carries the notice even when the mail client does not render HTML.
 */
final class AccountSecurityNoticeJobTest extends TestCase
{
    public function testTheNoticeGoesToTheAddressThatWasReplaced(): void
    {
        $transport = new DummyTransport();

        (new AccountSecurityNoticeJob('old-address@example.test', 'email.changed', new Mailer($transport)))
            ->execute(new Output());

        $message = $transport->getMessages()[0];
        $this->assertSame(['old-address@example.test'], $message->getRecipients());
        $this->assertTrue($message->isHtml());
        $this->assertStringContainsString(t('Die E-Mail-Adresse deines Kontos bei :name wurde geändert. Alle bisherigen Anmeldungen wurden beendet.', ['name' => 'Nafinity']), $message->getTextContent());
        $this->assertStringNotContainsString('<table', $message->getTextContent());
    }
}
