<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\User;
use App\Services\ProjectService;
use App\Services\TicketService;
use Naf\Auth\Auth;
use Naf\Auth\Support\PasswordHasher;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\ORM\Core\EntityManager;
use PDO;
use RuntimeException;

use function Naf\app;

final class SeedCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:seed';

    protected function configure(): void
    {
        $this->setTitle('Create Nafinity demo projects')->setDescription(
            'Create local demo accounts and projects once; refuses production.',
        );
    }

    public function run(Input $input, Output $output): int
    {
        if (!in_array(getenv('APP_ENV'), ['dev', 'test'], true)) {
            throw new RuntimeException('Demo seed is only available in dev/test.');
        }
        $c   = app()->container();
        $pdo = $c->get(PDO::class);
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            $output->writeLine('Database already contains users. Nothing changed.');

            return self::SUCCESS;
        }
        $entityManager = $c->get(EntityManager::class);
        $hash          = $c->get(PasswordHasher::class)->hash('Nafinity-Demo-2026!');
        $users         = [];
        foreach (
            [
                ['Alice Winter', 'alice@example.test'],
                ['Bob Sommer', 'bob@example.test'],
                ['Robin Becker', 'viewer@example.test'],
            ] as [$name, $email]
        ) {
            $user = new User([
                'name'          => $name,
                'email'         => $email,
                'password_hash' => $hash,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);
            $entityManager->save($user);
            $users[] = $user;
        }
        $auth = $c->get(Auth::class);
        $auth->setIdentity($users[0]);
        $projects = $c->make(ProjectService::class);
        $tickets  = $c->make(TicketService::class);
        $a        = $projects->create([
            'name' => 'Nafinity',
            'description'
                    => 'Ein klarer Ort für Ideen, Entscheidungen und die nächste gute Version.',
            'color' => '#6366f1',
            'icon'  => 'N',
        ]);
        $projects->member($a, ['email' => 'viewer@example.test', 'role' => 'viewer']);
        foreach (
            [['Produkt', '#8b5cf6'], ['Design', '#ec4899'], ['Qualität', '#10b981']] as [$name, $color]
        ) {
            $projects->structure($a, ['kind' => 'label', 'name' => $name, 'color' => $color]);
        }
        $projects->structure($a, ['kind' => 'swimlane', 'name' => 'Nächster Meilenstein']);
        $statement = $pdo->prepare('SELECT id FROM board_columns WHERE project_id=? ORDER BY position');
        $statement->execute([$a]);
        $columns   = $statement->fetchAll(PDO::FETCH_COLUMN);
        $statement = $pdo->prepare('SELECT id FROM swimlanes WHERE project_id=? ORDER BY position');
        $statement->execute([$a]);
        $lanes     = $statement->fetchAll(PDO::FETCH_COLUMN);
        $statement = $pdo->prepare('SELECT id FROM labels WHERE project_id=? ORDER BY id');
        $statement->execute([$a]);
        $labels = $statement->fetchAll(PDO::FETCH_COLUMN);
        $cards  = [
            [
                'Ein guter Start für jedes Projekt',
                'Leere Zustände sollen Orientierung geben und den nächsten Schritt zeigen.',
                0,
                0,
                'normal',
                0,
            ],
            [
                'Schneller zwischen Projekten wechseln',
                'Der Project Switcher bleibt auch auf kleinen Bildschirmen erreichbar.',
                0,
                0,
                'low',
                1,
            ],
            [
                'Das Board mit der Tastatur bedienen',
                'Jede Karte lässt sich auch ohne Drag-and-drop in eine andere Spalte verschieben.',
                1,
                0,
                'high',
                1,
            ],
            [
                'Ticketdetails an einem Ort',
                'Beschreibung, Verantwortliche, Kommentare und Verlauf gehören zusammen.',
                1,
                0,
                'normal',
                0,
            ],
            [
                'Zwei Ansichten, ein verlässlicher Stand',
                'Veraltete Änderungen müssen sichtbar werden, bevor Daten überschrieben werden.',
                2,
                0,
                'high',
                2,
            ],
            [
                'Projektgrenzen absichern',
                'Direkte URLs, Filter und Zuordnungen werden mit getrennten Konten geprüft.',
                3,
                0,
                'urgent',
                2,
            ],
            [
                'Weniger Ablenkung im Dark Mode',
                'Kontrast und Fokuszustände sollen in beiden Themes angenehm bleiben.',
                1,
                1,
                'normal',
                1,
            ],
            [
                'Die nächste Version vorbereiten',
                'Abnahmefälle sammeln und den Fortschritt im Activity-Verlauf nachvollziehen.',
                0,
                1,
                'low',
                0,
            ],
        ];
        foreach ($cards as [$title, $description, $column, $lane, $priority, $label]) {
            $b = $tickets->board($a);
            $tickets->create($a, [
                'title'          => $title,
                'description'    => $description,
                'priority'       => $priority,
                'color'          => '#6366f1',
                'due_date'       => gmdate('Y-m-d', time() + 7 * 86400),
                'column_id'      => $columns[$column],
                'swimlane_id'    => $lanes[$lane],
                'board_revision' => $b['revision'],
                'label_ids'      => [$labels[$label]],
                'assignee_ids'   => [$users[0]->getId()],
            ]);
        }
        $auth->setIdentity($users[1]);
        $projects->create([
            'name'        => 'Studio Nord',
            'description' => 'Ein getrenntes Projekt für Bobs Team.',
            'color'       => '#14b8a6',
            'icon'        => 'S',
        ]);
        $auth->logout();
        $output->writeLine(
            'Created Nafinity and Studio Nord. Local demo password: Nafinity-Demo-2026!',
            'ok',
        );

        return self::SUCCESS;
    }
}
