<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Domain\Failure;
use Naf\Board\Modules\CoreConfiguration;
use Naf\Board\Support\Settings\ApplicationConfiguration;
use Naf\Board\Support\Settings\FileConfigurationStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileConfigurationStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nafinity-config-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOverridesSurviveANewReaderWithoutPlaintextAndResetIndependently(): void
    {
        $store = new FileConfigurationStore($this->directory);
        self::assertSame([], $store->read());
        $store->update(fn(array $values) => ['mail:password' => ' secret with spaces ', 'csrf_validation' => false]);
        $reopened = new FileConfigurationStore($this->directory);
        self::assertSame(' secret with spaces ', $reopened->read()['mail:password']);
        self::assertFalse($reopened->read()['csrf_validation']);
        self::assertStringNotContainsString('secret with spaces', file_get_contents($this->directory . '/values.enc'));
        self::assertSame(0600, fileperms($this->directory . '/values.enc') & 0777);
        self::assertSame(0600, fileperms($this->directory . '/key') & 0777);
        $reopened->update(function (array $values): array {
            unset($values['mail:password']);
            $values['mail:smtp:port'] = 465;

            return $values;
        });
        self::assertSame(['csrf_validation' => false, 'mail:smtp:port' => 465], $store->read());
    }

    public function testFailedValidationDoesNotChangeTheStoredDocument(): void
    {
        $store = new FileConfigurationStore($this->directory);
        $store->update(fn() => ['app:name' => 'before']);

        try {
            $store->update(fn() => throw new RuntimeException('invalid'));
            self::fail('Validation failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('invalid', $exception->getMessage());
        }
        self::assertSame(['app:name' => 'before'], $store->read());
    }

    public function testTamperingFailsClosedWithRecoveryInstructions(): void
    {
        $store = new FileConfigurationStore($this->directory);
        $store->update(fn() => ['mail:password' => 'private']);
        file_put_contents($this->directory . '/values.enc', 'damaged');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NAFINITY_CONFIG_OVERRIDES=0');
        $store->read();
    }

    public function testRecoveryBypassDoesNotNeedAWorkingStorageOrDatabase(): void
    {
        $_ENV['NAFINITY_CONFIG_OVERRIDES'] = '0';

        try {
            self::assertSame([], (new ApplicationConfiguration())->load('/does/not/exist'));
        } finally {
            unset($_ENV['NAFINITY_CONFIG_OVERRIDES']);
        }
    }

    public function testStaleFormsCannotRemoveConcurrentOverrides(): void
    {
        $store = new FileConfigurationStore($this->directory);
        $store->update(fn() => ['app:name' => 'first']);
        $stale = $store->signature($store->read());
        $store->update(fn(array $values) => $values + ['mail:password' => 'concurrent']);

        try {
            $store->update(fn() => [], $stale);
            self::fail("A stale form erased another administrator's changes.");
        } catch (Failure $failure) {
            self::assertSame(409, $failure->status);
        }
        self::assertSame('concurrent', $store->read()['mail:password']);
    }

    public function testUnknownProviderSecretsIncludingStructuredValuesAreRedacted(): void
    {
        self::assertTrue(CoreConfiguration::sensitive('oauth:providers:example:client_secret', null));
        self::assertTrue(CoreConfiguration::sensitive('provider:clientSecret', null));
        self::assertTrue(CoreConfiguration::sensitive('provider:headers:Authorization', 'Bearer private'));
        self::assertTrue(CoreConfiguration::sensitive('ldap:connection:bindPassword', null));
        self::assertTrue(CoreConfiguration::sensitive('providers', [['api_key' => 'private']]));
        self::assertTrue(CoreConfiguration::sensitive('service:url', 'https://name:password@example.test'));
        self::assertFalse(CoreConfiguration::sensitive('auth:users:password_field', 'password_hash'));
    }
}
