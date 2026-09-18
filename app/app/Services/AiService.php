<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\ProjectTool;
use App\Domain\Failure;
use App\Support\Input;
use Naf\MCP\Support\ToolRegistry;
use Naf\RateLimit\PdoLimiter;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AiServiceInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\CommentServiceInterface;
use Nafinity\Contracts\TicketServiceInterface;

final class AiService implements AiServiceInterface
{
    public function __construct(
        private AccessInterface $access,
        private BoardQueryInterface $query,
        private TicketServiceInterface $tickets,
        private CommentServiceInterface $comments,
        private PdoLimiter $limiter,
    ) {
    }

    public function definitions(?int $project): array
    {
        $registry    = $this->registry($project);
        $definitions = $registry->definitions();
        foreach ($definitions as &$definition) {
            $tool               = $registry->getTool($definition['name']);
            $definition['meta'] = [
                'title'    => $tool->title,
                'risk'     => $tool->permission === 'read' ? 'read' : 'write',
                'autoRun'  => $tool->permission === 'read',
                'requires' => $tool->requires,
                'keywords' => $tool->keywords,
            ];
        }

        return $definitions;
    }

    public function call(?int $project, array $data): mixed
    {
        $actor = $this->access->actor();
        if (!$this->limiter->consume('ai:tools:' . $actor, 60, 60)['allowed']) {
            throw new Failure('Zu viele AI-Aktionen. Bitte warte kurz.', 429);
        }
        $name      = Input::validate($data, ['name' => 'required|string|max:80'])['name'];
        $arguments = $data['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new Failure('Ungültige Werkzeugargumente.');
        }
        $registry = $this->registry($project);
        if (!in_array($name, array_column($registry->definitions(), 'name'), true)) {
            throw new Failure('Dieses Werkzeug ist für dich hier nicht verfügbar.', 403);
        }
        $tool = $registry->getTool($name);
        if ($tool->permission !== 'read' && ($data['confirmed'] ?? false) !== true) {
            throw new Failure('Bitte bestätige die Änderung zuerst im Chat.', 422);
        }

        return $registry->call($name, $arguments);
    }

    private function registry(?int $project): ToolRegistry
    {
        $this->access->actor();
        $scope    = $project === null ? null : $this->access->project($project);
        $registry = new ToolRegistry();
        $add      = static function (ProjectTool $tool) use ($registry, $scope) {
            if ($tool->permission === 'read' || $scope?->allows($tool->permission)) {
                $registry->register($tool);
            }
        };
        if ($project === null) {
            $projectFields = array_flip(['id', 'name', 'description', 'open_count']);
            $add(new ProjectTool(
                'nafinity_projects',
                'List the signed-in user\'s visible projects. No other projects are accessible.',
                ['properties' => []],
                fn() => array_map(
                    static fn($item) => array_intersect_key($item, $projectFields),
                    $this->query->projects(),
                ),
                'Meine Projekte',
                keywords: ['Projekte', 'Übersicht', 'Arbeitsbereiche'],
            ));

            return $registry;
        }
        $identifier = ['type' => 'integer', 'minimum' => 1];
        $text       = ['type' => 'string'];
        $add(new ProjectTool(
            'nafinity_board',
            'Read the current project, its board revision, columns, swimlanes, labels and up to 300 tickets. '
                . 'Use returned IDs and versions for later actions.',
            ['properties' => []],
            function () use ($project) {
                $board = $this->query->board($project);

                return [
                    'project'        => ['id' => $project, 'name' => $board['project']['name']],
                    'board_revision' => (int) $board['board']['revision'],
                    'columns'        => $board['columns'],
                    'swimlanes'      => $board['swimlanes'],
                    'labels'         => $board['labels'],
                    'tickets'        => $board['cards'],
                    'total'          => $board['total'],
                ];
            },
            'Board ansehen',
            keywords: ['Spalten', 'Swimlanes', 'Labels', 'Aufgaben', 'Übersicht', 'Tickets'],
        ));
        $add(new ProjectTool(
            'nafinity_ticket',
            'Read a ticket with comments, activity, labels and attachment metadata from this project. '
                . 'ticket_id is the database ID, not its displayed number.',
            ['properties' => ['ticket_id' => $identifier], 'required' => ['ticket_id']],
            fn($args) => $this->query->detail($project, Input::id($args['ticket_id'])),
            'Ticket lesen',
            keywords: ['Aufgabe', 'Beschreibung', 'Kommentare', 'Anhänge', 'Details'],
        ));
        $add(new ProjectTool(
            'nafinity_activity',
            'Read the recent activity of the current project.',
            ['properties' => []],
            fn() => $this->query->activity($project),
            'Aktivität lesen',
            keywords: ['Änderungen', 'Verlauf', 'Aktivitäten'],
        ));
        $ticketFields = [
            'title'          => $text,
            'description'    => $text,
            'priority'       => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
            'column_id'      => $identifier,
            'swimlane_id'    => $identifier,
            'board_revision' => $identifier,
        ];
        $add(new ProjectTool(
            'nafinity_ticket_create',
            'Create a ticket in the current project after user confirmation. '
                . 'Read the board first for valid column, swimlane and revision IDs.',
            ['properties' => $ticketFields, 'required' => array_keys($ticketFields)],
            function ($args) use ($project) {
                $id        = $this->tickets->create($project, $args);
                $reference = $this->tickets->reference($project, $id);

                return [
                    'id'  => $reference,
                    'url' => '/projects/' . $project . '/tickets/' . $reference,
                ];
            },
            'Ticket erstellen',
            'write',
            requires: ['nafinity_board'],
        ));
        $editFields = [
            'ticket_id'      => $identifier,
            'version'        => $identifier,
            'board_revision' => $identifier,
            'title'          => $text,
            'description'    => $text,
            'priority'       => $ticketFields['priority'],
        ];
        $add(new ProjectTool(
            'nafinity_ticket_update',
            'Update title, description and priority of a ticket. Read ticket and board first. '
                . 'Keep unchanged values. Concurrent changes return a conflict; read again and ask before retrying.',
            ['properties' => $editFields, 'required' => array_keys($editFields)],
            function ($args) use ($project) {
                $id = Input::id($args['ticket_id']);
                $this->access->project($project, 'write');
                $old = $this->tickets->ticket($project, $id);
                $this->tickets->update($project, $id, [...$old, ...$args]);

                return ['updated' => true, 'id' => $id];
            },
            'Ticket bearbeiten',
            'write',
            requires: ['nafinity_board', 'nafinity_ticket'],
        ));
        $moveFields = [
            'ticket_id'      => $identifier,
            'version'        => $identifier,
            'board_revision' => $identifier,
            'column_id'      => $identifier,
            'swimlane_id'    => $identifier,
        ];
        $add(new ProjectTool(
            'nafinity_ticket_move',
            'Move a ticket to the end of a column and swimlane after confirmation. '
                . 'Read the board and ticket to obtain IDs and current versions.',
            ['properties' => $moveFields, 'required' => array_keys($moveFields)],
            function ($args) use ($project) {
                $this->tickets->move($project, Input::id($args['ticket_id']), $args);

                return ['moved' => true];
            },
            'Ticket verschieben',
            'write',
            requires: ['nafinity_board', 'nafinity_ticket'],
        ));
        $add(new ProjectTool(
            'nafinity_comment',
            'Add a new comment to a ticket after user confirmation.',
            ['properties' => ['ticket_id' => $identifier, 'body' => $text], 'required' => ['ticket_id', 'body']],
            function ($args) use ($project) {
                $this->comments->save($project, Input::id($args['ticket_id']), $args);

                return ['commented' => true];
            },
            'Kommentar schreiben',
            'comment',
            requires: ['nafinity_board', 'nafinity_ticket'],
        ));

        return $registry;
    }
}
