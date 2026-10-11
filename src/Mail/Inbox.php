<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

interface Inbox
{
    public function open(): void;

    public function uidValidity(): int;

    /** @return list<int> The next bounded batch, in ascending UID order. */
    public function uidsAfter(int $uid): array;

    /** Null means oversized or unsupported; the message remains unchanged. */
    public function read(int $uid): ?IncomingMail;

    public function markSeen(int $uid): void;

    public function close(): void;
}
