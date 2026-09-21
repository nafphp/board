<?php

declare(strict_types=1);
use Naf\Board\Contracts\AttachmentServiceInterface;
use Naf\Board\Controllers\AiController;
use Naf\Board\Controllers\AppController as C;
use Naf\Board\Controllers\ProfileController;
use Naf\Board\Controllers\SettingsApiController as S;
use Naf\Board\Migrations\M202609140001Nafinity;
use Naf\Board\Migrations\M202609140002Queue;
use Naf\Board\Migrations\M202609140003RateLimits;
use Naf\Board\Migrations\M202609150001ProjectRoles;
use Naf\Board\Migrations\M202609150002AccountProfile;
use Naf\Board\Migrations\M202609160001TicketDetails;
use Naf\Board\Migrations\M202609160002RichTextStorage;
use Naf\Board\Migrations\M202609160003ProjectTicketKey;
use Naf\Board\Migrations\M202609170001EstimationScale;
use Naf\Board\Migrations\M202609170002TicketTimers;
use Naf\Board\Migrations\M202609180001TicketTransfer;
use Naf\Board\Migrations\M202609180002PluginSettings;
use Naf\Board\Migrations\M202609180003TicketMetadata;
use Naf\Board\Migrations\M202609180004ScaleIdentifiers;

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
                M202609160001TicketDetails::class,
                M202609160002RichTextStorage::class,
                M202609160003ProjectTicketKey::class,
                M202609170001EstimationScale::class,
                M202609170002TicketTimers::class,
                M202609180001TicketTransfer::class,
                M202609180002PluginSettings::class,
                M202609180003TicketMetadata::class,
                M202609180004ScaleIdentifiers::class,
            ];
            $applied = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            if (array_diff($required, $applied)) {
                return json(['status' => 'not-ready'], 503);
            }
            $pdo->query('SELECT 1 FROM naf_queue_jobs LIMIT 1');
            $pdo->query('SELECT 1 FROM naf_rate_limits LIMIT 1');
            \Naf\app()->container()->get(AttachmentServiceInterface::class);

            return json(['status' => 'ready', 'schema' => '202609180004']);
        } catch (Throwable) {
            return json(['status' => 'not-ready'], 503);
        }
    },
    'health.ready',
);
route()->add('GET', '/api/settings/user', [S::class, 'readUser'], 'api.settings.user.read');
route()->add('POST', '/api/settings/user', [S::class, 'writeUser'], 'api.settings.user.write');
route()->add(
    'GET',
    '/api/projects/{project}/settings',
    [S::class, 'readProject'],
    'api.settings.project.read',
);
route()->add(
    'POST',
    '/api/projects/{project}/settings',
    [S::class, 'writeProject'],
    'api.settings.project.write',
);
route()->add(
    'GET',
    '/api/projects/{project}/settings/user',
    [S::class, 'readProjectUser'],
    'api.settings.project_user.read',
);
route()->add(
    'POST',
    '/api/projects/{project}/settings/user',
    [S::class, 'writeProjectUser'],
    'api.settings.project_user.write',
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
    ['GET', '/settings', 'installation', 'installation.settings'],
    ['POST', '/settings/users', 'createAccount', 'installation.users.create'],
    ['GET', '/audit', 'audit', 'audit'],
    ['GET', '/preferences', 'preferences', 'preferences'],
    ['POST', '/preferences', 'savePreferences', 'preferences.save'],
    ['POST', '/preferences/language', 'saveLanguage', 'preferences.language'],
    ['GET', '/projects/{project}', 'board', 'board'],
    ['GET', '/projects/{project}/settings', 'settings', 'project.settings'],
    ['POST', '/projects/{project}/settings', 'updateProject', 'project.update'],
    ['POST', '/projects/{project}/roles', 'saveRole', 'project.roles'],
    ['POST', '/projects/{project}/members', 'member', 'project.members'],
    ['POST', '/projects/{project}/structure', 'structure', 'project.structure'],
    ['POST', '/projects/{project}/archive', 'archiveProject', 'project.archive'],
    ['GET', '/projects/{project}/activity', 'activity', 'project.activity'],
    ['GET', '/projects/{project}/activity/entries', 'activityEntries', 'project.activity.entries'],
    ['GET', '/projects/{project}/state', 'boardState', 'board.state'],
    ['GET', '/projects/{project}/socket', 'boardSocket', 'board.socket'],
    ['GET', '/projects/{project}/cards', 'boardCards', 'board.cards'],
    ['GET', '/projects/{project}/export/{format}', 'export', 'project.export'],
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
    ['POST', '/projects/{project}/tickets/{ticket}/delete', 'deleteTicket', 'ticket.delete'],
    ['POST', '/projects/{project}/tickets/{ticket}/transfer', 'transferTicket', 'ticket.transfer'],
    ['POST', '/projects/{project}/tickets/{ticket}/comments', 'comment', 'ticket.comments'],
    ['POST', '/projects/{project}/tickets/{ticket}/links', 'linkTicket', 'ticket.links'],
    ['POST', '/projects/{project}/tickets/{ticket}/timer', 'ticketTimer', 'ticket.timer'],
];
foreach ($routes as [$method, $path, $action, $name]) {
    route()->add($method, $path, [C::class, $action], $name);
}

if (getenv('APP_ENV') === 'test') {
    require dirname(__DIR__) . '/tests/http_routes.php';
}
