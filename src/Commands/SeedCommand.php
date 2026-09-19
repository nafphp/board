<?php

declare(strict_types=1);

namespace Naf\Board\Commands;

use Naf\Auth\Auth;
use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Contracts\CommentServiceInterface;
use Naf\Board\Contracts\ProjectServiceInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Contracts\TimerServiceInterface;
use Naf\Board\Models\User;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\ORM\Core\EntityManager;
use PDO;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function Naf\app;

/** @internal */
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
                ['Mira Hoffmann', 'manager@example.test'],
                ['Jonas Peters', 'member@example.test'],
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
        $projects = $c->get(ProjectServiceInterface::class);
        $tickets  = $c->get(TicketServiceInterface::class);
        $purpose  = 'Ein klarer Ort für Ideen, Entscheidungen und die nächste gute Version.';
        $a        = $projects->create([
            'name'             => 'Nafinity',
            'description'      => $purpose,
            'color'            => '#6366f1',
            'icon'             => 'N',
            'estimation_scale' => 'points',
        ]);
        // One account per role, so a permission check has something to fail against.
        // Bob is not among them on purpose: he owns a project of his own and has to
        // stay outside this one, which is what makes project isolation testable.
        $projects->member($a, ['email' => 'viewer@example.test', 'role' => 'viewer']);
        $projects->member($a, ['email' => 'manager@example.test', 'role' => 'manager']);
        $projects->member($a, ['email' => 'member@example.test', 'role' => 'member']);
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
                3,
            ],
            [
                'Schneller zwischen Projekten wechseln',
                'Der Project Switcher bleibt auch auf kleinen Bildschirmen erreichbar.',
                0,
                0,
                'low',
                1,
                5,
            ],
            [
                'Das Board mit der Tastatur bedienen',
                'Jede Karte lässt sich auch ohne Drag-and-drop in eine andere Spalte verschieben.',
                1,
                0,
                'high',
                1,
                8,
            ],
            [
                'Ticketdetails an einem Ort',
                'Beschreibung, Verantwortliche, Kommentare und Verlauf gehören zusammen.',
                1,
                0,
                'normal',
                0,
                2,
            ],
            [
                'Zwei Ansichten, ein verlässlicher Stand',
                'Veraltete Änderungen müssen sichtbar werden, bevor Daten überschrieben werden.',
                2,
                0,
                'high',
                2,
                13,
            ],
            [
                'Projektgrenzen absichern',
                'Direkte URLs, Filter und Zuordnungen werden mit getrennten Konten geprüft.',
                3,
                0,
                'urgent',
                2,
                3,
            ],
            [
                'Weniger Ablenkung im Dark Mode',
                'Kontrast und Fokuszustände sollen in beiden Themes angenehm bleiben.',
                1,
                1,
                'normal',
                1,
                1,
            ],
            [
                'Die nächste Version vorbereiten',
                'Abnahmefälle sammeln und den Fortschritt im Activity-Verlauf nachvollziehen.',
                0,
                1,
                'low',
                0,
                5,
            ],
        ];
        $created = [];
        foreach ($cards as [$title, $description, $column, $lane, $priority, $label, $points]) {
            $b         = $tickets->board($a);
            $created[] = $tickets->create($a, [
                'title'           => $title,
                'description'     => $description,
                'priority'        => $priority,
                'color'           => '#6366f1',
                'due_date'        => gmdate('Y-m-d', time() + 7 * 86400),
                'column_id'       => $columns[$column],
                'swimlane_id'     => $lanes[$lane],
                'board_revision'  => $b['revision'],
                'estimate_points' => $points,
                'label_ids'       => [$labels[$label]],
                'assignee_ids'    => [$users[0]->getId()],
            ]);
        }

        // Everything above is a ticket in its initial state, which is not what a
        // board looks like after a week of work. The rest of this project exists so
        // that a closed card, a comment thread, a running clock and an archived
        // ticket are all on screen without anyone having to produce them by hand.
        $comments = $c->get(CommentServiceInterface::class);
        $comments->save($a, $created[1], ['body' => 'Auf schmalen Bildschirmen verdeckt der Switcher noch die Suche.']);
        $comments->save($a, $created[1], ['body' => 'Stimmt. Ich ziehe ihn unter die Kopfzeile, dann bleibt beides erreichbar.']);
        $comments->save($a, $created[2], ['body' => 'Tastaturbedienung ist auch ein Zugänglichkeitsthema, nicht nur Komfort.']);

        $timers = $c->get(TimerServiceInterface::class);
        // One clock left running and one stopped: the running case is the one that
        // shows up in the shell and is easy to forget while developing.
        //
        // The order is the whole trick. A person works on one thing at a time, so
        // starting a timer settles whatever else was running for that account --
        // start the one meant to keep running first and the next start pauses it.
        $timers->act($a, $created[4], ['action' => 'start']);
        $timers->act($a, $created[4], ['action' => 'stop']);
        $timers->act($a, $created[2], ['action' => 'start']);

        // state() guards against a concurrent edit, so it wants the version it is
        // acting on. Reading it back beats tracking it, because a comment or a
        // timer above has already moved it on.
        $act = static function (int $id, string $action) use ($tickets, $a): void {
            $tickets->state($a, $id, [
                'action'  => $action,
                'version' => $tickets->ticket($a, $id)['version'],
            ]);
        };
        $act($created[0], 'close');
        $act($created[3], 'close');
        $act($created[6], 'close');
        $act($created[7], 'archive');

        // Bob's project. Alice is not a member, which is what makes it useful: it is
        // the project that must stay out of reach, and it needs content for that to
        // mean anything.
        $auth->setIdentity($users[1]);
        $b = $projects->create([
            'name'             => 'Studio Nord',
            'description'      => 'Ein getrenntes Projekt für Bobs Team.',
            'color'            => '#14b8a6',
            'icon'             => 'S',
            'estimation_scale' => 'tshirt',
        ]);
        foreach ([['Kundenwunsch', '#0ea5e9'], ['Intern', '#64748b']] as [$name, $color]) {
            $projects->structure($b, ['kind' => 'label', 'name' => $name, 'color' => $color]);
        }
        $this->cards($c, $b, $users[1]->getId(), [
            // 5 and 2 are L and S on the t-shirt scale; a project using it shows the
            // size on the card and still sums the numbers behind it per column.
            ['Angebot für die Sommerkampagne', 'Zwei Varianten, eine davon ohne Druckkosten.', 0, 'high', 5],
            ['Bildrechte klären', 'Für drei Motive fehlt die schriftliche Freigabe.', 1, 'normal', 2],
            ['Retrospektive vorbereiten', 'Was hat im letzten Durchlauf gebremst?', 2, 'low', 1],
        ]);

        // A second project of Alice's own, so that moving a ticket between projects has
        // somewhere to go. Bob's is deliberately not that place: it is here to show that a
        // project nobody invited you to stays out of reach.
        $auth->setIdentity($users[0]);
        $d = $projects->create([
            'name'        => 'Archiv & Ideen',
            'description' => 'Was noch nicht dran ist, aber nicht verloren gehen soll.',
            'color'       => '#f59e0b',
            'icon'        => 'A',
        ]);
        $this->cards($c, $d, $users[0]->getId(), [
            ['Offline lesen', 'Ein Board ohne Verbindung wenigstens ansehen können.', 0, 'low', null],
            ['Wiederkehrende Tickets', 'Monatliche Aufgaben sollen sich selbst anlegen.', 0, 'normal', null],
        ]);

        // An archived project belongs in the fixture too: it stays readable and
        // refuses every change, and that is easy to break without noticing.
        $e = $projects->create([
            'name'        => 'Messe 2025',
            'description' => 'Abgeschlossen und nur noch zum Nachlesen da.',
            'color'       => '#a855f7',
            'icon'        => 'M',
        ]);
        $this->cards($c, $e, $users[0]->getId(), [
            ['Standaufbau koordinieren', 'Termine mit dem Messebauer abgestimmt.', 0, 'normal', null],
        ]);
        $projects->archive($e, true);

        $auth->logout();
        $output->writeLine(
            'Created Nafinity, Studio Nord, Archiv & Ideen and the archived Messe 2025, '
            . 'with comments, a running timer, closed and archived tickets. '
            . 'Local demo password: Nafinity-Demo-2026!',
            'ok',
        );

        return self::SUCCESS;
    }

    /**
     * Create tickets in a project, reading its structure rather than assuming it.
     *
     * A project's columns and swimlanes are made by the service that created it,
     * so their ids are only knowable afterwards. $rows is
     * [title, description, column index, priority, estimate or null].
     *
     * @param array<int, array{0: string, 1: string, 2: int, 3: string, 4: int|null}> $rows
     */
    private function cards(ContainerInterface $container, int $project, int $actor, array $rows): void
    {
        $pdo     = $container->get(PDO::class);
        $tickets = $container->get(TicketServiceInterface::class);

        $statement = $pdo->prepare('SELECT id FROM board_columns WHERE project_id=? ORDER BY position');
        $statement->execute([$project]);
        $columns = $statement->fetchAll(PDO::FETCH_COLUMN);

        $statement = $pdo->prepare('SELECT id FROM swimlanes WHERE project_id=? ORDER BY position');
        $statement->execute([$project]);
        $lanes = $statement->fetchAll(PDO::FETCH_COLUMN);

        $statement = $pdo->prepare('SELECT id FROM labels WHERE project_id=? ORDER BY id');
        $statement->execute([$project]);
        $labels = $statement->fetchAll(PDO::FETCH_COLUMN);

        foreach ($rows as $index => [$title, $description, $column, $priority, $estimate]) {
            $board = $tickets->board($project);
            $tickets->create($project, [
                'title'           => $title,
                'description'     => $description,
                'priority'        => $priority,
                'column_id'       => $columns[$column] ?? $columns[0],
                'swimlane_id'     => $lanes[0] ?? null,
                'board_revision'  => $board['revision'],
                'estimate_points' => $estimate,
                // Labels only where the project has any, and rotated so that a
                // board shows more than one colour.
                'label_ids'    => $labels === [] ? [] : [$labels[$index % count($labels)]],
                'assignee_ids' => [$actor],
            ]);
        }
    }
}
