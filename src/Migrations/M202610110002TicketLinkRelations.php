<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** Existing pairs remain symmetric until a relationship is chosen. @internal */
final class M202610110002TicketLinkRelations extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec("ALTER TABLE ticket_links ADD COLUMN relation VARCHAR(24) NOT NULL DEFAULT 'relates', ADD COLUMN reversed BOOLEAN NOT NULL DEFAULT 0");
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE ticket_links DROP COLUMN reversed, DROP COLUMN relation');
    }
}
