<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Support\Branding;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;
use PDO;

final class BrandingOverHttpTest extends AcceptanceTestCase
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j9T0AAAAASUVORK5CYII=';

    protected function tearDown(): void
    {
        $this->post($this->alice, '/api/settings/application', ['resetKeys' => [Branding::NAME, Branding::HOME, Branding::LOGO]]);
        parent::tearDown();
    }

    public function testTheLogoDefaultsToTheOverviewAndGuestsStillSignIn(): void
    {
        $response = $this->alice->request('/');
        self::assertSame(303, $response['status']);
        self::assertMatchesRegularExpression('#Location: /projects\r#i', $response['headers']);
        self::assertMatchesRegularExpression('#Location: /login\r#i', $this->guest('branding-home')->request('/')['headers']);
        $page = $this->page($this->alice, '/settings');
        self::assertStringContainsString('data-settings-open="branding"', $page);
        self::assertSame(1, substr_count($page, 'name="name" value="Nafinity"'));
        self::assertStringNotContainsString('data-setting-key="config.app:name"', $page, 'the advanced configuration must not duplicate branding');
    }

    public function testOneNameAppearsEscapedInTheShellAndPublicAccountPages(): void
    {
        $response = $this->save(['name' => 'Studio <em>North</em>', 'home_project' => '0']);
        self::assertSame(200, $response['status'], $response['body']);
        foreach ([
            $this->page($this->alice, '/projects'),
            $this->page($this->guest('branding-login'), '/login'),
            $this->guest('branding-invitation')->request('/invitations/' . str_repeat('a', 64))['body'],
            $this->guest('branding-password')->request('/password-reset/' . str_repeat('a', 64))['body'],
        ] as $page) {
            self::assertStringContainsString('· Studio &lt;em&gt;North&lt;/em&gt;</title>', $page);
            self::assertStringContainsString('<span class="brand-word">Studio &lt;em&gt;North&lt;/em&gt;</span>', $page);
            self::assertStringNotContainsString('Studio <em>', $page);
        }
    }

    public function testTheSelectedBoardDoesNotGrantAccessAndArchivedBoardsFallBack(): void
    {
        $response = $this->save(['name' => 'Nafinity', 'home_project' => (string) self::OTHER_PROJECT]);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertMatchesRegularExpression('#Location: /projects/2\r#i', $this->bob->request('/')['headers']);
        self::assertMatchesRegularExpression('#Location: /projects\r#i', $this->viewer->request('/')['headers']);

        $pdo = \Naf\app()->container()->get(PDO::class);

        try {
            $pdo->exec('UPDATE projects SET archived_at=NOW() WHERE id=2');
            self::assertMatchesRegularExpression('#Location: /projects\r#i', $this->bob->request('/')['headers']);
        } finally {
            $pdo->exec('UPDATE projects SET archived_at=NULL WHERE id=2');
        }

        self::assertSame(422, $this->save(['name' => 'Do not save', 'home_project' => '999999'])['status']);
        self::assertStringNotContainsString('Do not save', $this->page($this->alice, '/projects'));
    }

    public function testOnlyAnAdministratorWithCsrfCanChangeBrandingAndStaleEditsAreRefused(): void
    {
        $page     = $this->page($this->alice, '/settings');
        $revision = $this->revision($page);
        $response = $this->save(['name' => 'First saved name', 'home_project' => '0']);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame(409, $this->save(['name' => 'Stale name', 'home_project' => '0', 'configurationRevision' => $revision])['status']);
        self::assertSame(422, $this->save(['name' => '   ', 'home_project' => '0'])['status']);
        self::assertStringContainsString('First saved name', $this->page($this->alice, '/projects'));
        self::assertSame(403, $this->save(['name' => 'Forbidden', 'home_project' => '0'], $this->bob)['status']);
        self::assertSame(400, $this->alice->request('/settings/branding', ['name' => 'Missing CSRF'])['status']);
    }

    public function testRasterUploadIsPublicWhileOtherFilesStayPrivateAndRemoveRestoresTheSymbol(): void
    {
        try {
            $response = $this->upload(base64_decode(self::PNG));
            self::assertSame(200, $response['status'], $response['body']);
            $page = $this->page($this->guest('branding-logo'), '/login');
            self::assertStringNotContainsString('class="orbit-art"', $page);
            $url   = $this->firstMatch('#src="(/branding/logo/[^"]+)"#', $page, 'public logo');
            $image = $this->guest('branding-raster')->request($url);
            self::assertSame(200, $image['status']);
            self::assertSame(base64_decode(self::PNG), $image['body']);
            self::assertMatchesRegularExpression('#Content-Type: image/png#i', $image['headers']);
            self::assertMatchesRegularExpression('#X-Content-Type-Options: nosniff#i', $image['headers']);
            self::assertSame(404, $this->guest('branding-private')->request('/branding/logo/' . str_repeat('a', 64) . '.png')['status']);

            $response = $this->save(['name' => 'Nafinity', 'home_project' => '0', 'remove_logo' => '1']);
            self::assertSame(200, $response['status'], $response['body']);
            self::assertSame(404, $this->guest('branding-old')->request($url)['status']);
            self::assertStringNotContainsString('class="brand-image"', $this->page($this->alice, '/projects'));
        } finally {
            $this->save(['reset' => '1']);
        }
    }

    public function testReplacingALogoRemovesThePreviousFileAndPreservesOtherConfiguration(): void
    {
        $before = glob(BASE_PATH . '/storage/attachments/branding/*') ?: [];

        try {
            $this->post($this->alice, '/api/settings/application', ['values' => ['audit_retention_days' => 17]]);
            self::assertSame(200, $this->upload(base64_decode(self::PNG))['status']);
            $first = $this->firstMatch('#src="(/branding/logo/[^"]+)"#', $this->page($this->alice, '/projects'), 'first logo');
            self::assertSame(200, $this->upload(base64_decode(self::PNG))['status']);
            $second = $this->firstMatch('#src="(/branding/logo/[^"]+)"#', $this->page($this->alice, '/projects'), 'replacement logo');
            self::assertNotSame($first, $second);
            self::assertSame(404, $this->guest('branding-replaced')->request($first)['status']);
            self::assertSame(200, $this->guest('branding-replacement')->request($second)['status']);
            self::assertSame(count($before) + 1, count(glob(BASE_PATH . '/storage/attachments/branding/*') ?: []));
            $this->save(['reset' => '1']);
            self::assertSame($before, glob(BASE_PATH . '/storage/attachments/branding/*') ?: []);
            $values = json_decode($this->alice->request('/api/settings/application')['body'], true)['values'];
            self::assertSame(17, $values['audit_retention_days']);
        } finally {
            $this->save(['reset' => '1']);
            $this->post($this->alice, '/api/settings/application', ['resetKeys' => ['audit_retention_days']]);
        }
    }

    public function testInvalidOrStaleUploadsAreAtomicAndDoNotLeaveFilesBehind(): void
    {
        $before   = glob(BASE_PATH . '/storage/attachments/branding/*') ?: [];
        $response = $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        self::assertSame(422, $response['status'], $response['body']);
        self::assertSame(422, $this->upload(str_repeat('a', 524289))['status']);
        self::assertSame(422, $this->upload('')['status']);
        $tooWide = substr_replace(base64_decode(self::PNG), pack('N', 2049), 16, 4);
        self::assertSame(422, $this->upload($tooWide)['status']);
        self::assertSame(422, $this->upload(base64_decode(self::PNG), ['name' => ''])['status']);

        $revision = $this->revision($this->page($this->alice, '/settings'));
        $this->save(['name' => 'Changed elsewhere', 'home_project' => '0']);
        self::assertSame(409, $this->upload(base64_decode(self::PNG), ['configurationRevision' => $revision])['status']);
        self::assertSame($before, glob(BASE_PATH . '/storage/attachments/branding/*') ?: []);
        self::assertStringContainsString('Changed elsewhere', $this->page($this->alice, '/projects'));

        self::assertSame(422, $this->post($this->alice, '/api/settings/application', [
            'values' => [Branding::LOGO => '../ready/private', Branding::NAME => 'Invalid logo'],
        ])['status']);
        self::assertStringNotContainsString('Invalid logo', $this->page($this->alice, '/projects'));
    }

    public function testRestoreUsesTheServerDefaultsAndNativeFormsRedirectBackToTheCard(): void
    {
        self::assertSame(200, $this->save(['name' => 'Custom name', 'home_project' => '1'])['status']);
        $page     = $this->page($this->alice, '/settings');
        $response = $this->alice->request('/settings/branding', [
            '_csrf'                 => $this->alice->token($page),
            'configurationRevision' => $this->revision($page),
            'reset'                 => '1',
        ]);
        self::assertSame(303, $response['status'], $response['body']);
        self::assertMatchesRegularExpression('#Location: /settings\#branding#i', $response['headers']);
        self::assertMatchesRegularExpression('#Location: /projects\r#i', $this->alice->request('/')['headers']);
        self::assertStringContainsString('<span class="brand-word">Nafinity</span>', $this->page($this->alice, '/projects'));
    }

    private function revision(string $page): string
    {
        return $this->firstMatch('/name="configurationRevision" value="([^"]+)"/', $page, 'configuration revision');
    }

    private function save(array $values, ?HttpClient $client = null): array
    {
        $client ??= $this->alice;
        $page = $this->page($client, $client === $this->alice ? '/settings' : '/preferences');

        return $client->request('/settings/branding', $values + [
            '_csrf'                 => $client->token($page),
            'configurationRevision' => $client === $this->alice ? $this->revision($page) : str_repeat('a', 64),
        ], $client->jsonHeaders($client->token($page)));
    }

    private function upload(string $contents, array $values = []): array
    {
        $page     = $this->page($this->alice, '/settings');
        $boundary = 'branding-test-' . bin2hex(random_bytes(8));
        $body     = '';
        foreach ($values + [
            '_csrf'                 => $this->alice->token($page),
            'configurationRevision' => $this->revision($page),
            'name'                  => 'Nafinity',
            'home_project'          => '0',
        ] as $key => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"logo\"; filename=\"logo.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n{$contents}\r\n--{$boundary}--\r\n";

        return $this->alice->request('/settings/branding', $body, [
            'Content-Type: multipart/form-data; boundary=' . $boundary, 'Accept: application/json',
        ]);
    }
}
