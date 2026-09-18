<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Failure;
use App\Support\Input;
use Naf\Auth\Auth;
use Naf\Auth\Credentials\PasswordCredentials;
use Naf\Auth\Exceptions\UnauthenticatedException;
use Naf\RateLimit\PdoLimiter;
use Naf\Session\Core\Session;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AccountServiceInterface;
use Nafinity\Contracts\AttachmentServiceInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\CommentServiceInterface;
use Nafinity\Contracts\NotificationServiceInterface;
use Nafinity\Contracts\PageRendererInterface;
use Nafinity\Contracts\PreferenceServiceInterface;
use Nafinity\Contracts\ProjectServiceInterface;
use Nafinity\Contracts\RoleServiceInterface;
use Nafinity\Contracts\TicketServiceInterface;
use Nafinity\Contracts\TimerServiceInterface;
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
use function Nafinity\extensions;
use function Nafinity\template;

final class AppController
{
    public function __construct(
        private Auth $auth,
        private BoardQueryInterface $query,
        private ProjectServiceInterface $projects,
        private TicketServiceInterface $tickets,
        private TimerServiceInterface $timers,
        private CommentServiceInterface $comments,
        private AccessInterface $access,
        private PDO $pdo,
        private AttachmentServiceInterface $attachments,
        private NotificationServiceInterface $notifications,
        private PreferenceServiceInterface $prefs,
        private PdoLimiter $limiter,
        private RoleServiceInterface $roles,
        private AccountServiceInterface $accounts,
        private Session $session,
        private PageRendererInterface $pages,
    ) {
    }

    public function login(): ResponseInterface
    {
        if ($this->auth->check()) {
            return redirect('/', 303);
        }

        return render(template('login'), ['error' => null, 'email' => '', 'notice' => $this->session->getFlash('account.notice')])->withHeader('Cache-Control', 'no-store');
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

                return render(template('login'), [
                    'error' => 'Zu viele Anmeldeversuche. Bitte versuche es später erneut.',
                    'email' => $data['email'],
                ])
                    ->withStatus(429)
                    ->withHeader('Retry-After', (string) $retryAfter);
            }

            $useLdap     = ($body['provider'] ?? 'users') === 'ldap' && config('ldap:enabled', false);
            $provider    = $useLdap ? 'ldap' : 'users';
            $credentials = new PasswordCredentials($email, $data['password']);

            if (!$this->accounts->authenticate($credentials, $provider)) {
                return render(template('login'), [
                    'error' => 'E-Mail oder Passwort stimmt nicht.',
                    'email' => $data['email'],
                ])->withStatus(401);
            }

            csrf()->generate();

            return redirect('/', 303);
        } catch (Failure $exception) {
            return render(template('login'), ['error' => $exception->getMessage(), 'email' => ''])->withStatus(
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
            $details   = $this->query->detail($projectId, $this->tickets->resolve($projectId, $ticket));
            $fragment  = (request()->getQueryParams()['fragment'] ?? '') === '1';

            return $this->page('ticket', [
                'title' => 'Ticket',
                ...$boardData,
                ...$details,
                'mode'     => 'detail',
                'fragment' => $fragment,
                'timer'    => $this->timers->state($projectId, $details['ticket']['id']),
                'targets'  => $this->query->transferTargets($projectId),
            ]);
        });
    }

    public function newTicket(string $project): ResponseInterface
    {
        return $this->read(function () use ($project) {
            $projectId = Input::id($project);
            $this->access->project($projectId, 'write');

            return $this->page('ticket', [
                'title' => 'Neues Ticket',
                ...$this->query->board($projectId),
                'mode'               => 'create',
                'ticket'             => null,
                'fragment'           => (request()->getQueryParams()['fragment'] ?? '') === '1',
                'selected_labels'    => [],
                'selected_assignees' => [],
                'metadata'           => [],
                'metaDefinitions'    => array_filter(
                    extensions()->ticketFields()->metadata(),
                    static fn($field) => $field->showOnCreate,
                ),
                'metaUnknown' => [],
            ]);
        });
    }

    public function settings(string $project): ResponseInterface
    {
        return $this->read(function () use ($project) {
            $id    = Input::id($project);
            $scope = $this->access->project($id);

            return $this->page('settings', [
                'title'       => 'Einstellungen',
                'customRoles' => $this->roles->list($id),
                ...$this->query->board($id),
                'offScale' => $this->projects->offScaleEstimates(
                    $id,
                    $scope->project['estimation_scale'] ?? null,
                ),
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
            $projectId = Input::id($project);
            $id        = $this->tickets->create($projectId, $data);
            $reference = $this->tickets->reference($projectId, $id);

            return [
                'url' => '/projects/' . $project . '/tickets/' . $reference,
                'id'  => $reference,
            ];
        });
    }

    public function updateTicket(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->tickets->update($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket];
        });
    }

    public function linkTicket(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->tickets->link($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => \Naf\route('ticket', ['project' => $project, 'ticket' => $ticket])];
        });
    }

    public function saveLanguage(): ResponseInterface
    {
        return $this->mutation(function ($data) {
            $locale = $data['locale'] ?? null;
            $this->prefs->language($locale);

            // No address is returned on purpose: the page the picker sits on reloads itself,
            // and a destination taken from a request header would be an open redirect.
            return ['locale' => $locale];
        });
    }

    public function ticketTimer(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $state     = $this->timers->act(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
                $data,
            );

            return [
                ...$state,
                'url' => \Naf\route('ticket', ['project' => $project, 'ticket' => $ticket]),
            ];
        });
    }

    public function moveTicket(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $id       = Input::id($project);
            $ticketId = $this->tickets->resolve($id, $ticket);
            $this->tickets->move($id, $ticketId, $data);
            $moved = $this->tickets->ticket($id, $ticketId);

            // The board applies the move in place, so it needs the fresh optimistic lock values.
            return [
                'url'      => '/projects/' . $project,
                'revision' => (string) $this->tickets->board($id)['revision'],
                'version'  => (string) $moved['version'],
                'status'   => $moved['status'],
            ];
        });
    }

    public function transferTicket(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $moved     = $this->tickets->transfer(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
                $data,
            );

            // The address it was read from no longer answers, so the whole page follows it.
            return [
                'url' => \Naf\route('ticket', [
                    'project' => $moved['project'],
                    'ticket'  => $moved['reference'],
                ]),
            ];
        });
    }

    public function ticketState(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->tickets->state($projectId, $this->tickets->resolve($projectId, $ticket), $data);

            return ['url' => '/projects/' . $project . '/tickets/' . $ticket];
        });
    }

    public function comment(string $project, string $ticket): ResponseInterface
    {
        return $this->mutation(function ($data) use ($project, $ticket) {
            $projectId = Input::id($project);
            $this->comments->save($projectId, $this->tickets->resolve($projectId, $ticket), $data);

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
            $projectId = Input::id($project);
            $this->attachments->upload($projectId, $this->tickets->resolve($projectId, $ticket), $file);

            return ['url' => \Naf\route('ticket', ['project' => $project, 'ticket' => $ticket])];
        });
    }

    public function deleteAttachment(
        string $project,
        string $ticket,
        string $attachment,
    ): ResponseInterface {
        return $this->mutation(function () use ($project, $ticket, $attachment) {
            $projectId = Input::id($project);
            $this->attachments->remove(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
                Input::id($attachment),
            );

            return ['url' => \Naf\route('ticket', ['project' => $project, 'ticket' => $ticket])];
        });
    }

    public function download(string $project, string $ticket, string $attachment): ResponseInterface
    {
        return $this->read(function () use ($project, $ticket, $attachment) {
            $projectId = Input::id($project);
            $file      = $this->attachments->download(
                $projectId,
                $this->tickets->resolve($projectId, $ticket),
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
        return $this->pages->render($template, $data);
    }

    private function read(callable $read): ResponseInterface
    {
        try {
            return $read();
        } catch (UnauthenticatedException) {
            return redirect('/login', 303);
        } catch (Failure $exception) {
            return render(template('error'), [
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

            return render(template('error'), [
                'message' => $exception->getMessage(),
                'status'  => $exception->status,
            ])->withStatus($exception->status);
        }
    }
}
