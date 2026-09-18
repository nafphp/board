<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Support\Locales;
use App\Support\Settings\PreferenceStore;
use DateTimeZone;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\PreferenceServiceInterface;

final class PreferenceService implements PreferenceServiceInterface
{
    public function __construct(private AccessInterface $access, private PreferenceStore $store)
    {
    }

    public function save(array $data): void
    {
        $user   = $this->access->actor();
        $theme  = $data['theme'] ?? 'system';
        $locale = $data['locale'] ?? 'de';
        $zone   = $data['timezone'] ?? 'Europe/Berlin';
        if (
            !in_array($theme, ['light', 'dark', 'system'], true)
            || !Locales::supports($locale)
            || !is_string($zone)
            || !in_array($zone, DateTimeZone::listIdentifiers(), true)
        ) {
            throw new Failure('Ungültige Einstellungen.');
        }
        $this->store->writeUser($user, [
            'theme'         => $theme,
            'locale'        => $locale,
            'timezone'      => $zone,
            'notify_in_app' => isset($data['notify_in_app']) ? 1 : 0,
            'notify_mail'   => isset($data['notify_mail']) ? 1 : 0,
        ]);
    }

    /**
     * Only the language, because the picker in the bar sends nothing else. Running it
     * through save() would reset theme, timezone and both notification switches to their
     * defaults, since that method writes every field it is given or not given.
     */
    public function language(mixed $locale): void
    {
        if (!Locales::supports($locale)) {
            throw new Failure('Diese Sprache steht nicht zur Verfügung.');
        }
        $this->store->writeUser($this->access->actor(), ['locale' => $locale]);
    }

    public function mute(int $project, bool $muted): void
    {
        $this->access->project($project);
        $this->store->writeProjectUser($project, $this->access->actor(), ['muted' => $muted ? 1 : 0]);
    }
}
