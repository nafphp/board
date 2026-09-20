<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * The run lives on the server, not in a tab.
 *
 * So it is visible on the board and in the bar without opening the ticket, it
 * survives a reload, and starting somewhere else settles it -- a person counts
 * one thing at a time, and which thing is a fact about them rather than about
 * the window they happen to be looking at.
 */
final class TimerOverHttpTest extends AcceptanceTestCase
{
    private const FIRST  = '/projects/1/tickets/NAF-1/timer';
    private const SECOND = '/projects/1/tickets/NAF-2/timer';

    protected function tearDown(): void
    {
        // Whatever a test started, stopped again: this is the seeded project.
        $this->post($this->alice, self::FIRST, ['action' => 'stop']);
        $this->post($this->alice, self::SECOND, ['action' => 'stop']);
        parent::tearDown();
    }

    public function testStartingReportsARunningRunAndMarksItOnTheBoard(): void
    {
        $started = $this->post($this->alice, self::FIRST, ['action' => 'start']);

        $this->assertSame(200, $started['status']);
        $this->assertSame('running', json_decode($started['body'], true)['state']);

        $board = $this->page($this->alice, '/projects/' . self::PROJECT);
        $this->assertStringContainsString('data-running-timer', $board, 'the bar does not mark the run');
        $this->assertStringContainsString('class="timer-pulse"', $board, 'the card does not mark the run');
    }

    public function testStartingElsewhereSettlesTheRunThatWasCounting(): void
    {
        $this->post($this->alice, self::FIRST, ['action' => 'start']);

        $this->post($this->alice, self::SECOND, ['action' => 'start']);

        $this->assertStringContainsString(
            'data-state="paused"',
            $this->page($this->alice, '/projects/1/tickets/NAF-1'),
            'the first ticket kept counting',
        );
    }

    public function testStoppingClosesTheRunAndClearsTheMarker(): void
    {
        $this->post($this->alice, self::FIRST, ['action' => 'start']);

        $stopped = $this->post($this->alice, self::FIRST, ['action' => 'stop']);

        $this->assertSame(200, $stopped['status']);
        $this->assertSame('stopped', json_decode($stopped['body'], true)['state']);
        $this->assertStringNotContainsString(
            'data-running-timer',
            $this->page($this->alice, '/projects/' . self::PROJECT),
            'the bar still marks a run',
        );
    }

    public function testAnUnknownActionIsRefused(): void
    {
        $this->assertSame(422, $this->post($this->alice, self::FIRST, ['action' => 'rewind'])['status']);
    }

    public function testReadingIsNotPermissionToBookTime(): void
    {
        $refused = $this->post(
            $this->viewer,
            self::FIRST,
            ['action' => 'start'],
            $this->viewer->token($this->page($this->viewer, '/projects/' . self::PROJECT)),
        );

        $this->assertSame(403, $refused['status']);
    }

    public function testAStrangerIsNotToldTheTicketExists(): void
    {
        $refused = $this->post(
            $this->bob,
            self::FIRST,
            ['action' => 'start'],
            $this->bob->token($this->page($this->bob, '/projects/' . self::OTHER_PROJECT)),
        );

        $this->assertSame(404, $refused['status']);
    }
}
