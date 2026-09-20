<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

/**
 * A filter an extension contributes narrows the board like any built-in one:
 * the cards and the count agree, because they are one query rather than two.
 *
 * Its own project, so the counts mean what they say -- absolute numbers on a
 * board other tests also write to would be a test of the other tests.
 */
final class BoardFilterContributionTest extends ExtensionInstalledTestCase
{
    private int $board;
    private int $reviewed;
    private int $open;

    protected function setUp(): void
    {
        parent::setUp();

        $this->board = $this->projects->create([
            'name'        => 'Filters',
            'description' => 'One reviewed ticket and one not',
        ]);
        $layout = $this->query->board($this->board);

        $this->reviewed = $this->addTicket($layout, 'Reviewed');
        $this->open     = $this->addTicket($layout, 'Not yet');
        // Both values are written, not only the true one. The example's filter
        // asks EXISTS on the stored row, so it matches what was recorded rather
        // than what a ticket would default to -- a ticket nobody ever marked
        // either way is found by neither direction.
        $this->mark($this->reviewed, true);
        $this->mark($this->open, false);
    }

    public function testTheFilterFindsWhatItMatches(): void
    {
        $result = $this->query->board($this->board, ['filters' => ['example.reviewed' => '1']]);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['cards'], 'the cards and the count disagree');
        $this->assertSame($this->reviewed, (int) $result['cards'][0]['id']);
    }

    public function testTheFilterAlsoFindsWhatItDoesNotMatch(): void
    {
        $result = $this->query->board($this->board, ['filters' => ['example.reviewed' => '0']]);

        $this->assertSame(1, $result['total']);
        $this->assertSame($this->open, (int) $result['cards'][0]['id']);
    }

    public function testAFilterNobodyDeclaredIsRefusedRatherThanIgnored(): void
    {
        $this->assertDenied(
            422,
            fn() => $this->query->board($this->board, ['filters' => ['example.invented' => '1']]),
        );
    }

    private function mark(int $ticket, bool $reviewed): void
    {
        $this->tickets->update($this->board, $ticket, [
            'metadata'       => ['example.reviewed' => $reviewed],
            'version'        => $this->tickets->ticket($this->board, $ticket)['version'],
            'board_revision' => $this->tickets->board($this->board)['revision'],
        ]);
    }

    /** @param array<string,mixed> $layout */
    private function addTicket(array $layout, string $title): int
    {
        return $this->tickets->create($this->board, [
            'title'          => $title,
            'description'    => 'For the filter',
            'priority'       => 'normal',
            'column_id'      => $layout['columns'][0]['id'],
            'swimlane_id'    => $layout['swimlanes'][0]['id'],
            'board_revision' => $this->tickets->board($this->board)['revision'],
        ]);
    }
}
