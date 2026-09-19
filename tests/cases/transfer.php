<?php

declare(strict_types=1);

use Naf\Board\Services\TimerService;

$moveTimers = $c->make(TimerService::class);
$auth->setIdentity($users['alice']);

// Home has everyone in it; away is missing Bob on purpose, because he is the one who will
// have written things that must survive the move without him.
$moveHome = $projects->create(['name' => 'Transfer home', 'description' => 'Where the ticket starts']);
$moveAway = $projects->create(['name' => 'Transfer away', 'description' => 'Where the ticket lands']);
foreach (['bob', 'member'] as $name) {
    $projects->member($moveHome, ['email' => $name . '@example.test', 'role' => 'member']);
}
$projects->member($moveAway, ['email' => 'member@example.test', 'role' => 'member']);
$projects->structure($moveAway, ['kind' => 'column', 'name' => 'Eingang', 'color' => '#6366f1']);
$projects->structure($moveHome, ['kind' => 'label', 'name' => 'Nur hier']);
$moveLabel = (int) scalar('SELECT id FROM labels WHERE project_id=?', [$moveHome]);

$moveAdd = function (int $project, string $title) use ($tickets, $query) {
    $board = $query->board($project);

    return $tickets->create($project, [
        'title'          => $title,
        'description'    => 'Travels with the ticket',
        'priority'       => 'high',
        'column_id'      => $board['columns'][0]['id'],
        'swimlane_id'    => $board['swimlanes'][0]['id'],
        'board_revision' => $tickets->board($project)['revision'],
    ]);
};
$moveVersion = fn(int $project, int $id) => ['version' => $tickets->ticket($project, $id)['version']];
$moveCount   = fn(string $table, int $project, int $id) => (int) scalar(
    "SELECT COUNT(*) FROM $table WHERE project_id=? AND ticket_id=?",
    [$project, $id],
);
// A file is written straight into the table: what is under test is the key it hangs on, not
// the upload that would otherwise have to be staged and promoted first.
$moveAttach = function (int $project, int $id, int $uploader) use ($pdo): void {
    $pdo->prepare(
        'INSERT INTO attachments(project_id,ticket_id,uploaded_by,storage_key,original_name,'
        . "mime_type,byte_size,sha256,state,created_at) VALUES(?,?,?,?,'travels.txt','text/plain',9,?,'ready',?)",
    )->execute([$project, $id, $uploader, 'transfer-' . $id . '-' . $uploader, str_repeat('a', 64), gmdate('Y-m-d H:i:s')]);
};
$moveRewind = function (int $project, int $id, int $user, int $seconds) use ($pdo): void {
    $pdo->prepare('UPDATE ticket_timers SET started_at=? WHERE project_id=? AND ticket_id=? AND user_id=?')
        ->execute([gmdate('Y-m-d H:i:s', time() - $seconds), $project, $id, $user]);
};

test('a ticket takes its own history along and leaves the project behind', function () use (
    $tickets,
    $query,
    $comments,
    $projects,
    $auth,
    $users,
    $pdo,
    $moveTimers,
    $moveHome,
    $moveAway,
    $moveAdd,
    $moveAttach,
    $moveRewind,
    $moveVersion,
    $moveCount,
    $moveLabel,
) {
    $id        = $moveAdd($moveHome, 'Wandert');
    $neighbour = $moveAdd($moveHome, 'Bleibt hier');
    $tickets->update($moveHome, $id, [
        'label_ids'      => [$moveLabel],
        'assignee_ids'   => [$users['bob']->getId(), $users['member']->getId()],
        'version'        => $tickets->ticket($moveHome, $id)['version'],
        'board_revision' => $tickets->board($moveHome)['revision'],
    ]);
    $tickets->link($moveHome, $id, ['number' => $tickets->ticket($moveHome, $neighbour)['number']] + $moveVersion($moveHome, $id));
    $moveAttach($moveHome, $id, $users['bob']->getId());

    // Bob writes, and is then the one person the destination has never heard of.
    $auth->setIdentity($users['bob']);
    $comments->save($moveHome, $id, ['body' => 'Bob asks a question']);
    $root = (int) scalar('SELECT id FROM comments WHERE project_id=? AND ticket_id=? ORDER BY id', [$moveHome, $id]);
    $moveTimers->act($moveHome, $id, ['action' => 'start']);
    $moveRewind($moveHome, $id, $users['bob']->getId(), 150);
    $moveTimers->act($moveHome, $id, ['action' => 'pause']);
    $auth->setIdentity($users['alice']);
    $comments->save($moveHome, $id, ['body' => 'Alice replies', 'parent_id' => $root]);
    $moveTimers->act($moveHome, $id, ['action' => 'start']);
    $moveRewind($moveHome, $id, $users['alice']->getId(), 80);

    $before        = $tickets->ticket($moveHome, $id);
    $neighbourWas  = (int) $tickets->ticket($moveHome, $neighbour)['version'];
    $activitiesWas = $moveCount('activities', $moveHome, $id);
    check($moveCount('notifications', $moveHome, $id) > 0, 'nobody was told about the ticket');
    check($activitiesWas > 0, 'the ticket has no history to carry');

    $moved = $tickets->transfer($moveHome, $id, ['project_id' => $moveAway] + $moveVersion($moveHome, $id));
    $after = $tickets->ticket($moveAway, $id);

    check($moved['project'] === $moveAway, 'the ticket did not arrive');
    $awayKey = (string) scalar('SELECT ticket_key FROM projects WHERE id=?', [$moveAway]);
    check($moved['reference'] === $awayKey . '-1', 'the new reference is ' . $moved['reference']);
    check($tickets->resolve($moveAway, $moved['reference']) === $id, 'the new reference does not open the ticket');
    check((int) $after['number'] === 1, 'the ticket kept a number from the project it left');
    check($after['title'] === $before['title'] && $after['priority'] === 'high', 'the ticket lost its own fields');
    check((int) $after['version'] > (int) $before['version'], 'the move left the version untouched');
    check((int) $tickets->board($moveAway)['next_number'] === 2, 'the next ticket over there would reuse the number');

    // It lands at the front of the board it joins, not wherever it happened to sit.
    $awayBoard = $query->board($moveAway);
    check((int) $after['column_id'] === (int) $awayBoard['columns'][0]['id'], 'the ticket landed in the wrong column');
    check((int) $after['board_id'] === (int) $awayBoard['board']['id'], 'the ticket is on the wrong board');

    // What the ticket wrote comes along, whoever wrote it.
    $detail = $query->detail($moveAway, $id);
    check(count($detail['comments']) === 2, 'the conversation did not follow');
    check($detail['comments'][0]['author_name'] === 'Bob', 'the comment lost its author');
    check((int) $detail['comments'][1]['parent_id'] === $root, 'the reply lost the comment it answered');
    check(count($detail['attachments']) === 1, 'the attachment did not follow');
    check(
        (int) scalar('SELECT uploaded_by FROM attachments WHERE project_id=? AND ticket_id=?', [$moveAway, $id])
            === $users['bob']->getId(),
        'the attachment lost the person who uploaded it',
    );
    check($moveCount('activities', $moveAway, $id) >= $activitiesWas + 1, 'the history did not follow');
    check($moveCount('activities', $moveHome, $id) === 0, 'history was left behind in both places');
    check((int) $detail['ticket']['created_by'] === $users['alice']->getId(), 'the ticket lost its creator');

    // What only meant something over there stays over there.
    check($moveCount('ticket_labels', $moveAway, $id) === 0, 'a label from the old project came along');
    check($moveCount('ticket_links', $moveAway, $id) === 0, 'a link to a ticket left behind came along');
    check((int) scalar('SELECT COUNT(*) FROM ticket_links WHERE project_id=?', [$moveHome]) === 0, 'a half link stayed');
    check((int) $tickets->ticket($moveHome, $neighbour)['version'] > $neighbourWas, 'the neighbour was not told');
    check((int) scalar('SELECT COUNT(*) FROM notifications WHERE ticket_id=?', [$id]) > 0, 'the arrival went unannounced');
    check(
        (int) scalar('SELECT COUNT(*) FROM notifications WHERE project_id=? AND ticket_id=?', [$moveHome, $id]) === 0,
        'a notification points at a ticket that has left',
    );

    // Assignment needs a membership on the other side; authorship never did.
    check($query->detail($moveAway, $id)['selected_assignees'] == [$users['member']->getId()], 'the wrong people stayed assigned');

    // The clocks are stopped on the way out, so the work counted still reaches the ticket.
    check((int) $after['spent_minutes'] === 3, 'counted time was lost: ' . $after['spent_minutes']);
    check((int) scalar('SELECT COUNT(*) FROM ticket_timers WHERE ticket_id=?', [$id]) === 0, 'a run survived the move');
    check($moveTimers->running() === null, 'the bar still marks a run on a ticket that moved');
});

test('the destinations offered are the ones the person may actually write in', function () use (
    $query,
    $projects,
    $auth,
    $users,
    $moveHome,
    $moveAway,
) {
    $auth->setIdentity($users['bob']);
    $bobOnly = $projects->create(['name' => 'Transfer nowhere', 'description' => 'Alice only watches']);
    $projects->member($bobOnly, ['email' => 'alice@example.test', 'role' => 'viewer']);
    $auth->setIdentity($users['alice']);
    $shelved = $projects->create(['name' => 'Transfer shelved', 'description' => 'Archived before the move']);
    $projects->archive($shelved, true);

    $offered = array_column($query->transferTargets($moveHome), 'id');
    check(in_array($moveAway, $offered, true), 'a project the person owns was not offered');
    check(!in_array($moveHome, $offered, true), 'the project the ticket is already in was offered');
    check(!in_array($bobOnly, $offered, true), 'a project the person may only read was offered');
    check(!in_array($shelved, $offered, true), 'an archived project was offered');
});

test('a move that cannot be made changes nothing at all', function () use (
    $tickets,
    $query,
    $auth,
    $users,
    $projects,
    $moveHome,
    $moveAway,
    $moveAdd,
    $moveVersion,
) {
    $id = $moveAdd($moveHome, 'Bleibt wo es ist');
    denied(422, fn() => $tickets->transfer($moveHome, $id, ['project_id' => $moveHome] + $moveVersion($moveHome, $id)));
    denied(422, fn() => $tickets->transfer($moveHome, $id, ['project_id' => 'nowhere'] + $moveVersion($moveHome, $id)));
    denied(404, fn() => $tickets->transfer($moveHome, $id, ['project_id' => 999999] + $moveVersion($moveHome, $id)));
    denied(409, fn() => $tickets->transfer($moveHome, $id, ['project_id' => $moveAway, 'version' => 99]));

    // Reading a project is not permission to put work into it.
    $auth->setIdentity($users['bob']);
    $readOnly = $projects->create(['name' => 'Transfer readable', 'description' => 'Alice watches here too']);
    $projects->member($readOnly, ['email' => 'alice@example.test', 'role' => 'viewer']);
    $auth->setIdentity($users['alice']);
    denied(403, fn() => $tickets->transfer($moveHome, $id, ['project_id' => $readOnly] + $moveVersion($moveHome, $id)));

    $tickets->state($moveHome, $id, ['action' => 'archive'] + $moveVersion($moveHome, $id));
    denied(422, fn() => $tickets->transfer($moveHome, $id, ['project_id' => $moveAway] + $moveVersion($moveHome, $id)));
    $tickets->state($moveHome, $id, ['action' => 'restore'] + $moveVersion($moveHome, $id));

    check((int) $tickets->ticket($moveHome, $id)['project_id'] === $moveHome, 'a refused move went through anyway');
    check(count($query->detail($moveHome, $id)) > 0, 'the ticket is no longer readable where it was');
});
