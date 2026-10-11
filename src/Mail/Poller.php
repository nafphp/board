<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use PDO;

final class Poller
{
    public function __construct(
        private PDO $pdo,
        private Inbox $inbox,
        private Importer $importer,
        private Settings $settings,
    ) {
    }

    public function poll(bool $rescan = false): array
    {
        $counts = ['imported' => 0, 'duplicate' => 0, 'ignored' => 0];
        if (!$this->settings->enabled) {
            return $counts;
        }

        $key = $this->settings->mailboxKey();
        // A DB session lock also covers workers on different machines, without a
        // database transaction around slow network I/O. MySQL/MariaDB are supported.
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute(['board-mail:' . substr($key, 0, 50)]);
        if ((int) $lock->fetchColumn() !== 1) {
            return $counts;
        }

        try {
            $this->inbox->open();
            $validity = $this->inbox->uidValidity();
            $query    = $this->pdo->prepare('SELECT uid_validity,last_uid FROM board_mail_cursors WHERE mailbox_key=?');
            $query->execute([$key]);
            $cursor = $query->fetch(PDO::FETCH_ASSOC);
            $last   = !$rescan && $cursor && (int) $cursor['uid_validity'] === $validity ? (int) $cursor['last_uid'] : 0;

            $save = $this->pdo->prepare(
                'INSERT INTO board_mail_cursors(mailbox_key,uid_validity,last_uid) VALUES(?,?,?)'
                . ' ON DUPLICATE KEY UPDATE uid_validity=VALUES(uid_validity),last_uid=VALUES(last_uid)',
            );
            $save->execute([$key, $validity, $last]);

            foreach ($this->inbox->uidsAfter($last) as $uid) {
                $mail   = $this->inbox->read($uid);
                $result = $mail === null ? 'ignored' : $this->importer->import($mail);
                ++$counts[$result];
                if ($result !== 'ignored') {
                    // The comment and receipt are committed before flags/cursor.
                    $this->inbox->markSeen($uid);
                }
                $save->execute([$key, $validity, $uid]);
            }

            return $counts;
        } finally {
            try {
                $this->inbox->close();
            } finally {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['board-mail:' . substr($key, 0, 50)]);
            }
        }
    }
}
