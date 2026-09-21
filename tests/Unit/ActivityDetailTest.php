<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\ActivityDetail;
use PHPUnit\Framework\TestCase;

use function Naf\I18n\t;

/**
 * What a recorded change says it was.
 *
 * The rows were always carrying this and the history never showed it, so these
 * tests are mostly about reading old writing: a payload from a version that did
 * not record where a card came from, a field this version has no name for, and
 * one that is not readable at all.
 */
final class ActivityDetailTest extends TestCase
{
    public function testAnEditNamesTheFieldsItTouched(): void
    {
        // Through t(), because what is under test is which fields are picked
        // and folded together, not which language this installation speaks.
        $this->assertSame(t('Beschreibung'), $this->of('ticket.updated', [
            // The editor stores rich text twice; naming it twice would describe
            // the storage rather than the edit.
            'fields'   => ['description', 'description_html'],
            'metadata' => [],
        ]));
        $this->assertSame(t('Titel') . ', ' . t('Priorität'), $this->of('ticket.updated', [
            'fields' => ['title', 'priority'],
        ]));
    }

    public function testAFieldWithNoNameKeepsItsOwn(): void
    {
        $this->assertSame('erfundenes_feld', $this->of('ticket.updated', ['fields' => ['erfundenes_feld']]));
    }

    public function testAMoveNamesBothEnds(): void
    {
        $this->assertSame('Offen → Erledigt', $this->of('ticket.moved', [
            'from_column' => 'Offen',
            'column'      => 'Erledigt',
        ]));
    }

    /** Rows written before the source was recorded still name their destination. */
    public function testAnOlderMoveNamesTheEndItKnows(): void
    {
        $this->assertSame('→ Erledigt', $this->of('ticket.moved', ['from' => 1, 'column' => 'Erledigt']));
    }

    public function testTheOtherKindsOfEntry(): void
    {
        $this->assertSame('„Test“', $this->of('ticket.created', ['title' => 'Test']));
        $this->assertSame('„notiz.txt“', $this->of('attachment.added', ['name' => 'notiz.txt']));
        $this->assertSame('viewer', $this->of('project.member_changed', ['role' => 'viewer']));
    }

    /** A payload this version cannot read gives nothing back rather than a guess. */
    public function testNothingIsInventedForWhatCannotBeRead(): void
    {
        $this->assertSame('', ActivityDetail::of(['event_type' => 'ticket.updated', 'payload' => 'kein JSON']));
        $this->assertSame('', ActivityDetail::of(['event_type' => 'ticket.archive', 'payload' => '[]']));
        $this->assertSame('', $this->of('etwas.unbekanntes', ['was' => 'auch immer']));
    }

    /** @param array<string,mixed> $payload */
    private function of(string $type, array $payload): string
    {
        return ActivityDetail::of([
            'event_type' => $type,
            'payload'    => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
