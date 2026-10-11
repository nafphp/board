<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit\Mail;

use InvalidArgumentException;
use Naf\Board\Mail\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDisabledIntegrationNeedsNoCredentials(): void
    {
        self::assertFalse((new Settings(['enabled' => 'false']))->enabled);
    }

    public function testTlsDefaultsAndMailboxIdentityDoNotIncludeThePassword(): void
    {
        $values = [
            'enabled' => true, 'host' => 'imap.example.test', 'username' => 'support', 'password' => 'test',
            'actor'   => 'bot@example.test', 'from' => 'bot@example.test', 'reply_to' => 'support@example.test',
        ];
        $settings = new Settings($values);
        self::assertSame(993, $settings->port);
        self::assertSame('ssl', $settings->security);
        self::assertSame(143, (new Settings([...$values, 'security' => 'starttls']))->port);
        self::assertSame($settings->mailboxKey(), (new Settings([...$values, 'password' => 'rotated']))->mailboxKey());
        self::assertNotSame($settings->mailboxKey(), (new Settings([...$values, 'folder' => 'Replies']))->mailboxKey());
    }

    public function testImapInheritsTheSharedSenderLoginAndPasswordWithOptionalOverrides(): void
    {
        $mail = [
            'from' => 'support@example.test', 'password' => 'shared-secret',
            'imap' => ['enabled' => true, 'host' => 'imap.example.test', 'actor' => 'bot@example.test'],
        ];
        $settings = Settings::fromMailConfiguration($mail);
        self::assertSame('support@example.test', $settings->username);
        self::assertSame('shared-secret', $settings->password);
        self::assertSame('support@example.test', $settings->from);
        self::assertSame('support@example.test', $settings->replyTo);
        $mail['username'] = 'shared-login';
        $mail['imap'] += ['username' => 'imap-login', 'password' => 'imap-secret'];
        $override = Settings::fromMailConfiguration($mail);
        self::assertSame('imap-login', $override->username);
        self::assertSame('imap-secret', $override->password);
        self::assertSame('support@example.test', $override->from);
    }

    public function testUnencryptedImapIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Settings(['enabled' => true, 'host' => 'imap.test', 'username' => 'test', 'password' => 'test', 'security' => 'none']);
    }
}
