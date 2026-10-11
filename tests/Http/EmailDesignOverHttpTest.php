<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Mail\Design;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

final class EmailDesignOverHttpTest extends AcceptanceTestCase
{
    protected function tearDown(): void
    {
        $this->post($this->alice, '/api/settings/application', ['resetKeys' => [Design::KEY]]);
        parent::tearDown();
    }

    public function testPreviewIsPrivateSandboxedAndDoesNotSaveOrSend(): void
    {
        $page = $this->page($this->alice, '/settings');
        self::assertStringContainsString('data-settings-open="email-design"', $page);
        self::assertStringContainsString('sandbox="allow-same-origin" tabindex="-1" data-email-preview', $page);
        $before                     = $this->alice->request('/api/settings/application')['body'];
        $outbox                     = glob(BASE_PATH . '/storage/mail/*.json') ?: [];
        $design                     = Design::DEFAULTS;
        $design['colors']['accent'] = '#112233';
        foreach (array_keys(Design::SAMPLES) as $sample) {
            $preview = $this->save(['design' => $design, 'sample' => $sample], $this->alice, '/settings/email-design/preview');
            self::assertSame(200, $preview['status'], $preview['body']);
            self::assertStringContainsString('background: #112233', json_decode($preview['body'], true)['html']);
            self::assertMatchesRegularExpression('#Cache-Control: private, no-store#i', $preview['headers']);
        }
        self::assertSame($before, $this->alice->request('/api/settings/application')['body']);
        self::assertSame($outbox, glob(BASE_PATH . '/storage/mail/*.json') ?: []);
        self::assertSame(403, $this->save(['design' => $design], $this->bob, '/settings/email-design/preview')['status']);
        self::assertSame(400, $this->alice->request('/settings/email-design/preview', ['design' => $design])['status']);
        self::assertSame(422, $this->save(['design' => $design, 'sample' => '../../login'], $this->alice, '/settings/email-design/preview')['status']);
    }

    public function testSaveResetStaleWritesAndGenericConfigurationApiShareValidation(): void
    {
        $page                       = $this->page($this->alice, '/settings');
        $revision                   = $this->firstMatch('/name="configurationRevision" value="([^"]+)"/', $page, 'revision');
        $design                     = Design::DEFAULTS;
        $design['colors']['accent'] = '#123456';
        $design['css']              = '.email-title { color: var(--accent); }';
        self::assertSame(200, $this->save(['design' => $design])['status']);
        self::assertEquals($design, json_decode($this->alice->request('/api/settings/application')['body'], true)['values'][Design::KEY]);
        self::assertSame(409, $this->save(['design' => Design::DEFAULTS, 'configurationRevision' => $revision])['status']);
        self::assertSame(403, $this->save(['design' => $design], $this->bob)['status']);
        $design['css'] = '@import "https://example.test/evil.css";';
        self::assertSame(422, $this->save(['design' => $design])['status']);
        self::assertSame(422, $this->post($this->alice, '/api/settings/application', ['values' => [Design::KEY => $design]])['status']);
        self::assertStringContainsString('#123456', $this->alice->request('/api/settings/application')['body']);
        self::assertSame(200, $this->save(['action' => 'reset'])['status']);
        self::assertEquals(Design::DEFAULTS, json_decode($this->alice->request('/api/settings/application')['body'], true)['values'][Design::KEY]);
    }

    private function save(array $values, ?HttpClient $client = null, string $path = '/settings/email-design'): array
    {
        $client ??= $this->alice;
        $page     = $this->page($client, $client === $this->alice ? '/settings' : '/preferences');
        $revision = $client === $this->alice ? $this->firstMatch('/name="configurationRevision" value="([^"]+)"/', $page, 'revision') : str_repeat('a', 64);

        return $client->request($path, $values + ['_csrf' => $client->token($page), 'configurationRevision' => $revision], $client->jsonHeaders($client->token($page)));
    }
}
