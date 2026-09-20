<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\PreferenceService;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;

/**
 * The language picker in the top bar sends one field, not a whole settings form.
 *
 * So it needs a save of its own: the full one would reset the theme, the time
 * zone and both notification switches to their defaults, and someone switching
 * language would silently lose all four.
 */
final class PreferenceTest extends BoardTestCase
{
    private PreferenceService $preferences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preferences = app()->container()->make(PreferenceService::class);
        $this->preferences->save([
            'theme'         => 'dark',
            'locale'        => 'de',
            'timezone'      => 'Europe/Lisbon',
            'notify_in_app' => '1',
            'notify_mail'   => '1',
        ]);
    }

    public function testSwitchingTheLanguageChangesTheLanguage(): void
    {
        $this->preferences->language('en');

        $this->assertSame('en', $this->query->preferences()['locale']);
    }

    public function testSwitchingTheLanguageLeavesEveryOtherSettingAlone(): void
    {
        $this->preferences->language('en');

        $after = $this->query->preferences();
        $this->assertSame('dark', $after['theme'], 'the theme was reset by the language switch');
        $this->assertSame('Europe/Lisbon', $after['timezone'], 'the time zone was reset');
        $this->assertSame(1, (int) $after['notify_mail'], 'mail notifications were reset');
        $this->assertSame(1, (int) $after['notify_in_app'], 'in-app notifications were reset');
    }

    public function testALanguageNobodyOffersIsRefused(): void
    {
        $this->assertDenied(422, fn() => $this->preferences->language('xx'));
        $this->assertDenied(422, fn() => $this->preferences->language(null));
        $this->assertSame('de', $this->query->preferences()['locale'], 'the refused switch went through');
    }
}
