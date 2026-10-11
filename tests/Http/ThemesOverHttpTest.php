<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Support\Themes;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use Naf\Board\Tests\Support\TestServer;
use Nyholm\Psr7\Uri;

final class ThemesOverHttpTest extends AcceptanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->post($this->alice, '/api/settings/application', ['resetKeys' => [Themes::KEY]]);
    }

    protected function tearDown(): void
    {
        $this->post($this->alice, '/api/settings/application', ['resetKeys' => [Themes::KEY]]);
        foreach ([$this->alice, $this->bob] as $client) {
            $this->user($client, ['resetKeys' => ['palette']]);
        }
        parent::tearDown();
    }

    public function testPublishedThemesArePersonalSelectableAndCssIsVersionedAndShared(): void
    {
        $this->create();
        $id = $this->id();
        self::assertSame(200, $this->user($this->alice, ['values' => ['palette' => $id, 'theme' => 'dark']])['status']);
        $page = $this->page($this->alice, '/projects');
        self::assertStringContainsString('data-palette="' . $id . '"', $page);
        $url = $this->firstMatch('#href="(/themes/[^"]+)"#', $page, 'theme stylesheet');
        $css = $this->guest('theme-public')->request($url);
        self::assertSame(200, $css['status'], $css['body']);
        self::assertMatchesRegularExpression('#Content-Type: text/css#i', $css['headers']);
        self::assertMatchesRegularExpression('#X-Content-Type-Options: nosniff#i', $css['headers']);
        self::assertStringContainsString('--accent:#112233', $css['body']);
        self::assertStringContainsString('.theme-sample-card { border-width: 3px; }', $css['body']);
        self::assertStringNotContainsString('data-palette="' . $id . '"', $this->page($this->bob, '/projects'));
        self::assertSame(200, $this->save(['action' => 'default', 'id' => $id])['status']);
        $this->user($this->bob, ['values' => ['palette' => 'inherit']]);
        self::assertStringContainsString('data-palette="' . $id . '"', $this->page($this->bob, '/projects'));
        self::assertStringContainsString($url, $this->page($this->guest('theme-login'), '/login'));
        self::assertSame(200, $this->create(['id' => $id, 'light' => ['accent' => '#445566']])['status']);
        $newUrl = $this->firstMatch('#href="(/themes/[^"]+)"#', $this->page($this->alice, '/projects'), 'new version');
        self::assertNotSame($url, $newUrl);
        self::assertSame(404, $this->guest('theme-old')->request($url)['status']);
        self::assertSame(200, $this->guest('theme-new')->request($newUrl)['status']);
    }

    public function testDraftsDeletionAndSafeModeHavePredictableFallbacks(): void
    {
        $this->create();
        $id = $this->id();
        $this->user($this->alice, ['values' => ['palette' => $id]]);
        self::assertSame(200, $this->create(['id' => $id, 'enabled' => '0'])['status']);
        self::assertSame(422, $this->user($this->bob, ['values' => ['palette' => $id]])['status']);
        self::assertSame(422, $this->save(['action' => 'default', 'id' => $id])['status']);
        self::assertStringContainsString('data-palette="classic"', $this->page($this->alice, '/projects'));
        $this->create(['id' => $id]);
        self::assertSame(200, $this->save(['action' => 'default', 'id' => $id])['status']);
        $safe = $this->page($this->alice, '/settings?appearance=default');
        self::assertStringNotContainsString('data-theme-stylesheet', $safe);
        self::assertStringContainsString('data-palette="classic"', $safe);
        self::assertSame(422, $this->save(['action' => 'delete', 'id' => $id])['status']);
        $this->save(['action' => 'default', 'id' => 'anthracite']);
        self::assertSame(200, $this->save(['action' => 'delete', 'id' => $id])['status']);
        self::assertStringContainsString('data-palette="anthracite"', $this->page($this->alice, '/projects'));
    }

    public function testPreviewAndExportRequireAdminAndCsrfAndDoNotSave(): void
    {
        $before  = $this->alice->request('/api/settings/application')['body'];
        $preview = $this->save($this->draft(), $this->alice, '/settings/themes/preview');
        self::assertSame(200, $preview['status'], $preview['body']);
        self::assertStringContainsString('--accent:#112233', json_decode($preview['body'], true)['css']);
        self::assertSame($before, $this->alice->request('/api/settings/application')['body']);
        self::assertSame(403, $this->save($this->draft(), $this->bob, '/settings/themes/preview')['status']);
        self::assertSame(400, $this->alice->request('/settings/themes/preview', $this->draft())['status']);
        $sample = $this->alice->request('/settings/themes/sample');
        self::assertSame(200, $sample['status']);
        self::assertMatchesRegularExpression("#Content-Security-Policy: default-src 'none'#i", $sample['headers']);
        $settingsPage = $this->page($this->alice, '/settings');
        self::assertStringContainsString('sandbox="allow-same-origin"', $settingsPage);
        self::assertMatchesRegularExpression('/srcdoc="&lt;!doctype html&gt;.*?data-theme-frame/s', $settingsPage);
        self::assertStringNotContainsString('src="/settings/themes/sample"', $settingsPage);
        $policy = Themes::previewPolicy(new Uri(TestServer::url()));
        self::assertStringContainsString('Content-Security-Policy: ' . $policy, $sample['headers']);
        $inlinePreview = $this->firstMatch('/srcdoc="([^"]+)"[^>]*data-theme-frame/s', $settingsPage, 'inline preview');
        self::assertStringContainsString($policy, html_entity_decode(html_entity_decode($inlinePreview, ENT_QUOTES), ENT_QUOTES));
        foreach (['tokens', 'app', 'settings', 'icons'] as $asset) {
            self::assertStringContainsString('href="/assets/' . $asset . '.css?', $sample['body']);
        }
        self::assertSame(1, substr_count($settingsPage, 'id="theme-enabled"'));
        self::assertStringContainsString('for="theme-enabled"', $settingsPage);
        self::assertSame(403, $this->bob->request('/settings/themes/sample')['status']);
        $this->create();
        $export = $this->alice->request('/settings/themes/' . $this->id() . '/export');
        self::assertSame(200, $export['status']);
        $document = json_decode($export['body'], true);
        self::assertSame(1, $document['version']);
        self::assertSame('Studio', $document['theme']['name']);
        self::assertSame(403, $this->bob->request('/settings/themes/' . $this->id() . '/export')['status']);
    }

    public function testInvalidAndStaleChangesAreAtomicThroughBothWritePaths(): void
    {
        self::assertSame(403, $this->save($this->draft(), $this->bob)['status']);
        $this->create();
        $id   = $this->id();
        $page = $this->page($this->alice, '/settings');
        $old  = $this->firstMatch('/name="configurationRevision" value="([^"]+)"/', $page, 'revision');
        $this->create(['id' => $id, 'name' => 'Changed']);
        self::assertSame(409, $this->create(['id' => $id, 'configurationRevision' => $old])['status']);
        self::assertSame(422, $this->create(['id' => $id, 'css' => '@import "https://example.test/x";'])['status']);
        self::assertSame(422, $this->create(['id' => $id, 'common' => ['density' => 4]])['status']);
        $values                       = json_decode($this->alice->request('/api/settings/application')['body'], true)['values'];
        $catalog                      = $values[Themes::KEY];
        $catalog['items'][$id]['css'] = 'body { background: url(//example.test/a); }';
        self::assertSame(422, $this->post($this->alice, '/api/settings/application', ['values' => [Themes::KEY => $catalog]])['status']);
        $after = json_decode($this->alice->request('/api/settings/application')['body'], true)['values'][Themes::KEY];
        self::assertSame('Changed', $after['items'][$id]['name']);
        self::assertSame('.theme-sample-card { border-width: 3px; }', $after['items'][$id]['css']);
    }

    private function user(HttpClient $client, array $values): array
    {
        return $this->post($client, '/api/settings/user', $values, $client->token($this->page($client, '/preferences')));
    }

    private function draft(): array
    {
        return ['name' => 'Studio', 'base' => 'classic', 'enabled' => '1', 'common' => ['font-family' => 'serif', 'density' => '1.1'], 'light' => ['accent' => '#112233'], 'dark' => ['accent' => '#aabbcc'], 'css' => '.theme-sample-card { border-width: 3px; }'];
    }

    private function create(array $overrides = []): array
    {
        $response = $this->save($overrides + $this->draft());
        if ($overrides === []) {
            self::assertSame(200, $response['status'], $response['body']);
        }

        return $response;
    }

    private function id(): string
    {
        $values = json_decode($this->alice->request('/api/settings/application')['body'], true)['values'];

        return array_key_first($values[Themes::KEY]['items']);
    }

    private function save(array $values, ?HttpClient $client = null, string $url = '/settings/themes'): array
    {
        $client ??= $this->alice;
        $page = $this->page($client, $client === $this->alice ? '/settings' : '/preferences');

        return $client->request($url, $values + [
            '_csrf'                 => $client->token($page),
            'configurationRevision' => $client === $this->alice ? $this->firstMatch('/name="configurationRevision" value="([^"]+)"/', $page, 'revision') : 'none',
        ], $client->jsonHeaders($client->token($page)));
    }
}
