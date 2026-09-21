<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * The ticket page, through real requests.
 *
 * Every field saves on its own, carrying the version the page was showing. What
 * is stored is escaped on the way out and sanitized on the way in -- a title
 * somebody wrote as markup stays text, and a description keeps its formatting
 * without keeping anything that could run.
 */
final class TicketDetailOverHttpTest extends AcceptanceTestCase
{
    private const RICH = '<h2>Plan</h2><p><strong>Bold</strong> and <em>italic</em></p>'
        . '<script>alert(1)</script><a href="javascript:alert(2)">No</a>';

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url = $this->createTicket(['title' => 'HTTP acceptance <script>alert(1)</script>']);
    }

    public function testAStoredTitleIsEscapedWhereItIsShown(): void
    {
        $page = $this->page($this->alice, $this->url);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page, 'a stored title became markup');
    }

    public function testAnInlineEditPersistsAndASecondOneFromTheSameVersionConflicts(): void
    {
        $state = $this->ticketState($this->url);

        $saved = $this->post($this->alice, $this->url, ['title' => 'Inline HTTPS title'] + $state);

        $this->assertSame(200, $saved['status']);
        $this->assertStringContainsString('Inline HTTPS title', $this->page($this->alice, $this->url));
        $this->assertSame(
            409,
            $this->post($this->alice, $this->url, ['title' => 'Stale title'] + $state)['status'],
        );
    }

    public function testARichDescriptionKeepsItsFormattingAndNothingThatCouldRun(): void
    {
        $this->assertSame(
            200,
            $this->post($this->alice, $this->url, ['description_html' => self::RICH] + $this->ticketState($this->url))['status'],
        );

        $page = $this->page($this->alice, $this->url);
        $this->assertStringContainsString('<h2>Plan</h2>', $page);
        $this->assertStringContainsString('<strong>Bold</strong>', $page);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringNotContainsString('javascript:alert(2)', $page);
    }

    public function testAnEditToAnotherFieldLeavesTheFormattingAlone(): void
    {
        $this->post($this->alice, $this->url, ['description_html' => self::RICH] + $this->ticketState($this->url));

        $this->post($this->alice, $this->url, ['priority' => 'urgent'] + $this->ticketState($this->url));

        $this->assertStringContainsString('<h2>Plan</h2>', $this->page($this->alice, $this->url));
    }

    public function testPlanningAndTrackedTimePersistAndAreValidated(): void
    {
        $saved = $this->post($this->alice, $this->url, [
            'start_date'       => '2026-09-16',
            'due_date'         => '2026-09-22',
            'estimate_minutes' => 180,
            'spent_minutes'    => 45,
        ] + $this->ticketState($this->url));

        $this->assertSame(200, $saved['status']);
        $this->assertSame(
            422,
            $this->post($this->alice, $this->url, ['spent_minutes' => -1] + $this->ticketState($this->url))['status'],
        );
    }

    public function testAViewerCannotEditAndIsShownNoControlsToTryWith(): void
    {
        $refused = $this->post(
            $this->viewer,
            $this->url,
            ['title' => 'Forbidden'] + $this->ticketState($this->url),
            $this->viewer->token($this->page($this->viewer, '/projects/' . self::PROJECT)),
        );

        $this->assertSame(403, $refused['status']);

        $page = $this->page($this->viewer, $this->url);
        $this->assertStringNotContainsString('data-ticket-form', $page, 'a viewer was offered the edit form');
        $this->assertStringNotContainsString('data-comment-composer', $page, 'a viewer was offered the composer');
    }

    /** The drawer asks for the same page as a fragment: the workspace without the shell. */
    public function testTheDrawerFragmentIsTheWorkspaceWithoutThePage(): void
    {
        $fragment = $this->page($this->alice, $this->url . '?fragment=1');

        $this->assertStringContainsString('ticket-workspace', $fragment);
        $this->assertStringContainsString('data-comment-composer', $fragment);
        $this->assertStringNotContainsString('<html', $fragment, 'the fragment carried a whole page');
    }

    /**
     * The one assignment people reach for, as one click.
     *
     * Offered only to somebody the board already knows -- a person who is not a
     * member cannot hold a ticket, and a shortcut for it would be a promise the
     * endpoint breaks. It carries their id, because the button works the picker
     * rather than a second route to the server.
     */
    public function testAnUnassignedTicketOffersToAssignItself(): void
    {
        $detail = $this->page($this->alice, $this->url . '?fragment=1');

        $this->assertMatchesRegularExpression(
            '/data-assign-self="1"/',
            $detail,
            'the shortcut is missing or does not name the person it assigns',
        );
    }

    public function testAViewerIsNotOfferedIt(): void
    {
        $detail = $this->page($this->viewer, $this->url . '?fragment=1');

        $this->assertStringNotContainsString(
            'data-assign-self',
            $detail,
            'somebody who may not edit was offered a shortcut that edits',
        );
    }
}
