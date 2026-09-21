<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Support\RichText;
use Naf\Board\Tests\Support\TicketDetailTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Editing one field of a ticket.
 *
 * Every field in the detail view saves on its own, so a write carries the one
 * thing that changed -- and must leave everything it did not name exactly as it
 * was. The version it carries is what stops two people overwriting each other.
 */
final class TicketDetailTest extends TicketDetailTestCase
{
    public function testEditingOneFieldLeavesTheOthersAlone(): void
    {
        $this->edit(['title' => 'Inline title']);

        $detail = $this->query->detail($this->project, $this->ticket);
        $this->assertSame('Legacy <script> remains text', $detail['ticket']['description']);
        $this->assertCount(1, $detail['selected_assignees'], 'the assignee was lost');
    }

    /** Text written before rich text existed stays text, and is shown as text. */
    public function testOlderPlainTextIsNotInterpretedAsMarkup(): void
    {
        $detail = $this->query->detail($this->project, $this->ticket);

        $this->assertStringNotContainsString('<script>', RichText::description($detail['ticket']));
    }

    public function testASecondWriteFromTheSameVersionIsRefused(): void
    {
        $version = $this->current();
        $this->tickets->update($this->project, $this->ticket, ['title' => 'First'] + $version);

        $this->assertDenied(
            409,
            fn() => $this->tickets->update($this->project, $this->ticket, ['title' => 'Stale'] + $version),
        );
    }

    public function testAssigneesCanBeClearedAltogether(): void
    {
        $this->edit(['assignee_ids' => []]);

        $this->assertSame([], $this->query->detail($this->project, $this->ticket)['selected_assignees']);
    }

    public function testPlanningFieldsArePersisted(): void
    {
        $this->edit([
            'start_date'       => '2026-09-16',
            'due_date'         => '2026-09-20',
            'estimate_minutes' => '125',
            'spent_minutes'    => '61',
        ]);

        $row = $this->row();
        $this->assertSame('2026-09-16', $row['start_date']);
        $this->assertSame(61, (int) $row['spent_minutes']);
        $this->assertSame(125, (int) $row['estimate_minutes']);
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function impossiblePlanning(): array
    {
        return [
            'a date that does not exist'    => [['start_date' => '2026-02-30']],
            'a start after the due date'    => [['start_date' => '2026-10-01']],
            'a date that is not a string'   => [['start_date' => []]],
            'negative time spent'           => [['spent_minutes' => -1]],
            'a fraction of a minute'        => [['spent_minutes' => '1.5']],
            'time that is not a value'      => [['spent_minutes' => []]],
            'an estimate beyond the column' => [['estimate_minutes' => '10000001']],
        ];
    }

    /** @param array<string,mixed> $invalid */
    #[DataProvider('impossiblePlanning')]
    public function testImpossiblePlanningIsRefused(array $invalid): void
    {
        // A due date for the start-date cases to be impossible against.
        $this->edit(['start_date' => '2026-09-16', 'due_date' => '2026-09-20']);

        $this->assertDenied(422, fn() => $this->edit($invalid));
    }

    public function testEveryOptionalPlanningFieldCanBeCleared(): void
    {
        $this->edit(['start_date' => '2026-09-16', 'due_date' => '2026-09-20', 'estimate_minutes' => '125']);

        $this->edit(['start_date' => '', 'due_date' => '', 'estimate_minutes' => '', 'spent_minutes' => 0]);

        $row = $this->row();
        $this->assertNull($row['start_date']);
        $this->assertNull($row['estimate_minutes']);
    }

    /**
     * The shorthand itself is covered without a database in DurationTest; what
     * matters here is that the field actually goes through that parser rather
     * than storing whatever it was handed.
     */
    public function testADurationTypedInShorthandReachesTheTicketAsMinutes(): void
    {
        $this->edit(['spent_minutes' => '2h 40m']);

        $this->assertSame(160, (int) $this->row()['spent_minutes']);
    }
}
