<?php

declare(strict_types=1);

namespace Naf\Board\Repositories;

use Naf\Board\Models\Ticket;
use Naf\ORM\Repository\AbstractRepository;

final class TicketRepository extends AbstractRepository
{
    protected function getEntityClass(): string
    {
        return Ticket::class;
    }
}
