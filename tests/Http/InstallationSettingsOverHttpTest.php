<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Support\Settings\FileConfigurationStore;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use PDO;

final class InstallationSettingsOverHttpTest extends AcceptanceTestCase
{
    public function testConfigurationUsesTheSharedRendererAndShowsItsSources(): void
    {
        $page = $this->page($this->alice, '/settings');
        self::assertStringContainsString('data-settings-form="application"', $page);
        self::assertStringContainsString('notifications@example.test', $page);
        self::assertStringContainsString('name="values[audit_retention_days]"', $page);
        self::assertStringContainsString('Quelle', $page);
        self::assertStringContainsString('DB_PASSWORD', $page);
        self::assertStringNotContainsString('Unbekannter Feldtyp:', $page);
        self::assertDoesNotMatchRegularExpression('/type="password"[^>]*value="[^"]+"/', $page);
    }

    public function testAdvancedConfigurationIsGroupedBelowStandardFieldsAndDisabledInitially(): void
    {
        $page     = $this->page($this->alice, '/settings');
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML($page);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        self::assertSame(1, $xpath->query('//*[@data-settings-advanced-toggle and @role="switch" and not(@checked)]')->length);
        self::assertSame(1, $xpath->query('//*[@data-settings-advanced-group and @hidden]//*[@data-settings-open="configuration_custom_settings"]')->length);
        self::assertSame(1, $xpath->query('//fieldset[@data-settings-advanced-fields and @hidden and @disabled]//*[@data-setting-key="config.custom_settings:limit"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-setting-key="mail_enabled" and not(ancestor::fieldset[@data-settings-advanced-fields])]')->length);
        self::assertSame(1, $xpath->query('//fieldset[@data-settings-advanced-fields and @disabled]//*[@data-setting-key="config.mail:reply_to"]')->length);
        self::assertStringContainsString('name="values[config.custom_settings:enabled]"', $page);
        self::assertStringContainsString('name="values[config.custom_settings:steps]"', $page);
        self::assertStringNotContainsString('data-settings-advanced-toggle', $this->page($this->alice, '/preferences'));
    }

    public function testSavingStandardFieldsPreservesAnAdvancedHostOverride(): void
    {
        $key = 'config.custom_settings:limit';

        try {
            $response = $this->post($this->alice, '/api/settings/application', ['values' => [$key => 12]]);
            self::assertSame(200, $response['status'], $response['body']);
            $response = $this->post($this->alice, '/api/settings/application', ['values' => ['mail_from' => 'standard@example.test']]);
            self::assertSame(200, $response['status'], $response['body']);
            $values = json_decode($this->alice->request('/api/settings/application')['body'], true)['values'];
            self::assertSame(12, $values[$key]);
        } finally {
            $this->post($this->alice, '/api/settings/application', ['resetKeys' => [$key, 'mail_from']]);
        }
    }

    public function testInstallationSettingsCannotBeWrittenIntoPersonalPreferences(): void
    {
        $response = $this->post($this->alice, '/api/settings/user', [
            'values' => ['mail_from' => 'changed@example.test'],
        ]);
        self::assertSame(422, $response['status']);
    }

    public function testAnAdministratorCanOverrideAndResetAConfigValueAcrossRequests(): void
    {
        $key = 'config.app:name';

        try {
            $response = $this->post($this->alice, '/api/settings/application', [
                'values' => [$key => 'Administration override test'],
            ]);
            self::assertSame(200, $response['status'], $response['body']);
            self::assertStringContainsString('Administration override test', $this->page($this->alice, '/settings'));
            self::assertStringContainsString('Quelle: Administration', $this->page($this->alice, '/settings'));
        } finally {
            $response = $this->post($this->alice, '/api/settings/application', ['resetKeys' => [$key]]);
            self::assertSame(200, $response['status'], $response['body']);
        }
        self::assertStringNotContainsString('Administration override test', $this->page($this->alice, '/settings'));
    }

    public function testSecretsAreWriteOnlyAndAnEmptyFormFieldKeepsTheCurrentValue(): void
    {
        $key = 'config.mail:password';

        try {
            $response = $this->post($this->alice, '/api/settings/application', [
                'values' => [$key => ' private-test-secret '],
            ]);
            self::assertSame(200, $response['status'], $response['body']);
            self::assertStringNotContainsString('private-test-secret', $response['body']);
            self::assertStringNotContainsString('private-test-secret', $this->page($this->alice, '/settings'));
            $response = $this->post($this->alice, '/api/settings/application', [
                'values' => [$key => ''], 'modes' => [$key => 'administration'],
            ]);
            self::assertSame(200, $response['status'], $response['body']);
            $stored = (new FileConfigurationStore(BASE_PATH . '/storage/configuration'))->read();
            self::assertSame(' private-test-secret ', $stored['mail:password']);
        } finally {
            $this->post($this->alice, '/api/settings/application', ['resetKeys' => [$key]]);
        }
    }

    public function testOnlyAdministratorsCanReadOrWriteGlobalConfiguration(): void
    {
        self::assertSame(403, $this->bob->request('/api/settings/application')['status']);
        $response = $this->post($this->bob, '/api/settings/application', [
            'values' => ['config.app:name' => 'forbidden'],
        ], $this->bob->token($this->page($this->bob, '/preferences')));
        self::assertSame(403, $response['status'], $response['body']);
    }

    public function testInvalidFieldsAndMissingCsrfDoNotChangeConfiguration(): void
    {
        $response = $this->post($this->alice, '/api/settings/application', [
            'values' => ['config.app:name' => 'must-not-save', 'not-registered' => true],
        ]);
        self::assertSame(422, $response['status']);
        self::assertStringNotContainsString('must-not-save', $this->page($this->alice, '/settings'));
        $response = $this->alice->request(
            '/api/settings/application',
            ['values' => ['config.app:name' => 'without-csrf']],
            ['Content-Type: application/json', 'Accept: application/json'],
        );
        self::assertSame(400, $response['status']);
    }

    public function testBooleansAndNullOverridesKeepTheirMeaningAndAreAuditedWithoutValues(): void
    {
        try {
            $response = $this->post($this->alice, '/api/settings/application', [
                'values' => ['mail_enabled' => true, 'config.mail:reply_to' => null],
            ]);
            self::assertSame(200, $response['status'], $response['body']);
            $values = json_decode($this->alice->request('/api/settings/application')['body'], true)['values'];
            self::assertTrue($values['mail_enabled']);
            self::assertNull($values['config.mail:reply_to']);
            $response = $this->post($this->alice, '/api/settings/application', ['values' => ['mail_enabled' => false]]);
            self::assertSame(200, $response['status'], $response['body']);
            self::assertFalse(json_decode($response['body'], true)['values']['mail_enabled']);
            $payload = \Naf\app()->container()->get(PDO::class)->query(
                "SELECT payload FROM activities WHERE event_type='settings.changed' AND scope='' ORDER BY id DESC LIMIT 1",
            )->fetchColumn();
            self::assertSame(['keys' => ['mail_enabled'], 'reset' => []], json_decode($payload, true));
        } finally {
            $this->post($this->alice, '/api/settings/application', ['resetKeys' => ['mail_enabled', 'config.mail:reply_to']]);
        }
    }

    public function testInvalidCriticalConfigurationIsRejectedAsAWhole(): void
    {
        foreach (['config.database:driver' => 'unsupported', 'config.mail:transport' => 'Missing\\Transport'] as $key => $value) {
            $response = $this->post($this->alice, '/api/settings/application', [
                'values' => ['config.app:name' => 'invalid-batch', $key => $value],
            ]);
            self::assertSame(422, $response['status'], $response['body']);
            self::assertStringNotContainsString('invalid-batch', $this->page($this->alice, '/settings'));
        }
    }

    public function testNativeFieldModesCanOverrideAndRestoreMailConfiguration(): void
    {
        $key = 'mail_from';

        try {
            $response = $this->post($this->alice, '/api/settings/application', [
                'values' => [$key => 'override@example.test'],
                'modes'  => [$key => 'administration'],
            ]);
            self::assertSame(200, $response['status'], $response['body']);
            self::assertStringContainsString('override@example.test', $this->page($this->alice, '/settings'));
            $response = $this->post($this->alice, '/api/settings/application', [
                'values' => [$key => 'ignored@example.test'],
                'modes'  => [$key => 'configuration'],
            ]);
            self::assertSame(200, $response['status'], $response['body']);
            self::assertStringContainsString('notifications@example.test', $this->page($this->alice, '/settings'));
            self::assertStringNotContainsString('ignored@example.test', $this->page($this->alice, '/settings'));
        } finally {
            $this->post($this->alice, '/api/settings/application', ['resetKeys' => [$key]]);
        }
    }
}
