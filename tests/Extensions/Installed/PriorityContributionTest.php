<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\Board\extensions;

/**
 * A priority an extension brings.
 *
 * The board's own four are registry entries, not a closed type, so a package
 * declares a fifth the same way the board declares its first. This test is the
 * difference between that being true and merely being intended.
 *
 * Its own project, so the counts mean what they say -- absolute numbers on a
 * board other tests also write to would be a test of the other tests.
 */
final class PriorityContributionTest extends ExtensionInstalledTestCase
{
    private const string BROUGHT = 'example.blocker';

    private int $board;
    private int $blocked;
    private int $ordinary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->board = $this->projects->create([
            'name'        => 'Priorities',
            'description' => 'One blocked ticket and one ordinary',
        ]);
        $layout = $this->query->board($this->board);

        // The column was a VARCHAR(12) with a CHECK listing the board's own
        // four, so this write is the part that used to be impossible.
        $this->blocked  = $this->addTicket($layout, 'Blocked', self::BROUGHT);
        $this->ordinary = $this->addTicket($layout, 'Ordinary', 'normal');
    }

    public function testItJoinsTheBoardsOwnFourInOrder(): void
    {
        $this->assertSame(
            ['low', 'normal', 'high', 'urgent', self::BROUGHT],
            extensions()->priorities()->keys(),
            'the contributed priority sorts after the built-in four, by its index',
        );
        $this->assertSame('Blockiert', extensions()->priorities()->get(self::BROUGHT)?->label);
    }

    public function testATicketKeepsThePriorityItWasGiven(): void
    {
        $this->assertSame(
            self::BROUGHT,
            $this->tickets->ticket($this->board, $this->blocked)['priority'],
        );
    }

    public function testTheBoardCanBeFilteredByIt(): void
    {
        /*
         * The filter reads the registry when it runs. Reading it at registration
         * time would pass only if the extension happened to boot first -- and
         * extensions boot after the board's own providers, so it would in fact
         * never pass.
         */
        $result = $this->query->board($this->board, ['priority' => self::BROUGHT]);

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['cards'], 'the cards and the count disagree');
        $this->assertSame($this->blocked, (int) $result['cards'][0]['id']);
    }

    public function testItDoesNotWidenWhatTheOtherPrioritiesMatch(): void
    {
        $result = $this->query->board($this->board, ['priority' => 'normal']);

        $this->assertSame(1, $result['total']);
        $this->assertSame($this->ordinary, (int) $result['cards'][0]['id']);
    }

    public function testAPriorityNobodyDeclaredIsRefusedRatherThanIgnored(): void
    {
        $this->assertDenied(
            422,
            fn() => $this->query->board($this->board, ['priority' => 'example.invented']),
        );
    }

    /** @param array<string,mixed> $layout */
    private function addTicket(array $layout, string $title, string $priority): int
    {
        return $this->tickets->create($this->board, [
            'title'          => $title,
            'description'    => 'For the priority',
            'priority'       => $priority,
            'column_id'      => $layout['columns'][0]['id'],
            'swimlane_id'    => $layout['swimlanes'][0]['id'],
            'board_revision' => $this->tickets->board($this->board)['revision'],
        ]);
    }
}
