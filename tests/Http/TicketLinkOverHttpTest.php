<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * Linking two tickets over HTTP.
 *
 * Tickets are named by their number within the project, so a link can only ever
 * reach something on the same board -- and asking from another project finds
 * nothing rather than reporting that something was there.
 */
final class TicketLinkOverHttpTest extends AcceptanceTestCase
{
    private string $url;
    private string $other;
    private int $otherNumber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url   = $this->createTicket(['title' => 'Inline HTTPS title']);
        $this->other = $this->createTicket(['title' => 'Linked HTTP ticket']);

        $this->otherNumber = (int) $this->firstMatch(
            '/class="ticket-key">NAF-(\d+)/',
            $this->page($this->alice, $this->other),
            "the linked ticket's number",
        );
    }

    public function testALinkIsShownFromBothEnds(): void
    {
        $added = $this->post(
            $this->alice,
            $this->url . '/links',
            ['number' => $this->otherNumber] + $this->ticketState($this->url),
        );

        $this->assertSame(200, $added['status']);
        $this->assertStringContainsString('Linked HTTP ticket', $this->page($this->alice, $this->url));
        $this->assertStringContainsString('Inline HTTPS title', $this->page($this->alice, $this->other));
    }

    public function testALinkCanBeRemovedAgain(): void
    {
        $this->post(
            $this->alice,
            $this->url . '/links',
            ['number' => $this->otherNumber] + $this->ticketState($this->url),
        );

        $removed = $this->post(
            $this->alice,
            $this->url . '/links',
            ['number' => $this->otherNumber, 'action' => 'delete'] + $this->ticketState($this->url),
        );

        $this->assertSame(200, $removed['status']);
    }

    public function testLinkingNeedsATokenLikeAnyOtherWrite(): void
    {
        $response = $this->alice->request(
            $this->url . '/links',
            ['number' => $this->otherNumber] + $this->ticketState($this->url),
            ['Content-Type: application/json', 'Accept: application/json'],
        );

        $this->assertSame(400, $response['status']);
    }

    public function testAnotherProjectFindsNothingToLinkTo(): void
    {
        $refused = $this->post(
            $this->bob,
            $this->url . '/links',
            ['number' => 1, 'version' => 1],
            $this->bob->token($this->page($this->bob, '/projects/' . self::OTHER_PROJECT)),
        );

        $this->assertSame(404, $refused['status']);
    }
}
