<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * The board as data, with its cards already rendered.
 *
 * Placement is JSON because the client owns it; a card is HTML because the
 * server owns that. The split is the whole point: the five extension slots on a
 * card are PHP, and so is every registry behind a priority symbol or an
 * estimate, so a card rendered in the browser would be a second implementation
 * of all of it.
 *
 * What these tests hold is that the endpoint answers the same board the page
 * does -- same cards, same filters, same refusals.
 */
final class BoardCardsOverHttpTest extends AcceptanceTestCase
{
    public function testItAnswersEveryCardOfTheBoardAndWhereItSits(): void
    {
        $payload = $this->cards($this->alice, '');

        $this->assertSame(200, $payload['status']);
        $this->assertGreaterThan(0, $payload['body']['total']);
        $this->assertCount($payload['body']['total'], $payload['body']['cards']);

        $placed = array_merge(...array_values($payload['body']['cells']));
        $this->assertEqualsCanonicalizing(
            array_keys($payload['body']['cards']),
            $placed,
            'a card was handed out without a cell, or a cell names one that was not',
        );
    }

    public function testACardIsRenderedMarkupRatherThanFields(): void
    {
        $body = $this->cards($this->alice, '')['body'];
        $card = reset($body['cards']);

        $this->assertStringContainsString('class="ticket-card"', $card);
        $this->assertStringContainsString('data-ticket=', $card);
        $this->assertStringContainsString('class="card-title"', $card, 'the card came without its title');
    }

    /** A filtered board answers filtered, so a live update cannot smuggle past one. */
    public function testTheFilterOnTheQueryNarrowsWhatComesBack(): void
    {
        $all      = $this->cards($this->alice, '')['body'];
        $filtered = $this->cards($this->alice, '?priority=low')['body'];

        $this->assertLessThan($all['total'], $filtered['total']);
        $this->assertCount($filtered['total'], $filtered['cards']);
    }

    public function testAPriorityNobodyDeclaredIsRefusedHereToo(): void
    {
        $this->assertSame(422, $this->cards($this->alice, '?priority=erfunden')['status']);
    }

    public function testAStrangerIsNotToldTheBoardExists(): void
    {
        $this->assertSame(404, $this->cards($this->bob, '')['status']);
    }

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    private function cards($client, string $query): array
    {
        $response = $client->request('/projects/' . self::PROJECT . '/cards' . $query);

        return [
            'status' => $response['status'],
            'body'   => json_decode($response['body'], true) ?? [],
        ];
    }
}
