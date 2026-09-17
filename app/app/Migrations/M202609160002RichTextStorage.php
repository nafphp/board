<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

/** The documented character limits must also fit multi-byte text and rich-text markup. */
final class M202609160002RichTextStorage extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $connection->exec('ALTER TABLE tickets MODIFY description MEDIUMTEXT NOT NULL, MODIFY description_html MEDIUMTEXT NULL');
        }
    }

    public function down(PDO $connection): void
    {
        if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $connection->exec('ALTER TABLE tickets MODIFY description TEXT NOT NULL, MODIFY description_html TEXT NULL');
        }
    }
}
