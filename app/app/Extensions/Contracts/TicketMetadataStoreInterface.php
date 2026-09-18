<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * Where contributed ticket metadata is persisted.
 *
 * Every query is bound to a project. The store runs inside the domain
 * transaction that is already open; it never starts or commits one, and it is
 * an internal boundary, not a shortcut past authorization.
 */
interface TicketMetadataStoreInterface
{
    /**
     * @param int       $projectId The owning project
     * @param list<int> $ticketIds Tickets to read, in one query
     *
     * @return array<int, array<string, mixed>> Ticket id to stored values
     */
    public function read(int $projectId, array $ticketIds): array;

    /**
     * @param int                  $projectId The owning project
     * @param int                  $ticketId  The ticket being written
     * @param array<string, mixed> $values    Values to store
     * @param list<string>         $resetKeys Keys whose stored value is removed
     */
    public function write(
        int $projectId,
        int $ticketId,
        array $values,
        array $resetKeys = [],
    ): void;
}
