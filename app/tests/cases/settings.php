<?php

declare(strict_types=1);

use App\Services\AiService;
use App\Services\PreferenceService;
use App\Services\RoleService;
use App\Support\Locales;

$roles = $c->make(RoleService::class);
$ai    = $c->make(AiService::class);
$auth->setIdentity($users['alice']);
$roleProject    = $projects->create(['name' => 'Custom roles', 'description' => 'Role boundary regression']);
$foreignProject = $projects->create(['name' => 'Other roles', 'description' => 'Foreign role regression']);
$roles->save($roleProject, ['name' => 'Redaktion', 'description' => '', 'permissions' => ['comment', 'upload']]);
$custom    = $roles->list($roleProject)[0];
$roleId    = (int) $custom['id'];
$customKey = 'custom:' . $roleId;
$projects->member($roleProject, ['email' => 'member@example.test', 'role' => $customKey]);

test('custom role permissions are enforced by native auth policy', function () use ($auth, $users, $roleProject, $access, $query) {
    $auth->setIdentity($users['member']);
    $scope = $access->project($roleProject);
    check($scope->roleName === 'Redaktion', 'Role name missing');
    check($scope->allows('comment') && $scope->allows('upload'), 'Granted rights missing');
    denied(403, fn() => $access->project($roleProject, 'write'));
    denied(403, fn() => $access->project($roleProject, 'members'));
    check($query->board($roleProject)['scope']->roleName === 'Redaktion', 'Query lost role');
});
test('custom roles stay project scoped and cannot become owners', function () use ($auth, $users, $roles, $projects, $roleProject, $foreignProject, $customKey) {
    $auth->setIdentity($users['alice']);
    denied(404, fn() => $projects->member($foreignProject, ['email' => 'member@example.test', 'role' => $customKey]));
    denied(422, fn() => $roles->save($roleProject, ['name' => 'Backdoor', 'description' => '', 'permissions' => ['owners']]));
    denied(422, fn() => $roles->save($roleProject, ['name' => 'Owner', 'description' => '', 'permissions' => []]));
});
test('role edits revoke permissions immediately and reject stale versions', function () use ($auth, $users, $roles, $roleProject, $roleId, $access) {
    $data = ['id' => $roleId, 'version' => 1, 'name' => 'Redaktion', 'description' => '', 'permissions' => ['comment']];
    $roles->save($roleProject, $data);
    denied(409, fn() => $roles->save($roleProject, $data));
    $auth->setIdentity($users['member']);
    denied(403, fn() => $access->project($roleProject, 'upload'));
    denied(403, fn() => $roles->save($roleProject, ['name' => 'Self elevation', 'description' => '', 'permissions' => ['write']]));
    $auth->setIdentity($users['alice']);
    denied(422, fn() => $roles->save($roleProject, ['id' => $roleId, 'version' => 2, 'action' => 'delete']));
});
test('delegated member managers cannot grant or remove stronger roles', function () use ($auth, $users, $roles, $projects, $roleProject, $access) {
    $roles->save($roleProject, ['name' => 'Teamhilfe', 'description' => '', 'permissions' => ['members']]);
    $helper = array_values(array_filter($roles->list($roleProject), static fn($role) => $role['name'] === 'Teamhilfe'))[0];
    $projects->member($roleProject, ['email' => 'viewer@example.test', 'role' => 'custom:' . $helper['id']]);
    $auth->setIdentity($users['viewer']);
    $access->project($roleProject, 'members');
    denied(403, fn() => $projects->member($roleProject, ['email' => 'viewer@example.test', 'role' => 'member']));
    denied(403, fn() => $projects->member($roleProject, ['email' => 'alice@example.test', 'role' => 'remove']));
    denied(403, fn() => $projects->member($roleProject, ['email' => 'member@example.test', 'role' => 'remove']));
    $auth->setIdentity($users['alice']);
});
test('AI tool catalog and execution enforce project and current role boundaries', function () use ($auth, $users, $ai, $roleProject, $foreignProject, $projects) {
    $auth->setIdentity($users['member']);
    $definitions = $ai->definitions($roleProject);
    $names       = array_column($definitions, 'name');
    foreach ($definitions as $definition) {
        check(array_diff($definition['meta']['requires'], $names) === [], 'Tool prerequisite is not authorized');
    }
    check(in_array('nafinity_comment', $names, true), 'Allowed comment tool missing');
    check(!in_array('nafinity_ticket_create', $names, true), 'Write tool leaked');
    denied(404, fn() => $ai->definitions($foreignProject));
    denied(403, fn() => $ai->call($roleProject, ['name' => 'nafinity_ticket_create', 'arguments' => [], 'confirmed' => true]));
    $auth->setIdentity($users['alice']);
    $projects->member($roleProject, ['email' => 'member@example.test', 'role' => 'remove']);
    $auth->setIdentity($users['member']);
    denied(404, fn() => $ai->call($roleProject, ['name' => 'nafinity_board', 'arguments' => []]));
    $auth->setIdentity($users['alice']);
});
test('the language picker changes only the language', function () use ($c, $query) {
    $prefs = $c->make(PreferenceService::class);
    $prefs->save([
        'theme'         => 'dark',
        'locale'        => 'de',
        'timezone'      => 'Europe/Lisbon',
        'notify_in_app' => '1',
        'notify_mail'   => '1',
    ]);
    $prefs->language('en');
    $after = $query->preferences();
    check($after['locale'] === 'en', 'language not switched');
    // Everything else has to survive: the picker sends one field, and the full save would
    // otherwise reset theme, timezone and both notification switches to their defaults.
    check($after['theme'] === 'dark', 'theme reset by the language switch');
    check($after['timezone'] === 'Europe/Lisbon', 'timezone reset by the language switch');
    check((int) $after['notify_mail'] === 1, 'mail notifications reset by the language switch');
    denied(422, fn() => $prefs->language('xx'));
    denied(422, fn() => $prefs->language(null));
    // Only languages with a translation file are on offer, never every code the framework knows.
    $available = Locales::available();
    check(array_keys($available) === ['de', 'en'], 'offered languages do not match the translation files');
    check($available['de'] === 'Deutsch' && $available['en'] === 'English', 'names are not written in their own language');
    $prefs->language('de');
});

test('AI writes require confirmation and use normal optimistic concurrency', function () use ($ai, $roleProject, $query, $tickets) {
    $board = $query->board($roleProject);
    $args  = ['title' => 'AI test', 'description' => 'Confirmed action', 'priority' => 'normal', 'column_id' => (int) $board['columns'][0]['id'], 'swimlane_id' => (int) $board['swimlanes'][0]['id'], 'board_revision' => (int) $board['board']['revision']];
    $call  = ['name' => 'nafinity_ticket_create', 'arguments' => $args];
    denied(422, fn() => $ai->call($roleProject, $call));
    $result  = $ai->call($roleProject, [...$call, 'confirmed' => true]);
    $created = $tickets->resolve($roleProject, (string) $result['id']);
    check($tickets->ticket($roleProject, $created)['title'] === 'AI test', 'AI write missing');
    denied(409, fn() => $ai->call($roleProject, [...$call, 'confirmed' => true]));
    denied(422, fn() => $ai->call($roleProject, ['name' => 'nafinity_board', 'arguments' => ['project_id' => 999]]));
});
test('upload recovery discards a staged file after custom upload rights are revoked', function () use ($auth, $users, $roles, $projects, $roleProject, $attachments, $upload, $privateRoot, $query, $tickets) {
    $roles->save($roleProject, ['name' => 'Uploads', 'description' => '', 'permissions' => ['upload']]);
    $uploadRole = array_values(array_filter($roles->list($roleProject), static fn($role) => $role['name'] === 'Uploads'))[0];
    $projects->member($roleProject, ['email' => 'member@example.test', 'role' => 'custom:' . $uploadRole['id']]);
    $board  = $query->board($roleProject);
    $ticket = $tickets->create($roleProject, ['title' => 'Upload revocation', 'description' => '', 'priority' => 'normal', 'column_id' => $board['columns'][0]['id'], 'swimlane_id' => $board['swimlanes'][0]['id'], 'board_revision' => $board['board']['revision']]);
    $ready  = $privateRoot . '/ready';
    $parked = $privateRoot . '/ready-role-test-parked';
    rename($ready, $parked);
    file_put_contents($ready, 'temporary promotion fault');

    try {
        $auth->setIdentity($users['member']);
        $attachments->upload($roleProject, $ticket, $upload('A revoked staged file.'));
        $file = (int) scalar('SELECT MAX(id) FROM attachments');
        check(scalar('SELECT state FROM attachments WHERE id=?', [$file]) === 'staged', 'Upload did not stage');
    } finally {
        unlink($ready);
        rename($parked, $ready);
        $auth->setIdentity($users['alice']);
    }
    $roles->save($roleProject, ['id' => $uploadRole['id'], 'version' => 1, 'name' => 'Uploads', 'description' => '', 'permissions' => []]);
    $attachments->finalize($file);
    check((int) scalar('SELECT COUNT(*) FROM attachments WHERE id=?', [$file]) === 0, 'Revoked upload became visible');
    $projects->member($roleProject, ['email' => 'member@example.test', 'role' => 'remove']);
});
test('unused custom roles can be deleted after membership removal', function () use ($roles, $roleProject, $roleId) {
    $roles->save($roleProject, ['id' => $roleId, 'version' => 2, 'action' => 'delete']);
    check(!in_array($roleId, array_column($roles->list($roleProject), 'id')), 'Deleted role remains');
});
$auth->setIdentity($users['alice']);
