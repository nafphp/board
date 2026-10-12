<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use DateTimeZone;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\PreferenceServiceInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\Placement;
use Naf\Board\Support\Locales;
use Naf\Board\Support\Settings\PreferenceStore;
use Naf\Board\Support\Themes;

use function Naf\I18n\t;

/** @internal */
final class PreferenceService implements PreferenceServiceInterface
{
    public function __construct(private AccessInterface $access, private PreferenceStore $store)
    {
    }

    public function save(array $data): void
    {
        $user        = $this->access->actor();
        $theme       = $data['theme'] ?? 'system';
        $palette     = $data['palette'] ?? 'inherit';
        $locale      = $data['locale'] ?? 'de';
        $zone        = $data['timezone'] ?? 'Europe/Berlin';
        $notifyScope = $data['notify_scope'] ?? 'all';
        if (
            !in_array($theme, ['light', 'dark', 'system'], true)
            || (!is_string($palette) || !isset(Themes::choices()[$palette]))
            || !Locales::supports($locale)
            || !is_string($zone)
            || !in_array($zone, DateTimeZone::listIdentifiers(), true)
            || !in_array($notifyScope, ['all', 'relevant'], true)
        ) {
            throw new Failure(t('Ungültige Einstellungen.'));
        }
        $this->store->writeUser($user, [
            'theme'         => $theme,
            'palette'       => $palette,
            'locale'        => $locale,
            'timezone'      => $zone,
            'notify_in_app' => isset($data['notify_in_app']) ? 1 : 0,
            'notify_mail'   => isset($data['notify_mail']) ? 1 : 0,
            'notify_scope'  => $notifyScope,
            'live_updates'  => isset($data['live_updates']) ? 1 : 0,
            'presence'      => isset($data['presence']) ? 1 : 0,
            'new_tickets'   => Placement::person($data['new_tickets'] ?? null),
        ]);
    }

    /**
     * Only the language, because the picker in the bar sends nothing else. Running it
     * through save() would reset palette, brightness, timezone and the notification switches to their
     * defaults, since that method writes every field it is given or not given.
     */
    public function language(mixed $locale): void
    {
        if (!Locales::supports($locale)) {
            throw new Failure(t('Diese Sprache steht nicht zur Verfügung.'));
        }
        $this->store->writeUser($this->access->actor(), ['locale' => $locale]);
    }

    public function mute(int $project, bool $muted): void
    {
        $this->access->project($project);
        $this->store->writeProjectUser($project, $this->access->actor(), ['muted' => $muted ? 1 : 0]);
    }
}
