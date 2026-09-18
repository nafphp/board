<?php

declare(strict_types=1);

namespace App\Ai;

use App\Support\Input;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AiToolProviderInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\CommentServiceInterface;
use Nafinity\Contracts\TicketServiceInterface;
use Nafinity\Support\AiToolContext;

/**
 * The tools Nafinity itself offers the local chat.
 *
 * This used to be a fixed list inside AiService. As a provider it is one source
 * among others: a plugin adds its own tools beside these, and replacing one of
 * these takes an explicit replacement in the provider definition rather than
 * happening to be registered last.
 */
final class CoreToolProvider implements AiToolProviderInterface
{
    public function __construct(
        private AccessInterface $access,
        private BoardQueryInterface $query,
        private TicketServiceInterface $tickets,
        private CommentServiceInterface $comments,
    ) {
    }

    /**
     * @param AiToolContext $context The authorized actor and optional project
     *
     * @return iterable<ProjectTool>
     */
    public function tools(AiToolContext $context): iterable
    {
        $project = $context->projectId();

        if ($project === null) {
            $projectFields = array_flip(['id', 'name', 'description', 'open_count']);

            yield new ProjectTool(
                'nafinity_projects',
                'List the signed-in user\'s visible projects. No other projects are accessible.',
                ['properties' => []],
                fn() => array_map(
                    static fn($item) => array_intersect_key($item, $projectFields),
                    $this->query->projects(),
                ),
                'Meine Projekte',
                keywords: ['Projekte', 'Übersicht', 'Arbeitsbereiche'],
            );

            return;
        }

        $identifier = ['type' => 'integer', 'minimum' => 1];
        $text       = ['type' => 'string'];
        yield new ProjectTool(
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
        );
        yield new ProjectTool(
            'nafinity_ticket',
            'Read a ticket with comments, activity, labels and attachment metadata from this project. '
                . 'ticket_id is the database ID, not its displayed number.',
            ['properties' => ['ticket_id' => $identifier], 'required' => ['ticket_id']],
            fn($args) => $this->query->detail($project, Input::id($args['ticket_id'])),
            'Ticket lesen',
            keywords: ['Aufgabe', 'Beschreibung', 'Kommentare', 'Anhänge', 'Details'],
        );
        yield new ProjectTool(
            'nafinity_activity',
            'Read the recent activity of the current project.',
            ['properties' => []],
            fn() => $this->query->activity($project),
            'Aktivität lesen',
            keywords: ['Änderungen', 'Verlauf', 'Aktivitäten'],
        );
        $ticketFields = [
            'title'          => $text,
            'description'    => $text,
            'priority'       => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
            'column_id'      => $identifier,
            'swimlane_id'    => $identifier,
            'board_revision' => $identifier,
        ];
        yield new ProjectTool(
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
        );
        $editFields = [
            'ticket_id'      => $identifier,
            'version'        => $identifier,
            'board_revision' => $identifier,
            'title'          => $text,
            'description'    => $text,
            'priority'       => $ticketFields['priority'],
        ];
        yield new ProjectTool(
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
        );
        $moveFields = [
            'ticket_id'      => $identifier,
            'version'        => $identifier,
            'board_revision' => $identifier,
            'column_id'      => $identifier,
            'swimlane_id'    => $identifier,
        ];
        yield new ProjectTool(
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
        );
        yield new ProjectTool(
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
        );

    }
}
