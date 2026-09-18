<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * The authorized way for application and plugin code to read ticket metadata.
 *
 * Membership, ticket ownership and the field's read permission are checked on
 * every call. An unknown key returns the caller's default; a known but
 * forbidden key is a 403.
 */
interface TicketMetadataReaderInterface
{
    /**
     * @param int    $projectId The project the ticket belongs to
     * @param int    $ticketId  The ticket
     * @param string $key       Field key
     * @param mixed  $default   Returned for an unknown key only
     */
    public function get(int $projectId, int $ticketId, string $key, mixed $default = null): mixed;

    /**
     * @param int $projectId The project the ticket belongs to
     * @param int $ticketId  The ticket
     *
     * @return array<string, mixed> Readable active definitions only
     */
    public function all(int $projectId, int $ticketId): array;
}
