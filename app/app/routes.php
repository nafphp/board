<?php

declare(strict_types=1);
use App\Controllers\AiController;
use App\Controllers\AppController as C;
use App\Controllers\ProfileController;
use App\Migrations\M202609140001Nafinity;
use App\Migrations\M202609140002Queue;
use App\Migrations\M202609140003RateLimits;
use App\Migrations\M202609150001ProjectRoles;
use App\Migrations\M202609150002AccountProfile;
use App\Services\AttachmentService;

use function Naf\json;
use function Naf\route;

route()->add('GET', '/health/live', static fn() => json(['status' => 'ok']), 'health.live');
route()->add(
    'GET',
    '/health/ready',
    static function () {
        try {
            $pdo      = \Naf\app()->container()->get(PDO::class);
            $required = [
                M202609140001Nafinity::class,
                M202609140002Queue::class,
                M202609140003RateLimits::class,
                M202609150001ProjectRoles::class,
                M202609150002AccountProfile::class,
            ];
            $applied = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff($required, $applied)) {
                return json(['status' => 'not-ready'], 503);
            }
            $pdo->query('SELECT 1 FROM naf_queue_jobs LIMIT 1');
            $pdo->query('SELECT 1 FROM naf_rate_limits LIMIT 1');
            \Naf\app()->container()->make(AttachmentService::class);

            return json(['status' => 'ready', 'schema' => '202609150002']);
        } catch (Throwable) {
            return json(['status' => 'not-ready'], 503);
        }
    },
    'health.ready',
);
route()->add('GET', '/ai/tools', [AiController::class, 'tools'], 'ai.tools');
route()->add('POST', '/ai/tools/call', [AiController::class, 'call'], 'ai.call');
route()->add('GET', '/profile', [ProfileController::class, 'show'], 'profile');
route()->add('POST', '/profile/password', [ProfileController::class, 'password'], 'profile.password');
route()->add('POST', '/profile/email', [ProfileController::class, 'requestEmail'], 'profile.email');
route()->add('POST', '/profile/email/confirm', [ProfileController::class, 'confirmEmail'], 'profile.email.confirm');
route()->add('POST', '/profile/email/cancel', [ProfileController::class, 'cancelEmail'], 'profile.email.cancel');
$routes = [
    ['GET', '/notifications', 'notifications', 'notifications'],
    ['POST', '/notifications/read', 'markNotificationsRead', 'notifications.read'],
    ['POST', '/projects/{project}/mute', 'muteProject', 'project.mute'],

    ['GET', '/', 'home', 'home'],
    ['GET', '/login', 'login', 'login'],
    ['POST', '/login', 'authenticate', 'login.submit'],
    ['POST', '/logout', 'logout', 'logout'],
    ['GET', '/projects', 'projectList', 'projects'],
    ['POST', '/projects', 'createProject', 'projects.create'],
    ['GET', '/preferences', 'preferences', 'preferences'],
    ['POST', '/preferences', 'savePreferences', 'preferences.save'],
    ['GET', '/projects/{project}', 'board', 'board'],
    ['GET', '/projects/{project}/settings', 'settings', 'project.settings'],
    ['POST', '/projects/{project}/settings', 'updateProject', 'project.update'],
    ['POST', '/projects/{project}/roles', 'saveRole', 'project.roles'],
    ['POST', '/projects/{project}/members', 'member', 'project.members'],
    ['POST', '/projects/{project}/structure', 'structure', 'project.structure'],
    ['POST', '/projects/{project}/archive', 'archiveProject', 'project.archive'],
    ['GET', '/projects/{project}/activity', 'activity', 'project.activity'],
    ['GET', '/projects/{project}/state', 'boardState', 'board.state'],
    ['GET', '/projects/{project}/tickets/new', 'newTicket', 'ticket.new'],
    ['POST', '/projects/{project}/tickets', 'createTicket', 'ticket.create'],
    ['POST', '/projects/{project}/tickets/{ticket}/attachments', 'upload', 'attachment.upload'],
    [
        'GET',
        '/projects/{project}/tickets/{ticket}/attachments/{attachment}',
        'download',
        'attachment.download',
    ],
    [
        'POST',
        '/projects/{project}/tickets/{ticket}/attachments/{attachment}/delete',
        'deleteAttachment',
        'attachment.delete',
    ],
    ['GET', '/projects/{project}/tickets/{ticket}', 'ticket', 'ticket'],
    ['POST', '/projects/{project}/tickets/{ticket}', 'updateTicket', 'ticket.update'],
    ['PATCH', '/projects/{project}/tickets/{ticket}', 'updateTicket', 'ticket.patch'],
    ['POST', '/projects/{project}/tickets/{ticket}/move', 'moveTicket', 'ticket.move'],
    ['POST', '/projects/{project}/tickets/{ticket}/state', 'ticketState', 'ticket.state'],
    ['POST', '/projects/{project}/tickets/{ticket}/comments', 'comment', 'ticket.comments'],
];
foreach ($routes as [$method, $path, $action, $name]) {
    route()->add($method, $path, [C::class, $action], $name);
}

if (getenv('APP_ENV') === 'test') {
    require dirname(__DIR__) . '/tests/http_routes.php';
}
