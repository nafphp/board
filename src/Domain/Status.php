<?php

declare(strict_types=1);

namespace Naf\Board\Domain;

/**
 * Whether a ticket is still being worked on.
 *
 * Closed and open are not a matter of taste the way priorities are: a column
 * marked as closing sets this, the board counts by it, and the schema has a
 * constraint on it. Two values, no registry.
 */
enum Status: string
{
    case OPEN   = 'open';
    case CLOSED = 'closed';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn(self $case) => $case->value, self::cases());
    }
}
