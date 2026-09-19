<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

final class M202609160003ProjectTicketKey extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        // Named ticket_key because KEY is a reserved word in MySQL and would need quoting
        // in every statement, differently per driver.
        $connection->exec("ALTER TABLE projects ADD COLUMN ticket_key VARCHAR(6) NOT NULL DEFAULT ''");

        $projects = $connection->query('SELECT id,name FROM projects ORDER BY id')->fetchAll();
        $update   = $connection->prepare('UPDATE projects SET ticket_key=? WHERE id=?');
        $taken    = [];

        foreach ($projects as $project) {
            $key     = self::derive((string) $project['name'], $taken);
            $taken[] = $key;
            $update->execute([$key, $project['id']]);
        }
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE projects DROP COLUMN ticket_key');
    }

    /**
     * Letters of the name, upper case, falling back to a numbered key so that existing
     * projects keep distinct references.
     */
    private static function derive(string $name, array $taken): string
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
        $key     = substr($letters, 0, 3);
        if ($key === '') {
            $key = 'P';
        }
        $candidate = $key;
        $suffix    = 1;

        while (in_array($candidate, $taken, true)) {
            $suffix += 1;
            $candidate = substr($key, 0, 5) . $suffix;
        }

        return $candidate;
    }
}
