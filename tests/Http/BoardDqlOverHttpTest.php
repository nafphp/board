<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;

final class BoardDqlOverHttpTest extends AcceptanceTestCase
{
    public function testModeTranslationRestoresDropdownsAndRetainsComplexExpressions(): void
    {
        $simple = $this->translation(['status' => 'open', 'assignee' => '1', 'priority' => 'high']);
        self::assertStringContainsString('status = "open"', $simple['expression']);
        self::assertEquals(['status' => 'open', 'assignee' => 1, 'priority' => 'high'], $simple['filters']);
        self::assertSame('', $simple['residual']);

        $advanced = $this->translation(['dql' => 'status = open AND assignee = me AND (priority = high OR priority = urgent)']);
        self::assertSame(['status' => 'open', 'assignee' => 'me'], $advanced['filters']);
        self::assertStringContainsString(' OR ', $advanced['residual']);
        self::assertStringContainsString('name="assignee"', $advanced['controls']);
        $page = $this->page($this->alice, '/projects/1?' . http_build_query(['dql' => 'status = open AND assignee = me', 'mode' => 'simple']));
        $dom  = new DOMDocument();
        @$dom->loadHTML($page);
        $xpath = new DOMXPath($dom);
        self::assertSame('me', $xpath->query('//form[@data-simple-filters]//select[@name="assignee"]/option[@selected]')->item(0)->getAttribute('value'));
        self::assertSame(0, $xpath->query('//form[@data-simple-filters and @hidden]')->length);
        self::assertSame(1, $xpath->query('//form[@data-advanced-filters and @hidden]')->length);
    }

    public function testInvalidQueriesStayEditableAndCannotShowUnfilteredCards(): void
    {
        foreach (['status =', 'invented = value', 'priority = invented', 'assignee > 1', 'due = 2026-02-30'] as $query) {
            $path = '/projects/1?' . http_build_query(['dql' => $query]);
            $page = $this->alice->request($path);
            self::assertSame(422, $page['status'], $query);
            self::assertStringContainsString('aria-invalid="true"', $page['body']);
            self::assertStringContainsString('data-filter-error', $page['body']);
            self::assertStringNotContainsString('class="ticket-card', $page['body']);
            $result = $this->alice->request('/projects/1/filters?' . http_build_query(['dql' => $query]));
            self::assertSame(422, $result['status']);
            self::assertArrayHasKey('dql', json_decode($result['body'], true)['errors']);
        }
        self::assertSame(422, $this->alice->request('/projects/1?dql[]=status')['status']);
        self::assertSame(404, $this->bob->request('/projects/1/filters?dql=status%20%3D%20open')['status']);
        self::assertSame(303, $this->guest('dql-guest')->request('/projects/1/filters')['status']);
    }

    public function testDqlLinksSavedViewsAndLiveCardsUseTheSameQuery(): void
    {
        $query = 'status = open AND assignee = me';
        $path  = '/projects/1?' . http_build_query(['dql' => $query]);
        $page  = $this->page($this->alice, $path);
        $saved = $this->post($this->alice, '/projects/1/views', [
            'revision' => $this->firstMatch('/name="revision" value="([a-f0-9]{64})"/', $page, 'view revision'),
            'name'     => 'DQL saved view', 'query' => ['dql' => $query],
        ]);
        self::assertSame(200, $saved['status']);
        $url = json_decode($saved['body'], true)['url'];
        self::assertStringContainsString('dql=', $url);
        self::assertStringContainsString('DQL saved view', $this->page($this->alice, $url));
        self::assertStringNotContainsString('DQL saved view', $this->page($this->viewer, $url));
        $cards = $this->alice->request('/projects/1/cards?' . http_build_query(['dql' => $query]));
        self::assertSame(200, $cards['status']);
        $result = json_decode($cards['body'], true);
        self::assertNotEmpty($result['cards']);
        self::assertSame($result['total'], count($result['cards']));
        foreach ($result['cards'] as $id => $html) {
            self::assertStringContainsString('data-ticket="' . $id . '"', $page);
            self::assertStringContainsString('data-ticket="' . $id . '"', $html);
        }
        $savedPage = $this->page($this->alice, $url);
        $dom       = new DOMDocument();
        @$dom->loadHTML($savedPage);
        $remove = (new DOMXPath($dom))->query('//div[@class="saved-view-row"][a[text()="DQL saved view"]]//button[@name="remove"]')->item(0);
        self::assertNotNull($remove);
        self::assertSame(200, $this->post($this->alice, '/projects/1/views', [
            'revision' => $this->firstMatch('/name="revision" value="([a-f0-9]{64})"/', $savedPage, 'view revision'),
            'remove'   => $remove->getAttribute('value'),
        ])['status']);
    }

    public function testTheUnfilteredBoardKeepsBothToolsCollapsed(): void
    {
        $page = $this->page($this->alice, '/projects/1');
        self::assertMatchesRegularExpression('/<details class="board-filter-disclosure"\s*>/', $page);
        self::assertStringContainsString('data-dialog-editors=".saved-views"', $page);
        self::assertStringContainsString('<template id="settings-editor-template">', $page);
        self::assertStringContainsString('aria-label="Ansichten schließen"', $page);
        self::assertStringContainsString('data-filter-mode="advanced"', $page);
        self::assertStringContainsString('aria-describedby="board-dql-help board-filter-error"', $page);
    }

    private function translation(array $query): array
    {
        $response = $this->alice->request('/projects/1/filters?' . http_build_query($query));
        self::assertSame(200, $response['status'], $response['body']);

        return json_decode($response['body'], true);
    }
}
