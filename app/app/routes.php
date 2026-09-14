<?php

declare(strict_types=1);
use App\Controllers\AppController as C;

use function Naf\{route,json};

route()->add('GET', '/health/live', static fn () => json(['status' => 'ok']), 'health.live');
route()->add('GET', '/health/ready', static function () {
    try {
        $pdo = \Naf\app()->container()->get(PDO::class);
        $required = [\App\Migrations\M202609140001Nafinity::class, \App\Migrations\M202609140002Queue::class, \App\Migrations\M202609140003RateLimits::class];
        $applied = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($required, $applied)) {
            return json(['status' => 'not-ready'], 503);
        }
        $pdo->query('SELECT 1 FROM naf_queue_jobs LIMIT 1');
        $pdo->query('SELECT 1 FROM naf_rate_limits LIMIT 1');
        \Naf\app()->container()->make(\App\Services\AttachmentService::class);
        return json(['status' => 'ready', 'schema' => '202609140003']);
    } catch (\Throwable) {
        return json(['status' => 'not-ready'], 503);
    }
}, 'health.ready');
$routes = [
 ['GET','/notifications','notifications','notifications'],['POST','/notifications/read','markNotificationsRead','notifications.read'],
 ['POST','/projects/{project}/mute','muteProject','project.mute'],

 ['GET','/','home','home'],['GET','/login','login','login'],['POST','/login','authenticate','login.submit'],['POST','/logout','logout','logout'],
 ['GET','/projects','projectList','projects'],['POST','/projects','createProject','projects.create'],
 ['GET','/preferences','preferences','preferences'],['POST','/preferences','savePreferences','preferences.save'],
 ['GET','/projects/{project}','board','board'],['GET','/projects/{project}/settings','settings','project.settings'],
 ['POST','/projects/{project}/settings','updateProject','project.update'],['POST','/projects/{project}/members','member','project.members'],
 ['POST','/projects/{project}/structure','structure','project.structure'],['POST','/projects/{project}/archive','archiveProject','project.archive'],
 ['GET','/projects/{project}/activity','activity','project.activity'],['GET','/projects/{project}/state','boardState','board.state'],
 ['GET','/projects/{project}/tickets/new','newTicket','ticket.new'],['POST','/projects/{project}/tickets','createTicket','ticket.create'],
 ['POST','/projects/{project}/tickets/{ticket}/attachments','upload','attachment.upload'],
 ['GET','/projects/{project}/tickets/{ticket}/attachments/{attachment}','download','attachment.download'],
 ['POST','/projects/{project}/tickets/{ticket}/attachments/{attachment}/delete','deleteAttachment','attachment.delete'],
 ['GET','/projects/{project}/tickets/{ticket}','ticket','ticket'],['POST','/projects/{project}/tickets/{ticket}','updateTicket','ticket.update'],
 ['PATCH','/projects/{project}/tickets/{ticket}','updateTicket','ticket.patch'],['POST','/projects/{project}/tickets/{ticket}/move','moveTicket','ticket.move'],
 ['POST','/projects/{project}/tickets/{ticket}/state','ticketState','ticket.state'],['POST','/projects/{project}/tickets/{ticket}/comments','comment','ticket.comments'],
];
foreach ($routes as [$method,$path,$action,$name]) {
    route()->add($method, $path, [C::class,$action], $name);
}

if (getenv('APP_ENV') === 'test') {
    require dirname(__DIR__).'/tests/http_routes.php';
}
