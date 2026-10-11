<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Auth\Auth;
use Naf\Board\Domain\Failure;
use Naf\Board\Services\UserAdministration;
use Naf\Board\Support\Input;
use Naf\RateLimit\PdoLimiter;
use Naf\Session\Core\Session;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

use function Naf\Board\template;
use function Naf\Form\csrf;
use function Naf\I18n\t;
use function Naf\redirect;
use function Naf\request;
use function Naf\View\render;

/** Recovery never signs in an account; the recipient authenticates afterwards. @internal */
final class PasswordResetController
{
    public function __construct(private UserAdministration $users, private Auth $auth, private Session $session, private PdoLimiter $limiter)
    {
    }

    public function show(#[SensitiveParameter] string $token): ResponseInterface
    {
        return $this->preview($token);
    }

    public function accept(#[SensitiveParameter] string $token): ResponseInterface
    {
        try {
            $peer = request()->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
            if (!$this->limiter->consume('password-reset:peer:' . $peer, 100, 900)['allowed']) {
                throw new Failure(t('Zu viele Versuche. Bitte versuche es später erneut.'), 429);
            }
            $id = $this->users->resetPassword($token, Input::body());
            if ($this->auth->check() && (int) $this->auth->id() === $id) {
                $this->auth->logout();
                csrf()->generate();
            }
            $this->session->flash('account.notice', t('Dein Passwort wurde geändert. Bitte melde dich mit dem neuen Passwort an.'));

            return $this->privateResponse(redirect('/login', 303));
        } catch (Failure $failure) {
            return $this->preview($token, $failure);
        }
    }

    private function preview(#[SensitiveParameter] string $token, ?Failure $error = null): ResponseInterface
    {
        $reset = null;

        try {
            $reset = $this->users->findReset($token);
        } catch (Failure $failure) {
            $error = $failure;
        }

        return $this->privateResponse(render(template('password-reset'), ['reset' => $reset, 'token' => $token, 'error' => $error?->getMessage()])->withStatus($error?->status ?? 200));
    }

    private function privateResponse(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Cache-Control', 'private, no-store')->withHeader('Referrer-Policy', 'no-referrer');
    }
}
