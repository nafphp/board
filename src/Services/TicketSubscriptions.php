<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Fields\BooleanType;
use PDO;

use function Naf\I18n\t;

/** Personal, idempotent subscription changes do not edit the ticket. @internal */
final class TicketSubscriptions
{
    public function __construct(private PDO $pdo, private AccessInterface $access, private TicketServiceInterface $tickets)
    {
    }

    public function state(int $project, int $ticket): array
    {
        $this->access->project($project);
        $this->tickets->ticket($project, $ticket);
        $actor  = $this->access->actor();
        $member = $this->pdo->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=? AND active=1');
        $member->execute([$project, $actor]);
        $subscribed = $this->pdo->prepare('SELECT 1 FROM ticket_subscriptions WHERE project_id=? AND ticket_id=? AND user_id=?');
        $subscribed->execute([$project, $ticket, $actor]);

        return ['available' => (bool) $member->fetchColumn(), 'subscribed' => (bool) $subscribed->fetchColumn()];
    }

    public function save(int $project, int $ticket, mixed $value): void
    {
        $boolean = new BooleanType();
        if ($boolean->validate($value, []) !== []) {
            throw new Failure(t('Bitte wähle Ja oder Nein.'));
        }
        $this->access->write($project, 'read', function () use ($project, $ticket, $value, $boolean): void {
            if (!$this->state($project, $ticket)['available']) {
                throw new Failure(t('Nur aktive Projektmitglieder können Tickets folgen.'), 403);
            }
            $actor = $this->access->actor();
            if ($boolean->normalize($value, [])) {
                $this->pdo->prepare('INSERT INTO ticket_subscriptions(project_id,ticket_id,user_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)')
                    ->execute([$project, $ticket, $actor]);
            } else {
                $this->pdo->prepare('DELETE FROM ticket_subscriptions WHERE project_id=? AND ticket_id=? AND user_id=?')
                    ->execute([$project, $ticket, $actor]);
            }
        });
    }
}
