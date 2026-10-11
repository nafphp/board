<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit\Mail;

use Naf\Board\Mail\ImapInbox;
use Naf\Board\Mail\IncomingMail;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

final class IncomingMailTest extends TestCase
{
    private const string REFERENCE = 'V-0123456789ABCDEF01234567';

    public function testOnlyOnePublicReferenceIsAccepted(): void
    {
        $known = self::REFERENCE;
        self::assertSame($known, $this->mail("Re: Vorgangsnummer: $known", "Vorgangsnummer: $known")->reference());
        self::assertSame($known, $this->mail('', "Ticket-Nr: $known")->reference());
        self::assertNull($this->mail('Ticket-Nr: 12345', 'ULX-15')->reference());
        self::assertNull($this->mail("Vorgangsnummer: {$known}A", '')->reference());
        self::assertNull($this->mail("Vorgangsnummer: $known", 'Vorgangsnummer: V-AAAAAAAAAAAAAAAAAAAAAAAA')->reference());
    }

    public function testMimeCharsetAndEncodedSubjectAreDecoded(): void
    {
        $raw = "From: Person <person@example.test>\r\nTo: support@example.test\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode('Re: Vorgangsnummer: ' . self::REFERENCE) . "?=\r\n"
            . "Message-ID: <reply@example.test>\r\nDate: Sat, 10 Oct 2026 12:00:00 +0000\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=iso-8859-1\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\nGr=FC=DFe und danke!";
        $mail = ImapInbox::decode(Message::fromString($raw), hash('sha256', 'uid:1'));

        self::assertSame(self::REFERENCE, $mail->reference());
        self::assertSame('person@example.test', $mail->sender());
        self::assertSame('Grüße und danke!', $mail->text);
        self::assertFalse($mail->automatic);
    }

    public function testHtmlOnlyMailBecomesTextAndAutomaticRepliesAreFlagged(): void
    {
        $raw = "From: person@example.test\r\nAuto-Submitted: auto-replied\r\n"
            . "Date: Sat, 10 Oct 2026 12:00:00 +0000\r\nSubject: Vorgangsnummer: " . self::REFERENCE
            . "\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
            . '<style>.x{color:red}</style><p>Danke &amp; Grüße</p><script>alert(1)</script><p>Neue Angaben</p>';
        $mail = ImapInbox::decode(Message::fromString($raw), hash('sha256', 'uid:2'));

        self::assertSame("Danke & Grüße\nNeue Angaben\n", $mail->text);
        self::assertTrue($mail->automatic);
    }

    public function testMultipleSendersAndAliasesDoNotPassSenderMatching(): void
    {
        self::assertNull((new IncomingMail('x', ['a@example.test', 'b@example.test'], '', ''))->sender());
        self::assertSame('Person@example.test', (new IncomingMail('x', ['Person@EXAMPLE.TEST'], '', ''))->sender());
        self::assertNotSame('person@example.test', (new IncomingMail('x', ['person+tag@example.test'], '', ''))->sender());
    }

    private function mail(string $subject, string $body): IncomingMail
    {
        return new IncomingMail('x', ['person@example.test'], $subject, $body);
    }
}
