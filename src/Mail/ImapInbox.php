<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use RuntimeException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

final class ImapInbox implements Inbox
{
    private ?Client $client = null;
    private ?Folder $folder = null;
    private int $validity   = 0;

    public function __construct(private Settings $settings)
    {
    }

    public function open(): void
    {
        $manager = new ClientManager(['options' => [
            'fetch'       => IMAP::FT_PEEK,
            'sequence'    => IMAP::ST_UID,
            'fetch_body'  => false,
            'fetch_flags' => false,
            'soft_fail'   => false,
            'debug'       => false,
            'rfc822'      => false,
        ]]);
        $this->client = $manager->make([
            'host'           => $this->settings->host,
            'port'           => $this->settings->port,
            'encryption'     => $this->settings->security,
            'validate_cert'  => true,
            'username'       => $this->settings->username,
            'password'       => $this->settings->password,
            'protocol'       => 'imap',
            'authentication' => $this->settings->authentication,
            'timeout'        => 15,
            'ssl_options'    => $this->settings->caFile === null ? [] : ['cafile' => $this->settings->caFile],
        ]);
        $this->client->connect();
        $this->folder = $this->client->getFolderByPath($this->settings->folder);
        if ($this->folder === null) {
            throw new RuntimeException('The configured IMAP folder was not found.');
        }

        $status         = $this->folder->status();
        $this->validity = (int) ($status['uidvalidity'] ?? $status['UIDVALIDITY'] ?? 0);
        if ($this->validity < 1) {
            throw new RuntimeException('The IMAP server did not supply UIDVALIDITY.');
        }
    }

    public function uidValidity(): int
    {
        return $this->validity;
    }

    public function uidsAfter(int $uid): array
    {
        $this->client->openFolder($this->folder->path);
        $uids = $this->client->getConnection()->search(['UID ' . ($uid + 1) . ':* UNDELETED'], IMAP::ST_UID)->validatedData();
        // IMAP ranges can include the last message when the lower bound is beyond *.
        $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $value) => $value > $uid));
        sort($uids, SORT_NUMERIC);

        return array_slice($uids, 0, $this->settings->batchSize);
    }

    public function read(int $uid): ?IncomingMail
    {
        $this->client->openFolder($this->folder->path);
        $connection = $this->client->getConnection();
        $sizes      = $connection->sizes([$uid], IMAP::ST_UID)->validatedData();
        if (!isset($sizes[$uid])) {
            return null; // Expunged since SEARCH.
        }
        if ((int) $sizes[$uid] > $this->settings->maxBytes) {
            return null;
        }

        // A real PEEK leaves flags untouched even if parsing or the worker fails.
        // Request UID as a second item because the server returns BODY[], not
        // BODY.PEEK[], and the client's single-item helper expects an exact name.
        $data = $connection->fetch(['UID', 'BODY.PEEK[]'], [$uid], null, IMAP::ST_UID)->validatedData();
        $raw  = $data[$uid]['BODY[]'] ?? null;
        if (!is_string($raw) || strlen($raw) > $this->settings->maxBytes) {
            return null;
        }
        $message = Message::fromString($raw, $this->client->getConfig());

        return self::decode($message, hash('sha256', $this->settings->mailboxKey() . ':' . $this->validity . ':' . $uid));
    }

    /** Public for raw-message ingestion and MIME regression checks. */
    public static function decode(Message $message, string $receiptKey): IncomingMail
    {
        $header  = $message->getHeader();
        $senders = [];
        foreach ($header->get('from')->all() as $address) {
            $senders[] = $address->mail ?? '';
        }

        $text = $message->getTextBody();
        if (!is_string($text) || trim($text) === '') {
            $html = (string) $message->getHTMLBody();
            $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
            $html = preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|li|tr|h[1-6])>/i', "\n", $html);
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $auto       = strtolower(trim((string) $header->get('auto_submitted')));
        $precedence = strtolower(trim((string) $header->get('precedence')));
        $automatic  = ($auto !== '' && $auto !== 'no')
            || in_array($precedence, ['bulk', 'list', 'junk'], true)
            || str_contains(strtolower((string) $header->get('content_type')), 'multipart/report')
            || trim((string) $header->get('return_path')) === '<>';

        return new IncomingMail(
            $receiptKey,
            $senders,
            iconv_mime_decode((string) $header->get('subject'), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: (string) $header->get('subject'),
            str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text),
            (string) $header->get('message_id'),
            $automatic,
            $message->getAttachments()->count() > 0,
        );
    }

    public function markSeen(int $uid): void
    {
        $this->client->openFolder($this->folder->path);
        $this->client->getConnection()->store(['\\Seen'], $uid, $uid, '+', true, IMAP::ST_UID)->validate();
    }

    public function close(): void
    {
        $this->client?->disconnect();
        $this->client = null;
        $this->folder = null;
    }
}
