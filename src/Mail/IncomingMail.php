<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

final readonly class IncomingMail
{
    public function __construct(
        public string $receiptKey,
        public array $senders,
        public string $subject,
        public string $text,
        public string $messageId = '',
        public bool $automatic = false,
        public bool $hasAttachments = false,
    ) {
    }

    public function reference(): ?string
    {
        preg_match_all(
            '/\b(?:Vorgangsnummer|Ticket-Nr)\s*:\s*(V-[A-F0-9]{24})(?![A-Z0-9-])/iu',
            $this->subject . "\n" . $this->text,
            $matches,
        );
        $references = array_unique(array_map('strtoupper', $matches[1]));

        return count($references) === 1 ? reset($references) : null;
    }

    public function sender(): ?string
    {
        return count($this->senders) === 1 ? EmailType::address($this->senders[0]) : null;
    }

    public function fingerprint(): ?string
    {
        if ($this->messageId === '') {
            return null;
        }

        // A reused Message-ID with a different payload is a different mail.
        return hash('sha256', json_encode([
            $this->messageId, $this->sender(), $this->reference(), $this->subject, $this->text,
        ], JSON_THROW_ON_ERROR));
    }

    public function comment(): string
    {
        $attachmentNote = $this->hasAttachments ? "\n\n[Anhänge verbleiben im Mailpostfach.]" : '';

        return 'E-Mail von ' . $this->sender() . "\n\n" . trim($this->text) . $attachmentNote;
    }
}
