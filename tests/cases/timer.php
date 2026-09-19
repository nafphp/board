<?php

declare(strict_types=1);

use Naf\Board\Services\TimerService;

$timers = $c->make(TimerService::class);
$auth->setIdentity($users['alice']);
$timerProject = $projects->create(['name' => 'Timer', 'description' => 'Time tracking regression']);
$projects->member($timerProject, ['email' => 'bob@example.test', 'role' => 'member']);
$projects->member($timerProject, ['email' => 'viewer@example.test', 'role' => 'viewer']);
$timerBoard = $query->board($timerProject);
$timerAdd   = fn() => $tickets->create($timerProject, [
    'title'          => 'Tracked',
    'description'    => 'Carries time',
    'priority'       => 'normal',
    'column_id'      => $timerBoard['columns'][0]['id'],
    'swimlane_id'    => $timerBoard['swimlanes'][0]['id'],
    'board_revision' => $tickets->board($timerProject)['revision'],
]);
// Rewinding the stored start is how a long run is produced without waiting for one.
$timerRewind = function (int $ticket, int $seconds) use ($pdo, $timerProject, $users): void {
    $pdo->prepare('UPDATE ticket_timers SET started_at=? WHERE project_id=? AND ticket_id=? AND user_id=?')
        ->execute([
            gmdate('Y-m-d H:i:s', time() - $seconds),
            $timerProject,
            $ticket,
            $users['alice']->getId(),
        ]);
};
$timerSpent = fn(int $ticket) => (int) $tickets->ticket($timerProject, $ticket)['spent_minutes'];

test('counted time reaches the ticket and keeps the seconds below a minute', function () use (
    $timers,
    $timerProject,
    $timerAdd,
    $timerRewind,
    $timerSpent,
) {
    $id = $timerAdd();
    $timers->act($timerProject, $id, ['action' => 'start']);
    $timerRewind($id, 150);
    $state = $timers->act($timerProject, $id, ['action' => 'pause']);
    // Pausing holds the clock and writes nothing; that is what gives stopping a meaning.
    check($timerSpent($id) === 0, 'pausing booked time onto the ticket');
    check($state['state'] === 'paused' && $state['seconds'] === 150, 'the clock lost time on pause');
    $timers->act($timerProject, $id, ['action' => 'start']);
    $timerRewind($id, 40);
    $state = $timers->act($timerProject, $id, ['action' => 'pause']);
    check($timerSpent($id) === 0, 'a second pause booked time onto the ticket');
    check($state['seconds'] === 190, 'the clock did not carry on from where it stood');
    $state = $timers->act($timerProject, $id, ['action' => 'stop']);
    check($state['state'] === 'stopped' && $state['spent_minutes'] === 3, 'stop reported the wrong total');
    check($timerSpent($id) === 3, 'stopping did not book the three minutes it showed');
    check($state['seconds'] === 0, 'stopping did not start a fresh session');
    // The ten seconds left over stay with the run and count towards the next minute.
    $timers->act($timerProject, $id, ['action' => 'start']);
    $timerRewind($id, 50);
    $timers->act($timerProject, $id, ['action' => 'stop']);
    check($timerSpent($id) === 4, 'short sessions rounded away instead of adding up');
});

test('the clock never runs backwards across a pause', function () use (
    $timers,
    $timerProject,
    $timerAdd,
    $timerRewind,
    $timerSpent,
) {
    // Exactly what a person does: run for a while, pause, look, start again, look.
    $id = $timerAdd();
    $timers->act($timerProject, $id, ['action' => 'start']);
    $timerRewind($id, 90);
    $running = $timers->state($timerProject, $id)['seconds'];
    check($running === 90, 'a minute and a half was not counted');
    $paused = $timers->act($timerProject, $id, ['action' => 'pause'])['seconds'];
    check($paused === $running, 'the clock jumped on pause: ' . $running . ' to ' . $paused);
    $resumed = $timers->act($timerProject, $id, ['action' => 'start'])['seconds'];
    check($resumed === $paused, 'resuming restarted the clock at ' . $resumed);
    $timerRewind($id, 30);
    check($timers->state($timerProject, $id)['seconds'] === 120, 'the clock did not carry on');
    // The booked minutes stay behind the clock by less than a minute, never ahead of it.
    $timers->act($timerProject, $id, ['action' => 'stop']);
    check($timerSpent($id) === 2, 'booked minutes disagree with the clock');
});

test('a person counts one thing at a time across projects', function () use (
    $timers,
    $tickets,
    $projects,
    $query,
    $timerProject,
    $timerAdd,
    $timerRewind,
    $timerSpent,
) {
    $first  = $timerAdd();
    $second = $timerAdd();
    $timers->act($timerProject, $first, ['action' => 'start']);
    $timerRewind($first, 120);
    $timers->act($timerProject, $second, ['action' => 'start']);
    check($timers->state($timerProject, $first)['state'] === 'paused', 'previous run kept counting');
    // Settling it is a pause, so its time waits on the clock rather than reaching the ticket.
    check($timerSpent($first) === 0, 'the interrupted run was booked without being stopped');
    check($timers->state($timerProject, $first)['seconds'] === 120, 'the interrupted run lost its time');
    $running = $timers->running();
    check($running !== null && (int) $running['ticket_id'] === $second, 'the wrong run is reported as running');
    // The marker in the bar reads the same clock as the ticket, not a shorter one of its own.
    check(
        $running['seconds'] === $timers->state($timerProject, $second)['seconds'],
        'the marker and the ticket disagree about the elapsed time',
    );
    check($timers->runningIn($timerProject) === [$second], 'board marker lists the wrong tickets');

    // A second project must settle the first one just the same.
    $other = $projects->create(['name' => 'Timer elsewhere', 'description' => 'Second project']);
    $board = $query->board($other);
    $far   = $tickets->create($other, [
        'title'          => 'Elsewhere',
        'description'    => 'Another project',
        'priority'       => 'normal',
        'column_id'      => $board['columns'][0]['id'],
        'swimlane_id'    => $board['swimlanes'][0]['id'],
        'board_revision' => $tickets->board($other)['revision'],
    ]);
    $timers->act($other, $far, ['action' => 'start']);
    check($timers->state($timerProject, $second)['state'] === 'paused', 'run in another project kept counting');
    check($timers->runningIn($timerProject) === [], 'board still marks a settled run');
    $timers->act($other, $far, ['action' => 'stop']);
});

test('timer writes carry no ticket version and refuse impossible requests', function () use (
    $timers,
    $tickets,
    $timerProject,
    $timerAdd,
    $timerRewind,
) {
    $id = $timerAdd();
    $timers->act($timerProject, $id, ['action' => 'start']);
    $timerRewind($id, 3600);
    // Someone who tracked an hour holds a stale version by now; pausing must still work.
    $stale = $tickets->ticket($timerProject, $id)['version'];
    $tickets->update($timerProject, $id, [
        'title'          => 'Renamed while the clock ran',
        'version'        => $stale,
        'board_revision' => $tickets->board($timerProject)['revision'],
    ]);
    $timers->act($timerProject, $id, ['action' => 'stop']);
    check($tickets->ticket($timerProject, $id)['spent_minutes'] === 60, 'an hour did not reach the ticket');
    check($tickets->ticket($timerProject, $id)['title'] === 'Renamed while the clock ran', 'edit lost');
    denied(422, fn() => $timers->act($timerProject, $id, ['action' => 'rewind']));
    $tickets->state($timerProject, $id, [
        'action'  => 'archive',
        'version' => $tickets->ticket($timerProject, $id)['version'],
    ]);
    denied(422, fn() => $timers->act($timerProject, $id, ['action' => 'start']));
});

test('two people track the same ticket without overwriting each other', function () use (
    $timers,
    $auth,
    $users,
    $timerProject,
    $timerAdd,
    $timerRewind,
    $timerSpent,
    $pdo,
) {
    $id = $timerAdd();
    $timers->act($timerProject, $id, ['action' => 'start']);
    $timerRewind($id, 180);
    $timers->act($timerProject, $id, ['action' => 'stop']);
    check($timerSpent($id) === 3, 'own time missing');

    $auth->setIdentity($users['bob']);
    $timers->act($timerProject, $id, ['action' => 'start']);
    $pdo->prepare('UPDATE ticket_timers SET started_at=? WHERE project_id=? AND ticket_id=? AND user_id=?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 120), $timerProject, $id, $users['bob']->getId()]);
    $timers->act($timerProject, $id, ['action' => 'stop']);
    check($timerSpent($id) === 5, 'the second person did not add to the same ticket');
    check($timers->state($timerProject, $id)['seconds'] === 0, 'runs of two people were mixed up');

    // Reading a project is not permission to book time against it, and a stranger is not
    // even told the project exists.
    $auth->setIdentity($users['viewer']);
    denied(403, fn() => $timers->act($timerProject, $id, ['action' => 'start']));
    $auth->setIdentity($users['member']);
    denied(404, fn() => $timers->act($timerProject, $id, ['action' => 'start']));
    $auth->setIdentity($users['alice']);
});
