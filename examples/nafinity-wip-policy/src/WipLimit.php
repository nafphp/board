<?php

declare(strict_types=1);

namespace Example\WipPolicy;

use LogicException;
use Naf\Board\Contracts\SettingsServiceInterface;
use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\SettingsContext;
use PDO;

use function Naf\I18n\t;

/** An admission rule; ordinary edits and departures remain possible on an overfull column. */
final class WipLimit
{
    public function __construct(private PDO $pdo, private SettingsServiceInterface $settings)
    {
    }

    public function check(Change $change): void
    {
        if ($change->projectId === null || $change->ticketId === null
            || !in_array($change->type, ['ticket.created', 'ticket.moved', 'ticket.transferred', 'ticket.reopen', 'ticket.restore'], true)) {
            return;
        }
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('WIP rules require the native project write transaction.');
        }
        if ($change->type === 'ticket.moved'
            && (string) ($change->data['from'] ?? '') === (string) ($change->data['to'] ?? '')
            && ($change->data['from_status'] ?? '') === 'open') {
            return;
        }
        if (!$this->settings->get(SettingsContext::project($change->projectId), WipPolicyProvider::KEY, true)) {
            return;
        }
        // The domain has already written the candidate, while still holding its project lock.
        // Count all lanes and all cards, independently of board filters and the display limit.
        $column = $this->pdo->prepare(
            'SELECT c.id,c.name,c.wip_limit FROM tickets t'
            . ' JOIN board_columns c ON c.project_id=t.project_id AND c.id=t.column_id'
            . " WHERE t.project_id=? AND t.id=? AND t.status='open' AND t.archived_at IS NULL",
        );
        $column->execute([$change->projectId, $change->ticketId]);
        $target = $column->fetch();
        if (!$target || (int) $target['wip_limit'] <= 0) {
            return;
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM tickets WHERE project_id=? AND column_id=? AND status='open' AND archived_at IS NULL");
        $count->execute([$change->projectId, $target['id']]);
        if ((int) $count->fetchColumn() > (int) $target['wip_limit']) {
            throw new Failure(t('Die WIP-Grenze von :limit in „:column“ ist erreicht. Schließe oder verschiebe zuerst ein anderes Ticket.', [
                'limit' => $target['wip_limit'], 'column' => $target['name'],
            ]), 409);
        }
    }
}
