<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Services\UserAdministration;
use Naf\Board\Support\Input;
use Naf\Session\Core\Session;
use Psr\Http\Message\ResponseInterface;

use function Naf\Form\csrf;
use function Naf\I18n\t;
use function Naf\redirect;

/** @internal */
final class UserAccountController
{
    public function __construct(
        private UserAdministration $users,
        private AccessInterface $access,
        private PageRendererInterface $pages,
        private Auth $auth,
        private Session $session,
    ) {
    }

    public function update(string $user): ResponseInterface
    {
        return $this->privateResponse(Respond::mutation($this->access, function (array $data) use ($user): array {
            $id = Input::id($user);
            if ($this->users->update($id, $data)) {
                $this->auth->logout();
                csrf()->generate();
                $this->session->flash('account.notice', t('Dein Konto wurde geändert. Bitte melde dich erneut an.'));

                return ['url' => '/login'];
            }

            return ['url' => '/settings#installation_users', 'editor' => $this->pages->fragment('settings/user-detail', $this->users->editor($id))];
        }));
    }

    public function requestReset(string $user): ResponseInterface
    {
        return $this->privateResponse(Respond::mutation($this->access, function (array $data) use ($user): array {
            $id     = Input::id($user);
            $result = $this->users->requestReset($id, $data);
            $this->session->flash('account.reset_created', [...$result, 'actor' => $this->access->actor(), 'user' => $id]);

            return [...$result, 'url' => '/settings/users/' . $id . '/password-reset/created'];
        }));
    }

    public function created(string $user): ResponseInterface
    {
        return $this->privateResponse(Respond::read(function () use ($user): ResponseInterface {
            $id     = Input::id($user);
            $editor = $this->users->editor($id);
            $result = $this->session->getFlash('account.reset_created');
            if (!$editor['canReset'] || !is_array($result) || $result['actor'] !== $this->access->actor() || $result['user'] !== $id) {
                return redirect('/settings#installation_users', 303);
            }

            return $this->pages->render('password-reset-created', ['title' => t('Reset-Link erstellt'), 'reset' => $result]);
        }));
    }

    private function privateResponse(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
