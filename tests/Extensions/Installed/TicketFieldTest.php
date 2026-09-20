<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use InvalidArgumentException;
use LogicException;
use Naf\Board\Definition\TicketFieldDefinition;
use Naf\Board\Definition\UiContribution;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Naf\Board\extensions;

/**
 * Fields an extension adds to a ticket.
 *
 * They are stored beside the ticket rather than in it, and they take part in the
 * ticket's version: editing only a contributed value still moves the version, so
 * two people editing different halves of one ticket still see each other. A
 * value the extension refuses takes the whole write with it -- the ticket is one
 * thing, and half of it changing is not a state anyone asked for.
 *
 * What an extension may not do is claim a name the ticket already has, or take
 * away an area of the ticket the application is answerable for.
 */
final class TicketFieldTest extends ExtensionInstalledTestCase
{
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ticket = $this->createTicket();
    }

    public function testAContributedValueIsStoredAndItsDefaultIsReadable(): void
    {
        $this->assertSame('CRM-42', $this->metadata->get($this->project, $this->ticket, 'example.external_id'));
        $this->assertFalse(
            $this->metadata->get($this->project, $this->ticket, 'example.reviewed'),
            'the declared default was lost',
        );
    }

    public function testAContributedValueCanBeChangedAndReset(): void
    {
        $this->tickets->update($this->project, $this->ticket, [
            'metadata' => ['example.reviewed' => true],
            'version'  => $this->ticketVersion($this->ticket),
            ...$this->revision(),
        ]);
        $this->assertTrue($this->metadata->get($this->project, $this->ticket, 'example.reviewed'));

        $this->tickets->update($this->project, $this->ticket, [
            'metadata_reset' => ['example.external_id'],
            'version'        => $this->ticketVersion($this->ticket),
            ...$this->revision(),
        ]);
        $this->assertNull($this->metadata->get($this->project, $this->ticket, 'example.external_id'));
    }

    public function testAMetadataOnlyChangeRaisesTheVersionExactlyOnce(): void
    {
        $before = (int) $this->ticketVersion($this->ticket);

        $this->tickets->update($this->project, $this->ticket, [
            'metadata' => ['example.external_id' => 'CRM-77'],
            'version'  => $before,
            ...$this->revision(),
        ]);

        $this->assertSame($before + 1, (int) $this->ticketVersion($this->ticket));
    }

    public function testAValueTheExtensionRefusesTakesTheWholeWriteWithIt(): void
    {
        $before = $this->tickets->ticket($this->project, $this->ticket);
        $rows   = (int) $this->scalar('SELECT COUNT(*) FROM ticket_metadata');

        $this->assertDenied(422, fn() => $this->tickets->update($this->project, $this->ticket, [
            'title'    => 'Changed alongside an invalid value',
            'metadata' => ['example.reviewed' => 'perhaps'],
            'version'  => $before['version'],
            ...$this->revision(),
        ]));

        $after = $this->tickets->ticket($this->project, $this->ticket);
        $this->assertSame($before['title'], $after['title'], 'the title changed anyway');
        $this->assertSame($before['version'], $after['version'], 'the version moved anyway');
        $this->assertSame($rows, (int) $this->scalar('SELECT COUNT(*) FROM ticket_metadata'));
    }

    public function testAKeyNobodyDeclaredIsRefusedRatherThanStored(): void
    {
        $this->assertDenied(422, fn() => $this->tickets->update($this->project, $this->ticket, [
            'metadata' => ['example.invented' => 'x'],
            'version'  => $this->ticketVersion($this->ticket),
            ...$this->revision(),
        ]));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function reservedKeys(): array
    {
        return [
            'project_id'     => ['project_id'],
            'version'        => ['version'],
            'board_revision' => ['board_revision'],
        ];
    }

    #[DataProvider('reservedKeys')]
    public function testACoreAttributeCannotBeClaimedAsAContributedField(string $reserved): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TicketFieldDefinition($reserved, 'X', 'text');
    }

    /**
     * @return array<string,array{string}>
     */
    public static function fixedAreas(): array
    {
        return [
            'the title'       => ['core.ticket.title'],
            'the description' => ['core.ticket.description'],
            'the comments'    => ['core.ticket.comments'],
        ];
    }

    /** These are what a ticket is. An extension may add beside them, never instead. */
    #[DataProvider('fixedAreas')]
    public function testAFixedTicketAreaCannotBeReplaced(string $id): void
    {
        $this->expectException(LogicException::class);

        extensions()->ui()->add(
            new UiContribution($id, 'ticket.main.widgets', 'example-a/ticket-widget'),
            true,
        );
    }

    #[DataProvider('fixedAreas')]
    public function testAFixedTicketAreaCannotBeRemoved(string $id): void
    {
        $this->expectException(LogicException::class);

        extensions()->ui()->remove($id);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function fixedFieldNames(): array
    {
        return ['title' => ['title'], 'description' => ['description'], 'comments' => ['comments']];
    }

    #[DataProvider('fixedFieldNames')]
    public function testAFieldCannotTakeTheNameOfAFixedOne(string $key): void
    {
        $this->expectException(LogicException::class);

        extensions()->ticketFields()->add(new TicketFieldDefinition($key, 'X', 'text'));
    }
}
