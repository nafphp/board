<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Imap;

use Naf\Board\Mail\ImapInbox;
use Naf\Board\Mail\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

final class ImapTest extends TestCase
{
    public static function connections(): array
    {
        return [['ssl', 3993], ['starttls', 3143]];
    }

    #[DataProvider('connections')]
    public function testRealTlsMailboxPeekUidCursorAndExplicitSeenFlag(string $security, int $port): void
    {
        $ca = __DIR__ . '/server/certs/cert.pem';
        self::assertFileExists($ca, 'Start the disposable IMAP service with docker compose -f tests/Imap/compose.yaml up -d --build --wait.');
        $client = (new ClientManager())->make([
            'host'        => '127.0.0.1', 'port' => $port, 'encryption' => $security, 'validate_cert' => true,
            'username'    => 'support', 'password' => 'test-mail-password', 'protocol' => 'imap',
            'ssl_options' => ['cafile' => $ca],
        ]);
        $folderName = 'Test-' . bin2hex(random_bytes(6));
        $inbox      = null;

        try {
            $client->connect();
            $client->openFolder('INBOX');
            $client->createFolder($folderName);
            $folder = $client->getFolderByPath($folderName);
            foreach ([[], ['\\Seen']] as $flags) {
                $folder->appendMessage(
                    "From: requester@example.test\r\nTo: support@example.test\r\n"
                    . "Date: Sat, 10 Oct 2026 12:00:00 +0000\r\n"
                    . "Subject: Vorgangsnummer: V-0123456789ABCDEF01234567\r\n"
                    . "Content-Type: text/plain; charset=utf-8\r\n\r\nWeitere Angaben.\r\n",
                    $flags,
                );
            }
            $folder->appendMessage(
                "From: requester@example.test\r\nDate: nonsense-date\r\n"
                . "Subject: Invalid date\r\nContent-Type: text/plain\r\n\r\nUnsupported.\r\n",
            );
            $folder->appendMessage(
                "From: requester@example.test\r\nDate: Sat, 10 Oct 2026 12:00:00 +0000\r\n"
                . "Subject: Later reply\r\nContent-Type: text/plain\r\n\r\nStill processed.\r\n",
            );
            $settings = new Settings([
                'enabled'  => true, 'host' => '127.0.0.1', 'port' => $port, 'username' => 'support', 'security' => $security,
                'password' => 'test-mail-password', 'folder' => $folderName, 'actor' => 'bot@example.test',
                'from'     => 'bot@example.test', 'reply_to' => 'support@example.test',
                'ca_file'  => $ca,
            ]);
            $inbox = new ImapInbox($settings);
            $inbox->open();
            self::assertGreaterThan(0, $inbox->uidValidity());
            $uids = $inbox->uidsAfter(0);
            self::assertCount(4, $uids);
            self::assertSame([], $inbox->uidsAfter(max($uids)));
            $first = $inbox->read($uids[0]);
            self::assertNotNull($first);
            self::assertSame('V-0123456789ABCDEF01234567', $first->reference());
            self::assertSame('requester@example.test', $first->sender());
            self::assertStringContainsString('Weitere Angaben.', $first->text);
            $inbox->read($uids[1]);
            self::assertNull($inbox->read($uids[2]), 'Malformed mail must not block later UIDs.');
            self::assertStringContainsString('Still processed.', $inbox->read($uids[3])->text);
            self::assertSame([$uids[3]], $inbox->uidsAfter($uids[2]));

            $client->openFolder($folderName);
            $flags = $client->getConnection()->flags($uids, IMAP::ST_UID)->validatedData();
            self::assertNotContains('\\Seen', $flags[$uids[0]]);
            self::assertContains('\\Seen', $flags[$uids[1]]);
            self::assertNotContains('\\Seen', $flags[$uids[2]]);
            self::assertNotContains('\\Seen', $flags[$uids[3]]);
            $inbox->markSeen($uids[0]);
            $flags = $client->getConnection()->flags($uids, IMAP::ST_UID)->validatedData();
            self::assertContains('\\Seen', $flags[$uids[0]]);
        } finally {
            $inbox?->close();
            $client->disconnect();
        }
    }
}
