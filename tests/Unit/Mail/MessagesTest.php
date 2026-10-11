<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit\Mail;

use Naf\Board\Mail\Messages;
use Naf\Core\Config;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use PHPUnit\Framework\TestCase;

use function Naf\app;

final class MessagesTest extends TestCase
{
    public function testViewsEscapeUserContentAndKeepLinksInThePlainTextAlternative(): void
    {
        $original = app()->container()->get(Config::class);
        app()->container()->set(Config::class, new Config([
            'app'  => ['name' => 'Nafinity & Team'],
            'mail' => ['from' => 'support@example.test', 'reply_to' => 'support@example.test'],
        ]));

        try {
            $message = Messages::create(new Mailer(new DummyTransport()), 'invitation', [
                'projectName' => '<script>alert("test")</script>',
                'url'         => 'https://board.example.test/invitation/token?first=1&second=2',
            ]);
            self::assertSame('support@example.test', $message->getFrom());
            self::assertSame('support@example.test', $message->getReplyTo());
            self::assertStringContainsString('Nafinity &amp; Team', $message->getContent());
            self::assertStringContainsString('&lt;script&gt;', $message->getContent());
            self::assertStringNotContainsString('<script>', $message->getContent());
            self::assertStringContainsString('first=1&amp;second=2', $message->getContent());
            self::assertStringContainsString('https://board.example.test/invitation/token?first=1&second=2', $message->getTextContent());
            self::assertStringNotContainsString('<table', $message->getTextContent());
        } finally {
            app()->container()->set(Config::class, $original);
        }
    }

    public function testTheHostCanReplaceMailViewsThroughTheNativeViewPaths(): void
    {
        $original  = app()->container()->get(Config::class);
        $directory = sys_get_temp_dir() . '/board-mail-view-' . bin2hex(random_bytes(8));
        mkdir($directory . '/mail', 0777, true);
        file_put_contents($directory . '/mail/case-confirmation.phtml', '<p>Our own confirmation</p>');
        app()->container()->set(Config::class, new Config([
            'view' => ['paths' => [$directory]], 'mail' => ['from' => 'support@example.test'],
        ]));

        try {
            $message = Messages::create(new Mailer(new DummyTransport()), 'case-confirmation');
            self::assertSame('<p>Our own confirmation</p>', $message->getContent());
            self::assertSame('Our own confirmation', $message->getTextContent());
        } finally {
            app()->container()->set(Config::class, $original);
            unlink($directory . '/mail/case-confirmation.phtml');
            rmdir($directory . '/mail');
            rmdir($directory);
        }
    }

    public function testUnsafeActionUrlsNeverBecomeClickable(): void
    {
        $message = Messages::create(new Mailer(new DummyTransport()), 'invitation', [
            'projectName' => 'Support', 'url' => 'javascript:alert(1)',
        ]);
        self::assertStringNotContainsString('href="javascript:', $message->getContent());
    }

    public function testConfirmationContainsThePublicReferenceInBothParts(): void
    {
        $reference = 'V-' . str_repeat('A', 24);
        $message   = Messages::create(new Mailer(new DummyTransport()), 'case-confirmation', ['reference' => $reference]);
        self::assertStringContainsString($reference, $message->getContent());
        self::assertStringContainsString('Vorgangsnummer: ' . $reference, $message->getTextContent());
        self::assertStringContainsString('dieselbe E-Mail-Adresse', $message->getTextContent());
    }
}
