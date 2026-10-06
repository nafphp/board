<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\CommentServiceInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Contracts\TimerServiceInterface;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;

use function Naf\Board\extensions;
use function Naf\request;
use function Naf\route;

/**
 * One ticket: its page, and everything that changes it.
 *
 * Tickets are addressed by their reference (NAF-12) in the URL and resolved to
 * an id inside the project before anything else happens.
 *
 * @internal
 */
final class TicketController
{
    public function __construct(
        private BoardQueryInterface $query,
        private TicketServiceInterface $tickets,
        private TimerServiceInterface $timers,
        private CommentServiceInterface $comments,
        private AccessInterface $access,
        private PageRendererInterface $pages,
    ) {
    }

    public function show(string $project, string $ticket): ResponseInterface
    {
        return Respond::read(function () use ($project, $ticket) {
            $projectId = Input::id($project);
            $boardData = $this->query->board($projectId);
            $details   = $this->query->detail($projectId, $this->tickets->resolve($projectId, $ticket));

            return $this->pages->render('ticket', [
                'title' => 'Ticket',
                ...$boardData,
                ...$details,
                'mode'     => 'detail',
                'fragment' => $this->fragment(),
                'timer'    => $this->timers->state($projectId, $details['ticket']['id']),
                'targets'  => $this->query->transferTargets($projectId),
            ]);
        });
    }

    public function new(string $project): ResponseInterface
    {
        return Respond::read(function () use ($project) {
            $projectId = Input::id($project);
            $this->access->project($projectId, 'write');

            return $this->pages->render('ticket', [
                'title' => 'Neues Ticket',
                ...$this->query->board($projectId),
                'mode'               => 'create',
                'ticket'             => null,
                'fragment'           => $this->fragment(),
                'selected_labels'    => [],
                'selected_assignees' => [],
                'metadata'           => [],
                'metaDefinitions'    => array_filter(
                    extensions()->ticketFields()->metadata(),
                    static fn($field) => $field->showOnCreate,
                ),
                'metaUnknown' => [],
            ]);
        });
    }

    public function create(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $projectId = Input::id($project);
            $id        = $this->tickets->create($projectId, $data);
            $reference = $this->tickets->reference($projectId, $id);

            return [
                'url' => '/projects/' . $project . '/tickets/' . $reference,
                'id'  => $reference,
            ];
        });
    }

    public function update(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->tickets->update($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket];
        });
    }

    public function link(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->tickets->link($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => $this->address($project, $ticket)];
        });
    }

    public function timer(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $state     = $this->timers->act(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
                $data,
            );

            return [...$state, 'url' => $this->address($project, $ticket)];
        });
    }

    public function move(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $id       = Input::id($project);
            $ticketId = $this->tickets->resolve($id, $ticket);
            $this->tickets->move($id, $ticketId, $data);
            $moved = $this->tickets->ticket($id, $ticketId);

            // The board applies the move in place, so it needs the fresh optimistic lock values.
            return [
                'url'      => '/projects/' . $project,
                'revision' => (string) $this->tickets->board($id)['revision'],
                'version'  => (string) $moved['version'],
                'status'   => $moved['status'],
            ];
        });
    }

    public function transfer(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $moved     = $this->tickets->transfer(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
                $data,
            );

            // The address it was read from no longer answers, so the whole page follows it.
            return ['url' => $this->address((string) $moved['project'], (string) $moved['reference'])];
        });
    }

    /**
     * Remove a ticket for good.
     *
     * Answers with the board rather than the ticket, because the ticket is the
     * one place the browser cannot be sent back to.
     */
    public function delete(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->tickets->delete($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => route('board', ['project' => $projectId])];
        });
    }

    public function state(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->tickets->state($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket];
        });
    }

    public function comment(string $project, string $ticket): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->comments->save($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket . '#comments'];
        });
    }

    /** Whether the drawer asked for the page without its shell. */
    private function fragment(): bool
    {
        return (request()->getQueryParams()['fragment'] ?? '') === '1';
    }

    private function address(string $project, string $ticket): string
    {
        return route('ticket', ['project' => $project, 'ticket' => $ticket]);
    }
}
