<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Contracts\AttachmentServiceInterface;
use Naf\Board\Definition\UiContribution;
use Naf\Board\ExtensionContext;
use Naf\Board\Modules\CoreTicket;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\route;

/**
 * The ticket's own panels go through the same registry as an extension's, which
 * is what makes them reorderable at all. Two things must not follow from that:
 * a core field turning into a metadata field, and removing a surface taking the
 * thing behind it with it.
 *
 * A widget is a way in, not the feature. Removing the upload panel hides the
 * button; the route, the service and the files are untouched, because an
 * installation that wants no upload button has not asked to lose its files.
 */
final class PanelOrderTest extends ExtensionInstalledTestCase
{
    public function testThePanelsAreInTheirDeclaredOrder(): void
    {
        $this->assertSame([
            'core.ticket.primary',
            'core.ticket.details',
            'core.ticket.planning',
            'core.ticket.information',
        ], $this->panelIds());
    }

    public function testAPanelCanBeMovedByGivingItAnotherIndex(): void
    {
        $original = extensions()->ui()->get('core.ticket.information');

        try {
            extensions()->ui()->add(new UiContribution(
                $original->id,
                $original->slot,
                $original->template,
                50,
                $original->provider,
                $original->permission,
                $original->modes,
            ), true);

            $this->assertSame('core.ticket.information', $this->panelIds()[0], 'the reordering did not take');
        } finally {
            extensions()->ui()->add($original, true);
        }
    }

    /**
     * Reordering a core field must never turn it into a metadata field: those
     * are stored beside the ticket and would bypass move, the pivots and the
     * timer, all of which read the column.
     */
    public function testACoreFieldKeepsItsAdapter(): void
    {
        $column = extensions()->ticketFields()->get('column_id');

        $this->assertNotNull($column);
        $this->assertFalse($column->isMetadata(), 'column_id became metadata');
        $this->assertFalse(
            extensions()->ticketFields()->get('spent_minutes')?->isMetadata(),
            'spent_minutes became metadata',
        );
    }

    public function testTheUploadWidgetComesFromTheRegistryAndOnlyNeedsRead(): void
    {
        $widget = extensions()->ui()->get('core.ticket.attachments');

        $this->assertNotNull($widget, 'the upload widget is not registered');
        $this->assertSame('read', $widget->permission, 'seeing the list asks for more than read');
    }

    public function testRemovingTheUploadWidgetKeepsTheRoutesTheServiceAndTheFiles(): void
    {
        $widget = extensions()->ui()->get('core.ticket.attachments');
        $before = (int) $this->scalar('SELECT COUNT(*) FROM attachments');

        $this->assertTrue(extensions()->ui()->remove('core.ticket.attachments'));

        try {
            $this->assertNull(extensions()->ui()->get('core.ticket.attachments'), 'the widget survived');
            $this->assertArrayHasKey('attachment.upload', route()->all(), 'the upload route went with it');
            $this->assertArrayHasKey('attachment.download', route()->all(), 'the download route went with it');
            $this->assertNotNull(app()->container()->get(AttachmentServiceInterface::class));
            $this->assertSame(
                $before,
                (int) $this->scalar('SELECT COUNT(*) FROM attachments'),
                'removing a widget deleted attachments',
            );
        } finally {
            extensions()->ui()->add($widget);
        }
    }

    /**
     * Every group a field declares has to be a panel that exists, or the field
     * is registered into nowhere and simply never appears.
     */
    public function testEveryTicketFieldGroupPointsAtARegisteredPanel(): void
    {
        $groups = CoreTicket::groups(new ExtensionContext(app()->container(), extensions()));

        $this->assertContains('details', $groups, 'a core group is missing');

        extensions()->ticketFields()->assertGroups($groups);
        $this->addToAssertionCount(1);
    }

    /** @return list<string> */
    private function panelIds(): array
    {
        return array_keys(array_filter(
            extensions()->ui()->all(),
            static fn($contribution) => $contribution->slot === 'ticket.sidebar.panels',
        ));
    }
}
