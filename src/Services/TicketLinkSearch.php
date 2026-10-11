<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Format;
use PDO;

use function Naf\I18n\t;

/** Bounded, project-authorized candidates for a ticket relationship. @internal */
final class TicketLinkSearch
{
    public function __construct(private PDO $pdo, private AccessInterface $access, private TicketServiceInterface $tickets)
    {
    }

    public function find(int $project, int $ticket, mixed $query): array
    {
        $scope = $this->access->project($project);
        $this->tickets->ticket($project, $ticket);
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 120) {
            throw new Failure(t('Bitte gib einen Suchbegriff mit höchstens 120 Zeichen ein.'), 422);
        }
        $query     = trim($query);
        $pattern   = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $statement = $this->pdo->prepare(
            "SELECT t.number,t.title FROM tickets t
             WHERE t.project_id=? AND t.id<>? AND t.archived_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM ticket_links l WHERE l.project_id=t.project_id
                   AND ((l.ticket_id=? AND l.related_id=t.id) OR (l.related_id=? AND l.ticket_id=t.id)))
               AND (t.title LIKE ? ESCAPE '!' OR CONCAT(?,'-',t.number) LIKE ? ESCAPE '!')
             ORDER BY CASE WHEN CAST(t.number AS CHAR)=? OR CONCAT(?,'-',t.number)=? THEN 0 ELSE 1 END,
                      t.updated_at DESC,t.id DESC LIMIT 12",
        );
        $key = $scope->project['ticket_key'];
        $statement->execute([$project, $ticket, $ticket, $ticket, $pattern, $key, $pattern, $query, $key, $query]);

        return array_map(static fn(array $row): array => [
            'value' => (string) $row['number'],
            'label' => Format::ticket($key, $row['number']) . ' · ' . $row['title'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
