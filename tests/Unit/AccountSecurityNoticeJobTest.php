<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Jobs\AccountSecurityNoticeJob;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use PHPUnit\Framework\TestCase;

/**
 * The notice that an account's address has changed goes to the *old* address --
 * the one the person still reads if somebody else made the change. It is
 * therefore not a notification anyone can switch off, and it is plain text: a
 * security notice should not depend on a mail client rendering HTML.
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
        $this->assertFalse($message->isHtml(), 'a security notice was sent as HTML');
    }
}
