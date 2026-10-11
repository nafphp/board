<?php

declare(strict_types=1);

namespace Naf\Board\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** Preserve explicit palette choices; new preferences follow the installation standard. @internal */
final class M202610110001ThemePreferences extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec("ALTER TABLE user_preferences ALTER palette SET DEFAULT 'inherit'");
    }

    public function down(PDO $connection): void
    {
        $connection->exec("UPDATE user_preferences SET palette='classic' WHERE palette='inherit' OR palette LIKE 'theme-%'");
        $connection->exec("ALTER TABLE user_preferences ALTER palette SET DEFAULT 'classic'");
    }
}
