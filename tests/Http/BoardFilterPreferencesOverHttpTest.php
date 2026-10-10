<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

final class BoardFilterPreferencesOverHttpTest extends AcceptanceTestCase
{
    private const array FILTERS = ['column', 'swimlane', 'assignee', 'label', 'priority', 'status'];

    protected function tearDown(): void
    {
        $this->post($this->alice, '/api/settings/user', ['resetKeys' => ['board_filters']]);
        parent::tearDown();
    }

    public function testEveryCoreFilterHasItsOwnControlByDefault(): void
    {
        $page = $this->page($this->alice, '/projects/' . self::PROJECT);
        $this->assertStringNotContainsString('extra-filters', $page);
        $this->assertEqualsCanonicalizing(self::FILTERS, $this->controls($this->alice));
        $this->assertStringContainsString('data-profile-section="board_filters"', $page);
        $this->assertStringNotContainsString('>filter_list</span>', $page);
    }

    public function testVisibilityIsSavedForThisPersonWithoutChangingTheBoardQuery(): void
    {
        $this->save(['priority' => false, 'swimlane' => false]);
        $this->assertNotContains('priority', $this->controls($this->alice));
        $this->assertNotContains('swimlane', $this->controls($this->alice));
        $this->assertContains('priority', $this->controls($this->viewer));

        $all      = $this->cards('');
        $filtered = $this->cards('?priority=low');
        $this->assertLessThan($all['total'], $filtered['total']);
        $this->assertContains('priority', $this->controls($this->alice, '?priority=low'));
        $this->assertNotContains('priority', $this->controls($this->alice));
    }

    public function testAllFiltersCanBeHiddenWhileSearchStaysAvailable(): void
    {
        $this->save(array_fill_keys(self::FILTERS, false));
        $this->assertSame([], $this->controls($this->alice));
        $page = $this->page($this->alice, '/projects/' . self::PROJECT);
        $dom  = new DOMDocument();
        @$dom->loadHTML($page);
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@type="search" and @name="q"]')->length);
        $this->assertSame(0, $xpath->query('//form[@class="filterbar"]//input[@name="q"]')->length);
        $this->assertCount(1, $this->controls($this->alice, '?status=closed'));

        @$dom->loadHTML($this->page($this->alice, '/projects/' . self::PROJECT . '?q=Projekt'));
        $query = (new DOMXPath($dom))->query('//form[@class="filterbar"]//input[@name="q" and @type="hidden"]');
        $this->assertSame(1, $query->length);
        $this->assertSame('Projekt', $query->item(0)->getAttribute('value'));
    }

    public function testTheNativeSwitchFormAndEnhancedFormCanSaveFalse(): void
    {
        $token  = $this->alice->token($this->page($this->alice, '/preferences'));
        $body   = http_build_query(['_csrf' => $token, 'values' => ['board_filters' => ['column' => '0', 'status' => '1']]]);
        $native = $this->alice->request('/api/settings/user', $body, ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame(303, $native['status']);
        $this->assertNotContains('column', $this->controls($this->alice));
        $this->assertContains('status', $this->controls($this->alice));

        $enhanced = $this->alice->request('/api/settings/user', $body, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        $this->assertSame(200, $enhanced['status']);
        $this->assertSame(false, json_decode($enhanced['body'], true)['values']['board_filters']['column']);
    }

    public function testUnknownFiltersAndMalformedSwitchesAreRefusedAtomically(): void
    {
        $this->save(['priority' => false]);
        foreach ([['invented' => false], ['column' => []], ['column' => 'yes'], ['q' => false]] as $invalid) {
            $answer = $this->post($this->alice, '/api/settings/user', ['values' => ['board_filters' => $invalid]]);
            $this->assertSame(422, $answer['status']);
            $this->assertNotContains('priority', $this->controls($this->alice));
        }
    }

    public function testResetRestoresTheDefaultVisibility(): void
    {
        $this->save(['priority' => false]);
        $answer = $this->post($this->alice, '/api/settings/user', ['resetKeys' => ['board_filters']]);
        $this->assertSame(200, $answer['status']);
        $this->assertEqualsCanonicalizing(self::FILTERS, $this->controls($this->alice));
    }

    private function save(array $visibility): void
    {
        $answer = $this->post($this->alice, '/api/settings/user', ['values' => ['board_filters' => $visibility]]);
        $this->assertSame(200, $answer['status'], $answer['body']);
    }

    private function controls(HttpClient $client, string $query = ''): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($this->page($client, '/projects/' . self::PROJECT . $query));
        $nodes = (new DOMXPath($dom))->query('//form[@class="filterbar"]//select');

        return array_map(static fn($node) => $node->getAttribute('name'), iterator_to_array($nodes));
    }

    private function cards(string $query): array
    {
        $answer = $this->alice->request('/projects/' . self::PROJECT . '/cards' . $query);
        $this->assertSame(200, $answer['status']);

        return json_decode($answer['body'], true);
    }
}
