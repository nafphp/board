<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * Moving a ticket to another project, over HTTP.
 *
 * It answers at a new address afterwards and not at the old one, because the
 * address carries the project. What only meant something where it came from --
 * its labels -- stays behind, and the move obeys the same optimistic lock as
 * every other write.
 */
final class TicketTransferOverHttpTest extends AcceptanceTestCase
{
    private string $destination;
    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $created = $this->post($this->alice, '/projects', [
            'name'        => 'Ablage',
            'description' => 'Wohin verschobene Tickets gehen',
            'color'       => '#10b981',
            'icon'        => 'A',
        ]);
        $this->assertSame(200, $created['status'], 'the destination project could not be created');
        $this->destination = json_decode($created['body'], true)['url'];

        $this->url = $this->createTicket(['title' => 'Zieht um', 'label_ids' => [1]]);
    }

    public function testTheTicketOffersTheProjectsAMoveCouldGoTo(): void
    {
        $page = $this->page($this->alice, $this->url);

        $this->assertStringContainsString('name="project_id"', $page);
        $this->assertStringContainsString('Ablage', $page, 'the new project is not among the destinations');
    }

    /** There is nowhere to move something that does not exist yet. */
    public function testADraftIsOfferedNowhereToMoveTo(): void
    {
        $this->assertStringNotContainsString(
            'name="project_id"',
            $this->page($this->alice, '/projects/1/tickets/new'),
        );
    }

    public function testTheTicketAnswersAtANewAddressAndNoLongerAtTheOldOne(): void
    {
        $moved = $this->transfer();

        $this->assertStringStartsWith(
            $this->destination . '/tickets/',
            $moved,
            'the ticket did not arrive in the other project',
        );
        $this->assertSame(
            404,
            $this->alice->request($this->url)['status'],
            'the ticket still answers where it used to be',
        );
    }

    public function testWhatOnlyMeantSomethingInTheOldProjectStaysBehind(): void
    {
        $page = $this->page($this->alice, $this->transfer());

        $this->assertStringContainsString('Zieht um', $page);
        $this->assertStringNotContainsString('label-tag', $page, 'a label from the old project came along');
    }

    public function testAMoveCarryingAStaleVersionIsRefused(): void
    {
        $moved = $this->transfer();

        $refused = $this->post($this->alice, $moved . '/transfer', [
            'project_id' => (string) self::PROJECT,
            'version'    => '1',
        ]);

        $this->assertSame(409, $refused['status']);
    }

    public function testAStrangerCannotMoveATicketOutOfAProjectTheyCannotSee(): void
    {
        $refused = $this->post(
            $this->bob,
            $this->url . '/transfer',
            ['project_id' => (string) self::OTHER_PROJECT],
            $this->bob->token($this->page($this->bob, '/projects/' . self::OTHER_PROJECT)),
        );

        $this->assertSame(404, $refused['status']);
    }

    /** Move the ticket and return the address it answers at now. */
    private function transfer(): string
    {
        $page     = $this->page($this->alice, $this->url);
        $response = $this->post($this->alice, $this->url . '/transfer', [
            'project_id' => basename($this->destination),
            'version'    => $this->firstMatch('/data-version="(\d+)"/', $page, 'the version'),
        ]);

        $this->assertSame(200, $response['status'], 'the move was refused');

        return json_decode($response['body'], true)['url'];
    }
}
