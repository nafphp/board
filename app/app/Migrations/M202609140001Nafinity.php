<?php

declare(strict_types=1);

namespace App\Migrations;

use Naf\Database\Core\AbstractMigration;
use PDO;

final class M202609140001Nafinity extends AbstractMigration
{
    public function up(PDO $connection): void
    {
        $mysql  = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id     = $mysql ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'BIGSERIAL PRIMARY KEY';
        $tables = [
            'users'    => "id $id, name VARCHAR(120) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,password_hash VARCHAR(255) NULL,active SMALLINT NOT NULL DEFAULT 1,global_role VARCHAR(20) NOT NULL DEFAULT 'user',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'projects' => "id $id,name VARCHAR(120) NOT NULL,description TEXT NOT NULL,color VARCHAR(7) NOT NULL,icon VARCHAR(8) NOT NULL DEFAULT 'N',created_by BIGINT NOT NULL REFERENCES users(id),created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,archived_at TIMESTAMP NULL",
            'project_members'
                            => "project_id BIGINT NOT NULL,user_id BIGINT NOT NULL,role VARCHAR(20) NOT NULL,active SMALLINT NOT NULL DEFAULT 1,PRIMARY KEY(project_id,user_id),FOREIGN KEY(project_id) REFERENCES projects(id),FOREIGN KEY(user_id) REFERENCES users(id),CHECK(role IN ('owner','manager','member','viewer'))",
            'boards'        => "id $id,project_id BIGINT NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,revision BIGINT NOT NULL DEFAULT 1,next_number BIGINT NOT NULL DEFAULT 1,UNIQUE(project_id,id),FOREIGN KEY(project_id) REFERENCES projects(id)",
            'board_columns' => "id $id,project_id BIGINT NOT NULL,board_id BIGINT NOT NULL,name VARCHAR(80) NOT NULL,color VARCHAR(7) NOT NULL,position BIGINT NOT NULL,closes_tickets SMALLINT NOT NULL DEFAULT 0,wip_limit INTEGER NULL,UNIQUE(project_id,board_id,id),FOREIGN KEY(project_id,board_id) REFERENCES boards(project_id,id)",
            'swimlanes'     => "id $id,project_id BIGINT NOT NULL,board_id BIGINT NOT NULL,name VARCHAR(80) NOT NULL,position BIGINT NOT NULL,is_default SMALLINT NOT NULL DEFAULT 0,UNIQUE(project_id,board_id,id),FOREIGN KEY(project_id,board_id) REFERENCES boards(project_id,id)",
            'tickets'       => "id $id,project_id BIGINT NOT NULL,board_id BIGINT NOT NULL,column_id BIGINT NOT NULL,swimlane_id BIGINT NOT NULL,number BIGINT NOT NULL,title VARCHAR(200) NOT NULL,description TEXT NOT NULL,priority VARCHAR(12) NOT NULL DEFAULT 'normal',color VARCHAR(7) NOT NULL DEFAULT '#6366f1',due_date DATE NULL,status VARCHAR(10) NOT NULL DEFAULT 'open',created_by BIGINT NOT NULL,created_at TIMESTAMP NOT NULL,updated_at TIMESTAMP NOT NULL,closed_at TIMESTAMP NULL,archived_at TIMESTAMP NULL,position BIGINT NOT NULL,version BIGINT NOT NULL DEFAULT 1,UNIQUE(project_id,id),UNIQUE(project_id,number),UNIQUE(project_id,board_id,column_id,swimlane_id,position),FOREIGN KEY(project_id,board_id) REFERENCES boards(project_id,id),FOREIGN KEY(project_id,board_id,column_id) REFERENCES board_columns(project_id,board_id,id),FOREIGN KEY(project_id,board_id,swimlane_id) REFERENCES swimlanes(project_id,board_id,id),FOREIGN KEY(project_id,created_by) REFERENCES project_members(project_id,user_id),CHECK(status IN ('open','closed')),CHECK(priority IN ('low','normal','high','urgent'))",
            'labels'        => "id $id,project_id BIGINT NOT NULL,name VARCHAR(60) NOT NULL,color VARCHAR(7) NOT NULL,UNIQUE(project_id,id),UNIQUE(project_id,name),FOREIGN KEY(project_id) REFERENCES projects(id)",
            'ticket_labels'
                => 'project_id BIGINT NOT NULL,ticket_id BIGINT NOT NULL,label_id BIGINT NOT NULL,PRIMARY KEY(project_id,ticket_id,label_id),FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id),FOREIGN KEY(project_id,label_id) REFERENCES labels(project_id,id)',
            'ticket_assignees'
                          => 'project_id BIGINT NOT NULL,ticket_id BIGINT NOT NULL,user_id BIGINT NOT NULL,PRIMARY KEY(project_id,ticket_id,user_id),FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id),FOREIGN KEY(project_id,user_id) REFERENCES project_members(project_id,user_id)',
            'comments'    => "id $id,project_id BIGINT NOT NULL,ticket_id BIGINT NOT NULL,author_id BIGINT NOT NULL,body TEXT NOT NULL,created_at TIMESTAMP NOT NULL,updated_at TIMESTAMP NOT NULL,deleted_at TIMESTAMP NULL,version BIGINT NOT NULL DEFAULT 1,UNIQUE(project_id,id),FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id),FOREIGN KEY(project_id,author_id) REFERENCES project_members(project_id,user_id)",
            'activities'  => "id $id,project_id BIGINT NOT NULL,ticket_id BIGINT NULL,actor_id BIGINT NOT NULL,event_type VARCHAR(80) NOT NULL,payload TEXT NOT NULL,created_at TIMESTAMP NOT NULL,FOREIGN KEY(project_id) REFERENCES projects(id),FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id),FOREIGN KEY(actor_id) REFERENCES users(id)",
            'attachments' => "id $id,project_id BIGINT NOT NULL,ticket_id BIGINT NOT NULL,uploaded_by BIGINT NOT NULL,storage_key VARCHAR(100) NOT NULL UNIQUE,original_name VARCHAR(255) NOT NULL,mime_type VARCHAR(150) NOT NULL,byte_size BIGINT NOT NULL,sha256 VARCHAR(64) NOT NULL,state VARCHAR(10) NOT NULL,created_at TIMESTAMP NOT NULL,FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id),FOREIGN KEY(project_id,uploaded_by) REFERENCES project_members(project_id,user_id),CHECK(state IN ('staged','ready','deleting'))",
            'user_preferences'
                => "user_id BIGINT PRIMARY KEY,theme VARCHAR(10) NOT NULL DEFAULT 'system',locale VARCHAR(10) NOT NULL DEFAULT 'de',timezone VARCHAR(60) NOT NULL DEFAULT 'Europe/Berlin',notify_in_app SMALLINT NOT NULL DEFAULT 1,notify_mail SMALLINT NOT NULL DEFAULT 0,FOREIGN KEY(user_id) REFERENCES users(id)",
            'project_preferences'
                            => 'project_id BIGINT NOT NULL,user_id BIGINT NOT NULL,muted SMALLINT NOT NULL DEFAULT 0,PRIMARY KEY(project_id,user_id),FOREIGN KEY(project_id,user_id) REFERENCES project_members(project_id,user_id)',
            'notifications' => "id $id,project_id BIGINT NOT NULL,ticket_id BIGINT NULL,user_id BIGINT NOT NULL,activity_id BIGINT NOT NULL,title VARCHAR(255) NOT NULL,read_at TIMESTAMP NULL,created_at TIMESTAMP NOT NULL,UNIQUE(user_id,activity_id),FOREIGN KEY(project_id,user_id) REFERENCES project_members(project_id,user_id),FOREIGN KEY(project_id,ticket_id) REFERENCES tickets(project_id,id),FOREIGN KEY(activity_id) REFERENCES activities(id)",
            'notification_deliveries'
                => 'notification_id BIGINT NOT NULL,channel VARCHAR(20) NOT NULL,state VARCHAR(20) NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,last_error VARCHAR(255) NULL,sent_at TIMESTAMP NULL,PRIMARY KEY(notification_id,channel),FOREIGN KEY(notification_id) REFERENCES notifications(id)',
            'ldap_identities'
                => 'directory VARCHAR(190) NOT NULL,subject VARCHAR(190) NOT NULL,user_id BIGINT NOT NULL,PRIMARY KEY(directory,subject),FOREIGN KEY(user_id) REFERENCES users(id)',
        ];
        foreach ($tables as $table => $definition) {
            $connection->exec(
                "CREATE TABLE IF NOT EXISTS $table ($definition)"
                    . ($mysql
                        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                        : ''),
            );
        }
        $indexes = [
            'idx_tickets_project_updated'
                => 'CREATE INDEX idx_tickets_project_updated ON tickets(project_id,updated_at)',
            'idx_activity_project_ticket'
                => 'CREATE INDEX idx_activity_project_ticket ON activities(project_id,ticket_id,id)',
            'idx_notifications_user'
                => 'CREATE INDEX idx_notifications_user ON notifications(user_id,read_at,id)',
            'idx_comments_ticket'
                => 'CREATE INDEX idx_comments_ticket ON comments(project_id,ticket_id,id)',
            'idx_members_user'
                => 'CREATE INDEX idx_members_user ON project_members(user_id,active,project_id)',
        ];
        foreach ($indexes as $name => $sql) {
            $this->index($connection, $name, $sql, $mysql);
        }
        $this->index(
            $connection,
            'idx_tickets_search',
            $mysql
                ? 'CREATE FULLTEXT INDEX idx_tickets_search ON tickets(title,description)'
                : "CREATE INDEX idx_tickets_search ON tickets USING GIN (to_tsvector('simple', title || ' ' || description))",
            $mysql,
        );
    }

    private function index(PDO $pdo, string $name, string $sql, bool $mysql): void
    {
        if ($mysql) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name=?',
            );
            $statement->execute([$name]);
        } else {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM pg_indexes WHERE schemaname=current_schema() AND indexname=?',
            );
            $statement->execute([$name]);
        }
        if (!(int) $statement->fetchColumn()) {
            $pdo->exec($sql);
        }
    }

    public function down(PDO $connection): void
    {
        foreach (
            [
                'ldap_identities',
                'notification_deliveries',
                'notifications',
                'project_preferences',
                'user_preferences',
                'attachments',
                'activities',
                'comments',
                'ticket_assignees',
                'ticket_labels',
                'labels',
                'tickets',
                'swimlanes',
                'board_columns',
                'boards',
                'project_members',
                'projects',
                'users',
            ] as $table
        ) {
            $connection->exec('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
