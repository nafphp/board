<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\TicketLinkSearch;
use Naf\Board\Tests\Support\TicketDetailTestCase;

use function Naf\app;

final class TicketLinkSearchTest extends TicketDetailTestCase
{
    public function testSearchMatchesBareNumbersFullKeysAndTitles(): void
    {
        $this->tickets->update($this->project, $this->other, ['title' => 'Unique sunflower <script>'] + $this->current($this->other));
        $row = $this->tickets->ticket($this->project, $this->other);
        $key = $this->tickets->reference($this->project, $this->other);
        foreach ([(string) $row['number'], strtolower($key), 'sunflower'] as $query) {
            $results = $this->find($query);
            $this->assertSame((string) $row['number'], $results[0]['value']);
            $this->assertSame($key . ' · Unique sunflower <script>', $results[0]['label']);
        }
    }

    public function testSearchIsBoundedAndExcludesSelfArchivedAndLinkedTickets(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->createTicket();
        }
        $this->assertCount(12, $this->find(''));
        $own = (string) $this->row()['number'];
        $this->assertNotContains($own, array_column($this->find('Original'), 'value'));
        $other = (string) $this->tickets->ticket($this->project, $this->other)['number'];
        $this->tickets->link($this->project, $this->ticket, ['number' => $other] + $this->current());
        $this->assertNotContains($other, array_column($this->find(''), 'value'));
        $this->tickets->link($this->project, $this->ticket, ['number' => $other, 'action' => 'delete'] + $this->current());
        $this->tickets->state($this->project, $this->other, ['action' => 'archive'] + $this->current($this->other));
        $this->assertNotContains($other, array_column($this->find(''), 'value'));
    }

    public function testSearchTreatsSqlWildcardsAsLiteralText(): void
    {
        $this->assertSame([], $this->find('%'));
        $this->assertSame([], $this->find('_'));
    }

    public function testSearchRefusesForeignProjectsTicketsAndMalformedTerms(): void
    {
        foreach ([[], str_repeat('a', 121), "\xFF"] as $query) {
            $this->assertDenied(422, fn() => $this->find($query));
        }
        $this->assertDenied(404, fn() => app()->container()->make(TicketLinkSearch::class)->find($this->projectA, $this->ticket, ''));
        $this->actAs($this->bob);
        $this->assertDenied(404, fn() => $this->find('Original'));
    }

    private function find(mixed $query): array
    {
        return app()->container()->make(TicketLinkSearch::class)->find($this->project, $this->ticket, $query);
    }
}
