<?php

declare(strict_types=1);
use Naf\Board\Controllers\AiController;
use Naf\Board\Controllers\AttachmentController;
use Naf\Board\Controllers\BoardController;
use Naf\Board\Controllers\ExportController;
use Naf\Board\Controllers\InstallationController;
use Naf\Board\Controllers\InvitationController;
use Naf\Board\Controllers\InvoiceDraftController;
use Naf\Board\Controllers\PasswordResetController;
use Naf\Board\Controllers\PreferenceController;
use Naf\Board\Controllers\ProfileController;
use Naf\Board\Controllers\ProjectController;
use Naf\Board\Controllers\SearchController;
use Naf\Board\Controllers\SessionController;
use Naf\Board\Controllers\SettingsApiController as S;
use Naf\Board\Controllers\TicketController;
use Naf\Board\Controllers\UserAccountController;
use Naf\Board\Controllers\UserDirectoryController;
use Naf\Board\Support\AttachmentStorage;
use Naf\Database\Core\MigrationRunner;
use Naf\Database\Support\MigrationRegistry;

use function Naf\json;
use function Naf\route;

route()->add('GET', '/health/live', static fn() => json(['status' => 'ok']), 'health.live');
route()->add(
    'GET',
    '/health/ready',
    static function () {
        try {
            $pdo = \Naf\app()->container()->get(PDO::class);
            if ((new MigrationRunner($pdo))->pending(MigrationRegistry::getPaths()) !== []) {
                return json(['status' => 'not-ready'], 503);
            }
            $pdo->query('SELECT 1 FROM naf_queue_jobs LIMIT 1');
            $pdo->query('SELECT 1 FROM naf_rate_limits LIMIT 1');
            \Naf\app()->container()->get(AttachmentStorage::class)->check();

            return json(['status' => 'ready']);
        } catch (Throwable) {
            return json(['status' => 'not-ready'], 503);
        }
    },
    'health.ready',
);
route()->add('GET', '/api/settings/application', [S::class, 'readApplication'], 'api.settings.application.read');
route()->add('POST', '/api/settings/application', [S::class, 'writeApplication'], 'api.settings.application.write');
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
route()->add('GET', '/profile/export', [ExportController::class, 'personal'], 'profile.export');
route()->add('GET', '/profile/invoice-export', [ExportController::class, 'invoiceItems'], 'profile.invoice_export');
route()->add('GET', '/exports/invoices', [InvoiceDraftController::class, 'show'], 'invoice.drafts');
route()->add('POST', '/exports/invoices/connections', [InvoiceDraftController::class, 'connect'], 'invoice.connections.create');
route()->add('POST', '/exports/invoices/disconnect', [InvoiceDraftController::class, 'disconnect'], 'invoice.connections.delete');
route()->add('POST', '/exports/invoices/preview', [InvoiceDraftController::class, 'preview'], 'invoice.drafts.preview');
route()->add('POST', '/exports/invoices/send', [InvoiceDraftController::class, 'send'], 'invoice.drafts.send');
route()->add('POST', '/exports/invoices/resolve', [InvoiceDraftController::class, 'resolve'], 'invoice.drafts.resolve');
route()->add('GET', '/profile', [ProfileController::class, 'show'], 'profile');
route()->add('POST', '/profile/password', [ProfileController::class, 'password'], 'profile.password');
route()->add('POST', '/profile/email', [ProfileController::class, 'requestEmail'], 'profile.email');
route()->add('POST', '/profile/email/confirm', [ProfileController::class, 'confirmEmail'], 'profile.email.confirm');
route()->add('POST', '/profile/email/cancel', [ProfileController::class, 'cancelEmail'], 'profile.email.cancel');
// Every page and write of the board: method, path, handler, route name. Names
// are what a host or an extension reuses to replace one of these.
$routes = [
    ['GET', '/', [SessionController::class, 'home'], 'home'],
    ['GET', '/login', [SessionController::class, 'login'], 'login'],
    ['POST', '/login', [SessionController::class, 'authenticate'], 'login.submit'],
    ['POST', '/logout', [SessionController::class, 'logout'], 'logout'],
    ['GET', '/password-reset/{token}', [PasswordResetController::class, 'show'], 'password_reset.show'],
    ['POST', '/password-reset/{token}', [PasswordResetController::class, 'accept'], 'password_reset.accept'],

    ['POST', '/invitations', [InvitationController::class, 'create'], 'invitations.create'],
    ['GET', '/invitations/created', [InvitationController::class, 'created'], 'invitations.created'],
    ['GET', '/invitations/{token}', [InvitationController::class, 'show'], 'invitations.show'],
    ['POST', '/invitations/{token}', [InvitationController::class, 'accept'], 'invitations.accept'],
    ['GET', '/projects/{project}/accounts', [InvitationController::class, 'search'], 'project.accounts'],
    ['POST', '/projects/{project}/invitations/{invitation}/revoke', [InvitationController::class, 'revoke'], 'project.invitations.revoke'],

    ['GET', '/settings', [InstallationController::class, 'settings'], 'installation.settings'],
    ['GET', '/settings/users', [UserDirectoryController::class, 'index'], 'installation.users.index'],
    ['POST', '/settings/users/bulk', [UserDirectoryController::class, 'update'], 'installation.users.bulk'],
    ['GET', '/settings/users/{user}', [UserDirectoryController::class, 'show'], 'installation.users.show'],
    ['POST', '/settings/users/{user}', [UserAccountController::class, 'update'], 'installation.users.account'],
    ['POST', '/settings/users/{user}/password-reset', [UserAccountController::class, 'requestReset'], 'installation.users.password_reset'],
    ['GET', '/settings/users/{user}/password-reset/created', [UserAccountController::class, 'created'], 'installation.users.password_reset.created'],
    ['GET', '/settings/export', [ExportController::class, 'installation'], 'installation.export'],
    ['POST', '/settings/users', [InstallationController::class, 'createAccount'], 'installation.users.create'],
    ['GET', '/audit', [InstallationController::class, 'audit'], 'audit'],

    ['GET', '/preferences', [PreferenceController::class, 'show'], 'preferences'],
    ['POST', '/preferences', [PreferenceController::class, 'save'], 'preferences.save'],
    ['POST', '/preferences/language', [PreferenceController::class, 'language'], 'preferences.language'],
    ['GET', '/notifications', [PreferenceController::class, 'notifications'], 'notifications'],
    ['POST', '/notifications/read', [PreferenceController::class, 'markRead'], 'notifications.read'],
    ['POST', '/projects/{project}/mute', [PreferenceController::class, 'mute'], 'project.mute'],

    ['GET', '/search', [SearchController::class, 'index'], 'workspace.search'],
    ['GET', '/search/suggestions', [SearchController::class, 'suggestions'], 'workspace.suggestions'],
    ['GET', '/projects', [BoardController::class, 'projects'], 'projects'],
    ['POST', '/projects', [ProjectController::class, 'create'], 'projects.create'],
    ['GET', '/projects/{project}', [BoardController::class, 'board'], 'board'],
    ['GET', '/projects/{project}/state', [BoardController::class, 'state'], 'board.state'],
    ['GET', '/projects/{project}/socket', [BoardController::class, 'socket'], 'board.socket'],
    ['GET', '/projects/{project}/cards', [BoardController::class, 'cards'], 'board.cards'],
    ['GET', '/projects/{project}/activity', [BoardController::class, 'activity'], 'project.activity'],
    [
        'GET',
        '/projects/{project}/activity/entries',
        [BoardController::class, 'activityEntries'],
        'project.activity.entries',
    ],

    ['GET', '/projects/{project}/settings', [ProjectController::class, 'settings'], 'project.settings'],
    ['POST', '/projects/{project}/settings', [ProjectController::class, 'update'], 'project.update'],
    ['POST', '/projects/{project}/roles', [ProjectController::class, 'saveRole'], 'project.roles'],
    ['POST', '/projects/{project}/members', [ProjectController::class, 'member'], 'project.members'],
    ['POST', '/projects/{project}/structure', [ProjectController::class, 'structure'], 'project.structure'],
    ['POST', '/projects/{project}/archive', [ProjectController::class, 'archive'], 'project.archive'],
    ['POST', '/projects/{project}/delete', [ProjectController::class, 'delete'], 'project.delete'],

    ['GET', '/projects/{project}/export/{format}', [ExportController::class, 'board'], 'project.export'],
    ['GET', '/projects/{project}/export', [ExportController::class, 'selection'], 'project.export.settings'],

    ['GET', '/projects/{project}/tickets/new', [TicketController::class, 'new'], 'ticket.new'],
    ['POST', '/projects/{project}/tickets', [TicketController::class, 'create'], 'ticket.create'],
    [
        'POST',
        '/projects/{project}/tickets/{ticket}/attachments',
        [AttachmentController::class, 'upload'],
        'attachment.upload',
    ],
    [
        'GET',
        '/projects/{project}/tickets/{ticket}/attachments/{attachment}',
        [AttachmentController::class, 'download'],
        'attachment.download',
    ],
    [
        'POST',
        '/projects/{project}/tickets/{ticket}/attachments/{attachment}/delete',
        [AttachmentController::class, 'delete'],
        'attachment.delete',
    ],
    ['GET', '/projects/{project}/tickets/{ticket}', [TicketController::class, 'show'], 'ticket'],
    ['POST', '/projects/{project}/tickets/{ticket}', [TicketController::class, 'update'], 'ticket.update'],
    ['PATCH', '/projects/{project}/tickets/{ticket}', [TicketController::class, 'update'], 'ticket.patch'],
    ['POST', '/projects/{project}/tickets/{ticket}/move', [TicketController::class, 'move'], 'ticket.move'],
    ['POST', '/projects/{project}/tickets/{ticket}/state', [TicketController::class, 'state'], 'ticket.state'],
    ['POST', '/projects/{project}/tickets/{ticket}/delete', [TicketController::class, 'delete'], 'ticket.delete'],
    [
        'POST',
        '/projects/{project}/tickets/{ticket}/transfer',
        [TicketController::class, 'transfer'],
        'ticket.transfer',
    ],
    [
        'POST',
        '/projects/{project}/tickets/{ticket}/comments',
        [TicketController::class, 'comment'],
        'ticket.comments',
    ],
    ['POST', '/projects/{project}/tickets/{ticket}/links', [TicketController::class, 'link'], 'ticket.links'],
    ['POST', '/projects/{project}/tickets/{ticket}/timer', [TicketController::class, 'timer'], 'ticket.timer'],
];
foreach ($routes as [$method, $path, $handler, $name]) {
    route()->add($method, $path, $handler, $name);
}
