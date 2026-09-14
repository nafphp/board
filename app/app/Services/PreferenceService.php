<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use PDO;

final class PreferenceService
{
    public function __construct(private PDO $pdo, private Access $access)
    {
    }
    public function save(array $data): void
    {
        $user = $this->access->actor();
        $theme = $data['theme'] ?? 'system';
        $locale = $data['locale'] ?? 'de';
        $zone = $data['timezone'] ?? 'Europe/Berlin';
        if (!in_array($theme, ['light','dark','system'], true) || !in_array($locale, ['de','en'], true) || !is_string($zone) || !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            throw new Failure('Ungültige Einstellungen.');
        }
        $values = [$theme,$locale,$zone,isset($data['notify_in_app']) ? 1 : 0,isset($data['notify_mail']) ? 1 : 0,$user];
        $sql = 'INSERT INTO user_preferences(theme,locale,timezone,notify_in_app,notify_mail,user_id) VALUES(?,?,?,?,?,?)';
        $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE theme=VALUES(theme),locale=VALUES(locale),timezone=VALUES(timezone),notify_in_app=VALUES(notify_in_app),notify_mail=VALUES(notify_mail)' : ' ON CONFLICT(user_id) DO UPDATE SET theme=excluded.theme,locale=excluded.locale,timezone=excluded.timezone,notify_in_app=excluded.notify_in_app,notify_mail=excluded.notify_mail';
        $this->pdo->prepare($sql)->execute($values);
    }
    public function mute(int $project, bool $muted): void
    {
        $this->access->project($project);
        $sql = 'INSERT INTO project_preferences(project_id,user_id,muted) VALUES(?,?,?)';
        $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE muted=VALUES(muted)' : ' ON CONFLICT(project_id,user_id) DO UPDATE SET muted=excluded.muted';
        $this->pdo->prepare($sql)->execute([$project,$this->access->actor(),$muted ? 1 : 0]);
    }
}
