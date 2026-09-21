<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

/**
 * A project with two tickets, for everything that happens inside one ticket.
 *
 * The description deliberately contains text that looks like markup: it was
 * written before rich text existed and must keep being text.
 */
abstract class TicketDetailTestCase extends BoardTestCase
{
    protected int $project;
    protected int $ticket;
    protected int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->projects->create([
            'name'        => 'Ticket details',
            'description' => 'Inline regression',
        ]);
        $this->ticket = $this->createTicket();
        $this->other  = $this->createTicket();
    }

    protected function createTicket(): int
    {
        $board = $this->query->board($this->project);

        return $this->tickets->create($this->project, [
            'title'          => 'Original title',
            'description'    => 'Legacy <script> remains text',
            'priority'       => 'normal',
            'column_id'      => $board['columns'][0]['id'],
            'swimlane_id'    => $board['swimlanes'][0]['id'],
            'assignee_ids'   => [$this->alice->getId()],
            'board_revision' => $this->tickets->board($this->project)['revision'],
        ]);
    }

    /**
     * The version and board revision a write has to carry, read now.
     *
     * @return array<string,mixed>
     */
    protected function current(?int $ticket = null): array
    {
        return [
            'version'        => $this->tickets->ticket($this->project, $ticket ?? $this->ticket)['version'],
            'board_revision' => $this->tickets->board($this->project)['revision'],
        ];
    }

    /** @param array<string,mixed> $fields */
    protected function edit(array $fields): void
    {
        $this->tickets->update($this->project, $this->ticket, $fields + $this->current());
    }

    /** @return array<string,mixed> */
    protected function row(): array
    {
        return $this->tickets->ticket($this->project, $this->ticket);
    }
}
