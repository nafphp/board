<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use InvalidArgumentException;

final readonly class Settings
{
    public bool $enabled;
    public string $host;
    public int $port;
    public string $security;
    public string $username;
    public string $password;
    public string $folder;
    public string $actor;
    public string $from;
    public string $replyTo;
    public ?string $authentication;
    public int $batchSize;
    public int $maxBytes;
    public ?string $caFile;

    public function __construct(array $values = [])
    {
        $this->enabled        = filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $this->host           = trim((string) ($values['host'] ?? ''));
        $this->security       = (string) ($values['security'] ?? 'ssl');
        $this->port           = ($values['port'] ?? '') === '' ? ($this->security === 'starttls' ? 143 : 993) : (int) $values['port'];
        $this->username       = (string) ($values['username'] ?? '');
        $this->password       = (string) ($values['password'] ?? '');
        $this->folder         = (string) ($values['folder'] ?? 'INBOX');
        $this->actor          = EmailType::address($values['actor'] ?? null) ?? '';
        $this->from           = EmailType::address($values['from'] ?? null) ?? '';
        $this->replyTo        = EmailType::address($values['reply_to'] ?? null) ?? '';
        $this->authentication = ($values['authentication'] ?? '') === 'oauth' ? 'oauth' : null;
        $this->batchSize      = (int) ($values['batch_size'] ?? 50);
        $this->maxBytes       = (int) ($values['max_bytes'] ?? 1048576);
        $this->caFile         = ($values['ca_file'] ?? '') === '' ? null : (string) $values['ca_file'];

        if (!$this->enabled) {
            return;
        }

        if ($this->host === '' || $this->username === '' || $this->password === '' || $this->folder === '') {
            throw new InvalidArgumentException('Mail-to-Ticket needs IMAP host, user, password/token and folder.');
        }
        if (!in_array($this->security, ['ssl', 'starttls'], true) || $this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('IMAP requires ssl (normally 993) or starttls (normally 143).');
        }
        if ($this->actor === '' || $this->from === '' || $this->replyTo === '') {
            throw new InvalidArgumentException('Mail-to-Ticket needs valid actor, from and reply_to addresses.');
        }
        if (!in_array($values['authentication'] ?? '', ['', 'password', 'oauth'], true)) {
            throw new InvalidArgumentException('IMAP authentication must be password or oauth.');
        }
        if ($this->batchSize < 1 || $this->batchSize > 200 || $this->maxBytes < 1024 || $this->maxBytes > 10485760) {
            throw new InvalidArgumentException('IMAP batch_size must be 1..200 and max_bytes 1024..10485760.');
        }
        if ($this->caFile !== null && !is_readable($this->caFile)) {
            throw new InvalidArgumentException('The configured IMAP CA file is not readable.');
        }
    }

    public static function fromMailConfiguration(array $mail, array $legacy = []): self
    {
        $values             = (array) ($mail['imap'] ?? $legacy);
        $values['username'] = ($values['username'] ?? '') ?: (($mail['username'] ?? '') ?: ($mail['from'] ?? ''));
        $values['password'] = ($values['password'] ?? '') ?: ($mail['password'] ?? '');
        $values['from']     = ($mail['from'] ?? '') ?: ($values['from'] ?? '');
        $values['reply_to'] = ($mail['reply_to'] ?? '') ?: (($values['reply_to'] ?? '') ?: $values['from']);

        return new self($values);
    }

    public function mailboxKey(): string
    {
        return hash('sha256', json_encode([$this->host, $this->port, $this->username, $this->folder], JSON_THROW_ON_ERROR));
    }
}
