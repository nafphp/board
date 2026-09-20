<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Whether a person wants their board to change under them.
 *
 * Live updates are a help to most people and a distraction to some: a board that
 * rearranges itself while you are reading it is not what everybody wants from a
 * board. On by default, because that is what an installation switching the
 * socket on is asking for -- and off is one checkbox away.
 *
 * @internal
 */
final class M202609210004LiveUpdates extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE user_preferences ADD COLUMN live_updates SMALLINT NOT NULL DEFAULT 1',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE user_preferences DROP COLUMN live_updates');
    }
}
