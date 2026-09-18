<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * Ticket comments, including edits and moderation.
 */
interface CommentServiceInterface
{
    public function save(int $project, int $ticket, array $data): void;
}
