<?php

declare(strict_types=1);

use App\Support\Mentions;
use App\Support\RichText;
use Naf\Core\App;

// CLI boot omits the HTTP guards; load the framework's actual escaping guard for view tests.
$guards = new ReflectionMethod(App::class, 'loadGuards');
$guards->invoke(Naf\app());

$auth->setIdentity($users['alice']);
$detailProject = $projects->create(['name' => 'Ticket details', 'description' => 'Inline regression']);
$detailBoard   = $query->board($detailProject);
$detailData    = [
    'title'        => 'Original title', 'description' => 'Legacy <script> remains text', 'priority' => 'normal',
    'column_id'    => $detailBoard['columns'][0]['id'], 'swimlane_id' => $detailBoard['swimlanes'][0]['id'],
    'assignee_ids' => [$users['alice']->getId()],
];
$detailCreate   = fn() => $tickets->create($detailProject, $detailData + ['board_revision' => $tickets->board($detailProject)['revision']]);
$detailId       = $detailCreate();
$detailOther    = $detailCreate();
$detailVersions = fn() => ['version' => $tickets->ticket($detailProject, $detailId)['version'], 'board_revision' => $tickets->board($detailProject)['revision']];

test('inline updates preserve unrelated fields and reject stale writes', function () use ($tickets, $query, $detailProject, $detailId, $detailVersions) {
    $version = $detailVersions();
    $tickets->update($detailProject, $detailId, ['title' => 'Inline title'] + $version);
    $detail = $query->detail($detailProject, $detailId);
    check($detail['ticket']['description'] === 'Legacy <script> remains text', 'description lost');
    check(count($detail['selected_assignees']) === 1, 'assignee lost');
    check(!str_contains(RichText::description($detail['ticket']), '<script>'), 'legacy text interpreted as HTML');
    denied(409, fn() => $tickets->update($detailProject, $detailId, ['title' => 'Stale'] + $version));
    $tickets->update($detailProject, $detailId, ['assignee_ids' => []] + $detailVersions());
    check($query->detail($detailProject, $detailId)['selected_assignees'] === [], 'clearing assignees failed');
});

test('rich description uses sanitized HTML and searchable plain text', function () use ($tickets, $detailProject, $detailId, $detailVersions) {
    $html = '<h2>Goal</h2><p><strong>Bold</strong> <em>Italic</em></p><ol><li>Task</li></ol><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)" onclick="alert(4)">unsafe</a>';
    $tickets->update($detailProject, $detailId, ['description_html' => $html] + $detailVersions());
    $row = $tickets->ticket($detailProject, $detailId);
    check(str_contains($row['description_html'], '<strong>Bold</strong>'), 'formatting lost');
    check(str_contains($row['description_html'], '<h2>Goal</h2>'), 'heading lost');
    foreach (['<script', '<img', 'javascript:', 'onclick', 'onerror'] as $unsafe) {
        check(!str_contains($row['description_html'], $unsafe), 'unsafe rich text: ' . $unsafe);
    }
    check(str_contains($row['description'], 'Bold') && !str_contains($row['description'], '<strong>'), 'search text wrong');
    $tickets->update($detailProject, $detailId, ['priority' => 'high'] + $detailVersions());
    check($tickets->ticket($detailProject, $detailId)['description_html'] === $row['description_html'], 'unrelated update lost formatting');
    $tickets->update($detailProject, $detailId, ['description' => 'Updated through plain API'] + $detailVersions());
    check($tickets->ticket($detailProject, $detailId)['description_html'] === null, 'plain API leaves stale formatting');
    denied(422, fn() => $tickets->update($detailProject, $detailId, ['description_html' => ['bad']] + $detailVersions()));
});

test('rich text character limits also fit Unicode and HTML byte sizes', function () use ($tickets, $detailProject, $detailId, $detailVersions) {
    $html = '<p>' . str_repeat('界', 40000) . '</p>';
    $tickets->update($detailProject, $detailId, ['description_html' => $html] + $detailVersions());
    check(mb_strlen($tickets->ticket($detailProject, $detailId)['description']) === 40000, 'Unicode content truncated');
    // Keep the down-migration probe within the legacy column limit after testing the wider storage.
    $tickets->update($detailProject, $detailId, ['description' => 'Unicode storage verified'] + $detailVersions());
});

test('planning dates and durations validate and persist', function () use ($tickets, $detailProject, $detailId, $detailVersions) {
    $tickets->update($detailProject, $detailId, ['start_date' => '2026-09-16', 'due_date' => '2026-09-20', 'estimate_minutes' => '125', 'spent_minutes' => '61'] + $detailVersions());
    $row = $tickets->ticket($detailProject, $detailId);
    check($row['start_date'] === '2026-09-16' && (int) $row['spent_minutes'] === 61, 'planning not persisted');
    foreach ([['start_date' => '2026-02-30'], ['start_date' => '2026-10-01'], ['start_date' => []], ['spent_minutes' => -1], ['spent_minutes' => '1.5'], ['spent_minutes' => []], ['estimate_minutes' => '10000001']] as $invalid) {
        denied(422, fn() => $tickets->update($detailProject, $detailId, $invalid + $detailVersions()));
    }
    $tickets->update($detailProject, $detailId, ['start_date' => '', 'due_date' => '', 'estimate_minutes' => '', 'spent_minutes' => 0] + $detailVersions());
    $row = $tickets->ticket($detailProject, $detailId);
    check($row['start_date'] === null && $row['estimate_minutes'] === null, 'optional fields not cleared');
});

test('ticket links are bidirectional unique and confined to the project', function () use ($tickets, $query, $detailProject, $detailId, $detailOther, $detailVersions) {
    $number = $tickets->ticket($detailProject, $detailOther)['number'];
    $tickets->link($detailProject, $detailId, ['number' => $number] + $detailVersions());
    $tickets->link($detailProject, $detailId, ['number' => $number] + $detailVersions());
    check(count($query->detail($detailProject, $detailId)['linked_tickets']) === 1, 'duplicate link');
    check((int) $query->detail($detailProject, $detailOther)['linked_tickets'][0]['id'] === $detailId, 'reverse link missing');
    denied(422, fn() => $tickets->link($detailProject, $detailId, ['number' => 999999] + $detailVersions()));
    denied(422, fn() => $tickets->link($detailProject, $detailId, ['number' => $tickets->ticket($detailProject, $detailId)['number']] + $detailVersions()));
    denied(409, fn() => $tickets->link($detailProject, $detailId, ['number' => $number, 'version' => 1]));
    $tickets->link($detailProject, $detailId, ['number' => $number, 'action' => 'delete'] + $detailVersions());
    check($query->detail($detailProject, $detailOther)['linked_tickets'] === [], 'unlink not symmetric');
});

test('comment replies keep their exact parent and survive parent deletion', function () use ($comments, $query, $detailProject, $detailId, $detailOther) {
    $comments->save($detailProject, $detailId, ['body' => 'Parent']);
    $parent = (int) scalar('SELECT MAX(id) FROM comments');
    $comments->save($detailProject, $detailId, ['body' => '@alice Reply', 'parent_id' => $parent]);
    $reply = (int) scalar('SELECT MAX(id) FROM comments');
    $comments->save($detailProject, $detailId, ['body' => 'Nested', 'parent_id' => $reply]);
    denied(422, fn() => $comments->save($detailProject, $detailOther, ['body' => 'Cross ticket', 'parent_id' => $parent]));
    denied(422, fn() => $comments->save($detailProject, $detailId, ['body' => 'Forged', 'parent_id' => 999999]));
    $comments->save($detailProject, $detailId, ['id' => $parent, 'version' => 1, 'action' => 'delete']);
    $rows = $query->detail($detailProject, $detailId)['comments'];
    check(count($rows) === 3 && $rows[0]['body'] === '' && $rows[0]['deleted_at'] !== null, 'deleted parent not redacted');
    check((int) $rows[1]['parent_id'] === $parent && (int) $rows[2]['parent_id'] === $reply, 'reply ancestry lost');
    denied(422, fn() => $comments->save($detailProject, $detailId, ['body' => 'Deleted target', 'parent_id' => $parent]));
});

test('mention handles are unambiguous and rendered without HTML injection', function () {
    $members = Mentions::members([['id' => 1, 'name' => 'Anna Schmid'], ['id' => 2, 'name' => 'Anna Schmid'], ['id' => 3, 'name' => 'Florian Knapp']]);
    check($members[0]['handle'] === 'anna.schmid.1' && $members[1]['handle'] === 'anna.schmid.2', 'ambiguous handle');
    check($members[2]['handle'] === 'florian.knapp', 'name handle');
    $html = Mentions::render('@florian.knapp <img src=x onerror=alert(1)> person@florian.knapp', $members);
    check(substr_count($html, 'class="mention"') === 1 && !str_contains($html, '<img'), 'mention escaping/boundary failed');
});

test('inline writes and links enforce viewer and nonmember access', function () use ($auth, $users, $projects, $tickets, $comments, $query, $detailProject, $detailId, $detailVersions) {
    $projects->member($detailProject, ['email' => 'viewer@example.test', 'role' => 'viewer']);
    $version = $detailVersions();
    $auth->setIdentity($users['viewer']);
    denied(403, fn() => $tickets->update($detailProject, $detailId, ['title' => 'Forbidden'] + $version));
    denied(403, fn() => $tickets->link($detailProject, $detailId, ['number' => 2] + $version));
    denied(403, fn() => $comments->save($detailProject, $detailId, ['body' => 'Forbidden']));
    $auth->setIdentity($users['bob']);
    denied(404, fn() => $tickets->update($detailProject, $detailId, ['title' => 'Foreign'] + $version));
    denied(404, fn() => $query->detail($detailProject, $detailId));
    $auth->setIdentity($users['alice']);
    $tickets->state($detailProject, $detailId, ['action' => 'archive'] + $detailVersions());
    denied(422, fn() => $tickets->update($detailProject, $detailId, ['title' => 'Archived'] + $detailVersions()));
    denied(422, fn() => $tickets->link($detailProject, $detailId, ['number' => 2] + $detailVersions()));
});
