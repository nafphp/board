<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Contracts\PreferenceServiceInterface;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\app;
use function Naf\Board\settings;

/**
 * Saving one setting saves one setting.
 *
 * Every field in the settings surface writes on its own, so a save carries what
 * changed and must leave the rest as it was. A save that quietly rewrote the
 * whole set would let the language picker reset somebody's theme -- which is
 * exactly the bug this shape prevents.
 *
 * And values belong to a person. Two people reading the same key get their own.
 */
final class PersonalSettingsTest extends ExtensionInstalledTestCase
{
    protected function tearDown(): void
    {
        // These write real values for real accounts, and the later phases read
        // some of them. Back to the defaults afterwards.
        settings()->save(['locale' => 'de', 'timezone' => 'Europe/Berlin'], ['theme']);
        parent::tearDown();
    }

    public function testAPartialSaveLeavesTheUntouchedFieldsAlone(): void
    {
        settings()->save(['theme' => 'dark', 'timezone' => 'UTC']);

        settings()->save(['theme' => 'light']);

        $values = settings()->all();
        $this->assertSame('light', $values['theme']);
        $this->assertSame('UTC', $values['timezone'], 'the untouched field was reset');
    }

    public function testTheLanguagePickerWritesOnlyTheLanguage(): void
    {
        settings()->save(['theme' => 'light', 'timezone' => 'UTC']);

        app()->container()->get(PreferenceServiceInterface::class)->language('en');

        $values = settings()->all();
        $this->assertSame('en', $values['locale']);
        $this->assertSame('light', $values['theme'], 'the language save reset the theme');
        $this->assertSame('UTC', $values['timezone'], 'the language save reset the time zone');
    }

    public function testAResetRestoresTheDefaultAndTouchesNothingElse(): void
    {
        settings()->save(['theme' => 'dark', 'timezone' => 'UTC']);

        settings()->save([], ['theme']);

        $this->assertSame('system', settings()->get('theme'), 'the default was not restored');
        $this->assertSame('UTC', settings()->get('timezone'), 'the reset reached another field');
    }

    /** A write is visible to the next read, without another request. */
    public function testAWriteIsVisibleToTheNextReadOfTheSameRequest(): void
    {
        settings()->save(['example.reports.compact' => false]);
        $this->assertFalse(settings()->get('example.reports.compact'));

        settings()->save(['example.reports.compact' => true]);

        $this->assertTrue(settings()->get('example.reports.compact'), 'the read was stale');
        $this->assertTrue(settings()->all()['example.reports.compact'], 'all() was stale');
    }

    public function testValuesBelongToWhoeverIsActing(): void
    {
        settings()->save(['theme' => 'dark']);

        $this->actAs('reviewer');
        $this->assertSame('system', settings()->get('theme'), "another person saw the first one's value");
        settings()->save(['theme' => 'light']);

        $this->actAs('alice');
        $this->assertSame('dark', settings()->get('theme'), 'the value changed under the first person');
    }
}
