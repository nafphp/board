<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Services\SlotRenderer;
use Naf\Board\Support\BoardSlotContext;
use Naf\Board\Support\InlineFieldRenderer;
use Naf\Board\Support\PageSlotContext;
use Naf\Board\Support\TicketSlotContext;
use Naf\Board\Support\UiContext;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\app;
use function Naf\Board\extensions;

/**
 * Where an extension's own markup appears, and what it is told.
 *
 * A slot hands every contribution one object that answers everything it could
 * need. That is deliberate: the built-in widgets read nothing else any more, so
 * anything missing from the context would stop them rendering too -- which makes
 * the application its own check on whether the context is enough for extensions.
 */
final class SlotContributionTest extends ExtensionInstalledTestCase
{
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ticket = $this->createTicket();
        // The stored value the context questions below are about.
        $this->tickets->update($this->project, $this->ticket, [
            'metadata' => ['example.reviewed' => true],
            'version'  => $this->ticketVersion($this->ticket),
            ...$this->revision(),
        ]);
    }

    /**
     * Two widgets declare the same index, so the id decides -- otherwise the
     * order would depend on which package Composer happened to load first.
     */
    public function testWidgetsSharingAnIndexAreOrderedById(): void
    {
        $ids = array_map(
            static fn(array $entry) => $entry['id'],
            app()->container()->get(SlotRenderer::class)->items('ticket.main.widgets', $this->context()),
        );

        $this->assertSame([
            'core.ticket.links',
            'example.reports.widget',
            'example.review.notes',
            'core.ticket.attachments',
            'core.ticket.activity',
        ], $ids);
    }

    public function testExtensionBReplacedExtensionAsWidgetUnderTheSameId(): void
    {
        $widget = extensions()->ui()->get('example.reports.widget');

        $this->assertNotNull($widget);
        $this->assertSame('example-b/ticket-widget', $widget->template);
    }

    public function testTheTicketContextSaysWhatItIsAbout(): void
    {
        $slot = $this->ticketSlot();

        $this->assertSame($this->ticket, $slot->ticketId());
        $this->assertSame($this->project, $slot->projectId());
        $this->assertNotNull($slot->ui(), 'the authorized context is not reachable');
    }

    /** A value that is stored, one that only has a default, and one nobody knows. */
    public function testTheContextAnswersForStoredAndUnknownValuesAlike(): void
    {
        $slot   = $this->ticketSlot();
        $detail = $this->query->detail($this->project, $this->ticket);

        $this->assertTrue($slot->value('example.reviewed'), 'a stored value was not returned');
        $this->assertSame(
            $detail['metadata']['example.external_id'],
            $slot->value('example.external_id'),
        );
        $this->assertSame('fallback', $slot->value('example.invented', 'fallback'));
    }

    public function testFieldsInReturnsTheGroupAskedForAndNoOther(): void
    {
        $slot = $this->ticketSlot();

        $this->assertArrayHasKey('example.reviewed', $slot->fieldsIn('details'));
        $this->assertSame([], $slot->fieldsIn('planning'), 'a foreign group was returned');
    }

    /**
     * A board slot is reused for every card, so taking a card must give a new
     * context rather than change the one everything else is holding.
     */
    public function testABoardSlotTakesACardWithoutChangingTheOriginal(): void
    {
        $board = $this->query->board($this->project);
        $slot  = new BoardSlotContext(
            $this->context(),
            $board['project'],
            app()->container()->get(AccessInterface::class)->project($this->project),
            $board['labels'],
            $board['members'],
            $board['card_metadata'],
            'token',
        );

        $this->assertNull($slot->card, 'a board slot started with a card');

        $withCard = $slot->withCard(['id' => $this->ticket]);

        $this->assertSame($this->ticket, $withCard->card['id']);
        $this->assertNull($slot->card, 'withCard changed the original');
        $this->assertTrue($withCard->value('example.reviewed'), 'the card metadata was not reachable');
        $this->assertSame('fallback', $withCard->value('example.invented', 'fallback'));
    }

    /** A slot with nothing of its own still carries the authorization. */
    public function testAPageSlotKeepsItsAuthorization(): void
    {
        $context = $this->context();

        $this->assertSame($context, (new PageSlotContext($context))->ui());
    }

    public function testTheSlotOffersTheContributionsAtAll(): void
    {
        $this->assertNotSame(
            [],
            app()->container()->get(SlotRenderer::class)->items('ticket.main.widgets', $this->context()),
        );
    }

    private function context(): UiContext
    {
        return new UiContext(
            1,
            app()->container()->get(AccessInterface::class)->project($this->project),
            UiContext::MODE_DETAIL,
            'ticket',
            $this->ticket,
        );
    }

    private function ticketSlot(): TicketSlotContext
    {
        $scope  = app()->container()->get(AccessInterface::class)->project($this->project);
        $detail = $this->query->detail($this->project, $this->ticket);
        $board  = $this->query->board($this->project);

        return new TicketSlotContext(
            ui: $this->context(),
            ticket: $detail['ticket'],
            project: $board['project'],
            scope: $scope,
            board: $board['board'],
            params: [
                'project' => $this->project,
                'ticket'  => $this->tickets->reference($this->project, $this->ticket),
            ],
            token: 'token',
            editable: true,
            isNew: false,
            columns: $board['columns'],
            swimlanes: $board['swimlanes'],
            labels: $board['labels'],
            members: $board['members'],
            metadata: $detail['metadata'],
            fields: $detail['metaDefinitions'],
            links: $detail['linked_tickets'],
            attachments: $detail['attachments'],
            activity: $detail['activity'],
            timer: [],
            preferences: $this->query->preferences(),
            creator: $detail['creator'],
            field: new InlineFieldRenderer(static fn() => ''),
        );
    }
}
