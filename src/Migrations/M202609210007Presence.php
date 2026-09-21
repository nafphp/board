<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Whether a person is willing to be seen on a board, and to see.
 *
 * Presence is the one thing the socket carries that is about a person rather
 * than about the work: which board they have open and which ticket they are
 * reading. Most teams want that; somebody who does not should not have to be
 * talked out of it, and turning it off here takes the presence channel out of
 * their token altogether -- so they are not merely hidden from the others, they
 * are not sending anything in the first place. It follows that they stop seeing
 * the others too, which is the honest half of the same switch.
 *
 * On by default, like live updates: an installation that switched the socket on
 * asked for a board that shows what is going on.
 *
 * @internal
 */
final class M202609210007Presence extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE user_preferences ADD COLUMN presence SMALLINT NOT NULL DEFAULT 1',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE user_preferences DROP COLUMN presence');
    }
}
