<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/**
 * Time is counted on the server, so a closed tab cannot lose it. A run keeps two numbers:
 * what the person has tracked here since starting, which is what a clock has to show and
 * may therefore never fall, and the seconds that do not add up to a whole minute yet,
 * which is what stops repeated short bursts from rounding away into nothing.
 */
final class M202609170002TicketTimers extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec('CREATE TABLE ticket_timers (
            project_id BIGINT NOT NULL,
            ticket_id BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            started_at TIMESTAMP NULL,
            carry_seconds INTEGER NOT NULL DEFAULT 0 CHECK (carry_seconds >= 0 AND carry_seconds < 60),
            tracked_seconds INTEGER NOT NULL DEFAULT 0 CHECK (tracked_seconds >= 0),
            state VARCHAR(8) NOT NULL DEFAULT \'stopped\',
            updated_at TIMESTAMP NOT NULL,
            PRIMARY KEY(project_id, ticket_id, user_id),
            FOREIGN KEY(project_id, ticket_id) REFERENCES tickets(project_id, id),
            FOREIGN KEY(project_id, user_id) REFERENCES project_members(project_id, user_id)
        )');
        $connection->exec('CREATE INDEX ix_timer_runner ON ticket_timers(user_id, state)');
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE ticket_timers');
    }
}
