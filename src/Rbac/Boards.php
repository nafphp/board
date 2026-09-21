<?php

declare(strict_types=1);

namespace Naf\Board\Rbac;

use Naf\Rbac\Contracts\ScopeSourceInterface;
use PDO;

/**
 * The boards a role can be attached to.
 *
 * This is the whole of what naf/rbac needs to know about projects: a kind, a
 * heading and a list. Archived boards are left out -- a grant on something
 * nobody works in any more is a row somebody has to think about later.
 *
 * The connection arrives as a closure because this is built while plugins boot,
 * and asking the container for a database at that moment would tie the order
 * the plugins happen to boot in to whether roles can be offered at all. It is
 * called when a screen renders, by which time everything is up.
 *
 * @internal
 */
final readonly class Boards implements ScopeSourceInterface
{
    /** @param callable():PDO $connection */
    public function __construct(private mixed $connection)
    {
    }

    public function type(): string
    {
        return 'project';
    }

    public function label(): string
    {
        return 'Boards';
    }

    /** @return array<string, string> */
    public function instances(): array
    {
        $rows = ($this->connection)()
            ->query('SELECT id, name FROM projects WHERE archived_at IS NULL ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, 'name', 'id');
    }
}
