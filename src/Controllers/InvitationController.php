<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Auth\Auth;
use Naf\Auth\Credentials\PasswordCredentials;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\AccountServiceInterface;
use Naf\Board\Contracts\InvitationServiceInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Input;
use Naf\RateLimit\PdoLimiter;
use Naf\Session\Core\Session;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

use function Naf\Board\template;
use function Naf\Form\csrf;
use function Naf\I18n\t;
use function Naf\json;
use function Naf\redirect;
use function Naf\request;
use function Naf\View\render;

/** @internal */
final class InvitationController
{
    public function __construct(
        private InvitationServiceInterface $invitations,
        private AccessInterface $access,
        private AccountServiceInterface $accounts,
        private Auth $auth,
        private Session $session,
        private PageRendererInterface $pages,
        private PdoLimiter $limiter,
    ) {
    }

    public function create(): ResponseInterface
    {
        return $this->privateResponse(Respond::mutation($this->access, function (array $data): array {
            $result = $this->invitations->create(Input::id($data['project_id'] ?? null, 'project_id'), $data);
            $this->session->flash('invitation.created', [...$result, 'actor' => $this->access->actor()]);

            return ['url' => '/invitations/created', ...$result];
        }));
    }

    public function created(): ResponseInterface
    {
        return $this->privateResponse(Respond::read(function (): ResponseInterface {
            $actor  = $this->access->actor();
            $result = $this->session->getFlash('invitation.created');
            if (!is_array($result) || $actor !== $result['actor']) {
                return redirect('/projects', 303);
            }
            $this->access->project((int) $result['project_id'], 'members');

            return $this->pages->render('invitation-created', ['title' => 'Einladung erstellt', 'invitation' => $result]);
        }));
    }

    public function show(#[SensitiveParameter] string $token): ResponseInterface
    {
        return $this->preview($token);
    }

    public function accept(#[SensitiveParameter] string $token): ResponseInterface
    {
        $body = [];

        try {
            $peer = request()->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
            if (!$this->limiter->consume('invitation:peer:' . $peer, 100, 900)['allowed']) {
                throw new Failure(t('Zu viele Versuche. Bitte versuche es später erneut.'), 429);
            }
            $body   = Input::body();
            $result = $this->invitations->accept($token, $body);
            if ($result['created']) {
                if (!$this->accounts->authenticate(new PasswordCredentials($result['email'], $body['password']), 'users')) {
                    return $this->privateResponse(redirect('/login', 303));
                }
                csrf()->generate();
            }

            return $this->privateResponse(redirect('/projects/' . $result['project_id'], 303));
        } catch (Failure $failure) {
            return $this->preview($token, $failure, is_string($body['name'] ?? null) ? $body['name'] : '');
        }
    }

    public function revoke(string $project, string $invitation): ResponseInterface
    {
        return Respond::mutation($this->access, function () use ($project, $invitation): array {
            $id = Input::id($project);
            $this->invitations->revoke($id, Input::id($invitation));

            return ['url' => '/projects/' . $id . '/settings#users'];
        });
    }

    public function search(string $project): ResponseInterface
    {
        return $this->privateResponse(Respond::read(function () use ($project): ResponseInterface {
            $query = request()->getQueryParams()['q'] ?? '';

            return json(['accounts' => $this->invitations->search(Input::id($project), is_string($query) ? $query : '')]);
        }));
    }

    private function preview(#[SensitiveParameter] string $token, ?Failure $error = null, string $name = ''): ResponseInterface
    {
        $invitation = null;

        try {
            $invitation = $this->invitations->find($token);
        } catch (Failure $failure) {
            $error = $failure;
        }
        $email = $this->auth->user()?->getProfile()->email;

        return $this->privateResponse(render(template('invitation'), [
            'invitation' => $invitation,
            'token'      => $token,
            'error'      => $error?->getMessage(),
            'name'       => mb_substr($name, 0, 120),
            'signedIn'   => $this->auth->check(),
            'matching'   => $invitation !== null && $email !== null && strtolower($email) === $invitation['email'],
        ])->withStatus($error?->status ?? 200));
    }

    private function privateResponse(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
