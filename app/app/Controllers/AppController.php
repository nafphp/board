<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Failure;
use App\Services\Access;
use App\Services\AttachmentService;
use App\Services\BoardQuery;
use App\Services\CommentService;
use App\Services\NotificationService;
use App\Services\PreferenceService;
use App\Services\ProjectService;
use App\Services\RoleService;
use App\Services\TicketService;
use App\Support\Input;
use Naf\Auth\Auth;
use Naf\Auth\Credentials\PasswordCredentials;
use Naf\Auth\Exceptions\UnauthenticatedException;
use Naf\RateLimit\PdoLimiter;
use Nyholm\Psr7\Stream;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

use function Naf\config;
use function Naf\Form\csrf;
use function Naf\json;
use function Naf\redirect;
use function Naf\request;
use function Naf\View\render;

final class AppController
{
    public function __construct(
        private Auth $auth,
        private BoardQuery $query,
        private ProjectService $projects,
        private TicketService $tickets,
        private CommentService $comments,
        private Access $access,
        private PDO $pdo,
        private AttachmentService $attachments,
        private NotificationService $notifications,
        private PreferenceService $prefs,
        private PdoLimiter $limiter,
        private RoleService $roles,
    ) {
    }

    public function login(): ResponseInterface
    {
        if ($this->auth->check()) {
            return redirect('/', 303);
        }

        return render('login', ['error' => null, 'email' => '']);
    }

    public function authenticate(): ResponseInterface
    {
        try {
            $body = Input::body();
            $data = Input::validate($body, [
                'email'    => 'required|string|email|max:190',
                'password' => 'required|string|max:1024',
            ]);
            $email        = strtolower(trim($data['email']));
            $peer         = request()->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
            $ipLimit      = $this->limiter->consume('login:ip:' . $peer, 100, 600);
            $accountLimit = $this->limiter->consume('login:account:' . $email, 10, 600);

            if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
                $retryAfter = max($ipLimit['retry_after'], $accountLimit['retry_after']);

                return render('login', [
                    'error' => 'Zu viele Anmeldeversuche. Bitte versuche es später erneut.',
                    'email' => $data['email'],
                ])
                    ->withStatus(429)
                    ->withHeader('Retry-After', (string) $retryAfter);
            }

            $useLdap     = ($body['provider'] ?? 'users') === 'ldap' && config('ldap:enabled', false);
            $provider    = $useLdap ? 'ldap' : 'users';
            $credentials = new PasswordCredentials($email, $data['password']);

            if (!$this->auth->authenticate($credentials, $provider)) {
                return render('login', [
                    'error' => 'E-Mail oder Passwort stimmt nicht.',
                    'email' => $data['email'],
                ])->withStatus(401);
            }

            csrf()->generate();

            return redirect('/', 303);
        } catch (Failure $exception) {
            return render('login', ['error' => $exception->getMessage(), 'email' => ''])->withStatus(
                $exception->status,
            );
        }
    }

    public function logout(): ResponseInterface
    {
        $this->auth->logout();
        csrf()->generate();

        return redirect('/login', 303);
    }

    public function home(): ResponseInterface
    {
        if (!$this->auth->check()) {
            return redirect('/login', 303);
        }
        $projects = $this->query->projects();
        if ($projects) {
            return redirect('/projects/' . $projects[0]['id'], 303);
        }

        return $this->page('projects', ['title' => 'Deine Projekte']);
    }

    public function projectList(): ResponseInterface
    {
        return $this->page('projects', ['title' => 'Deine Projekte']);
    }

    public function board(string $project): ResponseInterface
    {
        return $this->read(
            fn() => $this->page('board', [
                'title' => 'Board',
                ...$this->query->board(Input::id($project), request()->getQueryParams()),
            ]),
        );
    }

    public function ticket(string $project, string $ticket): ResponseInterface
    {
        return $this->read(function () use ($project, $ticket) {
            $projectId = Input::id($project);
            $boardData = $this->query->board($projectId);
            $details   = $this->query->detail($projectId, Input::id($ticket));
            $fragment  = (request()->getQueryParams()['fragment'] ?? '') === '1';

            return $this->page('ticket', [
                'title' => 'Ticket',
                ...$boardData,
                ...$details,
                'fragment' => $fragment,
            ]);
        });
    }

    public function newTicket(string $project): ResponseInterface
    {
        return $this->read(
            fn() => $this->page('ticket', [
                'title' => 'Neues Ticket',
                ...$this->query->board(Input::id($project)),
                'ticket'             => null,
                'fragment'           => false,
                'selected_labels'    => [],
                'selected_assignees' => [],
                'comments'           => [],
                'activity'           => [],
                'attachments'        => [],
            ]),
        );
    }

    public function settings(string $project): ResponseInterface
    {
        return $this->read(function () use ($project) {
            $id = Input::id($project);
            $this->access->project($id);

            return $this->page('settings', [
                'title'       => 'Einstellungen',
                'customRoles' => $this->roles->list($id),
                ...$this->query->board($id),
            ]);
        });
    }

    public function activity(string $project): ResponseInterface
    {
        return $this->read(
            fn() => $this->page('activity', [
                'title' => 'Aktivität',
                ...$this->query->board(Input::id($project)),
                'activity' => $this->query->activity(Input::id($project)),
            ]),
        );
    }

    public function boardState(string $project): ResponseInterface
    {
        return $this->read(function () use ($project) {
            $id = Input::id($project);
            $this->access->project($id);

            return json(['revision' => (string) $this->tickets->board($id)['revision']]);
        });
    }

    public function createProject(): ResponseInterface
    {
        return $this->mutation(function ($data) {
            $id = $this->projects->create($data);

            return ['url' => '/projects/' . $id];
        });
    }

    public function updateProject(string $project): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project) {
            $this->projects->update(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings'];
        });
    }

    public function member(string $project): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project) {
            $this->projects->member(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings'];
        });
    }

    public function saveRole(string $project): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project) {
            $this->roles->save(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings#roles'];
        });
    }

    public function structure(string $project): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project) {
            $this->projects->structure(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings'];
        });
    }

    public function archiveProject(string $project): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project) {
            $this->projects->archive(
                Input::id($project),
                ($data['action'] ?? 'archive') !== 'restore',
            );

            return ['url' => '/projects/' . $project];
        });
    }

    public function createTicket(string $project): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project) {
            $id = $this->tickets->create(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $id, 'id' => (string) $id];
        });
    }

    public function updateTicket(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $this->tickets->update(Input::id($project), Input::id($ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket];
        });
    }

    public function moveTicket(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $id = Input::id($project);
            $this->tickets->move($id, Input::id($ticket), $data);

            return [
                'url'      => '/projects/' . $project,
                'revision' => (string) $this->tickets->board($id)['revision'],
            ];
        });
    }

    public function ticketState(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $this->tickets->state(Input::id($project), Input::id($ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket];
        });
    }

    public function comment(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $this->comments->save(Input::id($project), Input::id($ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket . '#comments'];
        });
    }

    public function preferences(): ResponseInterface
    {
        return $this->page('settings', ['title' => 'Einstellungen']);
    }

    public function savePreferences(): ResponseInterface
    {
        return $this->mutation(function ($data) {
            $this->prefs->save($data);

            return ['url' => \Naf\route('preferences')];
        });
    }

    public function notifications(): ResponseInterface
    {
        return $this->read(
            fn() => $this->page('notifications', [
                'title'         => 'Benachrichtigungen',
                'notifications' => $this->notifications->list(),
            ]),
        );
    }

    public function markNotificationsRead(): ResponseInterface
    {
        return $this->mutation(function () {
            $this->notifications->markRead();

            return ['url' => \Naf\route('notifications')];
        });
    }

    public function muteProject(string $project): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project) {
            $this->prefs->mute(Input::id($project), ($data['muted'] ?? '') === '1');

            return ['url' => \Naf\route('notifications')];
        });
    }

    public function upload(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function () use ($project, $ticket) {
            $file = request()->getUploadedFiles()['attachment'] ?? null;
            if (!($file instanceof UploadedFileInterface)) {
                throw new Failure('Bitte wähle eine Datei.');
            }
            $this->attachments->upload(Input::id($project), Input::id($ticket), $file);

            return ['url' => \Naf\route('ticket', ['project' => $project, 'ticket' => $ticket])];
        });
    }

    public function deleteAttachment(
        string $project,
        string $ticket,
        string $attachment,
    ): ResponseInterface {
        return $this->mutation(function () use ($project, $ticket, $attachment) {
            $this->attachments->remove(
                Input::id($project),
                Input::id($ticket),
                Input::id($attachment),
            );

            return ['url' => \Naf\route('ticket', ['project' => $project, 'ticket' => $ticket])];
        });
    }

    public function download(string $project, string $ticket, string $attachment): ResponseInterface
    {
        return $this->read(function () use ($project, $ticket, $attachment) {
            $file = $this->attachments->download(
                Input::id($project),
                Input::id($ticket),
                Input::id($attachment),
            );
            $downloadName = rawurlencode($file['original_name']);
            $disposition  = "attachment; filename=\"download\"; filename*=UTF-8''" . $downloadName;

            return \Naf\response()
                ->withBody(Stream::create($file['stream']))
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Length', (string) $file['byte_size'])
                ->withHeader('Content-Disposition', $disposition)
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        });
    }

    private function page(string $template, array $data): ResponseInterface
    {
        if (!$this->auth->check()) {
            return redirect('/login', 303);
        }
        $preferences = $this->query->preferences();
        \Naf\I18n\translator()->setLanguage($preferences['locale']);

        return render($template, [
            ...$data,
            'projects'    => $this->query->projects(),
            'user'        => $this->auth->user(),
            'preferences' => $preferences,
        ]);
    }

    private function read(callable $read): ResponseInterface
    {
        try {
            return $read();
        } catch (UnauthenticatedException) {
            return redirect('/login', 303);
        } catch (Failure $exception) {
            return render('error', [
                'message' => $exception->getMessage(),
                'status'  => $exception->status,
            ])->withStatus($exception->status);
        }
    }

    private function mutation(callable $operation): ResponseInterface
    {
        $acceptsJson = str_contains(request()->getHeaderLine('Accept'), 'application/json');

        try {
            $this->access->actor();
            $result = $operation(Input::body());

            return $acceptsJson
                ? json($result)
                : redirect($result['url'], 303);
        } catch (Failure $exception) {
            if ($acceptsJson) {
                return json([
                    'message' => $exception->getMessage(),
                    'errors'  => $exception->errors,
                ], $exception->status);
            }

            return render('error', [
                'message' => $exception->getMessage(),
                'status'  => $exception->status,
            ])->withStatus($exception->status);
        }
    }
}
