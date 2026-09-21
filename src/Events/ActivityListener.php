<?php

declare(strict_types=1);

namespace Naf\Board\Events;

use LogicException;
use Naf\Board\Contracts\NotificationServiceInterface;
use Naf\Board\Domain\Change;
use PDO;

/** @internal */
final class ActivityListener
{
    /**
     * How long an edit stays open to being folded into.
     *
     * The ticket editor saves by itself while somebody types, so one edit of one
     * description arrives here four or five times. Keeping each is not a fuller
     * record, it is a record nobody reads -- and a log nobody reads is worth
     * less than a shorter one that is true.
     */
    private const int FOLD_WINDOW = 300;

    /** Only the kind that arrives in bursts. Everything else is kept as it came. */
    private const array FOLDABLE = ['ticket.updated'];

    public function __construct(
        private PDO $pdo,
        private NotificationServiceInterface $notifications,
    ) {
    }

    public function record(Change $change): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Activity requires the domain transaction.');
        }

        // Folded into the entry it continues: the same person, still editing the
        // same ticket, moments later. No second entry and no second notification,
        // because nothing new has happened to tell anybody about.
        if ($this->fold($change)) {
            return;
        }

        $sql = 'INSERT INTO activities(scope,project_id,ticket_id,actor_id,event_type,payload,created_at)'
            . ' VALUES(?,?,?,?,?,?,?)';
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $sql .= ' RETURNING id';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            $change->scope,
            $change->projectId,
            $change->ticketId,
            $change->actorId,
            $change->type,
            json_encode($change->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                ? $statement->fetchColumn()
                : $this->pdo->lastInsertId());
        $this->notifications->record($change, $id);
    }

    /**
     * Whether this change continues the last one instead of being its own.
     *
     * It has to be the newest entry for that ticket, not merely a recent one:
     * if anybody else touched it in between, folding would quietly reorder the
     * history to make two things look like one.
     */
    private function fold(Change $change): bool
    {
        if (!in_array($change->type, self::FOLDABLE, true) || $change->ticketId === null) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, actor_id, event_type, payload, created_at FROM activities'
            . ' WHERE ticket_id = ? AND scope = ? ORDER BY id DESC LIMIT 1',
        );
        $statement->execute([$change->ticketId, $change->scope]);
        $last = $statement->fetch();

        if (
            !$last
            || ($last['actor_id'] === null ? null : (int) $last['actor_id']) !== $change->actorId
            || (string) $last['event_type'] !== $change->type
            || strtotime((string) $last['created_at'] . ' UTC') < time() - self::FOLD_WINDOW
        ) {
            return false;
        }

        $this->pdo
            ->prepare('UPDATE activities SET payload=?, created_at=? WHERE id=?')
            ->execute([
                json_encode(
                    $this->merge(json_decode((string) $last['payload'], true), $change->data),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
                gmdate('Y-m-d H:i:s'),
                (int) $last['id'],
            ]);

        return true;
    }

    /**
     * One entry for the whole burst, naming everything it touched.
     *
     * Every list in the payload is unioned. A person who changed the title, then
     * the priority, then the title again has changed a title and a priority --
     * saying so twice describes the autosave rather than the work.
     *
     * @param array<string,mixed>|null $kept
     * @param array<string,mixed>      $arriving
     *
     * @return array<string,mixed>
     */
    private function merge(?array $kept, array $arriving): array
    {
        $merged = is_array($kept) ? $kept : [];

        foreach ($arriving as $key => $value) {
            if (is_array($value) && is_array($merged[$key] ?? null)) {
                $merged[$key] = array_values(array_unique([...$merged[$key], ...$value]));
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }
}
