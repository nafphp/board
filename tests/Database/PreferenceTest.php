<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Contracts\SettingsServiceInterface;
use Naf\Board\Services\PreferenceService;
use Naf\Board\Support\SettingsContext;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;

/**
 * The language picker in the top bar sends one field, not a whole settings form.
 *
 * So it needs a save of its own: the full one would reset the palette, brightness,
 * time zone and notification switches to their defaults.
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
            'palette'       => 'anthracite',
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
        $this->assertSame('anthracite', $after['palette'], 'the palette was reset by the language switch');
        $this->assertSame('Europe/Lisbon', $after['timezone'], 'the time zone was reset');
        $this->assertSame(1, (int) $after['notify_mail'], 'mail notifications were reset');
        $this->assertSame(1, (int) $after['notify_in_app'], 'in-app notifications were reset');
    }

    public function testThePaletteAndBrightnessAreStoredSeparately(): void
    {
        $this->preferences->save(['palette' => 'classic', 'theme' => 'light']);

        $after = $this->query->preferences();
        $this->assertSame('classic', $after['palette']);
        $this->assertSame('light', $after['theme']);
    }

    public function testAnUnknownPaletteIsRefusedWithoutChangingAppearance(): void
    {
        $this->assertDenied(422, fn() => $this->preferences->save([
            'palette' => 'unknown',
            'theme'   => 'light',
        ]));

        $after = $this->query->preferences();
        $this->assertSame('anthracite', $after['palette']);
        $this->assertSame('dark', $after['theme']);
    }

    public function testTheSettingsApiCanTurnOffBooleanPreferencesWithoutResettingAppearance(): void
    {
        $settings = app()->container()->get(SettingsServiceInterface::class);
        $settings->save(SettingsContext::user((int) $this->alice->getId()), [
            'notify_in_app' => false,
            'notify_mail'   => false,
        ]);

        $after = $this->query->preferences();
        $this->assertSame(0, (int) $after['notify_in_app']);
        $this->assertSame(0, (int) $after['notify_mail']);
        $this->assertSame('dark', $after['theme']);
        $this->assertSame('anthracite', $after['palette']);
    }

    public function testAnArchivedProjectKeepsItsPersonalSettingsWhileSharedSettingsStayLocked(): void
    {
        $this->projects->archive($this->projectA, true);
        $settings = app()->container()->get(SettingsServiceInterface::class);
        $context  = SettingsContext::projectUser($this->projectA, (int) $this->alice->getId());

        foreach ([true, false] as $muted) {
            $settings->save($context, ['muted' => $muted]);
            $this->assertSame($muted, $settings->get($context, 'muted'));
            $listed = array_column($this->query->projects(), 'notifications_muted', 'id');
            $this->assertSame((int) $muted, (int) $listed[$this->projectA]);
        }

        $this->assertDenied(403, fn() => $settings->save(SettingsContext::project($this->projectA), [
            'name' => 'Changed archived project',
        ]));
    }

    public function testALanguageNobodyOffersIsRefused(): void
    {
        $this->assertDenied(422, fn() => $this->preferences->language('xx'));
        $this->assertDenied(422, fn() => $this->preferences->language(null));
        $this->assertSame('de', $this->query->preferences()['locale'], 'the refused switch went through');
    }
}
