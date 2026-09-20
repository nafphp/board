<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Creating a ticket, and the four ways a payload can be wrong.
 *
 * A rejected write must leave nothing behind -- not the ticket, and not the
 * activity entry that would otherwise record an event that never happened.
 */
final class TicketCreationTest extends BoardTestCase
{
    public function testACreatedTicketIsReadableAndRecordsOneActivity(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());

        $this->assertSame(
            'Searchable sunflower',
            $this->query->detail($this->projectA, $ticket)['ticket']['title'],
        );
        $this->assertSame(
            1,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM activities WHERE ticket_id=? AND event_type='ticket.created'",
                [$ticket],
            ),
        );
    }

    /**
     * Every one of these names something that belongs to Bob's project. Accepting
     * any of them would place a row of project A's under a column, lane, person or
     * label of project B's -- the boundary breached from the inside.
     *
     * @return array<string,array{string,mixed}>
     */
    public static function foreignReferences(): array
    {
        return [
            'column of another project'   => ['column_id', 'columns'],
            'swimlane of another project' => ['swimlane_id', 'swimlanes'],
            'member of another project'   => ['assignee_ids', 'assignee'],
            'label of another project'    => ['label_ids', 'label'],
        ];
    }

    #[DataProvider('foreignReferences')]
    public function testAForeignReferenceIsRejectedAndNothingIsWritten(string $key, string $source): void
    {
        $value = match ($source) {
            'columns'   => $this->boardB['columns'][0]['id'],
            'swimlanes' => $this->boardB['swimlanes'][0]['id'],
            'assignee'  => [$this->bob->getId()],
            'label'     => [$this->labelB],
        };
        $tickets    = $this->scalar('SELECT COUNT(*) FROM tickets');
        $activities = $this->scalar('SELECT COUNT(*) FROM activities');

        $this->assertDenied(
            422,
            fn() => $this->tickets->create($this->projectA, $this->ticketData([$key => $value])),
        );

        $this->assertSame($tickets, $this->scalar('SELECT COUNT(*) FROM tickets'), 'the ticket persisted');
        $this->assertSame(
            $activities,
            $this->scalar('SELECT COUNT(*) FROM activities'),
            'an activity for a ticket that was never created persisted',
        );
    }

    /**
     * @return array<string,array{string,mixed}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'title is not a string'      => ['title', []],
            'priority is not a string'   => ['priority', []],
            'the date does not exist'    => ['due_date', '2026-02-30'],
            'assignees are not a list'   => ['assignee_ids', '1,2'],
            'a label id is not a number' => ['label_ids', [[]]],
        ];
    }

    #[DataProvider('malformedPayloads')]
    public function testAMalformedPayloadIsRejected(string $key, mixed $value): void
    {
        $this->assertDenied(
            422,
            fn() => $this->tickets->create($this->projectA, $this->ticketData([$key => $value])),
        );
    }

    /**
     * The board revision is how a write says which board it was composed against.
     * An old one means someone else has moved something in the meantime, and the
     * write is refused rather than applied to a board its author never saw.
     */
    public function testAStaleBoardRevisionIsRefused(): void
    {
        $this->assertDenied(
            409,
            fn() => $this->tickets->create($this->projectA, $this->ticketData(['board_revision' => 1])),
        );
    }
}
