<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Change;
use App\Domain\Failure;
use App\Domain\ProjectScope;
use App\Support\Input;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\CommentServiceInterface;
use Nafinity\Contracts\TicketServiceInterface;
use PDO;

use function Naf\event;

final class CommentService implements CommentServiceInterface
{
    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private TicketServiceInterface $tickets,
    ) {
    }

    public function save(int $project, int $ticket, array $data): void
    {
        $remove = ($data['action'] ?? 'save') === 'delete';
        $id     = empty($data['id']) ? null : Input::id($data['id']);
        $body   = $remove
            ? ''
            : trim(Input::validate($data, ['body' => 'required|string|max:20000'])['body']);
        if (!$remove && $body === '') {
            throw new Failure('Ein Kommentartext wird benötigt.');
        }
        $this->access->write($project, 'comment', function (ProjectScope $scope) use (
            $project,
            $ticket,
            $data,
            $id,
            $body,
            $remove,
        ) {
            $this->tickets->ticket($project, $ticket);
            $actor = $this->access->actor();
            $now   = gmdate('Y-m-d H:i:s');
            if ($id) {
                $statement = $this->pdo->prepare(
                    'SELECT * FROM comments WHERE project_id=? AND ticket_id=? AND id=? AND deleted_at IS NULL',
                );
                $statement->execute([$project, $ticket, $id]);
                $old = $statement->fetch();
                if (!$old) {
                    throw new Failure('Kommentar nicht gefunden.', 404);
                }
                if (
                    (int) $old['author_id'] !== $actor
                    && !$scope->allows('moderate')
                ) {
                    throw new Failure('Du darfst diesen Kommentar nicht ändern.', 403);
                }
                if (Input::id($data['version'] ?? null) !== (int) $old['version']) {
                    throw new Failure('Der Kommentar wurde inzwischen geändert.', 409);
                }
                $this->pdo
                    ->prepare(
                        'UPDATE comments SET body=?,updated_at=?,deleted_at=?,version=version+1 WHERE project_id=? AND id=?',
                    )
                    ->execute([
                        $remove ? $old['body'] : $body,
                        $now,
                        $remove ? $now : null,
                        $project,
                        $id,
                    ]);
            } else {
                if ($remove) {
                    throw new Failure('Kommentar fehlt.');
                }
                $parent = ($data['parent_id'] ?? '') === '' ? null : Input::id($data['parent_id'], 'parent_id');
                if ($parent !== null) {
                    $statement = $this->pdo->prepare('SELECT id FROM comments WHERE project_id=? AND ticket_id=? AND id=? AND deleted_at IS NULL');
                    $statement->execute([$project, $ticket, $parent]);
                    if (!$statement->fetchColumn()) {
                        throw new Failure('Der beantwortete Kommentar ist nicht mehr verfügbar.', 422);
                    }
                }
                $this->pdo
                    ->prepare(
                        'INSERT INTO comments(project_id,ticket_id,author_id,body,created_at,updated_at,parent_id) VALUES(?,?,?,?,?,?,?)',
                    )
                    ->execute([$project, $ticket, $actor, $body, $now, $now, $parent]);
            }
            $this->pdo
                ->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')
                ->execute([$project]);
            event()->dispatch(
                'nafinity.changed',
                new Change(
                    $project,
                    $ticket,
                    $actor,
                    $remove ? 'comment.deleted' : ($id ? 'comment.updated' : 'comment.created'),
                ),
            );
        });
    }
}
