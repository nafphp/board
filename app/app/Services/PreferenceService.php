<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Support\Locales;
use DateTimeZone;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\PreferenceServiceInterface;
use PDO;

final class PreferenceService implements PreferenceServiceInterface
{
    public function __construct(private PDO $pdo, private AccessInterface $access)
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
        $values = [
            $theme,
            $locale,
            $zone,
            isset($data['notify_in_app']) ? 1 : 0,
            isset($data['notify_mail']) ? 1 : 0,
            $user,
        ];
        $sql = 'INSERT INTO user_preferences(theme,locale,timezone,notify_in_app,notify_mail,user_id) VALUES(?,?,?,?,?,?)';
        $sql
            .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE theme=VALUES(theme),locale=VALUES(locale),timezone=VALUES(timezone),notify_in_app=VALUES(notify_in_app),notify_mail=VALUES(notify_mail)'
                : ' ON CONFLICT(user_id) DO UPDATE SET theme=excluded.theme,locale=excluded.locale,timezone=excluded.timezone,notify_in_app=excluded.notify_in_app,notify_mail=excluded.notify_mail';
        $this->pdo->prepare($sql)->execute($values);
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
        $user = $this->access->actor();
        $sql  = 'INSERT INTO user_preferences(theme,locale,timezone,notify_in_app,notify_mail,user_id)'
            . " VALUES('system',?,'Europe/Berlin',1,0,?)";
        $sql
            .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE locale=VALUES(locale)'
                : ' ON CONFLICT(user_id) DO UPDATE SET locale=excluded.locale';
        $this->pdo->prepare($sql)->execute([$locale, $user]);
    }

    public function mute(int $project, bool $muted): void
    {
        $this->access->project($project);
        $sql = 'INSERT INTO project_preferences(project_id,user_id,muted) VALUES(?,?,?)';
        $sql
            .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? ' ON DUPLICATE KEY UPDATE muted=VALUES(muted)'
                : ' ON CONFLICT(project_id,user_id) DO UPDATE SET muted=excluded.muted';
        $this->pdo->prepare($sql)->execute([$project, $this->access->actor(), $muted ? 1 : 0]);
    }
}
